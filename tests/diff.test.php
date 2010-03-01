<?php

/*
	diff - for comparing, merging, between strings and files
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

require_once('../tester.inc.php');
require_once('../diff.inc.php');

class difftester extends tester
{
	function test_strgetsamelen()
	{
		$a = 'The quick brown fox jumps over the lazy dog.';
		$b = 'The quick brown fox leaps over my wierd big lazy pig.';

		$this->assert(diff::strgetsamelen($a, $b), '==', 20);
		$this->assert(diff::strgetsamelen($a, $b, 5, 5), '==', 15);
		$this->assert(diff::strgetsamelen($a, $b, 0, 0, true), '==', 20);
	}

	function test_strgetrevsamelen()
	{
		$a = 'The quick brown fox jumps over the lazy dog.';
		$b = 'The quick brown dog jumps over the lazy dog.';

		$this->assert(diff::strgetrevsamelen($a, $b), '==', 25);
	}

	function test_strgetdifflen()
	{
		$a = 'The quick brown fox jumps over the lazy dog.';
		$b = 'ppppppppppppppppjumps over the lazy dog.';
		$c = 'A strange blue fox jumps over the lazy dog.';

		$this->assert(diff::strgetdifflen($a, $b), '==', array(20,16)); 
		$this->assert(diff::strgetdifflen($a, $b, 3, 4), '==', array(17,12));
		$this->assert(diff::strgetdifflen($b, $c), '==', array(16,19));
		$this->assert(diff::strgetdifflen($a, $c, 0, 0, true), '==', array(15,14));
	}

	function test_dodiff()
	{
		$a = 'The quick brown fox jumps over the lazy dog.';
		$b = 'The quick brown fox leaps over my wierd big lazy pig.';

		$result = diff::dodiff($a, $b);

		$this->assert(count($result), '==', 3);
		$this->assert($result[0], '==', array(20,3,20,3));
		$this->assert($result[1], '==', array(31,3,31,12));
		$this->assert($result[2], '==', array(40,4,49,4));

		$result2 = diff::dodiff($a, $b, true);
		$this->assert($result, '==', $result2);
	}

	function test_dodiff_file()
	{
		$result = diff::dodiff_file(__FILE__, __FILE__);
		$this->assert(count($result), '==', 0);

		$result = diff::dodiff_file(__FILE__,'../diff.inc.php');
		$this->assert(count($result), '>', 0);
	}

	function test_reverse()
	{
		$result = diff::reverse(array(
			array(20,3,20,3),
			array(31,3,31,12),
			array(40,4,49,4)
			));
		$this->assert($result, '==', array(
			array(20,3,20,3),
			array(31,12,31,3),
			array(49,4,40,4)
			));

		// test re-ordering
		$result = diff::reverse(array(
			array(20,3,40,3),
			array(31,3,31,12),
			));
		$this->assert($result, '==', array(
			array(31,12,31,3),
			array(40,3,20,3),
			));
	}
}

?>