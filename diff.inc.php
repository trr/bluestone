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

// utf8 mode - on those functions that support it - avoids breaking utf8 characters
// in half in an edit.  all offsets and lengths are still counted in bytes, however.

// a file-based diff uses a sliding buffer which may decrease accuracy for files greater
// than the buffer size in some circumstances

//define("DIFF_DELETE", "DEL:");
//define("DIFF_INSERT", "INS:");
//define("DIFF_REPLACE", "REP:");
//define("DIFF_SEGMENTLEN", 2097152);

class diff
{
	// static
	function strgetsamelen($a, $b, $aoff = 0, $boff = 0, $utf8 = false)
	// string comparison - returns length in bytes from the start (or given offsets)
	// to the first non-matching byte.  utf8 mode avoids breaking utf8 chars
	{		
		if (!$aoff && !$boff && $a===$b) return strlen($a);
		$max = min(strlen($a) - $aoff, strlen($b) - $boff);
		$cmplen = 200;
		for ($i = 0; $i < $max && substr($a,$aoff+$i,$cmplen)===substr($b,$boff+$i,$cmplen); $i+=$cmplen) ;
		if ($i >= $max) return $max;
		$xor = substr($a,$aoff+$i,$cmplen-1) ^ substr($b,$boff+$i,$cmplen-1);
		$len = $i + strspn($xor, "\0");
		if ($utf8&&$len<$max) while (($a[$aoff+$len]&"\xc0")==="\x80") $len--;
		return $len;
	}
	
	// static
	function strgetrevsamelen($a, $b)
	// like strgetsamelen BUT IN REVERSE - works from the end of the string backwards.
	// no offsets or utf8 mode implemented for this one
	{		
		if ($a===$b) return strlen($a);
		$amax = strlen($a); $bmax = strlen($b); $max = min($amax,$bmax);
		$cmp = min(200,$max); // substr doesn't tolerate start pos before string start
		for ($i = 0; $i < $max && substr($a,$amax-$i-$cmp,$cmp)
			===substr($b,$bmax-$i-$cmp,$cmp);$cmp=min($cmp,$max-$i)) $i+=$cmp;
		if ($i >= $max) return $max;
		$xor = substr($a,max(0,$amax-$i-$cmp+1),$cmp-1)^substr($b,max(0,$bmax-$i-$cmp+1),$cmp-1);
		return $i + strspn(strrev($xor), "\0");
	}
	
	// static
	function strgetdifflen($a, $b, $aoff = 0, $boff = 0, $utf8 = false, $coarse = false)
	// substring matching - returns the length in bytes of the initial segments
	// of the strings (from offsets) which contain no significant matching substrings.
	// utf8 mode avoids breaking utf8 chars
	// coarse mode misses more matches and increases speed
	{
		$amax = strlen($a) - $aoff;
		$bmax = strlen($b) - $boff;
		$max = max($amax, $bmax);
		$len = strlen($a) + strlen($b);
		$maxmatchsize = ceil(max(2, sqrt($len)/4, $len/64));
		$matchsize = $coarse ? min(80, $maxmatchsize>>1) : min(7, $maxmatchsize);
		$cmplen = $coarse ? 60 : min(4, $matchsize);
		$pos1 = $pos2 = false;
		for ($i = $matchsize; $i < $max;)
		{			
			if ($i < $bmax-$cmplen)
				$pos1 = strpos(substr($a, $aoff, $i+$cmplen), substr($b, $boff+$i, $cmplen));
			if ($i < $amax-$cmplen)
				$pos2 = strpos(substr($b, $boff, (($pos1 === false) ? $i : $pos1)+$cmplen-1),
					substr($a, $aoff+$i, $cmplen));
			
			if ($pos2 !== false)
				return diff::backtrack($a, $b, $i, $pos2, $aoff, $boff, $matchsize, $cmplen, $utf8);
			elseif ($pos1 !== false)
			{
				list($blen,$alen) 
					= diff::backtrack($b, $a, $i, $pos1, $boff, $aoff, $matchsize, $cmplen, $utf8);
				return array($alen, $blen);
			}			
			
			$i += $matchsize;
			$matchsize = min($maxmatchsize, ceil($matchsize*1.25));
			$cmplen = min($matchsize, $coarse ? 120 : 12);
		}
		return array($amax, $bmax);
	}
	
