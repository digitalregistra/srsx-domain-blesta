<?php

/**
 * SRS-X Registrar Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.srsx
 * @copyright Copyright (c) 2019, PT. Digital Registra Indonesia
 */

class Srsx extends RegistrarModule
{

    private $set_pending = false;

    public function __construct()
    {
        // Load configuration required by this module
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        # Load components required by this module
        Loader::loadComponents($this, ['Input', "Record"]);
        # Load the language required by this module
        Language::loadLang('srsx', null, dirname(__FILE__) . DS . 'language' . DS);
        Configure::load('srsx', dirname(__FILE__) . DS . 'config' . DS);
    }

    public function getServiceDomain($service)
    {
        if (isset($service->fields)) {
            foreach ($service->fields as $service_field) {
                if ($service_field->key == 'domain-name') {
                    return $service_field->value;
                }
            }
        }

        return $this->getServiceName($service);
    }

    public function validateService($package, array $vars = null)
    {
        return true;
    }

    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $orderedService = isset($vars['service_id']) ? $vars['service_id'] : $vars['service_name'];
        $tblname = isset($vars['service_id']) ? 'service_id' : 'value';
        if (!isset($vars['domain-name'])) {
            $servicefield = $this->Record->select()->from("service_fields")->where($tblname, "=", $orderedService)->where('key', "=", 'domain-name')->fetch();
            $vars['domain-name'] = $servicefield->value;
        }
        $servicefield = $this->Record->select()->from("service_fields")->where($tblname, "=", $orderedService)->where('key', "=", 'epp-code')->fetch();
        if ($servicefield) {
            if ($servicefield->value) {
                $vars['epp-code'] = $servicefield->value;
            }

        }
        $service = json_decode(json_encode(['name' => $vars["domain-name"]]));
        # API configurations
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        # Input fields
        $input_fields = array();
        $domain_field_basics = array(
            'years' => true,
            'ns' => true,
        );
        $domain_fields = array_merge(Configure::get('Srsx.domain_fields'), $domain_field_basics);
        $transfer_fields = array_merge(Configure::get('Srsx.transfer_fields'), $domain_field_basics);
        $input_fields = array_merge($domain_fields, $transfer_fields);
        # Package TLD
        $packageTld = $package->meta->tlds[0];
        # Cek apakah input domain menggunakan TLD apa tidak
        $domainExplode = explode(".", $vars["domain-name"]);
        if (count($domainExplode) == 1) {
            $vars["domain-name"] = $vars["domain-name"] . $packageTld;
        }
        unset($domainExplode[0]);
        $extImplode = implode(".", $domainExplode);
        # Cek apakah TLD domain sama dengan TLD package
        $packageTld = $package->meta->tlds[0];
        $domainTld = ".{$extImplode}";
        if (!in_array($domainTld, $package->meta->tlds)) {
            $error = "Invalid domain TLD";
            $this->Input->setErrors(['errors' => ['error' => $error]]);
            return;
        }
        # Set all WHOIS info from client ($vars['client_id'])
        if (!isset($this->Clients)) {
            Loader::loadModels($this, array("Clients"));
        }
        $client = $this->Clients->get($vars["client_id"]);
        if (isset($vars["use_module"]) && $vars["use_module"] == "true") {
            if (!isset($this->Contacts)) {
                Loader::loadModels($this, array("Contacts"));
            }
            $contact_numbers = $this->Contacts->getNumbers($client->contact_id);
            $phonenumber = $this->formatPhone(isset($contact_numbers[0]) ? $contact_numbers[0]->number : null, $client->country);
            $api->loadCommand("srsx_user");
            $userAPI = new SrsxUser($api);
            # Check user availability
            $postfields = [];
            $postfields["user_username"] = $client->email;
            $userinfoResult = $userAPI->info($postfields);
            if (($userinfoResult->response_json()->result->resultCode) != 1000) {
                # Create new user
                $postfields = [];
                $postfields["user_username"] = $client->email;
                $postfields["user_password"] = $this->randomhash(10);
                $postfields["fname"] = $client->first_name;
                $postfields["lname"] = $client->last_name;
                $postfields["company"] = $client->company;
                $postfields["address"] = $client->address1;
                $postfields["address2"] = $client->address2;
                $postfields["city"] = $client->city;
                $postfields["province"] = $client->state;
                $postfields["country"] = $client->country;
                $postfields["postal_code"] = $client->zip;
                $postfields["phone"] = $phonenumber;
                $usercreateResult = $userAPI->create($postfields);
                $this->processResponseJson($usercreateResult);
                if ($this->Input->errors()) {
                    return;
                }
            }
            # Load command
            $api->loadCommand("srsx_domain");
            $domainAPI = new SrsxDomain($api);
            # Register / transfer
            $postfields = [];
            $postfields["domain"] = $vars["domain-name"];
            $postfields["api_id"] = $vars["domain-name"];

            $domainResult = $domainAPI->info($postfields);
            if ($domainResult->status_json() == 'OK') {
                if ($domainResult->response_json()->resultData->status == 'active') {

                    $productid = ($domainResult->response_json()->resultData->productid);
                    return array(
                        array(
                            "key" => "domain-name",
                            "value" => $vars["domain-name"],
                            "encrypted" => 0,
                        ),
                        array(
                            "key" => "order-id",
                            "value" => $productid,
                            "encrypted" => 0,
                        ),
                    );
                } else {
                    $this->Input->setErrors(['error' => ['errors' => "domain berhasil diregistrasi namun statusnya masih tidak aktif.silahkan melengkapi dokumen jika belum dan mohon coba beberapa saat lagi"]]);
                    return;
                }
            }

            for ($i = 1; $i <= 4; $i++) {
                if (isset($vars["ns{$i}"]) && $vars["ns{$i}"] != "") {
                    $postfields["ns{$i}"] = $vars["ns{$i}"];
                }
            }
            $postfields["periode"] = 1;
            foreach ($package->pricing as $pricing) {
                if ($pricing->id == $vars["pricing_id"]) {
                    $postfields["periode"] = $pricing->term;
                    break;
                }
            }
            $postfields["fname"] = $client->first_name;
            $postfields["lname"] = $client->last_name;
            $postfields["company"] = $client->company;
            $postfields["address1"] = $client->address1;
            $postfields["address2"] = $client->address2;
            $postfields["city"] = $client->city;
            $postfields["state"] = $client->state;
            $postfields["country"] = $client->country;
            $postfields["postcode"] = $client->zip;
            $postfields["phonenumber"] = $phonenumber;
            $postfields["handphone"] = $phonenumber;
            $postfields["email"] = $client->email;
            $postfields["user_username"] = $client->email;
            $postfields["user_fname"] = $client->first_name;
            $postfields["user_lname"] = $client->last_name;
            $postfields["user_email"] = $client->email;
            $postfields["user_company"] = $client->company;
            $postfields["user_address"] = $client->address1;
            $postfields["user_address2"] = $client->address2;
            $postfields["user_city"] = $client->city;
            $postfields["user_province"] = $client->state;
            $postfields["user_country"] = $client->country;
            $postfields["user_postal_code"] = $client->zip;
            $postfields["user_phone"] = $phonenumber;
            if (isset($vars["transfer"]) || isset($vars["epp-code"])) {
                $postfields["transfersecret"] = ($vars["epp-code"]);
                $domainResult = $domainAPI->transfer($postfields);
            } else {
                $postfields["randomhash"] = $this->randomhash(64);
                $domainResult = $domainAPI->register($postfields);
            }
            # Check Order ID
            $productid = null;
            $array = array(".ac.id", ".co.id", ".or.id", ".ponpes.id", ".sch.id", ".web.id");
            if (($domainResult->response_json()->result->resultCode) == 1000) {
                if (in_array($domainTld, $array) || (isset($vars["transfer"]) || isset($vars["epp-code"]))) {
                    $this->Input->setErrors(['error' => ['errors' => "domain berhasil diregistrasi namun statusnya masih tidak aktif.silahkan melengkapi dokumen jika belum dan mohon coba beberapa saat lagi"]]);
                    return;
                }
                if (isset($domainResult->response_json()->resultData->productid)) {
                    $productid = ($domainResult->response_json()->resultData->productid);
                }
            } else {

                if (in_array($domainTld, $array) || (isset($vars["transfer"]) || isset($vars["epp-code"]))) {
                    $this->Input->setErrors(['error' => ['errors' => "domain berhasil diregistrasi namun statusnya masih tidak aktif.silahkan melengkapi dokumen jika belum dan mohon coba beberapa saat lagi"]]);
                    return;
                }
            }
            // var_dump($domainResult->response_json());
            if (isset($domainResult->response_json()->result->resultMsg)) {
                if (!($domainResult->response_json()->result->resultMsg == 'Domain registration has been submited to Registrar System, but domain name activation is waiting for documents to be uploaded. Please make sure your user upload required documents. Domain will not be verified until all documents has been uploaded.')) {
                    $this->processResponseJson($domainResult);
                }
            } else {
                $this->processResponseJson($domainResult);
            }
            if ($this->Input->errors()) {
                return;
            }
            $custom_fields = [];
            // if(isset($vars['id_protection'])) {
            //     $custom_fields['id_protection'] = ['key' => 'id_protection', 'value' => "true", 'encrypted' => 0];
            //     // return $fields;
            //     $status_idp = 1;
            // } else {
            //     $custom_fields['id_protection'] = ['key' => 'id_protection', 'value' => "false", 'encrypted' => 0];
            //     // return $fields;
            //     $status_idp = 0;
            // }

            // $idp = $this->idp($status_idp, $row, $service);
            // if(!$idp) {
            //     return null;
            // }
            return array(
                array(
                    "key" => "domain-name",
                    "value" => $vars["domain-name"],
                    "encrypted" => 0,
                ),
                array(
                    "key" => "order-id",
                    "value" => $productid,
                    "encrypted" => 0,
                ),
                // array(
                //     "key" => $custom_fields['id_protection']['key'],
                //     "value" => $custom_fields['id_protection']['value'],
                //     "encrypted" => $custom_fields['id_protection']['encrypted']
                // )
            );
        } elseif ($status == "active") {
            # Change product status with skipping activation process, with same client and pruduct
            $api->loadCommand("srsx_user");
            $userAPI = new SrsxUser($api);
            # Check user availability
            $postfields = [];
            $postfields["user_username"] = $client->email;
            $userinfoResult = $userAPI->info($postfields);
            $this->processResponseJson($userinfoResult);
            if ($this->Input->errors()) {
                return;
            }
            $useridUser = ($userinfoResult->response_json()->resultData->userid);
            # Check domain information
            $api->loadCommand("srsx_domain");
            $domainAPI = new SrsxDomain($api);
            $postfields = [];
            $postfields["domain"] = $vars["domain-name"];
            $domaininfoResult = $domainAPI->info($postfields);
            $this->processResponseJson($domaininfoResult);
            if (($domaininfoResult->response_json()->result->resultCode) == 2400) {
                $errorMsg = "There are no order for this domain";
                $this->Input->setErrors(['errors' => $errorMsg]);
                return;
            }
            $productid = ($domaininfoResult->response_json()->resultData->productid);
            $useridDomain = ($domaininfoResult->response_json()->resultData->userid);
            # Are both userids same?
            if ($useridUser != $useridDomain) {
                $errorMsg = "Invalid user for this domain";
                $this->Input->setErrors(['errors' => $errorMsg]);
                return;
            }

            $custom_fields = [];
            // if(isset($vars['id_protection'])) {
            //     $custom_fields['id_protection'] = ['key' => 'id_protection', 'value' => "true", 'encrypted' => 0];
            //     // return $fields;
            //     $status_idp = 1;
            // } else {
            //     $custom_fields['id_protection'] = ['key' => 'id_protection', 'value' => "false", 'encrypted' => 0];
            //     // return $fields;
            //     $status_idp = 0;
            // }
            // $idp = $this->idp($status_idp, $row, $service);
            // if(!$idp) {
            //     return null;
            // }
            return array(
                array(
                    "key" => "domain-name",
                    "value" => $vars["domain-name"],
                    "encrypted" => 0,
                ),
                array(
                    "key" => "order-id",
                    "value" => $productid,
                    "encrypted" => 0,
                ),
                // array(
                //     "key" => $custom_fields['id_protection']['key'],
                //     "value" => $custom_fields['id_protection']['value'],
                //     "encrypted" => $custom_fields['id_protection']['encrypted']
                // )
            );
        }
        $meta = array();
        $fields = array_intersect_key(
            $vars,
            array_merge(array("ns1" => true, "ns2" => true, "ns3" => true, "ns4" => true), $input_fields)
        );

