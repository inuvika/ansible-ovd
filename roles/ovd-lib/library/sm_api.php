#!/usr/bin/env php
<?php
/**
 * Copyright (C) 2018 Inuvika Inc.
 * https://www.inuvika.com
 * Author David PHAM-VAN <d.phamvan@inuvika.com> 2018
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class AdminAPI {
	public function __construct($host_, $login_, $password_) {
		$this->host = $host_;
		$this->login = $login_;
		$this->password = $password_;
		$this->base_url = 'https://'.$this->host.'/ovd/service/admin/';
		$this->cookies = [];
	}


	public function __call($func_, $args_) {
		$payload = null;
		if ($args_) {
			$payload = ['args' => $args_];
		}

		$res = $this->curl_request('POST', $func_, $payload);
		if ($res['rc'] != 200) {
			if (!isset($res['data']['error'])) {
				throw new Exception('Communication error');
			}
			if (@$res['data']['error']['code'] == 'not_authorized') {
				throw new Exception('You are not allowed to perform this action');
			}

			throw new APIException(
				$res['data']['error']['code'],
				@$res['data']['error']['message'],
				$res['data']['error']
			);
		}

		return $res['data'];
	}

	protected function curl_request($method, $url_, $data_in_ = null) {
		$socket = curl_init($this->base_url.$url_);
		curl_setopt($socket, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($socket, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($socket, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($socket, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($socket, CURLOPT_FILETIME, true);
		curl_setopt($socket, CURLOPT_CUSTOMREQUEST, $method);

		if ($this->cookies) {
			$cookie_string = '';
			foreach ($this->cookies as $k => $v) {
				$cookie_string.= $k.'='.$v.'; ';
			}

			curl_setopt($socket, CURLOPT_COOKIE, $cookie_string);
		}
		else {
			curl_setopt($socket, CURLOPT_USERPWD, $this->login.':'.$this->password);
		}

		$headers = ['Connection: close'];
		if ($data_in_) {
			curl_setopt($socket, CURLOPT_POSTFIELDS, json_encode($data_in_));
			$headers []= 'Content-Type: application/json';
		}

		curl_setopt($socket, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($socket, CURLOPT_HEADER, 1);

		$data_out = curl_exec($socket);

		$rc = curl_getinfo($socket, CURLINFO_HTTP_CODE);
		$headers_size = curl_getinfo($socket, CURLINFO_HEADER_SIZE);
		curl_close($socket);
		$headers = substr($data_out, 0, $headers_size);
		$body = substr($data_out, $headers_size);

		return $this->parse_result($rc, $headers, $body);
	}


	protected function parse_result($code, $headers, $body) {
		$result = [
			'rc' => $code,
			'headers' => [],
		];

		preg_match_all('@^([^:\n]+): (.*)$@m', $headers, $matches, PREG_SET_ORDER);

		foreach ($matches as $item) {
			if (array_key_exists($item[1], $result['headers'])) {
				$result['headers'][$item[1]] = [
					$result['headers'][$item[1]],
					trim($item[2]),
				];
			}
			else {
				$result['headers'][$item[1]] = trim($item[2]);
			}
		}

		if (preg_match('#Content-Type: application/json(;|$)#i', $headers)) {
			$result['data'] = json_decode($body, true);
		}
		else {
			$result['raw'] = $body;
		}

		if (isset($result['data']['message'])) {
			$this->last_error_message = $result['data']['message'];
		} else {
			$this->last_error_message = str_replace(
				'{code}', $code,
				_('Unexpected response: {code}')
			);
		}

		if (isset($result['headers']['Set-Cookie'])) {
			$items = is_array($result['headers']['Set-Cookie']) ? $result['headers']['Set-Cookie'] : [$result['headers']['Set-Cookie']];

			foreach ($items as $item) {
				$header_item = self::parse_cookie_line($item);

				foreach ($header_item['cookies'] as $name => $value) {
					$this->cookies[$name] = $value;
				}
			}
		}

		return $result;
	}

	private static function parse_cookie_line($cookie_line_) {
		$result = [
			'cookies' => [],
			'expires' => 0,
			'path'    => '',
			'domain'  => '',
		];

		$items = explode(';', $cookie_line_);

		foreach ($items as $item) {
			$sub_item = explode('=', $item, 2);

			if (count($sub_item)<2) {
				continue;
			}

			$key   = trim($sub_item[0]);
			$value = trim($sub_item[1]);

			if (in_array($key, ['version', 'path', 'expires', 'domain'])) {
				$result[$key] = $value;
			}
			else {
				$result['cookies'][$key] = $value;
			}
		}

		return $result;
	}
}

class APIException extends Exception {
	public $faultcode;
	public $detail;

	public function __construct($faultcode, $message, $detail=null) {
		parent::__construct($message);
		$this->faultcode = $faultcode;
		$this->detail = $detail;
	}
}


abstract class Ansible {
	private static $string_boolean_false = ["no",  "false", "0"];
	private static $string_boolean_true  = ["yes", "true",  "1"];

	protected $options;

	protected static $base_parameters = [
		"_ansible_check_mode" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		'_ansible_diff' => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		'_ansible_no_log' => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		'_ansible_verbosity' => [
			'type' => 'int',
			'required' => false,
			'default' => 0,
		],
	];
	protected $parameters = [];

	public function __construct() {
		$this->parameters = array_merge($this->parameters, self::$base_parameters);
	}

	private function init_parameters($args_) {
		$this->options = [];

		$args_ = str_replace(["\n", "\r"], ["\\n", "\\r"], $args_);
		$args_ = json_decode ($args_, true);
		if (!is_array($args_)) {
			throw new Exception('Invalid arguments passed to the module. Expect JSON dict');
		}

		// also check that array is dict and not list

		foreach ($args_ as $key => $value) {
			if (!isset($this->parameters[$key])) {
				if (strpos($key, '_ansible_') === 0) {
					continue;
				}
				else {
					throw new Exception('Unknown parameter "'.$key.'"');
				}
			}

			switch($this->parameters[$key]['type']) {
				case 'int':
					if (!is_numeric($value)) {
						throw new Exception('Parameter "'.$key.'" expects type int. Type provided: '.var_export($value, true));
					}

					$this->options[$key] = intval($value);
					break;
				case 'string':
					if (
						isset($this->parameters[$key]['choices'])
							&&
						is_array($this->parameters[$key]['choices'])
							&&
						!in_array($value, $this->parameters[$key]['choices'])
					) {
						throw new Exception('Parameter "'.$key.'", value '.var_export($value, true).' not in: '.implode(', ', $this->parameters[$key]['choices']));
					}

					$this->options[$key] = $value;
					break;
				case 'boolean':
					if (!is_bool($value)) {
						throw new Exception('Parameter "'.$key.'" expects type boolean. Type provided: '.var_export($value, true));
					}

					$this->options[$key] = $value;
					break;
				case 'list':
				case 'dict':
					if (!is_array($value)) {
						throw new Exception('Parameter "'.$key.'" expects type '.$this->parameters[$key]['type'].' (3). Type provided: '.var_export($value, true));
					}

					$this->options[$key] = $value;
					break;
				default:
					throw new Exception('Internal error');
			}
		}

		foreach($this->parameters as $key => $parameter) {
			if (!array_key_exists($key, $this->options)) {
				if (array_key_exists('default', $parameter)) {
					$this->options[$key] = $parameter['default'];
				}
				else if ($parameter['required']) {
					throw new Exception('Parameter "'.$key.'" is required');
				}
			}
		}
	}


	public function run($args_) {
		ob_start();
		try {
			$this->init_parameters($args_);
			$result = $this->process();
		}
		catch (Exception $e) {
			$output = ob_get_contents();
			ob_end_clean();

			$result = [
				"failed" => true,
				"msg" => (string)$e,
			];

			if (@$this->options['_ansible_verbosity']) {
				$result["stdout"] = $output;
			}

			$this->json_exit($result, 2);
		}

		$output = ob_get_contents();
		ob_end_clean();

		if (@$this->options['_ansible_verbosity'] > 1) {
			$result["stdout"] = $output;
		}

		$this->json_exit($result);
	}

	abstract protected function process();


	protected function json_exit($data, $exit_code=0) {
		$json_opts = 0;
		if (defined ('JSON_PRETTY_PRINT')) {
			$json_opts|= JSON_PRETTY_PRINT;
		}

		echo json_encode($data)."\n";
		exit($exit_code);
	}
}


class AnsibleSm extends Ansible {
	protected $parameters = [
		"host" => [
			'type' => 'string',
			'required' => false,
			'default' => "127.0.0.1",
		],
		"user" =>  [
			'type' => 'string',
			'required' => true,
		],
		"password" =>  [
			'type' => 'string',
			'required' => true,
		],
		"settings" => [
			'type' => 'dict',
			'required' => false,
			'default' => [],
		],
		"purge_all_sessions" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		"subscription_key" => [
			'type' => 'string',
			'required' => false,
			'default' => null,
		],
		"populate" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		"populate_filter_apps" => [
			'type' => 'list',
			'required' => false,
			'default' => [],
		],
		"organization" => [
			'type' => 'dict',
			'required' => false,
			'default' => [],
		],
	];

	private $organization_selected = false;
	private $config = null;
	private $service;

	protected function getSetting($key) {
		if ($this->config === null) {
			$this->config = $this->service->settings_get();
		}

		$sconfig = $this->config;
		$pkey = explode(".", $key);
		while($item = array_shift($pkey)) {
			$sconfig = $sconfig[$item];
		}

		return $sconfig["value"];
	}

	private function populate() {
		if (!$this->organization_selected) {
			$conf = $this->service->getInitialConfiguration();

			$orgs = $this->service->organizations_list();
			$org = null;
			foreach ($orgs as $o) {
				if ($o['default']) {
					$org = $o['id'];
					break;
				}
			}

			if ($org != null) {
				$org = array_shift($orgs);
				$org = $org['id'];
			}

			$this->organization_selected = $org;
		}

		$this->service->organization_select(null, true);
		$servers = $this->service->servers_list(null, false, []);
		foreach($servers as $server) {
			$this->service->server_share($server["id"], $this->organization_selected);
		}

		$servers = $this->service->servers_list("unregistered", false, []);
		foreach($servers as $server) {
			if (!$server["can_register"]) {
				continue;
			}

			$this->service->server_register($server["id"]);
			$this->service->server_switch_maintenance($server["id"], false);
			$this->service->server_share($server["id"], $this->organization_selected);
		}

		$this->service->organization_select($this->organization_selected, true);

		$this->service->users_populate(false, null);
		$group = $this->service->users_groups_list_partial("All Users", ["name"], null);
		if ($group['data']) {
			$ug_id = reset($group['data'])['id'];
		}
		else {
			$ug_id = $this->service->users_group_add("All Users", "Default users group");
			if ($ug_id == false) {
				throw new Exception('populate: the user group already exists');
			}
		}

		$this->service->system_set_default_users_group($ug_id);

		foreach(['linux', 'windows'] as $os) {
			$apps = array_filter(
				$this->service->applications_list($os),
				function($app) {
					foreach($this->options["populate_filter_apps"] as $flt) {
						if (stripos($app['executable_path'], $flt) !== false) {
							return false;
						}
					}

					return true;
				}
			);

			if (!$apps) {
				continue;
			}

			$apps = $this->service->applications_list($os);
			if (count($apps) > 0) {
				$name = ucfirst($os)." applications";

				$ag_id = $this->service->applications_group_add($name, $name);
				if ($ag_id) {
					$this->service->publication_add($ug_id, $ag_id);
					foreach($apps as $app) {
						$this->service->applications_group_add_application($app["id"], $ag_id);
					}
				}
			}
		}
	}


	private function organization_from_name($name) {
		$orgs = $this->service->organizations_list();

		foreach ($orgs as $o) {
			if ($name == $o['name']) {
				return $o;
			}
		}

		return null;
	}


	private function organization_present($param) {
		$org = $this->organization_from_name(@$param['name']);
		if ($org) {
			# Updating existing organization if change needed
			$param['id'] = $org['id'];

			foreach (['name', 'description', 'max_ccu', 'domains'] as $attrib) {
				if (isset($param[$attrib]) and $param[$attrib] != $org[$attrib]) {
					if (!$this->service->organization_modify($param)) {
						throw new Exception('organization_present: Unable to update organization');
					}
					return ['changed' => true];
				}
			}
			return ['changed' => false];
		}

		# Creating new organization
		if (!$this->service->organization_add($param)) {
			throw new Exception('organization_present: Unable to add organization');
		}

		return ['changed' => true];
	}


	private function organization_absent($param) {
		$org = $this->organization_from_name(@$param['name']);
		if (!$org) {
			return ['changed' => false];
		}

		if (!$this->service->organization_remove($org['id'])) {
			throw new Exception('organization_absent: Unable to remove organization');
		}

		return ['changed' => true];
	}

	private function organization_select($param) {
		$org = $this->organization_from_name(@$param['name']);
		if (!$org) {
			throw new Exception('organization_select: Organization does not exist');
		}

		if (!$this->service->organization_select($org['id'], true)) {
			throw new Exception('organization_select: Unable to select organization');
		}

		$this->organization_selected = $org['id'];
	}


	protected function process() {
		$this->service = new AdminApi(
			$this->options["host"],
			$this->options["user"],
			$this->options["password"]
		);

		$changed = false;
		$diff = [];

		if ($this->options["organization"]) {
			$param = $this->options["organization"];
			$changed = true;
			if (!$this->options['_ansible_check_mode']) {
				if (!@$param['state']) {
					throw new Exception('state missing in organization parameter');
				}

				if (!@$param['name']) {
					throw new Exception('name missing in organization parameter');
				}

				switch($param['state']) {
					case 'select':
						$ret = $this->organization_select($param);
						break;

					case 'present':
						$ret = $this->organization_present($param);
						break;

					case 'absent':
						$ret = $this->organization_absent($param);
						break;

					default:
						throw new Exception('"state" invalid in organization parameter. valid values are "present","absent","select"');
				}
			}
		}

		if ($this->options["settings"]) {
			$config_modified = [];
			foreach($this->options["settings"] as $key => $value) {
				$value_saved = $this->getSetting($key);
				$diff['before'][$key] = $value_saved;
				$diff['after'][$key] = $value;
				if ($value == $value_saved) {
					continue;
				}

				$config_modified[$key] = $value;
				$changed = true;
			}

			if ($config_modified && !$this->options['_ansible_check_mode']) {
				$ret = $this->service->settings_set($config_modified);
				if (!$ret) {
					throw new Exception('settings_set returned unexpected value '.var_export($ret, true));
				}
			}
		}

		if ($this->options["purge_all_sessions"]) {
			$sessions = $this->service->sessions_list([]);
			if ($sessions) {
				$changed = true;
				$diff['before']['purge_all_sessions'] = array_keys($sessions);
				$diff['after']['purge_all_sessions'] = [];
				if (!$this->options['_ansible_check_mode']) {
					while (is_array($sessions) && count($sessions) > 0) {
						echo count($sessions) . " sessions left\n";
						foreach ($sessions as $session) {
							$this->service->session_kill($session["id"]);
						}

						sleep(1);
						$sessions = $this->service->sessions_list([]);
					}
				}
			}
		}

		if ($this->options["subscription_key"]) {
			$changed = true;

			if (!$this->options['_ansible_check_mode']) {
				if ($this->service->certificate_add($this->options["subscription_key"]) == 1) {
					throw new Exception('certificate_add returned unexpected value');
				}
			}
		}

		if ($this->options["populate"]) {
			$changed = true;
			if (!$this->options['_ansible_check_mode']) {
				$this->populate();
			}
		}

		if (!isset($ret["changed"])) {
			$ret = ["changed" => $changed];
		}

		if ($this->options['_ansible_diff'] && !$this->options['_ansible_no_log']) {
			$ret['diff'] = $diff;
		}

		return $ret;
	}
}

$args = <<<EOD
<<INCLUDE_ANSIBLE_MODULE_JSON_ARGS>>
EOD;

$ansible = new AnsibleSm();
$ansible->run($args);
