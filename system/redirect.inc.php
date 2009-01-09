<?php

// redirect.inc.php

// for redirecting
// if we're in debug mode, we actually see the redirect page

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