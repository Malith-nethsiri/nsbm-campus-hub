<?php
declare(strict_types=1);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'nsbm_campus_hub';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASSWORD') ?: '1234';

if ($dbPassword === false) {
    $dbPassword = '';
}

$dsn = sprintf('mysql:host=%s;port=3307;dbname=%s;charset=utf8mb4', $dbHost, $dbName);


try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    error_log('Campus Hub database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The application is temporarily unavailable. Please try again later.');
}
