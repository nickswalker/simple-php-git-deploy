<?php

$html_preamble = <<<END
 <!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex">
	<title>Simple PHP Git deploy script</title>
	<style>
        body { padding: 0 1em; background: #222; color: #fff; }
        h2, .error { color: #c33; }
        .prompt { color: #6be234; }
        .command { color: #729fcf; }
        .output { color: #999; }
    </style>
</head>
<body>
END;

interface Logger{

    public function log($message);
    public function getLog();
}

class HTMLLogger implements Logger{
    private $completeLog;
    function __construct() {
        ob_start();
        global $html_preamble;
        $this->completeLog = $html_preamble;
        echo $html_preamble;
    }
    public function log($message) {
        $this->completeLog .= htmlentities($message)."\n";
        echo "<pre>".htmlentities($message)."</pre>\n";
        ob_flush();
    }

    public function getLog(){
        return $this->completeLog;
    }
}

class PlainTextLogger implements Logger{
    private $completeLog;

    function __construct() {
        ob_start();
        $this->completeLog = "";

    }
    public function log($message) {
        $this->completeLog .= $message."\n";
        echo $message."\n";
        ob_flush();
    }

    public function getLog()
    {
        return $this->completeLog;
    }
}
