<?php

//for outputting a fatal error

if (!headers_sent())
{
	header('HTTP/1.1 500 Internal Server Error');
	header('Pragma: no-cache');
	header('Cache-Control: no-cache, must-revalidate');
}

$postmethod = !empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']=='POST';

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
		<?php if ($postmethod) { ?>
		<li>If you tried to submit a form or take an action, it may have been successful despite this error.
		Do not try re-submitting if it would be a problem to take the action twice (such as sending a message or making a purchase).</li>

		<?php } ?>
	</ul>
	<?php echo $debugnotices; ?>
</body>
</html><?php exit; ?>