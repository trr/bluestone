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

// utf8 mode - on those functions that support it - avoids breaking utf8 characters
// in half in an edit.  all offsets and lengths are still counted in bytes, however.

// a file-based diff uses a sliding buffer which may decrease accuracy for files greater
// than the buffer size in some circumstances

// this is a static class, in that it is not intended to be instantiated.
// in this case grouping these functions into a class is just to reduce the
// global footprint (could also have been done with namespaces)

define('DIFF_SEGMENTLEN', 2097152);

class diff
{
	public static function strgetsamelen($a, $b, $aoff = 0, $boff = 0, $utf8 = false)
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
	
	public static function strgetrevsamelen($a, $b)
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
	
	public static function strgetdifflen($a, $b, $aoff = 1, $boff = 0, $utf8 = false, $coarse = false, $final = true)
	// substring matching - returns the length in bytes of the initial segments
	// of the strings (from offsets) which contain no significant matching substrings.
	// utf8 mode avoids breaking utf8 chars
	// coarse mode misses more matches and increases speed
	{
		$amax = strlen($a) - $aoff;
		$bmax = strlen($b) - $boff;
		$max = max($amax, $bmax);
		$len = strlen($a) + strlen($b);
		$maxmatchsize = (int)ceil(max(2, sqrt($len)/4, $len/64));
		$matchsize = $coarse ? min(80, (int)($maxmatchsize / 2)) : min(7, $maxmatchsize);
		$cmplen = $coarse ? 60 : min(4, $matchsize);
		$pos1 = $pos2 = false;
		for ($i = $matchsize; $i < $max;)
		{			
			if ($i < $bmax-$cmplen)
				$pos1 = strpos(substr($a, $aoff, $i+$cmplen), substr($b, $boff+$i, $cmplen));
			if ($i < $amax-$cmplen) {
				$pos2 = strpos(substr($b, $boff, (($pos1 === false) ? $i : $pos1)+$cmplen-1),
					substr($a, $aoff+$i, $cmplen));
				if ($pos2 !== false)
					return diff::backtrack($a, $b, $i, $pos2, $aoff, $boff, $matchsize, $cmplen, $utf8);
			}

			if ($pos1 !== false) {
				list($blen,$alen) 
					= diff::backtrack($b, $a, $i, $pos1, $boff, $aoff, $matchsize, $cmplen, $utf8);
				return array($alen, $blen);
			}			
			
			$i += $matchsize;
			$matchsize = min($maxmatchsize, ceil($matchsize*1.25));
			$cmplen = min($matchsize, $coarse ? 120 : 12);
		}
		// backtrack from end if this is really the end
		if ($final && $amax && $bmax)
			return diff::backtrack($a, $b, $amax, $bmax, $aoff, $boff, $matchsize, $cmplen, $utf8);

		return array($amax, $bmax);
	}
	
	private static function backtrack($a, $b, $alen, $blen, $aoff, $boff, $matchsize, $cmplen = 12, $utf8 = false)
	{
		// shift AOFF back if there are earlier matches only a few chars ago
		if ($boff+$blen < strlen($b) && ($pos = strpos(substr($a, $aoff+$alen+1-$matchsize, $matchsize+$cmplen-2),
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
	
	private static function strgetdifflen_segment(
		$a1, $b1, $aoff, $boff, $aseg, $bseg, $asegpos, $bsegpos, $coarse, $final)
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
			if ($i < $amax-$cmplen) {
				$pos2 = strpos(substr($b1, $boff, ($pos1===false?PHP_INT_MAX:($pos1+$cmplen-1))),
					substr($aseg, $i, $cmplen));
				if ($pos2 !== false) {
					list($apos,$bpos) = diff::backtrack(
						$aseg, $b1, $i, $pos2, 0, $boff, $matchsize, $cmplen);
					return array($asegpos+$apos-$aoff, $bpos);
				}
			}
			if ($pos1 !== false) {
				list($bpos,$apos) = diff::backtrack(
					$bseg, $a1, $i, $pos1, 0, $aoff, $matchsize, $cmplen);
				return array($apos, $bsegpos+$bpos-$boff);
			}
			
			// checking in current segment - for replacing
			if ($i < $bmax-$cmplen)
				$pos1 = strpos(substr($aseg, 0, $i+$cmplen), substr($bseg, $i, $cmplen));
			if ($i < $amax-$cmplen) {
				$pos2 = strpos(substr($bseg, 0, ($pos1===false ? $i : $pos1)+$cmplen-1),
				substr($aseg, $i, $cmplen));
				if ($pos2 !== false) {
					list($apos,$bpos)
						= diff::backtrack($aseg, $bseg, $i, $pos2, 0, 0, $matchsize, $cmplen);
					return array($asegpos+$apos-$aoff, $bsegpos+$bpos-$boff);
				}
			}
			if ($pos1 !== false) {
				list($bpos,$apos) = diff::backtrack($bseg, $aseg, $i, $pos1, 0, 0, $matchsize, $cmplen);
				return array($asegpos+$apos-$aoff, $bsegpos+$bpos-$boff);
			}
			$i += $matchsize;
		}
		// backtrack from end if this is really the end
		if ($final) {
			list($apos,$bpos)
				= diff::backtrack($aseg, $bseg, $amax, $bmax, 0, 0, $matchsize, $cmplen);
			return array($asegpos+$apos-$aoff, $bsegpos+$bpos-$boff);
		}

		return array($amax+$asegpos-$aoff, $bmax+$bsegpos-$boff);
	}
	
	public static function dodiff($a, $b, $utf8 = false)
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
			
			// if we've found >15000 edits already, we switch to coarse mode for the rest
			// of the comparison.  just to handle pathalogical cases a bit better
			list($amov, $bmov) = diff::strgetdifflen($a, $b, $aoff, $boff, $utf8, $count>15000);

			if ($amov!=0 || $bmov != 0) $edits[] = array($aoff, $amov, $boff, $bmov);
			
			$aoff += $amov;
			$boff += $bmov;
		}
		return $edits;
	}
	
