<?php

/*
	debug - simple debugging aids
	Copyright (c) 2004, 2014 Thomas Rutter
	
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

// implements the singleton pattern (using debug::getinstance())

// the value $this->debugmode determines whether we bother with things like
// the timer


class debug
{
	private
		$notices = array(),
		$starttime,
		$depth = 0,
		$noticeid = 0,
		$debugmode,
		$useerrorhandler = false;

	function __construct($debugmode = false, $useerrorhandler = true)
	// constructor
	// to use this as a singleton, use getinstance() instead
	{
    $this->debugmode = $debugmode;
		if ($debugmode) $this->starttime = microtime(true);
		
		$this->useerrorhandler($useerrorhandler);
  }

	public function notice($module, $notice, $data = null)
	// module is name of module - should be the classname where the error occurred
	// or 'global' if in global scope
	// notice is name of notice
	// data is further information about this notice such as the arguments
	// errlog should be set to true to always show this in an error report
	{
		$this->notices[++$this->noticeid] = array(
			'module' => $module,
			'notice' => $notice,
			'data' => strlen($data) > 8192 ? substr($data, 0, 8192 - 16) . ' ... (truncated)' : $data,
			'elapsed' => !$this->debugmode ? null : microtime(true) - $this->starttime,
			'depth' => $this->depth
			);

		// control size of debug log (don't allow it to grow indefinitely, as this is a memory leak
		if ($this->noticeid > 251)
			unset($this->notices[$this->noticeid - 250]);
		elseif ($this->noticeid == 251)
			$this->notices[1] = array('module' => 'debug', 'notice' => 'debug log truncated', 'data' => null, 'elapsed' => null);

		return $this->noticeid;
	}
	
	public function starttask($module, $taskname, $data = NULL) {
	// returns a unique task id
		$id = $this->notice($module, $taskname, $data);
		$this->depth++;
		return $id;
	}
	
	public function endtask($noticeid)
	{
		if (!isset($this->notices[$noticeid])) return false;

		if ($this->debugmode) {
			$this->notices[$noticeid]['taskelapsed'] = 
				microtime(true) - $this->starttime - $this->notices[$noticeid]['elapsed'];
		}
		if ($this->depth) $this->depth--;
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
			if ($this->debugmode) {
				error_reporting(-1);
				ini_set('display_errors', '1');
			}
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
	// use
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
				require(BLUESTONE_DIR . '/system/fatalerror.inc.php');
			exit;
		}

		if ($htmlformat) echo '<div style="background:$fff;color:#000">';

		if ($message != '')
		{
			$c = $errorlevel ? '#c00' : '#080';
			echo $htmlformat 
				? "<p style=\"background:$c;color:#fff;font:bold large sans-serif;padding:12px\">"
				: str_repeat("======",13) . "\n";
			echo $htmlformat 
				? htmlspecialchars($message) 
				: wordwrap($message, 78, "\n", true) . "\n";
			echo $htmlformat
				? "</p>"
				: str_repeat("======",13) . "\n";
		}

		echo $htmlformat ? $this->getnoticeshtml(true) : $this->getnoticestext(true);
		if ($htmlformat)
			echo '<p style="font:small sans-serif"><em>Notice: These notices are shown because your site is in DEBUG mode.</em></p>';
		exit((int)$errorlevel);
	}
	
	public function getnoticeshtml() {
		return '<pre>' . htmlspecialchars($this->getnoticestext(160)) . '</pre>';
	}
	
	public function getnoticestext($linelength = 160) {
		$output = '';

    foreach ($this->notices as $notice) {
			$taskelapsed = !empty($notice['taskelapsed']) ? str_pad(number_format($notice['taskelapsed'] * 1000, 2), 7, ' ', STR_PAD_LEFT) : '       ';
			$indent = str_repeat('  ', $notice['depth']);
			$output .= str_pad(number_format($notice['elapsed'] * 1000, 2), 7, ' ', STR_PAD_LEFT) . " $taskelapsed $indent";
			$msg = '[' . $notice['module'] . '] ' . $notice['notice'];
			if ($notice['data'] != '') {
				$linesep = "\n                " . $indent;
				$len = 16 + ($notice['depth']);
				$output .= wordwrap(str_replace("\n", $linesep, $msg . ': ' . $notice['data']), max(31, $linelength - $len), $linesep) . "\n";
			}
			else $output .= $msg . "\n";
		}
			
	  return $output;
	}

	public function debug_errorhandler($err, $errstr='', $errfile='', $errline='')
	// designed as a custom error handler - it logs it into the debug notices as
	// a notice, unless it's a fatal error.
	{
		static $errortypes = array(
			E_ERROR => 'Error', E_WARNING => 'Warning', E_PARSE => 'Parse Error',
			E_NOTICE => 'Notice', E_CORE_ERROR => 'Core Error', E_CORE_WARNING => 'Core Warning',
			E_COMPILE_ERROR => 'Compile Error', E_COMPILE_WARNING => 'Compile Warning',
			E_USER_ERROR => 'User Error', E_USER_WARNING => 'User Warning',
			E_USER_NOTICE => 'User Notice', E_STRICT => 'Strict Error', 4096 => 'Recoverable Error',
			8192 => 'Deprecated Error', 16384 => 'User Deprecated Error',
			);

		if (is_object($err)) {
			$errortype = get_class($err);
			$errcode = $err->getCode();
			if ($errcode) $errortype .= " (code $errcode)";
			$errstr = $err->getMessage();
			$errfile = $err->getFile();
			$errline = $err->getLine();
			$backtrace = $err->getTrace();
		}
		else {
			// the following also allows suppressing errors with @ sign
			if (!($err & error_reporting())) return;

			$errortype = (isset($errortypes[$err])) ? $errortypes[$err] : 'Unknown Error';		
			$backtrace = debug_backtrace();
			foreach ($backtrace as $id => $row)
				if (isset($row['class']) && $row['class'] == 'debug')
					unset($backtrace[$id]);
		}
		$this->notice('debug', 'Error', "$errortype in $errfile line $errline: $errstr", true);
		$this->logtrace($backtrace);
		foreach ($this->tasks as $taskid => $task) // set incomplete tasks as errors
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
			if (isset($row['args']) && is_array($row['args']))
				foreach ($row['args'] as $arg)
					$args[] = is_object($arg) ? get_class($arg) : gettype($arg);
			$args = implode(', ', $args);
			$file = isset($row['file']) ? $row['file'] : '(unknown file)';
			$line = isset($row['line']) ? $row['line'] : '(unknown line)';
			$this->notice('debug', 'Backtrace', 
				"$func($args) in $file line $line", true
				);
		}
	}
}

?>
