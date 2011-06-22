<?php

/*
	fatalerror - for displaying a fatal error
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

if (!headers_sent())
{
	header('HTTP/1.1 500 Internal Server Error');
	header('Pragma: no-cache');
	header('Cache-Control: no-cache, must-revalidate');
}

$postmethod = !empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']=='POST';
$postempty = ($postmethod && !count($_POST));

if (class_exists('debug') && DEBUG)
{
	$debug = &debug::getinstance();
	$debugnotices = $debug->getnoticeshtml();
}
else
	$debugnotices = '';

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<!--

	NOTICE TO SITE ADMINISTRATORS:
	
		AN ERROR HAS OCCURRED.
	
	If this error persists, further information about the error may
	be gained by enabling DEBUG mode, the setting for which is in
	the site's configuration file.

	For reasons of security it is not recommended that you enable
	DEBUG mode while your site is accessible by the public, as it
	can reveal secret information about your application that an
	attacker could use to gain access.

-->

<head><title>An error has occurred.</title>
<style type="text/css">
html
{
	background: #EEE;
	color: #333;
	font-family: Arial, sans-serif;
	font-size: small;
}
body
{
	background: #FFF;
	border: 1px solid #CCC;
	margin: 120px 26%;
	padding: 20px;
}
h1
{
	color: #777;
	font-size: large;
	font-weight: bold;
	margin-top: 0;
}
a {color: #777;}
li {margin-bottom: 6px;} 
</style>
</head>
<body>
	<h1>
	Unable to view page.
	</h1>
	<p>
	An error has occurred that is preventing this page from displaying.
	</p>
	<ul>
		<li>You could try refreshing the page in a little while to see if the problem is temporary.</li>
		<li>Perhaps there is a problem with the page itself.  Try going back to the last page you were on, or visit the <a href="/">home page</a>.</li>
		<?php if ($postmethod && !$postempty) { ?>
		<li>If you tried to submit a form or take an action, it may have been successful despite this error.
		Do not try re-submitting if it would be a problem to take the action twice (such as sending a message or making a purchase).</li>
		<?php } elseif ($postempty) { ?>
		<li>If you tried to submit a form or send a file, the total size of what you sent may have been too large.</li>		
		<?php } ?>
	</ul>
	<?php echo $debugnotices; ?>
</body>
</html><?php exit; ?>
