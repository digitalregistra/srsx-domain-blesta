<?php
/**
 * SRS-X Contact Management
 *
 * @copyright Copyright (c) 2019, PT. Digital Registra Indonesia
 * @package srsx.commands
 */
class SrsxContact {

    private $api;

    public function __construct(SrsxApi $api) {
        $this->api = $api;
    }

    public function create(array $vars) {
        return $this->api->submit_json('contact/create',$vars);
    }

    public function info(array $vars) {
        return $this->api->submit_json('contact/info',$vars);
    }

    public function update(array $vars) {
        return $this->api->submit_json('contact/update',$vars);
    }
    public function delete(array $vars) {
        return $this->api->submit_json('contact/delete',$vars);
    }

    public function getallcontact(array $vars) {
        return $this->api->submit_json('contact/getallcontact',$vars);
    }

}
