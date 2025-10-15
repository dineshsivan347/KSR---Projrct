<?php
try {
    $db = new PDO('sqlite:ksrei.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "<h3>Table: $table</h3>";
        $stmt = $db->query("PRAGMA table_info($table)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<ul>";
        foreach ($columns as $col) {
            echo "<li>" . htmlspecialchars($col['name']) . " (" . htmlspecialchars($col['type']) . ")</li>";
        }
        echo "</ul>";
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>