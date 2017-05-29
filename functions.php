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


function run($config)
{
    /**
     * Check the site parameter to see which site we're trying to deploy.
     *
     * It's preferable to configure the script using `deploy-config.php` file.
     *
     * Rename `deploy-config.example.php` to `deploy-config.php` and edit the
     * configuration options there instead of here. That way, you won't have to edit
     * the configuration again if you download the new version of `deploy.php`.
     */

    ignore_user_abort(true);

    ob_start();
    global $html_preamble;
    echo $html_preamble;
    //echo "Using config found in ".$site_config_filename."\n";
    checkEnvironment($config);

    $remote = $config["remoteRepository"];
    $branch = $config["branch"];
    $targetDir = $config["targetDir"];

    echo <<<END

Deploying $remote $branch
to $targetDir ...

END;

    $commands = makeCommands($config);

    executeCommands($commands, $config);
}


function checkEnvironment($config) {
    $user = trim(shell_exec('whoami'));
    echo "<pre>Checking the environment ...\n";
    echo "Running as <b>" . $user . "</b>.\n";


    // Check if the required programs are available
    // Verify that backup directory is configured properly
    $requiredBinaries = array('git', 'rsync');
    if ($config["backup"]["enabled"] !== false) {
        $requiredBinaries[] = 'tar';
        $backup_dir = $config["backup"]["destination"];
        if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
            errorIfServer();
            die(sprintf('<div class="error">BACKUP_DIR `%s` does not exists or is not writeable.</div>', $backup_dir));
        }
    }
    if ($config["composer"]["enabled"] === true) {
        $requiredBinaries[] = 'composer --no-ansi';
    }
    if ($config["jekyll"]["enabled"] === true) {
        $requiredBinaries[] = 'jekyll';
    }
    foreach ($requiredBinaries as $command) {
        $path = trim(shell_exec('which ' . $command));
        if ($path == '') {
            errorIfServer();
            die(sprintf('<div class="error"><b>%s</b> not available. It needs to be installed on the server for this script to work.</div>', $command));
        } else {
            $version = explode("\n", shell_exec($command . ' --version'));
            printf('<b>%s</b> : %s' . "\n", $path, $version[0]);
        }
    }
    echo "Environment OK.";
}
function makeCommands($config){
    $branch = $config["branch"];
    $remoteRepo = $config["remoteRepository"];
    $tmpDir = '/tmp/spgd-'.md5($remoteRepo.$branch).'/';
    $commands = array();

    if (!is_dir($tmpDir)) {
        // Clone the repository into the $tmpDir
        $commands[] = sprintf(
            'git clone --depth=1 --branch %s %s %s'
            , $branch
            , $remoteRepo
            , $tmpDir
        );
    } else {
        // $tmpDir exists and hopefully already contains the correct remote origin
        // so we'll fetch the changes and reset the contents.
        $commands[] = sprintf(
            'git --git-dir="%s.git" --work-tree="%s" fetch --tags origin %s'
            , $tmpDir
            , $tmpDir
            , $branch
        );
        $commands[] = sprintf(
            'git --git-dir="%s.git" --work-tree="%s" reset --hard FETCH_HEAD'
            , $tmpDir
            , $tmpDir
        );
    }

    // Update the submodules
    $commands[] = sprintf(
        'git submodule update --init --recursive'
    );

    // Describe the deployed version
    if ($config["createVersionFile"]) {
        $commands[] = sprintf(
            'git --git-dir="%s.git" --work-tree="%s" describe --always > %s'
            , $tmpDir
            , $tmpDir
            , "sgd-version.txt"
        );
    }

    // Backup the TARGET_DIR
    // without the BACKUP_DIR for the case when it's inside the TARGET_DIR
    if ($config["backup"]["enabled"]) {
        $backupDir = $config["backup"]["destination"];
        $targetDir = $config["targetDir"];
        $commands[] = sprintf(
            "tar --exclude='%s*' -czf %s/%s-%s-%s.tar.gz %s*"
            , $backupDir
            , $backupDir
            , basename($targetDir)
            , md5($targetDir)
            , date('YmdHis')
            , $targetDir // We're backing up this directory into BACKUP_DIR
        );
    }

    // Invoke composer
    if ($config["composer"]["enabled"] === true) {
        $commands[] = sprintf(
            'composer --no-ansi --no-interaction --no-progress --working-dir=%s install %s'
            , $tmpDir
            , $config["composer"]["options"]
        );
        if ($config["composer"]["home"]) {
            putenv('COMPOSER_HOME='.$config["composer"]["home"]);
        }
    }

    // Invoke Jekyll
    if ($config["jekyll"]["enabled"] === true) {

        $commands[] = sprintf('JEKYLL_ENV=production bundle install');

        $commands[] = sprintf('JEKYLL_ENV=production jekyll build %s', $config["jekyll"]["options"]);
        // Move results out
        $commands[] = sprintf("mv %s_site %s..", $tmpDir, $tmpDir);
        // Clear the tmp dir
        $commands[] = sprintf('rm -rf %s* ', $tmpDir);
        // Move build results into the tmp dir
        $commands[] = sprintf('mv  %s../_site/* %s', $tmpDir, $tmpDir);

    }

    // Compile exclude parameters
    $exclude = '';
    foreach ($config['exclude'] as $exc) {
        $exclude .= ' --exclude='.$exc;
    }
    // Deployment command
    $commands[] = sprintf(
        'rsync -rltgoDzvO %s %s %s %s'
        , $tmpDir
        , $config["targetDir"]
        , ($config["deleteFiles"]) ? '--delete-after' : ''
        , $exclude
    );

    // Post Deploment

    // Remove the $tmpDir (depends on CLEAN_UP)
    if ($config["cleanup"]) {
        $commands['cleanup'] = sprintf('rm -rf %s', $tmpDir);
    }
    return $commands;
}
function executeCommands($commands, $config) {
    $tmpDir = '/tmp/spgd-'.md5($config["remoteRepository"].$config["branch"]).'/';
    $output = '';
    foreach ($commands as $command) {
        set_time_limit($config["timeLimit"]); // Reset the time limit for each command

        if (file_exists($tmpDir) && is_dir($tmpDir)) {
            chdir($tmpDir); // Ensure that we're in the right directory
        }
        $tmp = array();
        exec($command.' 2>&1', $tmp, $return_code); // Execute the command
        // Output the result
        printf('
            <span class="prompt">$</span> <span class="command">%s</span>
            <div class="output">%s</div>
            '
            , htmlentities(trim($command))
            , htmlentities(trim(implode("\n", $tmp)))
        );
        $output .= ob_get_contents();
        ob_flush(); // Try to output everything as it happens

        // Error handling and cleanup
        if ($return_code !== 0) {
            errorIfServer();
            printf('
            <div class="error">
            Error encountered!
            Stopping the script to prevent possible data loss.
            CHECK THE DATA IN YOUR TARGET DIR!
            </div>
            '
            );
            if ($config["cleanup"]) {
                $tmp = shell_exec($commands['cleanup']);
                printf('Cleaning up temporary files ...

<span class="prompt">$</span> <span class="command">%s</span>
<div class="output">%s</div>
'
                    , htmlentities(trim($commands['cleanup']))
                    , htmlentities(trim($tmp))
                );
            }
            $error = sprintf('Deployment error on %s using %s!', $config["hostname"], __FILE__);
            error_log($error);
            if ($config["emailOnError"]) {
                $output .= ob_get_contents();
                $headers = array();
                $headers[] = sprintf('From: Simple PHP Git deploy script <simple-php-git-deploy@%s>', $config["hostname"]);
                $headers[] = sprintf('X-Mailer: PHP/%s', phpversion());
                mail($config["emailOnError"], $error, strip_tags(trim($output)), implode("\r\n", $headers));
            }
            break;
        }
    }
    echo <<<END
        Done.
</pre>
</body>
</html>
END;
}

function errorIfServer($message) {
    if (defined("SERVER")) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
    }
}

function loadConfiguration($siteName) {
    // Read in defaults
    $configFilename = 'config/default.json';
    if (!file_exists($configFilename)) {
        die("Can't find " . $configFilename);
    }
    $contents = file_get_contents($configFilename);
    $config = json_decode($contents, true);
// Overlay shared configuration, if it exists
    $sharedConfigFilename = 'config/shared.json';
    if (file_exists($sharedConfigFilename)) {
        $sharedConfig = json_decode(file_get_contents($sharedConfigFilename), true);
        $config = array_replace_recursive($config, $sharedConfig);
    }

// Get the site specific configuration
    $site_config_filename = 'config/' . $siteName . '.json';
    if (file_exists($site_config_filename)) {
        $site_config = json_decode(file_get_contents($site_config_filename), true);
        $config = array_replace_recursive($config, $site_config);
    }
    return $config;
}
