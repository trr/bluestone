<?php

/*
	dbprepare - for creating or repairing database tables based on instructions
	Copyright (c) 2004, 2011 Thomas Rutter
	
	This file is part of Bluestone.
	
	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:
		* Redistributions of source code must retain the above copyright
			notice, this list of conditions and the following disclaimer.
		* Redistributions in binary form must reproduce the above copyright
			notice, this list of conditions and the following disclaimer in the
			documentation and/or other materials provided with the distribution.
		* Neither the name of the author nor the names of contributors may be used
			to endorse or promote products derived from this software without
			specific prior written permission.

	THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
	ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
	WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
	DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
	FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
	DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
	SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
	CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
	OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
	OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

// to be used in installer scripts and upgrade scripts

// tolerant of tables that have had unexpected fields and indexes added; leaves
// any added fields and indexes intact

class dbprepare
{
	private
		$db,
		$prefix,
		$errorcallback = null,
		$suppressbigchanges = true,
		$defaultengine,
		$defaultcollation;

	function __construct(&$db, $defaultengine='MyISAM', $defaultcollation='utf8mb4_unicode_ci')
	// if you are not using UTF-8, remember to change the default charset and collation
	{
		$this->db = &$db;
		
		$this->prefix = $this->db->get_prefix();
		
		$this->defaultengine = preg_replace('/[^\w-]+/', '', $defaultengine);
		$this->defaultcollation = preg_replace('/[^\w-]+/', '', $defaultcollation);
	}
	
	public function seterrorcallback($callback)
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
	
	private function seterror($msg, $type = 'notice')
	{
		if ($this->errorcallback)
			call_user_func($this->errorcallback, $msg, $type);
	}

	public function suppressbigchanges($val = true)
	{
		$this->suppressbigchanges = (boolean)$val;
	}
	
	public function preparemultiple($tabledefs)
	// $tabledefs is an array of
	// 		$table => array('fields' => $fields, 'indexes' => $indexes, 'isheap' => $heap), ...
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
			$preserveindexes = !empty($val['preserveindexes']);
			
			$this->prepare($table, $fields, $indexes, $isheap, $collation, $preserveindexes);
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
	
	public function populatemultiple($tabledata)
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
	
	public function populate($table, $records, $preserve = null)
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
			$sets = array();  // the sets and sets_update contain "`mykey`=?"
			$sets_update = array();
			$params = array(); // parameterised query - these are the params
			foreach ($values as $key => $val) {
				$sets[] = '`' . addslashes($key) . '`=?';
				$params[] = $val;
			}
			foreach ($values as $key => $val) {
				if (!is_array($preserve) || !in_array($key, $preserve)) {
					$sets_update[] = '`' . addslashes($key) . '`=?';
					$params[] = $val;
				}
			}
			if (count($sets))
			{
				$tablesl = addslashes($table);
				$setstring = implode(',', $sets);
				$insert = count($sets_update) ? 'INSERT' : 'REPLACE';
				$onduplicate = count($sets_update) ? ('ON DUPLICATE KEY UPDATE '
					. implode(',', $sets_update)) : '';
				$this->db->query("
					$insert INTO
						`{$this->prefix}$tablesl`
					SET
						$setstring
					$onduplicate
					", $params);
				if ($this->db->affected_rows())
					$changedrows++;
			}
		}
		if ($changedrows > 0)
			$this->seterror("Table {$this->prefix}{$table}: $changedrows rows added or updated", 'changed');	
	}

	public function prepare($table, $fields, $indexes, $heap = false, $collation = null, $preserveindexes = false)
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
	
	// $heap signals that the table type should be HEAP or MEMORY.  There are
	// no other controls for table type; if it is not HEAP/MEMORY (they are synonyms)
	// then it will be left as it is, or will default to what was set when this dbprepare
	// object was created
	
	// $collation specifies the collation (which also specifies character set) of the table
	// this can be overridden on specific fields.  This collation and character set will
	// apply to the table default, and to any fields not overridden.
	
	// By default, pre-existing UNIQUE, PRIMARY or FULLTEXT indexes on the table that
	// aren't specified may be culled or converted to plain KEY indexes, so that they
	// cannot interfere with the table (by causing duplicate key errors for example).
	// Specifying $preserveindexes as true will preserve these.
	
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
		if (strtoupper($tablestatus['Engine']) == 'HEAP') $tablestatus['Engine'] = 'MEMORY';
		$currentisheap = strcasecmp($tablestatus['Engine'], 'MEMORY') == 0;
		$currentcollation = $tablestatus['Collation'];
		
		$alterclauses = array();

		$suppress = $this->suppressbigchanges && ($tablestatus['Data_length'] + $tablestatus['Index_length']) > 12582912; 
		// tables over 12mb will be slow for most ALTER actions
		
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
		elseif (!$heap && strtoupper($tablestatus['Engine']) != strtoupper($this->defaultengine))
		{
			if ($suppress)
				$this->seterror("Need to modify storage engine of table {$this->prefix}{$table} to $this->defaultengine", 'suppressed');
			else
			{
				$this->seterror("Table {$this->prefix}{$table}: Storage engine changed to $this->defaultengine", 'changed');
				$alterclauses[] = "ENGINE = $this->defaultengine";
			}
		}
		
		// compare collation
		if ($currentcollation != $collation)
		{
			if ($suppress)
				 $this->seterror("Need to modify collation of table {$this->prefix}{$table} to $collation", 'suppressed');
			else
			{
				$this->seterror("Table {$this->prefix}{$table}: Collation changed to $collation", 'changed');
				$charset = $this->getcharset($collation);
				$alterclauses[] = "DEFAULT CHARACTER SET $charset COLLATE $collation";
			}
		}
		
		// check unexpected fields
		foreach ($currentfields as $fieldname => $val) if (!isset($fields[$fieldname]))
		{
			$this->seterror("Unexpected field $fieldname in table {$this->prefix}{$table} may not be needed");
		}
		
		// compare fields
		$lastfield = '';
		foreach ($fields as $fieldname => $val)
		{
			// apply defaults
			if (!isset($val[1])) $val[1] = null;
			if (!isset($val[2])) $val[2] = false;
			if (!isset($val[3])) $val[3] = false;
			if (empty($val[4])) $val[4] = $this->defaultcollation;
			
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
				if ($val[1] !== $valcmp[1])   // may cause problem on varchar where current default value
					// is the string '0' and replacement default is null
				{
					$olddesc = $valcmp[1] === null ? '(No default)' : '\'' . $valcmp[1] . '\'';
					$newdesc = $val[1]    === null ? '(No default)' : '\'' . $val[1]    . '\'';
					if ($suppress)
						$this->seterror("Need to modify default value of $fieldname in table {$this->prefix}{$table} to $newdesc", 'suppressed');
					else
					{
						$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Default value modified from $olddesc to $newdesc", 'changed');
						$valnew[1] = $val[1];
					}
				}
				
				// check nulls allowed
				if ((boolean)$val[2] != (boolean)$valcmp[2])
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
				if ((boolean)$val[3] != (boolean)$valcmp[3])
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
							$this->seterror("Need to change collation from $valcmp[4] to $fieldcollation in column $fieldname in table {$this->prefix}{$table}", 'suppressed');
						else
						{
							$this->seterror("Table {$this->prefix}{$table}: Column $fieldname: Collation changed from $valcmp[4] to $fieldcollation", 'changed');
							$valnew[4] = $fieldcollation;
						}					
					}
				}
				
				if ($valnew !== $valcmp)
				{
					$def = $this->getcolumndefinition($fieldname, $valnew);
					$alterclauses[] = "MODIFY COLUMN $def";
				}
			}
			$lastfield = $fieldname;
		}
		
		// check unexpected indexes
		foreach ($currentindexes as $indexname => $val)
		{
			if (!isset($indexes[$indexname]))
			{
				if ($preserveindexes)
					$this->seterror("Unexpected index $indexname in table {$this->prefix}{$table} may not be needed");
				else
				{
					// see if it's a duplicate index, if so delete it
					$dropped = false;
					$compareindexes = array_merge($currentindexes, $indexes);
					unset($compareindexes[$indexname]);
					foreach ($compareindexes as $name => $inval)
						if ($this->isfulltext($val[0]) == $this->isfulltext($inval[0])
							&& strtolower(trim(str_replace(' ', '', $inval[1]))) == strtolower(trim(str_replace(' ', '', $val[1]))))
					{
						if ($suppress)
							$this->seterror("Need to drop duplicate index $indexname on fields $val[1] from table {$this->prefix}{$table}", 'suppressed');
						else
						{
							unset($currentindexes[$indexname]);
							$this->seterror("Table {$this->prefix}{$table}: Duplicate index $indexname removed", 'changed');
							$alterclauses[] = "DROP INDEX `$indexname`";
						}
						$dropped = true;
						break;
					}
					// see if it is unique or primary, if so delete it or convert it to key
					if (!$dropped && in_array(trim(strtoupper($val[0])), array('UNIQUE', 'PRIMARY')))
					{						
						if ($suppress)
							$this->seterror("Need to change unexpected index $indexname from type {$val[0]} to type KEY in table {$this->prefix}{$table}", 'suppressed');
						else
						{
							$newname = $indexname;
							$valnew = $val;
							$valnew[0] = 'KEY';
							$currentindexes[$indexname] = $valnew;
							if (strtoupper($indexname) == 'PRIMARY')
							{
								for ($i = 1; in_array("old_primary_$i", array_keys($currentindexes)) || in_array("index_$i", array_keys($indexes)); $i++);
								$newname = "old_primary_$i";
								$currentindexes[$newname] = $currentindexes[$indexname];
								unset($currentindexes[$indexname]);
							}
							$this->seterror("Table {$this->prefix}{$table}: Unexpected index $indexname changed to type KEY", 'changed');
							$what = strtoupper($indexname) == 'PRIMARY' ? 'PRIMARY KEY' : "KEY `$indexname`";
							$alterclauses[] = "DROP $what";
							$def = $this->getindexdefinition($newname, $valnew);
							$alterclauses[] = "ADD $def";
							$indexname = $newname;
						}
						$this->seterror("Unexpected index $indexname in table {$this->prefix}{$table} may not be needed");
					}
					// or if it is fulltext, drop it
					elseif (!$dropped && trim(strtoupper($val[0])) == 'FULLTEXT')
					{
						if ($suppress)
							$this->seterror("Need to drop unexpected FULLTEXT index $indexname in table {$this->prefix}{$table}", 'suppressed');
						else
						{
							unset($currentindexes[$indexname]);
							$this->seterror("Table {$this->prefix}{$table}: Unexpected FULLTEXT index $indexname dropped", 'changed');
							$alterclauses[] = "DROP KEY `$indexname`";
						}
					}
				}
			}
		}
		
		// compare indexes
		foreach ($indexes as $indexname => $val)
		{
			if (!isset($currentindexes[$indexname]))
			{
				// need to add index
				$def = $this->getindexdefinition($indexname, $val);
				if ($suppress)
					$this->seterror("Need to add index $indexname on fields $val[1] to table {$this->prefix}{$table}", 'suppressed');
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

		if (empty($alterclauses)) return true;
		
		$alterclausestext = implode(",\n\t\t\t\t", $alterclauses);
		
		$this->db->query("
			ALTER TABLE `{$this->prefix}{$table}`
				$alterclausestext	
			");
			
		return true;
	}
	
	private function isfulltext($indextype)
	{
		return trim(strtoupper($indextype)) == 'FULLTEXT';
	}
	
	private function getenumvalues($str)
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
	
	private function getenumstr($values)
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
	
	private function getcolumndefinition($fieldname, $val)
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
	
	private function hascollation($type)
	{
		list($typename) = explode('(', $type);
		static $colltypes = array(
			'char' => true, 'varchar' => true, 'tinytext' => true, 'text' => true,
			'mediumtext' => true, 'longtext' => true, 'enum' => true, 'set' => true,
			);
		return !empty($colltypes[$typename]);
	}
	
	private function getindexdefinition($indexname, $val)
	{
		$type = '';
		if (strcasecmp($val[0], 'UNIQUE') == 0) $type = 'UNIQUE';
		elseif (strcasecmp($val[0], 'FULLTEXT') == 0) $type = 'FULLTEXT';
		elseif (strcasecmp($val[0], 'PRIMARY') == 0 || strcasecmp($indexname, 'PRIMARY') == 0) $type = 'PRIMARY';
		
		$name = ($type == 'PRIMARY') ? '' : "`$indexname`";
		 
		$fields = "(`" . implode("`,`", explode(',', $val[1])) . "`)";
	
		return "$type KEY $name $fields";
	}
	
	public function create($table, $fields, $indexes, $heap = false, $collation = null)
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
	
	private function gettablestatus($table)
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
	
	private function getfields($table)
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
				// type
				$row['Type'], 
				// default value
				$default,
				// allow nulls
				strcasecmp($row['Null'], 'YES') == 0 ? true : false,
				// auto increment
				strpos(strtolower($row['Extra']), 'auto_increment') === false ? false :
					true,
				$row['Collation'],
				);

		} 
		return $fields;
	}
	
	private function getindexes($table)
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
	
	public function prettyprint()
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
					$output .= ', ' . ($val[2] ? 'true' : 'false');
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
		
}

?>
