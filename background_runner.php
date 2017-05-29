<?php

include_once "functions.php";

$siteName = $argv[1];

// Load configuration
$config = loadConfiguration($siteName);

run($config);