<?php

declare(strict_types=1);

$host = '://render.com'; 
$port = '5432';
$database = 'my_filme_db';
$username = 'alpha_admin';
$password = 'ljt6guXdXIcstfg65DQXHKFI25oxBDI4';

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode=require";
    
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Twatsi amakosa ngo tuyabone neza
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // 1. Kurema ama-tables mu buryo bwa PostgreSQL nyabwo
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id SERIAL PRIMARY KEY, name VARCHAR(255));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS translators (id SERIAL PRIMARY KEY, name VARCHAR(255));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS movies (id SERIAL PRIMARY KEY, title VARCHAR(255), video_source TEXT, download_url TEXT, type VARCHAR(50), category_id INT, translator_id INT, poster TEXT);");

    // 2. Kureba niba categories zirimo, niba ari ubusa tuyiheremo seed data
    $stmt = $pdo->query("SELECT COUNT(*) FROM categories");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO categories (name) VALUES ('Action'), ('Comedy'), ('Adventure'), ('Sci-Fi')");
        $pdo->exec("INSERT INTO translators (name) VALUES ('Rocky'), ('Junior'), ('Yanga')");
        
        // Twinjizemo na filime imwe y'icyitegererezo ngo paji ye kuzamo ubusa ikore crash
        $pdo->exec("INSERT INTO movies (title, video_source, download_url, type, category_id, translator_id, poster) 
                    VALUES ('ALPHAFILMES Welcome Video', 'https://zencdn.net', '#', 'Movie', 1, 1, 'https://unsplash.com')");
    }

} catch (PDOException $e) {
    error_log('Connection broken: ' . $e->getMessage());
    
    // Safe memory fallback ngo ikosa rya controller rishire muli uwo mwanya
    $pdo = new PDO("sqlite::memory:");
    $pdo->exec("CREATE TABLE IF NOT EXISTS movies (id INTEGER PRIMARY KEY, title TEXT, video_source TEXT, download_url TEXT, type TEXT, category_id INTEGER, translator_id INTEGER, poster TEXT);");
}
