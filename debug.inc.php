<?php

/*
	debug - simple debugging aids
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

// implements the singleton pattern (using debug::getinstance())

// the value $this->debugmode determines whether we bother with things like
// the timer


class debug
{
	private
		$notices = array(),
		$starttime_sec,
		$starttime_usec,
		$tasks = array(),
		$nexttaskid = 0,
		$noticeid = 0,
		$noticetime,
		$error_callback,
		$debugmode,
		$halton = null,
		$errorcount = 0;

	function __construct($debugmode)
	// constructor
	// to use this as a singleton, use getinstance() instead
	{
    $this->debugmode = $debugmode;
		if ($debugmode) list($this->starttime_sec, $this->starttime_usec) = explode(' ', microtime());
  }

	function __destruct()
	{
		if ($this->debugmode && $this->errorcount)
			$this->halt();
	}

	public function notice($module, $notice, $data = null)
	// module is name of module - should be the classname where the error occurred
	// or 'global' if in global scope
	// notice is name of notice
	// description is a name for this notice
	{
		if ($this->debugmode)
		{
			list($sec, $usec) = explode(' ', microtime());
			$elapsed = (float)($sec - $this->starttime_sec) + (float)($usec - $this->starttime_usec);
			$this->noticetime = array($sec, $usec);
		}
		else
			$elapsed = null;

		$this->notices[++$this->noticeid] = array(
			'module' => $module,
			'notice' => $notice,
			'data' => (strlen($data) > (1024*512)) ? (substr($data, 0, (1024*512) - 15) . '... (truncated)') : $data,
			'elapsed' => $elapsed,
			'taskelapsed' => null
		);

		// control size of debug log (don't allow it to grow indefinitely, as this is a memory leak
		if ($this->noticeid > 50)
		{
			if (strlen($this->notices[$this->noticeid - 50]['data']) > 80)
				$this->notices[$this->noticeid - 50]['data'] = substr($this->notices[$this->noticeid - 50]['data'], 0, 65) . '... (truncated)';
			if ($this->noticeid > 251)
				unset($this->notices[$this->noticeid - 250]);
			elseif ($this->noticeid == 251)
				$this->notices[1] = array('module' => 'debug', 'notice' => 'debug log truncated', 'data' => null, 'elapsed' => $this->notices[1]['elapsed'], 'taskelapsed' => null);
		}
	}
	
	public function starttask($module, $taskname, $data = NULL)
	// returns a unique task id
	{
		$this->notice($module, $taskname, $data);

	  if ($this->debugmode)
			list($sec, $usec) = $this->noticetime;
		else
			$sec = $usec = null;
		
	  $this->tasks[$this->nexttaskid] = array('starttime_sec' => $sec, 'starttime_usec' => $usec, 'noticeid' => $this->noticeid);

    return $this->nexttaskid++;
	}
	
	public function endtask($taskid)
	{
	  if (!isset($this->tasks[$taskid])) return false;
		
		$task = &$this->tasks[$taskid];
		
		if ($this->debugmode)
		{
		  list($sec, $usec) = explode(' ', microtime());
			$taskelapsed = (float)($sec - $task['starttime_sec']) + (float)($usec - $task['starttime_usec']);
		}
		else
			$taskelapsed = null;
		
		$this->notices[$task['noticeid']]['taskelapsed'] = $taskelapsed;
		
		unset($this->tasks[$taskid]);
	}
	
	public function useerrorhandler()
	{
	  // if we haven't explicitly specified which errors to halt on,
		// infer this from the
		// current error_reporting value 
	  if ($this->halton === NULL) $this->halton = error_reporting();
		
		// all errors should be handled by this module's error handler
		error_reporting(E_ALL);
		set_error_handler(array(&$this, 'debug_errorhandler'));
	}
	
	public static function &getinstance($debugmode = true)
  {
    static $instance;
  	if (!isset($instance)) $instance = new debug($debugmode); 
  	return $instance;
  }
	
	public function sethalton($code)
	{
	  $this->halton = $code;
	}
	
	public function setdebugmode($mode)
	// mode is true or false.  sets status of debug mode, which gives additional
	// diagnostic information about errors and notices.  should be set to off
	// in production environments unless an administrator is logged in
	{
	  if ($mode and !$this->debugmode) list($this->starttime_sec, $this->starttime_usec) = explode(' ', microtime());
		$this->debugmode = $mode;
	}
	
	public function seterrorcallback($callback)
	// sets a file to be executed when a fatal error occurs.  the file should
	// output an error message to the screen.  in debug mode, this file is not
	// used
	{
		$this->error_callback = $callback;
	}
	
	private function haltif($errno)
	{
		if ($errno & $this->halton || $force)
			$this->halt();
	}

	private function halt()
	{
		if (!headers_sent() && empty($this->error_callback)) header('HTTP/1.1 500 Internal Server Error');
		while (ob_get_length() !== false) ob_end_clean();
			
		if (!$this->debugmode)
		{
			if (!empty($this->error_callback))
				call_user_func($this->error_callback, 'Application Error');
			else
			{
				echo '<h1>Internal Server Error</h1><p>The server encountered an internal error or misconfiguration and was unable to complete your request.</p><p>We apologise for the inconvenience this problem may have caused.  This problem has been brought to the attention of the webmaster and will be fixed as soon as possible.';
			}				
			exit;
		}

		echo '<h1>Error Notice</h1><p>Your site is currently in DEBUG mode.  DEBUG mode should
			never be enabled on a site that is accessible to the public, as it might reveal technical
			information that an untrusted person could use to gain access.</p>';
		
		$backtrace = debug_backtrace();
			
		$totallen = 0; // prevent backtrace being too big
		foreach ($backtrace as $row)
		{
			if (isset($row['class']) && $row['class'] == 'debug') continue;
			if ($totallen >= 16384)
			{
				$this->notice('debug', 'backtrace truncated', 'backtrace data too long; truncated');
				break;
			}
			$output = '';
				foreach ($row as $key => $var)
			{
					if ($output) $output .= ', ';
				$output .= $key . ': ' . print_r($var, true);
			}
			if (strlen($output) > 8192)
				$output = substr($output, 0, 8192) . '... (truncated)';
			$this->notice('debug', 'backtrace', $output);
			$totallen += strlen($output);
		}
		
		echo $this->getnoticeshtml();
		exit;
	}
	
	public function getnoticeshtml()
	{
		if (!count($this->notices)) return '';
		$output = '<table border="1" cellspacing="0" cellpadding="4" class="debugtable"><tr><th>Time (ms)</th><th>Task (ms)</th><th>Module</th><th>Notice Type</th><th>Data</th></tr>';
		
		foreach ($this->notices as $notice)
		{
			$taskelapsed = $notice['taskelapsed'] ? number_format($notice['taskelapsed'] * 1000, 5) : '&nbsp;';
			$data = $notice['data'] ? htmlentities($notice['data']) : '&nbsp;';
			$output .= '<tr><td>' . number_format($notice['elapsed'] * 1000, 5) . '</td><td>' . $taskelapsed . '</td><td>' . htmlentities($notice['module']) . '</td><td>' . htmlentities($notice['notice']) . '</td><td>' . $data . '</td></tr>';
		}		
		
		$output .= '</table>';
		
	  return $output;
	}
	
	public function getnoticestext()
	{
	  if (empty($this->notices)) return '';
	  $output =  'Time (ms)   : Task (ms)   : Module           : Notice Type                      : Data' . "\r\n";
    $output .= '............:.............:..................:..................................:.....' . "\r\n";
    
    foreach ($this->notices as $notice)
		{
			$taskelapsed = $notice['taskelapsed'] ? str_pad(number_format($notice['taskelapsed'] * 1000, 5), 11, ' ', STR_PAD_LEFT) : '           ';
			$output .= str_pad(number_format($notice['elapsed'] * 1000, 5), 11, ' ', STR_PAD_LEFT) . ' : ' . $taskelapsed . ' : ' . str_pad(substr($notice['module'], 0, 16), 16) . ' : ' . str_pad($notice['notice'], 32) . ' : ' . trim(strtr($notice['data'], "\r\n\t", '   ')) . "\r\n";
		}		
		
	  return $output;
	}
	
	public function debug_errorhandler($errno, $errstr, $errfile, $errline)
	// designed as a PHP custom error handler - it logs it into the debug notices as
	// a notice, unless it's a fatal error.
	{
		// we need to double check if this error needs reporting
		if (!($errno & error_reporting())) return;
		
		$errortypes = array(
			E_ERROR           => 'Error',
			E_WARNING         => 'Warning',
			E_PARSE           => 'Parse Error',
			E_NOTICE          => 'Notice',
			E_CORE_ERROR      => 'Core Error',
			E_CORE_WARNING    => 'Core Warning',
			E_COMPILE_ERROR   => 'Compile Error',
			E_COMPILE_WARNING => 'Compile Warning',
			E_USER_ERROR      => 'User Error',
			E_USER_WARNING    => 'User Warning',
			E_USER_NOTICE     => 'User Notice'
			);					 
											 
		$errortype = (isset($errortypes[$errno])) ? $errortypes[$errno] : 'Unknown Error';		
		
		$this->notice('debug', 'PHP error handling', $errortype . ' in ' . $errfile . ', line ' . $errline . ': ' . $errstr);
		$this->errorcount++;
		$this->haltif($errno);
	}
  
}

	

?>
