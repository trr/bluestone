<?php

/*
	texthtml - for simple conversions between text and html
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

// and logging replacements, so we can diff/merge

define('TEXTHTML_SPACE', 1);
define('TEXTHTML_LINE', 2);
define('TEXTHTML_PARA', 3);

class texthtml
{
	private
		$editlist;

	public function geteditlist()
	// returns the editlist generated by the last call to htmltosimple
	{
		return $this->editlist;
	}
	
	public function htmltosimple($html)
	// reduces the html to a very simple representation consisting of
	// only <br> and <p></p>, which can be represented in plain text
	// keeps a record of edits required to do so, so we can do a diff style merge
	{
		static $breaks = array(
			null => '', TEXTHTML_SPACE => ' ', TEXTHTML_LINE => '<br>',
			TEXTHTML_PARA => '</p><p>');
		
		$result = preg_match_all('/
			\s*+<\/?+(\w++)[^>"]*(?:"[^"]*"[^>"]*+)*+>\s*
			|\x20\s+|\t\s*|\n\s*|\r\s*|\r\s*
			|<!(?:--.*?--|[^-][^>]*)>\s*
			/xsS',
			$html, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
		
		$apos = 0; $startbreakpos = 0; $hasoutput = false; $break = null;
		$editlist = array(); $output = '<p>';
		foreach ($matches as $match)
		{
			$newpos = $match[0][1];
			if ($newpos-$apos)
			{
				$segment = substr($html, $apos, $newpos-$apos);
				$breaksymbol = $hasoutput ? $breaks[$break] : '';
				if ($apos>$startbreakpos||$breaksymbol!='')
				{
					$editlist[] = array($startbreakpos, $apos-$startbreakpos, 
						strlen($output), strlen($breaksymbol));
					$output .= $breaksymbol;
				}
				$startbreakpos = $newpos;
				$output .= $segment;
				$break = null;
				$hasoutput = true;
			}
			$tag = isset($match[1]) ? strtolower($match[1][0]) : null;
			$replace = '';
			
			if ($tag===null) $break = max($break, TEXTHTML_SPACE);
			elseif ($tag==='p'||$tag==='ul'||$tag==='ol'||$tag==='li'
				||$tag==='blockquote'||$tag=='h1'||$tag==='h2'||$tag==='h3'
				||$tag==='h4'||$tag==='h5'||$tag==='h6') 
				$break = TEXTHTML_PARA;
			elseif ($tag==='br'||$tag==='tr') $break = max($break, TEXTHTML_LINE);
			else $break = max($break, TEXTHTML_SPACE);
			
			$apos = $newpos+strlen($match[0][0]);
		}
		$this->editlist = $editlist;
		return $output . substr($html, $apos) . '</p>';
	}
	
	public function simpletotext($simple)
	// converts the simple formatting from htmltosimple to plain text
	{
		// filtering most common chars before we reach html_entity_decode is
		// MUCH faster
		// TODO would strtr be even faster?
		$output = str_replace(array(
			'</p><p>', '<br>', '<p>', '</p>',
			'&amp;', '&lt;', '&quot;', '&gt;', '&#039;', '&nbsp;',
			), 
			array(
			"\n\n", "\n", '', '',
			'&', '<', '"', '>', "'", "\xc2\xa0",
			), $simple);
			
		if (strpos($output, '&')===false) return $output; // OPT - if no entities
		if (version_compare(PHP_VERSION, "5.2") >= 0)
			return html_entity_decode($output, ENT_COMPAT, 'UTF-8');
		
		// php before 5.2 couldn't convert entities to UTF-8, so let's support
		// at least this small subset
		return strtr($output, array(
			'&copy;' => "\xc2\xa9",
			'&pound;' => "\xc2\xa3", '&euro;' => "\xe2\x82\xac",	
			));
	}
	
	public function htmltotext($html)
	// for now, this just runs htmltosimple, then simpletotext
	{
		return $this->simpletotext($this->htmltosimple($html));
	}
	
	public function texttohtml($text, $allowhtml = false)
	// converts plain text to html.  If allowhtml is false, then characters like
	// '<' and '&' are treated as literal characters and not as tags or entities
	{
		if ($allowhtml)
			$output = strtr($text, array(
			"\n\n" => '</p><p>', "\r\n\r\n" => '</p><p>', "\r\r" => '</p><p>'));
		else
			$output = strtr($text, array(
			'<' => '&lt;', '&' => '&amp;',
			"\n\n" => '</p><p>', "\r\n\r\n" => '</p><p>', "\r\r" => '</p><p>'));
		$output = strtr($output, array(			
			"\r\n" => '<br>', "\n" => '<br>', "\r" => '<br>'
			));
		return $output;
	}
}

/*
set_time_limit(12);

$a = "
 <p>Three sons left home, went out on their own and prospered. Getting back together, they discussed the gifts they were able to give their elderly Mother. </p>
<p>The first said, &quot;I built a big house for our Mother.&quot; The second said, &quot;I sent her a Mercedes with a driver.&quot; The third             smiled and said, &quot;I've got you both beat. You remember how Mom enjoyed reading the Bible?         And you know she can't see very well any more. I sent her a remarkable parrot that recites the entire Bible. It took Elders in the church 12 years to teach him. He's one of a kind. Mama just has to name the chapter and verse, and the parrot recites it.&quot; </p>

<p>Soon thereafter, Mom sent out her letters of thanks: &quot;Milton,&quot; she wrote one son, &quot;the house you built is so huge. I live in only one room, but I have to clean the whole house.&quot; </p>
<p>&quot;Gerald,&quot; she wrote to another, &quot;I am too old to travel any more. My eyesight isn't what it used to be. I stay most of the time at home, so I rarely use the Mercedes. And the driver is so rude!&quot; </p>
<p>&quot;Dearest Donald,&quot; she wrote to her third son, &quot;you have the good sense to know what your Mother likes. The chicken was delicious!&quot;</p>			
			
";


echo strlen($a) . "ready\n";

//file_put_contents('outputa.txt', $a);
//file_put_contents('outputb.txt', $b);

list($sec, $usec) = explode(' ', microtime());

set_time_limit(12);

for ($i = 0; $i < 1; $i++)
{
	$texthtml = new texthtml();
	$result = $texthtml->htmltosimple($a);
	$result = $texthtml->simpletotext($result);
}

list($xsec, $xusec) = explode(' ', microtime());

$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo count($result);
echo "\n$elapsed\n";
echo substr(var_export($result, true), 0, 2600);
exit;
*/

?>
