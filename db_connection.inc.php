<?php 

/*
	db_connection - a class for low level database abstraction
	Copyright (c) 2004, 2016 Thomas Rutter
	
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

// A class for (low level) database abstraction

// 20110623 - updated to use PDO rather than mysql backend & no longer mysql specific
// 20160912 - updated to derive from PDO rather than encapsulate a PDO object
//            now query() returns a statement object and so a preferred method of
//            fetching now is to fetch from that, but old ::fetch_array() method is
//            still there for backwards compatibility
//            query() can take any combination of extra parameters now but only numbered,
//            not named

// REMOVED:
//  num_rows() - this is not used, not useful and PDO doesn't have an equivalent
//  $result parameter of fetch(), free_result() - not very useful and difficult with PDO
//  persistent connections - not well supported with PDO < PHP 5.3, and have problems
//  special trigger_error way of reporting errors - now all done with exceptions
//  get_error() - never used
//  get_dbtype() - never used
//  get_dbversion() - never used

// DEPRECATED:
//  close() - as long as the object exists the connection should remain open - set last
//  reference to object to null to close
//  is_connected() - see above
//  begintransaction() - use PDO preferred capitalisation beginTransaction() instead
//  fetch_array() - use ::fetch method of returned statement instead
//  affected_rows() - use relevant method of returned statement instead
//  free_result() - not necessary, plus we internally cache statements now anyway
//  get_prefix() - will no longer be done at this level

class db_connection extends PDO
{
	private 
		$statements = [],
		$laststatement = null,
		$identquote = '"',
		$prefix = ''; // deprecated

	function __construct($dbsettings, $user = null, $pass = null, $extra = []) {
	// makes a connection to the server.
	
	// backwards-compatible $dbsettings could be an array:
	//  'server' => server hostname
	//  'database' => database to choose
	//  'user' => username of connection
	//  'pass' => password of connection
	//  'prefix' => table name prefix of connection - optional, defaults to ''
	//  'charset' => charset of connection (eg 'utf8') - optional, default set by mysql?

		if (is_array($dbsettings)) {

			if (isset($dbsettings['prefix']) && preg_match('/^[a-zA-Z0-9$_0x80-0xff]+$/', $dbsettings['prefix'])) 
				$this->prefix = $dbsettings['prefix'];
			if (isset($dbsettings['user'])) $user = $dbsettings['user'];
			if (isset($dbsettings['pass'])) $pass = $dbsettings['pass'];

			$opts = [];
			if (isset($dbsettings['database'])) $opts[] = "dbname=" . $dbsettings['database'];
			if (isset($dbsettings['server'])) $opts[] = "host=" . $dbsettings['server'];
			if (isset($dbsettings['charset'])) $opts[] = "charset=" . $dbsettings['charset'];

			$dbsettings = empty($dbsettings['dsn']) ? 
				("mysql:" . implode(';', $opts)) :
				($dbsettings['dsn'] . ($opts ? ";".implode(';',$opts) : ''));
		}

		if (preg_match('/^mysql:/i', $dbsettings)) $this->identquote = '`';

		$extra += array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			// always do parameterisation client-side, faster when db latency is the bottleneck
			PDO::ATTR_EMULATE_PREPARES => true,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);

		parent::__construct($dbsettings, $user, $pass, $extra);
	}

	private static function flattenArray($arr) {
		$flat = [];

		foreach ($arr as $v1) {
			if (!is_array($v1)) $flat[] = $v1;
			else foreach ($v1 as $v2) {
				if (!is_array($v2)) $flat[] = $v2;
				else $flat = array_merge($flat, self::flattenArray($v2));
			}
		}
		return $flat;
	}
	
	public function query($str /*, ... */)
	// runs a query.  Parameters can be added with '?' and supplied as additional
	// arguments after the query string, either in an array, or as multiple arguments
	// (or a combination of both)
	// returns PDO statement object which you can iterate over with foreach to
	// fetch rows
	{
		$values = self::flattenArray(array_slice(func_get_args(), 1));

		// we cache recent statement objects so we can reuse existing statements
		// matching the SQL text exactly without the application logic needing to
		// be aware
		$hash = hash('ripemd160', $str);
		if (isset($this->statements[$hash])) {
			$statement = $this->statements[$hash];
			$statement->closeCursor(); // todo is this necessary, and does it matter if it's closed already?
		}
		else {
			$statement = parent::prepare($str);
			$this->statements[$hash] = $statement;

			if (count($this->statements) == 11)
				array_shift($this->statements);
		}

		$statement->execute($values);

		$this->laststatement = $statement;

		return $statement;
	}
	
	public function querySingle($query /*, ... */) {
	// tries a query.	fetches the first row and returns it as an array.	
	// then frees the result.	Recommended for SELECT statements that will
	// return a single row
		$statement = call_user_func_array([$this, 'query'], func_get_args());

		$row = $statement->fetch();
		$statement->closeCursor();

		return $row;
	}

	public function valueList($arr) {
	// creates a value list with question marks from a one- or more-dimensional array
	// one-dimensional arrays will result in string like '(?,?,?,?)'
	// two-dimensional arrays will result in string like '(?,?),(?,?),(?,?)'
	// and each child array in a parent is assumed to have the same number of values
	// and no arrays can be empty
		$first = reset($arr);
		$repeat = count($arr) - 1;

		if (!is_array($first))
			return '(?' . str_repeat(',?', $repeat) . ')';

		$val = $this->valueList($first);
		return $val . str_repeat(',' . $val, $repeat);
	}

	private function quoteNames($names) {

		$q = $this->identquote;

		foreach ($names as $key => $val) {
			$names[$key] = !is_array($val) ? $q . str_replace($q, $q.$q, $val) . $q :
				$this->quoteNames($val);
		}
		return $names;
	}

	function insert($table, $values, $replace = false) {
		// insert new row. $values must be array of ($key => $value)

		$columns = !is_array(reset($values)) ? array_keys($values) : array_keys(reset($values));
		$quoted = $this->quoteNames([$table, $columns]);

        $verb = $replace ? 'REPLACE' : 'INSERT';
		
		return $this->query($verb . ' INTO ' . $quoted[0] . ' (' . implode(',', $quoted[1]) . ') VALUES ' .
			self::valueList($values),
			$values);
	}

	function update($table, $where, $values) {

		$quoted = $this->quoteNames([$table, array_keys($where), array_keys($values)]);

		$wherestr = $where ? 'WHERE ' . implode('=? AND ', $quoted[1]) . '=?' : '';
		$setstr = 'SET ' . implode('=?,', $quoted[2]) . '=?';

		return $this->query('UPDATE ' . $quoted[0] . ' SET ' . $setstr . ' ' . $wherestr, $values, $where);
	}

	function delete($table, $where) {

		$quoted = $this->quoteNames([$table, array_keys($where)]);

		$wherestr = $where ? 'WHERE ' . implode('=? AND ', $quoted[1]) . '=?' : '';

		return $this->query('DELETE FROM ' . $quoted[0] . ' ' . $wherestr, $where);
	}

	function select($table, $where, $columns = null) {
		// where is an array of (colname => value), supports only simple matching
		// selects single entry from table
		// all entries in where must match (they are ANDed)
		// if columns is null, returns all columns
	
		$quoted = $this->quoteNames([$table, array_keys($where), $columns, $orderby]);

		$wherestr = $where ? 'WHERE ' . implode('=? AND ', $quoted[1]) . '=?' : '';
		$colstr = $columns === null ? '*' : implode(',', $quoted[2]);

		return $this->query('SELECT ' . $colstr . ' FROM ' . $quoted[0] . ' ' . $wherestr, $where);
	}

	function selectSingle($table, $where, $columns = null) {
	
		$statement = call_user_func_array([$this, 'select'], func_get_args());

		return $statement->fetch();
	}

	/*
	private function get_dbtype() {
		return $this->getAttribute(PDO::ATTR_DRIVER_NAME);
	}
	private function get_dbversion() {
		return $this->getAttribute(PDO::ATTR_SERVER_VERSION);
	}
	 */
	
	
	// BACKWARDS COMPATIBILITY STUBS ---------------
	public function close() { }
	public function is_connected() { return true; }
	public function fetch_array() { return $this->laststatement->fetch(); }
	public function affected_rows() { return $this->laststatement->rowCount(); }
	public function free_result() { return $this->laststatement->closeCursor(); }
	public function query_single() { return call_user_func_array(array($this, 'querySingle'), func_get_args()); }
	public function get_prefix() { return $this->prefix; }
	// ---------------------------------------------
}

?>
