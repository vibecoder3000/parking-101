<?php
// Copy this file to config.php and fill in the MySQL credentials.
//
// On a hosted deploy you do not need this file at all: the same settings are read from the
// environment (MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD,
// MYSQL_SSL_CA, MYSQL_SSL_VERIFY), and anything set there overrides what is written here.
// config.php is in .gitignore so credentials never reach the repository.
return [
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => 'parking_101',
    'user' => 'parking_app',
    'password' => 'replace-with-a-strong-password',

    // TLS, required by every hosted MySQL provider. Leave null for a local server.
    // Accepts a path to a CA file, the string 'system' for the machine's own CA bundle,
    // or the certificate text itself.
    'ssl_ca' => null,
    // Set to false only if the provider's certificate does not match its hostname.
    'ssl_verify' => true,
];
