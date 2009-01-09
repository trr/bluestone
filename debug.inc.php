<?php

// debug.inc.php

// stuff to aid in debugging.

// implements the singleton pattern (using debug::getinstance())

// the value $this->debugmode determines whether we bother with things like
// the timer


class debug
{
  // public
	
	function debug($debugmode)
	// constructor
	// to use this as a singleton, use getinstance() instead
	{
    $this->debugmode = $debugmode;
		if ($debugmode) list($this->starttime_sec, $this->starttime_usec) = explode(' ', microtime());
  }
	
	function notice($module, $notice, $data = NULL)
	// module is name of module - should be the classname where the error occurred
	// or 'global' if in global scope
	// error is name of error
	// description is a name for this error
	{
	  if ($this->debugmode)
		{
		  list($sec, $usec) = explode(' ', microtime());
			$elapsed_sec = $sec - $this->starttime_sec;
			$elapsed_usec = $usec - $this->starttime_usec;
			$elapsed = (float)$elapsed_sec + (float)$elapsed_usec;
		}
		else $elapsed = NULL;
	  $this->notices[++$this->noticeid] = array('module' => $module, 'notice' => $notice, 'data' => $data, 'elapsed' => $elapsed, 'taskelapsed' => NULL);
	}
	
	function starttask($module, $taskname, $data = NULL)
	// returns a unique task id
	{
	  if ($this->debugmode)
		{
			list($sec, $usec) = explode(' ', microtime());
			$elapsed_sec = $sec - $this->starttime_sec;
			$elapsed_usec = $usec - $this->starttime_usec;
			$elapsed = (float)$elapsed_sec + (float)$elapsed_usec;
		}
		else
		{
		  $sec = NULL;
			$usec = NULL;
			$elapsed = NULL;
		}
		
	  $this->tasks[$this->nexttaskid] = array('starttime_sec' => $sec, 'starttime_usec' => $usec, 'noticeid' => ++$this->noticeid);

    $this->notices[$this->noticeid] = array('module' => $module, 'notice' => $taskname, 'data' => $data, 'elapsed' => $elapsed, 'taskelapsed' => NULL);

    return $this->nexttaskid++;
	}
	
	function endtask($taskid)
	{
	  if (!isset($this->tasks[$taskid])) return false;
		
		$task = &$this->tasks[$taskid];
		
		if ($this->debugmode)
		{
		  list($sec, $usec) = explode(' ', microtime());
			$taskelapsed_sec = $sec - $task['starttime_sec'];
			$taskelapsed_usec = $usec - $task['starttime_usec'];
			$taskelapsed = (float)$taskelapsed_sec + (float)$taskelapsed_usec;
			$elapsed_sec = $task['starttime_sec'] - $this->starttime_sec;
			$elapsed_usec = $task['starttime_usec'] - $this->starttime_usec;
			$elapsed = (float)$elapsed_sec + (float)$elapsed_usec;
		}
		else
		{
		  $taskelapsed = NULL;
		}
		
		$this->notices[$task['noticeid']]['taskelapsed'] = $taskelapsed;
		
		unset($this->tasks[$taskid]);
	}
	
	function useerrorhandler()
	{
	  // if we haven't explicitly specified which errors to halt on,
		// infer this from the
		// current error_reporting value 
	  if ($this->halton == NULL) $this->halton = error_reporting();
		
		// all errors should be handled by this module's error handler
		error_reporting(E_ALL);
		set_error_handler(array(&$this, 'debug_errorhandler'));
	}
	
	function &getinstance($debugmode = true)
  {
    static $instance;
  	if (!isset($instance)) $instance = new debug($debugmode); 
  	return $instance;
  }
	
	function sethalton($code)
	{
	  $this->halton = $code;
	}
	
	function setdebugmode($mode)
	// mode is true or false.  sets status of debug mode, which gives additional
	// diagnostic information about errors and notices.  should be set to off
	// in production environments unless an administrator is logged in
	{
	  if ($mode and !$this->debugmode) list($this->starttime_sec, $this->starttime_usec) = explode(' ', microtime());
		$this->debugmode = $mode;
	}
	
	function seterrorcallback($callback)
	// sets a file to be executed when a fatal error occurs.  the file should
	// output an error message to the screen.  in debug mode, this file is not
	// used
	{
		$this->error_callback = $callback;
	}
	
	function haltif($errno, $dooutput = true)
	{
		if ($errno & $this->halton)
		{
			while (ob_get_length() !== false) ob_end_clean();
				
			if (!$this->debugmode)
			{
				if (!empty($this->error_callback))
					call_user_func($this->error_callback, 'Application Error');
				else
				{
					if (!headers_sent()) header('HTTP/1.1 500 Internal Server Error');
					echo '<h1>Internal Server Error</h1><p>The server encountered an internal error or misconfiguration and was unable to complete your request.</p><p>We apologise for the inconvenience this problem may have caused.  This problem has been brought to the attention of the webmaster and will be fixed as soon as possible.';
				}				
				exit;
			}
			echo '<h1>Error Notice</h1><p>Your site is currently in DEBUG mode.  DEBUG mode should
				never be enabled on a site that is accessible to the public, as it might reveal secret
				information that an untrusted person could use to gain access.</p>';
			
			$backtrace = debug_backtrace();
		  	
			foreach ($backtrace as $row)
		  	{
		  		if (isset($row['class']) and $row['class'] == 'debug') continue;
					$output = '';
		  		foreach ($row as $key => $var)
		  		{
		  			if ($output) $output .= ', ';
						$output .= $key . ': ' . print_r($var, true);
		  		}
				$this->notice('debug', 'backtrace', $output);
		  	}
			
			echo $this->getnoticeshtml();
			exit;
		}
		if ($this->debugmode) echo "Debug Mode Notice: Non-fatal errors have occurred.  Please see error log.";
	}
	
	function getnoticeshtml()
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
	
	function getnoticestext()
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
	
	function debug_errorhandler($errno, $errstr, $errfile, $errline)
	// designed as a PHP custom error handler - it logs it into the debug notices as
	// a notice, unless it's a fatal error.
	{
	  // we need to double check if this error needs reporting
		if (!($errno & error_reporting())) return;
		
	  $errortypes = array( E_ERROR           => 'Error',
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
	  $this->haltif($errno);
	}
  
	// protected
	// private
	
  var $notices = array();
	var $starttime_sec;
	var $starttime_usec;
	var $tasks = array();
	var $nexttaskid = 0;
	var $noticeid = 0;
	var $error_include = NULL;
	var $debugmode;
	var $halton = NULL;
}

	

?>
