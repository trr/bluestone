<?php 

/*
	context - a generic class for handing input and output from web server
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

class context
{
	private
		$statuscode = 200,
		$statustext = 'OK',
		$contenttype = 'text/html; charset=utf-8',
		$cookies = false,
		$sourcearray;

	function __construct()
	{
		$this->sourcearray = array(
			'REQUEST' => $_REQUEST,
			'GET' => $_GET,
			'POST' => $_POST,
			'COOKIE' => $_COOKIE,
			'SERVER' => $_SERVER
			);
		if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'POST')
			$this->check_posterror();

		if (get_magic_quotes_gpc()) throw new Exception('Magic quotes are enabled; please disable');

		header_remove('X-Powered-By');
	}
	
	public function load_var($varname, $source = 'GET', $type='string', $options = array())
	{
		if (!isset($this->sourcearray[$source][$varname])) return null;

		$val = $this->sourcearray[$source][$varname];

		static $patterns = array(
			'name' => '/^[\w-]++$/',
			'location' => '/^[\w.-]++$/',
			'password' => '/^[\x20-\x7E]++$/', //printable ASCII characters for compatibility
			'alpha' => '/^[a-zA-Z]++$/',
			'alphanumeric' => '/^[a-zA-Z0-9]++$/',
			// todo email: filter non-ascii chars, disallow quoted parts anywhere
			'email' => '/^
					(?:
						[\w!#$%&\'*+\/=?^`{|}~_-]+ |
						(?<!\.|^)\.(?!\.|@) |
						"(?: [^ "\\\\\t\n\r]+ | \\\\[ \"\\\\] )*"
					)+
					@
					(?: [a-zA-Z0-9] (?:[a-zA-Z0-9-]*[a-zA-Z0-9])? (?:\.(?!$))? )+
					$/x',
			);

		if ($type === 'string') 
			if ($val === '') return '';
			elseif (is_string($val) && preg_match('/^[\x20-\x7e\x09\x0a\x0d\x{a0}-\x{fdcf}]++$/u', $val)) // opt
				return $val;
			else return $this->utf8_filter($val);

		if (isset($patterns[$type])) 
			if (is_string($val) && preg_match($patterns[$type], $val)) return $val;
			else return null;

		if ($type === 'int') 
			if (is_numeric($val)) return (int)$val;
			else return null;

		if ($type === 'yesno') return !empty($val);

		if ($type === 'float') 
			if (is_numeric($val)) return (float)$val;
			else return null;

		if ($type === 'select')
			if (in_array($val, $options)) return $val;
			else return null;

		if ($type === 'mixed') 
			if ($val === '') return '';
			elseif (is_string($val) && preg_match('/^[\x20-\x7e\x09\x0a\x0d\x{a0}-\x{fdcf}]++$/u', $val)) // opt
				return $val;
			elseif (!is_array($val)) return $val;
			else return $this->utf8_filter($val, true);

		if ($type === 'submit') return true;

		throw new Exception('Unknown type ' . $type);
	}
	
	private function utf8_filter($val, $recursive = false)
	// filters a string to remove invalid utf-8.  filters recursively
	// if $val is an array.
	{
		if ($recursive && is_array($val)) {
			foreach ($val as $vkey => $vval)
				$val[$vkey] = $this->utf8_filter($vval, true);
			return $val;
		}
		require_once(BLUESTONE_DIR . '/utf8_string.inc.php');
		return (new utf8_string($val))->filter();
	}
	
	private function check_posterror()
	// check empty post possibly caused by post_max_size exceeded
	{
		if (!count($_POST) && !empty($_SERVER['CONTENT_TYPE'])
			&& preg_match('#^(?:multipart/form-data|application/x-www-form-urlencoded)\b#i', 
				$_SERVER['CONTENT_TYPE']))
			trigger_error('Unexpected empty POST; post_max_size exceeded?', E_USER_ERROR);
	}

	public function redirect($destination, $temporary = false, $subjecttochange = true)
	// returns an http redirect
	// if subjecttochange and temporary are false, the redirect can be cached by user
	{
		$cookies = $this->cookies ? true : false;
		require(BLUESTONE_DIR . '/system/redirect.inc.php');
	}
	
	public function header($text, $replace = false) {
	// now just an alias for php header() but with default $replace as false
		header($text, $replace);
	}
	
	public function setstatus($code, $text) {
		header("HTTP/1.1 $code $text");
		$this->statuscode = $code;
		$this->statustext = $text;
	}
	
	public function setcookie($nam,$val='',$exp=0,$path='',$dom='',$secu=false,$httponly=false)
	// alias of setcookie() except that this will allow context to keep track of
	// whether cookies have been sent for its caching mechanism
	{
		$this->cookies = true;
		return setcookie($nam,$val,$exp,$path,$dom,$secu,$httponly);
	}

	public function handleetag($etag) {
	// returns true if the etag is matched (and we should not output any body)
	// if you intend to return a status code other than "200 OK" you must call
	// setstatus() prior to this

		if ($this->statuscode == 200) {
			header('ETag: ' . $etag);

			$ifnonematch = $this->load_var('HTTP_IF_NONE_MATCH', 'SERVER', 'string');
			if ($ifnonematch=='*' || strpos($ifnonematch, '"'.$etag.'"')!==false) {
				$this->setstatus(304, "Not Modified");
				return true;
			}
		}

		// todo we should return 412 for if-match even when no etag is specified
		if ($this->statuscode < 299 && $this->statuscode >= 200 && (
			(($ifmatch = $this->load_var('HTTP_IF_MATCH', 'SERVER', 'string')) !== null &&
			strpos($ifmatch, '"' . $etag . '"') === false))) {

			header('HTTP/1.1 412 Precondition Failed');
			return true;
		}

		return false;
	}

	public function handlelastmodified($lastmodified) {
	// returns true if the response is not modified (and we should not output any body)
	// if you intend to return a status code other than "200 OK" you must call
	// setstatus() prior to this

		if ($this->statuscode == 200) {
			if ($ifmodifiedsince = $this->load_var('HTTP_IF_MODIFIED_SINCE', 'SERVER', 'string')) {
				list($ifmodifiedsince) = explode(';', $ifmodifiedsince);
				$modifiedsincetime = strtotime($ifmodifiedsince);
				if ($lastmodified <= $modifiedsincetime) {
					$this->setstatus(304, "Not Modified");
					return true;
				}
			}
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmodified) . ' GMT'); //no lastmod if 304
		}

		// todo we should return 412 for if-unmodified-since even when no etag is specified
		if ($this->statuscode < 299 && $this->statuscode >= 200 && (
			(($ifunmodified = $this->load_var('HTTP_IF_UNMODIFIED_SINCE', 'SERVER', 'string')) !== null &&
			$lastmodified > strtotime($ifunmodified)))) {

			header('HTTP/1.1 412 Precondition Failed');
			return false;
		}
		return false;
	}
		
	public function setcacheheaders($ttl = null, $vary = array(), $directives = array()) {
	// if you intend to send any cookies the setcookie() method MUST be called prior to this

		$method = $this->load_var('REQUEST_METHOD', 'SERVER', 'name');
		$getmethod = $method === 'GET' || $method === 'HEAD';

		// can we output freshness info
		// for now we don't set cacheability if there are cookies in the response.  We could add
		// no-cache=set-cookie but support for this may be poor
		if ($ttl > 0 && $getmethod && !$this->cookies && empty($vary['*']) && 
			empty($directives['no-cache']) && empty($directives['no-store'])) {
			
			// firefox localhost issues
			$addr = $this->load_var('SERVER_ADDR', 'SERVER', 'location');
			if ($addr !== '127.0.0.1' && strpos($this->load_var('HTTP_USER_AGENT', 'SERVER', 'string'), 'Firefox/') === false) {
				$directives['max-age=' . (int)$ttl] = true;
			}
		}

		// output cache headers
		if (count($directives)) header('Cache-Control: ' . implode(', ', array_keys(array_filter($directives))));
		if (!empty($vary)) header('Vary: ' . implode(', ', array_keys(array_filter($vary))));
	}
	
	public function setcontenttype($contenttype)
	// you should define the character set, where applicable, like this
	// 'text/plain; charset=utf-8'
	{
		header('Content-Type: ' . $contenttype);
		$this->contenttype = $contenttype;
	}
	
	public function fatal_error($name='', $details='')
	// halts the script and displays an error message.  this is only to be used when
	// the error is absolutely unavoidable and beyond user's control
	{
		include(BLUESTONE_DIR . '/system/fatalerror.inc.php'); exit;
	}
	
	public static function &getinstance()
	// singleton implementation
	// these parameters are used to create the object if the object doesn't exist
	{
		static $instance;
		if (!isset($instance)) $instance = new context(); // no & in a singtleton
		return $instance;
	}

}

?>
