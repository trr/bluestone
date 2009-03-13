<?php

/*
	dbprepare - for creating or repairing database tables based on instructions
	Copyright (c) 2004, 2009 Thomas Rutter
	
	This file is part of Bluestone.
	
	Bluestone is free software: you can redistribute it and/or modify
	it under the terms of the GNU Lesser General Public License as 
	published by the Free Software Foundation, either version 3 of
	the License, or (at your option) any later version.
	
	Bluestone is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Lesser General Public License for more details.
	
	You should have received a copy of the GNU Lesser General Public
	License along with Bluestone.  If not, see	
	<http://www.gnu.org/licenses/>.
*/

// to be used in installer scripts and upgrade scripts

// tolerant of tables that have had unexpected fields and indexes added; leaves
// any added fields and indexes intact

class dbprepare
{
	//public
	function dbprepare(&$db, $defaultengine='MyISAM', $defaultcollation='utf8_general_ci')
	// if you are not using UTF-8, remember to change the default charset and collation
	{
		$this->db = &$db;
		
		$this->prefix = $this->db->get_prefix();
		
		$this->errorcallback = null;
		
		$this->suppressbigchanges = true;
		
		$this->defaultengine = $defaultengine;
		$this->defaultcollation = $defaultcollation;
	}
	
	function seterrorcallback($callback)
	// allows you to use a callback function to receive any notices during the
	// operation.  This includes warnings (things that seem wrong and can't safely
	// be fixed), notices (irregularities thought harmless such as unexpected
	// fields and indexes), changes (what has changed) and suppressed changes
	// (things that would have been changed except that we've been told not to
	// alter tables over a certain size without confirmation).
	
	// the callback function must be a function of function($errormsg, $errortype)
	// where errortype is one of 'notice', 'warning', 'changed', 'suppressed'
	
	// set this to a variable which equals null to remove the callback
	// functionality
	{
		$this->errorcallback = $callback;
	}
	
	// private
	function seterror($msg, $type = 'notice')
	{
		if (isset($this->errorcallback))
			call_user_func($this->errorcallback, $msg, $type);
	}

	function suppressbigchanges($val = true)
	{
		$this->suppressbigchanges = (boolean)$val;
	}
	
