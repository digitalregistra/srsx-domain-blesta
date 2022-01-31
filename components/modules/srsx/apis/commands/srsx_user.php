<?php
/**
 * SRS-X User Management
 *
 * @copyright Copyright (c) 2019, PT. Digital Registra Indonesia
 * @package srsx.commands
 */
class SrsxUser {

	private $api;

	public function __construct(SrsxApi $api) {
		$this->api = $api;
	}

	public function create(array $vars) {
		return $this->api->submit_json('user/create',$vars);
	}

	public function update(array $vars) {
		return $this->api->submit_json('user/update',$vars);
	}

	public function info(array $vars) {
		return $this->api->submit_json('user/info',$vars);
	}

}
