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

// 20090309: 
// words() has been removed from this class; it can now be found in the
// normaltext() class

class utf8_string
{
	private
		$string;

	function __construct($string = NULL)
	{
		if (is_string($string))
			$this->string = $string;
		else
			$this->string = (string)$string;
	}
	
	public function getstring()
	// returns string value without any conversions
	{
		return $this->string;
	}

	public function validate($allowcontrolcodes = false)
	// returns true if this is a valid utf-8 string, false otherwise.  
	// if allowcontrolcodes is false (default), then most C0 codes below 0x20, as
	// well as C1 codes 127-159, and Unicode non-characters, will be denied - recommend false for html/xml
	{
		return ($this->string=='' || 
			($allowcontrolcodes ? preg_match('/^.*$/su', $this->string) :
			(preg_match('/^[\x20-\x7e\x09\x0a\x0d\x{a0}-\x{fdcf}]++$/u', $this->string) || preg_match(
				'/^[\x20-\x7e\x09\x0a\x0d\x{a0}-\x{fdcf}\x{fdf0}-\x{fffd}' . 
				'\x{10000}-\x{1fffd}\x{20000}-\x{2fffd}\x{30000}-\x{3fffd}\x{40000}-\x{4fffd}\x{50000}-\x{5fffd}' .
				'\x{60000}-\x{6fffd}\x{70000}-\x{7fffd}\x{80000}-\x{8fffd}\x{90000}-\x{9fffd}\x{a0000}-\x{afffd}' .
				'\x{b0000}-\x{bfffd}\x{c0000}-\x{cfffd}\x{d0000}-\x{dfffd}\x{e0000}-\x{efffd}\x{f0000}-\x{ffffd}' .
				'\x{100000}-\x{10fffd}]++$/u', $this->string)
				)));
	}
	
	public function filter($replace = '', $convert = true, $allowcontrolcodes = false)
	// filters the string.  if it is valid utf-8, then it is returned unmodified.  If it
	// would be valid but contains control codes and allowcontrolcodes are false,
	// they are stripped out.  Otherwise, it is assumed to be either ascii (if convert
	// is false) or iso-8859-1/cp-1252 (otherwise) and converted thusly to utf-8
	{
		// opt: if it's UTF8 AND only contains BMP, return now
		if (preg_match('/^[\x20-\x7e\x09\x0a\x0d\x{a0}-\x{fdcf}]++$/u', $this->string))
			return $this->string;

		if (!$allowcontrolcodes) {
			// opt: if it's valid when including other planes, return now
			if (preg_match(
					'/^[\x20-\x7e\x09\x0a\x0d\x{a0}-\x{fdcf}\x{fdf0}-\x{fffd}' . 
					'\x{10000}-\x{1fffd}\x{20000}-\x{2fffd}\x{30000}-\x{3fffd}\x{40000}-\x{4fffd}\x{50000}-\x{5fffd}' .
					'\x{60000}-\x{6fffd}\x{70000}-\x{7fffd}\x{80000}-\x{8fffd}\x{90000}-\x{9fffd}\x{a0000}-\x{afffd}' .
					'\x{b0000}-\x{bfffd}\x{c0000}-\x{cfffd}\x{d0000}-\x{dfffd}\x{e0000}-\x{efffd}\x{f0000}-\x{ffffd}' .
					'\x{100000}-\x{10fffd}]++$/u', $this->string))
				return $this->string;
			
			// if it is valid UTF8 with control codes/noncharacters, filter
			if (preg_match('/./u', $this->string)) {
				$str = $this->string;

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

				return preg_replace(
				'/[^\x20-\x7e\x09\x0a\x0d\x{a0}-\x{fdcf}\x{fdf0}-\x{fffd}' . 
				'\x{10000}-\x{1fffd}\x{20000}-\x{2fffd}\x{30000}-\x{3fffd}\x{40000}-\x{4fffd}\x{50000}-\x{5fffd}' .
				'\x{60000}-\x{6fffd}\x{70000}-\x{7fffd}\x{80000}-\x{8fffd}\x{90000}-\x{9fffd}\x{a0000}-\x{afffd}' .
				'\x{b0000}-\x{bfffd}\x{c0000}-\x{cfffd}\x{d0000}-\x{dfffd}\x{e0000}-\x{efffd}\x{f0000}-\x{ffffd}' .
				'\x{100000}-\x{10fffd}]/u', preg_quote($replace), $str);
			}
		}
		else if (preg_match('/./u', $this->string)) {
			return $this->string;
		}

		if ($convert) return (new utf8_string(utf8_encode($this->string)))->filter($replace, false, $allowcontrolcodes);
		return preg_replace('/[^\x20-\x7e\x09\x0a\x0d]/', preg_quote($replace), $this->string);
	}
	
