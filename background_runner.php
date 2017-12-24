<?php

include_once "functions.php";
include_once "loggers.php";

$siteName = $argv[1];

if (!$siteName) {
    die("Pass site name to background_runner");
}

// Load configuration
$config = loadConfiguration($siteName);

if (!$config) {
    die("Can't find config file for $siteName");
}

$logger = new PlainTextLogger();
$logger->log("Started ".date("D M d, Y G:i"));
$config["siteName"] = $siteName;
run($config, $logger);
$logger->log("Finished ".date("D M d, Y G:i"));