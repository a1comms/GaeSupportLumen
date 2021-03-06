<?php namespace Shpasser\GaeSupportLumen\Setup;

use Illuminate\Console\Command;
use Artisan;

/**
 * Class Configurator
 *
 * @package Shpasser\GaeSupportLumen\Setup
 */

class Configurator {

    protected $myCommand;

    /**
     * Constructs a new instance of Configurator class.
     *
     * @param Command $myCommand console
     * command to be used for console output.
     */
    public function __construct(Command $myCommand)
    {
        $this->myCommand = $myCommand;
    }

    /**
     * Configures a Laravel app to be deployed on GAE.
     *
     * @param string $appId the GAE application ID.
     * @param bool $generateConfig if 'true' => generate GAE config files(app.yaml and php.ini).
     * @param string $bucketId the custom GCS bucket ID, if 'null' the default bucket is used.
     * @param string $dbSocket Cloud SQL socket connection string.
     * @param string $dbName Cloud SQL database name.
     * @param string $dbHost Cloud SQL host IPv4 address.
     */
    public function configure($appId, $generateConfig, $bucketId,
                              $dbSocket, $dbName, $dbHost)
    {
        $env_file               = base_path().'/.env';
        $env_production_file    = base_path().'/.env.production';
        $env_local_file         = base_path().'/.env.local';
        $bootstrap_app_php      = base_path().'/bootstrap/app.php';
        $config_view_php        = base_path().'/config/view.php';
        $config_queue_php       = base_path().'/config/queue.php';
        $config_database_php    = base_path().'/config/database.php';
        $config_filesystems_php = base_path().'/config/filesystems.php';

        $this->createEnvProductionFile($env_file, $env_production_file, $dbSocket, $dbName);
        $this->createEnvLocalFile($env_file, $env_local_file, $dbHost, $dbName);
        $this->processFile($bootstrap_app_php, ['replaceAppClass']);

        $this->copyLumenConfigFile('view.php');
        $this->processFile($config_view_php, ['replaceCompiledPath']);

        $this->copyLumenConfigFile('queue.php');
        $this->processFile($config_queue_php, ['addQueueConfig']);

        $this->copyLumenConfigFile('database.php');
        $this->processFile($config_database_php, ['addCloudSqlConfig']);

        $this->copyLumenConfigFile('filesystems.php');
        $this->processFile($config_filesystems_php, ['addGaeDisk']);

        if ($generateConfig)
        {
            $app_yaml   = base_path().'/app.yaml';
            $publicPath = base_path().'/public';
            $php_ini    = base_path().'/php.ini';

            $this->generateAppYaml($appId, $app_yaml, $publicPath);
            $this->generatePhpIni($appId, $bucketId, $php_ini);
        }
    }

    /**
     * Creates a '.env.production' file based on the existing '.env' file.
     *
     * @param string $env_file The '.env' file path.
     * @param string $env_production_file The '.env.production' file path.
     * @param string $dbSocket Cloud SQL socket connection string.
     * @param string $dbName Cloud SQL database name.
     */
    protected function createEnvProductionFile($env_file, $env_production_file, $dbSocket, $dbName)
    {
        if (!file_exists($env_file))
        {
            $this->myCommand->error('Cannot find ".env" file to import the existing options.');
            return;
        }

        if (file_exists($env_production_file))
        {
            $overwrite = $this->myCommand->confirm(
                'Overwrite the existing ".env.production" file?', false
            );

            if ( ! $overwrite)
            {
                return;
            }
        }

        $env = new IniHelper;
        $env->read($env_file);

        $env['APP_ENV']   = 'production';
        $env['APP_DEBUG'] = 'false';

        $env['CACHE_DRIVER']   = 'memcached';
        $env['SESSION_DRIVER'] = 'memcached';

        $env['MAIL_DRIVER']  = 'gae';
        $env['QUEUE_DRIVER'] = 'gae';
        $env['FILESYSTEM'] = 'gae';

        if (( ! is_null($dbSocket)) && ( ! is_null($dbName)))
        {
            $env['DB_CONNECTION']       = 'cloudsql';
            $env['CLOUD_SQL_SOCKET']    = $dbSocket;
            $env['CLOUD_SQL_HOST']      = '';
            $env['CLOUD_SQL_DATABASE']  = $dbName;
            $env['CLOUD_SQL_USERNAME']  = 'root';
            $env['CLOUD_SQL_PASSWORD']  = '';
        }

        $env->write($env_production_file);

        $this->myCommand->info('Created the ".env.production" file.');
    }


