<?php
$app_timezone = 'Africa/Lagos';
date_default_timezone_set($app_timezone);

$host = 'localhost';
$port = '3306';

$db   = 'fsscbt_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     $timezone_offset = (new DateTime('now', new DateTimeZone($app_timezone)))->format('P');
     $pdo->exec("SET time_zone = '{$timezone_offset}'");
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
