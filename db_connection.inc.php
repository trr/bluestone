<?php 

/*
	db_connection - a class for low level database abstraction
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

// A class for (low level) database abstraction

class db_connection
{
	//public
	
	function db_connection($dbsettings)
	// tries to make a connection to the server.
	// returns true on success, false on error
	
	// expects $dbsettings to be an array:
	//  'persistent' => true/false, false recommended
	//  'server' => server hostname
	//  'database' => database to choose
	//  'user' => username of connection
	//  'pass' => password of connection
	//  'prefix' => prefix of connection
	{
		$this->num_queries = 0;
		$this->prefix = isset($dbsettings['prefix']) ? $dbsettings['prefix'] : '';
		
		if(!empty($dbsettings['persistent']))
			$this->connection = 
				@mysql_pconnect($dbsettings['server'], $dbsettings['user'], $dbsettings['pass']);
		else
			$this->connection = 
				@mysql_connect($dbsettings['server'], $dbsettings['user'], $dbsettings['pass']);

		if (!$this->connection)
		{
			$this->set_error('Database connection failed');
			return false;
		}
		
		if (!isset($dbsettings['database'])) return true;
		$dbselect = mysql_select_db($dbsettings['database']);
		if(!$dbselect)
		{
			mysql_close($this->db_connect_id);
			$this->connection = false;
			$this->set_error('Database selection failed');
			return false;
		}
		
		return true;
	}
	
	function close()
	// closes the database connection.
	// returns false if there was no connection, true otherwise
	{
		if (!$this->connection)
		{
			$this->set_error('Could not close connection; connection not open', E_USER_NOTICE);
			return false;
		}
		if (is_resource($this->result))
		{
			@mysql_free_result($this->result);
			$this->result = NULL;
		}
		mysql_close($this->connection);
		$this->connection = false;
		return true;
	}
	
	function is_connected()
	// returns true if there is a connection, otherwise false
	{
		return $this->connection ? true : false;
	}
	
	function query($query)
	// tries a query.	returns false if unsuccessful, or result resource if successful
	{
		if (!$this->connection)
		{
			$this->set_error('Database query failed; database connection not open');
			return false;
		}
		$this->num_queries++;
		if (DEBUG)
		{
			$debug = &debug::getinstance();
			$taskid = $debug->starttask('db_connection', 'query()', $query);
		}
		$this->result = mysql_query($query, $this->connection);
		if (DEBUG)
		{
			$debug->endtask($taskid);
		}
		if ($this->result) return $this->result;
		// or failed
		$this->set_error('Database query failed; no result was returned');
		return false;
	}
	
	function query_single($query)
	// tries a query.	fetches the first row and returns it as an array.	
	// then frees the result.	therefore, should be a SELECT (or something that
	// returns results, like a SHOW)
	{
		if (!$this->connection)
		{
			$this->set_error('Database query failed; database connection not open');
			return false;
		}
		// does not remove any pre-existing queries
		$this->num_queries++;
		if (DEBUG)
		{
			$debug = &debug::getinstance();
			$taskid = $debug->starttask('db_connection', 'query_single()', $query);
		}
		$tempresult = mysql_query($query, $this->connection);
		if (DEBUG)
		{
			$debug->endtask($taskid);
		}
		if (!is_resource($tempresult))
		{
			$this->set_error('Database query failed; no result was returned');
			return false;
		}
		$array = mysql_fetch_array($tempresult);
		if ($array)
		{
			mysql_free_result($tempresult);
			return $array;
		}
		// or failed
		mysql_free_result($tempresult);
		return false;
	}
	
	function fetch_array($result = '')
	{
		if (!is_resource($result)) $result = $this->result;
		if (!is_resource($result))
		{
			return false;
		}

		$array = mysql_fetch_array($result);
		if ($array)	return $array;
		return false;
	}
	
	function affected_rows()
	// return the number of rows affected by the last query like DELETE, UPDATE...
	{
		if (!$this->connection)
		{
			$this->set_error('Database query failed; database connection not open');
			return false;
		}
		
		return mysql_affected_rows($this->connection);
	}
	
	function free_result($result = null)
	{
		if (is_resource($result))
		{
			mysql_free_result($result);
			return true;
		}
		// if result is not specified, assume we mean the currently stored result
		if (is_resource($this->result))
		{
			mysql_free_result($this->result);
			$this->result = NULL;
			return true;
		}
		$this->set_error('Could not free database result; no result specified', E_USER_NOTICE);
		return false;
	}
	
	function set_prefix($prefix)
	{
		$this->prefix = $prefix;	
	}
	
	function get_prefix()
	{
		return $this->prefix;
	}
	
	function num_rows()
	{
		if (!$this->connection)
		{
			$this->set_error('Could not return number of rows; no database connection exists');
			return false;
		}
		$numrows = mysql_num_rows($this->result);
		return $numrows;
	}
	
	function get_error()
	{
		return $this->error;
	}
	
	function get_dbtype()
	{
		return 'mysql';
	}
	
	function get_dbversion()
	{
		return (float)mysql_get_server_info($this->connection);
	}
	
	//protected
	
	function set_error($errorname, $errortype = E_USER_ERROR)
	{
		$this->error = $errorname;
		if ($mysqlerror = mysql_error()) $errorname = "MySQL Error: " . $mysqlerror;
		trigger_error($errorname, $errortype);
	}
	
	//private
	
	var $connection;
	var $query;
	var $result;
	var $num_queries;
	var $prefix;
	var $error;
}

?>