    /**
     * Creates a '.env.production' file based on the existing '.env' file.
     *
     * @param string $env_file The '.env' file path.
     * @param string $env_local_file The '.env.local' file path.
     * @param string $dbHost Cloud SQL host IPv4 address.
     * @param string $dbName Cloud SQL database name.
     */
    protected function createEnvLocalFile($env_file, $env_local_file, $dbHost, $dbName)
    {
        if (!file_exists($env_file))
        {
            $this->myCommand->error('Cannot find ".env" file to import the existing options.');
            return;
        }

        if (file_exists($env_local_file))
        {
            $overwrite = $this->myCommand->confirm(
                'Overwrite the existing ".env.local" file?', false
            );

            if ( ! $overwrite)
            {
                return;
            }
        }

        $env = new IniHelper;
        $env->read($env_file);

        $env['APP_ENV']   = 'local';
        $env['APP_DEBUG'] = 'true';

        $env['CACHE_DRIVER']   = 'file';
        $env['SESSION_DRIVER'] = 'file';

        if (( ! is_null($dbHost)) && ( ! is_null($dbName)))
        {
            $env['DB_CONNECTION']       = 'cloudsql';
            $env['CLOUD_SQL_SOCKET']    = '';
            $env['CLOUD_SQL_HOST']      = $dbHost;
            $env['CLOUD_SQL_DATABASE']  = $dbName;
            $env['CLOUD_SQL_USERNAME']  = 'root';
            $env['CLOUD_SQL_PASSWORD']  = 'password';
        }

        $env->write($env_local_file);

        $this->myCommand->info('Created the ".env.local" file.');
    }

    /**
     * Processes a given file with given processors.
     *
     * @param  string $filePath   the path of the file to be processed.
     * @param  array  $processors array of processor function names to
     * be called during the file processing. Every such function shall
     * receive the file contents string as a parameter and return the
     * modified file contents.
     *
     * <code>
     * protected function processorFunc($contents)
     * {
     *     ...
     *     return $modified;
     * }
     * </code>
     */
    protected function processFile($filePath, $processors)
    {
        $contents = file_get_contents($filePath);

        $processed = $contents;

        foreach ($processors as $processor)
        {
            $processed = $this->$processor($processed);
        }

        if ($processed === $contents)
        {
            return;
        }

        $this->backupFile($filePath);

        file_put_contents($filePath, $processed);
    }

    /**
     * Processor function. Replaces the Laravel
     * application class with the one compatible with GAE.
     *
     * @param string $contents the 'bootstrap/app.php' file contents.
     *
     * @return string the modified file contents.
     */
    protected function replaceAppClass($contents)
    {
        $modified = str_replace(
            'Laravel\Lumen\Application',
            'Shpasser\GaeSupportLumen\Foundation\Application',
            $contents);

        if ($contents !== $modified)
        {
            $this->myCommand->info('Replaced the application class in "bootstrap/app.php".');
        }

        return $modified;
    }

    /**
     * Processor function. Replaces 'compiled' path with GAE
     * compatible one when running on GAE.
     *
     * @param string $contents the 'config/view.php' file contents.
     * @return string the modified file contents.
     */
    protected function replaceCompiledPath($contents)
    {
        $modified = preg_replace(
            "/'compiled'\s*=>.*$/m",
            "'compiled' => env('COMPILED_PATH', storage_path().'/framework/views'),",
            $contents
        );

        if ($contents !== $modified)
        {
            $this->myCommand->info('Replaced the \'compiled\' path in "config/view.php".');
        }

        return $modified;
    }


