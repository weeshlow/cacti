<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2010 Boris Lytochkin                                      |
 | Sponsored by: Yandex LLC                                                |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
*/

define('SNMP_OID_OUTPUT_SUFFIX', 1);
define('SNMP_OID_OUTPUT_MODULE', 2);
define('SNMP_OID_OUTPUT_UCD', 5);
define('SNMP_OID_OUTPUT_NONE', 6);

class SNMP {
	const ERRNO_NOERROR		= 0;
	const ERRNO_GENERIC		= 1;
	const ERRNO_TIMEOUT		= 2;
	const ERRNO_ERROR_IN_REPLY	= 3;
	const ERRNO_OID_NOT_INCREASING	= 4;

	const VERSION_1 = 1;
	const VERSION_2C = 2;
	const VERSION_2c = SNMP::VERSION_2C;
	const VERSION_3 = 3;

	public $info;
	public $max_oids;
	public $valueretrieval;
	public $oid_output_format;
	public $enum_print;
	public $quick_print;
	public $oid_increasing_check;

	private $version;
	private $hostname;
	private $port;
	private $community;
	private $timeout;
	private $retries;
	
	private $username;
	private $sec_level;
	private $auth_proto;
	private $auth_pass;
	private $priv_proto;
	private $priv_pass;
	private $contextName;
	private $contextEngineID;

	private $errno;

	function __construct($version, $hostname, $community, $timeout = 1000000, $retries = 3) {
		$timeout /= 1000;
		$this->max_oids = 0;

		if (function_exists('snmp_get_valueretrieval')) {
			$this->valueretrieval = snmp_get_valueretrieval();
		}

		$this->oid_output_format = NULL;
		$this->enum_print = NULL;

		if (function_exists('snmp_get_quick_print')) {
			$this->quick_print = snmp_get_quick_print();
		}
		
		$this->version  = $version;
		$hostname       = explode(':', $hostname);
		$this->hostname = array_shift($hostname);
		$this->port     = (sizeof($hostname) ? array_shift($hostname) : 161);
		$this->timeout  = $timeout;
		$this->retries  = $retries;

		if ($version == SNMP::VERSION_3) {
			$this->username  = $community;
		} else {
			$this->community = $community;
		}

		$info = array (
			'hostname' => $this->hostname,
			'port' => $this->port,
			'timeout' => $this->timeout,
			'retries' => $this->retries,
		);
	}

	function __destruct() {
		$this->close();
	}

	function setSecurity($sec_level, $auth_protocol, $auth_passphrase, $priv_protocol, 
		$priv_passphrase, $contextName, $contextEngineID) {
		$this->sec_level       = $sec_level;
		$this->auth_protocol   = $auth_protocol;
		$this->auth_passphrase = $auth_passphrase;
		$this->priv_protocol   = $priv_protocol;
		$this->priv_passphrase = $priv_passphrase;
		$this->contextName     = $contextName;
		$this->contextEngineID = $contextEngineID;
		
		return TRUE;
	}

	function close() {
		return TRUE;
	}

	private function apply_options($backup = array()) {
		if (sizeof($backup) == 0) {
			if (function_exists('snmp_get_valueretrieval')) {
				$backup['valueretrieval'] = snmp_get_valueretrieval();
				snmp_set_valueretrieval($this->valueretrieval);

				$backup['quick_print']    = snmp_get_quick_print();
				snmp_set_quick_print($this->quick_print);

				if ($this->oid_output_format !== NULL) {
					snmp_set_oid_output_format($this->oid_output_format);
				}

				if ($this->enum_print !== NULL) {
					if (function_exists('snmp_set_enum_print')) {
						snmp_set_enum_print($this->enum_print);
					}
				}
			}
		} else {
			if (function_exists('snmp_get_valueretrieval')) {
				if (isset($backup['valueretrieval'])) {
					snmp_set_valueretrieval($backup['valueretrieval']);
				}

				if (isset($backup['quick_print'])) {
					snmp_set_quick_print($backup['quick_print']);
				}
			}
			$backup = TRUE;
		}
		
		return $backup;
	}
	
	private function uniget($command, $oids) {
		$this->errno = SNMP::ERRNO_NOERROR;
		$array_output = TRUE;
		$output = array();
		$function_name = "cacti_snmp_$command";
		$options_backup = $this->apply_options();
		if (!is_array($oids)) {
			$array_output = FALSE;
			$oids = array($oids);
		}

		foreach ($oids as $oid) {
			$output[$oid] = $function_name($this->hostname, $this->community, $oid, 
				$this->version, $this->username, $this->auth_pass, $this->auth_proto, 
				$this->priv_pass, $this->priv_proto, $this->contextName, $this->port, 
				$this->timeout, $this->retries, SNMP_POLLER, $this->contextEngineID);
		}

		$this->apply_options($options_backup);

		if (sizeof($output) == 0) {
			$this->errno = SNMP::TIMEOUT;
		}

		if ($array_output == FALSE) {
			if (sizeof($output) == 0) {
				return FALSE;
			}
			return array_shift($output);
		}
		
		return $output;
	}

	function get($oid) {
		return $this->uniget('get', $oid);
	}
	
	function getnext($oid) {
		return $this->uniget('getnext', $oid);
	}
	
	function walk($oid, $dummy = FALSE, $max_repetitions = 10, $non_repeaters = 0) {
		$this->errno = SNMP::ERRNO_NOERROR;

		if (is_array($oid)) {
			trigger_error('Multi OID walks are not supported!', E_WARNING);
			return FALSE;
		}

		$options_backup = $this->apply_options();
		$result = cacti_snmp_walk($this->hostname, $this->community, $oid, $this->version, 
			$this->username, $this->auth_pass, $this->auth_proto, $this->priv_pass, 
			$this->priv_proto, $this->contextName, $this->port, $this->timeout, 
			$this->retries, $max_repetitions, SNMP_POLLER, $this->contextEngineID);

		if ($result === FALSE) {
			$this->errno = SNMP::TIMEOUT;
		}

		$this->apply_options($options_backup);
		$output = array();
		foreach($result as $item) {
			$output[$item['oid']] = $item['value'];
		}

		return $output;
	}

	function getErrno() {
		return $this->errno;
	}
	
	function getError() {
		return '';
	}
	
	function set($oid, $type, $value) {
		trigger_error('set function is not implemented', E_WARNING);
		return FALSE;
	}

	function __set($name, $value) {
		switch ($name) {
		case 'info':
			trigger_error('info property is read-only', E_WARNING);
			return FALSE;
			
		case 'valuretrieval':
			switch ($value) {
			case SNMP_VALUE_LIBRARY:
			case SNMP_VALUE_PLAIN:
			case SNMP_VALUE_OBJECT:
				$this->$name = $value;
				return TRUE;
			default:
				trigger_error("Unknown SNMP value retrieval method '$value'", E_WARNING);
				return FALSE;

			}
		break;
		case 'oid_output_format':
			switch ($value) {
			case SNMP_OID_OUTPUT_SUFFIX:
			case SNMP_OID_OUTPUT_MODULE:
			case SNMP_OID_OUTPUT_FULL:
			case SNMP_OID_OUTPUT_NUMERIC:
			case SNMP_OID_OUTPUT_UCD:
			case SNMP_OID_OUTPUT_NONE:
				$this->$name = $value;
				return TRUE;
			default:
				trigger_error("Unknown SNMP output print format '$value'", E_WARNING);
				return FALSE;
			}
		break;
		case 'max_oids':
		case 'enum_print':
		case 'quick_print':
		default:
			$this->$name = $value;
		break;
		}
	}
}
