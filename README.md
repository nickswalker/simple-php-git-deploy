# Simple PHP Git deploy script

Automatically deploy code using PHP and Git. This is a fork of Marko Marković's [original project](https://github.com/markomarkovic/simple-php-git-deploy), which adds support for deploying Jekyll sites and improves support for using the script to deploy multiple sites.

## Requirements

* `git` and `rsync` are required on the server that's running the script
  (_server machine_).
  - Optionally, `tar` is required for backup functionality (`BACKUP_DIR` option).
  - Optionally, `composer` is required for composer functionality (`USE_COMPOSER`
  option).
  - Optionally, `jekyll` is required for jekyll functionality (`USE_JEKYLL`
    option).
* The system user running PHP (e.g. `www-data`) needs to have the necessary
  access permissions for the `TMP_DIR` and `TARGET_DIR` locations on
  the _server machine_.
* If the Git repository you wish to deploy is private, the system user running PHP
  also needs to have the right SSH keys to access the remote repository.

## Usage

### Initial Setup
 * Put the script somewhere that's accessible from the
   Internet.

### Configure a New Deployment
 * Rename `deploy-config.example.php` to `<site-name>-config.php` and edit the
   configuration options there. Keeping your configurations in this file ensures that you can safely update `deploy.php` later.
 * Ensure that you have a unique secret key set in the configuration file.
 * Configure your git repository to call `deploy.php` when the code is updated.
   The instructions for GitHub and Bitbucket are below.

### GitHub

 1. _(This step is only needed for private repositories)_ Go to
    `https://github.com/USERNAME/REPOSITORY/settings/keys` and add your server
    SSH key.
 1. Go to `https://github.com/USERNAME/REPOSITORY/settings/hooks`.
 1. Click **Add webhook** in the **Webhooks** panel.
 1. Enter the **Payload URL** for your deployment script e.g. `http://example.com/deploy.php?sat=YourSecretAccessTokenFromDeployFile?site=<site-name>`.
 1. _Optional_ Choose which events should trigger the deployment.
 1. Make sure that the **Active** checkbox is checked.
 1. Click **Add webhook**.

### Bitbucket

 1. _(This step is only needed for private repositories)_ Go to
    `https://bitbucket.org/USERNAME/REPOSITORY/admin/deploy-keys` and add your
    server SSH key.
 1. Go to `https://bitbucket.org/USERNAME/REPOSITORY/admin/services`.
 1. Add **POST** service.
 1. Enter the URL to your deployment script e.g. `http://example.com/deploy.php?sat=YourSecretAccessTokenFromDeployFile?site=<site-name>`.
 1. Click **Save**.

### Generic Git

 1. Configure the SSH keys.
 1. Add a executable `.git/hooks/post_receive` script that calls the script e.g.

```sh
#!/bin/sh
echo "Triggering the code deployment ..."
wget -q -O /dev/null http://example.com/deploy.php?sat=YourSecretAccessTokenFromDeployFile?site=<site-name>
```

## Done!

Next time you push the code to the repository that has a hook enabled, it's
going to trigger the `deploy.php` script which is going to pull the changes and
update the code on the _server machine_.

For more info, read the source of `deploy.php`.

## Tips'n'Tricks

 * Because `rsync` is used for deployment, the `TARGET_DIR` doesn't have to be
   on the same server that the script is running e.g. `define('TARGET_DIR',
   'username@example.com:/full/path/to/target_dir/');` is going to work as long
   as the user has the right SSH keys and access permissions.

