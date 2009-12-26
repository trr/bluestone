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
		$noticetime = array(null, null),
		$debugmode,
		$useerrorhandler = false;

	function __construct($debugmode = false, $useerrorhandler = true)
	// constructor
	// to use this as a singleton, use getinstance() instead
	{
    $this->debugmode = $debugmode;
		if ($debugmode) list($this->starttime_sec, $this->starttime_usec) = explode(' ', microtime());
		
		$this->useerrorhandler($useerrorhandler);
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

	  list($sec, $usec) = $this->noticetime;
		$this->tasks[$this->nexttaskid] =
			array('starttime_sec' => $sec, 'starttime_usec' => $usec, 'noticeid' => $this->noticeid);

    return $this->nexttaskid++;
	}
	
	public function endtask($taskid)
	{
	  if (!isset($this->tasks[$taskid])) return false;
		$task = &$this->tasks[$taskid];
		
		if ($this->debugmode)
		{
		  list($sec, $usec) = explode(' ', microtime());
			$this->notices[$task['noticeid']]['taskelapsed'] = 
			 (float)($sec - $task['starttime_sec']) + (float)($usec - $task['starttime_usec']);
		}
		
		unset($this->tasks[$taskid]);
	}
	
	public function useerrorhandler($useerrorhandler = true)
	// allows you to enable/disable whether this module's error handler should
	// be used.  note that the error handler is now used by default, hence
	// calling this should be unnecessary if you want it turned _on_ 
	{
		if ($useerrorhandler == $this->useerrorhandler) return;
		else $this->useerrorhandler = $useerrorhandler;

		if ($useerrorhandler)
		{
			// in debug mode we handle all errors
			if ($this->debugmode) error_reporting(-1);
			set_error_handler(array(&$this, 'debug_errorhandler'));
			set_exception_handler(array(&$this, 'debug_errorhandler'));
		}
		else
		{
			restore_error_handler();
			restore_exception_handler();
		}
	}
	
	public static function &getinstance($debugmode = true)
  {
    static $instance;
  	if (!isset($instance)) $instance = new debug($debugmode); 
  	return $instance;
  }
	
	public function seterrorcallback($callback)
	// sets a function to be executed when a fatal error occurs.  it could
	// output an error message to the screen.  in debug mode, this is not
	// used
	{
		$this->error_callback = $callback;
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
				require(BLUESTONE_DIR . 'system/fatalerror.inc.php');
			exit('Problem finding fatal error page');
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
	
	public function debug_errorhandler($err, $errstr='', $errfile='', $errline='')
	// designed as a custom error handler - it logs it into the debug notices as
	// a notice, unless it's a fatal error.
	{
		if (is_object($err))
		{
			$errortype = get_class($err);
			$errcode = $err->getCode();
			if ($errcode) $errortype .= " (code $errcode)";
			$errstr = $err->getMessage();
			$errfile = $err->getFile();
			$errline = $err->getLine();
		}
		else
		{
			// we need to double check if this error needs reporting
			if (!($err & error_reporting())) return;

			$errortypes = array(
				E_ERROR => 'Error', E_WARNING => 'Warning', E_PARSE => 'Parse Error',
				E_NOTICE => 'Notice', E_CORE_ERROR => 'Core Error', E_CORE_WARNING => 'Core Warning',
				E_COMPILE_ERROR => 'Compile Error', E_COMPILE_WARNING => 'Compile Warning',
				E_USER_ERROR => 'User Error', E_USER_WARNING => 'User Warning',
				E_USER_NOTICE => 'User Notice', E_STRICT => 'Strict Error', 4096 => 'Recoverable Error',
				8192 => 'Deprecated Error', 16384 => 'User Deprecated Error',
				);					 
			$errortype = (isset($errortypes[$err])) ? $errortypes[$err] : 'Unknown Error';		
		}
		$this->notice('debug', 'Error', $errortype . ' in ' . $errfile . ', line ' . $errline . ': ' . $errstr);

		$this->halt();
	}
}

?>