	//static private
	function backtrack($a, $b, $alen, $blen, $aoff, $boff, $matchsize, $cmplen = 12, $utf8 = false)
	{
		// shift AOFF back if there are earlier matches only a few chars ago
		if (($pos = strpos(substr($a, $aoff+$alen+1-$matchsize, $matchsize+$cmplen-2),
			substr($b, $boff+$blen, $cmplen))) !== false)
			$alen += ($pos+1-$matchsize);
			
		// shift both back to start of the matching section
		$backtrack = min($matchsize,$alen,$blen);
		$rev = diff::strgetrevsamelen(substr($a, $aoff+$alen-$backtrack, $backtrack),
			substr($b, $boff+$blen-$backtrack, $backtrack));
		$alen -= $rev; $blen -= $rev;
		
		// avoid breaking up utf8 chars
		if ($utf8)
			for ($amax = strlen($a), $bmax = strlen($b);
				$alen+$aoff<$amax&&$blen+$boff<$bmax&&($a[$alen+$aoff]&"\xC0")==="\x80";)
			{ $alen++; $blen++; }
		
		return array($alen, $blen);
	}
	
	// static private
	function strgetdifflen_segment(
		$a1, $b1, $aoff, $boff, $aseg, $bseg, $asegpos, $bsegpos, $coarse)
	// a version of strgetdifflen that can be used segment-by-segment on very large files
	// for internal use
	{
		$amax = strlen($aseg);
		$bmax = strlen($bseg);
		$max = max($amax, $bmax);
		$matchsize = ceil(($amax+$bmax)/16);
		$cmplen = $coarse ? 120 : 12;
		$pos1 = $pos2 = false;		
		for ($i = 0; $i < $max;)
		{			
			// checking back near start - for inserts/deletes
			if ($i < $bmax-$cmplen)
				$pos1 = strpos(substr($a1, $aoff), substr($bseg, $i, $cmplen));
			if ($i < $amax-$cmplen)
				$pos2 = strpos(substr($b1, $boff, ($pos1===false?PHP_INT_MAX:($pos1+$cmplen-1))),
					substr($aseg, $i, $cmplen));
			
			if ($pos2 !== false)
			{
				list($apos,$bpos) = diff::backtrack(
					$aseg, $b1, $i, $pos2, 0, $boff, $matchsize, $cmplen);
				return array($asegpos+$apos-$aoff, $bpos);
			}
			elseif ($pos1 !== false)
			{
				list($bpos,$apos) = diff::backtrack(
					$bseg, $a1, $i, $pos1, 0, $aoff, $matchsize, $cmplen);
				return array($apos, $bsegpos+$bpos-$boff);
			}
			
			// checking in current segment - for replacing
			if ($i < $bmax-$cmplen)
				$pos1 = strpos(substr($aseg, 0, $i+$cmplen), substr($bseg, $i, $cmplen));
			if ($i < $amax-$cmplen)
				$pos2 = strpos(substr($bseg, 0, ($pos1===false ? $i : $pos1)+$cmplen-1),
				substr($aseg, $i, $cmplen));
			
			if ($pos2 !== false)
			{
				list($apos,$bpos)
					= diff::backtrack($aseg, $bseg, $i, $pos2, 0, 0, $matchsize, $cmplen);
				return array($asegpos+$apos-$aoff, $bsegpos+$bpos-$boff);
			}
			elseif ($pos1 !== false)
			{
				list($bpos,$apos) = diff::backtrack($bseg, $aseg, $i, $pos1, 0, 0, $matchsize, $cmplen);
				return array($asegpos+$apos-$aoff, $bsegpos+$bpos-$boff);
			}
			$i += $matchsize;
		}
		return array($amax+$asegpos-$aoff, $bmax+$bsegpos-$boff);
	}
	