	function preparemultiple($tabledefs)
	// $tabledefs is an array of
	// 		$table => array('fields' => $fields, 'indexes' => $indexes, 'ishead' => $heap), ...
	// it allows you to prepare() multiple tables at once.
	// will also check and issue notice for other tables in db with same prefix
	// that weren't given in $tabledefs
	{
		foreach ($tabledefs as $table => $val)
		{
			$fields = isset($val['fields']) ? $val['fields'] : array();
			$indexes = isset($val['indexes']) ? $val['indexes'] : array();
			$isheap = !empty($val['isheap']);
			$collation = !empty($val['collation']) ? $val['collation'] : $this->defaultcollation;
			
			$this->prepare($table, $fields, $indexes, $isheap, $collation);
		}
		
		// get tables
		$this->db->query("
			SHOW TABLE STATUS
			");
		$tables = array();
		while ($row = $this->db->fetch_array()) $tables[] = $row['Name'];
		$this->db->free_result();
		
		// check for any unexpected tables
		foreach ($tables as $table) if (strlen($table) > strlen($this->prefix)
			&& substr($table, 0, strlen($this->prefix)) == $this->prefix)
		{
			if (!isset($tabledefs[substr($table, strlen($this->prefix))]))
				$this->seterror("Unexpected table {$table} may not be needed");
		}
		
	}
	
	function populatemultiple($tabledata)
	// $tabledata is an array of
	// 		$table => array('values' => $values, 'preserve' => $preserve), ...
	// it allows you to populate() multiple tables at once.
	{
		foreach ($tabledata as $table => $val)
		{
			$values = isset($val['values']) ? $val['values'] : array();
			$preserve = isset($val['preserve']) ? $val['preserve'] : array();
			
			$this->populate($table, $values, $preserve);
		}
	}
	
	function populate($table, $records, $preserve = null)
	// Populates a table with data.  $records is an array of rows to be inserted,
	// where each row is an arrow of $key => $value
	// values can be strings or numbers
	// any records which conflict with the primary key will overwrite the existing records
	// $preserve is an optional array of which fields should not overwrite existing
	// values, ie the values in these fields will be retained from the existing row
	
	// optimisation note: inserts are done one at a time - this isn't the function
	// to use for heavy bulk imports
	{
		$changedrows = 0;
		if (is_array($records)) foreach ($records as $values)
		{
			$sets = array();
			$sets_update = array();
			foreach ($values as $key => $val)
			{
				$set = '`' . addslashes($key) . '`=';
				if (is_int($val) || is_float($val))
					$set .= (string)$val;
				elseif (is_bool($val))
					$sel .= ($val ? '1' : '0');
				else
					$set .= "'" . addslashes((string)$val) . "'";
				$sets[] = $set;
				if (!is_array($preserve) || !in_array($key, $preserve))
					$sets_update[] = $set;
			}
			if (count($sets))
			{
				$tablesl = addslashes($table);
				$setstring = implode(', ', $sets);
				$insert = count($sets_update) ? 'INSERT' : 'REPLACE';
				$onduplicate = count($sets_update) ? ('ON DUPLICATE KEY UPDATE '
					. implode(', ', $sets_update)) : '';
				$this->db->query("
					$insert INTO
						`{$this->prefix}$tablesl`
					SET
						$setstring
					$onduplicate
					");
				if ($this->db->affected_rows())
					$changedrows++;
			}
		}
		if ($changedrows > 0)
			$this->seterror("Table {$this->prefix}{$table}: $changedrows rows added or updated", 'changed');	
	}

	function prepare($table, $fields, $indexes, $heap = false, $collation = null)
	// Creates the table with given fields and indexes if it doesn't exist,
	// makes sure it has the given fields and indexes if it does exist
	// extra fields/indexes in the table but not in the parameters are left intact
	// Conflicts where a field in the table has the same name as a field passed
	// but is different in way where data might be lost or altered will be skipped
	// and result in a warning.
	
	// $fields is an array(
	//		$field => array($type, $default, $nullsallowed, $isautoincrement)
	//   )
	
	// $indexes is an array (
	//    $keyname => array($type, $commaseparatedfields)
	
	// later values in arrays are optional, ie $field => array($type) is fine.
	// if $default is not given, no default val is given to MySQL
	// if $nullsallowed is not given, NOT NULL is assumed
	// if $isautoincrement is not given, no auto_increment is used
	
	// $commaseparated fields is a string of fields in the index separated by
	// commas
	
	// when altering an existing table, alterations to tables more than say 4Mb in
	// total size will be skipped and result in a warning unless
	// acceptdelays() has been called.  This gives the user an opportunity to
	// know and accept in advance if any operation will take a long time or large
	// amount of system resources to complete
	// ALTER statements usually copy all rows in the table to a new table, even
	// parts that are unchanged
	
	// TODO: adding a unique or primary key can cause a mysql error if there
	// are duplicate values already existing for those fields
	{		
		if (empty($collation)) $collation = $this->defaultcollation;
		
		// see if table exists already
		$tablestatus = $this->gettablestatus($table);
		
		
		if ($tablestatus === null)
			return $this->create($table, $fields, $indexes, $heap, $collation);
			
		// otherwise table exists
		$currentfields = $this->getfields($table);
		$currentindexes = $this->getindexes($table);
		$currentisheap = strcasecmp($tablestatus['Engine'], 'HEAP') == 0 || strcasecmp($tablestatus['Engine'], 'MEMORY') == 0;
		$currentcollation = $tablestatus['Collation'];
		
		$alterclauses = array();
		
		
		$suppress = $this->suppressbigchanges && ($tablestatus['Data_length'] + $tablestatus['Index_length']) > 4194304; 
			// tables over 4mb will be slow for most ALTER actions
		
		// compare fields
		$lastfield = '';
		foreach ($fields as $fieldname => $val)
		{
			if (empty($val[4]) && !empty($valcmp[4]))
				$val[4] = $this->defaultcollation;
			
			if (!isset($currentfields[$fieldname]))
			{
				// need to add field
				$def = $this->getcolumndefinition($fieldname, $val);
				$where = $lastfield ? "AFTER `$lastfield`" : "FIRST";
				if ($suppress)
					$this->seterror("Need to add column $fieldname to table {$this->prefix}{$table}", 'suppressed');
				else
				{
					$this->seterror("Table {$this->prefix}{$table}: Column $fieldname added", 'changed');
					$alterclauses[] = "ADD COLUMN $def $where";
				}
			}
			else
			{
				$valnew = $valcmp = $currentfields[$fieldname];
				
				// check type
				if (strcasecmp($val[0], $valcmp[0]) != 0)
				{
					if ( preg_match('#(\w+)(\((.*)\))?#', $val[0], $match)
						&& preg_match('#(\w+)(\((.*)\))?#', $valcmp[0], $matchcmp))
					{
						static $allowedconversions = array(
							'char' => array('varchar', 'varbinary', 'binary'),
							'varchar' => array('char', 'varbinary', 'binary'),
							'binary' => array('varbinary', 'varchar', 'char'),
							'varbinary' => array('varchar', 'binary', 'char'),
							'tinyint' => array('smallint', 'mediumint', 'int', 'bigint'),
							'smallint' => array('mediumint', 'int', 'bigint'),
							'mediumint' => array('int', 'bigint'),
							'int' => array('bigint'),
							'float' => array('double'),
							'tinyblob' => array('blob', 'mediumblob', 'longblob', 'tinytext', 'text', 'mediumtext', 'longtext'),
							'blob' => array('mediumblob', 'longblob', 'text', 'mediumtext', 'longtext'),
							'mediumblob' => array('longblob', 'mediumtext', 'longtext'),
							'longblob' => array('longtext'),
							'tinytext' => array('text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob'),
							'text' => array('mediumtext', 'longtext', 'blob', 'mediumblob', 'longblob'),
							'mediumtext' => array('longtext', 'mediumblob', 'longblob'),
							'longtext' => array('longblob'),
							'date' => array('datetime'),
							'year' => array('smallint', 'mediumint', 'int', 'bigint'),
							'enum' => array('varchar', 'char'),
							);
							
						$typematch = strcasecmp($match[1], $matchcmp[1]) == 0
							|| (isset($allowedconversions[strtolower($matchcmp[1])]) && in_array(strtolower($match[1]), $allowedconversions[strtolower($matchcmp[1])]));	
								
						if (!isset($match[3])) $match[3] = null;
						if (!isset($matchcmp[3])) $matchcmp[3] = null;
								
						// check same basic type
						if ($typematch)
						{
							// same basic type
							if ($match[3] != $matchcmp[3])
							{
								if (is_numeric($match[3]) && is_numeric($matchcmp[3]))
								{
									if ($match[3] > $matchcmp[3])
									{
										// new value has longer length than existing value, can
										// modify instantly
										if ($suppress)
											$this->seterror("Need to modify type of $fieldname in table {$this->prefix}{$table} to {$val[0]}", 'suppressed');
										else
										{
											$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Type modified from {$valcmp[0]} to {$val[0]}", 'changed');
											$valnew[0] = $val[0];
										}
									}
									else
									{
										// new value less length than existing value, cannot modify
										$this->seterror("Field $fieldname in table {$this->prefix}{$table} should be $val[0], but is $valcmp[0]", 'warning');
									}
									
								}
								elseif (strcasecmp($match[1], 'enum') == 0)
								{
									$values = $this->getenumvalues($match[3]);
									$valuescmp = $this->getenumvalues($matchcmp[3]);
									if (is_array($values) && is_array($valuescmp))
									{
										$newvalues = array_unique(array_merge($values, $valuescmp));
										$newstr = $this->getenumstr($newvalues);
										if (count($newvalues) > count($valuescmp))
										{
											if ($suppress)
												$this->seterror("Need to modify type of $fieldname in table {$this->prefix}{$table} to $newtype", 'suppressed');
											else
											{
												$valnew[0] = "enum($newstr)";
												$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Type modified from {$valcmp[0]} to $valnew[0]", 'changed');
											}
										}
										if ($extravals = array_diff($newvalues, $values))
										{
											$extravals_str = $this->getenumstr($extravals);
											$s = count($extravals) == 1 ? '' : 's';
											$this->seterror("Unexpected enumerated value$s $extravals_str for $fieldname in table {$this->prefix}{$table} may not be needed");
										}
									}
									else
										$this->seterror("Field $fieldname in table {$this->prefix}{$table} has different ENUM values, should be $val[0]", 'warning');
								}
								else
								{
									// unknown type
									$this->seterror("Field $fieldname in table {$this->prefix}{$table} should be $val[0], but is $valcmp[0]", 'warning');
								}
							}
							else
							{
								if (strcasecmp($match[1], $matchcmp[1]) != 0)
								{
									if (strcasecmp($match[1], 'char') == 0 && strcasecmp($matchcmp[1], 'varchar') == 0)
									{
										// special case, don't try converting varchar back to char
										// and don't issue any warnings/notices
										// some MySQL versions will convert chars to varchar under
										// various circumstances
									}
									else
									{
										// same attrib, different type
										if ($suppress)
											$this->seterror("Need to modify type of $fieldname in table {$this->prefix}{$table} from $valcmp[0] to $val[0]", 'suppressed');
										else
										{
											$valnew[0] = $val[0];
											$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Type modified from {$valcmp[0]} to $valnew[0]", 'changed');
										}
									}
								}
							}
						}
						else
						{
							$this->seterror("Field $fieldname in table {$this->prefix}{$table} should be of type $val[0], but is of type $valcmp[0]", 'warning');
						}
					}
					else
					{
						$this->seterror("Field $fieldname in table {$this->prefix}{$table} should be of type $val[0], but it is type $valcmp[0]", 'warning');
					}
				}
					
				// check default
				if (isset($val[1]) && $val[1] !== $valcmp[1] && !(($valcmp[1] == 0 || $valcmp[1] == '') && !isset($val[1])))   // may cause problem on varchar where current default value
					// is the string '0' and replacement default is null
				{
					if ($suppress)
						$this->seterror("Need to modify default value of $fieldname in table {$this->prefix}{$table} to '{$val[1]}'", 'suppressed');
					else
					{
						$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Default value modified from '{$valcmp[1]}' to '{$val[1]}'", 'changed');
						$valnew[1] = $val[1];
					}
				}
				
				// check nulls allowed
				if (isset($val[2]) && (boolean)$val[2] != (boolean)$valcmp[2])
				{
					if (empty($val[2]))
					{
						$this->seterror("Field $fieldname in table {$this->prefix}{$table} should be NOT NULL, but it is NULL", 'warning');
					}
					else
					{
						if ($suppress)
							$this->seterror("Need to change field $fieldname in table {$this->prefix}{$table} from 'NOT NULL' to 'NULL'", 'suppressed');
						else
						{
							$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Changed from 'NOT NULL' to 'NULL'", 'changed');
							$valnew[2] = true;
						}
					}
					
				}
				
				// check auto_increment
				if (empty($val[3]) != empty($valcmp[3]))
				{
					$addremove = !empty($val[3]) ? "add" : "remove";
					$tofrom = !empty($val[3]) ? "to" : "revove";
					$added = !empty($val[3]) ? "added" : "removed";
					if ($suppress)
						$this->seterror("Need to $addremove auto_increment option $tofrom column $fieldname in table {$this->prefix}{$table}", 'suppressed');
					else
					{
						$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: auto_increment $added", 'changed');
						$valnew[3] = empty($val[3]) ? null : 'auto_increment';
						if (!empty($val[3])) $valnew[1] = null; // remove default on auto_inc even if 0
					}
				}
				
				// check collation
				if ($this->hascollation($valnew[0]))
				{
					$fieldcollation = empty($val[4]) ? $collation : $val[4];
					
					if (!empty($valcmp[4]) && strtolower($valcmp[4]) != strtolower($fieldcollation))
					{
						if ($suppress)
							$this->seterror("Need to change collation from $valcmp[4] to $val[4] in column $fieldname in table {$this->prefix}{$table}", 'suppressed');
						else
						{
							$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Collation changed from $valcmp[4] to $fieldcollation", 'changed');
							$valnew[4] = $fieldcollation;
						}					
					}
				}
				
				if ($valnew != $valcmp)
				{
					$def = $this->getcolumndefinition($fieldname, $valnew);
					$alterclauses[] = "MODIFY COLUMN $def";
				}
			}
			$lastfield = $fieldname;
		}
		
		// check unexpected fields
		foreach ($currentfields as $fieldname => $val) if (!isset($fields[$fieldname]))
		{
			$this->seterror("Unexpected field $fieldname in table {$this->prefix}{$table} may not be needed");
		}
		
		// compare indexes
		foreach ($indexes as $indexname => $val)
		{
			if (!isset($currentindexes[$indexname]))
			{
				// need to add index
				$def = $this->getindexdefinition($indexname, $val);
				if ($suppress)
					$this->seterror("Need to add $indexname on fields $val[1] to table {$this->prefix}{$table}", 'suppressed');
				else
				{
					$this->seterror("Table {$this->prefix}{$table}: Index $indexname added", 'changed');
					$alterclauses[] = "ADD $def";
				}
			}
			else
			{
				// compare current index
				$valcmp = $currentindexes[$indexname];
				if (strcasecmp($val[0], $valcmp[0]) != 0 || $val[1] != $valcmp[1])
				{
					$what = (strcasecmp($val[0], 'PRIMARY') == 0 || strcasecmp($indexname, 'PRIMARY') == 0) ? 'PRIMARY KEY' : "KEY `$indexname`";
					if ($suppress)
					{
						$this->seterror("Need to modify index $indexname in table {$this->prefix}{$table}", 'suppressed');
					}
					else
					{
						$this->seterror("Table {$this->prefix}{$table}: Index $indexname modified", 'changed'); // TODO instead of drop then add, see if there is a MODIFY
						$alterclauses[] = "DROP $what";
						$def = $this->getindexdefinition($indexname, $val);
						$alterclauses[] = "ADD $def";
					}
				}
			}
		}
		
		// check unexpected indexes
		foreach ($currentindexes as $indexname => $val) if (!isset($indexes[$indexname]))
		{
			$this->seterror("Unexpected index $indexname in table {$this->prefix}{$table} may not be needed");
		}
		
		// compare storage engine
		if ($currentisheap != $heap)
		{
			$engine = $heap ? "MEMORY" : $this->defaultengine;
			if ($suppress)
				$this->seterror("Need to modify engine type of table {$this->prefix}{$table} to $engine", 'suppressed');
			else
			{
				$this->seterror("Table {$this->prefix}{$table}: Engine changed to $engine", 'changed');
				$alterclauses[] = "ENGINE = $engine";
			}
		}
		
		// compare collation
		if ($currentcollation != $collation)
		{
			if ($suppress)
				 $this->seterror("Need to modify collation of table {$this->prefix}{$table} to $collation", 'suppressed');
			else
			{
				$this->seterror("Table {$this->prefix}{$table}: Collation changed to $collation");
				$charset = $this->getcharset($collation);
				$alterclauses[] = "DEFAULT CHARACTER SET $charset COLLATE $collation";
			}
		}
		

		if (empty($alterclauses)) return true;
		
		$alterclausestext = implode(",\n\t\t\t\t", $alterclauses);
		
		$this->db->query("
			ALTER TABLE `{$this->prefix}{$table}`
				$alterclausestext	
			");
			
		return true;
	}
	
	// protected
	function getenumvalues($str)
	// parses a string like "'value','value2','tom\'s 3rd value'" and returns
	// a simple array of strings or FALSE if a parsing error occurred or if there
	// are no values found.
	{
		$values = array();
		while (preg_match('#^\'(([^\']|(?<=\\\)\')*)\',#', $str, $matches))
		{
			$values[] = stripslashes($matches[1]);
			$str = substr($str, strlen($matches[0]));
		}
		// last value
		if (preg_match("#^\'(([^\']|(?<=\\\)\')*)\',?#", $str, $matches))
		{
			$values[] = stripslashes($matches[1]);
			$str = substr($str, strlen($matches[0]));
		}
		else return FALSE;
		if (strlen($str)) return FALSE;
		return $values;			
	}
	
	function getenumstr($values)
	// changes a list of values back into a string like
	//   "'value','value2','tom\'s 3rd value'"
	{
		$outs = array();
		foreach ($values as $value)
		{
			$outs[] = "'" . addslashes($value) . "'";
		}
		return implode(',', $outs);
	}
	
	// protected
	function getcolumndefinition($fieldname, $val)
	// gets column definition in SQL from $field value
	{
		$type = $val[0];    
		$defaultval = !isset($val[1]) ? '' : ("DEFAULT '" . addslashes($val[1]) . "'");
		$notnull = !empty($val[2]) ? '' : 'NOT NULL';
		$autoinc = empty($val[3]) ? '' : 'AUTO_INCREMENT';
		if ($this->hascollation($type))
		{
			$collation = isset($val[4]) ? addslashes($val[4]) : $this->defaultcollation;
			$charset = $this->getcharset($collation);
			$charsetstring = "CHARACTER SET $charset COLLATE $collation";
		}
		else
			$charsetstring = '';
		return "`$fieldname` $type $charsetstring $notnull $defaultval $autoinc";		
	}
	
	protected function hascollation($type)
	{
		list($typename) = explode('(', $type);
		static $colltypes = array(
			'char' => true, 'varchar' => true, 'tinytext' => true, 'text' => true,
			'mediumtext' => true, 'longtext' => true, 'enum' => true, 'set' => true,
			);
		return !empty($colltypes[$typename]);
	}
	
	// protected
	function getindexdefinition($indexname, $val)
	{
		$type = '';
		if (strcasecmp($val[0], 'UNIQUE') == 0) $type = 'UNIQUE';
		elseif (strcasecmp($val[0], 'FULLTEXT') == 0) $type = 'FULLTEXT';
		elseif (strcasecmp($val[0], 'PRIMARY') == 0 || strcasecmp($indexname, 'PRIMARY') == 0) $type = 'PRIMARY';
		
		$name = ($type == 'PRIMARY') ? '' : "`$indexname`";
		 
		$fields = "(`" . implode("`,`", explode(',', $val[1])) . "`)";
	
		return "$type KEY $name $fields";
	}
	
	// public
	function create($table, $fields, $indexes, $heap = false, $collation = null)
	// this function, or indeed any functions in this class, will not take
	// responsibility for checking that the table, column and index names are
	// valid.  None of these should be used with untrusted human input.
	// It also won't check to ensure you aren't doing something illegal like
	// setting a default value for a TEXT column or something.  This will
	// just fail
	{
		if (empty($collation)) $collation = $this->defaultcollation;
		
		$createdefinitions = array();
		
		// do fields
		foreach ($fields as $fieldname => $val)
			$createdefinitions[] = $this->getcolumndefinition($fieldname, $val);

		// do indexes
		foreach ($indexes as $indexname => $val)
			$createdefinitions[] = $this->getindexdefinition($indexname, $val);

		$createdeftext = implode(",\n\t\t\t\t", $createdefinitions);
		
		$engine = $heap ? 'MEMORY' : $this->defaultengine;
		$charset = $this->getcharset($collation);
		
		$this->db->query("
			CREATE TABLE `{$this->prefix}{$table}`
			(
				$createdeftext
			)
			ENGINE = $engine
			CHARACTER SET $charset COLLATE $collation
			");
			
		$this->seterror("Table {$this->prefix}{$table} created", 'changed');									
	}
	
	// protected
	function gettablestatus($table)
	// returns an array with information about the table including 'Engine'.
	// returns NULL instead of an array if the table is not found.
	{
		$this->db->query("
			SHOW TABLE STATUS LIKE '{$this->prefix}{$table}'
			");
		while ($row = $this->db->fetch_array())
		{
			if ($row['Name'] == $this->prefix . $table)
			{
				$this->db->free_result();
				return $row;
			}
		}
		return null;
	}
	
	function getfields($table)
	// returns the fields of a table in the same format as $fields in prepare()
	{
		$this->db->query("
			SHOW FULL COLUMNS FROM `{$this->prefix}{$table}`
			");
		$fields = array();
		while ($row = $this->db->fetch_array())
		{
			$default = $row['Default'];
			if (in_array(strtolower($row['Type']), array(
				'tinyblob', 'tinytext', 'blob', 'text', 'mediumblob', 'mediumtext',
				'longblob', 'longtext',
				)))
				$default = null;
		
			$fields[$row['Field']] = array(
				$row['Type'], 
				$default,
				strcasecmp($row['Null'], 'YES') == 0 ? 'allownulls' : false,
				strpos(strtolower($row['Extra']), 'auto_increment') === false ? false :
					'auto_increment',
				$row['Collation'],
				);

		} 
		return $fields;
	}
	
	function getindexes($table)
	// returns the indexes of a table in the same format as $indexes in prepare()
	{
		$table_s = 
		$this->db->query("
			SHOW INDEX FROM `{$this->prefix}{$table}`
			");
		$indexes = array();
		while ($row = $this->db->fetch_array())
		{
			if (!isset($indexes[$row['Key_name']]))
			{
				$type = $row['Non_unique'] ? 'KEY' : 'UNIQUE';
				if (strcasecmp($row['Key_name'], 'PRIMARY') == 0)
					$type = 'PRIMARY';
				if (strcasecmp($row['Index_type'], 'FULLTEXT') == 0)
					$type = 'FULLTEXT';

				$indexes[$row['Key_name']] = array(
					$type,
					array()
					);
			}
			$indexes[$row['Key_name']][1][$row['Seq_in_index']-1] = $row['Column_name'];
		} 
		foreach ($indexes as $key => $val)
			$indexes[$key][1] = implode(',', $val[1]);
		return $indexes;
	}
	
	function prettyprint()
	// uses the existing tables to generate nicely formatted PHP with the
	// table definition including fields, indexes and isheap
	{
		// get tables
		$this->db->query("
			SHOW TABLE STATUS
			");
		$tables = array();
		$engines = array();
		$collations = array();
		while ($row = $this->db->fetch_array())
		{
			$tablename = $row['Name'];
			if (strlen($tablename) > strlen($this->prefix)
				&& substr($tablename, 0, strlen($this->prefix)) == $this->prefix)
			{
				$table = substr($tablename, strlen($this->prefix));
				$tables[] = $table;
				$engines[$table] = $row['Engine'];
				$collations[$table] = !empty($row['Collation']) ? $row['Collation'] : null;
			}
		}
		$output = "\$tabledefs = array();\n";
		
		foreach ($tables as $table)
		{
			$fields = $this->getfields($table);
			$indexes = $this->getindexes($table);
			$isheap = (strcasecmp($engines[$table], 'HEAP') == 0 || strcasecmp($engines[$table], 'MEMORY') == 0);
			$tablecollation = $collations[$table];

			$output .= "\n/* $table */\n";

			// print fields
			$output .= "\n\$tabledefs['$table']['fields'] = array(\n";

			$namelen = 0;
			foreach ($fields as $fieldname => $val)
				$namelen = max($namelen, strlen($fieldname));
			
			foreach ($fields as $fieldname => $val)
			{
				$collationoverride = !empty($val[4]) && $this->hascollation($val[0]) && $val[4] != $tablecollation;
				
				$output .= str_pad("\t" . var_export($fieldname, true), $namelen + 4);
				$output .= "=> array(" . var_export($val[0], true);
				if (isset($val[1]) || $val[2] != false || $val[3] != false || $collationoverride)
					$output .= ', ' . var_export($val[1], true);
				if ($val[2] != false || $val[3] != false || $collationoverride)
					$output .= ', ' . ($val[2] ? 'NULL' : 'false');
				if ($val[3] != false || $collationoverride)
					$output .= ', ' . ($val[3] ? "'AUTO_INCREMENT'" : 'false');
				if ($collationoverride)
					$output .= ', ' . var_export($val[4], true);
				$output .= "),\n";
			}
			$output .= "\t);\n";

			// print indexes
			$output .= "\$tabledefs['$table']['indexes'] = array(\n";
			
			$namelen = 0;
			foreach ($indexes as $indexname => $val)
				$namelen = max($namelen, strlen($indexname));
			
			foreach ($indexes as $indexname => $val)
			{
				$output .= str_pad("\t" . var_export($indexname, true), $namelen + 4);
				$output .= "=> array(" . var_export($val[0], true);
				$output .= ', ' . var_export($val[1], true);
				$output .= "),\n";
			}
			$output .= "\t);\n";

			// print isheap
			if ($isheap)
				$output .= "\$tabledefs['$table']['isheap'] = true;\n";
				
			if (!empty($tablecollation) && $tablecollation != $this->defaultcollation)
				$output .= "\$tabledefs['$table']['collation'] = " . var_export($tablecollation, true) . ";\n";
		}
		return $output;
	}
	
	private function getcharset($collation)
	// gets charset from collation
	{
		list($charset) = explode('_', $collation);
		return $charset;
	}
		
	var $db;
	var $prefix;
	
	var $suppressbigchanges;
	var $defaultengine;
}

?>