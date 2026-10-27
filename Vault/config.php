<?php
declare(strict_types=1);

// Configure these values with the environment settings provided by your host.
function database_connection(): PDO
{
    // Environment values override the local XAMPP defaults.
    $host = getenv('VAULT_DB_HOST') ?: '127.0.0.1';
    $name = getenv('VAULT_DB_NAME') ?: 'vault';
    $user = getenv('VAULT_DB_USER') ?: 'root';
    $password = getenv('VAULT_DB_PASSWORD') ?: '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    // Throw database errors and return rows by column name; disable emulated prepares.
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
