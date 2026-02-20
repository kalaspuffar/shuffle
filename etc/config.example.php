<?php
/**
 * Shuffle Configuration Template
 *
 * Copy this file to config.php and update values for your environment.
 * NEVER commit config.php to version control.
 *
 * Only the `db` and `session` sections are required here. All other
 * settings (app, smtp, s3, upload, polling) are stored in the database
 * `settings` table and written during the setup wizard at /setup.php.
 * The values below are shown for reference only — uncomment them if you
 * need a fallback for headless/automated deployments where the wizard
 * cannot be used.
 */
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'shuffle',
        'user'     => 'shuffle',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'lifetime'    => 86400,     // 24 hours
        'cookie_name' => 'shuffle_session',
    ],

    // ---------------------------------------------------------------------------
    // The settings below are managed via the setup wizard and stored in the DB.
    // They are shown here as reference only. Uncomment if you need a config-file
    // fallback (e.g. automated/headless deployments). DB values always take
    // precedence over these.
    // ---------------------------------------------------------------------------

    // 'app' => [
    //     'name'     => 'Shuffle',
    //     'url'      => 'https://shuffle.example.com',
    //     'locale'   => 'en',
    //     'timezone' => 'UTC',
    // ],

    // 'smtp' => [
    //     'host'       => 'smtp.example.com',
    //     'port'       => 587,
    //     'encryption' => 'tls',   // 'tls', 'ssl', or 'none'
    //     'username'   => '',
    //     'password'   => '',
    //     'from_email' => 'noreply@shuffle.example.com',
    //     'from_name'  => 'Shuffle',
    // ],

    // 's3' => [
    //     'endpoint'   => 'http://127.0.0.1:9000',
    //     'bucket'     => 'shuffle',
    //     'access_key' => '',
    //     'secret_key' => '',
    //     'region'     => 'us-east-1',
    //     'path_style' => true,
    // ],

    // 'upload' => [
    //     'chunk_size' => 5242880,    // 5 MB chunks for S3 multipart
    // ],

    // 'polling' => [
    //     'interval' => 15,           // seconds between polls
    // ],
];
