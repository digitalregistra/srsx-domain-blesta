<?php
/**
 * SRS-X API response handler
 *
 * @copyright Copyright (c) 2019, PT. Digital Registra Indonesia
 * @package srsx
 */
class SrsxResponse
{

    private $response;

    public $raw;

    public function __construct($response)
    {
        $this->raw = $response;
        if ($json = $this->formatResponse_json($this->raw)) {
            $this->response = $json;
        } else if ($xml = $this->formatResponse($this->raw)) {
            $this->response = $xml;
        } else {
            $this->response = $this->raw;
        }
    }

    public function response()
    {
        return $this->response;
    }
    public function response_json()
    {
        return json_decode($this->raw);
    }

    public function status_json()
    {
        if ($this->errors_json()) {
            return "ERROR";
        } elseif ($this->raw) {
            return "OK";
        }
        return false;
    }

    public function status()
    {
        if ($this->raw === false) {
            return "FAILED";
        }

        if ($this->errors()) {
            return "ERROR";
        } elseif ($this->raw) {
            return "OK";
        }
        return null;
    }

    public function errors_json()
    {
        if (($this->response_json()->result->resultCode) != 1000) {
            if (isset($this->response_json()->result->resultMsg)) {
                return ($this->response_json()->result->resultMsg);
            }
            return "api format failed";
        }
        return false;
    }

    public function errors()
    {
        if (sprintf($this->response->result->resultCode) != 1000) {
            if (isset($this->response->result->resultMsg)) {
                return sprintf($this->response->result->resultMsg);
            }
            return "api format failed";
        }
        return false;
    }

    public function raw()
    {
        return $this->raw;
    }

    private function formatResponse($data)
    {
        return simplexml_load_string($data);
    }
    private function formatResponse_json($data)
    {
        return json_decode($data);

    }

}
