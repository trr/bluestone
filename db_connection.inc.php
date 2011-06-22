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

class db_connection
{
	private 
		$connection,
		$result,
		$num_queries,
		$prefix,
		$error;

	function __construct($dbsettings)
	// tries to make a connection to the server.
	// if an error occurs, is_connected() will return false and get_error() will return an error message
	
	// expects $dbsettings to be an array:
	//  'persistent' => true/false - optional, defaults to false (recommended)
	//  'server' => server hostname
	//  'database' => database to choose
	//  'user' => username of connection
	//  'pass' => password of connection
	//  'prefix' => table name prefix of connection - optional, defaults to ''
	//  'charset' => charset of connection (eg 'utf8') - optional, default set by mysql?
	{
		if (!function_exists('mysql_connect')) trigger_error('PHP does not appear to have mysql extension', E_USER_ERROR);
		
		$this->num_queries = 0;
		$this->prefix = isset($dbsettings['prefix']) ? $dbsettings['prefix'] : '';
		
		if(!empty($dbsettings['persistent']))
			$this->connection = 
				@mysql_pconnect($dbsettings['server'], $dbsettings['user'], $dbsettings['pass']);
		else
			$this->connection = 
				@mysql_connect($dbsettings['server'], $dbsettings['user'], $dbsettings['pass']);

		if (!$this->connection)
			return $this->set_error('Database connection failed', false);
		
		if (!empty($dbsettings['database']))
		{
			$dbselect = mysql_select_db($dbsettings['database'], $this->connection);
			if(!$dbselect)
			{
				mysql_close($this->connection);
				$this->connection = false;
				return $this->set_error('Database selection failed', false);
			}
		}
		
		if (!empty($dbsettings['charset']))
		{
			$result = function_exists('mysql_set_charset')
				? mysql_set_charset($dbsettings['charset'], $this->connection)
				: mysql_query('SET NAMES \'' . $this->escape($dbsettings['charset']) . '\'', $this->connection);

			if (!$result)
			{
				mysql_close($this->connection);
				$this->connection = false;
				return $this->set_error('Connection character set selection failed, maybe not supported', false);
			}
		}
	}
	
	public function close()
	// closes the database connection.
	// returns false if there was no connection, true otherwise
	{
		if (!$this->connection)
			return $this->set_error('Could not close connection; connection not open', false);

		if (is_resource($this->result)) @mysql_free_result($this->result);
		mysql_close($this->connection);
		$this->connection = false;
		$this->result = null;
		return true;
	}
	
	public function is_connected()
	// returns true if there is a connection, otherwise false
	{
		return $this->connection ? true : false;
	}
	
	public function escape($string)
	// escaping in this way, instead of addslashes(), is important if using 
	// multi-byte charsets (other than utf-8 which is safe)
	{ 
		return mysql_real_escape_string($string, $this->connection);
	}
	
	public function query($query)
	// tries a query.	returns false if unsuccessful, or result resource if successful
	{
		if (!$this->connection)
			return $this->set_error('Database query failed; database connection not open');

		$this->num_queries++;
		if (DEBUG)
		{
			$debug = &debug::getinstance();
			$taskid = $debug->starttask('db_connection', 'Database query', $this->describe_query($query));
		}
		$this->result = mysql_query($query, $this->connection);
		if (!$this->result) $this->set_error('Database query failed; no result was returned');
		if (DEBUG) $debug->endtask($taskid);
		return $this->result ? $this->result : false; 
	}
	
	public function query_single($query)
	// tries a query.	fetches the first row and returns it as an array.	
	// then frees the result.	therefore, should be a SELECT (or something that
	// returns results, like a SHOW)
	{
		if (!$this->connection)
			return $this->set_error('Database query failed; database connection not open');
		
		// does not remove any pre-existing queries
		$this->num_queries++;
		if (DEBUG)
		{
			$debug = &debug::getinstance();
			$taskid = $debug->starttask('db_connection', 'Database query', $this->describe_query($query));
		}
		$tempresult = mysql_query($query, $this->connection);
		if ($success = is_resource($tempresult))
		{
			$arr = mysql_fetch_array($tempresult);
			mysql_free_result($tempresult);
		}
		else $this->set_error('Database query failed; no result was returned');

		if (DEBUG) $debug->endtask($taskid);

		return $success ? ($arr ? $arr : false) : false;
	}
	
	public function fetch_array($result = null)
	{
		if ($result === null) $result = $this->result;
		if (!is_resource($result)) return false;

		if ($array = mysql_fetch_array($result)) return $array;
		return false;
	}
	
	public function affected_rows()
	// return the number of rows affected by the last query like DELETE, UPDATE...
	{
		if (!$this->connection)
			return $this->set_error('Database query failed; database connection not open');
		
		return mysql_affected_rows($this->connection);
	}
	
	public function free_result($result = null)
	{
		if ($result === null) $result = $this->result;
		if (is_resource($result))
		{
			mysql_free_result($result);
			return true;
		}
		return $this->set_error('Could not free database result; no result specified');
	}
	
	public function set_prefix($prefix)
	{
		$this->prefix = $prefix;	
	}
	
	public function get_prefix()
	{
		return $this->prefix;
	}
	
	public function num_rows()
	{
		if (!$this->connection)
			return $this->set_error('Could not return number of rows; no database connection exists');

		return mysql_num_rows($this->result);
	}
	
	public function get_error()
	{
		return $this->error;
	}
	
	public function get_dbtype()
	{
		return 'mysql';
	}
	
	public function get_dbversion()
	{
		return (float)mysql_get_server_info($this->connection);
	}
	
	private function describe_query($query)
	{
		$succ = preg_match('/(?!<#[^\n]*)select|insert|update|delete|replace|alter|create/i', $query, $matches);
		return $succ ? strtoupper($matches[0]) : null;
	}

	protected function set_error($errorname, $throw = true)
	{
		$this->error = $errorname;
		if ($mysqlerror = mysql_error())
		{
			$succ = preg_match('/Table \'[^\'\s\\\\]+\' doesn\'t exist|(error in your sql )?syntax|unknown column|duplicate( (index|key|entry))?/i', $mysqlerror, $matches);
			if ($succ) $errorname .= '; Error type: ' . $matches[0];
		}
		if ($throw)	trigger_error($errorname, E_USER_ERROR);
		return false;
	}
	
}

?>
