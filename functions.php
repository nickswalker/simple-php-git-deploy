<?php


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
        $lock = obtainWorkingDirectory($workingDir);
    } catch (Exception $e) {
        die($e->getMessage());
    }
    $commands = makeCommands($config, $workingDir);

    $logger->log("Deploying $remote $branch to $targetDir ...");
    try{
        executeCommands($commands, $workingDir, $config, $logger);
    } catch (Exception $e) {
        $logger->log($e->getMessage());
        errorIfServer();

        if ($config["cleanUp"]) {
            $tmp = shell_exec($commands['cleanUp']);
            $logger->log("Cleaning up temporary files ...");
            $logger->log(sprintf("%s %s", trim($commands['cleanUp']), trim($tmp)));
        }
        $error = sprintf('Deployment error on %s using %s!', $config["hostname"], __FILE__);
        error_log($error);
        if ($config["email"]["enabled"]) {
            $headers = [];
            $headers[] = sprintf('From: Simple PHP Git deploy script <simple-php-git-deploy@%s>', $config["hostname"]);
            $headers[] = sprintf('X-Mailer: PHP/%s', phpversion());
            mail($config["email"]["to"], $error, strip_tags(trim($logger->getLog())), implode("\r\n", $headers));
        }
        releaseWorkingDirectory($lock);
        die($e->getMessage());
    }
    releaseWorkingDirectory($lock);
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

function obtainWorkingDirectory($workingDirectory) {
    $workingBase = basename($workingDirectory);
    $lockPath = "/tmp/$workingBase.lock";
    touch($lockPath);
    $lock = fopen($lockPath, "r+");
    $startStamp = microtime(true);
    $timeout = 60 * 5;
    // Poll the lock to see if we can obtain it, or wait until it no longer exists
    while (true) {
        $obtained = flock($lock, LOCK_EX | LOCK_NB);
        if ($obtained) {
            break;
        }
        $elapsed = microtime(true) - $startStamp;
        if ($elapsed > $timeout) {
            throw new Exception("Timed out while trying to get directory lock");
        }
    }
    return $lock;

}

function releaseWorkingDirectory($lockFile) {
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
}

function makeCommands($config, $tmpDir){
    $commands = [];
    $branch = $config["branch"];
    $remoteRepo = $config["remoteRepository"];
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
        $commands[] = sprintf('export PATH=$PATH;JEKYLL_ENV=production bundle install --deployment');

        $commands[] = sprintf('export PATH=$PATH;JEKYLL_ENV=production bundle exec jekyll build %s', $config["jekyll"]["options"]);
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
    if ($config["cleanUp"]) {
        $commands['cleanUp'] = sprintf('rm -rf %s', $tmpDir);
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

        // Error handling and cleanup
        if ($return_code !== 0) {
            throw new Exception("$command failed with exit code $return_code");
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