	private static function getfilechunk($file, $offset, &$segpos)
	{
		fseek($file, $offset);		$segpos = $offset;
		return fread($file, DIFF_SEGMENTLEN);
	}
	
	public static function dodiff_file($filenamea, $filenameb)
	// care MUST be taken that the filenames provided are safe
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
				$abuf, $bbuf, $aoff-$asegpos, $boff-$bsegpos, false, $coarse, 
				$aoff+$seglen >= $alen && $boff+$seglen >= $blen);
			$asegshift = $aoff; $bsegshift = $boff;
			if ($amov == $seglen-($aoff-$asegpos)) do
			{
				$aseg = diff::getfilechunk($a, $aoff+($amov-($coarse?119:11)), $asegshift);
				$bseg = diff::getfilechunk($b, $boff+($bmov-($coarse?119:11)), $bsegshift);
				list($amov,$bmov) = diff::strgetdifflen_segment(
					$abuf, $bbuf, $aoff, $boff, $aseg, $bseg, $asegshift, $bsegshift, $coarse,
					$asegshift+$seglen >= $alen && $bsegshift+$seglen >= $blen);
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
	
	public static function reverse($editlist)
	// reverses an editlist: the 'source' swaps with the 'destination'
	// re-orders the result so it is still in the order it appears in the
	// new source (not that that would occur often)
	{
		$templist = array();
		foreach ($editlist as $edit)
			$templist[] = array($edit[2], $edit[3], $edit[0], $edit[1]);
		usort($templist, array('diff', 'sourcesort'));
		return $templist;
	}
	
	public static function mergeleft($ab, $ac)
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
	
	private static function sourcesort($a, $b)
	{ return $a[0]==$b[0] ? $a[1]-$b[1] : $a[0]-$b[0]; }
	
	public static function assemblemerge($mergelist, $a, $b, $c /* , ... */)
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

/*
set_time_limit(12);

$a = str_repeat("The quick brown fox jumps over the lazy dog", 100);
$b = str_repeat("The quick brown fox leaps over my weird big lazy pig", 100);
$c = str_repeat("The quick brown fox jumps under my weird little lazy dog", 100);
echo (strlen($a) + strlen($b)) . "ready\n";

file_put_contents('outputa.txt', $a);
file_put_contents('outputb.txt', $b);

//$res = diff::dodiff_file('outputa.txt', 'outputb.txt', true);
//list($aoff, $amov, $boff, $bmov) = end($res);
//var_dump(substr(substr(file_get_contents('outputa.txt'), $aoff, $amov), -50));
//var_dump(substr(substr(file_get_contents('outputb.txt'), $boff, $bmov), -50));

list($sec, $usec) = explode(' ', microtime());

set_time_limit(12);

function binhash($d){return hash('sha1',$d,1);}

for ($i = 0; $i < 60; $i++)
{
	//$result = uhash(uniqid('c8PMLhAlevWdEbNf9BRjWhbxhbkTaThJo9wwCadYiys', true)
	//	.'ace'.serialize($_SERVER).mt_rand().__FILE__.time().serialize($_ENV));
	// $result = randhash();
	
	$el1 = diff::dodiff($a, $b, true);
	$el2 = diff::dodiff($a, $c, true);
	$result = diff::mergeleft($el1, $el2);
	$result = diff::assemblemerge($result, $a, $b, $c);
	$result = diff::dodiff_file('outputa.txt', 'outputb.txt');
}

list($xsec, $xusec) = explode(' ', microtime());

$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo count($result);
echo "\n$elapsed\n";
echo substr(var_export($result, true), 0, 300);
exit;
*/

?>
