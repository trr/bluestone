<?php

/*
	diff - for comparing, merging, between strings and files
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

require_once(dirname(__FILE__) . '/../tester.inc.php');
require_once(dirname(__FILE__) . '/../diff.inc.php');

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
		$this->assert($result[2], '==', array(40,2,49,2));

		$result2 = diff::dodiff($a, $b, true);
		$this->assert($result, '==', $result2);
	}

	function test_dodiff_file()
	{
		$result = diff::dodiff_file(__FILE__, __FILE__);
		$this->assert(count($result), '==', 0);

		$result = diff::dodiff_file(__FILE__,dirname(__FILE__).'/../diff.inc.php');
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