        // if(isset($vars['id_protection'])) {
        //     $fields['id_protection'] = "true";
        //     // return $fields;
        //     $status_idp = 1;
        // } else {
        //     $fields['id_protection'] = "false";
        //     // return $fields;
        //     $status_idp = 0;
        // }
        foreach ($fields as $key => $value) {
            $meta[] = array(
                "key" => $key,
                "value" => $value,
                "encrypted" => 0,
            );
        }
        // $idp = $this->idp($status_idp, $row, $service);
        // if(!$idp) {
        //     return null;
        // }
        // var_dump($vars);
        return $meta;
    }

    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {

        Loader::loadHelpers($this, ['Date']);

        $domain = $this->getServiceDomain($service);

        $module_row_id = $service->module_row_id ?? null;
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        # Domain cancel
        $postfields = [];
        $postfields["domain"] = $domain;
        $domainResult = $domainAPI->info($postfields);
        $this->processResponseJson($domainResult);
        if ($this->Input->errors()) {
            return null;
        }
        $result = $domainResult->response_json()->resultData;
        $status = 'pending';
        if ($result->status == 'active' || $result->status == 'suspended') {
            $status = $result->status;
        }
        // if($service->status != 'cancelled')
        $this->Record->where("id", "=", $service->id)->update("services", array("status" => $status));
        if (isset($domainResult->response_json()->resultData->unixenddate)) {
            return $domainResult->response_json()->resultData->unixenddate;
        }
        return null;
    }

    public function editService($package, $service, array $vars = [], $parent_package = null, $parent_service = null)
    {
        # Get the service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);
        $row = $this->getModuleRow($package->module_row);
        if (empty($service_fields->{'order-id'})) {
            $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
            $api->loadCommand('srsx_domain');
            $domainAPI = new SrsxDomain($api);
            $postfields = [];
            $postfields["domain"] = $service->name;
            $response = $domainAPI->info($postfields);
            $this->processResponseJson($response);
            if ($this->Input->errors()) {
                return;
            }
            $productid = null;
            if ($response->response_json()) {
                $productid = ($response->response_json()->resultData->domainid);
            }
            // if(isset($vars['id_protection'])) {
            //     $service_fields->id_protection = "true";
            //     $status_idp = 1;
            //     // return $fields;
            // } else {
            //     $service_fields->id_protection = "false";
            //     $status_idp = 0;
            //     // return $fields;
            // }
            $fields = [['key' => 'order-id', 'value' => $productid, 'encrypted' => 0]];
            $fields = [];
            foreach ($service_fields as $key => $value) {
                $fields[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0,
                ];
            }
            // $idp = $this->idp($status_idp, $row, $service);
            // if(!$idp) {
            //     return null;
            // }
            return $fields;
        } else {
            // if(isset($vars['id_protection'])) {
            //     $service_fields->id_protection = "true";
            //     // return $fields;
            //     $status_idp = 1;
            // } else {
            //     $service_fields->id_protection = "false";
            //     // return $fields;
            //     $status_idp = 0;
            // }
            $fields = [];
            foreach ($service_fields as $key => $value) {
                if ($key != 'order-id') {
                    $fields[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => 0,
                    ];
                }
            }
            // $idp = $this->idp($status_idp, $row, $service);
            // if(!$idp) {
            //     return null;
            // }
            // if(isset($vars['id_protection'])) {
            //     $fields['id_protection'] = ['key' => 'id_protection', 'value' => "true", 'encrypted' => 0];
            // } else {
            //     $fields['id_protection'] = ['key' => 'id_protection', 'value' => "false", 'encrypted' => 0];
            // }
            return $fields;
            // return null;
        }
        return null;
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $fields = $this->serviceFieldsToObject($service->fields);
        # Load the API
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        # Domain cancel
        $postfields = [];
        $postfields["domain"] = $service->name;
        $domaincancelResult = $domainAPI->cancel($postfields);
        $this->processResponseJson($domaincancelResult);
        return null;
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $fields = $this->serviceFieldsToObject($service->fields);
        # Load the API
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        # Domain suspend
        $postfields = [];
        $postfields["domain"] = $service->name;
        $domainsuspendResult = $domainAPI->suspend($postfields);
        $this->processResponseJson($domainsuspendResult);
        return null;
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $fields = $this->serviceFieldsToObject($service->fields);
        # Load the API
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        # Domain unsuspend
        $postfields = [];
        $postfields["domain"] = $service->name;
        $domainunsuspendResult = $domainAPI->unsuspend($postfields);
        $this->processResponseJson($domainunsuspendResult);
        return null;
    }

    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $fields = $this->serviceFieldsToObject($service->fields);
        # Load the API
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        # Domain renew
        $postfields = [];
        $postfields["domain"] = $service->name;
        $postfields["periode"] = 1;
        foreach ($package->pricing as $pricing) {
            if ($pricing->id == $service->pricing_id) {
                $postfields["periode"] = $pricing->term;
                break;
            }
        }
        $domainrenewResult = $domainAPI->renew($postfields);
        $this->processResponseJson($domainrenewResult);
        return null;
    }

    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        return null; // Nothing to do
    }

    public function addPackage(array $vars = null)
    {
        $meta = [];
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            # Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0,
                ];
            }
        }

        return $meta;
    }

    public function editPackage($package, array $vars = null)
    {
        $meta = [];
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            # Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0,
                ];
            }
        }
        return $meta;
    }

    public function manageModule($module, array &$vars)
    {
        # Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        $this->view->set('module', $module);
        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        # Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        # Set unspecified checkboxes
        if (!empty($vars)) {
            if (empty($vars['sandbox'])) {
                $vars['sandbox'] = 'false';
            }
        }
        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        # Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        if (empty($vars)) {
            $vars = $module_row->meta;
        } else {
            # Set unspecified checkboxes
            if (empty($vars['sandbox'])) {
                $vars['sandbox'] = 'false';
            }
        }
        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars)
    {
        $meta_fields = array('registrar', 'reseller_id', 'username', 'password', 'sandbox');
        $encrypted_fields = array('password');
        # Set unspecified checkboxes
        if (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }

        $this->Input->setRules($this->getRowRules($vars));
        # Validate module row

        if ($this->Input->validates($vars)) {
            # Build the meta data for this row
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0,
                    ];
                }
            }

            return $meta;
        }
    }

    public function editModuleRow($module_row, array &$vars)
    {
        # Same as adding
        return $this->addModuleRow($vars);
    }

    public function deleteModuleRow($module_row)
    {
        return null;
    }

    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();
        $types = array(
            'domain' => Language::_('Srsx.package_fields.type_domain', true),
        );
        # Set type of package (tidak perlu karena cuma domain saja)
        $type = $fields->label(Language::_('Srsx.package_fields.type', true), 'srsx_type');
        $type->attach(
            $fields->fieldSelect(
                'meta[type]',
                $types,
                $this->Html->ifSet($vars->meta['type']),
                array('id' => 'srsx_type')
            )
        );
        $fields->setField($type);
        # Set all TLD checkboxes (tidak perlu karena beda packages beda harga)
        $tld_options = $fields->label(Language::_('Srsx.package_fields.tld_options', true));
        $tlds = Configure::get('Srsx.tlds');
        sort($tlds);
        $option = '';
        foreach ($tlds as $tld) {
            $tld_label = $fields->label($tld, "tld_{$tld}");
            $tld_options->attach(
                $fields->fieldCheckbox(
                    'meta[tlds][]',
                    $tld,
                    (isset($vars->meta['tlds']) && in_array($tld, $vars->meta['tlds'])),
                    array('id' => "tld_{$tld}"),
                    $tld_label
                )
            );
            if ((isset($vars->meta['tlds']) && in_array($tld, $vars->meta['tlds']))) {
                $option .= "<option value='$tld' selected>$tld</option>";
            } else {
                $option .= "<option value='$tld' >$tld</option>";
            }
        }
        $fields->setField($tld_options);
        # Set nameservers
        for ($i = 1; $i <= 4; $i++) {
            $type = $fields->label(Language::_("Srsx.package_fields.ns{$i}", true), "srsx_ns{$i}");
            $type->attach(
                $fields->fieldText(
                    'meta[ns][]',
                    $this->Html->ifSet($vars->meta['ns'][$i - 1]),
                    array("id" => "srsx_ns{$i}")
                )
            );
            $fields->setField($type);
        }
        return $fields;
    }

    public function idp($status, $row, $service)
    {
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $postfields["api_id"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        if ($status) {
            $postfields["idprotection"] = 1;
            $this->processResponseJson($domainAPI->set_idprotection($postfields));
            return true;
        } else {
            $postfields["idprotection"] = 0;
            $this->processResponseJson($domainAPI->set_idprotection($postfields));
            return true;
        }
        return false;
    }

    public function getEmailTags()
    {
        return ['service' => ['domain-name']];
    }

    public function getAdminAddFields($package, $vars = null)
    {
        # Handle universal domain name
        if (isset($vars->domain)) {
            $vars->{'domain-name'} = $vars->domain;
        }
        # Set default name servers
        if (!isset($vars->ns1) && isset($package->meta->ns)) {
            $i = 1;
            foreach ($package->meta->ns as $ns) {
                $vars->{'ns' . $i++} = $ns;
            }
        }
        # Handle transfer request
        if (isset($vars->transfer) || isset($vars->{'epp-code'})) {
            $module_fields = $this->arrayToModuleFields(Configure::get('Srsx.transfer_fields'), null, $vars);

            // $idp = $module_fields->label("id_protection", "idp");
            // Create qty field and attach to qty label
            // $idp->attach($module_fields->fieldCheckbox("id_protection", "id_protection", (isset($vars->id_protection) && $vars->id_protection == "true"), array('id' => "idp"), $idp));
            // $module_fields->setField($idp);
        } else {
            # Handle domain registration

            $module_fields = $this->arrayToModuleFields(
                array_merge(
                    Configure::get('Srsx.domain_fields'),
                    Configure::get('Srsx.nameserver_fields')
                ),
                null,
                $vars
            );
            // $idp = $module_fields->label("id_protection", "idp");
            // Create qty field and attach to qty label
            // $idp->attach($module_fields->fieldCheckbox("id_protection", "id_protection", false, array('id' => "idp"), $idp));
            // $module_fields->setField($idp);
            if (isset($vars->{'domain-name'})) {
                $tld = $this->getTld($vars->{'domain-name'});
                if ($tld) {
                    $extension_fields = array_merge(
                        Configure::get('Srsx.domain_fields', $tld),
                        Configure::get('Srsx.contact_fields', $tld)
                    );
                    if ($extension_fields) {
                        $module_fields = $this->arrayToModuleFields($extension_fields, $module_fields, $vars);
                    }
                }
            }
            // Build the domain fields
            $domain_fields = $this->buildDomainModuleFields($vars);
            if ($domain_fields) {
                $module_fields = $domain_fields;
            }
        }
        return $module_fields;
    }

    public function getClientAddFields($package, $vars = null)
    {
        // var_dump($vars);
        # Handle universal domain name
        if (isset($vars->domain)) {
            $vars->{'domain-name'} = $vars->domain;
        }
        # Set default name servers
        if (!isset($vars->ns1) && isset($package->meta->ns)) {
            $i = 1;
            foreach ($package->meta->ns as $ns) {
                $vars->{'ns' . $i++} = $ns;
            }
        }
        $tld = (property_exists($vars, 'domain-name') ? $this->getTld($vars->{'domain-name'}, true) : null);
        # Handle transfer request
        if (isset($vars->transfer) || isset($vars->{'epp-code'})) {
            $fields = Configure::get('Srsx.transfer_fields');
            # We should already have the domain name don't make editable
            $fields['domain-name']['type'] = 'hidden';
            $fields['domain-name']['label'] = null;
            $module_fields = $this->arrayToModuleFields($fields, null, $vars);
            $extension_fields = Configure::get("Srsx.contact_fields");
            if ($extension_fields) {
                $module_fields = $this->arrayToModuleFields($extension_fields, $module_fields, $vars);
            }
            // $idp = $module_fields->label("id_protection", "idp");
            // Create qty field and attach to qty label
            // $idp->attach($module_fields->fieldCheckbox("id_protection", "id_protection", (isset($vars->id_protection) && $vars->id_protection == "true"), array('id' => "idp"), $idp));
            // $module_fields->setField($idp);
        } else {
            # Handle domain registration
            $fields = array_merge(
                Configure::get('Srsx.nameserver_fields'),
                Configure::get('Srsx.domain_fields')
            );
            # We should already have the domain name don't make editable
            $fields['domain-name']['type'] = 'hidden';
            $fields['domain-name']['label'] = null;
            $module_fields = $this->arrayToModuleFields($fields, null, $vars);
            // $idp = $module_fields->label("id_protection", "idp");
            // Create qty field and attach to qty label
            // $idp->attach($module_fields->fieldCheckbox("id_protection", "id_protection", (isset($vars->id_protection) && $vars->id_protection == "true"), array('id' => "idp"), $idp));
            // $module_fields->setField($idp);
            if (isset($vars->{'domain-name'})) {
                $extension_fields = array_merge(
                    Configure::get('Srsx.domain_fields'),
                    Configure::get('Srsx.contact_fields')
                );
                if ($extension_fields) {
                    $module_fields = $this->arrayToModuleFields($extension_fields, $module_fields, $vars);
                }
            }

            $domain_fields = $this->buildDomainModuleFields($vars, true);
            if ($domain_fields) {
                $module_fields = $domain_fields;
            }
        }
        return $module_fields;
    }

    private function buildDomainModuleFields($vars, $client = false)
    {
        if (isset($vars->domain)) {
            $tld = $this->getTld($vars->domain);

            $extension_fields = Configure::get('Srsx.domain_fields' . $tld);
            if ($extension_fields) {
                // Set the fields
                $fields = array_merge(Configure::get('Srsx.domain_fields'), $extension_fields);

                if (!isset($vars->transfer) || $vars->transfer == '0') {
                    $fields = array_merge($fields, Configure::get('Srsx.nameserver_fields'));
                } else {
                    $fields = array_merge($fields, Configure::get('Srsx.transfer_fields'));
                }

                if ($client) {
                    // We should already have the domain name don't make editable
                    $fields['domain']['type'] = 'hidden';
                    $fields['domain']['label'] = null;
                }

                // Build the module fields
                $module_fields = new ModuleFields();

                // Allow AJAX requests
                $ajax = $module_fields->fieldHidden('allow_ajax', 'true', ['id' => 'Srsx_allow_ajax']);
                $module_fields->setField($ajax);
                $please_select = ['' => Language::_('AppController.select.please', true)];

                foreach ($fields as $key => $field) {
                    // Build the field
                    $label = $module_fields->label((isset($field['label']) ? $field['label'] : ''), $key);

                    $type = null;
                    if ($field['type'] == 'text') {
                        $type = $module_fields->fieldText(
                            $key,
                            (isset($vars->{$key})
                                ? $vars->{$key}
                                : (isset($field['options']) ? $field['options'] : '')),
                            ['id' => $key]
                        );
                    } elseif ($field['type'] == 'select') {
                        $type = $module_fields->fieldSelect(
                            $key,
                            (isset($field['options']) ? $please_select + $field['options'] : $please_select),
                            (isset($vars->{$key}) ? $vars->{$key} : ''),
                            ['id' => $key]
                        );
                    } elseif ($field['type'] == 'checkbox') {
                        $type = $module_fields->fieldCheckbox($key, (isset($field['options']) ? $field['options'] : 1));
                        $label = $module_fields->label((isset($field['label']) ? $field['label'] : ''), $key);
                    } elseif ($field['type'] == 'hidden') {
                        $type = $module_fields->fieldHidden(
                            $key,
                            (isset($vars->{$key})
                                ? $vars->{$key}
                                : (isset($field['options']) ? $field['options'] : '')),
                            ['id' => $key]
                        );
                    }

                    // Include a tooltip if set
                    if (!empty($field['tooltip'])) {
                        $label->attach($module_fields->tooltip($field['tooltip']));
                    }

                    if ($type) {
                        $label->attach($type);
                        $module_fields->setField($label);
                    }
                }
            }
        }

        return (isset($module_fields) ? $module_fields : false);
    }

    public function getAdminEditFields($package, $vars = null)
    {
        $module_fields = new ModuleFields();
        return $module_fields;
    }

    public function getClientEditFields($package, $vars)
    {
        $module_fields = new ModuleFields();
        return $module_fields;

    }

    public function getAdminServiceInfo($service, $package)
    {
        return '';
    }

    public function getClientServiceInfo($service, $package)
    {
        return '';
    }

    public function getAdminTabs($package, $service = null)
    {
        $data = array(
            'tabContact' => Language::_('Srsx.tab_whois.title', true),
            'tabNameservers' => Language::_('Srsx.tab_nameservers.title', true),
            'tabSettings' => Language::_('Srsx.tab_settings.title', true),
            'tabEpp' => Language::_('Srsx.tab_epp.title', true),
            // 'tabIdp' => Language::_('Srsx.tab_idp.title',true),
            'tabChildNs' => Language::_('Srsx.tabClientChildNs.title', true),
            // 'tabDNSSEC' => Language::_('Srsx.tabClientDNSSEC.title',true),
            // 'tabDNS' => Language::_('Srsx.tabClientDNS.title',true),
            // 'tabDomainForwarding' => Language::_('Srsx.tabDomainForwarding.title',true),
            'tabRenew' => Language::_('Srsx.Renew.title', true),
        );

        return $data;
    }

    public function get_domain()
    {
        return $this->view->vars;
    }

    public function getProtectedValue($obj, $name)
    {
        $array = $obj;
        $prefix = chr(0) . '*' . chr(0);
        return $array[$prefix . $name];
    }

    public function getClientTabs($package, $service = null)
    {
        $data = array(
            'tabClientContact' => Language::_('Srsx.tab_whois.title', true),
            'tabClientNameservers' => Language::_('Srsx.tab_nameservers.title', true),
            'tabClientSettings' => Language::_('Srsx.tab_settings.title', true),
            // 'tabClientIdp' => Language::_('Srsx.tab_idp.title',true),
            'tabClientEpp' => Language::_('Srsx.tab_epp.title', true),
            'tabClientChildNs' => Language::_('Srsx.tabClientChildNs.title', true),
            // 'tabClientDNSSEC' => Language::_('Srsx.tabClientDNSSEC.title',true),
            'tabClientDNS' => Language::_('Srsx.tabClientDNS.title',true),
            'tabClientDomainForwarding' => Language::_('Srsx.tabDomainForwarding.title',true),
        );

        $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri_segments = explode('/', $uri_path);
        $service = $this->Record->select()->from("service_fields")->where('service_id', "=", $uri_segments[4])->where('key', "=", 'domain-name')->fetch();

        if ($service) {
            $array = array(".ac.id", ".co.id", ".or.id", ".ponpes.id", ".sch.id", ".web.id");
            foreach ($array as $a) {
                if (strpos($service->value, $a) !== false) {
                    $data['tabClientDomainId'] = Language::_('Srsx.tab_domainid.title', true);
                    break;
                }
            }
        }
        return $data;
    }

    public function tabIdp($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageIdp('/idp/admin/index', $package, $service, $get, $post, $files);

    }
    public function tabClientIdp($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageIdp('/idp/client/index', $package, $service, $get, $post, $files);

    }

    public function manage($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->Manage_Domain_Forwarding('/domain_forwarding/admin/index', $package, $service, $get, $post, $files);

    }

    public function tabClientInfo($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageClientInfo('/information/client/index', $package, $service, $get, $post, $files);
    }

    public function tabRenew($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageRenew('/renew/admin/index', $package, $service, $get, $post, $files);

    }

    public function tabDomainForwarding($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->Manage_Domain_Forwarding('/domain_forwarding/admin/index', $package, $service, $get, $post, $files);
    }

    public function tabClientDomainForwarding($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->Manage_Domain_Forwarding('/domain_forwarding/client/index', $package, $service, $get, $post, $files);
    }

    public function tabDNS($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_DNS('/dns_management/admin/tab_manageddns', $package, $service, $get, $post, $files);
    }

    public function tabClientDNS($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_DNS('/dns_management/client/tab_client_manageddns', $package, $service, $get, $post, $files);
    }

    public function tabClientDNSSEC($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_DNSSEC('/dnssec/client/tab_client_dnssec', $package, $service, $get, $post, $files);
    }

    public function tabDNSSEC($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_DNSSEC('/dnssec/admin/tab_dnssec', $package, $service, $get, $post, $files);
    }

    public function tabClientChildNs($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_client_child_nameserver('/childns/client/tab_client_child_nameserver', $package, $service, $get, $post, $files);
    }

    public function tabChildNs($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_client_child_nameserver('/childns/admin/tab_child_nameserver', $package, $service, $get, $post, $files);
    }

    public function tabClientEpp($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_client_epp('/epp/client/tab_client_epp', $package, $service, $get, $post, $files);
    }
    public function tabEpp($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_client_epp('/epp/admin/tab_epp', $package, $service, $get, $post, $files);
    }

    public function tabClientContact($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_client_domain_contact('/contact/client/tab_client_contact', $package, $service, $get, $post, $files);
    }
    public function tabContact($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manage_client_domain_contact('/contact/admin/tab_contact', $package, $service, $get, $post, $files);
    }

    public function tabWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois('/whois/admin/tab_whois', $package, $service, $get, $post, $files);
    }

    public function tabClientWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois('/whois/client/tab_client_whois', $package, $service, $get, $post, $files);
    }

    public function tabNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('/nameserver/admin/tab_nameservers', $package, $service, $get, $post, $files);
    }

    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('/nameserver/client/tab_client_nameservers', $package, $service, $get, $post, $files);
    }

    public function tabSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings('/settings/admin/tab_settings', $package, $service, $get, $post, $files);
    }

    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings('/settings/client/tab_client_settings', $package, $service, $get, $post, $files);
    }

    public function tabDomainId($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDomainId('/domainid/admin/tab_domainid', $package, $service, $get, $post, $files);
    }

    public function tabClientDomainId($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDomainId('/domainid/client/tab_client_domainid', $package, $service, $get, $post, $files);
    }

    public function manageClientInfo($view, $package, $service, $get, $post, $files)
    {
        Loader::loadHelpers($this, ['Html', 'Form', 'currencies']);
        $day_inv = $this->Record->select()->from("company_settings")->where('company_id', "=", $package->company_id)->where('key', "=", 'inv_days_before_renewal')->fetch();

        $money = $this->Currencies->toCurrency(
            $service->package_pricing->price_renews,
            $service->package_pricing->currency,
            $package->company_id
        );

        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        # Domain cancel
        $postfields = [];
        $postfields["domain"] = $domain;
        $postfields["api_id"] = $domain;
        $domainResult = $domainAPI->status($postfields);

        $this->processResponseJson($domainResult);
        if (isset($domainResult->response_json()->resultData->domainstatus)) {
            $service->status = $domainResult->response_json()->resultData->domainstatus;
        }
        $timezone = $this->Record->select()->from("company_settings")->where('company_id', "=", $package->company_id)->where('key', "=", "timezone")->fetch();

        $created = new DateTime($service->date_added, new DateTimeZone("Africa/Abidjan")); //utc 0 didatabase
        // echo $service->date_renews;
        $created->setTimezone(new DateTimeZone($timezone->value));
        $renews = new DateTime($service->date_renews, new DateTimeZone("Africa/Abidjan")); //utc 0 didatabase
        $renews->setTimezone(new DateTimeZone($timezone->value));
        $inv_renew = new DateTime($service->date_renews, new DateTimeZone("Africa/Abidjan")); //utc 0 didatabase
        $inv_renew->setTimezone(new DateTimeZone($timezone->value));
        $inv_renew->modify("-{$day_inv->value} day");

        $this->view = new View($view, 'default');
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('day_inv', $day_inv);
        $this->view->set('renews', $renews->format("M d, Y"));
        $this->view->set('created', $created->format("M d, Y"));
        $this->view->set('inv_renew', $inv_renew->format("M d, Y"));
        $this->view->set('money', $money);
        // echo date("M d, Y",strtotime($service->date_renews));
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        $view = $this->view->fetch();
        return $view;
    }
    public function remove_client_default_link($service)
    {
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view = new View("/irtp/client/remove_default_link", 'default');
        $this->view->set('service', $service);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        // $this->irtp_client($row, $service);
        return $this->view->fetch();
    }
    public function add_irtp_to_view_client($view, $package, $service)
    {
        $row = $this->getModuleRow($package->module_row);
        $irtp = $this->irtp_client($row, $service);
        return $irtp . $view;
    }

    public function manageRenew($view, $package, $service, $get, $post, $files)
    {
        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            Loader::loadHelpers($this, ['Html', 'Form']);
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $show_content = true;
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            $row = $this->getModuleRow($package->module_row);
            $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
            $api->loadCommand('srsx_domain');
            $postfields["domain"] = $service->name;
            $postfields["api_id"] = $service->name;
            $postfields["periode"] = $_POST['renew'];
            $domainAPI = new SrsxDomain($api);
            $status = $domainAPI->status($postfields);
            $domainrenewResult = $domainAPI->renew($postfields);
            $this->processResponseJson($domainrenewResult);
            if ($this->Input->errors()) {
                return;
            }
            //perpanjang
            $strtotime = strtotime($service->date_renews) + (365 * 24 * 60 * 60);
            $this->Record->where("id", "=", $service->id)->update("services", array("date_renews" => date("Y-m-d H:i:s", $strtotime)));
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        $this->view->set('domain', $service->name);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();

    }

    public function domainstatus($view, $package, $service, $get, $post, $files)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $postfields["api_id"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        $status = $domainAPI->status($postfields);
        if (isset($status->response_json()->resultData->domainstatus)) {
            return $status->response_json()->resultData->domainstatus;
        } else {
            //gagal mendapatkan status domain
            return "failed";
        }
        return "failed";

    }

    private function Manage_Domain_Forwarding($view, $package, $service, $get, $post, $files)
    {
        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        // $id = $_GET['id'];
        $domainAPI = new SrsxDomain($api);
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            if ($_POST['action'] == 'init') {
                unset($_POST['action']);
                unset($_POST['_csrf_token']);
                $_POST['domain'] = $postfields["domain"];
                $domainAPI->forward_init($_POST);
                $this->processResponseJson($domainAPI->forward_init($_POST));
                if ($this->Input->errors()) {
                    return;
                }
            }
            if ($_POST['action'] == 'update') {
                unset($_POST['action']);
                unset($_POST['_csrf_token']);
                $_POST['domain'] = $postfields["domain"];
                $this->processResponseJson($domainAPI->forward_update($_POST));
                if ($this->Input->errors()) {
                    return;
                }
            }
        }
        $record = (object) [
            "target" => "",
            "type" => "",
            "header" => "",
            "noframe" => "",
            "subdomain" => "",
            "path" => "",
        ];
        $response = $domainAPI->forward_status($postfields);
        $status = true;
        if ($res = ($response->response_json())) {
            if ($res->result->resultMsg == "Domain Forwarding for {$postfields['domain']} isn't registered yet") {
                $status = false;
            } else if ($res->result->resultCode == 1000) {
                $record = $res->resultData;
            } else {
                $this->processResponseJson($domainAPI->forward_status($postfields));
                if ($this->Input->errors()) {
                    return;
                }
            }

        } else {
            $this->Input->setErrors(['errors' => ["error" => 'failed to decode json response']]);
            return;
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('domain', $service->name);
        $this->view->set('record', $record);
        $this->view->set('status', $status);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();

    }

    private function edit_dns_management($view, $package, $service, $get, $post, $files)
    {
        $view = '/dns_management/client/tab_client_manageddnsmodify';
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $id = $_GET['id'];
        $domainAPI = new SrsxDomain($api);
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            unset($_POST['_csrf_token']);
            $_POST['domain'] = $postfields["domain"];
            $this->processResponseJson($domainAPI->dns_edit($_POST));
            if ($this->Input->errors()) {
                return;
            }
        }
        $dnsinfo = $domainAPI->dns_info($postfields);
        foreach ($dnsinfo->response_json()->resultData as $d) {
            if ($d->dnsid == $id) {
                $record = $d;
            }
        }
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('domain', $service->name);
        $this->view->set('record', $record);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();

    }

    private function add_dns_management($view, $package, $service, $get, $post, $files)
    {
        $view = '/dns_management/client/tab_client_manageddnsadd';
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            unset($_POST['_csrf_token']);
            $_POST['domain'] = $postfields["domain"];
            $this->processResponseJson($domainAPI->dns_add($_POST));
            if ($this->Input->errors()) {
                return;
            }
        }

        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('domain', $service->name);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function manage_DNS($view, $package, $service, $get, $post, $files)
    {
        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        if (isset($_GET['action'])) {
            if ($_GET['action'] == 'add_dns_management') {
                return $this->add_dns_management($view, $package, $service, $get, $post, $files);
            } else if ($_GET['action'] == 'edit_dns_management') {
                return $this->edit_dns_management($view, $package, $service, $get, $post, $files);
            }
        }
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            unset($_POST['_csrf_token']);
            if ($_POST['action'] == 'add_dns_management') {
                if (isset($_POST['view'])) {
                    header('Location: ?action=add_dns_management&view=' . $_POST['view']);

                } else {
                    header('Location: ?action=add_dns_management');
                }
            } else if ($_POST['action'] == 'edit_dns_management') {
                if (isset($_POST['view'])) {
                    header('Location: ?action=edit_dns_management&id=' . $_POST['id'] . "&view=" . $_POST['view']);
                } else {
                    header('Location: ?action=edit_dns_management&id=' . $_POST['id']);
                }
            } else if ($_POST['action'] == 'delete') {
                unset($_POST['action']);
                $_POST['dnsid'] = $_POST['id'];
                $_POST['domain'] = $postfields["domain"];
                $this->processResponseJson($domainAPI->dns_delete($_POST));
            } else if ($_POST['action'] == 'init') {
                $init = false;
                unset($_POST['action']);
                $_POST['domain'] = $postfields["domain"];
                $this->processResponseJson($domainAPI->dns_init($_POST));
                $dns_info = $domainAPI->dns_info($postfields);
                if ($dns_info->response_json()->result->resultCode == 1000) {
                    $list = $dns_info->response_json()->resultData;
                    foreach ($list as $key => $l) {
                        if (strpos($key, 'dns') !== false) {
                            $init = true;
                        }
                    }
                }

                if (!$init) {
                    $this->processResponseJson($domainAPI->dns_init($_POST));
                }
            } else if ($_POST['action'] == 'update') {
                unset($_POST['action']);
                $_POST['domain'] = $postfields["domain"];
                $dns_info = $domainAPI->dns_info($postfields);
                $list = ($dns_info)->response_json()->resultData;
                $_POST['nameserver'] = "{$list->reseller_ns1},{$list->reseller_ns2},{$list->reseller_ns3},{$list->reseller_ns4}";
                $this->processResponseJson($domainAPI->updatens($_POST));
            }
            if ($this->Input->errors()) {
                return;
            }
        }

        $update = $init = false;
        $count = 0;
        $dns_info = $domainAPI->dns_info($postfields);
        if ($dns_info->response_json()->result->resultCode == 1000) {
            $list = $dns_info->response_json()->resultData;

            if (
                $list->reseller_ns1 != $list->domain_ns1 ||
                $list->reseller_ns2 != $list->domain_ns2 ||
                $list->reseller_ns3 != $list->domain_ns3 ||
                $list->reseller_ns4 != $list->domain_ns4
            ) {
                $update = true;
            }
    
            foreach ($list as $key => $l) {
                if (strpos($key, 'dns') !== false) {
                    $init = true;
                    if ($l->type != 'SOA') {
                        $count++;
                    } else {
                        unset($list->$key);
                    }
                } else {
                    unset($list->$key);
                }
            }
        }

        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('domain', $service->name);
        $this->view->set('list', $list ?? []);
        $this->view->set('count', $count);
        $this->view->set('init', $init);
        $this->view->set('update', $update);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function manage_DNSSEC($view, $package, $service, $get, $post, $files)
    {
        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            unset($_POST['_csrf_token']);
            if ($_POST['action'] == 'add') {
                unset($_POST['action']);
                $_POST['domain'] = $postfields["domain"];
                $this->processResponseJson($domainAPI->add_dnssec($_POST));
            } else if ($_POST['action'] == 'delete') {
                unset($_POST['action']);
                $_POST['domain'] = $postfields["domain"];
                $this->processResponseJson($domainAPI->delete_dnssec($_POST));
            }
            if ($this->Input->errors()) {
                return;
            }
        }
        $list = $domainAPI->list_dnssec($postfields);
        $list = $list->response_json()->resultData->dnssec;
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('domain', $service->name);
        $this->view->set('list', $list);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();

    }

    private function manage_client_child_nameserver($view, $package, $service, $get, $post, $files)
    {
        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $api->loadCommand('srsx_contact');
        $postfields["domain"] = $service->name;
        unset($_POST['domainid']);
        $domainAPI = new SrsxDomain($api);
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            unset($_POST['_csrf_token']);
            if ($_POST['action'] == 'register') {
                unset($_POST['action']);
                $_POST['domain'] = $postfields["domain"];
                $this->processResponseJson($domainAPI->register_private_ns($_POST));
            } else if ($_POST['action'] == 'update') {
                unset($_POST['action']);
                $_POST['domain'] = $postfields["domain"];
                $this->processResponseJson($domainAPI->update_private_ns($_POST));
            } else if ($_POST['action'] == 'delete') {
                unset($_POST['action']);
                $_POST['domain'] = $postfields["domain"];
                $_POST['nameserver'] = $_POST['nameserver'] . "." . $postfields["domain"];
                $this->processResponseJson($domainAPI->delete_private_ns($_POST));
            }
            if ($this->Input->errors()) {
                return;
            }
        }
        $domain_privatens = $domainAPI->get_private_ns($postfields);
        $response = $domain_privatens->response_json()->resultData->privatens;
        // var_dump($response[0]);die();
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('domain', $service->name);
        $this->view->set('response', $response);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();

    }

    public function modify_client_domain_contact($view, $package, $service, $get, $post, $files)
    {
        $show_content = true;
        $view = 'tab_client_contact2';
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('contact', $contact);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();

    }

    public function cek_idp_exist($service)
    {
        if (isset($service->options)) {
            if ($service->options) {
                foreach ($service->options as $s) {
                    if ($s->option_name == 'id_protection') {
                        return $s;
                    }
                }
            }
        }
        return false;
    }

    public function cek_idp_active($service)
    {
        if ($idp = $this->cek_idp_exist($service)) {
            if ($idp->option_value == 0) {
                return false;
            }
            if (strtotime("now") <= strtotime("{$service->date_added} + {$idp->option_pricing_term} {$idp->option_pricing_period}")) {
                return true;
            }
        }
        return false;
    }

    public function toggle_idp($package, $service)
    {
        if ($this->cek_idp_active($service)) {
            $status = !$this->get_idp_status($package, $service);

            $row = $this->getModuleRow($package->module_row);
            $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
            $api->loadCommand('srsx_domain');
            $postfields["domain"] = $service->name;
            $postfields["api_id"] = $service->name;
            $postfields["idprotection"] = $status ? 1 : 0;
            $domainAPI = new SrsxDomain($api);
            $info = $domainAPI->set_idprotection($postfields);
            if (!$this->Input->errors()) {
                return true;
            }
        }
        return false;
    }

    public function get_idp_status($package, $service)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        $info = $domainAPI->info($postfields);
        if ($info->status_json() == 'OK') {
            $idp = $info->response_json()->resultData->idprotection;
            if ($idp->status == 1) {
                if ($idp->config1 == 1) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }

    }

    public function manageIdp($view, $package, $service, $get, $post, $files)
    {
        $show_content = true;
        $active = $this->cek_idp_active($service);
        if (!$active) {
            $view = str_replace('index', 'not_active', $view);
        }
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!$this->toggle_idp($package, $service)) {
                return;
            }
        }
        $status = $this->get_idp_status($package, $service);
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');

        $this->view->set('status', $status);
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    public function manage_client_domain_contact($view, $package, $service, $get, $post, $files)
    {

        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $show_content = true;
        if (isset($_GET['action'])) {
            if ($_GET['action'] == 'modifycontact') {
                return $this->modifycontact($view, $package, $service, $get, $post, $files);
            } else if ($_GET['action'] == 'changecontact') {
                return $this->changecontact($view, $package, $service, $get, $post, $files);
            } else if ($_GET['action'] == 'createcontact') {
                return $this->createcontact($view, $package, $service, $get, $post, $files);
            } else if ($_GET['action'] == 'deletecontact') {
                return $this->deletecontact($view, $package, $service, $get, $post, $files);
            } else if ($_GET['action'] == 'detailmodifycontact') {
                return $this->detailmodifycontact($view, $package, $service, $get, $post, $files);
            }
        }
        if (!isset($_GET['tab'])) {
            $_GET['tab'] = 1;
        }
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $api->loadCommand('srsx_contact');
        $postfields["domain"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        $contactAPI = new SrsxContact($api);
        $domain_info = $domainAPI->info($postfields);
        $type = ["registrant", "admin", "tech", "billing"];
        $contact_name_type = 'contact_' . $type[($_GET['tab'] - 1)];
        $contact_id = (string) $domain_info->response_json()->resultData->$contact_name_type;
        $postfields['contactid'] = $contact_id;
        $contact_info = $contactAPI->info($postfields);
        $contact = $contact_info->response_json()->resultData;
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('contact', $contact);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function modifycontact($view, $package, $service, $get, $post, $files)
    {
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_contact');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $contactAPI = new SrsxContact($api);
        $contact_info = $contactAPI->getallcontact($postfields);
        $contact = $contact_info->response_json()->resultData->contact;
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            if (isset($_POST['view'])) {
                header('Location: ?action=detailmodifycontact&id=' . $_POST['contactId'] . "&view=" . $_POST['view']);
            } else {
                header('Location: ?action=detailmodifycontact&id=' . $_POST['contactId']);
            }
        }
        $view = '/contact/client/tab_client_contact_modify';
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('contact', $contact);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }
    private function detailmodifycontact($view, $package, $service, $get, $post, $files)
    {
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_contact');
        $contactAPI = new SrsxContact($api);
        $postfields["domain"] = $service->name;
        $postfields['contactid'] = $_GET['id'];
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            $this->processResponseJson($contactAPI->update($_POST));
            if ($this->Input->errors()) {
                return;
            }
        }
        $contact_info = $contactAPI->info($postfields);
        $contact = $contact_info->response_json()->resultData;
        $view = '/contact/client/tab_client_contact_modify_detail';
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('contact', $contact);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();

    }

    private function createcontact($view, $package, $service, $get, $post, $files)
    {
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_contact');
        $contactAPI = new SrsxContact($api);
        $view = '/contact/client/tab_client_contact_create';
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        if (isset($_POST) && $_SERVER['REQUEST_METHOD'] == 'POST') {
            $_POST['domain'] = $service->name;
            $this->processResponseJson($contactAPI->create($_POST));
            if ($this->Input->errors()) {
                return;
            }

        }
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('contact', $contactAPI);
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function deletecontact($view, $package, $service, $get, $post, $files)
    {
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_contact');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $contactAPI = new SrsxContact($api);
        $domainAPI = new SrsxDomain($api);
        if (isset($_POST['contactId'])) {
            $postfields['contactid'] = $_POST['contactId'];
            $this->processResponseJson($contactAPI->delete($postfields));
            if ($this->Input->errors()) {
                return;
            }
        }
        $contact_info = $contactAPI->getallcontact($postfields);
        $contact = $contact_info->response_json()->resultData->contact;
        $view = '/contact/client/tab_client_contact_delete';
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('contact', $contact);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function changecontact($view, $package, $service, $get, $post, $files)
    {
        // var_dump($_POST);
        // exit;

        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_contact');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $contactAPI = new SrsxContact($api);
        $domainAPI = new SrsxDomain($api);
        if (!empty($_POST)) {
            $postfields["registrant_contact"] = $_POST['registrantContactId'];
            $postfields["admin_contact"] = $_POST['adminContactId'];
            $postfields["billing_contact"] = $_POST['billingContactId'];
            $postfields["tech_contact"] = $_POST['techContactId'];
            $this->processResponseJson($domainAPI->editcontact($postfields));
            if ($this->Input->errors()) {
                return;
            }
        }
        $contact_info = $contactAPI->getallcontact($postfields);
        $contact = $contact_info->response_json()->resultData->contact;
        $domain_info = $domainAPI->info($postfields);
        // var_dump($contact);
        $show_content = true;
        $view = '/contact/client/tab_client_contact_change';
        $view = ($show_content ? $view : 'tab_unavailable');
        if (isset($_GET['view'])) {
            $view = $_GET['view'];
        }
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('contact', $contact);
        $this->view->set('domain', $domain_info->response_json()->resultData);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    public function irtp_client($row, $service)
    {
        $vars = new stdClass();
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        $send_email = false;
        $send_email_status = false;
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $send = $domainAPI->resend_raa($postfields);
            $send_email = true;
            if ($result_send = ($send)) {
                if ($result_send->result->resultCode == 1000) {
                    $send_email_status = true;
                } else {
                    $send_email_status = false;
                }
            } else {
                $send_email_status = false;
            }
        }
        $irtp = $domainAPI->veri_info($postfields);
        if ($this->Input->errors()) {
            return;
        }
        $this->processResponseJson($irtp);
        $result = ($irtp);
        $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $view = 'irtp/client/index';
        if (strpos($uri_path, 'admin/clients')) {
            $view = 'irtp/admin/index';
        } else {
            $view = 'irtp/client/index';
        }
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('irtp_status', $result->response_data()->resultData->raaVerificationStatus);
        $this->view->set('irtp_data', $result->response_data()->resultData);
        $this->view->set('send_email', $send_email);
        $this->view->set('send_email_status', $send_email_status);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        return ($this->view->fetch());
    }

    public function manage_client_epp($view, $package, $service, $get, $post, $files)
    {

        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $show_content = true;
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $postfields["domain"] = $service->name;
        $domainAPI = new SrsxDomain($api);
        if (isset($_POST['epp'])) {
            $postfields["eppcode"] = $_POST['epp'];
            $this->processResponseJson($domainAPI->epp_set($postfields));
            if ($this->Input->errors()) {
                return;
            }
        }
        $domain_epp_get = $domainAPI->epp_get($postfields);
        $epp = (string) $domain_epp_get->response_json()->resultData->epp;
        $vars = ['epp' => $epp];
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function manageDomainId($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        // Error message: Domain registration has been submited to Registrar System, but domain name activation is waiting for documents to be uploaded. Please make sure your user upload required documents. Domain will not be verified until all documents has been uploaded.

        // Error message: Domain registration has been submited to Registrar System, but domain name activation is waiting for documents to be uploaded. Please make sure your user upload required documents. Domain will not be verified until all documents has been uploaded.
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        # Is the TLD ".ID"?
        $show_content = false;
        $domainTld = $this->getTld($service->name);
        if (in_array($domainTld, array(".ac.id", ".co.id", ".or.id", ".ponpes.id", ".sch.id", ".web.id"))) {
            $show_content = true;
        }
        # Domain status API
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        $postfields = [];
        $postfields["domain"] = $service->name;
        $postfields["api_id"] = $service->name;
        # Get Domain Status
        $status = $domainstatusResult = $domainAPI->status_json($postfields)->response_json();
        if ($status = $domainstatusResult = ($status)) {
            if (isset($status->resultData->domainstatus)) {
                $array = ["awaiting document", 'verifying'];
                if (!in_array($status->resultData->domainstatus, $array)) {
                    $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                    if (strpos($uri_path, "admin/clients")) {
                        $view = 'domainid/admin/not_awaiting';
                    } else {
                        $view = 'domainid/client/not_awaiting';
                    }
                    $this->view = new View($view, 'default');
                    $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
                    return $this->view->fetch();
                }
            } else {
                $this->Input->setErrors(['errors' => ["error" => 'domainstatus is not set']]);

            }
        } else {
            $this->Input->setErrors(['errors' => ["error" => 'failed to decode json']]);

        }
        if ($this->Input->errors()) {
            return;
        }
        # Set base URL
        if (preg_match("/a/", $row->meta->reseller_id)) {
            $resellerId = substr($row->meta->reseller_id, 0, -1);
            $baseUrl = "https://srb{$resellerId}.alpha.srs-x.com";
        } else {
            if ($row->meta->sandbox) {
                $baseUrl = "http://srb{$row->meta->reseller_id}.srs-x.net";
            } else {
                $baseUrl = "https://srb{$row->meta->reseller_id}.srs-x.com";
            }
        }
        $vars = $domainstatusResult->resultData;
        $domainstatusUrl = ($domainstatusResult->resultData->url);
        $vars->replyurl = "{$baseUrl}/guest/notification/{$domainstatusUrl}";
        $vars->documentUrl = "{$baseUrl}/document/id/{$domainstatusUrl}";
        $vars->domainStatus = ($domainstatusResult->resultData->domainstatus);
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function manageWhois($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        $vars = new stdClass();
        $contact_fields = Configure::get('Srsx.contact_fields');
        $fields = $this->serviceFieldsToObject($service->fields);
        // var_dump($_POST);
        // die();
        $show_content = true;
        if (!empty($post)) {
            # Update contact
            $api->loadCommand('srsx_contact');
            $contactAPI = new SrsxContact($api);
            $postfields = [];
            foreach ($post as $key => $value) {
                $postfields[$key] = $value;
            }
            $contactupdateResult = $contactAPI->update($postfields);
            $this->processResponseJson($contactupdateResult);
            if ($this->Input->errors()) {
                // break;
                return;
            }
            # Update domain contact
            $postfields = [];
            $postfields["domain"] = $service->name;
            $postfields["registrant_contact"] = $post["contactid"];
            $postfields["admin_contact"] = $post["contactid"];
            $postfields["billing_contact"] = $post["contactid"];
            $postfields["tech_contact"] = $post["contactid"];
            $domaineditcontactResult = $domainAPI->editcontact($postfields);
            $this->processResponseJson($domaineditcontactResult);
            if ($this->Input->errors()) {
                // break;
                return;
            }
            $vars = (object) $post;
        } elseif (property_exists($fields, 'domain-name')) {
            # Get contact ID
            $postfields = [];
            $postfields["domain"] = $service->name;
            $domaininfoResult = $domainAPI->info($postfields);
            $this->processResponseJson($domaininfoResult);
            if ($this->Input->errors()) {
                return;
            }
            $contactid = ($domaininfoResult->response_json()->resultData->contact_registrant);
            # Contact information
            $postfields = [];
            $postfields["contactid"] = $contactid;
            $api->loadCommand("srsx_contact");
            $contactAPI = new SrsxContact($api);
            $contactinfoResult = $contactAPI->info($postfields);
            $this->processResponseJson($contactinfoResult);
            if ($this->Input->errors()) {
                return;
            }
            # Format fields
            $vars->contactid = ($contactinfoResult->response_json()->resultData->contactid);
            $vars->fname = ($contactinfoResult->response_json()->resultData->fname);
            $vars->lname = ($contactinfoResult->response_json()->resultData->lname);
            $vars->email = ($contactinfoResult->response_json()->resultData->email);
            $vars->company = ($contactinfoResult->response_json()->resultData->company);
            $vars->address = ($contactinfoResult->response_json()->resultData->address1);
            $vars->address2 = ($contactinfoResult->response_json()->resultData->address2);
            $vars->address3 = ($contactinfoResult->response_json()->resultData->address3);
            $vars->city = ($contactinfoResult->response_json()->resultData->city);
            $vars->state = ($contactinfoResult->response_json()->resultData->state);
            $vars->phonenumber = ($contactinfoResult->response_json()->resultData->phonenumber);
            $vars->fax = ($contactinfoResult->response_json()->resultData->fax);
            $vars->country = ($contactinfoResult->response_json()->resultData->country);
            $vars->postcode = ($contactinfoResult->response_json()->resultData->postcode);
        } else {
            # No order-id; info is not available
            $show_content = false;
        }
        $contact_fields = array_merge(
            Configure::get('Srsx.contact_fields'),
            ['contactid' => ['type' => 'hidden']]
        );
        $all_fields = [];
        foreach ($contact_fields as $key => $value) {
            $all_fields[$key] = $value;
        }
        $module_fields = $this->arrayToModuleFields(Configure::get('Srsx.contact_fields'), null, $vars);
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('vars', $vars);
        $this->view->set('fields', $this->arrayToModuleFields($all_fields, null, $vars)->getFields());
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function manageNameservers($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        $fields = $this->serviceFieldsToObject($service->fields);
        $show_content = true;
        $tld = $this->getTld($fields->{'domain-name'});
        $sld = substr($fields->{'domain-name'}, 0, -strlen($tld));
        if (property_exists($fields, 'order-id')) {
            if (!empty($post)) {
                $ns = [];
                foreach ($post["ns"] as $i => $nameserver) {
                    if ($nameserver != "") {
                        $ns[] = $nameserver;
                    }
                }
                $post['order-id'] = $fields->{'order-id'};
                $postfields = [];
                $postfields["domain"] = $service->name;
                $postfields["nameserver"] = implode(",", $ns);
                $domainupdatensResult = $domainAPI->updatens($postfields);
                $this->processResponseJson($domainupdatensResult);
                $vars = (object) $post;
            } else {
                $postfields = [];
                $postfields["domain"] = $service->name;
                $domaininfoResult = $domainAPI->info($postfields)->response_json();
                $vars->ns = [];
                for ($i = 0; $i < 5; $i++) {
                    if (isset($domaininfoResult->resultData->{'ns' . ($i + 1)})) {
                        $vars->ns[] = $domaininfoResult->resultData->{'ns' . ($i + 1)};
                    }
                }
            }
        } else {
            # No order-id; info is not available
            $show_content = false;
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    private function manageSettings($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $domainstatus = $this->domainstatus($view, $package, $service, $get, $post, $files);
        if ($domainstatus != 'active') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (strpos($uri_path, "admin/clients")) {
                $view = 'domain_not_active/admin/index';
            } else {
                $view = 'domain_not_active/client/index';
            }
            $this->view = new View($view, 'default');
            $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
            $this->view->set('domainstatus', $domainstatus);
            return $this->view->fetch();
        }
        $vars = new stdClass();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $domainAPI = new SrsxDomain($api);
        $fields = $this->serviceFieldsToObject($service->fields);
        $show_content = true;
        if (property_exists($fields, 'domain-name')) {
            if (!empty($post)) {
                if (isset($post['registrar_lock'])) {
                    $postfields = [];
                    $postfields["domain"] = $service->name;
                    if ($post['registrar_lock'] == 'true') {
                        $postfields["reseller_lock"] = 1;
                    } else {
                        $postfields["reseller_lock"] = 0;
                    }
                    $domainsetlockResult = $domainAPI->set_lock($postfields);
                    $this->processResponseJson($domainsetlockResult);
                }
                $vars = (object) $post;
            } else {
                $postfields = [];
                $postfields["domain"] = $service->name;
                $domaininfoResult = $domainAPI->info($postfields)->response_json();
                if ($domaininfoResult) {
                    $vars->registrar_lock = 'false';
                    $domaingetlockResult = $domainAPI->get_lock($postfields)->response_json();
                    if (($domaingetlockResult->resultData->domainlock)) {
                        $vars->registrar_lock = 'true';
                    }
                    $vars->epp_code = ($domaininfoResult->resultData->authcode);
                }
            }
        } else {
            # No order-id; info is not available
            $show_content = false;
        }
        $view = ($show_content ? $view : 'tab_unavailable');
        $this->view = new View($view, 'default');
        # Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'srsx' . DS);
        //return $this->add_irtp_to_view_client($this->view->fetch(), $package, $service);
        return $this->view->fetch();
    }

    public function checkAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');
        $api->loadCommand("srsx_domain");
        $domainAPI = new SrsxDomain($api);
        $postfields = array(
            "domain" => $domain,
        );
        if (!$this->is_valid_domain_name($domain)) {
            // $this->Input->setErrors(['errors' => ["error" => 'invalid domain name']]);
            return false;
        }
        $domaininfoResult = $domainAPI->check($postfields);
        if ($domaininfoResult->status() != "OK") {
            return false;
        }
        return true;
    }

    public function is_valid_domain_name($domain_name)
    {
        return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain_name) //valid chars check
             && preg_match("/^.{1,253}$/", $domain_name) //overall length check
             && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain_name)); //length of each label
    }

    private function getRowRules(&$vars)
    {
        return array(
            'reseller_id' => array(
                'valid' => array(
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Srsx.!error.reseller_id.valid', true),
                ),
            ),
            'username' => array(
                'valid' => array(
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Srsx.!error.username.valid', true),
                ),
            ),
            'password' => array(
                'valid' => array(
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Srsx.!error.password.valid', true),
                ),
                'valid_connection' => array(
                    'rule' => array(
                        array($this, 'validateConnection'),
                        $vars['reseller_id'],
                        $vars['username'],
                        isset($vars['sandbox']) ? $vars['sandbox'] : 'false',
                    ),
                    'message' => Language::_('Srsx.!error.password.valid_connection', true),
                ),
            ),
        );
    }

    public function validateConnection($password, $reseller_id, $username, $sandbox)
    {
        $api = $this->getApi($reseller_id, $username, $password, $sandbox == 'true');
        $api->loadCommand('srsx_domain');
        $domain = new SrsxDomain($api);
        $postfields = $_POST;
        return $domain->validate($postfields)->status() == 'OK';
    }

    private function getApi($reseller_id, $username, $password, $sandbox)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'srsx_api.php');
        return new SrsxApi($reseller_id, $username, $password, $sandbox);
    }

    private function processResponseJson($response)
    {
        if ($error = $response->errors_json()) {
            $this->Input->setErrors(['errors' => ['error' => $error]]);
        }
    }

    public function getTlds($module_row_id = null)
    {
        return Configure::get('Srsx.tlds');
    }

    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        Loader::loadModels($this, ['Currencies']);
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->username, $row->meta->password, $row->meta->sandbox == 'true');

        $currencies = [];
        $company_currencies = $this->Currencies->getAll(Configure::get('Blesta.company_id'));
        foreach ($company_currencies as $currency) {
            $currencies[$currency->code] = $currency;
        }

        $api->loadCommand("srsx_domain");
        $domainAPI = new SrsxDomain($api);
        $domainResult = $domainAPI->get_pricelist();
        if ($domainResult->status() != 'OK') {
            return false;
        }

        $response = $domainResult->response_json();
        if ($response->result->resultCode != 1000) {
            return false;
        }

        $srsx_currency = $response->resultData->currency;
        $srsx_pricings = $response->resultData->pricelist;

        if (!in_array($srsx_currency, array_keys($currencies))) {
            $this->Input->setErrors(['currency' => ['not_exists' => Language::_('Srsx.!error.currency.not_exists', true)]]);

            return false;
        }

        $tldPricings = [];
        foreach ($srsx_pricings as $pricing) {
            $tldPricings[$pricing->name][$srsx_currency] = [
                1 => [
                    'register' => $pricing->register->{1} <= 0 ? 0 : $pricing->register->{1},
                    'transfer' => $pricing->transfer->{1} <= 0 ? 0 : $pricing->transfer->{1},
                    'renew' => $pricing->renew->{1} <= 0 ? 0 : $pricing->renew->{1},
                ],
                2 => [
                    'register' => $pricing->register->{2} <= 0 ? 0 : $pricing->register->{2},
                    'transfer' => $pricing->transfer->{2} <= 0 ? 0 : $pricing->transfer->{2},
                    'renew' => $pricing->renew->{2} <= 0 ? 0 : $pricing->renew->{2},
                ],
                3 => [
                    'register' => $pricing->register->{3} <= 0 ? 0 : $pricing->register->{3},
                    'transfer' => $pricing->transfer->{3} <= 0 ? 0 : $pricing->transfer->{3},
                    'renew' => $pricing->renew->{3} <= 0 ? 0 : $pricing->renew->{3},
                ],
                4 => [
                    'register' => $pricing->register->{4} <= 0 ? 0 : $pricing->register->{4},
                    'transfer' => $pricing->transfer->{4} <= 0 ? 0 : $pricing->transfer->{4},
                    'renew' => $pricing->renew->{4} <= 0 ? 0 : $pricing->renew->{4},
                ],
                5 => [
                    'register' => $pricing->register->{5} <= 0 ? 0 : $pricing->register->{5},
                    'transfer' => $pricing->transfer->{5} <= 0 ? 0 : $pricing->transfer->{5},
                    'renew' => $pricing->renew->{5} <= 0 ? 0 : $pricing->renew->{5},
                ],
                6 => [
                    'register' => $pricing->register->{6} <= 0 ? 0 : $pricing->register->{6},
                    'transfer' => $pricing->transfer->{6} <= 0 ? 0 : $pricing->transfer->{6},
                    'renew' => $pricing->renew->{6} <= 0 ? 0 : $pricing->renew->{6},
                ],
                7 => [
                    'register' => $pricing->register->{7} <= 0 ? 0 : $pricing->register->{7},
                    'transfer' => $pricing->transfer->{7} <= 0 ? 0 : $pricing->transfer->{7},
                    'renew' => $pricing->renew->{7} <= 0 ? 0 : $pricing->renew->{7},
                ],
                8 => [
                    'register' => $pricing->register->{8} <= 0 ? 0 : $pricing->register->{8},
                    'transfer' => $pricing->transfer->{8} <= 0 ? 0 : $pricing->transfer->{8},
                    'renew' => $pricing->renew->{8} <= 0 ? 0 : $pricing->renew->{8},
                ],
                9 => [
                    'register' => $pricing->register->{9} <= 0 ? 0 : $pricing->register->{9},
                    'transfer' => $pricing->transfer->{9} <= 0 ? 0 : $pricing->transfer->{9},
                    'renew' => $pricing->renew->{9} <= 0 ? 0 : $pricing->renew->{9},
                ],
                10 => [
                    'register' => $pricing->register->{10} <= 0 ? 0 : $pricing->register->{10},
                    'transfer' => $pricing->transfer->{10} <= 0 ? 0 : $pricing->transfer->{10},
                    'renew' => $pricing->renew->{10} <= 0 ? 0 : $pricing->renew->{10},
                ],
            ];
        }

        for ($year = 1; $year <= 10; $year++) {
            foreach ($tldPricings as $tld => $items) {
                $pricing = $items[$srsx_currency];
                if (($pricing[$year]['register'] <= 0) && 
                    ($pricing[$year]['transfer'] <= 0) && 
                    ($pricing[$year]['renew']) <= 0) {
                        unset($tldPricings[$tld][$srsx_currency][$year]);
                    }
            }
        }

        return $tldPricings;
    }

    private function getTld($domain, $top = false)
    {
        $tlds = Configure::get('Srsx.tlds');
        $domain = strtolower($domain);
        if (!$top) {
            foreach ($tlds as $tld) {
                if (substr($domain, -strlen($tld)) == $tld) {
                    return $tld;
                }
            }
        }
        return strrchr($domain, '.');
    }

    private function formatPhone($number, $country)
    {
        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }
        return $this->Contacts->intlNumber($number, $country, ".");
    }

    private function logmessage($type = false, $message = false)
    {
        $configDir = dirname(__FILE__) . DS . "logs";
        if (!is_dir($configDir)) {
            mkdir($configDir);
        }
        $file = "{$configDir}" . DS . "logsrsx-" . date('Y-m-d') . ".php";
        if (!file_exists($file)) {
            error_log("<?php defined(\"BASEPATH\") OR exit(\"No direct script access allowed\"); ?>\n\n", 3, $file);
        }
        error_log("[" . date('Y-m-d H:i:s') . "] {$type} : {$message}\n", 3, $file);
    }

    private function randomhash($length = 6)
    {
        $base = 'ABCDEFGHKLMNOPQRSTWXYZ123456789';
        $max = strlen($base) - 1;
        $randomResult = "";
        mt_srand((double) microtime() * 1000000);
        while (strlen($randomResult) < $length) {
            $randomResult .= $base[mt_rand(0, $max)];
        }
        return $randomResult;
    }

}
