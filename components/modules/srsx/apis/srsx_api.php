<?php

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . "srsx_response.php";

/**
 * SRS-X API processor
 *
 * @copyright Copyright (c) 2019, PT. Digital Registra Indonesia
 * @package srsx
 */
class SrsxApi
{

    private $reseller_id;

    private $username;

    private $password;

    private $sandbox;

    private $last_request = array("url" => null, "args" => null);

    public function __construct($reseller_id, $username, $password, $sandbox = false)
    {
        $this->reseller_id = $reseller_id;
        $this->username = $username;
        $this->password = $password;
        $this->sandbox = $sandbox;
    }

    public function submit($command = false, $args = array())
    {
        if (preg_match("/a/", $this->reseller_id)) {
            $resellerId = substr($this->reseller_id, 0, -1);
            $url = "http://srb{$resellerId}.alpha.srs-x.com/";
        } else {
            if ($this->sandbox) {
                $url = "http://srb{$this->reseller_id}.srs-x.net/";
            } else {
                $url = "https://srb{$this->reseller_id}.srs-x.com/";
            }
        }
        $url .= "api/{$command}";
        $args["username"] = $this->username;
        $args["password"] = $this->password;
        $this->last_request = array(
            "url" => $url,
            "args" => $args,
        );
        $this->logmessage("submit_url", $url);
        $this->logmessage("submit_postfields:\n", print_r($this->protectField($args), true));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildQuery($args));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        // var_dump($url);
        // var_dump($this->buildQuery($args));
        // var_dump($response);
        $this->logmessage("submit_response", $response);
        return new SrsxResponse($response);
    }

    protected function protectField($postfields = false)
    {
        if (is_array($postfields)) {
            $fields = array("username", "password");
            foreach ($fields as $field) {
                if (isset($postfields[$field])) {
                    $postfields[$field] = "**********";
                }
            }
        }
        return $postfields;
    }

    public function submit_json($command = false, $args = array())
    {
        if (preg_match("/a/", $this->reseller_id)) {
            $resellerId = substr($this->reseller_id, 0, -1);
            $url = "http://srb{$resellerId}.alpha.srs-x.com/";
        } else {
            if ($this->sandbox) {
                $url = "http://srb{$this->reseller_id}.srs-x.net/";
            } else {
                $url = "https://srb{$this->reseller_id}.srs-x.com/";
            }
        }
        $url .= "api/{$command}";
        $args["username"] = $this->username;
        $args["password"] = $this->password;
        $args["respondType"] = 'json';
        $this->last_request = array(
            "url" => $url,
            "args" => $args,
        );
        $this->logmessage("submit_url", $url);
        $this->logmessage("submit_postfields:\n", print_r($this->protectField($args), true));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildQuery($args));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $this->logmessage("submit_response", $response);
        return new SrsxResponse($response);
    }

    public function lastRequest()
    {
        return $this->last_request;
    }

    public function loadCommand($command)
    {
        require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . "commands" . DIRECTORY_SEPARATOR . $command . ".php";
    }

    private function buildQuery(array $args)
    {
        $query = [];
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subkey => $subvalue) {
                    if (is_numeric($subkey)) {
                        $query[] = rawurlencode($key) . "=" . rawurlencode($subvalue);
                    } else {
                        $query[] = rawurlencode($key . "[" . $subkey . "]") . "=" . rawurlencode($subvalue);
                    }
                }
            } else {
                $query[] = rawurlencode($key) . "=" . rawurlencode($value);
            }
        }
        return implode("&", $query);
    }

    private function logmessage($type = false, $message = false)
    {
        $configDir = dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "logs";
        if (!is_dir($configDir)) {
            mkdir($configDir);
        }
        $file = "{$configDir}" . DIRECTORY_SEPARATOR . "logsrsx-" . date('Y-m-d') . ".php";
        if (!file_exists($file)) {
            error_log("<?php defined(\"BASEPATH\") OR exit(\"No direct script access allowed\"); ?>\n\n", 3, $file);
        }
        error_log("[" . date('Y-m-d H:i:s') . "] {$type} : {$message}\n", 3, $file);
    }

}
