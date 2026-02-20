<?php
/**
 * Shuffle Configuration Template
 *
 * Copy this file to config.php and update values for your environment.
 * NEVER commit config.php to version control.
 */
return [
    'app' => [
        'url'      => 'https://shuffle.example.com',
        'locale'   => 'en',
        'timezone' => 'UTC',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'shuffle',
        'user'     => 'shuffle',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    's3' => [
        'endpoint'   => 'http://127.0.0.1:9000',
        'bucket'     => 'shuffle',
        'access_key' => '',
        'secret_key' => '',
        'region'     => 'us-east-1',
        'path_style' => true,
    ],
    // SMTP is configured via the web setup wizard at /setup.php and stored in the
    // database `settings` table. You do not need an `smtp` block here for a
    // standard installation — run the wizard on first visit instead.
    //
    // For automated / headless deployments that cannot use the wizard, you may
    // add an `smtp` array here as a fallback. It is used only when no `smtp.*`
    // rows exist in the `settings` table, so it will be ignored on any instance
    // that has already been configured via the wizard.
    //
    // 'smtp' => [
    //     'host'       => 'smtp.example.com',
    //     'port'       => 587,
    //     'encryption' => 'tls',   // 'tls', 'ssl', or 'none'
    //     'username'   => '',
    //     'password'   => '',
    //     'from_email' => 'noreply@shuffle.example.com',
    //     'from_name'  => 'Shuffle',
    // ],

    'session' => [
        'lifetime'    => 86400,     // 24 hours
        'cookie_name' => 'shuffle_session',
    ],
    'upload' => [
        'chunk_size' => 5242880,    // 5 MB chunks for S3 multipart
    ],
    'polling' => [
        'interval' => 15,           // seconds between polls
    ],
];
