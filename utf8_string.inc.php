<?php

/*
	utf8_string - class for handling utf8 strings
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

// must functions are optimised for best performance on characters that fall within
// the ascii range, so the overhead on most english and european content will be
// low.

// deprecated interface kept for compatibility
// note: tolower() was rarely used, it will be moved to textnormal class
// validate() was virtually never used, it's gone
class utf8_string {
	private $str;
	function __construct($str = '') { $this->str = (string)$str; }
	public function getstring() { return $this->str; }
	public function filter($a = '', $b = true, $c = false) { return utf8::filter($this->str); }
	public function convertdoubleutf8() { return utf8::convertdoubleutf8($this->str); }
}

class utf8 {

	public static function filter($str) {
	// filters the string ensuring it's valid UTF-8
	// If already valid, is returned unmodified very quickly - otherwise:
	// - converts incorrectly-converted CP-1252 codepoints to their Unicode equivalent
	// - strips out control characters (except /r,/n,/t), non-characters and reserved codepoints
	// - If it's not UTF-8 encoded, treats it as ASCII/ISO-8859-1/CP-1252 and converts
	//   accordingly
	// This is best used when you are fairly sure the string is UTF-8, and you just want
	// to guard against encoding errors in input.

		if (preg_match('/^[\x20-\x7e\x0a\x0d\x09\PC\p{Cf}\p{Co}]*$/u', $str))
			return $str;

		if (preg_match('/./u', $str)) {
			// if it is valid UTF8 with control codes/noncharacters, filter

			// do we have code points in C1 that would be valid CP-1252?
			if (strpos($str, "\xc2") !== false) {
				$str = str_replace(array(
					"\xc2\x80","\xc2\x82","\xc2\x83","\xc2\x84","\xc2\x85","\xc2\x86",
					"\xc2\x87","\xc2\x88","\xc2\x89","\xc2\x8a","\xc2\x8b","\xc2\x8c",
					"\xc2\x8e","\xc2\x91","\xc2\x92","\xc2\x93","\xc2\x94","\xc2\x95",
					"\xc2\x96","\xc2\x97","\xc2\x98","\xc2\x99","\xc2\x9a","\xc2\x9b",
					"\xc2\x9c","\xc2\x9e","\xc2\x9f",
					),array(
					"\xe2\x82\xac","\xe2\x80\x9a","\xc6\x92","\xe2\x80\x9e","\xe2\x80\xa6","\xe2\x80\xa0",
					"\xe2\x80\xa1","\xcb\x86","\xe2\x80\xb0","\xc5\xa0","\xe2\x80\xb9","\xc5\x92",
					"\xc5\xbd","\xe2\x80\x98","\xe2\x80\x99","\xe2\x80\x9c","\xe2\x80\x9d","\xe2\x80\xa2",
					"\xe2\x80\x93","\xe2\x80\x94","\xcb\x9c","\xe2\x84\xa2","\xc5\xa1","\xe2\x80\xba",
					"\xc5\x93","\xc5\xbe","\xc5\xb8",
					),$str);
			}

			return preg_replace('/[^\x20-\x7e\x0a\x0d\x09\PC\p{Cf}\p{Co}]/u', "\xef\xbf\xbd", $str);
		}

		return self::filter(utf8_encode($str));
	}
	
	public static function convertdoubleutf8($str)
	// detects whether the string looks like improperly double-encoded utf-8
	// with or without a cp1252-utf8 conversion and if it does, try to convert 
	// it back.
	// Note that double-encoded utf-8 is usually still valid utf-8, so it is not
	// possible to tell with complete certainty, so only use this on fields you
	// know are likely to be double-encoded utf-8
	// note that double-encoding utf-8 then using this function isn't loss-less;
	// if the double-encoding also did a cp1252 to utf-8 conversion then
	// some characters may have been lost due to gaps in cp1252
	{
		// opt: if it's UTF-8 and contained within a selection of characters that
		// couldn't be used in double-encoded UTF-8 on their own
		if (preg_match('/^[\x20-\x7e\x09\x0a\x0d\x{c0}-\x{151}]*$/u', $str))
			return $str;

		// first check if the unicode code points look like another layer of utf-8 encoding
		// (rough check, not exact, but false positives probably low)
		if (!preg_match('/^(?:[\x00-\x7f]++|[\xc2-\xf4][\x80-\xbf\x{152}\x{153}\x{160}\x{161}\x{17d}\x{178}\x{17e}\x{192}\x{2c6}\x{2dc}\x{2013}\x{2014}\x{2018}-\x{2019}\x{201a}\x{201c}-\x{201e}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203a}\x{20ac}\x{2122}]{1,3})++$/u', $str))
			return self::filter($str);

		// why not just use utf8_decode?  It doesn't support code points outside latin-1
		// Also I didn't want to be dependant on mbstring (for this entire class)
		return self::filter(strtr($str, array(
			// undo characters that also went through a cp1252-utf8 conversion
			"\xe2\x82\xac"=>"\x80","\xe2\x80\x9a"=>"\x82","\xc6\x92"=>"\x83","\xe2\x80\x9e"=>"\x84",
			"\xe2\x80\xa6"=>"\x85","\xe2\x80\xa0"=>"\x86","\xe2\x80\xa1"=>"\x87","\xcb\x86"=>"\x88",
			"\xe2\x80\xb0"=>"\x89","\xc5\xa0"=>"\x8a","\xe2\x80\xb9"=>"\x8b","\xc5\x92"=>"\x8c",
			"\xc5\xbd"=>"\x8e","\xe2\x80\x98"=>"\x91","\xe2\x80\x99"=>"\x92","\xe2\x80\x9c"=>"\x93",
			"\xe2\x80\x9d"=>"\x94","\xe2\x80\xa2"=>"\x95","\xe2\x80\x93"=>"\x96","\xe2\x80\x94"=>"\x97",
			"\xcb\x9c"=>"\x98","\xe2\x84\xa2"=>"\x99","\xc5\xa1"=>"\x9a","\xe2\x80\xba"=>"\x9b",
			"\xc5\x93"=>"\x9c","\xc5\xbe"=>"\x9e","\xc5\xb8"=>"\x9f",
			// undo any other codes \x80-\xff
			"\xc2\x80"=>"\x80","\xc2\x81"=>"\x81","\xc2\x82"=>"\x82","\xc2\x83"=>"\x83",
			"\xc2\x84"=>"\x84","\xc2\x85"=>"\x85","\xc2\x86"=>"\x86","\xc2\x87"=>"\x87",
			"\xc2\x88"=>"\x88","\xc2\x89"=>"\x89","\xc2\x8a"=>"\x8a","\xc2\x8b"=>"\x8b",
			"\xc2\x8c"=>"\x8c","\xc2\x8d"=>"\x8d","\xc2\x8e"=>"\x8e","\xc2\x8f"=>"\x8f",
			"\xc2\x90"=>"\x90","\xc2\x91"=>"\x91","\xc2\x92"=>"\x92","\xc2\x93"=>"\x93",
			"\xc2\x94"=>"\x94","\xc2\x95"=>"\x95","\xc2\x96"=>"\x96","\xc2\x97"=>"\x97",
			"\xc2\x98"=>"\x98","\xc2\x99"=>"\x99","\xc2\x9a"=>"\x9a","\xc2\x9b"=>"\x9b",
			"\xc2\x9c"=>"\x9c","\xc2\x9d"=>"\x9d","\xc2\x9e"=>"\x9e","\xc2\x9f"=>"\x9f",
			"\xc2\xa0"=>"\xa0","\xc2\xa1"=>"\xa1","\xc2\xa2"=>"\xa2","\xc2\xa3"=>"\xa3",
			"\xc2\xa4"=>"\xa4","\xc2\xa5"=>"\xa5","\xc2\xa6"=>"\xa6","\xc2\xa7"=>"\xa7",
			"\xc2\xa8"=>"\xa8","\xc2\xa9"=>"\xa9","\xc2\xaa"=>"\xaa","\xc2\xab"=>"\xab",
			"\xc2\xac"=>"\xac","\xc2\xad"=>"\xad","\xc2\xae"=>"\xae","\xc2\xaf"=>"\xaf",
			"\xc2\xb0"=>"\xb0","\xc2\xb1"=>"\xb1","\xc2\xb2"=>"\xb2","\xc2\xb3"=>"\xb3",
			"\xc2\xb4"=>"\xb4","\xc2\xb5"=>"\xb5","\xc2\xb6"=>"\xb6","\xc2\xb7"=>"\xb7",
			"\xc2\xb8"=>"\xb8","\xc2\xb9"=>"\xb9","\xc2\xba"=>"\xba","\xc2\xbb"=>"\xbb",
			"\xc2\xbc"=>"\xbc","\xc2\xbd"=>"\xbd","\xc2\xbe"=>"\xbe","\xc2\xbf"=>"\xbf",
			"\xc3\x80"=>"\xc0","\xc3\x81"=>"\xc1","\xc3\x82"=>"\xc2","\xc3\x83"=>"\xc3",
			"\xc3\x84"=>"\xc4","\xc3\x85"=>"\xc5","\xc3\x86"=>"\xc6","\xc3\x87"=>"\xc7",
			"\xc3\x88"=>"\xc8","\xc3\x89"=>"\xc9","\xc3\x8a"=>"\xca","\xc3\x8b"=>"\xcb",
			"\xc3\x8c"=>"\xcc","\xc3\x8d"=>"\xcd","\xc3\x8e"=>"\xce","\xc3\x8f"=>"\xcf",
			"\xc3\x90"=>"\xd0","\xc3\x91"=>"\xd1","\xc3\x92"=>"\xd2","\xc3\x93"=>"\xd3",
			"\xc3\x94"=>"\xd4","\xc3\x95"=>"\xd5","\xc3\x96"=>"\xd6","\xc3\x97"=>"\xd7",
			"\xc3\x98"=>"\xd8","\xc3\x99"=>"\xd9","\xc3\x9a"=>"\xda","\xc3\x9b"=>"\xdb",
			"\xc3\x9c"=>"\xdc","\xc3\x9d"=>"\xdd","\xc3\x9e"=>"\xde","\xc3\x9f"=>"\xdf",
			"\xc3\xa0"=>"\xe0","\xc3\xa1"=>"\xe1","\xc3\xa2"=>"\xe2","\xc3\xa3"=>"\xe3",
			"\xc3\xa4"=>"\xe4","\xc3\xa5"=>"\xe5","\xc3\xa6"=>"\xe6","\xc3\xa7"=>"\xe7",
			"\xc3\xa8"=>"\xe8","\xc3\xa9"=>"\xe9","\xc3\xaa"=>"\xea","\xc3\xab"=>"\xeb",
			"\xc3\xac"=>"\xec","\xc3\xad"=>"\xed","\xc3\xae"=>"\xee","\xc3\xaf"=>"\xef",
			"\xc3\xb0"=>"\xf0","\xc3\xb1"=>"\xf1","\xc3\xb2"=>"\xf2","\xc3\xb3"=>"\xf3",
			"\xc3\xb4"=>"\xf4","\xc3\xb5"=>"\xf5","\xc3\xb6"=>"\xf6","\xc3\xb7"=>"\xf7",
			"\xc3\xb8"=>"\xf8","\xc3\xb9"=>"\xf9","\xc3\xba"=>"\xfa","\xc3\xbb"=>"\xfb",
			"\xc3\xbc"=>"\xfc","\xc3\xbd"=>"\xfd","\xc3\xbe"=>"\xfe","\xc3\xbf"=>"\xff",
			)));
	}
	
}

/*
//echo preg_match('//u', "ABC\xc9");
//smp:F0 9F 98 90 
//bad1252: C2 89
//control: 05 or C2 81
//notutf8: 80 c2

$str = "The quick brown fox jumps over the lazy dog";

$microtime = microtime(true);

for ($i = 0; $i < 10000; $i++) {
	$result = utf8::filter($str);
}

echo "\n" . (microtime(true) - $microtime) * 1000;
echo "\n" . $result;
 */

?>
