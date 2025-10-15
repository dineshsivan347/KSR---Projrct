<?php

// ======================================================================================
// 1. Configuration, Setup & Helpers
// ======================================================================================

// Start session for login state and flash messages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Third-party library (FPDF is large, so include it if you intend to generate PDFs)
// require 'fpdf.php'; // Kept commented as its usage is not fully shown, but its presence is noted

// File paths and constants
$dbFile = __DIR__ . '/ksrei.db';
$roomsDir = __DIR__ . '/rooms';
$uploadsDir = __DIR__ . '/uploads';
$collegeName = "K.S.R. College of Engineering (KSREI)";
$collegeLogoUrl = "rooms/logo.png";
$adminSigPath = "uploads/sign.png";

// Ensure directories exist
if (!is_dir($roomsDir)) mkdir($roomsDir, 0755, true);
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

// Get current page and tab from URL
$page = $_GET['page'] ?? 'home';
$tab = $_GET['tab'] ?? null;

// =======================
// Helper Functions
// =======================

/** Sets a session-based flash message. */
function flash(string $msg): void {
    $_SESSION['flash'] = $msg;
}

/** Gets and clears the flash message. */
function get_flash(): ?string {
    $t = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $t;
}

/** Helper: escape HTML output. */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES);
}

/** Helper: check if user is logged in. */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/** Helper: require login, redirect to login page if not logged in. */
function require_login(): void {
    if (!is_logged_in()) {
        flash("Please log in to continue.");
        header('Location: ?page=login');
        exit;
    }
}

/** Helper: send SMS notification (dummy implementation). */
function send_sms_notification(string $phone, string $message): void {
    // In a real application, this would call an SMS API (e.g., Twilio, local SMS gateway).
    error_log("SMS to $phone: $message");
}

// =======================
// Database & User Setup
// =======================

