<?php

/*
	filetype - a generic library for determining file types from content
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

// Works with files in the file system or in memory

// Note that files over 2GB may not be reliably detected, not to mention
// it may not be possible in PHP to know if your file is over 2GB. Make
// sure your file is <2GB before testing.

define('FILETYPE_BUF_CHUNK', 4096);

class filetype
{
	private
		$isfile,
		$file,
		$valid,
		$length,
		$data,
		$types;

	function __construct($data = null, $filename = null)
	// if filename is left blank, $data is taken to contain the contents of the file.
	// if filename is given then data is ignored
	// care MUST be taken to ensure the filename provided is safe
	{
		$this->isfile = $filename !== null;
		
		if ($this->isfile)
		{
			$this->file = fopen($filename, 'rb');
			$this->valid = $this->file ? true : false;
			$this->length = filesize($filename);
		}
		else
		{
			$this->data = $data;
			$this->length = strlen($data);
		}
	}
	
	private function getchunk($offset, $len)
	{
		if (!$this->isfile) return substr($this->data, $offset, $len);
		if (!$this->file) return null;
		
		fseek($this->file, $offset);
		return fread($this->file, $len);		
	}
	
	private function findstring($needle, $start = 0, $end = null /* end of string */)
	// end can be before start, in which case the search is in reverse
	// returns position of found substring, or FALSE if not found
	{
		if ($end===null) $end = $this->length;
		$backward = ($start > $end);
		$sourceoff = min($start, $end);
		$sourcelen = min(abs($start-$end)+strlen($needle), $this->length-$sourceoff);
		
		$chunksize = max(FILETYPE_BUF_CHUNK, strlen($needle)+ceil(FILETYPE_BUF_CHUNK/2));
		$advance = $chunksize-strlen($needle);
		
		for ($i = 0, $end = $sourcelen-strlen($needle); $i <= $end; $i += $advance)
		{
			$extent = min($chunksize, $sourcelen-$i);
			if ($backward)
			{
				$haystack = $this->getchunk($sourceoff+$sourcelen-$i-$extent, $extent);				
				// requires PHP5
				if (($pos = strrpos($haystack, $needle)) !== false)
					return $pos+$sourceoff+$sourcelen-$i-$extent;
			}
			else
			{
				$haystack = $this->getchunk($sourceoff+$i, $extent);
				if (($pos = strpos($haystack, $needle)) !== false)
					return $pos+$sourceoff+$i;
			}
		}
		return false;	
	}
	
	public function gettypes()
	// analyses the type of the file and returns a list of matching types
	// we use x- rather than vnd. notation for types where it doesn't seem official
	// note that the x- may be removed in future so comparisons should be done removing the x-
	{
		if (isset($this->types)) return;
		
		// more specific or important types should come before more general types
		
		static $headmagic = array(
			"\xff\xd8\xff" => 'image/jpeg',
			"\x89PNG\x0d\x0a\x1a\x0a" => 'image/png',
			"II*\x00" => 'image/tiff',
			"MM\x00*" => 'image/tiff',
			"\x00\x00\x01\x00" => 'image/ico',
			"\x00\x00\x02\x00" => 'image/ico',
			"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" => 'application/msoffice',
			"PK\x03\x04\x14\x00\x06\x00" => 'application/msoffice',
			"PK\x03\x04\x14\x00\x00\x00\x00\x00" => 'application/opendocument', // check
			"\x1f\x9d\x90" => 'application/x-gzip',
			"7z\xbc\xaf\x27\x1c" => 'application/x-7z',
			"MSCF" => 'application/x-cab', // MS CAB FILE
			"ISc(" => 'application/x-cab', // Installshield CAB
			"\xca\xfe\xba\xbe" => 'application/x-java-class',
			"FLV" => 'application/flv',
			"GIF89a" => 'image/gif',
			"GIF87a" => 'image/gif',
			"BM" => 'image/bmp',
			"MZ" => 'application/exe', // also DLL, OCX, SCR etc
			"ZM" => 'application/exe',
			"NE" => 'application/exe', // note false positives quite likely
			"RIFF" => 'application/avi',
			"FWS" => 'application/swf',
			"XFIR" => 'application/x-shockwave',
			"%!PS" => 'application/postscript',
			"{\rtf1" => 'application/rtf',			
			);
		
		$this->types = array();
		
		$chunk = $this->getchunk(0, 512);
		$chunklower = strtolower($chunk);
		
		foreach ($headmagic as $magic => $type)
			if (substr($chunk, 0, strlen($magic))===$magic) $this->types[$type] = true;
		
		// zip test
		if ($this->gettypezip($chunk)) $this->types['application/zip'] = true;
		
		// html test
		if ($this->gettypehtml($chunklower)) $this->types['text/html'] = true;
			
		// mov/mp4 test
		if (substr($chunk, 4, 4) == 'ftyp') $this->types['video/mp4'] = true;
		if (in_array(substr($chunk, 4, 4), array(
			'moov', 'free', 'skip', 'mdat', 'pnot'))) $this->types['video/quicktime'] = true;
		
		// php source test
		if (strpos(strtolower($chunk), '<?php')!==false) $this->types['application/x-httpd-php-source'] = true;
		
		// xml types
		if (substr(ltrim($chunk), 0, 5) == '<?xml')
		{
			if (strpos($chunk, 'http://www.w3.org/2000/svg')!==false) $this->types['image/svg+xml'] = true;
			$this->types['application/xml'] = true;
		}		
		
		// pdf type (we're being fairly lax about case and some leading whitespace)
		if (substr(ltrim($chunklower), 0, 4) == '%pdf') $this->types['application/pdf'] = true;			
		
		// htc (microsoft dhtml behaviours) - not an exhaustive check
		if (strpos($chunklower, '<public:component>')!==false) $this->types['text/x-component'] = true;
		
		// TODO add ini/inf detection
		
		return array_keys($this->types);			
	}
	
	private function gettypehtml($chunklower)
	// sees if this content looks like html
	{
		static $matches = array(
			// suggested IE sniffing matches
			'<body', '<head', '<html', '<img src', '<plaintext', '<a href', '<script', '<table', '<title',
			// extra ones
			'<!doctype html'
			);
		foreach ($matches as $match)
			if (strpos($chunklower, $match)!== false) return true;
		if (strpos($chunklower, '<!--') !== false && strpos($chunklower, '-->') !== false) return true; 
		return false;
	}
	
	private function gettypezip($chunk)
	// more thorough check to see if the file could be identified as zip
	// errs on the side of detecting; true result could mean "looks like zip but who knows if it works"
	{
		if ($chunk[0]=='P' && $chunk[1]=='K' && $chunk[2] < 0x09 && $chunk[3] < 0x09)
			return true;
		
		$i = $this->length;
		$min = max(0, ($this->length-65536)-42);
		for (; $i >= $min && ($pos = $this->findstring("PK", $i, $min)) !== false; $i = $pos-1)
		{
			$chunk = $this->getchunk($pos, 56);
			
			// check if it looks like CDENDMARKER
			if (substr($chunk, 0, 4) == "PK\x05\x06")
			{
				$parts = unpack('Vsig/vdisknum/vdiskcdstart/vcdentriesdisk/vcdentriestotal/Vcdsize/Vcdoffset/vcommlength', 
					str_pad($chunk, 22, "\x00"));
				
				if ($parts['disknum']==$parts['diskcdstart'])
				{
					// going back cdlen finds a central directory record
					if ($parts['cdsize'] <= $pos && $this->getchunk($pos - $parts['cdsize'], 4) == "PK\x01\x02") return true;
					// offset cdoffset finds a central directory record
					if ($parts['cdoffset'] < $this->length && $this->getchunk($parts['cdoffset'], 4) == "PK\x01\x02") return true;
				}
			}	
			// check if it looks like ZIP64CDENDLOCATOR
			elseif (substr($chunk, 0, 4) == "PK\x06\x07")
			{
				$parts = unpack('Vsig/Vdiskcdend/Vcdoffsetlo/Vcdoffsethi/Vdisknum', 
					str_pad($chunk, 20, "\x00"));
				
				if ($parts['disknum']==$parts['diskcdend'])
				{
					// if offset is out of 32 bit signed int range then give up
					if ($parts['cdoffsethi'] != 0 || $parts['cdoffsetlo'] < 0) return true;
					// going back cdoffsetlo bytes works
					if ($parts['cdoffsetlo'] < $pos && $this->getchunk($pos - $parts['cdoffsetlo'], 4) == "PK\x06\x06") return true;
					// get to offset cdoffsetlo bytes works
					if ($parts['cdoffsetlo'] < $this->length && $this->getchunk($parts['cdoffsetlo'], 4) == "PK\x06\x06") return true;
				}
			}
			// check if it looks like ZIP64CDENDMARKER
			elseif (substr($chunk, 0, 4) == "PK\x06\x06")
			{
				$parts = unpack('Vsig/Vendcdsizelo/Vendcdsizehi/vversion/vversionreq/Vdisknum/Vdiskcdstart/'
					.'/Ventriesherelo/Ventriesherehi/Ventrieslo/Ventrieshi/Vcdsizelo/Vcdsizehi/Vcdofflo/Vcdoffhi', 
					str_pad($chunk, 56, "\x00"));
				
				if ($parts['disknum']==$parts['diskcdstart'])
				{
					// if cdsize is out of 32 bit signed int range then give up
					if ($parts['cdsizehi'] != 0 || $parts['cdsizelo'] < 0) return true;
					// if cdoffset is out of 32 bit signed int range then give up
					if ($parts['cdoffhi'] != 0 || $parts['cdsizelo'] < 0) return true;					
					// going back cdsizelo bytes works
					if ($parts['cdsizelo'] < $pos && $this->getchunk($pos - $parts['cdsizelo'], 4) == "PK\x01\x02") return true;
					// get to offset cdoffsetlo bytes works
					if ($parts['cdofflo'] < $this->length && $this->getchunk($parts['cdofflo'], 4) == "PK\x01\x02") return true;
				}
			}	
		}
		return false;
	}
	
	public function gettype()
	// returns just a single matching type.  if more than one type matched, then
	// it tries to go with the more specific or important one
	// if no types matched, it returns application/octet-stream
	// note that if you serve application/octet-stream you may get browser sniffed by IE
	{
		if (!isset($this->types)) $this->gettypes();
		
		if (!empty($this->types))
			foreach ($this->types as $key => $val)
				if ($val) return $key;
		
		return 'application/octet-stream';
	}
	
	public function issafeimage()
	// returns true if the type was detected to be a PNG, GIF or JPG file and nothing else
	// it should be relatively safe to serve it provided proper precautions.  gif and jpeg (and PNG
	// on recent versions) shouldn't be content sniffed by IE
	{
		if (!isset($this->types)) $this->gettypes();
		
		if (!$this->valid || empty($this->types)) return false;
		foreach ($this->types as $key => $val)
			if ($val && (!in_array($key, array('image/gif', 'image/png', 'image/jpeg'))))
				return false;
		
		return true;
	}
	
	public function isbrowsersafe()
	// returns true if it is relatively safe to serve a file of this file type uploaded by an
	// untrusted user.  not as safe as issafeimage, as some browsers may browser sniff
	// the type though
	{
		if (!isset($this->types)) $this->gettypes();
		
		if (!$this->valid || empty($this->types)) return false;
		foreach ($this->types as $key => $val)
			if ($val && (!in_array($key, array(
				'image/gif', 'image/png', 'image/jpeg',
				'image/bmp', 'image/tiff', 'image/ico',
				'application/avi', 'application/mp4', 'application/flv',
				'application/rdf', 'text/plain'				
				// PDF removed; can now run javascript?
				))))
				return false;
		
		return true;
	}
}

// TODO profiling

//$filetype = new filetype(null, "d:/temp/me4.jpg");
//var_export($filetype->gettypes());

?>
