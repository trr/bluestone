<?php

/*
	httpresume - a helper library for http resuming support
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

// interprets
// 'if-range' and 'range' headers and can deal with multiple ranges

class httpresume
{
	private
		$length,
		$etag,
		$lastmodified,
		$vary,
		$contenttype,
		$ranges,
		$notsatisfiable,
		$boundary,
		$totallen;

	function __construct($length, $etag = null, $lastmodified = null, $vary = null, $contenttype = null)
	{
		$this->length = $length;
		$this->etag = $etag;
		$this->lastmodified = $lastmodified;
		$this->vary = $vary;
		$this->contenttype = $contenttype;
	}
	
	public function getranges()
	{
		$this->ranges = null;
		$this->notsatisfiable = false;
		
		if (empty($_SERVER['HTTP_RANGE'])) return null;	
		$rangeline = $_SERVER['HTTP_RANGE'];
		
		// my interpretation of if-range is that if it exists and it doesn't match, then we should
		// act as if there is no range header, and not even return a 206
		if (!empty($_SERVER['HTTP_IF_RANGE']))
		{
			$ifrange = $_SERVER['HTTP_IF_RANGE'];
			if ($this->etag != preg_replace('/^\"?([\w-]+)\"?$/', '$1', trim($ifrange)))
			{
				if (!$this->lastmodified || $this->lastmodified > strtotime(trim($ifrange)))
					return null;
			}
		}	
		$rangeline = preg_replace('#\s+#', '', $rangeline);
		if (!preg_match('#^bytes\s*=\s*(.*)$#i', $rangeline, $matches)) return null;
		$rangepairs = explode(',', $matches[1]);
		$ranges = array();
		foreach ($rangepairs as $pair)
		{
			if (!preg_match('#^(\d+-\d*|-\d+)$#', $pair)) return null;
			list($low, $high) = explode('-', $pair);
			if ($low != '')
			{
				if ($high != '' && $low > $high) return null;
				if ($low >= $this->length) continue;
				if ($high == '' || $high >= $this->length) $high = $this->length-1;
			}
			else
			{
				if ($high > $this->length) $high = $this->length;
				$low = ($this->length - $high);
				$high = $this->length-1;
			}
			// check for overlaps
			foreach ($ranges as $compid => $range)
			{
				list($complow, $comphigh) = $range;
				if ($low <= $comphigh && $high >= $complow)
				{
					$low = min($low, $complow);
					$high = max($high, $comphigh);
					unset($ranges[$compid]);
				}
			}
			$ranges[] = array((int)$low, (int)$high, null, null);
		}
		if (!count($ranges))
		{
			//header('HTTP/1.1 416 Requested Range Not Satisfiable');
			$ranges[] = array(0, $this->length-1, null, null);
			if (empty($_SERVER['HTTP_IF_RANGE'])) $this->notsatisfiable = true;
		}
		
		if (count($ranges) > 1)
		{
			$this->boundary = md5(uniqid('7iVpx40FGtprA7tKn0faHtbt5PUxrbceI2OEt3PRsU0'));
			$this->totallen = 0;
			foreach ($ranges as $id => $range)
			{
				list($low, $high) = $range;
				$str = "\r\n--BOUND_$this->boundary\r\n"
							. "Content-Type: {$this->contenttype}\r\n"
							. "Content-Range: bytes $low-$high/{$this->length}\r\n\r\n";
				$this->totallen += (strlen($str) + 1 + $high - $low);
				$ranges[$id][2] = $str;		
			}
			$trail = "\r\n--BOUND_$this->boundary--\r\n";
			$this->totallen += strlen($trail);
			$ranges[$id][3] = $trail;
		}
		else
		{
			list($low, $high) = current($ranges);
			$this->totallen = (1 + $high - $low);
		}				
		$this->ranges = $ranges;
		return $ranges;
	}
	
	public function sendheaders()
	{
		if (headers_sent()) trigger_error('Headers already sent', E_USER_ERROR);
		$ranges = $this->ranges;
		header("Content-Length: {$this->totallen}");
		
		if (is_array($ranges) && count($ranges) > 1)
			header("Content-Type: multipart/byteranges; boundary=BOUND_{$this->boundary}");
		else
		{	
			list($low, $high) = reset($ranges);
			header("Content-Range: bytes $low-$high/{$this->length}");
		}
		if (is_array($ranges)) 
		{
			if ($this->notsatisfiable) header('HTTP/1.1 416 Requested Range Not Satisfiable');
				else header('HTTP/1.1 206 Partial Content');
		}
	}

}

?>
