<?php
/**
 * Simple PHP Git deploy script
 *
 * Automatically deploy the code using PHP and Git.
 *
 * @version 1.4
 * @link    https://github.com/nickswalker/simple-php-git-deploy/
 */

include_once "functions.php";

$siteName = isset($_GET['site']) ? $_GET['site'] : "";
$token = isset($_GET['sat']) ? $_GET['sat'] : "";

// Load configuration
$config = loadConfiguration($siteName);


// If there's authorization error, set the correct HTTP header.
if (!isset($config['secretToken']) || $config['secretToken'] !== $token) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden', true, 403);
    die('<h2>ACCESS DENIED!</h2>');
}

if($config["runInBackground"] === true) {
    $descriptorspec = array(
        array('pipe', 'r'),               // stdin
        array('file', 'lastbackgrounddeploy.txt', 'a'), // stdout
        array('pipe', 'w'),               // stderr
    );

    $proc = proc_open('php background_runner.php '. $siteName .' &', $descriptorspec, $pipes);
    die("Running in background...");
}


run($config);
