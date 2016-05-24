<?php

/*
	textnormal - class for normalisation, word separation, stemming of UTF-8 text
	Copyright (c) 2004, 2016 Thomas Rutter
	
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

// strings can be normalised in various ways, or chopped into distinct words
// ready for sorting or indexing.

if (!defined('BLUESTONE_DIR')) define('BLUESTONE_DIR', dirname(__FILE__));

class textnormal
{
	// ############## Deprecated interface ##################
	private $string, $apos = "'";
	function __construct($string = NULL, $filter = true) {
		if ($filter) {
			require_once(BLUESTONE_DIR . '/utf8_string.inc.php');
			$this->string = utf8::filter($string);
		} else $this->string = $string;
	}
	public function normal($chars = true, $spaces = true, $dashes = true, $punc = true) {
		$str = $this->string;
		if ($chars) $str = self::characters($str, true);
		if ($spaces && $punc) {
			$str = self::wordsep($str);
			if (!$dashes) $str = str_replace('-', ' ', $str);
		} elseif ($spaces) $str = self::spaces($str);
		if ($this->apos != "'") $str = str_replace("'", $apos, $str);
		return $str;
	}
	public function setwebchars() {} // deprecated
	public function setapostrophe($apos) { $this->apos = $apos; } // deprecated
	public function tolower() { return self::lower($this->string); }
	public function words($chars = true) { return explode(' ', $this->normal($chars)); }
	// ######################################################

	public static function lower($str) {
		// UTF sequences starting c2-c9 can be folded
		$tmp = strtr($str, "\xc3\xc4\xc5\xc7\xe1", "\xf8\xf9\xf9\xf9\xf9");

		if ($tmp !== $str) {
			// if there are no c4-c7 then we can use a shorter, faster table
			if (strpos($tmp, "\xf9") === false)
				$str = strtr($str, self::$shortlowertable);
			else
				$str = strtr($str, self::$lowercharstable);
		}
	}

	public static function spaces($str) {

		// space
		$str = str_replace("\xc2\xa0", ' ', $str);
		$str = strtr($str, "\r\n\t\v\f", '     ');

		while ($str !== ($tmp = str_replace('  ', ' ', $str)))
			$str = $tmp;

		return trim($str);
	}

	public static function characters($str, $lowercase = true) {
	// transliterates Unicode characters (including all latin letters in WGL-4)
	// into ascii
	// if $lowercase is true, it also converts them to lowercase
	// otherwise converts them to ascii equivalent of same case
		
		// UTF starting bytes which can be folded
		$tmp = strtr($str, "\xc2\xc3\xc4\xc5\xc6\xc7\xcb\xe1", "\xf8\xf8\xf9\xf9\xf9\xf9\xf9\xf9");

		if ($tmp !== $str) {
			// if there are no c4-c7, cb, e1 then we can use a shorter, faster table
			if (strpos($tmp, "\xf9") === false) {
				$str = strtr($str, self::$shortnormaltable);
			}
			else
				$str = strtr($str, self::$normalletterstable);
		}
		if ($lowercase)
			$str = strtr($str,
				'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
				'abcdefghijklmnopqrstuvwxyz');

		return $str;
	}

	public static function wordsep($str, $except = '') {
	// returns only the words from the input string, separated by single spaces
	// punctuation is removed, with exceptions:
	//   - hyphens, periods and apostrophes in words are allowed
	//   - hyphen and period allowed at start of number, comma allowed within number
	//   - any characters supplied in $except won't be removed
	// $except can only take ASCII punctuation or control chars, and can't accept 
	// hyphens, periods, apostrophes or commas, which get special treatment

		$replace = "\r\n\t\v\f!\"#\$%&()*+/:;<=>?@[\]^_`{|}~";
		if ($except) $replace = strtr($replace, $except, '                                 ');

		$str = strtr($str, $replace, '                                 ');
		if (strpos($str, "\xe2") !== false)
			$str = preg_replace('/\xe2[\x80-\xaf][\x80-\xbf]/S', ' ',
				str_replace("\xe2\x80\x99", "'", str_replace("\xe2\x80\x90", '-', $str))); //todo is x2010 hyphen common?
		if (strpos($str, "\xc2") !== false)
			$str = preg_replace('/\xc2[\x80-\xbf]/S', ' ', str_replace("\xc2\xad", '', $str));
		if (strpos($str, "\xc3") !== false)
			$str = str_replace("\xc3\xb7", ' ', str_replace("\xc3\x97", ' ', $str));
		
		$str = preg_replace('/
			[.\'](?![^ ,.\-\'])
			| ,(?![0-9])
			| (?<=[^ ,.\-\'])-(?![^ ,.\-\'])
			| (?<![0-9]),
			| (?<![^ ,.\-\'])\'
			| (?<![^ ,.\-\'])\.(?![0-9])
			| (?<![^ ,.\-\'])-(?![.0-9])
			/Sx', ' ', $str);

		while ($str !== ($tmp = str_replace('  ', ' ', $str)))
			$str = $tmp;

		return trim($str);
	}

	public function naturalsortindex($normaliseletters = true)
	{
		$webchars = $this->webchars;
		$this->webchars = false;
		$str = $this->normal($normaliseletters);
		$this->webchars = $webchars;
		
		$str = preg_replace('/^the |^an? /i', '', $str);
		
		//if (preg_match('/[0-9]/', $str))
		$str = preg_replace_callback('/(?<![0-9]\.)[1-9][0-9]*+/S', array($this, 'numbertrans_callback'), $str);
		
		return $str;
	}
	
	public function numbertrans_callback($matches)
		//todo does this have to be public?
	{
		$len = strlen($matches[0]);
		if ($len <= 9) return (string)($len - 1) . $matches[0];
		return '9' . (string)($len - 10) . $matches[0];
	}

	private static $shortnormaltable = array(
		// latin-1 punc
		"\xc2\xa0"=>' ', // nbsp
		"\xc2\xad"=>'', // soft hyphen
		// latin 1
		"\xc3\x80"=>"A","\xc3\x81"=>"A","\xc3\x82"=>"A","\xc3\x83"=>"A","\xc3\x84"=>"A",
		"\xc3\x85"=>"A","\xc3\x86"=>"E","\xc3\x87"=>"C","\xc3\x88"=>"E","\xc3\x89"=>"E",
		"\xc3\x8a"=>"E","\xc3\x8b"=>"E","\xc3\x8c"=>"I","\xc3\x8d"=>"I","\xc3\x8e"=>"I",
		"\xc3\x8f"=>"I","\xc3\x90"=>"D","\xc3\x91"=>"N","\xc3\x92"=>"O","\xc3\x93"=>"O",
		"\xc3\x94"=>"O","\xc3\x95"=>"O","\xc3\x96"=>"O","\xc3\x98"=>"O","\xc3\x99"=>"U",
		"\xc3\x9a"=>"U","\xc3\x9b"=>"U","\xc3\x9c"=>"U","\xc3\x9d"=>"Y","\xc3\x9e"=>"TH",
		"\xc3\x9f"=>"ss","\xc3\xa0"=>"a","\xc3\xa1"=>"a","\xc3\xa2"=>"a",
		"\xc3\xa3"=>"a","\xc3\xa4"=>"a","\xc3\xa5"=>"a","\xc3\xa6"=>"e","\xc3\xa7"=>"c",
		"\xc3\xa8"=>"e","\xc3\xa9"=>"e","\xc3\xaa"=>"e","\xc3\xab"=>"e","\xc3\xac"=>"i",
		"\xc3\xad"=>"i","\xc3\xae"=>"i","\xc3\xaf"=>"i","\xc3\xb0"=>"d","\xc3\xb1"=>"n",
		"\xc3\xb2"=>"o","\xc3\xb3"=>"o","\xc3\xb4"=>"o","\xc3\xb5"=>"o","\xc3\xb6"=>"o",
		"\xc3\xb8"=>"o","\xc3\xb9"=>"u","\xc3\xba"=>"u","\xc3\xbb"=>"u","\xc3\xbc"=>"u",
		"\xc3\xbd"=>"y","\xc3\xbe"=>"th","\xc3\xbf"=>"y",
	);

	private static $normalletterstable = array(
		// latin-1 punc
		"\xc2\xa0"=>' ', // nbsp
		"\xc2\xad"=>'', // soft hyphen
		// latin 1
		"\xc3\x80"=>"A","\xc3\x81"=>"A","\xc3\x82"=>"A","\xc3\x83"=>"A","\xc3\x84"=>"A",
		"\xc3\x85"=>"A","\xc3\x86"=>"E","\xc3\x87"=>"C","\xc3\x88"=>"E","\xc3\x89"=>"E",
		"\xc3\x8a"=>"E","\xc3\x8b"=>"E","\xc3\x8c"=>"I","\xc3\x8d"=>"I","\xc3\x8e"=>"I",
		"\xc3\x8f"=>"I","\xc3\x90"=>"D","\xc3\x91"=>"N","\xc3\x92"=>"O","\xc3\x93"=>"O",
		"\xc3\x94"=>"O","\xc3\x95"=>"O","\xc3\x96"=>"O","\xc3\x98"=>"O","\xc3\x99"=>"U",
		"\xc3\x9a"=>"U","\xc3\x9b"=>"U","\xc3\x9c"=>"U","\xc3\x9d"=>"Y","\xc3\x9e"=>"TH",
		"\xc3\x9f"=>"ss","\xc3\xa0"=>"a","\xc3\xa1"=>"a","\xc3\xa2"=>"a",
		"\xc3\xa3"=>"a","\xc3\xa4"=>"a","\xc3\xa5"=>"a","\xc3\xa6"=>"e","\xc3\xa7"=>"c",
		"\xc3\xa8"=>"e","\xc3\xa9"=>"e","\xc3\xaa"=>"e","\xc3\xab"=>"e","\xc3\xac"=>"i",
		"\xc3\xad"=>"i","\xc3\xae"=>"i","\xc3\xaf"=>"i","\xc3\xb0"=>"d","\xc3\xb1"=>"n",
		"\xc3\xb2"=>"o","\xc3\xb3"=>"o","\xc3\xb4"=>"o","\xc3\xb5"=>"o","\xc3\xb6"=>"o",
		"\xc3\xb8"=>"o","\xc3\xb9"=>"u","\xc3\xba"=>"u","\xc3\xbb"=>"u","\xc3\xbc"=>"u",
		"\xc3\xbd"=>"y","\xc3\xbe"=>"th","\xc3\xbf"=>"y",
		// latin ext-a
		"\xc4\x80"=>"A","\xc4\x82"=>"A","\xc4\x84"=>"A","\xc4\x86"=>"C","\xc4\x88"=>"C",
		"\xc4\x8a"=>"C","\xc4\x8c"=>"C","\xc4\x8e"=>"D","\xc4\x90"=>"D","\xc4\x92"=>"E",
		"\xc4\x94"=>"E","\xc4\x96"=>"E","\xc4\x98"=>"E","\xc4\x9a"=>"E","\xc4\x9c"=>"G",
		"\xc4\x9e"=>"G","\xc4\xa0"=>"G","\xc4\xa2"=>"G","\xc4\xa4"=>"H","\xc4\xa6"=>"H",
		"\xc4\xa8"=>"I","\xc4\xaa"=>"I","\xc4\xac"=>"I","\xc4\xae"=>"I","\xc4\xb0"=>"I",
		"\xc4\xb2"=>"IJ","\xc4\xb4"=>"J","\xc4\xb6"=>"K","\xc4\xb9"=>"L","\xc4\xbb"=>"L",
		"\xc4\xbd"=>"L","\xc4\xbf"=>"L","\xc5\x81"=>"L","\xc5\x83"=>"N","\xc5\x85"=>"N",
		"\xc5\x87"=>"N","\xc5\x8a"=>"N","\xc5\x8c"=>"O","\xc5\x8e"=>"O","\xc5\x90"=>"O",
		"\xc5\x92"=>"OE","\xc5\x94"=>"R","\xc5\x96"=>"R","\xc5\x98"=>"R","\xc5\x9a"=>"S",
		"\xc5\x9c"=>"S","\xc5\x9e"=>"S","\xc5\xa0"=>"S","\xc5\xa2"=>"T","\xc5\xa4"=>"T",
		"\xc5\xa6"=>"T","\xc5\xa8"=>"U","\xc5\xaa"=>"U","\xc5\xac"=>"U","\xc5\xae"=>"U",
		"\xc5\xb0"=>"U","\xc5\xb2"=>"U","\xc5\xb4"=>"W","\xc5\xb6"=>"Y",
		"\xc5\xb8"=>"Y",
		"\xc5\xb9"=>"Z",
		"\xc5\xbb"=>"Z","\xc5\xbd"=>"Z",
		"\xc4\x81"=>"a","\xc4\x83"=>"a",
		"\xc4\x85"=>"a","\xc4\x87"=>"c","\xc4\x89"=>"c","\xc4\x8b"=>"c","\xc4\x8d"=>"c",
		"\xc4\x8f"=>"d","\xc4\x91"=>"d","\xc4\x93"=>"e","\xc4\x95"=>"e","\xc4\x97"=>"e",
		"\xc4\x99"=>"e","\xc4\x9b"=>"e","\xc4\x9d"=>"g","\xc4\x9f"=>"g","\xc4\xa1"=>"g",
		"\xc4\xa3"=>"g","\xc4\xa5"=>"h","\xc4\xa7"=>"h","\xc4\xa9"=>"i","\xc4\xab"=>"i",
		"\xc4\xad"=>"i","\xc4\xaf"=>"i","\xc4\xb1"=>"i","\xc4\xb3"=>"ij","\xc4\xb5"=>"j",
		"\xc4\xb7"=>"k","\xc4\xb8"=>"q","\xc4\xba"=>"l","\xc4\xbc"=>"l","\xc4\xbe"=>"l",
		"\xc5\x80"=>"l","\xc5\x82"=>"l","\xc5\x84"=>"n","\xc5\x86"=>"n","\xc5\x88"=>"n",
		"\xc5\x89"=>"n","\xc5\x8b"=>"n","\xc5\x8d"=>"o","\xc5\x8f"=>"o","\xc5\x91"=>"o",
		"\xc5\x93"=>"oe","\xc5\x95"=>"r","\xc5\x97"=>"r","\xc5\x99"=>"r","\xc5\x9b"=>"s",
		"\xc5\x9d"=>"s","\xc5\x9f"=>"s","\xc5\xa1"=>"s","\xc5\xa3"=>"t","\xc5\xa5"=>"t",
		"\xc5\xa7"=>"t","\xc5\xa9"=>"u","\xc5\xab"=>"u","\xc5\xad"=>"u","\xc5\xaf"=>"u",
		"\xc5\xb1"=>"u","\xc5\xb3"=>"u","\xc5\xb5"=>"w","\xc5\xb7"=>"y","\xc5\xba"=>"z",
		"\xc5\xbc"=>"z","\xc5\xbe"=>"z","\xc5\xbf"=>"s",
		// latin ext-b (WGL-4 only)
		"\xc6\x92"=>"f",
		"\xc7\xba"=>"A","\xc7\xbc"=>"E","\xc7\xbe"=>"O",
		"\xc7\xbb"=>"a","\xc7\xbd"=>"e","\xc7\xbf"=>"o",
		// modifier letters (WGL-4)
		"\xcb\x86"=>"","\xcb\x87"=>"","\xcb\x89"=>"",
		"\xcb\x98"=>"","\xcb\x99"=>"","\xcb\x9a"=>"","\xcb\x9b"=>"","\xcb\x9c"=>"","\xcb\x9d"=>"",
		// latin ext-additional (WGL-4 only)
		"\xe1\xba\x80"=>"W","\xe1\xba\x81"=>"w","\xe1\xba\x82"=>"W","\xe1\xba\x83"=>"w",
		"\xe1\xba\x84"=>"W","\xe1\xba\x85"=>"w",
		"\xe1\xbb\xb2"=>"Y","\xe1\xbb\xb3"=>"y",
	);

	/*
	private static $asciifylower = array(
		// latin-1
		"\xc2\xa1"=>'!',"\xc2\xa2"=>'c',"\xc2\xa3"=>"GBP","\xc2\xa5"=>"JPY",
		"\xc2\xa6"=>'|',"\xc2\xa9"=>'(C)',"\xc2\xab"=>'<<',"\xc2\xae"=>'(R)',
		"\xc2\xb0"=>'deg',"\xc2\xb1"=>'+/-',"\xc2\xb5"=>'mu',"\xc2\xb7"=>'.',"\xc2\xbb"=>'>>',
		"\xc2\xbc"=>'1/4',"\xc2\xbd"=>'1/2',"\xc2\xbe"=>'3/4',"\xc2\xbf"=>'?',
	);

	private static $asciifyhigher = array(
		// general punc
		"\xe2\x80\x93"=>' - ',"\xe2\x80\x94"=>' - ',"\xe2\x80\x95"=>' - ',
		"\xe2\x80\x98"=>"'","\xe2\x80\x99"=>"'","\xe2\x80\x9c"=>'"',"\xe2\x80\x9d"=>'"',
		"\xe2\x80\xa2"=>'*',"\xe2\x80\xa6"=>'...',
		"\xe2\x80\xb2"=>"'","\xe2\x80\xb3"=>'"',
		"\xe2\x80\xb9"=>'<',"\xe2\x80\xba"=>'>',"\xe2\x80\xbc"=>'!!',
		"\xe2\x81\x84"=>'/',
		// currency
		"\xe2\x82\xa3"=>'FRF',"\xe2\x82\xa4"=>"Lira","\xe2\x82\xa7"=>"ESP",
		"\xe2\x82\xac"=>'EUR',
		// number forms
		"\xe2\x85\x9b"=>'1/8',"\xe2\x85\x9c"=>'3/8',"\xe2\x85\x9d"=>'5/8',"\xe2\x85\x9e"=>'7/8',
		// math
		"\xe2\x88\x92"=>'-',"\xe2\x88\x95"=>'/',
	);
	 */
	
	private static $shortlowertable = array(
		// latin-1 only
		"\xc3\x80"=>"\xc3\xa0","\xc3\x81"=>"\xc3\xa1","\xc3\x82"=>"\xc3\xa2",
		"\xc3\x83"=>"\xc3\xa3","\xc3\x84"=>"\xc3\xa4","\xc3\x85"=>"\xc3\xa5",
		"\xc3\x86"=>"\xc3\xa6","\xc3\x87"=>"\xc3\xa7","\xc3\x88"=>"\xc3\xa8",
		"\xc3\x89"=>"\xc3\xa9","\xc3\x8a"=>"\xc3\xaa","\xc3\x8b"=>"\xc3\xab",
		"\xc3\x8c"=>"\xc3\xac","\xc3\x8d"=>"\xc3\xad","\xc3\x8e"=>"\xc3\xae",
		"\xc3\x8f"=>"\xc3\xaf","\xc3\x90"=>"\xc3\xb0","\xc3\x91"=>"\xc3\xb1",
		"\xc3\x92"=>"\xc3\xb2","\xc3\x93"=>"\xc3\xb3","\xc3\x94"=>"\xc3\xb4",
		"\xc3\x95"=>"\xc3\xb5","\xc3\x96"=>"\xc3\xb6",
		"\xc3\x98"=>"\xc3\xb8","\xc3\x99"=>"\xc3\xb9","\xc3\x9a"=>"\xc3\xba",
		"\xc3\x9b"=>"\xc3\xbb","\xc3\x9c"=>"\xc3\xbc","\xc3\x9d"=>"\xc3\xbd",
		"\xc3\x9e"=>"\xc3\xbe",
	);

	private static $lowercharstable = array(
		// latin-1
		"\xc3\x80"=>"\xc3\xa0","\xc3\x81"=>"\xc3\xa1","\xc3\x82"=>"\xc3\xa2",
		"\xc3\x83"=>"\xc3\xa3","\xc3\x84"=>"\xc3\xa4","\xc3\x85"=>"\xc3\xa5",
		"\xc3\x86"=>"\xc3\xa6","\xc3\x87"=>"\xc3\xa7","\xc3\x88"=>"\xc3\xa8",
		"\xc3\x89"=>"\xc3\xa9","\xc3\x8a"=>"\xc3\xaa","\xc3\x8b"=>"\xc3\xab",
		"\xc3\x8c"=>"\xc3\xac","\xc3\x8d"=>"\xc3\xad","\xc3\x8e"=>"\xc3\xae",
		"\xc3\x8f"=>"\xc3\xaf","\xc3\x90"=>"\xc3\xb0","\xc3\x91"=>"\xc3\xb1",
		"\xc3\x92"=>"\xc3\xb2","\xc3\x93"=>"\xc3\xb3","\xc3\x94"=>"\xc3\xb4",
		"\xc3\x95"=>"\xc3\xb5","\xc3\x96"=>"\xc3\xb6",
		"\xc3\x98"=>"\xc3\xb8","\xc3\x99"=>"\xc3\xb9","\xc3\x9a"=>"\xc3\xba",
		"\xc3\x9b"=>"\xc3\xbb","\xc3\x9c"=>"\xc3\xbc","\xc3\x9d"=>"\xc3\xbd",
		"\xc3\x9e"=>"\xc3\xbe",
		// latin ext-a
		"\xc4\x80"=>"\xc4\x81","\xc4\x82"=>"\xc4\x83",
		"\xc4\x84"=>"\xc4\x85","\xc4\x86"=>"\xc4\x87","\xc4\x88"=>"\xc4\x89",
		"\xc4\x8a"=>"\xc4\x8b","\xc4\x8c"=>"\xc4\x8d","\xc4\x8e"=>"\xc4\x8f",
		"\xc4\x90"=>"\xc4\x91","\xc4\x92"=>"\xc4\x93","\xc4\x94"=>"\xc4\x95",
		"\xc4\x96"=>"\xc4\x97","\xc4\x98"=>"\xc4\x99","\xc4\x9a"=>"\xc4\x9b",
		"\xc4\x9c"=>"\xc4\x9d","\xc4\x9e"=>"\xc4\x9f","\xc4\xa0"=>"\xc4\xa1",
		"\xc4\xa2"=>"\xc4\xa3","\xc4\xa4"=>"\xc4\xa5","\xc4\xa6"=>"\xc4\xa7",
		"\xc4\xa8"=>"\xc4\xa9","\xc4\xaa"=>"\xc4\xab","\xc4\xac"=>"\xc4\xad",
		"\xc4\xae"=>"\xc4\xaf","\xc4\xb0"=>"\xc4\xb1","\xc4\xb2"=>"\xc4\xb3",
		"\xc4\xb4"=>"\xc4\xb5","\xc4\xb6"=>"\xc4\xb7","\xc4\xb9"=>"\xc4\xba",
		"\xc4\xbb"=>"\xc4\xbc","\xc4\xbd"=>"\xc4\xbe","\xc4\xbf"=>"\xc5\x80",
		"\xc5\x81"=>"\xc5\x82","\xc5\x83"=>"\xc5\x84","\xc5\x85"=>"\xc5\x86",
		"\xc5\x87"=>"\xc5\x88","\xc5\x8a"=>"\xc5\x8b","\xc5\x8c"=>"\xc5\x8d",
		"\xc5\x8e"=>"\xc5\x8f","\xc5\x90"=>"\xc5\x91","\xc5\x92"=>"\xc5\x93",
		"\xc5\x94"=>"\xc5\x95","\xc5\x96"=>"\xc5\x97","\xc5\x98"=>"\xc5\x99",
		"\xc5\x9a"=>"\xc5\x9b","\xc5\x9c"=>"\xc5\x9d","\xc5\x9e"=>"\xc5\x9f",
		"\xc5\xa0"=>"\xc5\xa1","\xc5\xa2"=>"\xc5\xa3","\xc5\xa4"=>"\xc5\xa5",
		"\xc5\xa6"=>"\xc5\xa7","\xc5\xa8"=>"\xc5\xa9","\xc5\xaa"=>"\xc5\xab",
		"\xc5\xac"=>"\xc5\xad","\xc5\xae"=>"\xc5\xaf","\xc5\xb0"=>"\xc5\xb1",
		"\xc5\xb2"=>"\xc5\xb3","\xc5\xb4"=>"\xc5\xb5","\xc5\xb6"=>"\xc5\xb7",
		"\xc5\xb8"=>"\xc3\xbf","\xc5\xb9"=>"\xc5\xba","\xc5\xbb"=>"\xc5\xbc",
		"\xc5\xbd"=>"\xc5\xbe",
		// latin ext-b (WGL-4 only)
		"\xc7\xba"=>"\xc7\xbb","\xc7\xbc"=>"\xc7\xbd","\xc7\xbe"=>"\xc7\xbf",
		// latin ext-additional (WGL-4 only)
		"\xe1\xba\x80"=>"\xe1\xba\x81","\xe1\xba\x82"=>"\xe1\xba\x83",
		"\xe1\xba\x84"=>"\xe1\xba\x85",
		"\xe1\xbb\xb2"=>"\xe1\xbb\xb3",
	);


}

?>
