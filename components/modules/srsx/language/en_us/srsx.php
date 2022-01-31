<?php

// Tooltips
$lang['Srsx.!tooltip.row_meta.sandbox'] = 'If Sandbox is checked, you must define test/demo account credentials. Using your production account credentials with Sandbox checked will still perform live actions.';

// Basics
$lang['Srsx.name'] = 'SRS-X';
$lang['Srsx.description'] = 'Module domain registrar Digital Registra';
$lang['Srsx.module_row'] = 'Account';
$lang['Srsx.module_row_plural'] = 'Accounts';

// Module management
$lang['Srsx.add_module_row'] = 'Add Reseller Account';
$lang['Srsx.manage.module_rows_title'] = 'Accounts';
$lang['Srsx.manage.module_rows_heading.registrar'] = 'Registrar Name';
$lang['Srsx.manage.module_rows_heading.reseller_id'] = 'Reseller ID';
$lang['Srsx.manage.module_rows_heading.username'] = 'API Username';
$lang['Srsx.manage.module_rows_heading.password'] = 'API Password';
$lang['Srsx.manage.module_rows_heading.sandbox'] = 'Sandbox';
$lang['Srsx.manage.module_rows_heading.options'] = 'Options';
$lang['Srsx.manage.module_rows.edit'] = 'Edit';
$lang['Srsx.manage.module_rows.delete'] = 'Delete';
$lang['Srsx.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this account?';
$lang['Srsx.manage.module_rows_no_results'] = 'There are no accounts.';

// Row Meta
$lang['Srsx.row_meta.registrar'] = 'Registrar Name';
$lang['Srsx.row_meta.reseller_id'] = 'Reseller ID';
$lang['Srsx.row_meta.username'] = 'API Username';
$lang['Srsx.row_meta.password'] = 'API Password';
$lang['Srsx.row_meta.sandbox'] = 'Sandbox';
$lang['Srsx.row_meta.sandbox_true'] = 'Yes';
$lang['Srsx.row_meta.sandbox_false'] = 'No';

// Add row
$lang['Srsx.add_row.box_title'] = 'Add Reseller Account';
$lang['Srsx.add_row.basic_title'] = 'Basic Settings';
$lang['Srsx.add_row.add_btn'] = 'Add Account';

// Edit row
$lang['Srsx.edit_row.box_title'] = 'Edit Reseller Account';
$lang['Srsx.edit_row.basic_title'] = 'Basic Settings';
$lang['Srsx.edit_row.add_btn'] = 'Update Account';

// Package fields
$lang['Srsx.package_fields.type'] = 'Type';
$lang['Srsx.package_fields.type_domain'] = 'Domain Registration';
$lang['Srsx.package_fields.tld_options'] = 'TLDs';
$lang['Srsx.package_fields.ns1'] = 'Name Server 1';
$lang['Srsx.package_fields.ns2'] = 'Name Server 2';
$lang['Srsx.package_fields.ns3'] = 'Name Server 3';
$lang['Srsx.package_fields.ns4'] = 'Name Server 4';

//renew

$lang['Srsx.Renew.title'] = 'Renew';

// Service management
$lang['Srsx.tab_unavailable.message'] = 'This information is not yet available.';

$lang['Srsx.tab_whois.title'] = 'WHOIS';
$lang['Srsx.tab_whois.section_registrant'] = 'Registrant';
$lang['Srsx.tab_whois.section_admin'] = 'Administrative';
$lang['Srsx.tab_whois.section_tech'] = 'Technical';
$lang['Srsx.tab_whois.section_billing'] = 'Billing';
$lang['Srsx.tab_whois.section_contact'] = 'WHOIS Contact';
$lang['Srsx.tab_whois.field_submit'] = 'Update WHOIS';

$lang['Srsx.tab_nameservers.title'] = 'Name Servers';
$lang['Srsx.tab_nameserver.field_ns'] = 'Name Server %1$s'; // %1$s is the name server number
$lang['Srsx.tab_nameservers.field_submit'] = 'Update Name Servers';

$lang['Srsx.tab_settings.title'] = 'Registrar Lock';
$lang['Srsx.tab_settings.field_registrar_lock'] = 'Registrar Lock';
$lang['Srsx.tab_settings.field_registrar_lock_yes'] = 'Set the registrar lock. Recommended to prevent unauthorized transfer.';
$lang['Srsx.tab_settings.field_registrar_lock_no'] = 'Release the registrar lock so the domain can be transferred.';
$lang['Srsx.tab_settings.field_request_epp'] = 'Request EPP Code/Transfer Key';
$lang['Srsx.tab_settings.field_submit'] = 'Update Settings';

$lang['Srsx.tab_idp.title'] = 'ID Protection';

//eppp
$lang['Srsx.tab_epp.title'] = 'EPP';

//contact
$lang['Srsx.tab_client_contact.title'] = 'Contact';
$lang['Srsx.tab_client_contact.changecontact_desc'] = 'Select an existent contact to use for any of the 4 contact type reg-c, admin-c, tech-c and bill-c for this domain. By default, there exist only one Contact which has been created for you using your Customer details. If you wish to create a new contact or would modify an existent contact, use the options "Create Contact" or "Modify Contact"';
$lang['Srsx.tab_client_contact.clientareasavechanges'] = 'Save Changes';
$lang['Srsx.tab_client_contact.clientareabacklink'] = 'Back';
$lang['Srsx.tab_client_contact.modifycontact_desc'] = 'Select the contact you wish to modify from the dropdown below and click the "Modify Selected Contact" button.';
$lang['Srsx.tab_client_contact.deletecontact_desc'] = 'Select the Contact you wish to delete and click the button "Delete Selected Contact"';
$lang['Srsx.tab_client_contact.createcontact_desc'] = 'Create a new contact using the form below. Once created the new contact, you can apply it by using the option "Change Contacts" for this and for any other domains you have with us."';
$lang['Srsx.tab_client_contact.clientareafirstname'] = 'First Name';
$lang['Srsx.tab_client_contact.clientarealastname'] = 'Last Name';
$lang['Srsx.tab_client_contact.clientareacompanyname'] = 'Company Name';
$lang['Srsx.tab_client_contact.loginemail'] = 'Email Address';
$lang['Srsx.tab_client_contact.clientareaaddress1'] = 'Address 1';
$lang['Srsx.tab_client_contact.clientareaaddress2'] = 'Address 2';
$lang['Srsx.tab_client_contact.clientareaaddress3'] = 'Address 3';
$lang['Srsx.tab_client_contact.clientareacity'] = 'City';
$lang['Srsx.tab_client_contact.clientareastate'] = 'State/Region';
$lang['Srsx.tab_client_contact.clientareacountry'] = 'Country';
$lang['Srsx.tab_client_contact.clientareaphonenumber'] = 'Phone Number';
$lang['Srsx.tab_client_contact.clientareapostcode'] = 'Zip Code';

//childns
$lang['Srsx.tabClientChildNs.title'] = 'Child NS';
$lang['Srsx.tabClientDNSSEC.title'] = 'DNSSEC';
$lang['Srsx.tabClientDNS.title'] = 'DNS Management';

//domain forwarding
$lang['Srsx.tabDomainForwarding.title'] = 'Domain Forwarding';

//domainid
$lang['Srsx.tab_domainid.title'] = 'Domain ID';
$lang['Srsx.tab_domainid.domain_status'] = 'Domain Status';
$lang['Srsx.tab_domainid.document_status'] = 'Document Status';
$lang['Srsx.tab_domainid.document_upload'] = 'Upload Document';
$lang['Srsx.tab_domainid.document_upload_button'] = 'Upload';
$lang['Srsx.tab_domainid.notification'] = 'Notification';
$lang['Srsx.tab_domainid.notification_reply_button'] = 'Reply';

// Errors
$lang['Srsx.!error.registrar.valid'] = 'Please enter a registrar name.';
$lang['Srsx.!error.reseller_id.valid'] = 'Please enter a reseller ID.';
$lang['Srsx.!error.username.valid'] = 'Please enter API username.';
$lang['Srsx.!error.password.valid'] = 'Please enter API password.';
$lang['Srsx.!error.password.valid_connection'] = 'The reseller ID, API username and API password combination appear to be invalid, or your Reseller account may not be configured to allow API access.';

// Domain Transfer Fields
$lang['Srsx.transfer.domain-name'] = 'Domain Name';
$lang['Srsx.transfer.epp-code'] = 'EPP Code';

// Domain Fields
$lang['Srsx.domain.domain-name'] = 'Domain Name';

// Nameserver Fields
$lang['Srsx.nameserver.ns1'] = 'Name Server 1';
$lang['Srsx.nameserver.ns2'] = 'Name Server 2';
$lang['Srsx.nameserver.ns3'] = 'Name Server 3';
$lang['Srsx.nameserver.ns4'] = 'Name Server 4';

// Contact Fields
$lang['Srsx.contact.contactid'] = 'Contact ID';
$lang['Srsx.contact.nickhandle'] = 'Nickhandle';
$lang['Srsx.contact.fname'] = 'First Name';
$lang['Srsx.contact.lname'] = 'Last Name';
$lang['Srsx.contact.email'] = 'Email';
$lang['Srsx.contact.company'] = 'Company';
$lang['Srsx.contact.address'] = 'Address';
$lang['Srsx.contact.address2'] = 'Address 2';
$lang['Srsx.contact.address3'] = 'Address 3';
$lang['Srsx.contact.city'] = 'City';
$lang['Srsx.contact.state'] = 'State';
$lang['Srsx.contact.phonenumber'] = 'Phone Number';
$lang['Srsx.contact.fax'] = 'Fax Number';
$lang['Srsx.contact.country'] = 'Country';
$lang['Srsx.contact.postcode'] = 'Postal Code';
