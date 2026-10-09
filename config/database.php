<?php

declare(strict_types=1);

$host = '://render.com'; 
$port = '5432';
$database = 'my_filme_db';
$username = 'alpha_admin';
$password = 'ljt6guXdXIcstfg65DQXHKFI25oxBDI4';

try {
    // Gukoresha sslmode=require ni ryo rembo rya Render Postgres
    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode=require";
    
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Auto-schema generation ya Postgres
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id SERIAL PRIMARY KEY, username VARCHAR(255), password VARCHAR(255));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS movies (id SERIAL PRIMARY KEY, title VARCHAR(255), video_source TEXT, download_url TEXT, type VARCHAR(50), category_id INT, translator_id INT, poster TEXT);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id SERIAL PRIMARY KEY, name VARCHAR(255));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS translators (id SERIAL PRIMARY KEY, name VARCHAR(255));");

} catch (PDOException $e) {
    exit("Server Maintenance: " . $e->getMessage());
}
