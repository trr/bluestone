<?php 

/*
	context - a generic class for handing input and output from web server
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

class context
{
	function context()
	// constructor
	{
		$this->max_age = 86400;
		$this->sourcearray = array(
			'REQUEST' => &$_REQUEST,
			'GET' => &$_GET,
			'POST' => &$_POST,
			'COOKIE' => &$_COOKIE,
			'SERVER' => &$_SERVER
			);
		if (!count($_POST) && !empty($_SERVER['REQUEST_METHOD'])
			&& $_SERVER['REQUEST_METHOD'] == 'POST')
			trigger_error("POST with no vars, check post_max_size", E_USER_ERROR);
		$this->magicquotes = get_magic_quotes_gpc();
	}
	
	function load_var($varname, $source = 'GET', $type='string', $options = array())
	{
		if (!isset($this->sourcearray[$source])) return NULL;
		$sourcevar = &$this->sourcearray[$source];
		
		if (isset($sourcevar[$varname])) $val = $sourcevar[$varname];
			else return NULL;
		
		if ($this->magicquotes && is_string($val)) $val = stripslashes($val);
			
		switch ($type)
		{
			case 'name':
				return (preg_match('/^[\w-]+$/', (string)$val)) ? (string)$val : NULL;
			case 'int':
				if (!is_numeric($val)) return NULL;
				return (int) $val;
			case 'string':
				return $this->utf8_filter($val);
			case 'yesno':
				return ($val) ? true : false;
			case 'location':
				return (preg_match('/^[\w.-]+$/', (string)$val)) ? (string)$val : NULL;
			case 'password':
				// only printable ascii characters allowed in password, for max compatibility
				return (preg_match('/^[\x20-\x7E]++$/', (string)$val)) ? (string)$val : NULL;
			case 'float':
				if (!is_numeric($val)) return NULL;
				return (float) $val;
			case 'alpha':
				return (preg_match('/^[a-zA-Z]++$/', (string)$val)) ? (string)$val : NULL;
			case 'alphanumeric':
				return (preg_match('/^[a-zA-Z0-9]++$/', (string)$val)) ? (string)$val : NULL;
			case 'email':
				return (preg_match('/^[\w.+-]+\@[a-zA-Z.-]+\.[a-zA-Z-]{2,8}$/', (string)$val)) ? (string)$val : NULL;
			case 'submit':
			  return true;
			case 'select':
				foreach ($options as $optionval) if ($val == $optionval) return $optionval;
				return NULL;
			case 'mixed':
				return strlen($val) ? $this->utf8_filter($val) : '';
		}
		return NULL;
	}
	
	function utf8_filter($val)
	// filters a string to remove invalid utf-8.  filters recursively
	// if $val is an array.
	{
		require_once(BLUESTONE_DIR . 'utf8_string.inc.php');
		if (is_array($val))
		{
			foreach ($val as $vkey => $vval)
				$val[$vkey] = $this->utf8_filter($vval);
			return $val;
		}
		$str = &new utf8_string($val);
		return $str->filter();
	}
	
	function redirect($destination, $temporary = false)
	// returns an http redirect
	{
		$cookies = !empty($this->cookies);
		require_once(BLUESTONE_DIR . 'system/redirect.inc.php');
	}
	
	function header($text, $replace = false)
	// adds a header to output (ie, the response header).
	{
		$this->headers[] = array('header' => $text, 'replace' => $replace);
	}
	
	function setmaxage($age)
	{
		if ($age < $this->max_age && $age >= 0) $this->max_age = $age;
	}
	
	function setvary($vary)
	{
		$this->vary = $vary;
	}
	
	function setlastmodified($time)
	// unlike http modified, you are allowed to set and check a last modified time
	// even if the content varies.  this module will not use the last modified time
	// for conditional responses when vary is enabled.
	{
		if ($time > $this->lastmodified && $time > 0) $this->lastmodified = $time;
	}
	
	function setcachedirective($data)
	{
		if ($data && !isset($this->cache_directives[$data]))
			$this->cache_directives[$data] = $data;
	}
	
	function setstatus($code, $text)
	{
		$this->statuscode = $code;
		$this->statustext = $text;
	}
	
	function setcookie($nam,$val='',$exp=0,$path='',$dom='',$secu=false,$httponly=false)
	// alias of setcookie() except that this will allow context to keep track of
	// whether cookies have been sent for its caching mechanism
	{
		$this->cookies = true;
		if (version_compare(PHP_VERSION, '5.2.0') > 0)
			return setcookie($nam,$val,$exp,$path,$dom,$secu,$httponly);
		return setcookie($nam,$val,$exp,$path,$dom,$secu); // compatibility with earlier PHP
	}
	
	function processcache($data, $isfile, $filename)
	{
		$addr = $this->load_var('SERVER_ADDR', 'SERVER', 'location');
		$ua = $this->load_var('HTTP_USER_AGENT', 'SERVER', 'string');
		
		$nofresh = (($addr=='127.0.0.1' && preg_match('!Firefox/!', $ua)) //firefox localhost issues	
			|| ($this->vary=='*' || strpos($this->vary, ',') !== false) // common intolerance of multiple vary
			|| !empty($this->cache_directives['no-cache'])); 
		$nohttp10 = $this->vary != '' || $this->statuscode != 200 || $this->max_age < 300;

		if (!$nofresh) $this->cache_directives[] = "max-age={$this->max_age}";
		if ($this->docompress) $this->vary=($this->vary=='' ? 'Accept-Encoding' : "{$this->vary}, Accept-Encoding");
		
		header("Cache-Control: " . implode(', ', $this->cache_directives));
		if (!$nofresh && !$nohttp10)
			header("Expires: " . gmdate('D, d M Y H:i:s', TIMENOW + $this->max_age) . ' GMT');
		if ($this->vary!='') header("Vary: {$this->vary}");
		
		$this->etag = null;
	
		if ($this->statuscode == 200 && empty($this->cookies)
			&& $this->method == 'GET' || $this->method == 'HEAD')
		// there is debate about whether you can set cookies along with not modified
		// but some versions of apache 2 (at least) won't allow it, so we don't
		{
			// etag
			$this->etag = $isfile ? md5($filename.filemtime($filename).':'.filesize($filename)) 
				: md5($data."\xff\xdf{$this->docompress}");
			header("ETag: \"{$this->etag}\"");
			$ifnonematch = $this->load_var('HTTP_IF_NONE_MATCH', 'SERVER', 'string');
			if ($ifnonematch=='*' || strpos($ifnonematch, '"'.$this->etag.'"')!==false)
			{
				header('HTTP/1.1 304 Not Modified');
				return false;
			}
			
			// last modified
			if ($this->lastmodified && $this->vary=='' && !$ifnonematch)
			{
				if ($ifmodifiedsince = $this->load_var('HTTP_IF_MODIFIED_SINCE', 'SERVER', 'string'))
				{
					list($ifmodifiedsince) = explode(';', $ifmodifiedsince);
					$modifiedsincetime = strtotime($ifmodifiedsince);
					if ($modifiedsincetime >= $this->lastmodified)
					{
						header('HTTP/1.1 304 Not Modified');
						return false;
					}
				}
				header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $this->lastmodified) . ' GMT'); //no lastmod if 304
			}
		}
		else $this->etag === null;
		
		if ($this->statuscode<299 && $this->statuscode>=200 && (
			(($ifmatch = $this->load_var('HTTP_IF_MATCH', 'SERVER', 'string'))!==null
			&& strpos($ifmatch, '"' . $this->etag . '"')===false)
			|| (($ifunmodified = $this->load_var('HTTP_IF_UNMODIFIED_SINCE', 'SERVER', 'string'))!==null
			&& (!$this->lastmodified || $this->lastmodified > strtotime($ifunmodified)))
			))
		{
			header('HTTP/1.1 412 Precondition Failed');
			return false;
		}
		
		return true;
	}
	
	function processgzip()
	{
 		if (!extension_loaded('zlib')) return false;
		$acceptencoding = $this->load_var('HTTP_ACCEPT_ENCODING', 'SERVER', 'string');
		if (empty($acceptencoding)) return false;
		if (!preg_match('#^(text/|application/(xhtml|xml|postsc|mswor|excel|rtf|x-tar)|image/(bmp|tiff))#i', $this->contenttype))
			return false;
		return preg_match('/(?<=^|\b)gzip($|\b)/i', $acceptencoding);
	}
	
	function setcontenttype($contenttype)
	// you should define the character set, where applicable, like this
	// 'text/plain; charset=utf-8'
	{
		$this->contenttype = $contenttype;
	}
	
	function setcontentfilename($filename, $basedir)
	// setting a filename here will cause dooutput to output data from this file
	// rather than the data parameter sent to it.
	// basedir is required; using it properly ensures that the $filename is from
	// within that basedir.  it's important
	{
		$this->contentfilename = $filename;
		$this->basedir = $basedir;
	}
	
	function dooutput($data, $gzipcompress = true)
	{
		for ($i = ob_get_level(); $i > 0; $i--) @ob_end_clean();
		$isfile = $this->contentfilename === null 
			? false : file_exists($this->contentfilename);
		$this->method = $this->load_var('REQUEST_METHOD', 'SERVER', 'name');
		header("HTTP/1.1 $this->statuscode $this->statustext");
			
		$this->docompress = ($gzipcompress && !$isfile) ? $this->processgzip() : false;
		
		//header('X-Powered-By: '); header('Server: ');
		if ($this->processcache($data, $isfile, $this->contentfilename))
		{
			header("Content-Type: {$this->contenttype}");
			foreach ($this->headers as $val) header($val['header'], $val['replace']);
			if (DEBUG && preg_match('!text/html;|application/xhtml(?:\+xml)?;!', $this->contenttype))
			{
				$debug = &debug::getinstance();
				$debug->notice('context', 'dooutput()');
				$debugmessages = $debug->getnoticestext();
				$debugmessages = str_replace('--', '==', $debugmessages);
				$data .= "\n<!-- Debug messages:\n\n$debugmessages\n-->";
			}
			if ($this->docompress)
			{
				$data = gzencode($data, 1);
				$ua = $this->load_var('HTTP_USER_AGENT', 'SERVER', 'string');
				if ($this->vary and preg_match('/MSIE (5\.5|6\.0);/', $ua))
				{
					// see microsoft knowledgebase http://support.microsoft.com/default.aspx?scid=kb;en-us;321722
					// IE 5.5 and 6.0 cannot handle any type of cache control headers with gzip encoding - it will always cache the file
					header('Cache-Control:');
					header('Expires:');
				}				
				header('Content-Encoding: gzip');
				ini_set('zlib.output_compression', 'Off');
			}
			
			$this->length = $isfile ? filesize($this->contentfilename) : strlen($data);
			
			// resuming support
			if ($this->length >= 8192) header('Accept-Ranges: bytes');
			if (!empty($_SERVER['HTTP_RANGE']))
			{
				require_once(BLUESTONE_DIR . 'httpresume.inc.php');
				$httpresume = 
					&new httpresume($this->length, $this->etag, $this->lastmodified, $this->vary, $this->contenttype);
				$ranges = $httpresume->getranges();	
				if ($ranges) $httpresume->sendheaders();		
			} 
			else $ranges = null;
			
			if ($ranges===null) header("Content-Length: {$this->length}");
			
			if ($this->method != 'HEAD')
			{
				if ($isfile)
				{
					// trying to secure file serving: setting a base directory
					$oldbasedir = ini_get('open_basedir');
					ini_set('open_basedir', $this->basedir);
					$file = fopen($this->contentfilename, 'rb');
				}
				if (is_array($ranges)) foreach ($ranges as $range)
				{
					list($low, $high, $boundary, $trailer) = $range;
					$len = 1 + $high - $low;
					if ($boundary !== null) echo $boundary;
					if ($isfile) $this->file_echo($file, $low, $len);
						else echo substr($data, $low, $len);
					if ($trailer !== null) echo $trailer;
				}
				elseif ($isfile) $this->file_echo($file);
					else echo $data;
					
				if ($isfile) 
				{
					fclose($file);
					ini_set('open_basedir', $oldbasedir);
				}
			}
		}
		flush();
		unset($data);
	}
	
	function file_echo($file, $start = null, $len = null)
	{
		if (!$file) trigger_error('Not a valid file resource', E_USER_ERROR);
		if ($start !== null) fseek($file, $start);
		for ($bytesread=0; ($len===null||$bytesread<$len) && !feof($file);)
		{
			$fetch = $len===null ? 32768 : min(32768, $len-$bytesread);
			$data = fread($file, $fetch);
			$bytesread += strlen($data);
			echo $data;
			flush();
		}
		unset($data);
	}
	
	function fatal_error($name='', $details='')
	// halts the script and displays an error message.  this is only to be used when
	// the error is absolutely unavoidable and beyond user's control
	{
		include(BLUESTONE_DIR . 'system/fatalerror.inc.php'); exit;
	}
	
	function &getinstance()
	// singleton implementation
	// these parameters are used to create the object if the object doesn't exist
	{
		static $instance;
		if (!isset($instance)) $instance = new context(); // no & in a singtleton
		return $instance;
	}

	// private

	var $headers = array();
	var $output = '';
	var $lastmodified = 0;
	var $cache_directives = array();
	var $contentfilename = null;
	var $statuscode = 200;
	var $statustext = 'OK';
	var $vary = '';
	var $initiatortoken = '';
	var $initiatorhash = null;
	var $contenttype = 'text/html; charset=utf-8';
}

?>