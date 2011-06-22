<?php

/*
	redirect - for implementing an HTTP redirect
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

// determine status - we assume we never want to redirect on POST and re-submit the POST vars
$context = context::getinstance();
$method = $context->load_var('REQUEST_METHOD', 'SERVER', 'name');
$protocol = $context->load_var('SERVER_PROTOCOL', 'SERVER', 'string');
$acceptencoding = $this->load_var('HTTP_ACCEPT_ENCODING', 'SERVER', 'string');
$useragent = $context->load_var('HTTP_USER_AGENT', 'SERVER', 'string');

if (preg_match('!^HTTP/1.[1-9]!', $protocol) && $method != 'GET' && $method != 'HEAD') 
	$status = '303 See Other';
elseif ($temporary) $status = '302 Found';
else $status = '301 Moved Permanently';

$destination = strtr($destination, "\r\n\t", "   ");

for ($i = ob_get_level(); $i > 0; $i--) @ob_end_clean();

header("HTTP/1.1 $status");
header("Location: $destination");

if ($temporary) header('Cache-Control: no-cache');

$destination = htmlspecialchars($destination);
$destslash = addslashes($destination);

$data = <<<DOCBOUNDARY
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<title>Page redirect</title><meta http-equiv="refresh" content="2;url=$destination">
<script type="text/javascript">location.href='$destslash';</script>
<h1>Notice</h1>
<p>The page that was requested is located elsewhere, and you are being redirected.
You can go to the new location <a href="$destination">here</a>.
DOCBOUNDARY;

if ((!defined('GZIP_OUTPUT_COMPRESSION') || GZIP_OUTPUT_COMPRESSION==true)
	&& preg_match('/(?<=^|\b)gzip($|\b)/i', $acceptencoding))
{
	header('Content-Encoding: gzip');
	ini_set('zlib.output_compression', 'Off');
	$data = gzencode($data, 1);
}
header('Content-Length: ' . strlen($data));
echo $data;
exit;

?>
