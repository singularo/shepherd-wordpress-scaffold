<?php

/**
 * @file
 * Contains \Robo\RoboFileBase.
 *
 * Implementation of class for Robo - http://robo.li/
 */

/**
 * Class RoboFile.
 */
abstract class RoboFileBase extends \Robo\Tasks {

  protected $wp_cli_cmd;
  protected $local_user;
  protected $sudo_cmd;

  protected $wp_cli_bin = "bin/wp";
  protected $composer_bin = "composer";

  protected $php_enable_module_command = 'phpenmod -v ALL';
  protected $php_disable_module_command = 'phpdismod -v ALL';

  protected $web_server_user = 'www-data';

  protected $application_root = "web";

  protected $config = [];

  /**
   * Initialize config variables and apply overrides.
   */
  public function __construct() {
    $this->wp_cli_cmd = "$this->wp_cli_bin --path=$this->application_root";
    $this->sudo_cmd = posix_getuid() == 0 ? '' : 'sudo';
    $this->local_user = $this->getLocalUser();

    // Read config from env vars.
    $environment_config = $this->readConfigFromEnv();
    $this->config = array_merge($this->config, $environment_config);
    if (!isset($this->config['database']['username'])) {
      echo "Database config is missing.\n";
    }
  }

  /**
   * Force projects to declare which install profile to use.
   *
   * I.e. return 'some_profile'.
   */
  protected function getDrupalProfile() {
    $profile = getenv('SHEPHERD_INSTALL_PROFILE');
    if (empty($profile)) {
      $this->say("Install profile environment variable is not set.\n");
      exit(1);
    }
    return $profile;
  }

  /**
   * Returns known configuration from environment variables.
   *
   * Runs during the constructor; be careful not to use Robo methods.
   */
  protected function readConfigFromEnv() {
    $config = [];

    // Site.
    $config['site']['title']            = getenv('SITE_TITLE');
    $config['site']['mail']             = getenv('SITE_MAIL');
    $config['site']['admin_email']      = getenv('SITE_ADMIN_EMAIL');
    $config['site']['admin_user']       = getenv('SITE_ADMIN_USERNAME');
    $config['site']['admin_password']   = getenv('SITE_ADMIN_PASSWORD');
    $config['site']['url']              = getenv('VIRTUAL_HOST');

    // Environment.
    $config['environment']['hash_salt']       = getenv('HASH_SALT');
    $config['environment']['config_sync_dir'] = getenv('CONFIG_SYNC_DIRECTORY');

    // Databases.
    $config['database']['database']  = getenv('DATABASE_NAME');
    $config['database']['driver']    = getenv('DATABASE_DRIVER');
    $config['database']['host']      = getenv('DATABASE_HOST');
    $config['database']['port']      = getenv('DATABASE_PORT');
    $config['database']['username']  = getenv('DATABASE_USER');
    $config['database']['password']  = getenv('DATABASE_PASSWORD');
    $config['database']['namespace'] = getenv('DATABASE_NAMESPACE');
    $config['database']['prefix']    = getenv('DATABASE_PREFIX');

    // Clean up NULL values and empty arrays.
    $array_clean = function (&$item) use (&$array_clean) {
      foreach ($item as $key => $value) {
        if (is_array($value)) {
          $array_clean($item[$key]);
        }
        if (empty($item[$key]) && $value !== '0') {
          unset($item[$key]);
        }
      }
    };

    $array_clean($config);

    return $config;
  }

  /**
   * Perform a full build on the project.
   */
  public function build() {
    $start = new DateTime();
    $this->devComposerValidate();
    $this->buildMake();
    $this->buildSetFilesOwner();
    $this->buildInstall();
    $this->buildSetFilesOwner();
    $this->say('Total build duration: ' . date_diff(new DateTime(), $start)->format('%im %Ss'));
  }

  /**
   * Perform a build for automated deployments.
   *
   * Don't install anything, just build the code base.
   */
  public function distributionBuild() {
    $this->devComposerValidate();
    $this->buildMake('--no-dev --optimize-autoloader');
    $this->setSitePath();
  }

  /**
   * Validate composer files and installed dependencies with strict mode off.
   */
  public function devComposerValidate() {
    $this->taskComposerValidate()
      ->noCheckPublish()
      ->run()
      ->stopOnFail(TRUE);
  }

  /**
   * Run composer install to fetch the application code from dependencies.
   *
   * @param string $flags
   *   Additional flags to pass to the composer install command.
   */
  public function buildMake($flags = '') {
    $successful = $this->_exec("$this->composer_bin --no-progress $flags install")->wasSuccessful();

    $this->checkFail($successful, "Composer install failed.");
  }

  /**
   * Set the owner and group of all files in the files dir to the web user.
   */
  public function buildSetFilesOwner() {
  }

  /**
   * Clean config and files, then install Drupal and module dependencies.
   */
  public function buildInstall() {
    $this->devConfigWriteable();

    // @TODO: When is this really used? Automated builds - can be random values.
    $successful = $this->_exec("$this->wp_cli_cmd core install" .
        " --admin_email=\"" . $this->config['site']['admin_email'] . "\"" .
        " --admin_user=\"" .  $this->config['site']['admin_user'] . "\"" .
        " --title=\"" .       $this->config['site']['title'] . "\"" .
        " --url=\"" .         $this->config['site']['url'] . "\"")
      ->wasSuccessful();

    // Re-set settings.php permissions.
    $this->devConfigReadOnly();

    $this->checkFail($successful, 'wp core install failed.');

    $this->devCacheRebuild();
  }


