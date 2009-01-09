<?php

header('HTTP/1.1 503 Service Unavailable');

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
 <head>
  <title>
		Site closed for maintenance
  </title>
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

</style>
 </head>
 <body>
  <h1>
   	This site is currently closed for maintenance.
  </h1>
	<p>
	 The site should become available as soon as possible.
	</p>
	<p>
		Sorry for the inconvenience.
	</p>
 </body>
</html>
<?php
	exit;
?>