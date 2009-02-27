<?php

/*
	utf8_string - class for handling utf8 strings
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

// currently handles utf8 characters of up to four bytes (26 bits)

// must functions are optimised for best performance on characters that fall within
// the ascii range, so the overhead on most english and european content will be
// low.

class utf8_string
{
	function utf8_string($string = NULL)
	{
		$this->string = $string;
	}
	
	function validate($allowcontrolcodes = false)
	// returns true if this is a valid utf-8 string, false otherwise.  
	// if allowcontrolcodes is false (default), then most C0 codes below 0x20, as
	// well as C1 codes 127-159, will be denied - recommend false for html/xml
	{
		if ($this->string=='') return '';
		return preg_match($allowcontrolcodes
			? '/^[\x00-\x{d7ff}\x{e000}-\x{10ffff}]++$/u'
			: '/^[\x20-\x7e\x0a\x09\x0d\x{a0}-\x{d7ff}\x{e000}-\x{10ffff}]++$/u',
			$this->string) ? true : false;	
	}

	function filter($replace = '', $convert = true, $allowcontrolcodes = false)
	// filters the string.  if it is valid utf-8, then it is returned unmodified.  If it
	// would be valid but contains control codes and allowcontrolcodes are false,
	// they are stripped out.  Otherwise, it is assumed to be either ascii (if convert
	// is false) or iso-8859-1/cp-1252 (otherwise) and converted thusly to utf-8
	{
		// make sure this returns very fast if it is valid already
		if ($this->validate($allowcontrolcodes)) return $this->string;
		
		// strip out control codes if they are the only reason it wouldn't validate
		if (!$allowcontrolcodes 
			&& $this->validate(true))
			return str_replace("\0", $replace, strtr(strtr($this->string, 
				"\x1\x2\x3\x4\x5\x6\x7\x8\xb\xc\xe\xf\x10\x11\x12\x13\x14\x15\x16".
				"\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f\x81\x8d\x8f\x90\x9d", 
				"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
				"\0\0\0\0\0\0\0\0\0\0\0\0\0\0"),
				array(
				"\xc2\x80"=>"\0","\xc2\x81"=>"\0","\xc2\x82"=>"\0","\xc2\x83"=>"\0",
				"\xc2\x84"=>"\0","\xc2\x85"=>"\0","\xc2\x86"=>"\0","\xc2\x87"=>"\0",
				"\xc2\x88"=>"\0","\xc2\x89"=>"\0","\xc2\x8a"=>"\0","\xc2\x8b"=>"\0",
				"\xc2\x8c"=>"\0","\xc2\x8d"=>"\0","\xc2\x8e"=>"\0","\xc2\x8f"=>"\0",
				"\xc2\x90"=>"\0","\xc2\x91"=>"\0","\xc2\x92"=>"\0","\xc2\x93"=>"\0",
				"\xc2\x94"=>"\0","\xc2\x95"=>"\0","\xc2\x96"=>"\0","\xc2\x97"=>"\0",
				"\xc2\x98"=>"\0","\xc2\x99"=>"\0","\xc2\x9a"=>"\0","\xc2\x9b"=>"\0",
				"\xc2\x9c"=>"\0","\xc2\x9d"=>"\0","\xc2\x9e"=>"\0","\xc2\x9f"=>"\0",
				)));
		
		if ($convert) return $this->convertfrom1252($replace);
		return $this->convertfromascii($replace);
	}
	
	function normalchars()
	// attempts to normalise any character that is a variant of another.
	// letters with accents get turned into their base letters
	// This can aid in sorting, where different forms of the letter 'a' should
	// sort in the same position
	// note: only selected unicode chars are translated, doesn't process most chars above 0x24f for example
	// this will also convert everything to lowercase.  Therefore converting to lowercase
	// separately is an unnecessary use of cpu cycles.
	{
		$str = $this->tolower();
		
		// optimise - nothing to do if \xc3 through \xc9, or \xcc through \xcd, not present
		if (strtr($str, "\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xcc\xcd", '         ') == $str)
			return $str;
		
		static $transtable = array( 
			// normalisation set up to U+024F, pretty rough by TRUT
			"\xc3\xdf"=>'ss',"\xc3\xa0"=>'a',"\xc3\xa1"=>'a',"\xc3\xa2"=>'a',"\xc3\xa3"=>'a',
			"\xc3\xa4"=>'a',"\xc3\xa5"=>'a',"\xc3\xa6"=>'e',"\xc3\xa7"=>'c',"\xc3\xa8"=>'e',
			"\xc3\xa9"=>'e',"\xc3\xaa"=>'e',"\xc3\xab"=>'e',"\xc3\xac"=>'i',"\xc3\xad"=>'i',
			"\xc3\xae"=>'i',"\xc3\xaf"=>'i',"\xc3\xb0"=>'d',"\xc3\xb1"=>'n',"\xc3\xb2"=>'o',
			"\xc3\xb3"=>'o',"\xc3\xb4"=>'o',"\xc3\xb5"=>'o',"\xc3\xb6"=>'o',/*gap*/
			"\xc3\xb8"=>'o',"\xc3\xb9"=>'u',"\xc3\xba"=>'u',"\xc3\xbb"=>'u',"\xc3\xbc"=>'u',
			"\xc3\xbd"=>'d',"\xc3\xbe"=>'th',"\xc3\xbf"=>'y',"\xc4\x81"=>'a',"\xc4\x83"=>'a',
			"\xc4\x85"=>'a',"\xc4\x87"=>'c',"\xc4\x89"=>'c',"\xc4\x8b"=>'c',"\xc4\x8d"=>'c',
			"\xc4\x8f"=>'d',"\xc4\x91"=>'d',"\xc4\x93"=>'e',"\xc4\x95"=>'e',"\xc4\x97"=>'e',
			"\xc4\x99"=>'e',"\xc4\x9b"=>'e',"\xc4\x9d"=>'g',"\xc4\x9f"=>'g',"\xc4\xa1"=>'g',
			"\xc4\xa3"=>'g',"\xc4\xa5"=>'h',"\xc4\xa7"=>'h',"\xc4\xa9"=>'i',"\xc4\xab"=>'i',
			"\xc4\xad"=>'i',"\xc4\xaf"=>'i',"\xc4\xb1"=>'i',"\xc4\xb3"=>'ij',"\xc4\xb5"=>'j',
			"\xc4\xb7"=>'k',"\xc4\xb8"=>'q',"\xc4\xba"=>'l',"\xc4\xbc"=>'l',"\xc4\xbe"=>'l',
			"\xc5\x80"=>'l',"\xc5\x82"=>'l',"\xc5\x84"=>'n',"\xc5\x86"=>'n',"\xc5\x88"=>'n',
			"\xc5\x89"=>'n',"\xc5\x8b"=>'n',"\xc5\x8d"=>'o',"\xc5\x8f"=>'o',"\xc5\x91"=>'o',
			"\xc5\x93"=>'oe',"\xc5\x95"=>'r',"\xc5\x97"=>'r',"\xc5\x99"=>'r',"\xc5\x9b"=>'s',
			"\xc5\x9d"=>'s',"\xc5\x9f"=>'s',"\xc5\xa1"=>'s',"\xc5\xa3"=>'t',"\xc5\xa5"=>'t',
			"\xc5\xa7"=>'t',"\xc5\xa9"=>'u',"\xc5\xab"=>'u',"\xc5\xad"=>'u',"\xc5\xaf"=>'u',
			"\xc5\xb1"=>'u',"\xc5\xb3"=>'u',"\xc5\xb5"=>'w',"\xc5\xb7"=>'y',"\xc5\xba"=>'z',
			"\xc5\xbc"=>'z',"\xc5\xbe"=>'z',"\xc5\xbf"=>'s',
			"\xc6\x80"=>'b',"\xc6\x83"=>'b',"\xc6\x85"=>'b',/*??*/"\xc6\x88"=>'c',
			"\xc6\x8c"=>'d',"\xc6\x8d"=>'d',/*delta*/"\xc6\x92"=>'f',"\xc6\x95"=>'hv',
			"\xc6\x99"=>'k',"\xc6\x9a"=>'l',"\xc6\x9b"=>'l',/*lambda*/"\xc6\x9e"=>'n',
			"\xc6\xa1"=>'o',"\xc6\xa3"=>'o',"\xc6\xa5"=>'p',"\xc6\xa6"=>'r',"\xc6\xa8"=>'s',
			"\xc6\xaa"=>'s',"\xc6\xab"=>'t',"\xc6\xad"=>'t',"\xc6\xb0"=>'u',"\xc6\xb4"=>'y',
			"\xc6\xb6"=>'z',"\xc6\xb9"=>'z',"\xc6\xba"=>'z',"\xc6\xbb"=>'2',"\xc6\xbd"=>'5',
			"\xc6\xbe"=>'t',"\xc6\xbf"=>'w',
			"\xc7\x80"=>'t',"\xc7\x81"=>'k',/*??*/"\xc7\x82"=>'k',/*??*/"\xc7\x83"=>'k',/*??*/
			"\xc7\x86"=>'z',"\xc7\x89"=>'lj',"\xc7\x8c"=>'nj',"\xc7\x8e"=>'a',"\xc7\x90"=>'i',
			"\xc7\x92"=>'o',"\xc7\x94"=>'u',"\xc7\x96"=>'u',"\xc7\x98"=>'u',"\xc7\x9a"=>'u',
			"\xc7\x9c"=>'u',"\xc7\x9d"=>'e',"\xc7\x9f"=>'a',"\xc7\xa1"=>'a',"\xc7\xa3"=>'e',
			"\xc7\xa5"=>'g',"\xc7\xa7"=>'g',"\xc7\xa9"=>'k',"\xc7\xab"=>'o',"\xc7\xad"=>'o',
			"\xc7\xaf"=>'s',"\xc7\xb0"=>'j',"\xc7\xb3"=>'dz',"\xc7\xb5"=>'g',"\xc7\xb8"=>'n',
			"\xc7\xbb"=>'a',"\xc7\xbd"=>'e',"\xc7\xbf"=>'o',
			"\xc8\x81"=>'a',"\xc8\x83"=>'a',"\xc8\x85"=>'e',"\xc8\x87"=>'e',"\xc8\x89"=>'i',
			"\xc8\x8b"=>'i',"\xc8\x8d"=>'o',"\xc8\x8f"=>'o',"\xc8\x91"=>'r',"\xc8\x93"=>'r',
			"\xc8\x95"=>'u',"\xc8\x97"=>'u',"\xc8\x99"=>'s',"\xc8\x9b"=>'t',"\xc8\x9d"=>'y',
			"\xc8\x9f"=>'h',"\xc8\xa1"=>'d',"\xc8\xa3"=>'ou',"\xc8\xa5"=>'z',"\xc8\xa7"=>'a',
			"\xc8\xa9"=>'e',"\xc8\xab"=>'o',"\xc8\xad"=>'o',"\xc8\xaf"=>'o',"\xc8\xb1"=>'o',
			"\xc8\xb3"=>'y',"\xc8\xb4"=>'l',"\xc8\xb5"=>'n',"\xc8\xb6"=>'t',"\xc8\xb7"=>'j',
			"\xc8\xb8"=>'db',"\xc8\xb9"=>'qp',"\xc8\xbc"=>'c',"\xc8\xbf"=>'s',
			"\xc9\x80"=>'z',"\xc9\x82"=>'t',"\xc9\x87"=>'e',"\xc9\x89"=>'j',"\xc9\x8b"=>'q',
			"\xc9\x8d"=>'r',"\xc9\x8f"=>'y',
			
			// combining marks U+0300 to U+036F (strip them out)
			"\xcc\x80"=>'',"\xcc\x81"=>'',"\xcc\x82"=>'',"\xcc\x83"=>'',"\xcc\x84"=>'',
			"\xcc\x85"=>'',"\xcc\x86"=>'',"\xcc\x87"=>'',"\xcc\x88"=>'',"\xcc\x89"=>'',
			"\xcc\x8a"=>'',"\xcc\x8b"=>'',"\xcc\x8c"=>'',"\xcc\x8d"=>'',"\xcc\x8e"=>'',
			"\xcc\x8f"=>'',"\xcc\x90"=>'',"\xcc\x91"=>'',"\xcc\x92"=>'',"\xcc\x93"=>'',
			"\xcc\x94"=>'',"\xcc\x95"=>'',"\xcc\x96"=>'',"\xcc\x97"=>'',"\xcc\x98"=>'',
			"\xcc\x99"=>'',"\xcc\x9a"=>'',"\xcc\x9b"=>'',"\xcc\x9c"=>'',"\xcc\x9d"=>'',
			"\xcc\x9e"=>'',"\xcc\x9f"=>'',"\xcc\xa0"=>'',"\xcc\xa1"=>'',"\xcc\xa2"=>'',
			"\xcc\xa3"=>'',"\xcc\xa4"=>'',"\xcc\xa5"=>'',"\xcc\xa6"=>'',"\xcc\xa7"=>'',
			"\xcc\xa8"=>'',"\xcc\xa9"=>'',"\xcc\xaa"=>'',"\xcc\xab"=>'',"\xcc\xac"=>'',
			"\xcc\xad"=>'',"\xcc\xae"=>'',"\xcc\xaf"=>'',"\xcc\xb0"=>'',"\xcc\xb1"=>'',
			"\xcc\xb2"=>'',"\xcc\xb3"=>'',"\xcc\xb4"=>'',"\xcc\xb5"=>'',"\xcc\xb6"=>'',
			"\xcc\xb7"=>'',"\xcc\xb8"=>'',"\xcc\xb9"=>'',"\xcc\xba"=>'',"\xcc\xbb"=>'',
			"\xcc\xbc"=>'',"\xcc\xbd"=>'',"\xcc\xbe"=>'',"\xcc\xbf"=>'',"\xcd\x80"=>'',
			"\xcd\x81"=>'',"\xcd\x82"=>'',"\xcd\x83"=>'',"\xcd\x84"=>'',"\xcd\x85"=>'',
			"\xcd\x86"=>'',"\xcd\x87"=>'',"\xcd\x88"=>'',"\xcd\x89"=>'',"\xcd\x8a"=>'',
			"\xcd\x8b"=>'',"\xcd\x8c"=>'',"\xcd\x8d"=>'',"\xcd\x8e"=>'',"\xcd\x8f"=>'',
			"\xcd\x90"=>'',"\xcd\x91"=>'',"\xcd\x92"=>'',"\xcd\x93"=>'',"\xcd\x94"=>'',
			"\xcd\x95"=>'',"\xcd\x96"=>'',"\xcd\x97"=>'',"\xcd\x98"=>'',"\xcd\x99"=>'',
			"\xcd\x9a"=>'',"\xcd\x9b"=>'',"\xcd\x9c"=>'',"\xcd\x9d"=>'',"\xcd\x9e"=>'',
			"\xcd\x9f"=>'',"\xcd\xa0"=>'',"\xcd\xa1"=>'',"\xcd\xa2"=>'',"\xcd\xa3"=>'',
			"\xcd\xa4"=>'',"\xcd\xa5"=>'',"\xcd\xa6"=>'',"\xcd\xa7"=>'',"\xcd\xa8"=>'',
			"\xcd\xa9"=>'',"\xcd\xaa"=>'',"\xcd\xab"=>'',"\xcd\xac"=>'',"\xcd\xad"=>'',
			"\xcd\xae"=>'',"\xcd\xaf"=>'',
			
			);
			
		$str = strtr($str, $transtable);
		return $str;
	}
	
	function tolower()
	// surprisingly fast
	// this is about 10 times faster than strtolower for ascii-only strings,
	// and only about 1.2 times slower than it for unicode
	// note: only selected unicode chars below 0x24f are translated, may need amendments
	{
		// optimise - use ascii tolower when characters \xc3 through \xc9 not present
		if (strtr($this->string, "\xc3\xc4\xc5\xc6\xc7\xc8\xc9", '       ') == $this->string)
			return strtr($this->string,
				'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
		
		static $transtable = array( // case folding set, pretty rough by TRUT
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
				
		return strtr(strtr($this->string, $transtable),
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
		
		return strtolower($this->string);
	}
	
	/*
	function normalspace()
	// normalises spaces and some punctuation so that only words and digits, and punc
	// that may have some meaning, remains, separated by single spaces where anything
	// was removed.
	{
		static $wschars = array(     
			// brief list of word separating chars (insignificant whitespace and some punc.)
			"\xc2\xa6"=>' ',"\xc2\xa8"=>' ',"\xc2\xab"=>' ',"\xc2\xb1"=>' ',"\xc2\xb4"=>' ',
			"\xc2\xb7"=>' ',"\xc2\xbb"=>' ',"\xca\xb9"=>' ',"\xca\xba"=>' ',"\xca\xbb"=>' ',
			"\xca\xbc"=>' ',"\xca\xbd"=>' ',"\xca\xbe"=>' ',"\xca\xbf"=>' ',"\xcb\x80"=>' ',
			"\xcb\x81"=>' ',"\xcb\x82"=>' ',"\xcb\x83"=>' ',"\xcb\xae"=>' ',"\xcc\x80"=>' ',
			"\xcc\x81"=>' ',"\xcc\x8b"=>' ',"\xcc\x8d"=>' ',"\xcc\x8e"=>' ',"\xcc\x8f"=>' ',
			"\xcc\x92"=>' ',"\xcc\x93"=>' ',"\xcc\x94"=>' ',"\xcc\x95"=>' ',"\xcc\x96"=>' ',
			"\xcc\x97"=>' ',"\xcc\x9b"=>' ',"\xcd\x80"=>' ',"\xcd\x81"=>' ',"\xcd\x91"=>' ',
			"\xcc\x97"=>' ',"\xcd\xb4"=>' ',"\xcd\xb5"=>' ',"\xce\x84"=>' ',"\xdf\xb4"=>' ',
			"\xdf\xb5"=>' ',"\xe2\x80\x98"=>' ',"\xe2\x80\x99"=>' ',"\xe2\x80\x9a"=>' ',
			"\xe2\x80\x9b"=>' ',"\xe2\x80\x9c"=>' ',"\xe2\x80\x9d"=>' ',"\xe2\x80\x9e"=>' ',
			"\xe2\x80\x9f"=>' ',"\xe2\x80\xb2"=>' ',"\xe2\x80\xb3"=>' ',"\xe2\x80\xb4"=>' ',
			"\xe2\x80\xb5"=>' ',"\xe2\x80\xb6"=>' ',"\xe2\x80\xb7"=>' ',"\xe2\x9d\x9b"=>' ',
			"\xe2\x9d\x9c"=>' ',"\xe2\x9d\x9d"=>' ',"\xe2\x9d\x9e"=>' ',
			// ascii
			"\n"=>' ',"\r"=>' ',"\t"=>' ',"\""=>' ',"("=>' ',")"=>' ',','=>' ','.'=>' ',
			':'=>' ',';'=>' ','`'=>' ',
			
			"'"=>'',
			
			);
	}
	*/
	
	function words()
	// constant rate of around 0.25 seconds per megabyte
	// 450,000 words per second or 2.5 milliseconds for 1000 words
	{
		static $wschars = array(     
			// brief list of word separating chars (insignificant whitespace and some punc.)
			"\xc2\xa6","\xc2\xa8","\xc2\xab","\xc2\xb1","\xc2\xb4","\xc2\xb7","\xc2\xbb",
			"\xca\xb9","\xca\xba","\xca\xbb","\xca\xbc","\xca\xbd","\xca\xbe","\xca\xbf",
			"\xcb\x80","\xcb\x81","\xcb\x82","\xcb\x83","\xcb\xae","\xcc\x80","\xcc\x81",
			"\xcc\x8b","\xcc\x8d","\xcc\x8e","\xcc\x8f","\xcc\x92","\xcc\x93","\xcc\x94",
			"\xcc\x95","\xcc\x96","\xcc\x97","\xcc\x9b","\xcd\x80","\xcd\x81","\xcd\x91",
			"\xcc\x97","\xcd\xb4","\xcd\xb5","\xce\x84","\xdf\xb4","\xdf\xb5",
			"\xe2\x80\x98","\xe2\x80\x99","\xe2\x80\x9a","\xe2\x80\x9b","\xe2\x80\x9c",
			"\xe2\x80\x9d","\xe2\x80\x9e","\xe2\x80\x9f","\xe2\x80\xb2","\xe2\x80\xb3",
			"\xe2\x80\xb4","\xe2\x80\xb5","\xe2\x80\xb6","\xe2\x80\xb7","\xe2\x9d\x9b",
			"\xe2\x9d\x9c","\xe2\x9d\x9d","\xe2\x9d\x9e",);
		static $replacetable = null;
		if ($replacetable === null)
			foreach ($wschars as $wschar)
				$replacetable[$wschar] = ' ';
			
		$str = strtr(strtr($this->string, $replacetable), 
			" \n\r\t!\"&'()*+,-./0123456789:;<=>?[\]`{|}", '                                       ');
		return preg_split('/ ++/', $str);
	}
	
	// private
	function chr($n)
	// returns a character from the unicode integer value $n.  Unlike PHP's built
	// in chr(), this can generate utf-8 characters of up to 4 bytes
	// this is very slow, use for single characters only
	{
		$n = (int)$n;
		if ($n < 128) return chr($n);
		$out = '';
		$seg = 0;
		while ($n > (0xFF >> ($seg + 2)))
		{
			$out = chr(($n & 0x3F) | 0x80) . $out;
			$n >>= 6;
			$seg++;
		}
		return chr(((0xFF >> (7 - $seg)) << (7 - $seg)) | $n) . $out;
	}
	
	// private
	function ord($char)
	// returns the unicode integer value of $char.  Unlike PHP's built in ord(),
	// this works with utf-8 characters of up to 4 bytes
	// this is extremely slow, use for single characters only
	{
		if (strlen($char) == 1 and ord($char) < 0x80) return ord($char);
		$max = strlen($char);
		$val = 0;
		$bits = 0;
		for ($i = 0; $i < $max and $n = ord($char[$i]); $i++)
		{
			$b = 1;
			while ($n & 0x80)
			{
				$n <<= 1;
				$n &= 0xFF;
				$b++;
			}
			$n >>= ($b - 1);
			$val <<= (8 - $b);
			$val |= $n;
		}
		return $val;
	}
	
	// private
	function convertfromascii($replace = '')
	// this assumes ascii and removes any non-ascii bytes
	// like convertfrom1252, slowish
	// this is a private function only.  It does NOT convert any characters from
	// one representation to another, just removes any bytes not valid in ASCII.
	{
		$str = strtr($this->string, 
			// this table is simply any byte value not valid in ascii except \x00
			"\x1\x2\x3\x4\x5\x6\x7\x8\xb\xc\xe\xf\x10\x11\x12\x13\x14\x15\x16".
			"\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f".			
			"\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f".
			"\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f".
			"\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xab\xac\xad\xae\xaf".
			"\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf".
			"\xc0\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf".
			"\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf".
			"\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef".
			"\xf0\xf1\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff",
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0");
		return str_replace("\x0", $replace, $str);
	}
	
	// private
	function convertfrom1252($replace = '')
	// this is very fast if the string contains nothing over 0x7f
	// otherwise, at its slowest it's about 500KB per second (fairly slow)
	{
		//add utf8 encoding
		$str = preg_match('/^[\x00-\x7f]++$/', $this->string) 
			? $this->string : utf8_encode($this->string);
		
		$str = strtr($str, 
			// any byte value not valid in ascii, iso-8859-1 or cp-1252
			"\x1\x2\x3\x4\x5\x6\x7\x8\xb\xc\xe\xf\x10\x11\x12\x13\x14\x15\x16".
			"\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f\x81\x8d\x8f\x90\x9d", 
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0".
			"\0\0\0\0\0\0\0\0\0\0\0\0\0\0");
		$str = str_replace("\0", $replace, $str);
				
		// optimisation, if there is no /xc2 char then don't filter any more
		if (strtr($str, "\xc2", ' ') == $str) return $str;
		
		static $codemap = array(
			// translation of utf-encoded byte values from cp-1252
			// to utf-encoded unicode values
			"\xc2\x80"=>"\xe2\x82\xac",	"\xc2\x82"=>"\xe2\x80\x9a",
			"\xc2\x83"=>"\xc6\x92",		"\xc2\x84"=>"\xe2\x80\x9e",
			"\xc2\x85"=>"\xe2\x80\xa6",	"\xc2\x86"=>"\xe2\x80\xa0",
			"\xc2\x87"=>"\xe2\x80\xa1",	"\xc2\x88"=>"\xcb\x86",
			"\xc2\x89"=>"\xe2\x80\xb0",	"\xc2\x8a"=>"\xc5\xa0",
			"\xc2\x8b"=>"\xe2\x80\xb9",	"\xc2\x8c"=>"\xc5\x92",
			"\xc2\x8e"=>"\xc5\xbd",		"\xc2\x91"=>"\xe2\x80\x98",
			"\xc2\x92"=>"\xe2\x80\x99",	"\xc2\x93"=>"\xe2\x80\x9c",
			"\xc2\x94"=>"\xe2\x80\x9d",	"\xc2\x95"=>"\xe2\x80\xa2",
			"\xc2\x96"=>"\xe2\x80\x93",	"\xc2\x97"=>"\xe2\x80\x94",
			"\xc2\x98"=>"\xcb\x9c",		"\xc2\x99"=>"\xe2\x84\xa2",
			"\xc2\x9a"=>"\xc5\xa1",		"\xc2\x9b"=>"\xe2\x80\xba",
			"\xc2\x9c"=>"\xc5\x93",		"\xc2\x9e"=>"\xc5\xbe",
			"\xc2\x9f"=>"\xc5\xb8",
			);
		return strtr($str, $codemap);	
	}
}

/*
for ($i = 0x300; $i <= 0x36f; $i++)
{
	$char = utf8_string::chr($i);
	echo '"\\x' . bin2hex($char[0]) . '\\x' . bin2hex($char[1]) . '"=>\'\',';
}
*/

/*
$random = '';
for ($i = 0; $i < 100; $i++)
	$random .= utf8_string::chr(mt_rand(40, 60) + (300 * mt_rand(0,1)) + (6000 * mt_rand(0,1)));
$randominvalid = '';
for ($i = 0; $i < 100; $i++)
	$randominvalid .= chr(mt_rand(0,255));
	
$text = '';
for ($i = 0; $i < 4200; $i++)
	$text .= "The quick brown fox $random";
	
$str = new utf8_string($text);

echo strlen($text);
echo "ready\n";

list($sec, $usec) = explode(' ', microtime());

for ($i = 0; $i < 10; $i++)
	$result = $str->words(true);
	
list($xsec, $xusec) = explode(' ', microtime());
$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo "\n$elapsed\n";

echo substr(var_export($result, true), 0, 1000);
*/

?>