	//static
	function dodiff($a, $b, $utf8 = false)
	// returns a one way editlist in the form of array(array($aoff, $alen, $boff, $blen))
	// for every edit between a and b.
	// utf8 mode avoids splitting utf-8 characters on edit edges
	{
		$edits = array();
		$aoff = 0; $boff = 0;
		for ($alen = strlen($a), $blen = strlen($b), $count = 0; $aoff<$alen || $boff<$blen;$count++)
		{
			$len = diff::strgetsamelen($a, $b, $aoff, $boff, $utf8);
			$aoff += $len;
			$boff += $len;
			
			list($amov, $bmov) = diff::strgetdifflen($a, $b, $aoff, $boff, $utf8, $count>15000);
			if ($amov!=0 || $bmov != 0) $edits[] = array($aoff, $amov, $boff, $bmov);
			
			$aoff += $amov;
			$boff += $bmov;
		}
		return $edits;
	}
	
	//static private
	function getfilechunk($file, $offset, &$segpos)
	{
		fseek($file, $offset);		$segpos = $offset;
		return fread($file, DIFF_SEGMENTLEN);
	}
	
	//static
	function dodiff_file($filenamea, $filenameb)
	{
		$alen = filesize($filenamea);		$blen = filesize($filenameb);
		$a = fopen($filenamea, 'rb');		$b = fopen($filenameb, 'rb');
		if (!$a || !$b) trigger_error('File not found', E_USER_ERROR);
		$asegpos = 0;							$bsegpos = 0;
		$seglen = DIFF_SEGMENTLEN;
		$halfseg = floor($seglen/2);
		$abuf = fread($a, $seglen);		$bbuf = fread($b, $seglen);
		
		$edits = array();
		for ($aoff = 0, $boff = 0, $count = 0; $aoff < $alen || $boff < $blen; $count++)
		{
			do
			{
				$len = diff::strgetsamelen($abuf, $bbuf, $aoff-$asegpos, $boff-$bsegpos);
				$max = min($seglen-($aoff-$asegpos), $seglen-($boff-$bsegpos));
				$aoff += $len;
				$boff += $len;
				if ($aoff > $asegpos+$halfseg) $abuf = diff::getfilechunk($a, $aoff, $asegpos);
				if ($boff > $bsegpos+$halfseg) $bbuf = diff::getfilechunk($b, $boff, $bsegpos);
			}
			while ($len && $len == $max);
			
			$coarse = $count>30000;
			list($amov, $bmov) = diff::strgetdifflen(
				$abuf, $bbuf, $aoff-$asegpos, $boff-$bsegpos, false, $coarse);
			$asegshift = $aoff; $bsegshift = $boff;
			if ($amov == $seglen-($aoff-$asegpos)) do
			{
				$aseg = diff::getfilechunk($a, $aoff+($amov-($coarse?119:11)), $asegshift);
				$bseg = diff::getfilechunk($b, $boff+($bmov-($coarse?119:11)), $bsegshift);
				list($amov,$bmov) = diff::strgetdifflen_segment(
					$abuf, $bbuf, $aoff, $boff, $aseg, $bseg, $asegshift, $bsegshift, $coarse);
			}
			while (($amov || $bmov)
				&& $amov == strlen($aseg)+$asegshift-$aoff
				&& $bmov == strlen($bseg)+$bsegshift-$boff 
				&& ($amov+$aoff < $alen || $bmov+$boff < $blen));
			
			if ($amov!=0 || $bmov != 0) 
				$edits[] = array($aoff, $amov, $boff, $bmov);
			
			$aoff += $amov;
			$boff += $bmov;
			if ($aoff > $asegpos+$halfseg) $abuf = diff::getfilechunk($a, $aoff, $asegpos);
			if ($boff > $bsegpos+$halfseg) $bbuf = diff::getfilechunk($b, $boff, $bsegpos);
		}
		fclose($a); fclose($b);
		return $edits;
	}
	
	//static
	function reverse($editlist)
	// reverse an editlist
	{
		$templist = array();
		foreach ($editlist as $edit)
			$templist[] = array($edit[2], $edit[3], $edit[0], $edit[1]);
		usort($templist, array('diff', 'sourcesort'));
		return $templist;
	}
	
