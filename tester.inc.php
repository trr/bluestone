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
	public static function testdir($dir)
	{
		$debug = debug::getinstance(true);
		$dir = rtrim($dir, '\\/') . '/';
		$hnd = opendir($dir);
		$tested = 0;
		while (($filename = readdir($hnd)) !== false)
			if ($filename != '.' && $filename != '..')
		{
			if (is_dir($dir . $filename))
				$tested += tester::testdir($dir . $filename);
			elseif (preg_match('#.\.php[56]?$#i', $filename))
				$tested += tester::testfile($dir . $filename);
		}
	}

	public static function testfile($file)
	{
		$debug = debug::getinstance(true);
		$before = get_declared_classes();
		require($file);
		$after = get_declared_classes();
		$newclasses = array_diff($after, $before);
		$tested = 0;
		foreach ($newclasses as $classname)
		{
			if ($classname == 'tester' || !is_subclass_of($classname, 'tester')) continue;
			$tested += tester::testclass($classname);
		}
		return $tested;
	}

	public static function testclass($classname)
	{
		$debug = debug::getinstance(true);
		if ($classname == 'tester' || !is_subclass_of($classname, 'tester')) return 0;
		$methods = get_class_methods($classname);
		$tested = 0;
		foreach ($methods as $method) if (preg_match('#^test#i', $method))
		{
			$obj = new $classname();
			$obj->$method();
			$tested++;
			unset($obj);
		}
		return $tested;
	}
}

?>
