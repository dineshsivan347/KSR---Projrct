<?php

require_once __DIR__ . '/bootstrap.php';

// Schema setup (idempotent)
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        email TEXT,
        mobile TEXT,
        role TEXT DEFAULT 'staff',
        staff_id TEXT,
        admin_type TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_number TEXT UNIQUE,
        title TEXT,
        capacity INTEGER,
        images TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        staff_user_id INTEGER,
        staff_id TEXT,
        staff_phone TEXT,
        staff_college TEXT,
        staff_dept TEXT,
        guest_no INTEGER,
        guest_name TEXT,
        guest_designation TEXT,
        guest_college_org TEXT,
        guest_address TEXT,
        guest_phone TEXT,
        morning_refreshment INTEGER DEFAULT 0,
        breakfast_veg INTEGER DEFAULT 0,
        breakfast_nonveg INTEGER DEFAULT 0,
        lunch_veg INTEGER DEFAULT 0,
        lunch_nonveg INTEGER DEFAULT 0,
        dinner_veg INTEGER DEFAULT 0,
        dinner_nonveg INTEGER DEFAULT 0,
        evening_refreshment INTEGER DEFAULT 0,
        checkin DATETIME,
        checkout DATETIME,
        status TEXT DEFAULT 'pending',
        approved_by INTEGER DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(room_id) REFERENCES rooms(id),
        FOREIGN KEY(staff_user_id) REFERENCES users(id),
        FOREIGN KEY(approved_by) REFERENCES users(id)
    );
    CREATE TABLE IF NOT EXISTS food_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_for_date DATE NOT NULL,
        ordered_by_userid INTEGER,
        notes TEXT,
        morning_refreshment INTEGER DEFAULT 0,
        breakfast_veg INTEGER DEFAULT 0,
        breakfast_nonveg INTEGER DEFAULT 0,
        lunch_veg INTEGER DEFAULT 0,
        lunch_nonveg INTEGER DEFAULT 0,
        dinner_veg INTEGER DEFAULT 0,
        dinner_nonveg INTEGER DEFAULT 0,
        evening_refreshment INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(ordered_by_userid) REFERENCES users(id)
    );
");

// Ensure default admin exists
$stmt = $db->query("SELECT COUNT(*) FROM users WHERE role LIKE 'admin%'");
if ($stmt->fetchColumn() == 0) {
    $pw = password_hash('admin123', PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO users (username, password, email, mobile, role, admin_type, staff_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute(['admin', $pw, 'admin@ksrei.local', '0000000000', 'admin', 'superadmin', 'ADM001']);
}


