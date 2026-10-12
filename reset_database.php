<?php
/**
 * Database Reset Script
 * This script drops and recreates the database to fix persistent corruption.
 */

// Database connection settings (Update if needed)
$host = 'localhost';
$port = '3306';
$user = 'root';
$pass = '';
$db_name = 'fsscbt_db';

try {
    // 1. Connect without selecting a database
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "<h1>Database Reset Tool - FSS OYO CBT System</h1>";
    echo "<p>Starting reset process...</p>";

    // 2. Drop the database
    echo "<p>Dropping database '$db_name'...</p>";
    $pdo->exec("DROP DATABASE IF EXISTS `$db_name` ");
    echo "<p style='color: green;'>Database dropped successfully.</p>";

    // 3. Create the database
    echo "<p>Creating database '$db_name'...</p>";
    $pdo->exec("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name` ");
    echo "<p style='color: green;'>Database created successfully.</p>";

    // 4. Load database.sql
    $sql_file = __DIR__ . '/database.sql';
    if (file_exists($sql_file)) {
        echo "<p>Loading schema from 'database.sql'...</p>";
        $sql = file_get_contents($sql_file);
        
        // Split SQL into individual statements
        // Simple splitting by semicolon (works for most basic SQL files)
        $queries = explode(';', $sql);
        $count = 0;
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                $pdo->exec($query);
                $count++;
            }
        }
        echo "<p style='color: green;'>Successfully executed $count SQL statements.</p>";
    } else {
        echo "<p style='color: orange;'>Warning: 'database.sql' not found. Tables were not recreated.</p>";
    }

    echo "<h2 style='color: blue;'>Reset Complete!</h2>";
    echo "<p>FSS OYO Polytechnic - Computer Science Department CBT System has been configured.</p>";
    echo "<p>You can now log in using the default admin account:</p>";
    echo "<ul><li>Username: <b>admin</b></li><li>Password: <b>password</b></li></ul>";
    echo "<p><a href='admin/login.php'>Go to Login Page</a></p>";
    echo "<p style='color: red;'><b>SECURITY WARNING: Delete this file ('reset_database.php') immediately after use!</b></p>";

} catch (PDOException $e) {
    echo "<h2 style='color: red;'>Reset Failed!</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><b>Common fixes:</b></p>";
    echo "<ul>
            <li>Stop MySQL in XAMPP.</li>
            <li>Manually delete the folder: <code>C:\\xampp\\mysql\\data\\$db_name</code></li>
            <li>Start MySQL and try running this script again.</li>
          </ul>";
}
?>
