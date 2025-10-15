<?php

// Start session early
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load config
require_once __DIR__ . '/config.php';

// Database connection (PDO SQLite)
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}