  /**
   * Clean the application root in preparation for a new build.
   */
  public function buildClean() {
    $this->setPermissions("$this->application_root/sites/default", '0755');
    $this->_exec("$this->sudo_cmd rm -fR $this->application_root/wp-admin");
    $this->_exec("$this->sudo_cmd rm -fR $this->application_root/wp-content");
    $this->_exec("$this->sudo_cmd rm -fR $this->application_root/wp-includes");
    $this->_exec("$this->sudo_cmd rm -fR bin");
    $this->_exec("$this->sudo_cmd rm -fR vendor");
  }

  /**
   * Perform cache clear in the app directory.
   */
  public function devCacheRebuild() {
    $successful = $this->_exec("$this->wp_cli_cmd cache flush")->wasSuccessful();

    $this->checkFail($successful, 'wp_cli cache flush failed.');
  }

  /**
   * Ask a couple of questions and then configure git.
   */
  public function devInit() {
    $this->say("Initial project setup. Adds user details to gitconfig.");
    $git_name  = $this->ask("Enter your Git name (e.g. Bob Rocks):");
    $git_email = $this->ask("Enter your Git email (e.g. bob@rocks.adelaide.edu.au):");
    $this->_exec("git config --global user.name \"$git_name\"");
    $this->_exec("git config --global user.email \"$git_email\"");

    // Automatically initialise git flow.
    $git_config = file_get_contents('.git/config');
    if (!strpos($git_config, '[gitflow')) {
      $this->taskWriteToFile(".git/config")
        ->append()
        ->text("\n[gitflow \"branch\"]\n" .
          "        master = master\n" .
          "        develop = develop\n" .
          "[gitflow \"prefix\"]\n" .
          "        feature = feature/\n" .
          "        release = release/\n" .
          "        hotfix = hotfix/\n" .
          "        support = support/\n" .
          "        versiontag = \n")
        ->run();
    }
  }

  /**
   * Install Adminer for database administration.
   */
  public function devInstallAdminer() {
    $this->taskFilesystemStack()
      ->remove("$this->application_root/adminer.php")
      ->run();

    $this->taskExec("wget -q -O adminer.php http://www.adminer.org/latest-mysql-en.php")
      ->dir($this->application_root)
      ->run();
  }

  /**
   * CLI debug enable.
   */
  public function devXdebugEnable() {
    $this->_exec("sudo $this->php_enable_module_command -s cli xdebug");
  }

  /**
   * CLI debug disable.
   */
  public function devXdebugDisable() {
    $this->_exec("sudo $this->php_disable_module_command -s cli xdebug");
  }

  /**
   * Make config files write-able.
   */
  public function devConfigWriteable() {
  }

  /**
   * Make config files read only.
   */
  public function devConfigReadOnly() {
  }

  /**
   * Imports a database, updates the admin user password and applies updates.
   *
   * @param string $sql_file
   *   Path to sql file to import.
   */
  public function devImportDb($sql_file) {
    $start = new DateTime();
    $this->_exec("$this->wp_cli_cmd db drop");
    $this->_exec("$this->wp_cli_cmd import $sql_file");
    $this->_exec("$this->wp_cli_cmd cr");
    $this->_exec("$this->wp_cli_cmd user create admin admin@test.com --user_pass=password");
    $this->say('Duration: ' . date_diff(new DateTime(), $start)->format('%im %Ss'));
    $this->say('Database imported, admin user password is : password');
  }

  /**
   * Exports a database and gzips the sql file.
   *
   * @param string $name
   *   Name of sql file to be exported.
   */
  public function devExportDb($name = 'dump') {
    $start = new DateTime();
    $this->_exec("$this->wp_cli_cmd export $name.sql");
    $this->say("Duration: " . date_diff(new DateTime(), $start)->format('%im %Ss'));
    $this->say("Database $name.sql.gz exported");
  }

  /**
   * Check if file exists and set permissions.
   *
   * @param string $file
   *   File to modify.
   * @param string $permission
   *   Permissions. E.g. '0644'.
   */
  protected function setPermissions($file, $permission) {
    if (file_exists($file)) {
      $this->_exec("$this->sudo_cmd chmod $permission $file");
    }
  }

  /**
   * Return the name of the local user.
   *
   * @return string
   *   Returns the current user.
   */
  protected function getLocalUser() {
    $user = posix_getpwuid(posix_getuid());
    return $user['name'];
  }

  /**
   * Helper function to check whether a task has completed successfully.
   *
   * @param bool $successful
   *   Task ran successfully or not.
   * @param string $message
   *   Optional: A helpful message to print.
   */
  protected function checkFail($successful, $message = '') {
    if (!$successful) {
      $this->say('APP_ERROR: ' . $message);
      // Prevent any other tasks from executing.
      exit(1);
    }
  }

}
