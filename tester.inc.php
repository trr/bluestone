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
		$passed = true,
		$failmsg = null;

	private static
		$tests = 0,
		$classes = 0,
		$asserts_total = 0,
		$errors = array();

	public static function testdir($dir, $recurse = true)
		// test all test classes found in all php files in given directory.
		// this will cause all code in all the php files to be executed, so
		// only execute in a trusted directory which consists only of test
		// classes.  will recurse into subdirectories by default.
	{
		$debug = debug::getinstance(true);
		$dir = rtrim($dir, '\\/') . '/';
		$hnd = opendir($dir);
		$tested = 0;
		while (($filename = readdir($hnd)) !== false)
			if ($filename != '.' && $filename != '..')
		{
			if (is_dir($dir . $filename))
			{
				if ($recurse) tester::testdir($dir . $filename, true);
			}
			elseif (preg_match('#.\.php[56]?$#i', $filename))
				tester::testfile($dir . $filename);
		}
	}

	public static function testfile($file)
		// test all test classes found in the given file.
	{
		$debug = debug::getinstance(true);
		$before = get_declared_classes();
		require($file);
		$after = get_declared_classes();
		$newclasses = array_diff($after, $before);
		foreach ($newclasses as $classname)
		{
			if ($classname == 'tester' || !is_subclass_of($classname, 'tester')) continue;
			tester::testclass($classname);
		}
	}

	public static function testclass($classname)
		// test all test classes found in the given classname.  the class
		// must have already been declared, ie the file it is declared in
		// must already have been parsed.
	{
		$debug = debug::getinstance(true);
		if ($classname == 'tester' || !is_subclass_of($classname, 'tester')) return 0;
		$methods = get_class_methods($classname);
		$hastests = false;
		foreach ($methods as $method) if (preg_match('#^test#i', $method))
		{
			$hastests = true;
			$obj = new $classname();
			$obj->$method();
			if (!$obj->passed)
				self::$errors[$classname . '::' . $method] = $obj->failmsg;
			self::$asserts_total += $obj->asserts;
			unset($obj);
			self::$tests++;
		}
		if ($hastests) self::$classes++;
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
			$this->failmsg = "Assertion failed: " . var_export($val, true) . " is not $op "
				. var_export($rval, true);
			return false;
		}
		return true;
	}
}

?>
