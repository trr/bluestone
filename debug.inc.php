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

	public function notice($module, $notice, $data = null, $errlog = false)
	// module is name of module - should be the classname where the error occurred
	// or 'global' if in global scope
	// notice is name of notice
	// data is further information about this notice such as the arguments
	// errlog should be set to true to show this in an error report
	{
		if ($this->debugmode)
		{
			list($sec, $usec) = explode(' ', microtime());
			$elapsed = (float)($sec - $this->starttime_sec) + (float)($usec - $this->starttime_usec);
			$this->noticetime = array($sec, $usec);
		}
		else $elapsed = null;

		$this->notices[++$this->noticeid] = array(
			'module' => $module,
			'notice' => $notice,
			'data' => (strlen($data) > (1024*512)) ? (substr($data, 0, (1024*512) - 15) . '... (truncated)') : $data,
			'elapsed' => $elapsed,
			'taskelapsed' => null,
			'errlog' => $errlog,
		);

		// control size of debug log (don't allow it to grow indefinitely, as this is a memory leak
		if ($this->noticeid > 80)
		{
			if (strlen($this->notices[$this->noticeid - 50]['data']) > 80)
				$this->notices[$this->noticeid - 80]['data'] = substr($this->notices[$this->noticeid - 80]['data'], 0, 65) . '... (truncated)';
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
	
	public static function &getinstance($debugmode = false, $useerrorhandler = true)
  {
    static $instance;
  	if (!isset($instance)) $instance = new debug($debugmode, $useerrorhandler); 
  	return $instance;
  }
	
	public function seterrorcallback($callback)
	// sets a function to be executed when a fatal error occurs.  it could
	// output an error message to the screen.  in debug mode, this is not
	// used
	{
		$this->error_callback = $callback;
	}
	
	public function halt($message = '', $errorlevel = 1, $htmlformat = null)
	// errorlevel 0 = success, >0 = error
	// htmlformat true = use html, false = use text, null = autodetect
	{
		if ($htmlformat === null)
			$htmlformat = !empty($_SERVER['REQUEST_URI']);
		if (!headers_sent() && empty($this->error_callback)) header('HTTP/1.1 500 Internal Server Error');
		while (ob_get_length() !== false) ob_end_clean();
			
		if (!$this->debugmode)
		{
			if (!empty($this->error_callback))
				call_user_func($this->error_callback, 'Application Error');
			else
				require(BLUESTONE_DIR . 'system/fatalerror.inc.php');
			exit;
		}

		if ($htmlformat) echo '<div style="background:$fff;color:#000;font:small sans-serif!important">';

		if ($message != '')
		{
			$c = $errorlevel ? '#c00' : '#080';
			echo $htmlformat 
				? "<p style=\"background:$c;color:#fff;font-weight:bold;padding:6px\">"
				: str_repeat("=============",6) . "\n";
			echo $htmlformat 
				? htmlspecialchars($message) 
				: wordwrap($message, 78, "\n", true) . "\n";
			echo $htmlformat
				? "</p>"
				: str_repeat("=============",6) . "\n";
		}

		echo $htmlformat ? $this->getnoticeshtml(true) : $this->getnoticestext(true);
		if ($htmlformat)
			echo '<p><em>Security notice: Do not enable DEBUG mode on a site visible to the public.</em></p>';
		exit((int)$errorlevel);
	}
	
	public function getnoticeshtml($onlyerrors = false)
	{
		if (!count($this->notices)) return '';
		$output = '<table border="1" cellspacing="0" cellpadding="4" class="debugtable"><tr><th>Time (ms)</th><th>Task (ms)</th><th>Module</th><th>Notice Type</th><th>Data</th></tr>';
		
		foreach ($this->notices as $notice)
		{
			if ($onlyerrors && !$notice['errlog']) continue;
			$taskelapsed = $notice['taskelapsed'] ? number_format($notice['taskelapsed'] * 1000, 2) : '&nbsp;';
			$data = $notice['data'] ? htmlentities($notice['data']) : '&nbsp;';
			$output .= '<tr><td>' . number_format($notice['elapsed'] * 1000, 2) . '</td><td>' . $taskelapsed . '</td><td>' . htmlentities($notice['module']) . '</td><td>' . htmlentities($notice['notice']) . '</td><td>' . $data . '</td></tr>';
		}		
		
		$output .= '</table>';
		
	  return $output;
	}
	
	public function getnoticestext($onlyerrors = false)
	{
	  if (empty($this->notices)) return '';
		$output = '';
    foreach ($this->notices as $notice)
		{
			if ($onlyerrors && !$notice['errlog']) continue;
			$taskelapsed = $notice['taskelapsed'] ? str_pad(number_format($notice['taskelapsed'] * 1000, 2), 7, ' ', STR_PAD_LEFT) : '       ';
			$output .= str_pad(number_format($notice['elapsed'] * 1000, 2), 8, ' ', STR_PAD_LEFT) . ' ' . $taskelapsed . ' ';
			$msg = '[' . $notice['module'] . '] ' . $notice['notice'];
			if ($notice['data'] != '') $msg .= ': ' . $notice['data'];
			$output .= wordwrap(str_replace("\n", "\n                 ", $msg), 61, "\n                 ", true) . "\n";
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
			$backtrace = $err->getTrace();
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
			$backtrace = debug_backtrace();
			foreach ($backtrace as $id => $row)
				if (isset($row['class']) && $row['class'] == 'debug')
					unset($backtrace[$id]);
		}
		$this->notice('debug', 'Error', "$errortype in $errfile line $errline: $errstr", true);
		$this->logtrace($backtrace);
		foreach ($this->tasks as $taskid => $task)
			$this->notices[$task['noticeid']]['errlog'] = true;
		$this->halt("$errortype in $errfile line $errline: $errstr");
	}

	private function logtrace($trace)
	{
		foreach ($trace as $row)
		{
			$func = isset($row['function']) ? $row['function'] : null;
			if (!empty($row['class'])) $func = $row['class'] . '::' . $func;
			$args = array();
			if (isset($row['args']) && is_array($row['args'])) foreach ($row['args'] as $arg)
			{
				$argtext = var_export($arg, true);
				if (strlen($argtext) > 32)
				{
					$argtext = gettype($arg);
					if (is_string($arg)) $argtext .= '[' . strlen($arg) . ']';
					elseif (is_array($arg)) $argtext .= '[' . count($arg) . ']';
					elseif (is_object($arg)) $argtext = get_class($arg);
				}
				$args[] = $argtext;
			}
			$file = isset($row['file']) ? $row['file'] : '(unknown file)';
			$line = isset($row['line']) ? $row['line'] : '(unknown line)';
			$args = implode(', ', $args);
			$this->notice('debug', 'Backtrace', 
				"$func($args) in $file line $line", true
				);
		}
	}
}

?>
