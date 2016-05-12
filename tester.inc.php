<?php

/*
	tester - simple unit testing helper
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

if (!defined('BLUESTONE_DIR'))
	define('BLUESTONE_DIR', dirname(__FILE__));
require_once(BLUESTONE_DIR . '/debug.inc.php');

register_shutdown_function(array('tester', '_sdfunc'));

class tester
{
	protected
		$asserts = 0,
		$passed = true;

	private static
		$tests = 0,
		$classes = 0,
		$errors = 0,
		$asserts_total = 0;

	public static function _sdfunc()
	{
		self::runincluded();
		if (!self::$classes) return;
		self::report();
	}

	public static function runincluded()
	{
		$debug = debug::getinstance(true);
		$classes = get_declared_classes();
		foreach ($classes as $classname)
			if (is_subclass_of($classname, 'tester'))
		{
			tester::runclass($classname);
		}
	}

	public static function runclass($classname)
	// test all test methods found in the given classname.  the class
	// must have already been declared, ie the file it is declared in
	// must already have been parsed.
	{
		$debug = debug::getinstance(true);
		$methods = get_class_methods($classname);
		$hastests = false;
		foreach ($methods as $method) if (preg_match('#^test#i', $method))
		{
			$hastests = true;
			try
			{
				$obj = new $classname();
				$obj->$method();
				if (!$obj->passed) self::$errors++;
				self::$asserts_total += $obj->asserts;
				unset($obj);
			}
			catch (Exception $e)
			{
				$debug->debug_errorhandler($e);
			}
			self::$tests++;
		}
		if ($hastests) self::$classes++;
	}

	public static function report()
	// displays a report of tests passed/failed
	{
		$message = '';
		$s = self::$tests != 1 ? 's' : '';
		$es = self::$classes != 1 ? 'es' : '';
		if (!self::$errors)
		{
			$message = "SUCCESS: " . self::$tests . " test$s passed in ".
				self::$classes . " class$es";
		}
		else
		{
			$message = "FAILURE --- " . self::$errors . '/' .
				self::$tests . " test$s FAILED in " . self::$classes .
				" class$es";
		}
		$debug = debug::getinstance();
		$debug->halt($message, self::$errors ? 1 : 0);
	}

	public static function includedir($dir, $recurse = true)
		// include all php files in given directory.
		// this will cause all code in all the php files to be executed, so
		// only execute in a trusted directory which consists only of test
		// classes.  will recurse into subdirectories by default.
	{
		$dir = rtrim($dir, '\\/') . '/';
		$hnd = opendir($dir);
		while (($filename = readdir($hnd)) !== false)
			if ($filename != '.' && $filename != '..')
		{
			if (is_dir($dir . $filename))
			{
				if ($recurse) tester::includedir($dir . $filename, true);
			}
			elseif (preg_match('#.\.php[56]?$#i', $filename))
				require_once($dir . $filename);
		}
	}

	public function assert($val, $op='==', $rval=true)
		// makes an assertion.  when given with one argument $val, asserts that
		// $val evaluates to true.  or you can specify an operator and a right value
	{
		$this->asserts++;
		if (!$this->passed) return false;
		switch ($op)
		{
		case '==': $p = ($val == $rval); break;
		case '>=': $p = ($val >= $rval); break;
		case '<=': $p = ($val <= $rval); break;
		case '>': $p = ($val > $rval); break;
		case '<': $p = ($val < $rval); break;
		case '!=': $p = ($val != $rval); break;
		case '===': $p = ($val === $rval); break;
		case '|': $p = ($val | $rval ? true : false); break;
		case '&': $p = ($val & $rval ? true : false); break;
		default: trigger_error("Unknown operator '$op':", E_USER_ERROR);
		}
		if (!$p)
		{
			$this->passed = false;
			$inmsg = $func = '';
			$traces = debug_backtrace();
			if (count($traces) >= 2)
			{
				list($trace0, $trace1) = $traces;
				$inmsg = " in $trace0[file] line $trace0[line]";
				$func = "$trace1[function]: ";
			}
			$debug = debug::getinstance();
			$debug->notice('tester', 'Test failed', $func . var_export($val, true) . " not $op " . var_export($rval,  true) . $inmsg, true);
			return false;
		}
		return true;
	}
}

?>
