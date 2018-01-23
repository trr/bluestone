<?php 

/*
	dblite - extension to sqlite3 interface to add some functionality
	Copyright (c) 2016 Thomas Rutter
	
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

class dblite extends SQLite3 {

	private $statements = array();
	private $timeout = false;

	static function valueString($arr) {
	// creates a value string of question marks from a one- or more-dimensional array
	// one-dimensional arrays will result in string like '?,?,?,?'
	// two-dimensional arrays will result in string like '(?,?),(?,?),(?,?)'
	// and each child array in a parent must have the same number of values
	// and no arrays must be empty
		$first = reset($arr);
		$val = is_array($first) ? ('(' . self::valueString($first) . ')') : '?';
		$count = count($arr);

		if ($count > 1) $val .= str_repeat(",$val", $count - 1);
		
		return $val;
	}

	function query($str) {
		// if any additional params appear after $str, this prepares a statement
		// and uses the additional params as the bound values.
		// eg $dblite->query("SELECT * FROM a WHERE b = ?", $myvalue);
		// extra params can include arrays to bind all their members
		// named parameters are not supported, only numbered (eg question marks)

		// set busy timeout so this db is usable in web environment
		if (!$this->timeout) $this->busyTimeout(15000);

		if (func_num_args() <= 1) 
			return parent::query($str);

		$input = array_slice(func_get_args(), 1);
		$flat = array(0 => null);

		foreach ($input as $k1 => $v1) {
			if (!is_array($v1)) $flat[] = $v1;
			else foreach ($v1 as $k2 => $v2) {
				if (!is_array($v2)) $flat[] = $v2;
				else foreach ($v2 as $k3 => $v3) {
					if (!is_array($v3)) $flat[] = $v3;
					else throw new Exception('>3 levels of array not supported');
				}
			}
		}

		$hash = hash('ripemd160', $str);
		if (isset($this->statements[$hash])) {
			$statement = $this->statements[$hash];
			$statement->reset();
			//$statement->clear();
		}
		else {
			$statement = parent::prepare($str);
			$this->statements[$hash] = $statement;

			if (count($this->statements) == 11)
				array_shift($this->statements);
		}
		
		foreach ($flat as $id => $val) if ($id != 0) {

			if (is_int($val))
				$statement->bindValue($id, $val, SQLITE3_INTEGER);
			elseif (is_string($val))
			// strings that are valid UTF-8 are saved as text, otherwise blobs, there is a small chance of problem
			// if you intend something as a blob and by coincidence it happens to be valid UTF-8
				$statement->bindValue($id, $val, ($val == '' || preg_match('/^/u', $val)) ?
					SQLITE3_TEXT : SQLITE3_BLOB);
			elseif (is_null($val))
				$statement->bindValue($id, $val, SQLITE3_NULL);
			elseif (is_bool($val))
				$statement->bindValue($id, $val ? 1 : 0, SQLITE3_INTEGER);
			elseif (is_float($val))
				$statement->bindValue($id, $val, SQLITE3_FLOAT);
			else
				throw new Exception('Unknown variable type');

		}
		return $statement->execute();
	}

	function querySingle($str, $wholerow = false) {
		// as with extended query() above, but bound values begin at the third argument due to the
		// $entire_row argument
		if (func_num_arts() <= 2)
			return parent::querySingle($str, $wholerow);

		$extra = array_slice(func_get_args(), 2);
		$result = $this->query($str, $extra);

		if (!$wholerow)
			return $result->fetchArray(SQLITE3_NUM)[0];

		return $result->fetchArray(SQLITE3_ASSOC);
	}

	private static function quotenames($names) {
		// names can be a multidimensional array
		$result = array();

		foreach ($names as $k1 => $v1) {
			if (!is_array($v1)) $result[$k1] = '"' . str_replace('"', '""', $v1) . '"';
			else foreach ($v1 as $k2 => $v2) {
				if (!is_array($v2)) $result[$k1][$k2] = '"' . str_replace('"', '""', $v2) . '"';
				else foreach ($v2 as $k3 => $v3) {
					if (!is_array($v3)) $result[$k1][$k2][$k3] = '"' . str_replace('"', '""', $v3) . '"';
					else throw new Exception('>3 levels of array not supported');
				}
			}
		}
		return $result;
	}

	// The insert/update/select/selectSingle functions are shortcut functions enabling a small
	// number of basic operations without needing to build SQL.  They are intended to speed
	// up development rather than provide any new functionality.  They are the equivalent of
	// using particular SQL queries.

	function insert($table, $values) {
		// insert new row. $values must be array of ($key => $value)

		$quoted = self::quotenames(array($table, array_keys($values)));
		
		return $this->query('INSERT INTO ' . $quoted[0] . ' (' . implode(',', $quoted[1]) . ') VALUES ' .
			self::valueString($values),
			$values);
	}

	function update($table, $where, $values) {

		$quoted = self::quotenames(array($table, array_keys($where), array_keys($values)));

		$wherestr = $where ? 'WHERE ' . implode('=? AND ', $quoted[1]) . '=?' : '';
		$setstr = 'SET ' . implode('=?,', $quoted[2]) . '=?';

		return $this->query('UPDATE ' . $quoted[0] . ' SET ' . $setstr . ' ' . $wherestr, $values, $where);
	}

	function delete($table, $where) {

		$quoted = self::quotenames(array($table, array_keys($where)));

		$wherestr = $where ? 'WHERE ' . implode('=? AND ', $quoted[1]) . '=?' : '';

		return $this->query('DELETE FROM ' . $quoted[0] . ' ' . $wherestr, $where);
	}

	function select($table, $where, $columns = null) {
		// where is an array of (colname => value), supports only simple matching
		// selects single entry from table
		// all entries in where must match (they are ANDed)
		// if columns is null, returns all columns
	
		$quoted = self::quotenames(array($table, array_keys($where), $columns));

		$wherestr = $where ? 'WHERE ' . implode('=? AND ', $quoted[1]) . '=?' : '';
		$colstr = $columns === null ? '*' : implode(',', $quoted[2]);

		return $this->query('SELECT ' . $colstr . ' FROM ' . $quoted[0] . ' ' . $wherestr, $where);
	}

	function selectSingle($table, $where, $columns = null, $wholerow = false) {
		// where is an array of (colname => value), supports only simple matching
		// selects single entry from table
		// all entries in where must match (they are ANDed)
		// if columns is null, returns all columns
	
		$quoted = self::quotenames(array($table, array_keys($where), $columns));

		$wherestr = $where ? 'WHERE ' . implode('=? AND ', $quoted[1]) . '=?' : '';
		$colstr = $columns === null ? '*' : implode(',', $quoted[2]);

		return $this->querySingle('SELECT ' . $colstr . ' FROM ' . $quoted[0] . ' ' . $wherestr . ' LIMIT 1', $wholerow, $where);
	}

	function busyTimeout($msec) {
		$this->timeout = true;
		return parent::busyTimeout($msec);
	}

	function prepareTable($table, $fields, $indexes) {
		// creates table with given fields and indexes
		// if table exists, it makes sure it has these fields and indexes
		// $fields is array of $name => array($type, $default, $nullsallowed)

		$quoted = self::quotenames(array($table, array_keys($fields), array_keys($indexes)));

		static $typeaffinity = array(
			'INT' => 1, 'TIN' => 1, 'SMA' => 1, 'MED' => 1, 'BIG' => 1, 'UNS' => 1,
			'CHA' => 2, 'VAR' => 2, 'NCH' => 2, 'NAT' => 2, 'NVA' => 2, 'TEX' => 2, 'CLO' => 2,
			'BLO' => 3,
			'REA' => 4, 'DOU' => 4, 'FLO' => 4,
			'NUM' => 5, 'DEC' => 5, 'BOO' => 5, 'DAT' => 5
		);
		static $affinity = array(
			1 => 'INTEGER', 2 => 'TEXT', 3 => 'BLOB', 4 => 'REAL', 5 => 'NUMERIC'
		);

		// normalise type names to their affinity
		foreach ($fields as $name => $field) {
			$short = strtoupper(substr($field[0], 0, 3));
			$fields[$name][0] = $affinity[$typeaffinity[$short]];
		}

		$recreate = false;
		$existing = array();
		$this->query("PRAGMA table_info(" . $quoted[0] . ")");
		while ($row = $this->fetch_array()) {
			// get base affinity of type name
			$existing[] = $row['name'];
		
			if (isset($fields[$row['name']]) {
				$newfield = $fields[$row['name']];
				$short = strtoupper(substr($row['type']), 0, 3));
				$atype = $affinity[$typeaffinity[$short]];
				if ($atype != $newfield[0] || $row['dflt_value'] != $newfield[1] || $row['notnull'] != $newfield[2]) {
					$recreate = true;
				}
			}
			else {
				$fields[$row['name']] = array($row['type'], $row['dflt_value'], !$row['notnull']);
			}
		}
		foreach ($fields as $name => $field) if (!isset($existing[$name])) {
			$recreate = true;
		}

		if (!$existing || $recreate) {
		}
	}

}
