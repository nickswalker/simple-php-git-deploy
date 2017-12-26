<?php
/**
 * Simple PHP Git deploy script
 *
 * Automatically deploy the code using PHP and Git.
 *
 * @version 1.4
 * @link    https://github.com/nickswalker/simple-php-git-deploy/
 */
//error_reporting(E_ALL);
//ini_set('display_errors', '1');

include_once "functions.php";
include_once "loggers.php";

$siteName = isset($_GET['site']) ? $_GET['site'] : "";
$token = isset($_GET['sat']) ? $_GET['sat'] : "";

// Load configuration
$config = loadConfiguration($siteName);

if (!$config) {
    die("Can't find config file for $siteName");
}

// If there's authorization error, set the correct HTTP header.
if (!isset($config['secretToken']) || $config['secretToken'] !== $token) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden', true, 403);
    die('<h2>Access denied</h2>');
}

if($config["runInBackground"] === true) {
    $branch = $config['branch'];
    $descriptorspec = [
        ['pipe', 'r'],               // stdin
        ['file', "$siteName-$branch.txt", 'a'], // stdout
        ['pipe', 'w'],               // stderr
    ];

    $proc = proc_open('php background_runner.php '. $siteName .' &', $descriptorspec, $pipes);
    die("Running in background...");
}

$logger = new HTMLLogger();
$logger->log("Started ".date("D M d, Y G:i"));
$config["siteName"] = $siteName;
run($config, $logger);
$logger->log("Finished ".date("D M d, Y G:i"));