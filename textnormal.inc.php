<?php

/*
	textnormal - class for normalisation, word separation, stemming of UTF-8 text
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

// strings can be normalised in various ways, or chopped into distinct words
// ready for sorting or indexing.

if (!defined('BLUESTONE_DIR')) define('BLUESTONE_DIR', dirname(__FILE__) . '/');
require_once(BLUESTONE_DIR . 'utf8_string.inc.php');

class textnormal
{
	private
		$utf8_string,
		$webchars;

	function __construct($string = NULL)
	// can accept a plain string or utf8_string object
	{
		if (is_string($string))
			$this->utf8_string = new utf8_string($string);
			
		else $this->utf8_string = $string;
		
		$this->webchars = false;
	}
	
	public function setwebchars($webchars = false)
	// if set to true, commas, periods, & @ will be treated as word separating chars
	// so "word.word" and "word,word" will each be considered two words, not one
	// and symbols $ + < = > | ~ ^ ` will be treated as separate to their adjoining words
	// this setting is intended to better extract words where things like URLs and	email
	// addresses may exist, but it may have unwanted effects in collation of proper titles
	{
		$this->webchars = $webchars ? true : false;
	}	

	public function normal($letters = true, $space = true, $dashes = true, $punc = true)
	// normalises the string - different types of normalisation are specified by
	// the arguments.  Note that doing all 5 at once is significantly faster than all
	// one after the other
	// $letters - convert letters to lowercase and most variants of letters to their base
	//   letter, removing accents
	// $space - converts all occurrences of one or more whitespace chars to a single space
	// $dashes - converts dashes and slashes to spaces (and normalises with spaces if $space true)
	// $punc - strips most punctuation
	// note that symbols are currently preserved
	{
		$replacetable = array();	
		
		$str = $letters ? $this->utf8_string->tolower() : $this->utf8_string->getstring();
		
		// opt
		$isascii = strtr($str, 
			"\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf".
			"\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf".
			"\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef".
			"\xf0\xf1\xf2\xf3\xf4",
			"                                                   ") == $str;
			
		if ($this->webchars) $replacetable += array(
			','=>' ','.'=>' ','&'=>' ','@'=>' ',
			'$'=>' $ ','+'=>' + ','<'=>' < ','='=>' = ','>'=>' > ','|'=>' | ','~'=>' ~ ','^'=>' ^ ',
			'`'=>' ` ',
			);
			
		if ($letters && !$isascii) $replacetable += textnormal::$normalletterstable;
		
		if ($space) $replacetable += textnormal::$spacecharsascii;
		if ($space && !$isascii) $replacetable += textnormal::$spacecharstable;
		
		// dashes must come after space
		if ($dashes) $replacetable += textnormal::$dashcharsascii;
		if ($dashes&& !$isascii) $replacetable += textnormal::$dashcharstable;
		
		if ($punc) $replacetable += textnormal::$punccharsascii;
		if ($punc&& !$isascii) $replacetable += textnormal::$punccharstable;
				
		$str = strtr($str, $replacetable);
		
		// a "." should be stripped, unless it looks like a
		// decimal point, in which case it should be left
		if ($punc) $str = preg_replace('/\.(?![0-9]++(?: |$))|(?<![0-9])\./S', '', $str);
		
		if ($space) return preg_replace('/  +/', ' ', trim($str));
		return $str;
	}
	
	public function words($normaliseletters = false)
	// split the string into words, stripping out punctuation (but leaving symbols)
	// does not case fold or normalise characters - should probably do that before
	// splitting into words if you need it.  
	// $commaorperiodsplits determines whether "word.word" or "word,word"
	// without a space should be treated as two words.  Note that slashes or dashes
	// always split.
	// returns array of words. pretty fast now, 10MB/s or so.
	{
		$str = $this->normal($normaliseletters);
		
		return explode(' ', $str);
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
	
	private static $normalletterstable = array( 
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
		
	private static $spacecharsascii = array(
		"\x09"=>' ',"\x0a"=>' ',"\x0b"=>' ',"\x0c"=>' ',"\x0d"=>' ',/*"\x20"=>' ',*/
		);
	private static $spacecharstable = array(     
		// space
		"\xc2\xa0"=>' ',"\xe2\x80\x80"=>' ',"\xe2\x80\x81"=>' ',
		"\xe2\x80\x82"=>' ',"\xe2\x80\x83"=>' ',"\xe2\x80\x84"=>' ',"\xe2\x80\x85"=>' ',
		"\xe2\x80\x86"=>' ',"\xe2\x80\x87"=>' ',"\xe2\x80\x88"=>' ',"\xe2\x80\x89"=>' ',
		"\xe2\x80\x8a"=>' ',"\xe2\x80\xaf"=>' ',"\xe2\x81\x9f"=>' ',
		// line separator
		"\xe2\x80\xa8"=>' ',
		// paragraph separator
		"\xe2\x80\xa9"=>' ',			
		// formatting (soft hyphens=>' ', zero width spaces=>' ', etc)
		"\xc2\xad"=>' ',"\xd8\x80"=>' ',"\xd8\x81"=>' ',"\xd8\x82"=>' ',"\xd8\x83"=>' ',
		"\xdb\x9d"=>' ',"\xdc\x8f"=>' ',"\xe1\x9e\xb4"=>' ',"\xe1\x9e\xb5"=>' ',
		"\xe2\x80\x8b"=>' ',"\xe2\x80\x8c"=>' ',"\xe2\x80\x8d"=>' ',"\xe2\x80\x8e"=>' ',
		"\xe2\x80\x8f"=>' ',"\xe2\x80\xaa"=>' ',"\xe2\x80\xab"=>' ',"\xe2\x80\xac"=>' ',
		"\xe2\x80\xad"=>' ',"\xe2\x80\xae"=>' ',"\xe2\x81\xa0"=>' ',"\xe2\x81\xa1"=>' ',
		"\xe2\x81\xa2"=>' ',"\xe2\x81\xa3"=>' ',"\xe2\x81\xa4"=>' ',"\xe2\x81\xaa"=>' ',
		"\xe2\x81\xab"=>' ',"\xe2\x81\xac"=>' ',"\xe2\x81\xad"=>' ',"\xe2\x81\xae"=>' ',
		"\xe2\x81\xaf"=>' ',"\xef\xbb\xbf"=>' ',
		);
	
	private static $dashcharsascii = array(
		"-"=>' ',"\\"=>' ',"/"=>' ',
		);
	private static $dashcharstable = array(
		"\xd6\x8a"=>' ',"\xd6\xbe"=>' ',"\xe2\x80\x90"=>' ',"\xe2\x80\x91"=>' ',
		"\xe2\x80\x92"=>' ',"\xe2\x80\x93"=>' ',"\xe2\x80\x94"=>' ',"\xe2\x80\x95"=>' ',
		"\xe2\xb8\x97"=>' ',"\xe2\xb8\x9a"=>' ',
		);
	
	private static $punccharsascii = array(
		// connector
		"_"=>'',
		// closing opening punc
		")"=>'',"]"=>'',"}"=>'',"("=>'',"["=>'',"\x7b"=>'',
		// dashes
		"-"=>'',
		// other
		"!"=>'',"\x22"=>'',"#"=>'',"%"=>'',"&"=>'',"'"=>'',"*"=>'',","=>'',/*"."=>'',*/
		"/"=>'',":"=>'',";"=>'',"?"=>'',"@"=>'',"\x5c"=>'',
		);
	private static $punccharstable = array(
		// close quotes
		"\xc2\xbb"=>'',"\xe2\x80\x99"=>'',"\xe2\x80\x9d"=>'',"\xe2\x80\xba"=>'',
		// open quotes
		"\xc2\xab"=>'',"\xe2\x80\x98"=>'',"\xe2\x80\x9b"=>'',"\xe2\x80\x9c"=>'',
		"\xe2\x80\x9f"=>'',"\xe2\x80\xb9"=>'',
		// connectors
		"\xe2\x80\xbf"=>'',"\xe2\x81\x80"=>'',"\xe2\x81\x94"=>'',
		// closing punctuation
		"\xe2\x81\x86"=>'',"\xe2\x81\xbe"=>'',"\xe2\x82\x8e"=>'',
		"\xe2\x8c\xaa"=>'',"\xe2\x9d\xa9"=>'',"\xe2\x9d\xab"=>'',"\xe2\x9d\xad"=>'',
		"\xe2\x9d\xaf"=>'',"\xe2\x9d\xb1"=>'',"\xe2\x9d\xb3"=>'',"\xe2\x9d\xb5"=>'',
		// opening punctuation			
		"\xe2\x80\x9a"=>'',"\xe2\x80\x9e"=>'',
		"\xe2\x81\x85"=>'',"\xe2\x81\xbd"=>'',"\xe2\x82\x8d"=>'',"\xe2\x8c\xa9"=>'',
		"\xe2\x9d\xa8"=>'',"\xe2\x9d\xaa"=>'',"\xe2\x9d\xac"=>'',"\xe2\x9d\xae"=>'',
		"\xe2\x9d\xb0"=>'',"\xe2\x9d\xb2"=>'',"\xe2\x9d\xb4"=>'',
		// dashes (the ones in $dashchars take precedence over these)
		"\xd6\x8a"=>'',"\xd6\xbe"=>'',"\xe2\x80\x90"=>'',"\xe2\x80\x91"=>'',
		"\xe2\x80\x92"=>'',"\xe2\x80\x93"=>'',"\xe2\x80\x94"=>'',"\xe2\x80\x95"=>'',
		"\xe2\xb8\x97"=>'',"\xe2\xb8\x9a"=>'',
		// other punctuation
		"\xc2\xa1"=>'',
		"\xc2\xb7"=>'',"\xc2\xbf"=>'',"\xcd\xbe"=>'',"\xce\x87"=>'',"\xd5\x9a"=>'',
		"\xd5\x9b"=>'',"\xd5\x9c"=>'',"\xd5\x9d"=>'',"\xd5\x9e"=>'',"\xd5\x9f"=>'',
		"\xd6\x89"=>'',"\xd7\x80"=>'',"\xd7\x83"=>'',"\xd7\x86"=>'',"\xd7\xb3"=>'',
		"\xd7\xb4"=>'',"\xd8\x89"=>'',"\xd8\x8a"=>'',"\xd8\x8c"=>'',"\xd8\x8d"=>'',
		"\xd8\x9b"=>'',"\xd8\x9e"=>'',"\xd8\x9f"=>'',"\xd9\xaa"=>'',"\xd9\xab"=>'',
		"\xd9\xac"=>'',"\xd9\xad"=>'',"\xdb\x94"=>'',"\xdc\x80"=>'',"\xdc\x81"=>'',
		"\xdc\x82"=>'',"\xdc\x83"=>'',"\xdc\x84"=>'',"\xdc\x85"=>'',"\xdc\x86"=>'',
		"\xdc\x87"=>'',"\xdc\x88"=>'',"\xdc\x89"=>'',"\xdc\x8a"=>'',"\xdc\x8b"=>'',
		"\xdc\x8c"=>'',"\xdc\x8d"=>'',"\xdf\xb7"=>'',"\xdf\xb8"=>'',"\xdf\xb9"=>'',
		"\xe0\xa5\xa4"=>'',"\xe0\xa5\xa5"=>'',"\xe0\xa5\xb0"=>'',"\xe0\xb7\xb4"=>'',
		"\xe0\xb9\x8f"=>'',"\xe0\xb9\x9a"=>'',"\xe0\xb9\x9b"=>'',"\xe2\x80\x96"=>'',
		"\xe2\x80\x97"=>'',"\xe2\x80\xa0"=>'',"\xe2\x80\xa1"=>'',"\xe2\x80\xa2"=>'',
		"\xe2\x80\xa3"=>'',"\xe2\x80\xa4"=>'',"\xe2\x80\xa5"=>'',"\xe2\x80\xa6"=>'',
		"\xe2\x80\xa7"=>'',"\xe2\x80\xb0"=>'',"\xe2\x80\xb1"=>'',"\xe2\x80\xb2"=>'',
		"\xe2\x80\xb3"=>'',"\xe2\x80\xb4"=>'',"\xe2\x80\xb5"=>'',"\xe2\x80\xb6"=>'',
		"\xe2\x80\xb7"=>'',"\xe2\x80\xb8"=>'',"\xe2\x80\xbb"=>'',"\xe2\x80\xbc"=>'',
		"\xe2\x80\xbd"=>'',"\xe2\x80\xbe"=>'',"\xe2\x81\x81"=>'',"\xe2\x81\x82"=>'',
		"\xe2\x81\x83"=>'',"\xe2\x81\x87"=>'',"\xe2\x81\x88"=>'',"\xe2\x81\x89"=>'',
		"\xe2\x81\x8a"=>'',"\xe2\x81\x8b"=>'',"\xe2\x81\x8c"=>'',"\xe2\x81\x8d"=>'',
		"\xe2\x81\x8e"=>'',"\xe2\x81\x8f"=>'',"\xe2\x81\x90"=>'',"\xe2\x81\x91"=>'',
		"\xe2\x81\x93"=>'',"\xe2\x81\x95"=>'',"\xe2\x81\x96"=>'',"\xe2\x81\x97"=>'',
		"\xe2\x81\x98"=>'',"\xe2\x81\x99"=>'',"\xe2\x81\x9a"=>'',"\xe2\x81\x9b"=>'',
		"\xe2\x81\x9c"=>'',"\xe2\x81\x9d"=>'',"\xe2\x81\x9e"=>'',
		);
		
	/*
	static $symbolcharsascii = array(
		"\x24",
		"+","<","=",">","|","~",
		);
	static $symbolcharstable = array(
		// currency
		"\xc2\xa2","\xc2\xa3","\xc2\xa4","\xc2\xa5","\xe2\x82\xa0","\xe2\x82\xa1",
		"\xe2\x82\xa2","\xe2\x82\xa3","\xe2\x82\xa4","\xe2\x82\xa5","\xe2\x82\xa6",
		"\xe2\x82\xa7","\xe2\x82\xa8","\xe2\x82\xa9","\xe2\x82\xaa","\xe2\x82\xab",
		"\xe2\x82\xac","\xe2\x82\xad","\xe2\x82\xae","\xe2\x82\xaf","\xe2\x82\xb0",
		"\xe2\x82\xb1","\xe2\x82\xb2","\xe2\x82\xb3","\xe2\x82\xb4","\xe2\x82\xb5",
		// mathematical
		"\xc2\xac","\xc2\xb1","\xc3\x97","\xc3\xb7","\xe2\x81\x84","\xe2\x81\x92",
		"\xe2\x86\x90","\xe2\x86\x91","\xe2\x86\x92","\xe2\x86\x93","\xe2\x88\x9a",
		"\xe2\x88\x9e","\xe2\x88\xa0","\xe2\x88\xa9","\xe2\x88\xaa","\xe2\x88\xab",
		// other symbols
		"\xc2\xa6","\xc2\xa7","\xc2\xa9","\xc2\xae","\xc2\xb0","\xc2\xb6",
		"\xe2\x84\x80","\xe2\x84\x81","\xe2\x84\x83","\xe2\x84\x84","\xe2\x84\x85",
		"\xe2\x84\x86","\xe2\x84\x88","\xe2\x84\x89","\xe2\x84\x94","\xe2\x84\x96",
		"\xe2\x84\x97","\xe2\x84\x98","\xe2\x84\x9e","\xe2\x84\x9f","\xe2\x84\xa0",
		"\xe2\x84\xa1","\xe2\x84\xa2","\xe2\x84\xa3","\xe2\x84\xa5","\xe2\x84\xa7",
		"\xe2\x84\xa9","\xe2\x84\xae","\xe2\x84\xba","\xe2\x84\xbb","\xe2\x85\x8a",
		// stylised numbers like 1/2, 3/4 (treated like symbols for now)
		"\xc2\xb2","\xc2\xb3","\xc2\xb9","\xc2\xbc","\xc2\xbd","\xc2\xbe",
		// roman numerals (treated like symbols for now)
		"\xe2\x85\xa0","\xe2\x85\xa1","\xe2\x85\xa2","\xe2\x85\xa3","\xe2\x85\xa4",
		"\xe2\x85\xa5","\xe2\x85\xa6","\xe2\x85\xa7","\xe2\x85\xa8","\xe2\x85\xa9",
		"\xe2\x85\xaa","\xe2\x85\xab","\xe2\x85\xac","\xe2\x85\xad","\xe2\x85\xae",
		"\xe2\x85\xaf","\xe2\x85\xb0","\xe2\x85\xb1","\xe2\x85\xb2","\xe2\x85\xb3",
		"\xe2\x85\xb4","\xe2\x85\xb5","\xe2\x85\xb6","\xe2\x85\xb7","\xe2\x85\xb8",
		"\xe2\x85\xb9","\xe2\x85\xba","\xe2\x85\xbb","\xe2\x85\xbc","\xe2\x85\xbd",
		"\xe2\x85\xbe","\xe2\x85\xbf","\xe2\x86\x80","\xe2\x86\x81","\xe2\x86\x82",
		);
	*/
}

