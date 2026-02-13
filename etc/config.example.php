<?php
/**
 * Shuffle Configuration Template
 *
 * Copy this file to config.php and update values for your environment.
 * NEVER commit config.php to version control.
 */
return [
    'app' => [
        'name'     => 'Shuffle',
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
    'smtp' => [
        'host'       => '127.0.0.1',
        'port'       => 587,
        'encryption' => 'tls',
        'username'   => '',
        'password'   => '',
        'from_email' => 'noreply@shuffle.example.com',
        'from_name'  => 'Shuffle',
    ],
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