	public function tolower()
	// surprisingly fast
	// this is about 10 times faster than strtolower for ascii-only strings,
	//     (long strings; strtolower seems to do better with very short strings)
	// and only about 1.2 times slower than it for unicode
	// note: only selected unicode chars below 0x24f are translated, may need amendments
	{
		// optimise - use ascii tolower when characters \xc3 through \xc9 not present
		if (strtr($this->string, "\xc3\xc4\xc5\xc6\xc7\xc8\xc9", '       ') == $this->string)
			return strtr($this->string,
				'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

		return strtr(strtr($this->string, utf8_string::$lowercharstable),
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
	}

	public function convertdoubleutf8()
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
		if ($this->string == '') return '';

		// first check if the unicode code points look like another layer of utf-8 encoding
		// (rough check, not exact, but false positives probably low)
		if (!preg_match('/^([\x00-\x7f]++|[\xc0-\xf4][\x80-\xbf\x{152}\x{153}\x{160}\x{161}\x{17d}\x{178}\x{17e}\x{192}\x{2c6}\x{2dc}\x{2013}\x{2014}\x{2018}-\x{2019}\x{201a}\x{201c}-\x{201e}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203a}\x{20ac}\x{2122}]{1,3})++$/u', $this->string))
			return $this->filter();

		// why not just use utf8_decode?  It doesn't support code points outside latin-1
		// Also I didn't want to be dependant on mbstring (for this entire class)
		$str = new utf8_string(strtr($this->string, array(
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

		return $str->filter();
	}
	
	private static $lowercharstable = array(
		// case folding set by TRUT, not very comprehensive
		"\xc3\x80"=>"\xc3\xa0","\xc3\x81"=>"\xc3\xa1","\xc3\x82"=>"\xc3\xa2",
		"\xc3\x83"=>"\xc3\xa3","\xc3\x84"=>"\xc3\xa4","\xc3\x85"=>"\xc3\xa5",
		"\xc3\x86"=>"\xc3\xa6","\xc3\x87"=>"\xc3\xa7","\xc3\x88"=>"\xc3\xa8",
		"\xc3\x89"=>"\xc3\xa9","\xc3\x8a"=>"\xc3\xaa","\xc3\x8b"=>"\xc3\xab",
		"\xc3\x8c"=>"\xc3\xac","\xc3\x8d"=>"\xc3\xad","\xc3\x8e"=>"\xc3\xae",
		"\xc3\x8f"=>"\xc3\xaf","\xc3\x90"=>"\xc3\xb0","\xc3\x91"=>"\xc3\xb1",
		"\xc3\x92"=>"\xc3\xb2","\xc3\x93"=>"\xc3\xb3","\xc3\x94"=>"\xc3\xb4",
		"\xc3\x95"=>"\xc3\xb5","\xc3\x96"=>"\xc3\xb6","\xc3\x97"=>"\xc3\xb7",
		"\xc3\x98"=>"\xc3\xb8","\xc3\x99"=>"\xc3\xb9","\xc3\x9a"=>"\xc3\xba",
		"\xc3\x9b"=>"\xc3\xbb","\xc3\x9c"=>"\xc3\xbc","\xc3\x9d"=>"\xc3\xbd",
		"\xc3\x9e"=>"\xc3\xbe","\xc4\x80"=>"\xc4\x81","\xc4\x82"=>"\xc4\x83",
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
		"\xc5\xb8"=>"\xc5\xb9","\xc5\xb9"=>"\xc5\xba","\xc5\xbb"=>"\xc5\xbc",
		"\xc5\xbd"=>"\xc5\xbe","\xc6\x81"=>"\xc6\x80","\xc6\x82"=>"\xc6\x83",
		"\xc6\x84"=>"\xc6\x85","\xc6\x87"=>"\xc6\x88","\xc6\x8a"=>"\xc6\x89",
		"\xc6\x8b"=>"\xc6\x8c","\xc6\x8e"=>"\xc6\x8f","\xc6\x91"=>"\xc6\x92",
		"\xc6\x98"=>"\xc6\x99","\xc6\x9d"=>"\xc6\x9e","\xc6\xa0"=>"\xc6\xa1",
		"\xc6\xa2"=>"\xc6\xa3","\xc6\xa4"=>"\xc6\xa5","\xc6\xa7"=>"\xc6\xa8",
		"\xc6\xaf"=>"\xc6\xb0","\xc6\xb1"=>"\xc6\xb2","\xc6\xb3"=>"\xc6\xb4",
		"\xc6\xb5"=>"\xc6\xb6","\xc6\xb7"=>"\xc6\xba","\xc6\xb8"=>"\xc6\xb9",
		"\xc6\xbc"=>"\xc6\xbd","\xc7\x84"=>"\xc7\x86","\xc7\x85"=>"\xc7\x86",
		"\xc7\x87"=>"\xc7\x89","\xc7\x88"=>"\xc7\x89","\xc7\x8a"=>"\xc7\x8c",
		"\xc7\x8b"=>"\xc7\x8c","\xc7\x8d"=>"\xc7\x8e","\xc7\x8f"=>"\xc7\x90",
		"\xc7\x91"=>"\xc7\x92","\xc7\x93"=>"\xc7\x94","\xc7\x95"=>"\xc7\x96",
		"\xc7\x97"=>"\xc7\x98","\xc7\x99"=>"\xc7\x9a","\xc7\x9b"=>"\xc7\x9c",
		"\xc7\x9e"=>"\xc7\x9f","\xc7\xa0"=>"\xc7\xa1","\xc7\xa2"=>"\xc7\xa3",
		"\xc7\xa4"=>"\xc7\xa5","\xc7\xa6"=>"\xc7\xa7","\xc7\xa8"=>"\xc7\xa9",
		"\xc7\xaa"=>"\xc7\xab","\xc7\xac"=>"\xc7\xad","\xc7\xae"=>"\xc7\xaf",
		"\xc7\xb1"=>"\xc7\xb3","\xc7\xb2"=>"\xc7\xb3","\xc7\xb4"=>"\xc7\xb5",
		"\xc7\xb8"=>"\xc7\xb9","\xc7\xba"=>"\xc7\xbb","\xc7\xbc"=>"\xc7\xbd",
		"\xc7\xbe"=>"\xc7\xbf","\xc8\x80"=>"\xc8\x81","\xc8\x82"=>"\xc8\x83",
		"\xc8\x84"=>"\xc8\x85","\xc8\x86"=>"\xc8\x87","\xc8\x88"=>"\xc8\x89",
		"\xc8\x8a"=>"\xc8\x8b","\xc8\x8c"=>"\xc8\x8d","\xc8\x8e"=>"\xc8\x8f",
		"\xc8\x90"=>"\xc8\x91","\xc8\x92"=>"\xc8\x93","\xc8\x94"=>"\xc8\x95",
		"\xc8\x96"=>"\xc8\x97","\xc8\x98"=>"\xc8\x99","\xc8\x9a"=>"\xc8\x9b",
		"\xc8\x9c"=>"\xc8\x9d","\xc8\x9e"=>"\xc8\x9f","\xc8\xa0"=>"\xc8\xa1",
		"\xc8\xa2"=>"\xc8\xa3","\xc8\xa4"=>"\xc8\xa5","\xc8\xa6"=>"\xc8\xa7",
		"\xc8\xa8"=>"\xc8\xa9","\xc8\xaa"=>"\xc8\xab","\xc8\xac"=>"\xc8\xad",
		"\xc8\xae"=>"\xc8\xaf","\xc8\xb0"=>"\xc8\xb1","\xc8\xb2"=>"\xc8\xb3",
		"\xc8\xbb"=>"\xc8\xbc","\xc9\x81"=>"\xc9\x82","\xc9\x86"=>"\xc9\x87",
		"\xc9\x88"=>"\xc9\x89","\xc9\x8a"=>"\xc9\x8b","\xc9\x8c"=>"\xc9\x8d",
		"\xc9\x8e"=>"\xc9\x8f",);
		
}

/*
$utf8 = new utf8_string('something');

$microtime = microtime(true);

for ($i = 0; $i < 10000; $i++) {
	$result = $utf8->filter();
}

echo "\n" . (microtime(true) - $microtime);
 */

?>
