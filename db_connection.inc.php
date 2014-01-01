<?php 

/*
	db_connection - a class for low level database abstraction
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

// A class for (low level) database abstraction

// 20110623 - updated to use PDO rather than mysql backend & no longer mysql specific

// REMOVED:
//  num_rows() - this is not used, not useful and PDO doesn't have an equivalent
//  $result parameter of fetch(), free_result() - not very useful and difficult with PDO
//  persistent connections - not well supported with PDO < PHP 5.3, and have problems
//  special trigger_error way of reporting errors - now all done with exceptions

class db_connection
{
	private 
		$connection,
		$debug = null,
		$statement,
		$prefix = '',
		$error;

	function __construct($dbsettings)
	// tries to make a connection to the server.
	// if an error occurs, is_connected() will return false and get_error() will return an error message
	
	// expects $dbsettings to be an array:
	//  'server' => server hostname
	//  'database' => database to choose
	//  'user' => username of connection
	//  'pass' => password of connection
	//  'prefix' => table name prefix of connection - optional, defaults to ''
	//  'charset' => charset of connection (eg 'utf8') - optional, default set by mysql?
	{
		if (isset($dbsettings['prefix']) && $dbsettings['prefix'] != '') {
			// prefixes may now only contain the characters a-z, 0-9, A-Z, $, _ and unicode
			if (!preg_match('/^[a-zA-Z0-9$_0x80-0xff]+$/', $dbsettings['prefix'])) 
				throw new Exception('Invalid prefix');
			$this->prefix = $dbsettings['prefix'];
		}

		$opts = array();
		if (isset($dbsettings['database'])) $opts[] = "dbname=" . $dbsettings['database'];
		if (isset($dbsettings['server'])) $opts[] = "host=" . $dbsettings['server'];
		if (isset($dbsettings['charset'])) $opts[] = "charset=" . $dbsettings['charset'];

		$dsn = empty($dbsettings['dsn']) ? 
			("mysql:" . implode(';', $opts)) :
			($dbsettings['dsn'] . ($opts ? ";".implode(';',$opts) : ''));

		if (class_exists('debug')) {
			$this->debug = &debug::getinstance();
			$taskid = $this->debug->starttask('db_connection', 'Database connect');
		}

		// according to PDO, errors connecting always throw exceptions
		$this->connection = new PDO(
			$dsn,
			isset($dbsettings['user']) ? $dbsettings['user'] : null,
			isset($dbsettings['pass']) ? $dbsettings['pass'] : null);

		$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		if ($this->debug) $this->debug->endtask($taskid);
	}
	
	public function close()
	// closes the database connection.
	// returns false if there was no connection, true otherwise
	{
		if (!$this->connection) return false;
			
		if ($this->statement) {
			$this->statement->closeCursor();
			$this->statement = null;
		}
		$this->connection = null;
		return true;
	}
	
	public function is_connected()
	// returns true if there is a connection, otherwise false
	{
		return $this->connection ? true : false;
	}

	public function query($query /*, param, param ... */)
	// tries a query.	returns false if unsuccessful, or result resource if successful
	{
		if (!$this->connection) throw new Exception('No database connection');

		// debugging
		if ($this->debug)
			$taskid = $this->debug->starttask('db_connection', 'Database query', $this->describe_query($query));

		if ($this->statement)
			$this->statement->closeCursor();

		if (func_num_args() > 1) {
			$params = is_array(func_get_arg(1)) ? func_get_arg(1) :
				array_slice(func_get_args(), 1);
			foreach ($params as $id => $param) {
				if (is_bool($param)) $params[$id] = $param ? 1 : 0;
			}
			$this->statement = $this->connection->prepare($query);
			$this->statement->execute($params);
		}
		else {
			$this->statement = $this->connection->query($query);
		}

		if ($this->debug) $this->debug->endtask($taskid);

		return $this->statement ? true : false;
	}
	
	public function query_single($query /*, param, param ... */)
	// tries a query.	fetches the first row and returns it as an array.	
	// then frees the result.	therefore, should be a SELECT (or something that
	// returns results, like a SHOW)
	{
		// call query() with same arguments
		$result = call_user_func_array(array($this, "query"), func_get_args());

		if ($result) {
			$arr = $this->statement->fetch();
			$this->statement->closeCursor();
			$this->statement = null;
		}

		return $arr;
	}

	public function begintransaction() {
		if (!$this->connection) throw new Exception('No database connection');
		return $this->connection->beginTransaction();
	}
	
	public function commit() {
		if (!$this->connection) throw new Exception('No database connection');
		return $this->connection->commit();
	}

	public function rollback() {
		if (!$this->connection) throw new Exception('No database connection');
		return $this->connection->rollback();
	}

	public function fetch_array($numeric = false)
	{
		if (!$this->statement) throw new Exception('No database statement open');

		return $this->statement->fetch(!$numeric ? PDO::FETCH_ASSOC : PDO::FETCH_NUM);
	}
	
	public function affected_rows()
	// return the number of rows affected by the last query like DELETE, UPDATE...
	{
		if (!$this->statement) throw new Exception('No database statement open');

		return $this->statement->rowCount();
	}
	
	public function free_result() {
		if (!$this->statement) throw new Exception('No database statement open');

		$this->statement->closeCursor();
		$this->statement = null;
		return true;
	}
	
	public function set_prefix($prefix)
	// prefixes may now only contain the characters a-z, 0-9, A-Z, $, _ and unicode
	{
		if (!preg_match('/^[a-zA-Z0-9$_0x80-0xff]+$/', $dbsettings['prefix'])) 
			throw new Exception('Invalid prefix');
		$this->prefix = $dbsettings['prefix'];
	}
	
	public function get_prefix() {
		return $this->prefix;
	}
	
	public function get_error() {
		$info = $this->connection->errorInfo();
		return $info ? $info[2] : null;
	}
	
	public function get_dbtype() {
		return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
	}
	
	public function get_dbversion() {
		return $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
	}
	
	private function describe_query($query) {
		return trim(preg_replace('$#.*?\n|set.*|value.*|\'.*|\s+$si', ' ', substr($query, 0, 72))) . '...';
	}

}

?>
