<?php
/**
 * SRS-X Domain Management
 *
 * @copyright Copyright (c) 2019, PT. Digital Registra Indonesia
 * @package srsx.commands
 */
class SrsxDomain {

    private $api;

    public function __construct(SrsxApi $api) {
        $this->api = $api;
    }

    public function check(array $vars) {
        return $this->api->submit_json('domain/check',$vars);
    }

    public function info(array $vars) {
        return $this->api->submit_json('domain/info',$vars);
    }

    public function register(array $vars) {
        return $this->api->submit_json('domain/register',$vars);
    }

    public function transfer(array $vars) {
        return $this->api->submit_json('domain/transfer',$vars);
    }

    public function updatens(array $vars) {
        return $this->api->submit_json('domain/updatens',$vars);
    }

    public function cancel(array $vars) {
        return $this->api->submit_json('domain/cancel',$vars);
    }

    public function editcontact(array $vars) {
        return $this->api->submit_json('domain/editcontact',$vars);
    }

    public function renew(array $vars) {
        return $this->api->submit_json('domain/renew',$vars);
    }

    public function suspend(array $vars) {
        return $this->api->submit_json('domain/suspend',$vars);
    }

    public function unsuspend(array $vars) {
        return $this->api->submit_json('domain/unsuspend',$vars);
    }

    public function get_lock(array $vars) {
        return $this->api->submit_json('domain/get_lock',$vars);
    }

    public function set_lock(array $vars) {
        return $this->api->submit_json('domain/set_lock',$vars);
    }

    public function status(array $vars) {
        return $this->api->submit_json('domain/status',$vars);
    }

    public function status_json(array $vars) {
        return $this->api->submit_json('domain/status',$vars);
    }

    public function notificationurl(array $vars) {
        return $this->api->submit_json('domain/notificationurl',$vars);
    }

    public function documenturl(array $vars) {
        return $this->api->submit_json('domain/documenturl',$vars);
    }

    public function validate(array $vars) {
        return $this->api->submit_json('reseller/validate',$vars);
    }
    public function epp_get(array $vars) {
        return $this->api->submit_json('domain/getepp',$vars);
    }
    public function epp_set(array $vars) {
        return $this->api->submit_json('domain/setepp',$vars);
    }
    public function get_private_ns($vars) {
        return $this->api->submit_json('host/all',$vars);
    }
    public function register_private_ns($vars) {
        return $this->api->submit_json('host/addchild',$vars);
    }
    public function update_private_ns($vars) {
        return $this->api->submit_json('host/updatechild',$vars);
    }
    public function delete_private_ns($vars) {
        return $this->api->submit_json('host/delchild',$vars);
    }
    public function list_dnssec($vars) {
        return $this->api->submit_json('domain/listds',$vars);
    }
    public function add_dnssec($vars) {
        return $this->api->submit_json('domain/addds',$vars);
    }
    public function delete_dnssec($vars) {
        return $this->api->submit_json('domain/delds',$vars);
    }
    public function dns_info($vars) {
        return $this->api->submit_json('dns/info',$vars);
    }
    public function dns_init($vars) {
        return $this->api->submit_json('dns/start',$vars);
    }
    public function dns_edit($vars) {
        return $this->api->submit_json('dns/edit',$vars);
    }
    public function dns_add($vars) {
        return $this->api->submit_json('dns/create',$vars);
    }
    public function dns_delete($vars) {
        return $this->api->submit_json('dns/delete',$vars);
    }
    public function forward_status($vars) {
        return $this->api->submit_json('forward/status',$vars);
    }
    public function forward_init($vars) {
        return $this->api->submit_json('forward/configure',$vars);
    }
    public function forward_update($vars) {
        return $this->api->submit_json('forward/update',$vars);
    }
    public function veri_info($vars) {
        return $this->api->submit_json('domain/veri_info',$vars);
    }
    public function resend_raa($vars) {
        return $this->api->submit_json('domain/resend_raa',$vars);
    }
    public function set_idprotection($vars) {
        return $this->api->submit_json('domain/set_idprotection',$vars);
    }

}
