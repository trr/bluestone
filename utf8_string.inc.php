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
	// well as C1 codes 127-159, will be denied - recommend false for html/xml
	{
		return $this->string=='' || preg_match($allowcontrolcodes
			? '/^[\x00-\x{d7ff}\x{e000}-\x{10ffff}]++$/u'
			: '/^[\x20-\x7e\x0a\x09\x0d\x{a0}-\x{d7ff}\x{e000}-\x{10ffff}]++$/u',
			$this->string);
	}
	
	public function filter($replace = '', $convert = true, $allowcontrolcodes = false)
	// filters the string.  if it is valid utf-8, then it is returned unmodified.  If it
	// would be valid but contains control codes and allowcontrolcodes are false,
	// they are stripped out.  Otherwise, it is assumed to be either ascii (if convert
	// is false) or iso-8859-1/cp-1252 (otherwise) and converted thusly to utf-8
	{
		// make sure this returns very fast if it is valid already
		if ($this->string=='' || preg_match($allowcontrolcodes
			? '/^[\x00-\x{d7ff}\x{e000}-\x{10ffff}]++$/u'
			: '/^[\x20-\x7e\x0a\x09\x0d\x{a0}-\x{d7ff}\x{e000}-\x{10ffff}]++$/u',
			$this->string)) return $this->string;
		
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
	
	/*
	private function chr($n)
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
	
	private function ord($char)
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
	*/
	
	private function convertfromascii($replace = '')
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
	
	private function convertfrom1252($replace = '')
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
		if (strcspn($str, "\xc2") == strlen($str)) return $str;
		//if (strtr($str, "\xc2", ' ') == $str) return $str;
		
		static $codemap = array(
			// translation of utf-encoded byte values from cp-1252
			// to utf-encoded unicode values
			"\xc2\x80"=>"\xe2\x82\xac","\xc2\x82"=>"\xe2\x80\x9a",
			"\xc2\x83"=>"\xc6\x92","\xc2\x84"=>"\xe2\x80\x9e",
			"\xc2\x85"=>"\xe2\x80\xa6","\xc2\x86"=>"\xe2\x80\xa0",
			"\xc2\x87"=>"\xe2\x80\xa1","\xc2\x88"=>"\xcb\x86",
			"\xc2\x89"=>"\xe2\x80\xb0","\xc2\x8a"=>"\xc5\xa0",
			"\xc2\x8b"=>"\xe2\x80\xb9","\xc2\x8c"=>"\xc5\x92",
			"\xc2\x8e"=>"\xc5\xbd","\xc2\x91"=>"\xe2\x80\x98",
			"\xc2\x92"=>"\xe2\x80\x99","\xc2\x93"=>"\xe2\x80\x9c",
			"\xc2\x94"=>"\xe2\x80\x9d","\xc2\x95"=>"\xe2\x80\xa2",
			"\xc2\x96"=>"\xe2\x80\x93","\xc2\x97"=>"\xe2\x80\x94",
			"\xc2\x98"=>"\xcb\x9c","\xc2\x99"=>"\xe2\x84\xa2",
			"\xc2\x9a"=>"\xc5\xa1","\xc2\x9b"=>"\xe2\x80\xba",
			"\xc2\x9c"=>"\xc5\x93","\xc2\x9e"=>"\xc5\xbe",
			"\xc2\x9f"=>"\xc5\xb8",
			);
		return strtr($str, $codemap);	
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
