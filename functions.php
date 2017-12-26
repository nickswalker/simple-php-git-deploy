<?php

$DEPLOY_TIMEOUT = 60 * 10;

function run($config, $logger){
    ignore_user_abort(true);
    //echo "Using config found in ".$site_config_filename."\n";
    try {
        checkEnvironment($config, $logger);
    } catch (Exception $e) {
        die($e->getMessage());
    }
    $remote = $config["remoteRepository"];
    $branch = $config["branch"];
    $targetDir = $config["targetDir"];
    $workingDir = "/tmp/spgd-".$config["siteName"]."-".$branch.md5($remote.$branch).'/';


    $logger->log("Obtaining working directory lock...");
    try{
        $workingBase = basename($workingDir);
        $workingDirLock = obtainLock($workingBase);
    } catch (Exception $e) {
        die($e->getMessage());
    }
    $commands = makeCommands($config, $workingDir);

    $logger->log("Deploying $remote $branch to $targetDir ...");
    try{
        if ($config["oneAtATime"]) {
            $logger->log("Obtaining deploy lock...");
            $deployLock = obtainLock("deploy");
        }
        executeCommands($commands, $workingDir, $config, $logger);
    } catch (Exception $e) {
        if ($config["oneAtATime"] && $deployLock) {
            releaseLock($deployLock);
        }
        $logger->log($e->getMessage());
        errorIfServer();

        if ($config["cleanUp"]) {
            $tmp = shell_exec($commands['cleanUp']);
            $logger->log("Cleaning up temporary files ...");
            $logger->log(sprintf("%s %s", trim($commands['cleanUp']), trim($tmp)));
        }
        $error = sprintf('Deployment error on %s using %s!', $config["hostname"], __FILE__);

        $logger->log($error);
        if ($config["email"]["enabled"]) {
            $headers = [];
            $headers[] = "From: Simple PHP Git deploy script <simple-php-git-deploy@${config['hostname']}>";
            $headers[] = sprintf('X-Mailer: PHP/%s', phpversion());
            mail($config["email"]["to"], $error, strip_tags(trim($logger->getLog())), implode("\r\n", $headers));
        }
        releaseLock($workingDirLock);
        die();
    }
    releaseLock($workingDirLock);
}


function checkEnvironment($config, $logger) {
    $user = trim(shell_exec('whoami'));
    $logger->log("Checking the environment ...\n");
    $logger->log("Running as $user.\n");


    // Check if the required programs are available
    // Verify that backup directory is configured properly
    $requiredBinaries = ['git', 'rsync'];
    if ($config["backup"]["enabled"] !== false) {
        $requiredBinaries[] = 'tar';
        $backup_dir = $config["backup"]["destination"];
        if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
            errorIfServer();
            throw new Exception("BACKUP_DIR $backup_dir does not exists or is not writeable.");
        }
    }
    if ($config["composer"]["enabled"] === true) {
        $requiredBinaries[] = 'composer --no-ansi';
    }
    if ($config["jekyll"]["enabled"] === true) {
        $requiredBinaries[] = 'ruby';
        $requiredBinaries[] = 'bundle';
    }
    if ($config['cloudflare']['enabled'] === true) {
        $requiredBinaries[] = 'curl';
    }
    foreach ($requiredBinaries as $command) {
        $path = trim(shell_exec('which ' . $command));
        if ($path == '') {
            errorIfServer();
            throw new Exception("$command not available. It needs to be installed on the server for this script to work.");
        } else {
            $version = explode("\n", shell_exec($command . ' --version'));
            $logger->log("$path : $version[0]");
        }
    }
    $logger->log("Environment OK.");
}

function obtainLock($name) {
    global $DEPLOY_TIMEOUT;
    $lockPath = "/tmp/$name.lock";
    touch($lockPath);
    $lock = fopen($lockPath, "r+");
    $startStamp = microtime(true);;
    // Poll the lock to see if we can obtain it, or wait until it no longer exists
    while (true) {
        $obtained = flock($lock, LOCK_EX | LOCK_NB);
        if ($obtained) {
            break;
        }
        $elapsed = microtime(true) - $startStamp;
        if ($elapsed > $DEPLOY_TIMEOUT) {
            throw new Exception("Timed out while trying to get directory lock");
        }
    }
    return $lock;

}

function releaseLock($lockFile) {
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
}