	// static
	function mergeleft($ab, $ac)
	// given two editlists A->B and A->C, returns an editlist A->X where X
	// is a new document created by a 3-way merge.  The editlist returned contains
	// additional information about how to build the string X from only information
	// found in A, B and C
	// This version gives the left parameter, ie the A->B editlist, priority over the
	// A->C editlist - where an edit in one overlaps with the other, the edit from
	// A->C will be discarded and the edit from A->B used.  Edits which 'touch' but
	// do not overlap will both be retained.
	{
		$edit1 = reset($ab); $edit2 = reset($ac);
		$mergelist = array(); 
		$xpos = 0; $aprev = 0; $apos = 0;
		
		while ($edit1 || $edit2)
		{
			if (!$edit1) $process = 2;
			elseif (!$edit2) $process = 1;
			elseif ($edit1[0]+$edit1[1]>$edit2[0] && $edit2[0]+$edit2[1]>$edit1[0])
			{ // overlapping edit; discard the second one
				$edit2 = next($ac);
				continue;
			}
			elseif ($edit1[0] < $edit2[0])
				$process = 1;
			else $process = 2;
			
			$editop = $process==1 ? $edit1 : $edit2;
			$samelen = $editop[0]-$aprev;
			$apos += $samelen;
			$xpos += $samelen;
			$mergelist[] = array($apos, $editop[1], $xpos, $editop[3], $process, $editop[2]);	
			$apos += $editop[1];
			$xpos += $editop[3];
			$aprev = $editop[0]+$editop[1];
			if ($process==1) $edit1 = next($ab);
				else $edit2 = next($ac);
		}
		usort($mergelist, array('diff', 'sourcesort'));
		return $mergelist;
	}
	
	// private static
	function sourcesort($a, $b)
	{ return $a[0]==$b[0] ? $a[1]-$b[1] : $a[0]-$b[0]; }
	
	function assemblemerge($mergelist, $a, $b, $c /* , ... */)
	// returns new string formed by sources $a, $b, $c, ..., ... with merge 
	// instructions $mergelist
	{
		$sources = func_get_args(); $output = ''; $apos = 0;
		foreach ($mergelist as $edit)
		{
			$output .= substr($a, $apos, $edit[0] - $apos);
			$output .= substr($sources[$edit[4]+1], $edit[5], $edit[3]);
			$apos = $edit[0] + $edit[1];
		}		
		return $output . substr($a, $apos);
	}
}

//function uhash($d){return trim(strtr(base64_encode(hash('sha256',$d,1)),'+/=','-_ '));}
//echo uhash('\IY&FB&I$Y yg Ubdombpmknpdhnji tf YTF YFYU F@!U%F@!VVDCV^R WU  BWJHBJHbJB b  v ewhrhonruo6hwun6p8upht ohethei qeiuick browth thwi afngs ae. ai jiw ant t ot fua ck t ha tat a aleij laeji laie lial ei lai glirl aglajeglaiegah');

/*
set_time_limit(12);

$a = str_repeat("The quick brown fox jumps over the lazy dog", 1);
$b = str_repeat("The quick brown fox leaps over my weird big lazy pig", 1);
$c = str_repeat("The quick brown fox jumps under my weird little lazy dog", 1);
echo (strlen($a) + strlen($b)) . "ready\n";

//file_put_contents('outputa.txt', $a);
//file_put_contents('outputb.txt', $b);

list($sec, $usec) = explode(' ', microtime());

set_time_limit(12);
//echo strlen($b) . "ready";

function binhash($d){return hash('sha1',$d,1);}

for ($i = 0; $i < 1000; $i++)
{
	//$result = uhash(uniqid('c8PMLhAlevWdEbNf9BRjWhbxhbkTaThJo9wwCadYiys', true)
	//	.'ace'.serialize($_SERVER).mt_rand().__FILE__.time().serialize($_ENV));
	$result = randhash();
	
	//$el1 = diff::dodiff($a, $b, true);
	//$el2 = diff::dodiff($a, $c, true);
	//$result = diff::mergeleft($el1, $el2);
	//$result = diff::assemblemerge($result, $a, $b, $c);
	//$result = diff::dodiff_file('outputa.txt', 'outputb.txt');
}

list($xsec, $xusec) = explode(' ', microtime());

$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo count($result);
echo "\n$elapsed\n";
echo substr(var_export($result, true), 0, 6000);
exit;
*/

?>