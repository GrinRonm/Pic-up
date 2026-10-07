<?php
// SQLite Database initialization and connection

$config = require __DIR__ . '/config.php';

try {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create batches table
    $db->exec("CREATE TABLE IF NOT EXISTS batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_code TEXT UNIQUE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        ip_address TEXT NOT NULL
    )");

    // Create photos table
    $db->exec("CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER NOT NULL,
        filename TEXT NOT NULL,
        local_path TEXT,
        telegram_file_id TEXT,
        telegram_file_path TEXT,
        status TEXT DEFAULT 'uploading', -- 'uploading', 'stored'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        ip_address TEXT NOT NULL,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

return $db;