    /**
     * Adds the GAE queue configuration to the 'config/queue.php'
     * if it does not already exist.
     *
     * @param string $contents the 'config/queue.php' file contents.
     * @return string the modified file contents.
     */
    protected function addQueueConfig($contents)
    {
        if (str_contains($contents, "'gae'"))
        {
            return $contents;
        }

        $expression = "/'connections'\s*=>\s*\[/";

        $replacement =
<<<EOT
'connections' => [

        'gae' => [
            'driver'	=> 'gae',
            'queue'		=> 'default',
            'url'		=> '/tasks',
            'encrypt'	=> true,
        ],
EOT;
        $modified = preg_replace($expression, $replacement, $contents);

        if ($contents !== $modified)
        {
            $this->myCommand->info('Added queue driver configuration in "config/queue.php".');
        }

        return $modified;
    }


    /**
     * Adds the Cloud SQL configuration to the 'config/database.php'
     * if it does not already exist.
     *
     * @param string $contents the 'config/database.php' file contents.
     * @return string the modified file contents.
     */
    protected function addCloudSqlConfig($contents)
    {
        if (str_contains($contents, "'cloudsql'"))
        {
            return $contents;
        }

        $expressions = [
            "/'default'.*=>\s*'\b.+\b'/",
            "/'connections'\s*=>\s*\[/"
        ];

        $replacements = [
            "'default' => env('DB_CONNECTION', 'mysql')",
<<<EOT
'connections' => [

        'cloudsql' => [
            'driver'      => 'mysql',
            'unix_socket' => env('CLOUD_SQL_SOCKET'),
            'host'        => env('CLOUD_SQL_HOST'),
            'database'    => env('CLOUD_SQL_DATABASE'),
            'username'    => env('CLOUD_SQL_USERNAME'),
            'password'    => env('CLOUD_SQL_PASSWORD'),
            'charset'     => 'utf8',
            'collation'   => 'utf8_unicode_ci',
            'prefix'      => '',
            'strict'      => false,
        ],
EOT
        ];

        $modified = preg_replace($expressions, $replacements, $contents);

        if ($contents !== $modified)
        {
            $this->myCommand->info('Added Cloud SQL driver configuration in "config/database.php".');
        }

        return $modified;
    }

    /**
     * Adds the GAE disk configuration to the 'config/filesystem.php'
     * if it does not already exist.
     *
     * @param string $contents the 'config/filesystem.php' file contents.
     * @return string the modified file contents.
     */
    protected function addGaeDisk($contents)
    {
        if (str_contains($contents, "'gae'"))
        {
            return $contents;
        }

        $expressions = [
            "/'default'.*=>\s*'\b.+\b'/",
            "/'disks'\s*=>\s*\[/"
        ];

        $replacements = [
            "'default' => env('FILESYSTEM', 'local')",
<<<EOT
'disks' => [

		'gae' => [
			'driver' => 'gae',
			'root'   => storage_path().'/app',
		],
EOT
        ];

        $modified = preg_replace($expressions, $replacements, $contents);

        if ($contents !== $modified)
        {
            $this->myCommand->info('Added GAE filesystem driver configuration in "config/filesystems.php".');
        }

        return $modified;
    }

    /**
     * Generates a "app.yaml" file for a GAE app.
     *
     * @param string $appId the GAE app id.
     * @param string $filePath the 'app.yaml' file path.
     * @param string $publicPath the application public dir path.
     */
    protected function generateAppYaml($appId, $filePath, $publicPath)
    {
        if (file_exists($filePath))
        {
            $overwrite = $this->myCommand->confirm(
                'Overwrite the existing "app.yaml" file?', false
            );

            if ( ! $overwrite)
            {
                return;
            }
        }

        $pathMappings = '';
        foreach (new \DirectoryIterator($publicPath) as $fileInfo)
        {
            if($fileInfo->isDot() || ! $fileInfo->isDir())
            {
                continue;
            }

            $dirName = $fileInfo->getFilename();
            $pathMappings .= PHP_EOL.
<<<EOT
        - url: /{$dirName}
          static_dir: public/{$dirName}

EOT;
        }

        $contents =
<<<EOT
application:    {$appId}
version:        1
runtime:        php55
api_version:    1

handlers:
        - url: /favicon\.ico
          static_files: public/favicon.ico
          upload: public/favicon\.ico
{$pathMappings}
        - url: /.*
          script: public/index.php

skip_files:
        - ^(.*/)?#.*#$
        - ^(.*/)?.*~$
        - ^(.*/)?.*\.py[co]$
        - ^(.*/)?.*/RCS/.*$
        - ^(.*/)?\.(?!env).*$
        - ^(.*/)?node_modules.*$
        - ^(.*/)?_ide_helper\.php$
        - ^(.*/)?\.DS_Store$

env_variables:
        GAE_SKIP_GCS_INIT: false
EOT;
        file_put_contents($filePath, $contents);

        $this->myCommand->info('Generated the "app.yaml" file.');
    }

    /**
     * Generates a "php.ini" file for a GAE app.
     *
     * @param string $appId the GAE app id.
     * @param string $bucketId the GAE gs-bucket id.
     * @param string $filePath the 'php.ini' file path.
     */
    protected function generatePhpIni($appId, $bucketId, $filePath)
    {
        if (file_exists($filePath))
        {
            $overwrite = $this->myCommand->confirm(
                'Overwrite the existing "php.ini" file?', false
            );

            if ( ! $overwrite)
            {
                return;
            }
        }

        $storageBucket = "{$appId}.appspot.com";
        if ($bucketId !== null)
        {
            $storageBucket = $bucketId;
        }

        $contents =
<<<EOT
; enable function that are disabled by default in the App Engine PHP runtime
google_app_engine.enable_functions = "php_sapi_name, php_uname, getmypid"
google_app_engine.allow_include_gs_buckets = "{$storageBucket}"
allow_url_include = 1
EOT;
        file_put_contents($filePath, $contents);

        $this->myCommand->info('Generated the "php.ini" file.');
    }

    /**
     * Copies Lumen configuration file into application `config` directory.
     * Creates the application `config` directory if itd oes not exist.
     *
     * @param  string $filename configuration file name to copy.
     */
    protected function copyLumenConfigFile($filename)
    {
        $lumenConfigFilePath = base_path()."/vendor/laravel/lumen-framework/config/{$filename}";
        $appConfigFilePath   = base_path()."/config/{$filename}";

        if ( ! file_exists($appConfigFilePath))
        {
            $app_config_dir = base_path().'/config';
            if ( ! file_exists($app_config_dir))
            {
                mkdir($app_config_dir);
            }

            copy($lumenConfigFilePath, $appConfigFilePath);

            $this->myCommand->info("Copied {$lumenConfigFilePath} file to {$appConfigFilePath}.");
        }
    }

    /**
     * Creates a backup copy of a desired file.
     *
     * @param string $filePath the file path.
     * @return string the created backup file path.
     */
    protected function backupFile($filePath)
    {
        $sourcePath = $filePath;
        $backupPath = $filePath.'.bak';

        if (file_exists($backupPath))
        {
            $date = new \DateTime();
            $backupPath = "{$filePath}{$date->getTimestamp()}.bak";
        }

        copy($sourcePath, $backupPath);

        return $backupPath;
    }

    /**
     * Restores a file from its backup copy.
     *
     * @param string $filePath the file path.
     * @param string $backupPath the backup path.
     * @param boolean $clean if 'true' deletes the backup copy.
     * @return string the created backup file path.
     */
    protected function restoreFile($filePath, $backupPath, $clean = true)
    {
        if (file_exists($backupPath))
        {
            copy($backupPath, $filePath);

            if ($clean)
            {
                unlink($backupPath);
            }
        }

        return $backupPath;
    }

}