/*
$chars = preg_split('/\s+/', "
	");
foreach ($chars as $char) if ($char != '')
{
	$char = utf8_string::chr(hexdec($char));
	if (preg_match('/[\x00-\x20\x7f-\xff]/', $char) || in_array($char, array('"', '\\', '$', '{')))
	{
		$hex = bin2hex($char);
		for ($char = '', $i = 0; $i <= strlen($hex)-2; $i += 2)
		{
			$char .= '\\x' . substr($hex, $i, 2);
		}
	}
	echo '"' . $char . '",';
}
exit;
*/

/*
mt_srand(12345);
$random = '';
for ($i = 0; $i < 100; $i++)
	$random .= utf8_string::chr(mt_rand(40, 60) + (300 * mt_rand(0,1)) + (6000 * mt_rand(0,1)));
$randominvalid = '';
for ($i = 0; $i < 100; $i++)
	$randominvalid .= chr(mt_rand(0,255));
	
$text = '';
for ($i = 0; $i < 4200; $i++)
	$text .= "The quick brown fox www.abc.com 65.5 $random";
	
$str = new utf8_string($text);
$str = new textnormal($str->filter());
$str->setwebchars(false);

echo strlen($text);
echo "ready\n";

list($sec, $usec) = explode(' ', microtime());

for ($i = 0; $i < 10; $i++)
	$result = $str->naturalsortindex();
	
list($xsec, $xusec) = explode(' ', microtime());
$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo "\n$elapsed\n";

echo substr(var_export($result, true), 0, 1000);
if (is_array($result)) echo "\nCount " . count($result) . "\n";
*/

?>