function makeCommands($config, $tmpDir){
    $commands = [];
    $branch = $config["branch"];
    $remoteRepo = $config["remoteRepository"];
    $toDeploy = $tmpDir;
    if (!is_dir($tmpDir)) {
        // Clone the repository into the $tmpDir
        $commands[] = "git clone --depth=1 --branch $branch $remoteRepo $tmpDir";
    } else {
        // $tmpDir exists and hopefully already contains the correct remote origin
        // so we'll fetch the changes and reset the contents.
        $commands[] = "git --git-dir='$tmpDir.git' --work-tree='$tmpDir' fetch --tags origin $branch";
        $commands[] = "git --git-dir='$tmpDir.git' --work-tree='$tmpDir' reset --hard FETCH_HEAD";

    }

    // Update the submodules
    $commands[] = 'git submodule update --init --recursive';


    // Describe the deployed version
    if ($config["createVersionFile"]) {
        $commands[] = "git --git-dir='$tmpDir.git' --work-tree='$tmpDir' describe --always > 'sgd-version.txt'";
        $dateStamp = date("D M d, Y G:i");
        $commands[] = "echo '\n$dateStamp' >> 'sgd-version.txt'";
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
        $composerOptions = $config['composer']['options'];
        $commands[] = "composer --no-ansi --no-interaction --no-progress --working-dir=$tmpDir install $composerOptions";

        if ($config["composer"]["home"]) {
            putenv('COMPOSER_HOME='.$config["composer"]["home"]);
        }
    }

    // Invoke Jekyll
    if ($config["jekyll"]["enabled"] === true) {
        $jekyllOptions = $config['jekyll']['options'];
        $commands[] = 'export PATH=$PATH;JEKYLL_ENV=production bundle install --deployment';
        $commands[] = "export PATH=\$PATH;JEKYLL_ENV=production bundle exec jekyll build $jekyllOptions";
        $toDeploy = "{$tmpDir}_site/";

    }

    // Compile exclude parameters
    $exclude = '';
    foreach ($config['exclude'] as $exc) {
        $exclude .= ' --exclude='.$exc;
    }




    // Deployment command
    $commands[] = sprintf(
        'rsync -rltgoDzvO %s %s %s %s'
        , $toDeploy
        , $config["targetDir"]
        , ($config["deleteFiles"]) ? '--delete-after' : ''
        , $exclude
    );

    // Post Deployment

    //https://api.cloudflare.com/#zone-purge-all-files
    if ($config['cloudflare']['enabled']) {
        $cfEmail = $config['cloudflare']['email'];
        $zone = $config['cloudflare']['zoneId'];
        $apiKey = $config['cloudflare']['apiKey'];
        $commands[] = "curl -X DELETE 'https://api.cloudflare.com/client/v4/zones/$zone/purge_cache' \
        -H 'Content-Type:application/json' \
        -H 'X-Auth-Key:$apiKey' \
        -H 'X-Auth-Email:$cfEmail' \
        -H 'Content-Type: application/json' \
        --data '{\"purge_everything\":true}'";
    }

    // Remove the $tmpDir (depends on CLEAN_UP)
    if ($config["cleanUp"]) {
        $commands['cleanUp'] = "rm -rf $tmpDir";
    }
    return $commands;
}

function executeCommands($commands, $workingDir, $config, $logger){
    foreach ($commands as $command) {
        set_time_limit($config["timeLimit"]); // Reset the time limit for each command

        if (file_exists($workingDir) && is_dir($workingDir)) {
            chdir($workingDir); // Ensure that we're in the right directory
        }
        $tmp = [];
        $logger->log( trim($command));
        exec($command . ' 2>&1', $tmp, $return_code); // Execute the command
        // Output the result
        $logger->log(trim(implode("\n", $tmp)));
        $logger->log("Returned $return_code");
        // Error handling and cleanup
        if ($return_code !== 0) {
            throw new Exception("Command failed");
        }
    }

    $logger->log("Done");
}

function errorIfServer() {
    if (defined("SERVER")) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
    }
}

function loadConfiguration($siteName) {
    // Read in defaults
    $configFilename = 'config/default.json';
    if (!file_exists($configFilename)) {
       return false;
    }
    $contents = file_get_contents($configFilename);
    $config = json_decode($contents, true);
    if (!$config) {
        throw new Exception("Couldn't parse $config");
    }
    // Overlay shared configuration, if it exists
    $sharedConfigFilename = 'config/shared.json';
    if (file_exists($sharedConfigFilename)) {
        $sharedConfig = json_decode(file_get_contents($sharedConfigFilename), true);
        if (!$sharedConfig) {
            throw new Exception("Couldn't parse $sharedConfigFilename");
        }
        $config = array_replace_recursive($config, $sharedConfig);
    }

    // Get the site specific configuration
    $site_config_filename = 'config/' . $siteName . '.json';
    if (file_exists($site_config_filename)) {
        $site_config = json_decode(file_get_contents($site_config_filename), true);
        if (!$site_config) {
            throw new Exception("Couldn't parse $site_config_filename");
        }
        $config = array_replace_recursive($config, $site_config);
    }
    return $config;
}