try {
    // Database connection using PDO and SQLite
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Schema setup
    $db->exec("
           CREATE TABLE IF NOT EXISTS admin_users (
                    admin_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    admin_username TEXT UNIQUE,
                    mobile TEXT,
                    password TEXT,
                    email TEXT,
                    college TEXT DEFAULT 'NULL',
                    admin_type TEXT DEFAULT 'admin',
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
            booking_id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER,
            user_id TEXT,
            user_phone TEXT,
            user_college TEXT,
            user_dept TEXT,
            guest_no INTEGER,
            guest_name TEXT,
            guest_designation TEXT,
            guest_college_org TEXT,
            guest_address TEXT,
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
            FOREIGN KEY(approved_by) REFERENCES admin_users(admin_id)
    );
    CREATE TABLE IF NOT EXISTS HOD_users (
        HOD_user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        HOD_username TEXT UNIQUE,
        mobile TEXT,
        password TEXT,
        email TEXT,
        college TEXT DEFAULT 'NULL',
        dept TEXT DEFAULT 'NULL',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ");
    // Add new status if not exists (for two-tier approval)
    // Create default superadmin and manager if none exists
    $stmt = $db->query("SELECT COUNT(*) FROM admin_users WHERE admin_type LIKE 'superadmin%'");
    if ($stmt->fetchColumn() == 0) {
        $pw_admin = password_hash('admin123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO admin_users (admin_username, password, email, mobile, admin_type) VALUES (?, ?, ?, ?, ?)")
            ->execute(['admin', $pw_admin, 'admin@ksrei.local', '0000000000', 'superadmin']);
        $pw_manager = password_hash('manager123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO admin_users (admin_username, password, email, mobile, admin_type) VALUES (?, ?, ?, ?, ?)")
            ->execute(['manager', $pw_manager, 'Gbmanager@ksrei.local', '0000000000', 'manager']);
    }

} catch (PDOException $e) {
    // Graceful error for DB failure
    die("Database connection failed: " . $e->getMessage());
}

/** Helper: get current user record from DB. */
function current_user(): ?array {
    global $db;
    if (!is_logged_in()) return null;
    try {
        // First, try to find in admin_users
        $st = $db->prepare("SELECT admin_id as id, admin_username as username, mobile, email, college, admin_type as role, NULL as dept FROM admin_users WHERE admin_id = ? LIMIT 1");
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user;
        }

        // If not found, try HOD_users (set role as 'staff')
        $st = $db->prepare("SELECT HOD_user_id as id, HOD_username as username, mobile, email, college, 'staff' as role, dept FROM HOD_users WHERE HOD_user_id = ? LIMIT 1");
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user;
        }

        // If neither found, return null
        return null;
    } catch (PDOException $e) {
        error_log("DB Error fetching current user: " . $e->getMessage());
        return null;
    }
}

$currentUser = current_user();

// ======================================================================================
// 2. Request Handlers (Controller Logic)
// ======================================================================================

// Login/Logout Handler
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) { flash("Username and password are required."); header('Location: ?page=login'); exit; }
    try {
        // First, try admin_users
        $st = $db->prepare("SELECT admin_id as id, admin_username as username, password, admin_type as role FROM admin_users WHERE admin_username = ? LIMIT 1");
        $st->execute([$username]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
        // If not found, try HOD_users
            $st = $db->prepare("SELECT HOD_user_id as id, HOD_username as username, password, 'staff' as role FROM HOD_users WHERE HOD_username = ? LIMIT 1");
            $st->execute([$username]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            flash("Welcome, " . h($username) . "!");
            $redirectPage = ($user['role'] === 'staff') ? 'staff' : 'admin';
            header("Location: ?page=$redirectPage"); exit;
        } else {
            flash("Invalid username or password.");
            header('Location: ?page=login'); exit;
        }
    } catch (PDOException $e) {
        flash("Login error. Try again.");
        error_log("Login DB Error: " . $e->getMessage());
        header('Location: ?page=login'); exit;
    }
}elseif ($page === 'logout') {
    session_destroy();
    header('Location: ?page=home'); exit;
}


// Registration Handler
if ($page === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $college = trim($_POST['college'] ?? '');
    $dept = trim($_POST['dept'] ?? '');

    if (empty($username) || empty($email) || empty($password) || empty($role) || empty($college)) {
        flash("All fields are required.");
        header('Location: ?page=register'); exit;
    }

    if ($role === 'hod' && empty($dept)) {
        flash("Department is required for HOD role.");
        header('Location: ?page=register'); exit;
    }

    if (!in_array($role, ['admin', 'hod'])) {
        flash("Invalid role selected.");
        header('Location: ?page=register'); exit;
    }

    try {
        // Check if username or email already exists in admin_users or HOD_users
        $st = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE admin_username = ? OR email = ?");
        $st->execute([$username, $email]);
        $count_admin = $st->fetchColumn();

        $st = $db->prepare("SELECT COUNT(*) FROM HOD_users WHERE HOD_username = ? OR email = ?");
        $st->execute([$username, $email]);
        $count_hod = $st->fetchColumn();

        if ($count_admin > 0 || $count_hod > 0) {
            flash("Username or email already exists.");
            header('Location: ?page=register'); exit;
        }

        $pwHash = password_hash($password, PASSWORD_DEFAULT);

        if ($role === 'admin') {
            $db->prepare("INSERT INTO admin_users (admin_username, mobile, password, email, college, admin_type) VALUES (?, ?, ?, ?, ?, 'admin')")
                ->execute([$username, $mobile, $pwHash, $email, $college]);
        } elseif ($role === 'hod') {
            $db->prepare("INSERT INTO HOD_users (HOD_username, mobile, password, email, college, dept) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$username, $mobile, $pwHash, $email, $college, $dept]);
        }

        flash("Registration successful! You can now log in.");
        header('Location: ?page=login'); exit;
    } catch (PDOException $e) {
        flash("Database error during registration."); error_log("Register DB Error: " . $e->getMessage()); header('Location: ?page=register'); exit;
    }
}

// Admin: Direct Booking
if ($page === 'admin' && isset($_POST['admin_create_booking']) && is_logged_in() && strpos($currentUser['role'] ?? '', 'admin') !== false) {
    $data = [
        'room_id' => (int)($_POST['room_id'] ?? 0),
        'staff_user_id' => $currentUser['id'],
        'staff_id' => trim($_POST['staff_id'] ?? 'ADMIN'),
        'staff_phone' => trim($_POST['staff_phone'] ?? $currentUser['mobile'] ?? ''),
        'staff_college' => trim($_POST['staff_college'] ?? $collegeName),
        'staff_dept' => trim($_POST['staff_dept'] ?? 'Admin'),
        'guest_no' => (int)($_POST['guest_no'] ?? 0),
        'guest_name' => trim($_POST['guest_name'] ?? ''),
        'guest_phone' => trim($_POST['guest_phone'] ?? ''),
        'guest_designation' => trim($_POST['guest_designation'] ?? ''),
        'guest_college_org' => trim($_POST['guest_college_org'] ?? ''),
        'guest_address' => trim($_POST['guest_address'] ?? ''),
        'morning_refreshment' => (int)($_POST['morning_refreshment'] ?? 0),
        'breakfast_veg' => (int)($_POST['breakfast_veg'] ?? 0),
        'breakfast_nonveg' => (int)($_POST['breakfast_nonveg'] ?? 0),
        'lunch_veg' => (int)($_POST['lunch_veg'] ?? 0),
        'lunch_nonveg' => (int)($_POST['lunch_nonveg'] ?? 0),
        'dinner_veg' => (int)($_POST['dinner_veg'] ?? 0),
        'dinner_nonveg' => (int)($_POST['dinner_nonveg'] ?? 0),
        'evening_refreshment' => (int)($_POST['evening_refreshment'] ?? 0),
        'checkin' => $_POST['checkin'] ?? null,
        'checkout' => $_POST['checkout'] ?? null
    ];
    
    if (!$data['room_id'] || empty($data['checkin']) || empty($data['checkout']) || empty($data['guest_name'])) {
        flash("Room, check-in/out dates, and guest name are mandatory.");
        header('Location: ?page=admin&tab=book'); exit;
    }

    try {
        // Check availability
        $st = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id=? AND status IN ('pending','approved') AND NOT (datetime(checkout) <= datetime(?) OR datetime(checkin) >= datetime(?))");
        $st->execute([$data['room_id'], $data['checkin'], $data['checkout']]);
        if ($st->fetchColumn() > 0) {
            flash("Room not available for the selected range.");
            header('Location: ?page=admin&tab=book'); exit;
        }

        // Insert booking as approved
        $q = "INSERT INTO bookings (room_id, staff_user_id, staff_id, staff_phone, staff_college, staff_dept, guest_no, guest_name, guest_phone, guest_designation, guest_college_org, guest_address, morning_refreshment, breakfast_veg, breakfast_nonveg, lunch_veg, lunch_nonveg, dinner_veg, dinner_nonveg, evening_refreshment, checkin, checkout, status, approved_by, approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $db->prepare($q);
        $stmt->execute(array_merge(array_values($data), ['approved', $currentUser['id'], date('Y-m-d H:i:s')]));
        
        flash("Booking created and approved successfully.");
    } catch (PDOException $e) {
        flash("Database error during booking.");
        error_log("Admin Direct Booking DB Error: " . $e->getMessage());
    }
    header('Location: ?page=admin&tab=history'); exit;
}

// Admin: Direct Booking
if ($page === 'admin' && isset($_POST['admin_create_booking']) && is_logged_in() && strpos($currentUser['role'] ?? '', 'admin') !== false) {
    $data = [
        'room_id' => (int)($_POST['room_id'] ?? 0),
        'staff_user_id' => $currentUser['id'],
        'staff_id' => trim($_POST['staff_id'] ?? 'ADMIN'),
        'staff_phone' => trim($_POST['staff_phone'] ?? $currentUser['mobile'] ?? ''),
        'staff_college' => trim($_POST['staff_college'] ?? $collegeName),
        'staff_dept' => trim($_POST['staff_dept'] ?? 'Admin'),
        'guest_no' => (int)($_POST['guest_no'] ?? 0),
        'guest_name' => trim($_POST['guest_name'] ?? ''),
        'guest_phone' => trim($_POST['guest_phone'] ?? ''),
        'guest_designation' => trim($_POST['guest_designation'] ?? ''),
        'guest_college_org' => trim($_POST['guest_college_org'] ?? ''),
        'guest_address' => trim($_POST['guest_address'] ?? ''),
        'morning_refreshment' => (int)($_POST['morning_refreshment'] ?? 0),
        'breakfast_veg' => (int)($_POST['breakfast_veg'] ?? 0),
        'breakfast_nonveg' => (int)($_POST['breakfast_nonveg'] ?? 0),
        'lunch_veg' => (int)($_POST['lunch_veg'] ?? 0),
        'lunch_nonveg' => (int)($_POST['lunch_nonveg'] ?? 0),
        'dinner_veg' => (int)($_POST['dinner_veg'] ?? 0),
        'dinner_nonveg' => (int)($_POST['dinner_nonveg'] ?? 0),
        'evening_refreshment' => (int)($_POST['evening_refreshment'] ?? 0),
        'checkin' => $_POST['checkin'] ?? null,
        'checkout' => $_POST['checkout'] ?? null
    ];
    
    if (!$data['room_id'] || empty($data['checkin']) || empty($data['checkout']) || empty($data['guest_name'])) {
        flash("Room, check-in/out dates, and guest name are mandatory.");
        header('Location: ?page=admin&tab=book'); exit;
    }

    try {
        $st = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id=? AND status IN ('pending','approved') AND NOT (datetime(checkout) <= datetime(?) OR datetime(checkin) >= datetime(?))");
        $st->execute([$data['room_id'], $data['checkin'], $data['checkout']]);
        if ($st->fetchColumn() > 0) { flash("Room not available for the selected range."); header('Location: ?page=admin&tab=book'); exit; }

        $q = "INSERT INTO bookings (room_id, staff_user_id, staff_id, staff_phone, staff_college, staff_dept, guest_no, guest_name, guest_phone, guest_designation, guest_college_org, guest_address, morning_refreshment, breakfast_veg, breakfast_nonveg, lunch_veg, lunch_nonveg, dinner_veg, dinner_nonveg, evening_refreshment, checkin, checkout, status, approved_by, approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $db->prepare($q);
        $stmt->execute(array_merge(array_values($data), ['approved', $currentUser['id'], date('Y-m-d H:i:s')]));
        flash("Booking created and auto-approved successfully.");
    } catch (PDOException $e) { flash("Database error during booking."); error_log("Admin Direct Booking DB Error: " . $e->getMessage()); }
    header('Location: ?page=admin&tab=history'); exit;
}
// Admin: Room Management (add/update/delete room)
if ($page === 'admin' && isset($_POST['room_action']) && is_logged_in() && strpos($currentUser['role'] ?? '', 'admin') !== false) {
    $ra = $_POST['room_action'];
    $rid = (int)($_POST['room_id'] ?? 0);
    $room_number = trim($_POST['room_number'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $capacity = (int)($_POST['capacity'] ?? 0);

    if ($ra === 'add' || $ra === 'update') {
        if (empty($room_number) || empty($title) || $capacity < 1) {
            flash("Room number, title, and capacity are required.");
            header('Location: ?page=admin&tab=rooms'); exit;
        }
        
        $images = [];
        if ($ra === 'update') {
            // Preserve existing images for update
            $st = $db->prepare("SELECT images FROM rooms WHERE id=?"); $st->execute([$rid]); $row = $st->fetch(PDO::FETCH_ASSOC);
            $images = json_decode($row['images'] ?? '[]', true) ?: [];
        }

        // Handle up to 4 image uploads
        for ($i=1;$i<=4;$i++){
            if (!empty($_FILES["img$i"]['name']) && $_FILES["img$i"]['error'] == UPLOAD_ERR_OK) {
                $t = $_FILES["img$i"];
                $ext = strtolower(pathinfo($t['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'])) continue; // Basic extension check
                
                $fn = 'room_' . time() . '_' . rand(100,999) . "_$i.".$ext;
                if (move_uploaded_file($t['tmp_name'], "$roomsDir/$fn")) {
                    $images[] = $fn;
                }
            }
        }
        $image_json = json_encode($images);
    }
    
    try {
        if ($ra === 'add') {
            $st = $db->query("SELECT COUNT(*) FROM rooms");
            if ($st->fetchColumn() >= 4) { flash("Cannot add more than 4 rooms."); header('Location: ?page=admin&tab=rooms'); exit; }
            $db->prepare("INSERT INTO rooms (room_number,title,capacity,images) VALUES (?,?,?,?)")
                ->execute([$room_number,$title,$capacity,$image_json]);
            flash("Room **" . h($room_number) . "** added successfully.");
        } elseif ($ra === 'update' && $rid) {
            $db->prepare("UPDATE rooms SET room_number=?, title=?, capacity=?, images=? WHERE id=?")
                ->execute([$room_number,$title,$capacity,$image_json,$rid]);
            flash("Room **" . h($room_number) . "** updated successfully.");
        } elseif ($ra === 'delete' && $rid) {
            // Delete room images from folder
            $st = $db->prepare("SELECT images FROM rooms WHERE id=?"); $st->execute([$rid]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $imgs = json_decode($row['images'], true) ?: [];
                foreach ($imgs as $im) { @unlink("$roomsDir/$im"); }
            }
            $db->prepare("DELETE FROM rooms WHERE id=?")->execute([$rid]);
            flash("Room deleted.");
        }
    } catch (PDOException $e) {
        flash("Database error: could not perform room action.");
        error_log("Room DB Error: " . $e->getMessage());
    }
    header('Location: ?page=admin&tab=rooms'); exit;
}

// Admin: Delete individual room image
if ($page === 'admin' && isset($_POST['delete_image_action']) && is_logged_in() && strpos($currentUser['role'] ?? '', 'admin') !== false) {
    $rid = (int)($_POST['room_id'] ?? 0);
    $image_to_delete = $_POST['image_name'] ?? '';
    if ($rid && $image_to_delete) {
        $st = $db->prepare("SELECT images FROM rooms WHERE id=?"); $st->execute([$rid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $images = json_decode($row['images'], true) ?: [];
            if (($key = array_search($image_to_delete, $images)) !== false) {
                unset($images[$key]);
                @unlink("$roomsDir/$image_to_delete");
                $db->prepare("UPDATE rooms SET images=? WHERE id=?")->execute([json_encode(array_values($images)), $rid]);
                flash("Image deleted successfully.");
            }
        }
    }
    header('Location: ?page=admin&tab=rooms'); exit;
}

// Staff: Create Booking
if ($page === 'staff' && isset($_POST['create_booking']) && is_logged_in() && $currentUser['role'] === 'staff') {
    $data = [
        'room_id' => (int)($_POST['room_id'] ?? 0),
        'staff_user_id' => $currentUser['id'],
        'staff_id' => trim($_POST['staff_id'] ?? $currentUser['staff_id'] ?? ''),
        'staff_phone' => trim($_POST['staff_phone'] ?? $currentUser['mobile'] ?? ''),
        'guest_no' => (int)($_POST['guest_no'] ?? 0),
        'guest_name' => trim($_POST['guest_name'] ?? ''),
        'guest_phone' => trim($_POST['guest_phone'] ?? ''),
        'guest_designation' => trim($_POST['guest_designation'] ?? ''),
        'guest_college_org' => trim($_POST['guest_college_org'] ?? ''),
        'guest_address' => trim($_POST['guest_address'] ?? ''),
        'morning_refreshment' => (int)($_POST['morning_refreshment'] ?? 0),
        'breakfast_veg' => (int)($_POST['breakfast_veg'] ?? 0),
        'breakfast_nonveg' => (int)($_POST['breakfast_nonveg'] ?? 0),
        'lunch_veg' => (int)($_POST['lunch_veg'] ?? 0),
        'lunch_nonveg' => (int)($_POST['lunch_nonveg'] ?? 0),
        'dinner_veg' => (int)($_POST['dinner_veg'] ?? 0),
        'dinner_nonveg' => (int)($_POST['dinner_nonveg'] ?? 0),
        'evening_refreshment' => (int)($_POST['evening_refreshment'] ?? 0),
        'checkin' => $_POST['checkin'] ?? null,
        'checkout' => $_POST['checkout'] ?? null
    ];

    if (!$data['room_id'] || empty($data['checkin']) || empty($data['checkout']) || empty($data['guest_name'])) {
        flash("Room, check-in/out dates, and guest name are mandatory.");
        header("Location: ?page=staff&tab=booking&book_room={$data['room_id']}"); exit;
    }

    try {
        // Check availability: no booking for same room where times overlap and status in pending or approved
        $st = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id=? AND status IN ('pending','approved') AND NOT (datetime(checkout) <= datetime(?) OR datetime(checkin) >= datetime(?))");
        $st->execute([$data['room_id'], $data['checkin'], $data['checkout']]);
        if ($st->fetchColumn() > 0) {
            flash("Room not available for the selected range. Check the room's availability.");
            header("Location: ?page=staff&tab=booking&book_room={$data['room_id']}"); exit;
        }

        // Insert booking
        $q = "INSERT INTO bookings (room_id, staff_user_id, staff_id, staff_phone, guest_no, guest_name, guest_phone, guest_designation, guest_college_org, guest_address, morning_refreshment, breakfast_veg, breakfast_nonveg, lunch_veg, lunch_nonveg, dinner_veg, dinner_nonveg, evening_refreshment, checkin, checkout, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $db->prepare($q);
        $stmt->execute(array_merge(array_values($data), ['pending']));

        flash("Booking request submitted and is **pending** approval. You'll be notified.");
    } catch (PDOException $e) {
        flash("A database error occurred during booking. Please try again.");
        error_log("Booking DB Error: " . $e->getMessage());
    }

    header("Location: ?page=staff&tab=status"); exit;
}

// Staff: Cancel Booking
if ($page === 'staff' && isset($_POST['cancel_booking']) && is_logged_in() && $currentUser['role'] === 'staff') {
    $bid = (int)($_POST['booking_id'] ?? 0);
    $uid = $currentUser['id'];
    try {
        $st = $db->prepare("SELECT status FROM bookings WHERE id=? AND staff_user_id=?");
        $st->execute([$bid, $uid]);
        $bk = $st->fetch(PDO::FETCH_ASSOC);
        if ($bk && $bk['status'] === 'pending') {
            $db->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$bid]);
            flash("Booking cancelled successfully. A notification has been sent.");
        } else {
            flash("Cannot cancel this booking. Only pending bookings can be cancelled.");
        }
    } catch (PDOException $e) {
        flash("An error occurred during cancellation.");
        error_log("Cancel DB Error: " . $e->getMessage());
    }
    header('Location: ?page=staff&tab=status'); exit;
}

// Admin: Approve/Deny/Revoke Booking
if ($page === 'admin' && isset($_POST['booking_action']) && is_logged_in() && strpos($currentUser['role'] ?? '', 'admin') !== false) {
    $ba = $_POST['booking_action'];
    $bid = (int)($_POST['booking_id'] ?? 0);
    $uid = $currentUser['id'];
    if (!$bid) { flash("Invalid booking ID."); header('Location: ?page=admin'); exit; }
    
    try {
        $st = $db->prepare("SELECT * FROM bookings WHERE id=?"); $st->execute([$bid]); $bk = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bk) { flash("Booking not found."); header('Location: ?page=admin'); exit; }

        if ($ba === 'approve' && $bk['status'] === 'pending') {
            $db->prepare("UPDATE bookings SET status='approved', approved_by=?, approved_at=datetime('now') WHERE id=?")
                ->execute([$uid,$bid]);
            flash("Booking **approved** successfully.");
            // send_sms_notification($bk['staff_phone'], "Your booking (ID: {$bk['id']}) has been approved.");
            header('Location: ?page=admin&tab=pending'); exit;
        } 
        
        if ($ba === 'deny' && $bk['status'] === 'pending') {
            $db->prepare("UPDATE bookings SET status='denied', approved_by=?, approved_at=datetime('now') WHERE id=?")
                ->execute([$uid,$bid]);
            flash("Booking **denied**.");
            // send_sms_notification($bk['staff_phone'], "Your booking (ID: {$bk['id']}) has been denied.");
            header('Location: ?page=admin&tab=pending'); exit;
        } 
        
        if ($ba === 'revoke' && $bk['status'] === 'approved') {
            $checkin = new DateTime($bk['checkin']);
            $now = new DateTime();
            $cutoff = (clone $checkin)->sub(new DateInterval('P4D')); // Four days before check-in

            if ($now > $cutoff) {
                flash("Cannot revoke: it's within four days of check-in (**Cutoff: " . h($cutoff->format('Y-m-d H:i')) . "**).");
                header('Location: ?page=admin&tab=pending'); exit;
            }

            $db->prepare("UPDATE bookings SET status='revoked', approved_by=?, approved_at=datetime('now') WHERE id=?")
                ->execute([$uid,$bid]);
            send_sms_notification($bk['staff_phone'], "Your booking (ID: {$bk['id']}) has been revoked.");
            flash("Approval **revoked** and notification sent.");
            header('Location: ?page=admin&tab=history'); exit;
        }
        
        flash("Action failed: Invalid booking status or action.");
        header('Location: ?page=admin&tab=pending'); exit;

    } catch (Exception $e) {
        flash("An unexpected error occurred during the booking action.");
        error_log("Admin Action Error: " . $e->getMessage());
        header('Location: ?page=admin'); exit;
    }
}


// ======================================================================================
// 3. Presentation & View Functions
// ======================================================================================

/** Renders the main HTML header and starts the body. */
function layout_header(string $title = ""): void {
    global $page, $collegeName, $collegeLogoUrl, $currentUser;
    $bodyClass = '';
    if ($page === 'admin') { $bodyClass = 'admin-bg'; }
    elseif ($page === 'staff') { $bodyClass = 'staff-bg'; }
    elseif ($page === 'home' || $page === 'login' || $page === 'register') { $bodyClass = 'home-bg'; }
    
    $isLoggedIn = is_logged_in();
    $isStaff = $isLoggedIn && ($currentUser['role'] === 'staff');
    $isAdmin = $isLoggedIn && strpos($currentUser['role'] ?? '', 'admin') !== false;
    $headerStyle = 'style="min-height:90px;padding:12px 0;background:rgba(255,255,255,0.9);backdrop-filter: blur(10px);box-shadow:0 6px 30px rgba(2, 16, 80, 0.08);"';
    $brandStyle = 'style="font-size:2.2rem;font-weight:800;letter-spacing:2px;display:flex;align-items:center;gap:12px;color:var(--dark);"';
    $logoHtml = '<img src="' . h($collegeLogoUrl) . '" alt="logo" style="height:64px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);"><span style="font-size:1.8rem;font-weight:700;">KSREI GUESTHOUSE</span>';

    echo '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>KSREI Guesthouse - ' . h($title) . '</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4a74e5; /* A more vibrant blue */
            --secondary: #1a4d9e; /* Darker blue for contrast */
            --accent: #ffd700;
            --dark: #1e3a8a; /* Deep Navy */
            --light: #f8fafc;
            --success: #10b981;
            --danger: #ef4444;
            --shadow-light: rgba(74, 116, 229, 0.08);
            --shadow-medium: rgba(0,0,0,0.15);
            --shadow-heavy: rgba(74, 116, 229, 0.18);
        }
        body { font-family: "Montserrat", Arial, sans-serif; min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; font-size: 15px; }
        .page-bg { min-height: 100vh; background-attachment: fixed; background-repeat: no-repeat; background-size: cover; overflow-x: hidden; }
        body.home-bg.page-bg { background: linear-gradient(135deg, #e0f7fa 0%, #b3e5fc 100%); }
        body.admin-bg.page-bg { background: linear-gradient(-45deg, #07132a 0%, #0b2545 45%, #0f1720 75%, #07132a 100%); background-size: 400% 400%; animation: bgMove 14s ease infinite; color: #fff; }
        body.staff-bg.page-bg { background: linear-gradient(-45deg, #1f2d47 0%, #3a5793 50%, #1f2d47 100%); background-size: 400% 400%; animation: bgMove 12s ease infinite; color: #fff; }
        @keyframes bgMove { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .navbar-brand { font-weight: 700; }
        .nav-link { font-weight: 600; border-radius: 8px; transition: all 0.3s ease; color: var(--dark) !important; }
        .navbar-nav .nav-link.active, .navbar-nav .nav-link:hover { color: var(--primary) !important; background: rgba(74, 116, 229, 0.1); }
        
        .content-card { backdrop-filter: blur(6px) saturate(120%); background: rgba(255,255,255,0.9); border-radius: 18px; box-shadow: 0 12px 40px rgba(30, 58, 138, 0.1); padding: 2rem; color: #1f2937; margin-top: 30px; animation: fadeInUp 0.7s cubic-bezier(.77,0,.175,1); }
        .btn-primary { background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%); border: none; font-weight: 700; }
        .btn-primary:hover { background: linear-gradient(90deg, var(--secondary) 0%, var(--primary) 100%); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3); }
        .msg { background: #e3f2fd; border-left: 6px solid var(--primary); padding: 12px 20px; border-radius: 8px; margin-top: 20px; margin-bottom: 20px; font-weight: 500; color: var(--dark); animation: fadeInUp 0.5s; }
        .footer { background: linear-gradient(90deg, var(--dark) 0%, #34495e 100%); color: #fff; padding: 20px 8px; margin-top: auto; }
        
        /* Dashboard Tabs Styling */
        .nav-tabs { border-bottom: none; }
        .nav-tabs .nav-link { border: none; border-radius: 12px 12px 0 0; margin-bottom: -1px; background: rgba(255,255,255,0.6); padding: 12px 20px; color: var(--dark) !important; }
        .nav-tabs .nav-link.active { background: #fff !important; border-bottom: 3px solid var(--primary); color: var(--primary) !important; font-weight: 700; }
        
        /* Card refinements */
        .card { border-radius: 16px; transition: all 0.4s; }
        .room-card:hover { transform: translateY(-6px); box-shadow: 0 14px 40px rgba(0,0,0,0.12); }

        .room-info {
            padding: 20px;
        }
        .room-info h5 {
            color: var(--dark);
            margin-bottom: 8px;
            transition: color 0.3s;
        }
        .room-card:hover .room-info h5 {
            color: var(--primary);
        }
        .room-info p {
            color: #7f8c8d;
            font-size: 14px;
        }
        /* Fix for carousel slide transition glitch */
        .carousel-item {
            background-color: #0f7ba2ff;
        }
    </style>
</head>
<body class="' . $bodyClass . ' page-bg">
    <header ' . $headerStyle . '>
        <div class="container">
            <nav class="navbar navbar-expand-lg">
                <a class="navbar-brand" href="?page=' . ($isLoggedIn ? ($isAdmin ? 'admin' : 'staff') : 'home') . '">
                    ' . $logoHtml .   '
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div style="gap: 0.10rem"class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">';
                        if (!$isLoggedIn) {
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'home' ? 'active' : '') . '" href="?page=home"><i class="fas fa-home me-1"></i> Home</a></li>';
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'login' ? 'active' : '') . '" href="?page=login"><i class="fas fa-sign-in-alt me-1"></i> Login</a></li>';
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'register' ? 'active' : '') . '" href="?page=register"><i class="fas fa-user-plus me-1"></i> Register</a></li>';
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'contact' ? 'active' : '') . '" href="?page=contact"><i class="fas fa-envelope me-1"></i> Contact</a></li>';
                        } elseif ($isAdmin) {
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'admin' ? 'active' : '') . '" href="?page=admin"><i class="fas fa-chart-line me-1"></i> Admin Dashboard</a></li>';
                            echo '<li class="nav-item"><a class="nav-link" href="?page=logout"><i class="fas fa-sign-out-alt me-1"></i> Logout</a></li>';
                        } elseif ($isStaff) {
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'staff' ? 'active' : '') . '" href="?page=staff"><i class="fas fa-hotel me-1"></i> Staff Booking</a></li>';
                            echo '<li class="nav-item"><a class="nav-link" href="?page=logout"><i class="fas fa-sign-out-alt me-1"></i> Logout</a></li>';
                        }
                    echo '</ul>
                </div>
            </nav>
        </div>
    </header>
    <div class="container">';
    
    // Display flash message
    if ($flash = get_flash()) {
        echo '<div class="msg alert alert-info" role="alert"><i class="fas fa-bell me-2"></i>' . h($flash) . '</div>';
    }
}

/** Renders the main HTML footer and ends the body. */
function layout_footer(): void {
    global $collegeName;
    
    // Simplified, professional footer
    echo '</div>'; // close .container
    echo '<footer class="footer mt-auto">
        <div class="container text-center">
            <div class="row row-cols-2 row-cols-md-5 g-4 mb-3">
                <div class="col"><h6 class="text-warning">Academic</h6><ul class="list-unstyled small"><li>Departments</li><li>Library</li><li>Curriculum</li></ul></div>
                <div class="col"><h6 class="text-warning">Campus</h6><ul class="list-unstyled small"><li>Photo Gallery</li><li>Contact us</li><li>Location map</li></ul></div>
                <div class="col"><h6 class="text-warning">Accreditation</h6><ul class="list-unstyled small"><li>NAAC</li><li>NBA</li><li>NIRF</li></ul></div>
                <div class="col"><h6 class="text-warning">Links</h6><ul class="list-unstyled small"><li>Online Courses</li><li>Online Payment</li><li>Alumni</li></ul></div>
                <div class="col"><h6 class="text-warning">Contact</h6><ul class="list-unstyled small"><li>+91 4288 - 274213</li><li>principal@ksrce.ac.in</li><li>Tiruchengode - 637 215</li></ul></div>
            </div>
            
    <div class="footer-bottom" style="border-top:1px solid #444;margin-top:18px;padding-top:8px;text-align:center;font-size:0.95em;color:#ccc;">@2025 - K.S.R. College Of Engineering All rights reserved | Professionally Build By <strong style="color:yellow"> SRIRAM & DINESH OF IT DEPT-2023-2027 BATCH</strong>
    <span class="social-icons ms-3" style="letter-spacing:2px;">
    <a href="https://www.facebook.com/KSRCEofficial/" target="_blank" style="margin:0 3px;"><i class="fab fa-facebook-f"></i></a>
    <a href="https://x.com/ksrceofficial?t=28p8b4Fe09aERx3Dr75FUQ&s=08" target="_blank" style="margin:0 3px;"><i class="fab fa-twitter"></i></a>
    <a href="https://www.instagram.com/ksrce_official?utm_source=qr&igsh=MWJzMTYzaTJjeHdoNw==" target="_blank" style="margin:0 3px;"><i class="fab fa-instagram"></i></a>
    <a href="https://www.linkedin.com/company/k-s-r-college-of-engineering-autonomous/" target="_blank" style="margin:0 3px;"><i class="fab fa-linkedin-in"></i></a>
    </span></div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Simple slider logic for room images (can be expanded)
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(".slider").forEach(slider => {
            const slides = slider.querySelectorAll(".slide");
            if (slides.length > 1) {
                let currentSlide = 0;
                slides[0].classList.add("active");
                setInterval(() => {
                    slides[currentSlide].classList.remove("active");
                    currentSlide = (currentSlide + 1) % slides.length;
                    slides[currentSlide].classList.add("active");
                }, 4000); // Change slide every 4 seconds
            } else if (slides.length === 1) {
                 slides[0].classList.add("active");
            }
        });
        
        // Dynamic set of checkin/checkout minimum dates
        const today = new Date().toISOString().split("T")[0];
        document.querySelectorAll("input[type=\'date\']").forEach(input => {
            if (input.min === "") { // Only set if not already set by PHP logic
                input.min = today;
            }
        });
    });
    </script>
</body>
</html>';
}

/** Renders the Home Page. */
function page_home(): void {
    global $db, $roomsDir;
    
    // Fetch all rooms
    $rooms = $db->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="text-center my-5">
        <h1 class="display-3 fw-bolder text-primary animate__animated animate__fadeInDown">Welcome to the KSREI Guesthouse</h1>
        <p class="lead text-secondary animate__animated animate__fadeInUp">Modern, comfortable, and centrally located accommodation for our esteemed guests.</p>
    </div>';

    // About Section
    echo '<h2 class="section-title text-center"><i class="fas fa-info-circle me-2"></i> About Our Guesthouse</h2>
    <div class="row justify-content-center mb-5">
        <div class="col-lg-10">
            <div class="card content-card text-center p-4">
                <p class="lead" style="text-align: justify;">
                Discover comfort and hospitality at the KSREI Guesthouse, where every guest is treated like family. 
                   Our guesthouse offers a tranquil retreat for visitors, faculty, and dignitaries, featuring modern 
                   amenities, lush surroundings, and personalized service. Whether you are here for academic pursuits, 
                   conferences, or leisure, our rooms are designed to provide a restful experience. Enjoy spacious 
                   accommodations, high-speed Wi-Fi, delicious cuisine, and easy access to campus facilities. 
                   Experience the perfect blend of tradition and innovation at KSREI Guesthouse—your home away from home.</p>
            </div>
        </div>
    </div>';

    // Rooms Section
    echo '<h2 class="section-title text-center"><i class="fas fa-bed me-2"></i> Our Rooms & Suites</h2>';
    echo '<div class="row">';
    if (empty($rooms)) {
        echo '<div class="col-12"><div class="alert alert-warning">No rooms have been added yet.</div></div>';
    } else {
        foreach ($rooms as $room) {
            $images = json_decode($room['images'], true) ?: [];
            if (empty($images)) {
                $images = ['default_room.png'];
            }
            echo '<div  class="col-md-4 mb-4">
                <div  style="background:sky blue    ; bottom-padding:15%;border-radius:10%;"class="room-card">
                    <div id="carousel' . $room['room_id'] . '" class="carousel slide" data-bs-ride="carousel">
                        <div class="carousel-inner">';
                            foreach ($images as $index => $img) {
                                echo '<div class="carousel-item ' . ($index === 0 ? 'active' : '') . '">
                                    <img src="rooms/' . h($img) . '" class="d-block w-100 " alt="Room Image">
                                </div>';
                            }
                        echo '</div>';
                        if (count($images) > 1) {
                            echo '<a class="carousel-control-prev" href="#carousel' . $room['room_id'] . '" role="button" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Previous</span>
                            </a>
                            <a class="carousel-control-next" href="#carousel' . $room['room_id'] . '" role="button" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Next</span>
                            </a>';
                        }
                    echo '</div>
                    <div class="card-body">
                        <h5 class="card-title">' . h($room['room_number']) . ' - ' . h($room['title']) . '</h5>
                        <p class="card-text"><i class="fas fa-users"></i> Capacity: ' . h($room['capacity']) . ' Persons</p>
                        <a href="?page=login" class="btn btn-primary">Request Booking</a>
                    </div>
                </div>
            </div>';
        }
    }
    echo '</div>';
}


/** Renders a Login Form. */
function page_login(): void {
    echo '<div class="d-flex justify-content-center align-items-center" style="min-height: calc(100vh - 180px);">
        <div class="card p-4 shadow-lg" style="max-width: 450px; width: 100%;">
            <div class="card-body">
                <h2 class="card-title text-center text-primary mb-4 fw-bold">User Login</h2>
                <form action="?page=login" method="POST">
                    <input type="hidden" name="login" value="1">
                    <div class="mb-3">
                        <label for="username" class="form-label"><i class="fas fa-user-alt me-2"></i>Username</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label"><i class="fas fa-lock me-2"></i>Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-3"><i class="fas fa-sign-in-alt me-1"></i> Log In</button>
                </form>
                <p class="mt-3 text-center small">New user? <a href="?page=register" class="text-primary fw-bold">Register here</a></p>
            </div>
        </div>
    </div>';
}

/** Renders the Registration Form (Dummy/Placeholder). */
function page_register(): void {
    $colleges = [
        "K.S.Rangasamy College of Technology (KSRCT)" => [
            "Mechanical Engineering","Mechatronics Engineering","Civil Engineering","Textile Technology","Biotechnology","Nano Science and Technology","Food Technology",
            "Electrical & Electronics Engineering","Electronics & Communication Engineering","VLSI Design and Technology","Computer Science and Engineering","Information Technology",
            "Computer Science and Business Systems","AIDS","AIML","MBA","MCA","Mathematics","Physics","Chemistry","English","Tamil"
        ],
        "K.S.R. College of Engineering (KSRCE)" => [
            "CSE", "CSE (Cybersecurity)", "CSE (IoT)", "ECE", "EEE", "Biomedical", "Mechanical", "Civil", "Safety & Fire Engg", "IT", "AI & DS",
            "Industrial Safety", "Structural Engg", "Construction Engg", "Big Data Analytics", "Communication Systems", "Embedded Systems", "CAD/CAM",
            "MBA", "MCA", "Ph.D."
        ],
        "KSR College of Arts and Science for Women" => [
            "B.Sc. Computer Science", "B.Sc. (CS) AI & DS", "B.Com", "B.Com (CA)", "B.Sc. Costume Design & Fashion", "B.Sc. Nutrition & Dietetics", "BCA",
            "PG - M.Com", "M.Sc. Costume Design & Fashion"
        ],
        "KSR Institute of Dental Science and Research" => [
            "B.D.S.", "M.D.S.","Prosthodontics", " Orthodontics and Dentofacial Orthopaedics", "Pedodontics and Preventive Dentistry", "Conservative Dentistry and Endodontics", "Periodontics", "Oral Medicine and Radiology", " Oral and Maxillofacial Pathology and Microbiology"
        ],
        "K.S. Rangasamy College of Nursing" => [
            "Nursing"
        ],
        "K.S. Rangasamy College of Allied Health Science" => [
            "Operation Theatre & Anesthesia Tech", "Cardiac Tech", "Radiography & Imaging Tech"
        ],
        "K.S. Rangasamy College of Pharmacy" => [
             "Pharmaceutics", "Pharmacology", "Pharmaceutical Chemistry", "Pharmacognosy"
        ],
        "KSR College of Education" => [
            "B.Ed","M.Ed"
        ],
        "kSREI"=>[
            "CDC","ADMISSION","CHAIRMAN OFFICE","TRANSPORT","ADMINISRATION"
        ]
    ];

    echo '<br><div class="d-flex justify-content-center align-items-center" style="min-height: calc(100vh - 180px);">
        <div class="card p-4 shadow-lg" style="max-width: 500px; width: 100%;">
            <div class="card-body">
                <h2 class="card-title text-center text-primary mb-4 fw-bold">Registration</h2>
                <form action="?page=register" method="POST">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="Choose a username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="Your email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mobile Number</label>
                        <input type="text" name="mobile" class="form-control" placeholder="Your mobile number">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-control" id="roleSelect" required>
                            <option value="">Select Role</option>
                            <option value="admin">Admin</option>
                            <option value="hod">HOD</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">College</label>
                        <select name="college" id="collegeSelect" class="form-control" required>
                            <option value="">Select College</option>';
                            foreach (array_keys($colleges) as $college) {
                                echo '<option value="' . htmlspecialchars($college) . '">' . htmlspecialchars($college) . '</option>';
                            }
    echo '              </select>
                    </div>
                    <div class="mb-3" id="deptField" style="display: none;">
                        <label class="form-label">Department</label>
                        <select name="dept" id="deptSelect" class="form-control">
                            <option value="">Select Department</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Create a password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-3"><i class="fas fa-user-plus me-1"></i> Register</button>
                </form>
                <p class="mt-3 text-center small">Already have an account? <a href="?page=login" class="text-primary fw-bold">Login</a></p>
            </div>
        </div>
        
    </div><br>

    <script>
        // ✅ Properly parse PHP array into JavaScript object
        const collegesData = ' . json_encode($colleges, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ';

        document.getElementById("roleSelect").addEventListener("change", function() {
            const deptField = document.getElementById("deptField");
            if (this.value === "hod") {
                deptField.style.display = "block";
            } else {
                deptField.style.display = "none";
            }
        });

        document.getElementById("collegeSelect").addEventListener("change", function() {
            const college = this.value;
            const deptSelect = document.getElementById("deptSelect");
            deptSelect.innerHTML = "<option value=\'\'>Select Department</option>";

            if (college && collegesData[college]) {
                collegesData[college].forEach(function(dept) {
                    const option = document.createElement("option");
                    option.value = dept;
                    option.textContent = dept;
                    deptSelect.appendChild(option);
                });
            }
        });
    </script>';
}

/** Renders the Contact Page (Dummy/Placeholder). */
function page_contact(): void {
    global $collegeName;
    echo '<div class="card p-5 mt-5 content-card">
        <h2 class="text-primary fw-bold mb-4"><i class="fas fa-map-marker-alt me-2"></i> Contact Us</h2>
        <div class="row">
            <div class="col-md-6">
                <p class="lead">For guesthouse booking inquiries, please contact the main administration office.</p>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item"><i class="fas fa-building me-3 text-primary"></i><strong>College:</strong> ' . h($collegeName) . '</li>
                    <li class="list-group-item"><i class="fas fa-envelope me-3 text-primary"></i><strong>Email:</strong> principal@ksrce.ac.in</li>
                    <li class="list-group-item"><i class="fas fa-phone me-3 text-primary"></i><strong>Phone:</strong> +91 4288 - 274213</li>
                    <li class="list-group-item"><i class="fas fa-map-pin me-3 text-primary"></i><strong>Address:</strong> K.S.R. Kalvi Nagar, Tiruchengode - 637 215</li>
                </ul>
            </div>
            <div class="col-md-6">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3913.3765103607065!2d77.89209597500582!3d11.458019688636733!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3babf19159981881%3A0xc3f83713f019f2ed!2sK.S.R%20College%20of%20Engineering!5e0!3m2!1sen!2sin!4v1700000000000!5m2!1sen!2sin" width="100%" height="300" style="border:0;border-radius:12px;" allowfullscreen="" loading="lazy"></iframe>
            </div>
        </div>
    </div>';
}

/** Renders the Admin Dashboard tabs and content. */
function page_admin(): void {
    require_login();
    global $currentUser, $tab, $db, $roomsDir, $collegeName;
    
    if (strpos($currentUser['role'] ?? '', 'admin') === false) {
        flash("Access denied.");
        header('Location: ?page=staff'); exit;
    }
    
    $tab = $tab ?: 'pending';
    
    echo '<div class="content-card mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-dark fw-bold"><i class="fas fa-user-shield me-2"></i> Admin Dashboard - ' . h($currentUser['username']) . '</h2>
            <div class="badge bg-primary fs-6 p-2">Role: ' . h($currentUser['role']) . '</div>
        </div>
        <ul class="nav nav-tabs" id="adminTabs" role="tablist" style="gap: 0.6rem;">
            <li class="nav-item"><a class="nav-link ' . ($tab === 'pending' ? 'active' : '') . '" href="?page=admin&tab=pending"><i class="fas fa-clock me-1"></i> Pending Bookings</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'rooms' ? 'active' : '') . '" href="?page=admin&tab=rooms"><i class="fas fa-door-open me-1"></i> Room Management</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'book' ? 'active' : '') . '" href="?page=admin&tab=book"><i class="fas fa-calendar-plus me-1"></i> Book Room</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'history' ? 'active' : '') . '" href="?page=admin&tab=history"><i class="fas fa-history me-1"></i> Booking History</a></li>
        </ul>
        <div class="tab-content pt-3">';
        
        if ($tab === 'pending') {
            // Render Pending Bookings Table
            render_admin_bookings_table('pending');
        } elseif ($tab === 'rooms') {
            // Render Room Management Interface
            render_admin_rooms_management();
        } elseif ($tab === 'book') {
            // Render Admin Booking Interface
            render_admin_create_booking();
        } elseif ($tab === 'history') {
            // Render Booking History Table
            render_admin_bookings_table('history');
        }
        
    echo '</div></div>';
}

/** Renders the Staff Dashboard tabs and content. */
function page_staff(): void {
    require_login();
    global $currentUser, $tab, $db, $collegeName;
    
    if ($currentUser['role'] !== 'staff') {
        flash("Access denied.");
        header('Location: ?page=admin'); exit;
    }

    $tab = $tab ?: 'booking';
    
    echo '<div class="content-card mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-dark fw-bold"><i class="fas fa-hotel me-2"></i> Staff Booking Portal - ' . h($currentUser['username']) . '</h2>
            <div class="badge bg-primary fs-6 p-2">ID: ' . h($currentUser['staff_id']) . '</div>
        </div> 
        <ul class="nav nav-tabs" id="staffTabs" role="tablist" style="gap: 0.5rem;">
           
            <li class="nav-item"><a class="nav-link ' . ($tab === 'booking' ? 'active' : '') . '" href="?page=staff&tab=booking"><i class="fas fa-calendar-plus me-1"></i> Create Booking</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'status' ? 'active' : '') . '" href="?page=staff&tab=status"><i class="fas fa-list-alt me-1"></i> My Bookings Status</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'history' ? 'active' : '') . '" href="?page=staff&tab=history"><i class="fas fa-list-alt me-1"></i> My History</a></li>
        </ul>
        <div class="tab-content pt-3">';

        
        if ($tab === 'booking') {
            render_staff_create_booking();
        } elseif ($tab === 'status') {
            render_staff_bookings_status();
        }
        elseif($tab === 'history'){
            render_staff_history();
        }
        
    echo '</div></div>';
}

/** Helper to render Admin Booking Tables (Pending/History). */
function render_admin_bookings_table(string $type = 'pending'): void {
    global $db;
    
    $statusCondition = $type === 'pending' ? " status='pending' " : " status IN ('approved', 'denied', 'cancelled', 'revoked') ";
    $sortOrder = $type === 'pending' ? " ORDER BY created_at ASC " : " ORDER BY checkin DESC ";
    $title = $type === 'pending' ? 'Pending Booking Requests' : 'Booking History';
    
    try {
        $q = "SELECT b.*, r.room_number, u.username as staff_username FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN users u ON b.staff_user_id = u.id WHERE {$statusCondition} {$sortOrder}";
        $bookings = $db->query($q)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo '<div class="alert alert-danger">Error loading bookings.</div>';
        error_log("Admin Bookings Load Error: " . $e->getMessage());
        return;
    }
    
    echo "<h4>{$title}</h4>";
    if (empty($bookings)) {
        echo '<div class="alert alert-info"><i class="fas fa-info-circle me-1"></i> No ' . ($type === 'pending' ? 'pending' : 'historical') . ' bookings found.</div>';
        return;
    }
    
    echo '<div class="table-responsive"><table class="table table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th class="text-nowrap">Booking ID</th>
                <th class="text-nowrap">Staff</th>
                <th class="text-nowrap">Guest Name</th>
                <th class="text-nowrap">Room No</th>
                <th class="text-nowrap">Check-in</th>
                <th class="text-nowrap">Check-out</th>
                <th class="text-nowrap"># Guests</th>
                <th>Status</th>' . ($type === 'pending' ? '<th>Action</th>' : '<th>Details</th>') . '
                ' . ($type === 'history' ? '<th>Receipt</th>' : '') . '</tr>
        </thead>
        <tbody>';
    
    foreach ($bookings as $bk) {
        $statusClass = [
            'pending' => 'badge bg-warning text-dark',
            'approved' => 'badge bg-success',
            'denied' => 'badge bg-danger',
            'cancelled' => 'badge bg-secondary',
            'revoked' => 'badge bg-danger',
        ][$bk['status']] ?? 'badge bg-info';

        echo '<tr>
            <td>' . h($bk['id']) . '</td>
            <td>' . h($bk['staff_username']) . '</td>
            <td>' . h($bk['guest_name']) . '</td>
            <td>' . h($bk['room_number']) . '</td>
            <td class="text-nowrap">' . h(date('Y-m-d H:i', strtotime($bk['checkin']))) . '</td>
            <td class="text-nowrap">' . h(date('Y-m-d H:i', strtotime($bk['checkout']))) . '</td>
            <td>' . h($bk['guest_no']) . '</td>
            <td><span class="' . $statusClass . '">' . h(ucfirst($bk['status'])) . '</span></td>
            <td>';
            
            if ($type === 'pending') {
                echo '<form method="POST" class="d-inline-block me-1">
                    <input type="hidden" name="booking_id" value="' . h($bk['id']) . '">
                    <button type="submit" name="booking_action" value="approve" class="btn btn-sm btn-success" onclick="return confirm(\'Approve booking ' . h($bk['id']) . '?\');"><i class="fas fa-check"></i></button>
                    </form>
                    <form method="POST" class="d-inline-block me-1">
                    <input type="hidden" name="booking_id" value="' . h($bk['id']) . '">
                    <button type="submit" name="booking_action" value="deny" class="btn btn-sm btn-danger" onclick="return confirm(\'Deny booking ' . h($bk['id']) . '?\');"><i class="fas fa-times"></i></button>
                    </form>';
            } elseif ($bk['status'] === 'approved') {
                 echo '<form method="POST" class="d-inline-block">
                    <input type="hidden" name="booking_id" value="' . h($bk['id']) . '">
                    <button type="submit" name="booking_action" value="revoke" class="btn btn-sm btn-warning" onclick="return confirm(\'Revoke booking ' . h($bk['id']) . '?\');"><i class="fas fa-minus-circle"></i> Revoke</button>
                    </form>';
            } else {
                 echo '<span class="text-muted small">N/A</span>';
            }
            
            if ($type === 'history') {
                echo '</td><td><a href="?page=receipt&id=' . h($bk['id']) . '" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-receipt"></i> View</a>';
            }
            echo '</td>
        </tr>';
    }
    
    echo '</tbody></table></div>';
}

/** Helper to render Staff Booking Status Table. */
function render_staff_bookings_status(): void {
    global $db, $currentUser;
    $uid = $currentUser['id'];
    
    try {
        $q = "SELECT b.*, r.room_number FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.staff_user_id = ? ORDER BY b.checkin DESC";
        $st = $db->prepare($q);
        $st->execute([$uid]);
        $bookings = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo '<div class="alert alert-danger">Error loading your bookings.</div>';
        return;
    }
    
    echo '<h4>My Booking Status</h4>';
    if (empty($bookings)) {
        echo '<div class="alert alert-info"><i class="fas fa-info-circle me-1"></i> You have no booking history.</div>';
        return;
    }

    echo '<div class="table-responsive"><table class="table table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Room No</th>
                <th>Guest</th>
                <th>Check-in/out</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($bookings as $bk) {
         $statusClass = [
            'pending' => 'badge bg-warning text-dark',
            'approved' => 'badge bg-success',
            'denied' => 'badge bg-danger',
            'cancelled' => 'badge bg-secondary',
            'revoked' => 'badge bg-danger',
        ][$bk['status']] ?? 'badge bg-info';

        echo '<tr>
            <td>' . h($bk['id']) . '</td>
            <td>' . h($bk['room_number']) . '</td>
            <td>' . h($bk['guest_name']) . '</td>
            <td>' . h(date('Y-m-d', strtotime($bk['checkin']))) . ' to ' . h(date('Y-m-d', strtotime($bk['checkout']))) . '</td>
            <td><span class="' . $statusClass . '">' . h(ucfirst($bk['status'])) . '</span></td>
            <td>';

        if ($bk['status'] === 'pending') {
            echo '<form method="POST" class="d-inline-block">
                <input type="hidden" name="booking_id" value="' . h($bk['id']) . '">
                <button type="submit" name="cancel_booking" value="1" class="btn btn-sm btn-danger" onclick="return confirm(\'Are you sure you want to cancel booking ' . h($bk['id']) . '?\')"><i class="fas fa-times-circle"></i> Cancel</button>
                </form>';
        } elseif ($bk['status'] === 'approved') {
            echo '<a href="?page=receipt&id=' . h($bk['id']) . '" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-print"></i> Print Receipt</a>';
        } else {
            echo '<span class="text-muted small">No action</span>';
        }

        echo '</td>
        </tr>';
    }

    echo '</tbody></table></div>';
}

function render_staff_history():void{
    global $db, $currentUser;
    $uid = $currentUser['id'];
    
    try {
        $q = "SELECT b.*, r.room_number FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.staff_user_id = ? ORDER BY b.checkin DESC";
        $st = $db->prepare($q);
        $st->execute([$uid]);
        $bookings = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo '<div class="alert alert-danger">Error loading your bookings.</div>';
        return;
    }
    
    echo '<h4>My Booking History</h4>';
    if (empty($bookings)) {
        echo '<div class="alert alert-info"><i class="fas fa-info-circle me-1"></i> You have no booking history.</div>';
        return;
    }

    echo '<div class="table-responsive"><table class="table table-hover align-middle">
        <thead class="table-dark">
            <tr>
                <th>Booking ID</th>
                <th>Guest Name</th>
                <th>Room No</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th># Guests</th>
                <th>Status</th>
                <th>Receipt</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($bookings as $bk) {
         $statusClass = [
            'pending' => 'badge bg-warning text-dark',
            'approved' => 'badge bg-success',
            'denied' => 'badge bg-danger',
            'cancelled' => 'badge bg-secondary',
            'revoked' => 'badge bg-danger',
        ][$bk['status']] ?? 'badge bg-info';

        echo '<tr>
            <td>' . h($bk['id']) . '</td>
            <td>' . h($bk['guest_name']) . '</td>
            <td>' . h($bk['room_number']) . '</td>
            <td>' . h(date('Y-m-d', strtotime($bk['checkin']))) . '</td>
            <td>' . h(date('Y-m-d', strtotime($bk['checkout']))) . '</td>
            <td>' . h($bk['guest_no']) . '</td>
            <td><span class="' . $statusClass . '">' . h(ucfirst($bk['status'])) . '</span></td>
            <td>';

        if ($bk['status'] === 'approved') {
            echo '<a href="?page=receipt&id=' . h($bk['id']) . '" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-print"></i> View</a>';
        } else {
            echo '<span class="text-muted small">N/A</span>';
        }

        echo '</td>
        </tr>';
    }

    echo '</tbody></table></div>';

}

/** Helper to render Staff Create Booking Form. */
function render_staff_create_booking(): void {
    global $db, $currentUser, $tab, $collegeName;
    $checkin_date = $_GET['checkin'] ?? null;
    $checkout_date = $_GET['checkout'] ?? null;
    $bookRoomId = (int)($_GET['book_room'] ?? 0);

    echo '<h4>Create New Guesthouse Booking</h4>';

    // Step 1: Date Selection
    if (!$checkin_date || !$checkout_date) {
        echo '<div class="card p-4 shadow-sm" style="max-width: 600px;">
            <h5 class="card-title">Step 1: Select Dates</h5>
            <p class="text-muted small">Enter check-in and check-out dates to see available rooms. Bookings made here are auto-approved.</p>
            <form method="GET" action="?">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="tab" value="book">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Check-in Date & Time</label>
                        <input type="datetime-local" name="checkin" class="form-control" min="' . h(date('Y-m-d\TH:i')) . '" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Check-out Date & Time</label>
                        <input type="datetime-local" name="checkout" class="form-control" min="' . h(date('Y-m-d\TH:i')) . '" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3 w-100">Check Availability</button>
            </form>
        </div>';
        return;
    }

    // Step 2: Room Selection (if dates are provided but no room is selected)
    if ($checkin_date && $checkout_date && !$bookRoomId) {
        $st = $db->prepare("SELECT DISTINCT room_id FROM bookings WHERE status IN ('pending','approved') AND NOT (checkout <= ? OR checkin >= ?)");
        $st->execute([$checkin_date, $checkout_date]);
        $unavailable_room_ids = $st->fetchAll(PDO::FETCH_COLUMN);
        
        $rooms_query = "SELECT * FROM rooms" . (empty($unavailable_room_ids) ? "" : " WHERE id NOT IN (" . implode(',', $unavailable_room_ids) . ")") . " ORDER BY room_number ASC";
        $available_rooms = $db->query($rooms_query)->fetchAll(PDO::FETCH_ASSOC);

        echo '<h5>Step 2: Select an Available Room</h5>';
        echo '<p>Showing rooms available from <strong>' . h($checkin_date) . '</strong> to <strong>' . h($checkout_date) . '</strong>. <a href="?page=staff&tab=booking">Change dates</a></p>';

        if (empty($available_rooms)) {
            echo '<div class="alert alert-warning">No rooms are available for the selected dates. Please try a different date range.</div>';
            return;
        }

        echo '<div class="row">';
        foreach ($available_rooms as $room) {
            $images = json_decode($room['images'], true) ?: [];
            if (empty($images)) {
                $images = ['default_room.png'];
            }
            echo '<div class="col-md-4 mb-4"><div class="card h-100"><img src="rooms/' . h($images[0]) . '" class="card-img-top" style="height:200px; object-fit:cover;"><div class="card-body d-flex flex-column"><h5 class="card-title">' . h($room['title']) . '</h5><p class="card-text small">Room: ' . h($room['room_number']) . ' | Capacity: ' . h($room['capacity']) . '</p><a href="?page=staff&tab=booking&checkin=' . h($checkin_date) . '&checkout=' . h($checkout_date) . '&book_room=' . h($room['id']) . '" class="btn btn-primary mt-auto">Book This Room</a></div></div></div>';
        }
        echo '</div>';
        return;
    }
    
    // Step 3: Booking Form
    $st = $db->prepare("SELECT * FROM rooms WHERE id = ?"); $st->execute([$bookRoomId]); $room = $st->fetch(PDO::FETCH_ASSOC);
    if (!$room) { flash("Invalid room selected."); header('Location: ?page=staff&tab=booking'); exit; }
    
    echo '<div class="row g-4">
        <div class="col-lg-5">'; // Left column for carousel
            $images = json_decode($room['images'], true) ?: ['default_room.png'];
            $carouselId = "booking_carousel_staff_" . $room['id'];
            echo '<div id="' . $carouselId . '" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner rounded-3 shadow-sm">';
                    foreach ($images as $index => $img) {
                        echo '<div class="carousel-item ' . ($index === 0 ? 'active' : '') . '">
                            <img src="rooms/' . h($img) . '" class="d-block w-100" style="height: 300px; object-fit: cover;" alt="Room Image">
                        </div>';
                    }
                echo '</div>';
                if (count($images) > 1) {
                    echo '<button class="carousel-control-prev" type="button" data-bs-target="#' . $carouselId . '" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                          <button class="carousel-control-next" type="button" data-bs-target="#' . $carouselId . '" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>';
                }
            echo '</div>
            <div class="card p-3 mt-3">
                <h5 class="card-title text-primary">' . h($room['room_number']) . ' - ' . h($room['title']) . '</h5>
                <p class="mb-1"><i class="fas fa-users me-2"></i>Capacity: ' . h($room['capacity']) . ' Persons</p>
                <a href="?page=staff&tab=booking&checkin=' . h($checkin_date) . '&checkout=' . h($checkout_date) . '" class="btn btn-sm btn-outline-secondary mt-2 w-50"><i class="fas fa-arrow-left me-1"></i> Change Room</a>
            </div>';
    echo '</div>
        <div class="col-lg-7">'; // Right column for form
            echo '<form action="?page=staff&tab=status" method="POST" class="row g-3">';
            echo '<input type="hidden" name="create_booking" value="1">';
            echo '<input type="hidden" name="room_id" value="' . h($room['id']) . '">';
            
            echo '<h5 class="mt-0 text-secondary"><i class="fas fa-user-tie me-1"></i> Staff Details</h5>';
            echo '<div class="col-12"><label class="form-label">Staff ID</label><input type="text" name="staff_id" class="form-control" value="' . h($currentUser['staff_id'] ?? '') . '" required></div>';
            echo '<div class="col-12"><label class="form-label">Staff Phone</label><input type="text" name="staff_phone" class="form-control" value="' . h($currentUser['mobile'] ?? '') . '" required></div>';
            echo '<div class="col-12"><label class="form-label">College</label><input type="text" name="staff_college" class="form-control" value="' . h($collegeName) . '" readonly></div>';
            echo '<div class="col-12"><label class="form-label">Department</label><input type="text" name="staff_dept" class="form-control" required></div>';
            
            echo '<h5 class="mt-3 text-secondary"><i class="fas fa-user-friends me-1"></i> Guest Details</h5>';
            echo '<div class="col-12"><label class="form-label">Guest Name</label><input type="text" name="guest_name" class="form-control" required></div>';
            echo '<div class="col-12"><label class="form-label">Guest Phone</label><input type="text" name="guest_phone" class="form-control" required></div>';
            echo '<div class="col-12"><label class="form-label">Guest Designation</label><input type="text" name="guest_designation" class="form-control"></div>';
            echo '<div class="col-12"><label class="form-label">Guest College/Organization</label><input type="text" name="guest_college_org" class="form-control"></div>';
            echo '<div class="col-12"><label class="form-label">Guest Address</label><textarea name="guest_address" class="form-control" rows="2"></textarea></div>';
            echo '<div class="col-12"><label class="form-label">Number of Guests</label><input type="number" name="guest_no" class="form-control" min="1" max="' . h($room['capacity']) . '" required></div>';
            
            echo '<h5 class="mt-3 text-secondary"><i class="fas fa-calendar-alt me-1"></i> Dates</h5>';
            echo '<div class="col-md-6"><label class="form-label">Check-in Date</label><input type="date" name="checkin" class="form-control" value="' . h($checkin_date) . '" readonly></div>';
            echo '<div class="col-md-6"><label class="form-label">Check-out Date</label><input type="date" name="checkout" class="form-control" value="' . h($checkout_date) . '" readonly></div>';
            
            echo '<h5 class="mt-3 text-secondary"><i class="fas fa-utensils me-1"></i> Meal Requirements (Pax)</h5>';
            echo '<div class="row g-2">';
            echo '<div class="col-md-4"><label class="form-label small">Morning Refreshment</label><input type="number" name="morning_refreshment" class="form-control" value="0" min="0"></div>';
            echo '<div class="col-md-4"><label class="form-label small">Breakfast (Veg/Non-Veg)</label><div class="input-group"><input type="number" name="breakfast_veg" class="form-control" value="0" min="0" placeholder="Veg"><input type="number" name="breakfast_nonveg" class="form-control" value="0" min="0" placeholder="Non-Veg"></div></div>';
            echo '<div class="col-md-4"><label class="form-label small">Lunch (Veg/Non-Veg)</label><div class="input-group"><input type="number" name="lunch_veg" class="form-control" value="0" min="0" placeholder="Veg"><input type="number" name="lunch_nonveg" class="form-control" value="0" min="0" placeholder="Non-Veg"></div></div>';
            echo '<div class="col-md-4"><label class="form-label small">Evening Refreshment</label><input type="number" name="evening_refreshment" class="form-control" value="0" min="0"></div>';
            echo '<div class="col-md-4"><label class="form-label small">Dinner (Veg/Non-Veg)</label><div class="input-group"><input type="number" name="dinner_veg" class="form-control" value="0" min="0" placeholder="Veg"><input type="number" name="dinner_nonveg" class="form-control" value="0" min="0" placeholder="Non-Veg"></div></div>';
            echo '</div>';

            echo '<div class="col-12 mt-4 text-center">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-paper-plane me-2"></i> Submit Booking Request</button>
            </div>';
            echo '</form>';
    echo '</div>
    </div>';
}


/** Helper to render Admin Create Booking Form. */
function render_admin_create_booking(): void {
    global $db, $currentUser, $collegeName;
    $checkin_date = $_GET['checkin'] ?? null;
    $checkout_date = $_GET['checkout'] ?? null;
    $bookRoomId = (int)($_GET['book_room'] ?? 0);

    echo '<h4>Create New Booking (Admin)</h4>';

    // Step 1: Date Selection
    if (!$checkin_date || !$checkout_date) {
        echo '<div class="card p-4 shadow-sm" style="max-width: 600px;">
            <h5 class="card-title">Step 1: Select Dates</h5>
            <p class="text-muted small">Enter check-in and check-out dates to see available rooms. Bookings made here are auto-approved.</p>
            <form method="GET" action="?">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="tab" value="book">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Check-in Date & Time</label>
                        <input type="datetime-local" name="checkin" class="form-control" min="' . h(date('Y-m-d\TH:i')) . '" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Check-out Date & Time</label>
                        <input type="datetime-local" name="checkout" class="form-control" min="' . h(date('Y-m-d\TH:i')) . '" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3 w-100">Check Availability</button>
            </form>
        </div>';
        return;
    }

    // Step 2: Room Selection
    if ($checkin_date && $checkout_date && !$bookRoomId) {
        $st = $db->prepare("SELECT DISTINCT room_id FROM bookings WHERE status IN ('pending','approved') AND NOT (datetime(checkout) <= ? OR datetime(checkin) >= ?)");
        $st->execute([$checkin_date, $checkout_date]);
        $unavailable_room_ids = $st->fetchAll(PDO::FETCH_COLUMN);

        $rooms_query = "SELECT * FROM rooms" . (empty($unavailable_room_ids) ? "" : " WHERE id NOT IN (" . implode(',', $unavailable_room_ids) . ")") . " ORDER BY room_number ASC";
        $available_rooms = $db->query($rooms_query)->fetchAll(PDO::FETCH_ASSOC);

        echo '<h5>Step 2: Select an Available Room</h5>';
        echo '<p>Showing rooms available from <strong>' . h($checkin_date) . '</strong> to <strong>' . h($checkout_date) . '</strong>. <a href="?page=admin&tab=book">Change dates</a></p>';

        if (empty($available_rooms)) {
            echo '<div class="alert alert-warning">No rooms are available for the selected dates. Please try a different date range.</div>';
            return;
        }

        echo '<div class="row">';
        foreach ($available_rooms as $room) {
            $images = json_decode($room['images'], true) ?: [];
            if (empty($images)) {
                $images = ['default_room.png'];
            }
            echo '<div class="col-md-4 mb-4"><div class="card h-100"><img src="rooms/' . h($images[0]) . '" class="card-img-top" style="height:200px; object-fit:cover;"><div class="card-body d-flex flex-column"><h5 class="card-title">' . h($room['title']) . '</h5><p class="card-text small">Room: ' . h($room['room_number']) . ' | Capacity: ' . h($room['capacity']) . '</p><a href="?page=admin&tab=book&checkin=' . h($checkin_date) . '&checkout=' . h($checkout_date) . '&book_room=' . h($room['id']) . '" class="btn btn-primary mt-auto">Book This Room</a></div></div></div>';
        }
        echo '</div>';
        return;
    }
    
    // Step 3: Booking Form
    $st = $db->prepare("SELECT * FROM rooms WHERE id = ?"); $st->execute([$bookRoomId]); $room = $st->fetch(PDO::FETCH_ASSOC);
    if (!$room) { flash("Invalid room selected."); header('Location: ?page=admin&tab=book'); exit; }
    
    echo '<div class="row g-4">
        <div class="col-lg-5">'; // Left column for carousel
            $images = json_decode($room['images'], true) ?: ['default_room.png'];
            $carouselId = "booking_carousel_admin_" . $room['id'];
            echo '<div id="' . $carouselId . '" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner rounded-3 shadow-sm">';
                    foreach ($images as $index => $img) {
                        echo '<div class="carousel-item ' . ($index === 0 ? 'active' : '') . '">
                            <img src="rooms/' . h($img) . '" class="d-block w-100" style="height: 300px; object-fit: cover;" alt="Room Image">
                        </div>';
                    }
                echo '</div>';
                if (count($images) > 1) {
                    echo '<button class="carousel-control-prev" type="button" data-bs-target="#' . $carouselId . '" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                          <button class="carousel-control-next" type="button" data-bs-target="#' . $carouselId . '" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>';
                }
            echo '</div>
            <div class="card p-3 mt-3">
                <h5 class="card-title text-primary">' . h($room['room_number']) . ' - ' . h($room['title']) . '</h5>
                <p class="mb-1"><i class="fas fa-users me-2"></i>Capacity: ' . h($room['capacity']) . ' Persons</p>
                <a href="?page=admin&tab=book&checkin=' . h($checkin_date) . '&checkout=' . h($checkout_date) . '" class="btn btn-sm btn-outline-secondary mt-2 w-50"><i class="fas fa-arrow-left me-1"></i> Change Room</a>
            </div>';
    echo '</div>
        <div class="col-lg-7">'; // Right column for form
            echo '<form action="?page=admin&tab=book" method="POST" class="row g-3">';
            echo '<input type="hidden" name="admin_create_booking" value="1">';
            echo '<input type="hidden" name="room_id" value="' . h($room['id']) . '">';
            
            echo '<h5 class="mt-0 text-secondary"><i class="fas fa-user-shield me-1"></i> Admin Details</h5>';
            echo '<div class="col-12"><label class="form-label">Admin Username</label><input type="text" name="staff_id" class="form-control" value="' . h($currentUser['username'] ?? '') . '" readonly></div>';
            
            echo '<h5 class="mt-3 text-secondary"><i class="fas fa-user-friends me-1"></i> Guest Details</h5>';
            echo '<div class="col-12"><label class="form-label">Guest Name</label><input type="text" name="guest_name" class="form-control" required></div>';
            echo '<div class="col-12"><label class="form-label">Guest Phone</label><input type="text" name="guest_phone" class="form-control" required></div>';
            echo '<div class="col-12"><label class="form-label">Guest Designation</label><input type="text" name="guest_designation" class="form-control"></div>';
            echo '<div class="col-12"><label class="form-label">Guest College/Organization</label><input type="text" name="guest_college_org" class="form-control"></div>';
            echo '<div class="col-12"><label class="form-label">Guest Address</label><textarea name="guest_address" class="form-control" rows="2"></textarea></div>';
            echo '<div class="col-12"><label class="form-label">Number of Guests</label><input type="number" name="guest_no" class="form-control" min="1" max="' . h($room['capacity']) . '" required></div>';
            
            echo '<h5 class="mt-3 text-secondary"><i class="fas fa-calendar-alt me-1"></i> Dates</h5>';
            echo '<div class="col-md-6"><label class="form-label">Check-in Date & Time</label><input type="datetime-local" name="checkin" class="form-control" value="' . h($checkin_date) . '" readonly></div>';
            echo '<div class="col-md-6"><label class="form-label">Check-out Date & Time</label><input type="datetime-local" name="checkout" class="form-control" value="' . h($checkout_date) . '" readonly></div>';
            
            echo '<h5 class="mt-3 text-secondary"><i class="fas fa-utensils me-1"></i> Meal Requirements (Pax)</h5>';
            echo '<div class="row g-2">';
            echo '<div class="col-md-4"><label class="form-label small">Morning Refreshment</label><input type="number" name="morning_refreshment" class="form-control" value="0" min="0"></div>';
            echo '<div class="col-md-4"><label class="form-label small">Breakfast (Veg/Non-Veg)</label><div class="input-group"><input type="number" name="breakfast_veg" class="form-control" value="0" min="0" placeholder="Veg"><input type="number" name="breakfast_nonveg" class="form-control" value="0" min="0" placeholder="Non-Veg"></div></div>';
            echo '<div class="col-md-4"><label class="form-label small">Lunch (Veg/Non-Veg)</label><div class="input-group"><input type="number" name="lunch_veg" class="form-control" value="0" min="0" placeholder="Veg"><input type="number" name="lunch_nonveg" class="form-control" value="0" min="0" placeholder="Non-Veg"></div></div>';
            echo '<div class="col-md-4"><label class="form-label small">Evening Refreshment</label><input type="number" name="evening_refreshment" class="form-control" value="0" min="0"></div>';
            echo '<div class="col-md-4"><label class="form-label small">Dinner (Veg/Non-Veg)</label><div class="input-group"><input type="number" name="dinner_veg" class="form-control" value="0" min="0" placeholder="Veg"><input type="number" name="dinner_nonveg" class="form-control" value="0" min="0" placeholder="Non-Veg"></div></div>';
            echo '</div>';

            echo '<div class="col-12 mt-4 text-center">
                <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-check-circle me-2"></i> Create & Approve Booking</button>
            </div>';
            echo '</form>';
    echo '</div>
    </div>';
}

/** Helper to render Staff Available Rooms. */
function render_staff_available_rooms(): void {
    global $db, $roomsDir;
    
    try {
        $rooms = $db->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo '<div class="alert alert-danger">Error loading rooms.</div>';
        return;
    }

    echo '<h4>Check Room Availability</h4>';
    echo '<p>Review the rooms and click "Book Now" to check specific dates.</p>';
    
    echo '<div class="rooms-grid">';
    if (empty($rooms)) {
        echo '<div class="col-12"><div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-1"></i> No rooms have been configured by the admin yet.</div></div>';
    } else {
        foreach ($rooms as $room) {
            $images = json_decode($room['images'], true) ?: [];
            $imgSrc = empty($images) ? 'rooms/default_room.png' : 'rooms/' . h($images[0]);
            echo '<div class="room-card">
                <div class="slider" style="height:200px;">
                    <div class="slide active"><img src="rooms/' . h(basename($imgSrc)) . '" alt="Room Image" style="width:100%;height:100%;object-fit:cover;"></div>
                </div>
                <div class="room-info">
                    <h5>' . h($room['room_number']) . ' - ' . h($room['title']) . '</h5>
                    <p><i class="fas fa-users me-1 text-primary"></i> Max Capacity: ' . h($room['capacity']) . '</p>
                    <a href="?page=staff&tab=booking&book_room=' . h($room['id']) . '" class="btn btn-sm btn-primary mt-2"><i class="fas fa-calendar-check me-1"></i> Book Now</a>
                </div>
            </div>';
        }
    }
    echo '</div>';
}

/** Helper to render Admin Room Management (CRUD). */
function render_admin_rooms_management(): void {
    global $db, $roomsDir;

    try {
        $rooms = $db->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo '<div class="alert alert-danger">Error loading rooms.</div>';
        return;
    }

    echo '<h4>Room Management (Total: ' . count($rooms) . ')</h4>';
    
    // --- Add Room Form ---
    echo '<div class="card p-4 mb-4 shadow-sm">
        <h5 class="card-title text-primary"><i class="fas fa-plus-circle me-1"></i> Add New Room</h5>
        <form method="POST" action="?page=admin&tab=rooms" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="room_action" value="add">
            <div class="col-md-3"><input type="text" name="room_number" class="form-control" placeholder="Room Number (e.g., GH-101)" required></div>
            <div class="col-md-4"><input type="text" name="title" class="form-control" placeholder="Title (e.g., Deluxe Suite)" required></div>
            <div class="col-md-2"><input type="number" name="capacity" class="form-control" placeholder="Capacity" min="1" required></div>
            <div class="col-12">
                <label class="form-label small">Room Images (up to 4)</label>
                <div class="row g-2">
                    <div class="col-md-3"><input type="file" name="img1" class="form-control form-control-sm"></div>
                    <div class="col-md-3"><input type="file" name="img2" class="form-control form-control-sm"></div>
                    <div class="col-md-3"><input type="file" name="img3" class="form-control form-control-sm"></div>
                    <div class="col-md-3"><input type="file" name="img4" class="form-control form-control-sm"></div>
                </div>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-plus me-1"></i> Add Room</button></div>
        </form>
    </div>';

    // --- Rooms List ---
    echo '<div class="row">';
    if (empty($rooms)) {
        echo '<div class="col-12"><div class="alert alert-info">No rooms defined. Use the form above to add one.</div></div>';
    } else {
        foreach ($rooms as $room) {
            $images = json_decode($room['images'], true) ?: []; 
            echo '<div class="col-md-4 mb-4">
                <div class="room-card">
                    <div id="carousel' . $room['id'] . '" class="carousel slide" data-bs-ride="carousel">
                        <div class="carousel-inner">';
                            foreach ($images as $index => $img) {
                                echo '<div class="carousel-item ' . ($index === 0 ? 'active' : '') . '">
                                    <img src="rooms/' . h($img) . '" class="d-block w-100" alt="Room Image">
                                </div>'; 
                            }
                        echo '</div>';
                        if (count($images) > 1) {
                            echo '<a class="carousel-control-prev" href="#carousel' . $room['id'] . '" role="button" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Previous</span>
                            </a>
                            <a class="carousel-control-next" href="#carousel' . $room['id'] . '" role="button" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Next</span>
                            </a>';
                        }
                    echo '</div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title">' . h($room['room_number']) . ' - ' . h($room['title']) . '</h5>
                            <span class="badge bg-info"><i class="fas fa-users"></i> ' . h($room['capacity']) . '</span>
                        </div>
                        <p class="text-muted small">Images: ' . count($images) . '</p>
                        <button class="btn btn-sm btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#editRoomModal' . h($room['id']) . '"><i class="fas fa-edit"></i> Edit</button>
                        <form method="POST" class="d-inline-block">
                            <input type="hidden" name="room_action" value="delete">
                            <input type="hidden" name="room_id" value="' . h($room['id']) . '">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'Permanently delete room ' . h($room['room_number']) . ' and its images?\')"><i class="fas fa-trash-alt"></i> Delete</button>
                        </form>
                    </div>
                </div>
            </div>';
            
            // --- Edit Modal (Generated per room) ---
            echo render_edit_room_modal($room);
        }
    }
    echo '</div>';
}

/** Helper to render the Edit Room Modal. */
function render_edit_room_modal(array $room): string {
    global $roomsDir;
    $images = json_decode($room['images'], true) ?: [];
    $image_count = count($images);
    $slots_left = 4 - $image_count;

    $modalHtml = '<div class="modal fade" id="editRoomModal' . h($room['id']) . '" tabindex="-1" aria-labelledby="editRoomLabel' . h($room['id']) . '" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRoomLabel' . h($room['id']) . '">Edit Room: ' . h($room['room_number']) . '</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>';

    // Update Details Form
    $modalHtml .= '<form method="POST" enctype="multipart/form-data" action="?page=admin&tab=rooms">
                    <input type="hidden" name="room_action" value="update"><input type="hidden" name="room_id" value="' . h($room['id']) . '">
                    <div class="modal-body">
                        <h6>Room Details</h6>
                        <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Room Number</label><input type="text" name="room_number" class="form-control" value="' . h($room['room_number']) . '" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">Title</label><input type="text" name="title" class="form-control" value="' . h($room['title']) . '" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Capacity</label><input type="number" name="capacity" class="form-control" value="' . h($room['capacity']) . '" min="1" required>
                        </div>
                        </div>
                        <hr>
                        <h6>Manage Images (' . $image_count . '/4 used)</h6>
                        <div class="row g-3">';
    // Display current images with delete buttons
    foreach ($images as $img) {
        $modalHtml .= '<div class="col-md-3 text-center">
                            <img src="' . h($roomsDir . '/' . $img) . '" class="img-fluid rounded mb-2" style="height: 80px; object-fit: cover;">
                            <button type="submit" form="delete-image-form-' . h($room['id']) . '-' . h(pathinfo($img, PATHINFO_FILENAME)) . '" class="btn btn-danger btn-sm w-100">Delete</button>
                       </div>';
    }
    $modalHtml .= '</div>';

    // Add new image upload fields
    if ($slots_left > 0) {
        $modalHtml .= '<p class="small text-muted mt-3">You can upload ' . $slots_left . ' more image(s).</p><div class="row g-2">';
        for ($i = 1; $i <= $slots_left; $i++) {
            $modalHtml .= '<div class="col-md-3"><input type="file" name="img' . $i . '" class="form-control form-control-sm"></div>';
        }
        $modalHtml .= '</div>';
    }

    $modalHtml .= '
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
                    </div>
                </form>
                <!-- Hidden forms for deleting individual images -->';
    foreach ($images as $img) {
        $modalHtml .= '<form id="delete-image-form-' . h($room['id']) . '-' . h(pathinfo($img, PATHINFO_FILENAME)) . '" method="POST" action="?page=admin&tab=rooms" class="d-none">
            <input type="hidden" name="delete_image_action" value="1">
            <input type="hidden" name="room_id" value="' . h($room['id']) . '">
            <input type="hidden" name="image_name" value="' . h($img) . '">
        </form>';
    }
    $modalHtml .= '</div> <!-- /.modal-content -->
        </div> <!-- /.modal-dialog -->
    </div>'; // End of .modal div
    return $modalHtml;
}

/** Renders the Receipt Page (Printable View). */
function page_receipt(): void {
    global $db, $collegeName;
    $bid = (int)($_GET['id'] ?? 0);
    
    try {
        $st = $db->prepare("SELECT b.*, r.room_number, r.title as room_title, u.username as staff_username, u.mobile as staff_mobile FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN users u ON b.staff_user_id = u.id WHERE b.id = ? AND b.status='approved'");
        $st->execute([$bid]);
        $booking = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $booking = null;
        error_log("Receipt Load Error: " . $e->getMessage());
    }
    
    if (!$booking) {
        layout_header("Error");
        echo '<div class="alert alert-danger mt-5">Booking not found or not yet approved.</div>';
        layout_footer();
        return;
    }
    
    layout_header("Booking Receipt #{$bid}");
    
    echo '<div class="card p-5 mt-5 shadow-lg" id="receipt-area" style="max-width: 800px; margin: 50px auto; border: 1px solid #ddd;">
        <div class="text-center mb-4">
            <h1 class="text-primary fw-bolder">' . h($collegeName) . '</h1>
            <h3 class="text-secondary">Guesthouse Booking Receipt</h3>
        </div>
        <hr>
        <div class="row mb-3 small">
            <div class="col-6"><strong>Booking ID:</strong> #' . h($booking['id']) . '</div>
            <div class="col-6 text-end"><strong>Status:</strong> <span class="badge bg-success">Approved</span></div>
        </div>
        <div class="row mb-4 small">
            <div class="col-6"><strong>Date Generated:</strong> ' . h(date('Y-m-d H:i:s')) . '</div>
            <div class="col-6 text-end"><strong>Staff Username:</strong> ' . h($booking['staff_username']) . ' (' . h($booking['staff_id']) . ')</div>
        </div>
        
        <h5 class="text-primary mt-3 mb-2"><i class="fas fa-calendar-check me-1"></i> Booking Details</h5>
        <div class="table-responsive small">
            <table class="table table-bordered table-sm">
                <tr><th>Room</th><td>' . h($booking['room_number']) . ' - ' . h($booking['room_title']) . '</td></tr>
                <tr><th>Check-in</th><td>' . h(date('Y-m-d H:i', strtotime($booking['checkin']))) . '</td></tr>
                <tr><th>Check-out</th><td>' . h(date('Y-m-d H:i', strtotime($booking['checkout']))) . '</td></tr>
                <tr><th>Guest(s)</th><td>' . h($booking['guest_name']) . ' (' . h($booking['guest_no']) . ' Pax)</td></tr>
                <tr><th>Guest Designation</th><td>' . h($booking['guest_designation']) . ' from ' . h($booking['guest_college_org']) . '</td></tr>
            </table>
        </div>

        <h5 class="text-primary mt-4 mb-2"><i class="fas fa-utensils me-1"></i> Meal Requirements (Pax)</h5>
        <div class="table-responsive small">
             <table class="table table-bordered table-sm text-center">
                <thead><tr><th>Refreshment (M/E)</th><th>Breakfast (V/NV)</th><th>Lunch (V/NV)</th><th>Dinner (V/NV)</th></tr></thead>
                <tbody>
                    <tr>
                        <td>' . h($booking['morning_refreshment']) . ' / ' . h($booking['evening_refreshment']) . '</td>
                        <td>' . h($booking['breakfast_veg']) . ' / ' . h($booking['breakfast_nonveg']) . '</td>
                        <td>' . h($booking['lunch_veg']) . ' / ' . h($booking['lunch_nonveg']) . '</td>
                        <td>' . h($booking['dinner_veg']) . ' / ' . h($booking['dinner_nonveg']) . '</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="row mt-5">
            <div class="col-6 text-center">
                <p>_________________________</p>
                <p>Staff In-Charge Signature</p>
            </div>
            <div class="col-6 text-center">
                <img src="' . h($booking['admin_sig_path'] ?? 'uploads/sign.png') . '" style="height: 60px; width: 150px; object-fit: contain; border-bottom: 1px solid #000; display: block; margin: 0 auto;">
                <p>_________________________</p>
                <p>Approved By (Admin)</p>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <button class="btn btn-info print-button" onclick="window.print()"><i class="fas fa-print me-2"></i> Print Receipt</button>
            <a href="?page=' . (is_logged_in() ? 'staff' : 'admin') . '" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i> Go Back</a>
        </div>
    </div>';
    
    // Add print-specific CSS
    echo '<style>@media print {.print-button, .navbar, .footer { display: none !important; } #receipt-area { margin: 0 auto; border: none !important; box-shadow: none !important; } body { background: none !important; }}</style>';
    
    layout_footer();
    return;
}

// ======================================================================================
// 4. Main Router (Moved to the end of the file)
// ======================================================================================

switch ($page) {
    case 'home':
        layout_header("Home"); // This call starts outputting HTML
        page_home(); // This function also outputs HTML
        break;
    case 'login':
        layout_header("Login");
        page_login();
        break;
    case 'register':
        layout_header("Register"); 
        page_register();
        break;
    case 'contact':
        layout_header("Contact");
        page_contact();
        break;
    case 'admin':
        layout_header("Admin Dashboard"); // This call starts outputting HTML
        page_admin();
        break;
    case 'staff':
        layout_header("Staff Portal");
        page_staff();
        break;
    case 'receipt':
        // This page handles its own headers
        page_receipt();
        // Skip default footer call
        return;
    default:
        // Default to home if page is invalid
        layout_header("Home");
        page_home();
        break;
}

layout_footer();
// The end.