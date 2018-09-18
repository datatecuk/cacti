#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2018 The Cacti Group                                 |
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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require(__DIR__ . '/../include/cli_check.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$table = '';
$create = true;

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '--table':
				$table = trim(sql_clean($value));
				break;
			case '--update':
				$create = false;
				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit(0);
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit(0);
			default:

		}
	}
}

if ($table == '') {
	print "ERROR: You must provide a table name\n";
	display_help();
	exit(1);
} else {
	print sqltable_to_php($table, $create);
}

function sqltable_to_php($table, $create) {
	global $config, $database_default;

	include_once($config['library_path'] . '/database.php');

	$result = db_fetch_assoc('SHOW tables FROM `' . $database_default . '`');

	$tables = array();
	$text   = '';

	if (cacti_sizeof($result)) {
		foreach($result as $index => $arr) {
			foreach ($arr as $t) {
				$tables[] = $t;
			}
		}
	} else {
		print "ERROR: Obtaining list of tables from $database_default\n";
		exit;
	}

	if (in_array($table, $tables)) {
		$result = db_fetch_assoc("SHOW FULL columns FROM $table");

		$cols   = array();
		$pri    = array();
		$keys   = array();
		$text   = "\n\$data = array();\n";

		if (cacti_sizeof($result)) {
			foreach ($result as $r) {
				$text .= "\$data['columns'][] = array(";
				$text .= "'name' => '" . $r['Field'] . "'";

				if (strpos(strtolower($r['Type']), ' unsigned') !== false) {
					$r['Type'] = str_ireplace(' unsigned', '', $r['Type']);
					$text .= ", 'unsigned' => true";
				}

				$text .= ", 'type' => \"" . $r['Type'] . "\"";
				$text .= ", 'NULL' => " . (strtolower($r['Null']) == 'no' ? 'false' : 'true');

				if (trim($r['Default']) != '') {
					$text .= ", 'default' => '" . $r['Default'] . "'";
				}

				if (trim($r['Extra']) != '') {
					if (strtolower($r['Extra']) == 'on update current_timestamp') {
						$text .= ", 'on_update' => 'CURRENT_TIMESTAMP'";
					}

					if (strtolower($r['Extra']) == 'auto_increment') {
						$text .= ", 'auto_increment' => true";
					}
				}
				if (trim($r['Comment']) != '') {
					$text .= ", 'comment' => '" . $r['Comment'] . "'";
				}

				$text .= ");\n";
			}
		} else {
			print "ERROR: Obtaining list of columns from $table\n";
			exit;
		}

		$result = db_fetch_assoc("SHOW INDEX FROM $table");
		if (cacti_sizeof($result)) {
			foreach ($result as $r) {
				if ($r['Key_name'] == 'PRIMARY') {
					$pri[] = $r['Column_name'];
				} else {
					$keys[$r['Key_name']][$r['Seq_in_index']] = $r['Column_name'];
				}
			}

			if (!empty($pri)) {
				$text .= "\$data['primary'] = '" . implode("`,`", $pri) . "';\n";
			}

			if (!empty($keys)) {
				foreach ($keys as $n => $k) {
					$text .= "\$data['keys'][] = array('name' => '$n', 'columns' => '" . implode("`,`", $k) . "');\n";
				}
			}
		} else {
			//print "ERROR: Obtaining list of indexes from $table\n";
			//exit;
		}

		$result = db_fetch_row("SELECT ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_NAME = '$table'");
		if (cacti_sizeof($result)) {
			$text .= "\$data['type'] = '" . $result['ENGINE'] . "';\n";
			$text .= "\$data['comment'] = '" . $result['TABLE_COMMENT'] . "';\n";
			if ($create) {
				$text .= "api_plugin_db_table_create ('$table', \$data);\n";
			} else {
				$text .= "db_update_table ('$table', \$data, false);\n";
			}
		} else {
			print "ERROR: Unable to get tables details from Information Schema\n";
			exit;
		}
	}

	return $text;
}

function sql_clean($text) {
	$text = str_replace(array("\\", '/', "'", '"', '|'), '', $text);
	return $text;
}

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Add Device Utility, Version $version, " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	print "\nusage: sqltable_to_php.php --table=table_name [--update]\n\n";
	print "A simple developers utility to create a save schema for a newly created or\n";
	print "modified database table.  These save schema's can be placed into a plugins\n";
	print "setup.php file in order to create the tables inside of a plugin as a part of\n";
	print "it's install function.\n\n";
	print "Required:\n";
	print "--table=table_name - The table that you want exportred\n\n";
	print "Optional:\n";
	print "--update           - The utility provides create syntax.  If the update flag is\n";
	print "                     specified, the utility will provide update syntax\n\n";
}

