<?php

namespace UniversityOfAdelaide\ShepherdWordpressScaffold;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem as ComposerFilesystem;
use Symfony\Component\Filesystem\Filesystem;

class Handler
{

    /**
     * @var \Composer\Composer
     */
    protected $composer;

    /**
     * @var \Composer\IO\IOInterface
     */
    protected $io;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected $filesystem;

    /**
     * Handler constructor.
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->filesystem = new Filesystem();
    }

    /**
    * Post update command event to execute the scaffolding.
    *
    * @param \Composer\Script\Event $event
    */
    public function onPostCmdEvent(\Composer\Script\Event $event)
    {
        $event->getIO()->write("Updating Shepherd scaffold files.");
        $this->updateShepherdScaffoldFiles();
        $event->getIO()->write("Creating wp-config.php file if not present.");
        $this->modifySettingsFile();
        $event->getIO()->write("Removing write permissions on settings files.");
        $this->removeWritePermissions();
    }

    /**
     * Update the Shepherd scaffold files.
     */
    public function updateShepherdScaffoldFiles()
    {
        $packagePath = $this->getPackagePath();
        $projectPath = $this->getProjectPath();

        // Always copy and replace these files.
        $this->copyFiles(
            $packagePath,
            $projectPath,
            [
                'dsh',
                'RoboFileBase.php',
            ],
            true
        );

        // Only copy these files if they do not exist at the destination.
        $this->copyFiles(
            $packagePath,
            $projectPath,
            [
                'docker-compose.yml',
                'dsh_proxy.conf',
                'RoboFile.php',
            ]
        );
    }


    /**
     * Create wp-config.php file and inject Shepherd-specific settings.
     *
     * Note: does nothing if the file already exists.
     */
    public function modifySettingsFile()
    {
        $root = $this->getWordpressRootPath();

        // Assume Wordpress scaffold created the wp-config.php
        $this->filesystem->chmod($root . '/wp-config.php', 0664);

        // If we haven't already written to wp-config.php.
        if (!(strpos(file_get_contents($root . '/wp-config.php'), 'START SHEPHERD CONFIG') !== false)) {
            $shepherdSettings = "
<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to \"wp-config.php\" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', getenv('DATABASE_NAME') );

/** MySQL database username */
define( 'DB_USER', getenv('DATABASE_USER') );

/** MySQL database password */
\$db_password = getenv('DATABASE_PASSWORD_FILE') ? file_get_contents(getenv('DATABASE_PASSWORD_FILE')) : getenv('DATABASE_PASSWORD');
define( 'DB_PASSWORD', \$db_password );

/** MySQL hostname */
define( 'DB_HOST', getenv('DATABASE_HOST') );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
define( 'NONCE_KEY',        'put your unique phrase here' );
define( 'AUTH_SALT',        'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
define( 'NONCE_SALT',       'put your unique phrase here' );

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
\$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

/** Sets up WordPress vars and included files. */
require_once( ABSPATH . 'wp-settings.php' );
";

            // Write Shepherd-specific wp-config.php.
            file_put_contents(
                $root.'/wp-config.php',
                $shepherdSettings
            );
        }
    }

    /**
     * Remove all write permissions on Wordpress configuration files and folder.
     */
    public function removeWritePermissions()
    {
        $root = $this->getWordpressRootPath();
        $this->filesystem->chmod($root . '/wp-config.php', 0444);
    }

    /**
     * Copy files from origin to destination, optionally overwriting existing.
     *
     * @param bool $overwriteExisting
     *  If true, replace existing files. Defaults to false.
     */
    public function copyFiles($origin, $destination, $filenames, $overwriteExisting = false)
    {
        foreach ($filenames as $filename) {
            // Skip copying files that already exist at the destination.
            if (! $overwriteExisting && $this->filesystem->exists($destination . '/' . $filename)) {
                continue;
            }
            $this->filesystem->copy(
                $origin . '/' . $filename,
                $destination . '/' . $filename,
                true
            );
        }
    }

    /**
     * Get the path to the vendor directory.
     *
     * E.g. /home/user/code/project/vendor
     *
     * @return string
     */
    public function getVendorPath()
    {
        // Load ComposerFilesystem to get access to path normalisation.
        $composerFilesystem = new ComposerFilesystem();

        $config = $this->composer->getConfig();
        $composerFilesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $vendorPath = $composerFilesystem->normalizePath(realpath($config->get('vendor-dir')));

        return $vendorPath;
    }

    /**
     * Get the path to the project directory.
     *
     * E.g. /home/user/code/project
     *
     * @return string
     */
    public function getProjectPath()
    {
        $projectPath = dirname($this->getVendorPath());
        return $projectPath;
    }

    /**
     * Get the path to the package directory.
     *
     * E.g. /home/user/code/project/vendor/singularo/shepherd-wordpress-scaffold
     *
     * @return string
     */
    public function getPackagePath()
    {
        $packagePath = $this->getVendorPath() . '/singularo/shepherd-wordpress-scaffold';
        return $packagePath;
    }

    /**
     * Get the path to the Wordpress root directory.
     *
     * E.g. /home/user/code/project/web
     *
     * @return string
     */
    public function getWordpressRootPath()
    {
        $wordpressRootPath = $this->getProjectPath() . '/web';
        return $wordpressRootPath;
    }
}
