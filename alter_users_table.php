<?php
try {
    // Connect to the SQLite database
    $db = new PDO("sqlite:ksrei.db");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h1>Database Cleanup Script</h1>";

    // Get current columns
    $stmt = $db->query("PRAGMA table_info(users);");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);

    // --- Step 1: Ensure 'department' column exists ---
    if (!in_array('department', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN department TEXT");
        echo "<p style='color:green;'>✅ Column 'department' added successfully.</p>";
    } else {
        echo "<p style='color:blue;'>ℹ️ Column 'department' already exists.</p>";
    }

    // --- Step 2: Copy data from 'dept' to 'department' if 'dept' exists ---
    if (in_array('dept', $columns) && in_array('department', $columns)) {
        // This query copies data only for rows where 'department' is NULL but 'dept' is not.
        $db->exec("UPDATE users SET department = dept WHERE department IS NULL AND dept IS NOT NULL");
        echo "<p style='color:green;'>✅ Data from 'dept' has been copied to 'department' where necessary.</p>";
    }

    // --- Step 3: Drop the old 'dept' column ---
    // Note: SQLite's ALTER TABLE has limitations. The "right" way is to recreate the table.
    if (in_array('dept', $columns)) {
        echo "<p style='color:orange;'>⚠️ Found old 'dept' column. SQLite does not support simple 'DROP COLUMN'. You need to manually recreate the table to remove it. This script has copied the data, so it is safe to do so using a database tool.</p>";
        echo "<p><b>Action Required:</b> Open 'ksrei.db' with a tool like 'DB Browser for SQLite', copy the data from the 'users' table to a temporary table, drop the 'users' table, recreate it with the correct schema (without 'dept'), and copy the data back.</p>";
    } else {
        echo "<p style='color:blue;'>ℹ️ Old 'dept' column not found. No cleanup needed for it.</p>";
    }

    echo "<h2>Script finished.</h2>";

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
