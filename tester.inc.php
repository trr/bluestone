<?php

/*
	tester - simple unit testing helper
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

if (!defined('BLUESTONE_DIR'))
	define('BLUESTONE_DIR', dirname(__FILE__) . '/');
require_once(BLUESTONE_DIR . 'debug.inc.php');

class tester
{
	protected
		$asserts = 0,
		$passed = true;

	private static
		$registered = false,
		$donottest = array('tester'),
		$tests = 0,
		$classes = 0,
		$errors = 0,
		$asserts_total = 0;

	public static function register()
	// this is actually called when this file is included.  it registers
	// a shutdown function which searches for all declared tests classes
	// and runs them.  to prevent this happening, call tester::noauto()
	{
		if (self::$registered) return;
		register_shutdown_function(array('tester', '_sdfunc'));
		self::$registered = true;
	}

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
			if (is_subclass_of($classname, 'tester') 
				&& !in_array($classname, self::$donottest))
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
		if (!is_subclass_of($classname, 'tester') || in_array($classname, self::$donottest)) return;
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
				require_once($file);
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
			$inmsg = '';
			$traces = debug_backtrace();
			if (count($traces) >= 2)
			{
				list($trace0, $trace1) = $traces;
				$inmsg = " from $trace1[function]() in $trace0[file] line $trace0[line]";
			}
			$debug = debug::getinstance();
			$debug->notice('tester', 'Assertion failed', var_export($val, true) . " is not $op " . var_export($rval,  true) . $inmsg, true);
			return false;
		}
		return true;
	}
}

tester::register();

?>
