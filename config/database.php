<?php

declare(strict_types=1);

$host = 'dpg-db49o42d0e5s73csq5o0-a.virginia-postgres.render.com';
$database = 'my_filme_db';
$username = 'alpha_admin';
$password = 'ljt6guXdXIcstfg65DQXHKFI25oxBDI4';
$port = '5432';

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode=require";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
} catch (PDOException $e) {
    error_log('PostgreSQL connection failed: ' . $e->getMessage());
    exit('Server Maintenance');
}

$schemaStatements = [
    'CREATE TABLE IF NOT EXISTS users (id SERIAL PRIMARY KEY, username VARCHAR(255), password VARCHAR(255));',
    'CREATE TABLE IF NOT EXISTS movies (id SERIAL PRIMARY KEY, title VARCHAR(255), video_source TEXT, download_url TEXT, type VARCHAR(50), category_id INT, translator_id INT, poster TEXT);',
    'CREATE TABLE IF NOT EXISTS categories (id SERIAL PRIMARY KEY, name VARCHAR(255));',
    'CREATE TABLE IF NOT EXISTS translators (id SERIAL PRIMARY KEY, name VARCHAR(255));',
    'CREATE TABLE IF NOT EXISTS seasons (
        id SERIAL PRIMARY KEY,
        movie_id INTEGER NOT NULL REFERENCES movies (id) ON DELETE CASCADE,
        season_number INTEGER NOT NULL,
        UNIQUE (movie_id, season_number)
    );',
    'CREATE TABLE IF NOT EXISTS episodes (
        id SERIAL PRIMARY KEY,
        season_id INTEGER NOT NULL REFERENCES seasons (id) ON DELETE CASCADE,
        episode_number INTEGER NOT NULL,
        video_url TEXT NOT NULL,
        UNIQUE (season_id, episode_number)
    );',
    'CREATE TABLE IF NOT EXISTS comments (
        id SERIAL PRIMARY KEY,
        movie_id INTEGER NOT NULL REFERENCES movies (id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
        comment_text TEXT NOT NULL
    );',
];

foreach ($schemaStatements as $statement) {
    if ($pdo->exec($statement) === false) {
        error_log('PostgreSQL schema initialization failed: ' . ($pdo->errorInfo()[2] ?? 'Unknown database error'));
        exit('Server Maintenance');
    }
}

$compatibilityColumns = [
    'users' => [
        'email' => 'VARCHAR(254)',
        'role' => "VARCHAR(32) NOT NULL DEFAULT 'user'",
    ],
    'movies' => [
        'description' => 'TEXT',
        'poster_image' => 'TEXT',
        'backdrop_image' => 'VARCHAR(2048)',
        'release_year' => 'INTEGER',
        'runtime_minutes' => 'INTEGER',
        'duration_minutes' => 'INTEGER',
        'server_2_url' => 'TEXT',
        'vip_url' => 'TEXT',
        'is_featured' => 'SMALLINT NOT NULL DEFAULT 0',
    ],
    'categories' => [
        'category_name' => 'VARCHAR(255)',
    ],
    'translators' => [
        'translator_name' => 'VARCHAR(255)',
    ],
    'episodes' => [
        'episode_title' => 'VARCHAR(255)',
        'episode_thumbnail' => 'VARCHAR(2048)',
        'video_url_server2' => 'TEXT',
        'video_url_vip' => 'TEXT',
    ],
];

foreach ($compatibilityColumns as $table => $columns) {
    foreach ($columns as $column => $type) {
        $statement = "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$type}";
        if ($pdo->exec($statement) === false) {
            error_log("PostgreSQL schema update failed for {$table}.{$column}: " . ($pdo->errorInfo()[2] ?? 'Unknown database error'));
            exit('Server Maintenance');
        }
    }
}
