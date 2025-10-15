<?php

// ======================================================================================
// 1. Configuration, Setup & Helpers
// ======================================================================================

// Bootstrap application and shared modules
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pages/home.php';
require_once __DIR__ . '/pages/login.php';
require_once __DIR__ . '/pages/register.php';
require_once __DIR__ . '/pages/contact.php';
require_once __DIR__ . '/pages/admin.php';
require_once __DIR__ . '/pages/staff.php';
require_once __DIR__ . '/pages/profile.php';
require_once __DIR__ . '/pages/receipt.php';
// components/components.php is a placeholder; not required to avoid function redeclarations

// Get current page and tab from URL
$page = $_GET['page'] ?? 'home';
$tab = $_GET['tab'] ?? null;

// Helpers are now loaded from helpers.php

// Database and schema are initialized in db.php; current_user is provided by helpers.php

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
        $st = $db->prepare("SELECT id, password, role FROM users WHERE username = ? LIMIT 1");
        $st->execute([$username]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            flash("Welcome, " . h($username) . "!");
            $redirectPage = strpos($user['role'], 'admin') !== false ? 'admin' : 'staff';
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
} elseif ($page === 'logout') {
    session_destroy();
    header('Location: ?page=home'); exit;
}

// Registration Handler from trail.php, with improvements
if ($page === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $college = trim($_POST['college'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (empty($username) || empty($email) || empty($password) || empty($college) || empty($department)) {
        flash("All fields, including college and department, are required.");
        header('Location: ?page=register'); exit;
    }

    try {
        // Check if username or email already exists
        $st = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $st->execute([$username, $email]);
        if ($st->fetchColumn() > 0) {
            flash("Username or email already exists.");
            header('Location: ?page=register'); exit;
        }

        // Check if a staff member from this college/department already exists
        $st = $db->prepare("SELECT COUNT(*) FROM users WHERE college = ? AND department = ? AND role = 'staff'");
        $st->execute([$college, $department]);
        if ($st->fetchColumn() > 0) {
            flash("A staff account for " . h($department) . " at " . h($college) . " already exists.");
            header('Location: ?page=register'); exit;
        }

        $pwHash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username, password, email, mobile, college, department, role) VALUES (?, ?, ?, ?, ?, ?, 'staff')")
           ->execute([$username, $pwHash, $email, $mobile, $college, $department]);

        flash("Registration successful! You can now log in.");
        header('Location: ?page=login'); exit;
    } catch (PDOException $e) {
        flash("Database error during registration.");
        error_log("Register DB Error: " . $e->getMessage());
        header('Location: ?page=register'); exit;
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

// Admin: Block Room
if ($page === 'admin' && isset($_POST['admin_block_room']) && is_logged_in() && strpos($currentUser['role'] ?? '', 'admin') !== false) {
    $room_id = (int)($_POST['room_id'] ?? 0);
    $checkin = $_POST['checkin'] ?? null;
    $checkout = $_POST['checkout'] ?? null;
    $reason = trim($_POST['reason'] ?? 'Blocked by Admin');

    if (!$room_id || empty($checkin) || empty($checkout)) {
        flash("Room and check-in/out dates are mandatory for blocking.");
        header('Location: ?page=admin&tab=block'); exit;
    }

    try {
        // Final availability check
        $st = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id=? AND status IN ('pending','approved','blocked') AND NOT (datetime(checkout) <= datetime(?) OR datetime(checkin) >= datetime(?))");
        $st->execute([$room_id, $checkin, $checkout]);
        if ($st->fetchColumn() > 0) {
            flash("Room is no longer available for the selected range.");
            header('Location: ?page=admin&tab=block'); exit;
        }

        $q = "INSERT INTO bookings (room_id, staff_user_id, guest_name, checkin, checkout, status, approved_by, approved_at) VALUES (?,?,?,?,?,?,?,?)";
        $stmt = $db->prepare($q);
        $stmt->execute([$room_id, $currentUser['id'], $reason, $checkin, $checkout, 'blocked', $currentUser['id'], date('Y-m-d H:i:s')]);
        flash("Room blocked successfully for: " . h($reason));
    } catch (PDOException $e) {
        flash("Database error during room blocking.");
        error_log("Admin Block Room DB Error: " . $e->getMessage());
    }
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
if ($page === 'profile' && isset($_POST['update_profile']) && is_logged_in()) {
    $uid = $currentUser['id'];
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $staff_id = trim($_POST['staff_id'] ?? ''); // Add this line
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Verify current password
    if (empty($current_password) || !password_verify($current_password, $currentUser['password'])) {
        flash("Incorrect current password. Your details were not updated.");
        header('Location: ?page=profile'); exit;
    }

    // Update logic
    try {
        $sql = "UPDATE users SET username=?, email=?, mobile=?, staff_id=? WHERE id=?"; // Add staff_id to query
        $params = [$username, $email, $mobile, $staff_id, $uid]; // Add staff_id to params

        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) { flash("New passwords do not match."); header('Location: ?page=profile'); exit; }
            $sql = "UPDATE users SET username=?, email=?, mobile=?, staff_id=?, password=? WHERE id=?"; // Add staff_id to query
            $params = [$username, $email, $mobile, $staff_id, password_hash($new_password, PASSWORD_DEFAULT), $uid]; // Add staff_id to params
        }

        $db->prepare($sql)->execute($params);
        flash("Your profile has been updated successfully.");
    } catch (PDOException $e) {
        flash("Database error: Could not update profile. The username or email might already be taken.");
    }
    header('Location: ?page=profile'); exit;
}


// Staff: Create Booking
if ($page === 'staff' && isset($_POST['create_booking']) && is_logged_in() && $currentUser['role'] === 'staff') {
    $data = [
        'room_id' => (int)($_POST['room_id'] ?? 0),
        'staff_user_id' => $currentUser['id'],
        'staff_id' => trim($_POST['staff_id'] ?? $currentUser['staff_id'] ?? ''),
        'staff_phone' => trim($_POST['staff_phone'] ?? $currentUser['mobile'] ?? ''),
        'staff_college' => trim($_POST['staff_college'] ?? $collegeName),
        'staff_dept' => trim($_POST['staff_dept'] ?? ''),
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
        $st = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id=? AND status IN ('pending','approved','blocked') AND NOT (datetime(checkout) <= datetime(?) OR datetime(checkin) >= datetime(?))");
        $st->execute([$data['room_id'], $data['checkin'], $data['checkout']]);
        if ($st->fetchColumn() > 0) {
            flash("Room not available for the selected range. Check the room's availability.");
            header("Location: ?page=staff&tab=booking&book_room={$data['room_id']}"); exit;
        }

        // Insert booking
        $q = "INSERT INTO bookings (room_id, staff_user_id, staff_id, staff_phone, staff_college, staff_dept, guest_no, guest_name, guest_phone, guest_designation, guest_college_org, guest_address, morning_refreshment, breakfast_veg, breakfast_nonveg, lunch_veg, lunch_nonveg, dinner_veg, dinner_nonveg, evening_refreshment, checkin, checkout, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
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

// Admin/Staff: Place Food Order
if (in_array($page, ['admin', 'staff']) && isset($_POST['place_food_order']) && is_logged_in()) {
    $order_date = $_POST['order_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? 'N/A');

    if (empty($notes)) {
        flash("A reason/note for the food order is required.");
        header('Location: ?page=' . $page . '&tab=food');
        exit;
    }

    try {
        $stmt = $db->prepare("INSERT INTO food_orders 
            (order_for_date, ordered_by_userid, notes, morning_refreshment, breakfast_veg, breakfast_nonveg,
             lunch_veg, lunch_nonveg, evening_refreshment, dinner_veg, dinner_nonveg)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $order_date,
            $currentUser['id'],
            $notes,
            (int)($_POST['morning_refreshment'] ?? 0),
            (int)($_POST['breakfast_veg'] ?? 0),
            (int)($_POST['breakfast_nonveg'] ?? 0),
            (int)($_POST['lunch_veg'] ?? 0),
            (int)($_POST['lunch_nonveg'] ?? 0),
            (int)($_POST['evening_refreshment'] ?? 0),
            (int)($_POST['dinner_veg'] ?? 0),
            (int)($_POST['dinner_nonveg'] ?? 0),
        ]);
        flash('✅ Food order placed successfully for ' . h(date('F j, Y', strtotime($order_date))) . '.');
    } catch (PDOException $e) {
        flash('❌ Error placing order. Please try again.');
        error_log("Food Order Insert Error: " . $e->getMessage());
    }
    header('Location: ?page=' . $page . '&tab=food');
    exit;
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
    $role = $currentUser['role'] ?? '';
    $isStaff = $isLoggedIn && ($role === 'staff');
    $isAdmin = $isLoggedIn && strpos($role, 'admin') !== false;
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
                    ' . $logoHtml .       '
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
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'profile' ? 'active' : '') . '" href="?page=profile"><i class="fas fa-user-circle me-1"></i> Profile</a></li>';
                            echo '<li class="nav-item"><a class="nav-link" href="?page=logout"><i class="fas fa-sign-out-alt me-1"></i> Logout</a></li>';
                        } elseif ($isStaff) {
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'staff' ? 'active' : '') . '" href="?page=staff"><i class="fas fa-hotel me-1"></i> Staff Booking</a></li>';
                            echo '<li class="nav-item"><a class="nav-link ' . ($page === 'profile' ? 'active' : '') . '" href="?page=profile" ><i class="fas fa-user-circle me-1"></i> Profile</a></li>';
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
            <div class="text-center pt-3 small" style="border-top: 1px solid rgba(255,255,255,0.1); color: #ccc;">
                &copy; ' . date('Y') . ' - ' . h($collegeName) . '. All rights reserved.
            </div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Simple slider logic for room images (can be expanded)
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(".slider").forEach(slider => {
            const slides = slider.querySelectorAll(".slide");
            if (slides.length > 0) {
                slides[0].classList.add("active");
                if (slides.length > 1) {
                    let currentSlide = 0;
                    setInterval(() => {
                        slides[currentSlide].classList.remove("active");
                        currentSlide = (currentSlide + 1) % slides.length;
                        slides[currentSlide].classList.add("active");
                    }, 4000); // Change slide every 4 seconds
                }
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

// page_home extracted to pages/home.php


// page_login extracted to pages/login.php

// page_register extracted to pages/register.php

// page_contact extracted to pages/contact.php


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
            <a class="nav-link ' . ($tab === 'block' ? 'active' : '') . '" href="?page=admin&tab=block"><i class="fas fa-ban fa-fw me-2"></i> Block Room</a>
            <a class="nav-link ' . ($tab === 'food' ? 'active' : '') . '" href="?page=admin&tab=food"><i class="fas fa-utensils fa-fw me-2"></i> Today Food</a>
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
        }elseif ($tab === 'block') {
                    render_admin_block_room();
// The end.
        } elseif ($tab === 'food') {
                    render_food_report();
                }   
        
    echo '</div></div>';
}

/** Renders the Staff Dashboard tabs and content. */
function page_staff(): void {
    require_login();
    global $currentUser, $tab, $db, $collegeName;

    if (!$currentUser || $currentUser['role'] != 'staff') {
        flash("Access denied.");
        header("Location: ?page=login");
        exit;
    }

    $tab = $tab ?: 'booking';
    
    echo '<div class="content-card mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-dark fw-bold"><i class="fas fa-hotel me-2"></i> Staff Booking Portal - ' . h($currentUser['username']) . '</h2>
          
            <div class="d-flex gap-2"><div class="badge bg-primary fs-6 p-2">User ID: ' . h($currentUser['id']) . '</div><div class="badge bg-info fs-6 p-2">Staff ID: ' . h($currentUser['staff_id']) . '</div></div>
        </div> 
        <ul class="nav nav-tabs" id="staffTabs" role="tablist" style="gap: 0.5rem;">
           
            <li class="nav-item"><a class="nav-link ' . ($tab === 'booking' ? 'active' : '') . '" href="?page=staff&tab=booking"><i class="fas fa-calendar-plus me-1"></i> Create Booking</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'status' ? 'active' : '') . '" href="?page=staff&tab=status"><i class="fas fa-list-alt me-1"></i> My Bookings Status</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'food' ? 'active' : '') . '" href="?page=staff&tab=food"><i class="fas fa-utensils fa-fw me-2"></i> Today Food</a></li>
            <li class="nav-item"><a class="nav-link ' . ($tab === 'history' ? 'active' : '') . '" href="?page=staff&tab=history"><i class="fas fa-list-alt me-1"></i> My History</a></li>
        </ul>
        <div class="tab-content pt-3">';

       
       
        
         if ($tab === 'booking') { render_staff_create_booking(); }
        elseif ($tab === 'status') { render_staff_bookings_status(); }
        elseif ($tab === 'history') { render_staff_history(); }
        elseif ($tab === 'food') { render_food_report(); }
        else { render_staff_create_booking(); }
        
    echo '</div></div>';
}

/** Helper to render Admin Booking Tables (Pending/History). */
function render_admin_bookings_table(string $type = 'pending'): void {
    global $db;
    
    $statusCondition = $type === 'pending' ? " status='pending' " : " status IN ('approved', 'denied', 'cancelled', 'revoked', 'blocked') ";
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
            
            if ($bk['status'] === 'pending') {
                echo '<form method="POST" class="d-inline-block me-1">
                    <input type="hidden" name="booking_id" value="' . h($bk['id']) . '">
                    <button type="submit" name="booking_action" value="approve" class="btn btn-sm btn-success" onclick="return confirm(\'Approve booking ' . h($bk['id']) . '?\');"><i class="fas fa-check"></i></button>
                    </form>
                    <form method="POST" class="d-inline-block me-1">
                    <input type="hidden" name="booking_id" value="' . h($bk['id']) . '">
                    <button type="submit" name="booking_action" value="deny" class="btn btn-sm btn-danger" onclick="return confirm(\'Deny booking ' . h($bk['id']) . '?\');"><i class="fas fa-times"></i></button>
                    </form>';
            } elseif ($bk['status'] === 'approved' || $bk['status'] === 'blocked') {
                $can_revoke = false;
                try {
                    $checkin = new DateTime($bk['checkin']);
                    $now = new DateTime();
                    $cutoff = (clone $checkin)->sub(new DateInterval('P4D'));
                    if ($now < $cutoff) {
                        $can_revoke = true;
                    }
                } catch (Exception $e) { /* Date parsing error, cannot revoke */ }

                if ($can_revoke) {
                    echo '<form method="POST" class="d-inline-block">
                        <input type="hidden" name="booking_id" value="' . h($bk['id']) . '">
                        <button type="submit" name="booking_action" value="revoke" class="btn btn-sm btn-warning" onclick="return confirm(\'Revoke booking ' . h($bk['id']) . '?\');"><i class="fas fa-minus-circle"></i> Revoke</button>
                        </form>';
                } else {
                    echo '<span class="text-muted small">No revoke can be done</span>';
                }
            } else {
                 echo '<span class="text-muted small">N/A</span>';
            }
            
            if ($type === 'history' && $bk['status'] !== 'blocked') {
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
            'blocked' => 'badge bg-dark',
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
     $collegeDepartments = [
        "K.S.Rangasamy College of Technology (Autonomous)" => [
            "Artificial Intelligence and Data Science", "Biotechnology", "Chemistry", "Civil Engineering",
            "Computer Science and Business System", "Computer Science and Engineering",
            "Computer Science and Engineering - Artificial Intelligence and Machine Learning",
            "Electrical and Electronics Engineering", "Electronics and Communication Engineering",
            "Electronics Engineering (VLSI Design and Technology)", "English", "Food Technology",
            "Information Technology", "Library", "Master of Business Administration", "Mathematics",
            "Mechanical Engineering", "Mechatronics Engineering", "Nanoscience and Technology", "Physics",
            "Tamil", "Textile Technology"
        ],
        "K.S.R. College of Engineering" => [
            "Artificial Intelligence and Data Science", "Biomedical Engineering", "Civil Engineering",
            "Computer Science and Engineering", "Computer Science and Engineering (Cyber Security)",
            "Computer Science and Engineering (Internet of Things)", "Electrical and Electronics Engineering",
            "Electronics and Communication Engineering", "Information Technology",
            "Master of Business Administration", "Master of Computer Applications", "Mechanical Engineering",
            "Safety and Fire Engineering"
        ],
        "KSR Institute for Engineering and Technology (KSRIET)" => [
            "Bio-Medical", "Computer Science and Engineering", "Computer Science and Engineering (Cyber Security)",
            "Electrical and Electronics Engineering", "Electronics and Communication Engineering",
            "Information Technology", "Mechanical Engineering"
        ],
        "K.S. Rangasamy College of Arts and Science (KSRCAS)" => [
            "Biochemistry", "Biotechnology", "Business Administration", "Chemistry", "Commerce",
            "Commerce (Banking & Insurance)", "Commerce (Computer Applications)", "Commerce (Professional Accounting)",
            "Computer Applications", "Computer Science", "Computer Science (Data Science)", "Electronics and Communication",
            "English", "Management", "Mathematics", "Microbiology", "Physical Education", "Physics",
            "Tamil", "Textile and Fashion Designing", "Visual Communication"
        ],
        "KS Rangasamy College of Nursing" => [
            "A.V. Aids Lab / Simulation Lab", "Child Health Nursing Lab", "Community Health Nursing Lab",
            "Computer Lab", "Nursing Foundation Lab", "Nutrition Lab", "Obstetrics and Gynecology Lab", "Preclinical Science Lab"
        ],
        "KSR Institute of Dental Science and Research (KSRIDSR)" => [
            "Biochemistry", "Oral and Maxillofacial Surgery", "Microbiology", "Pathology"
        ],
        "K.S.Rangasamy College of Allied Health Science" => ["General Department"],
        "K.S.Rangasamy College Of Pharmacy" => ["General Department"],
        "KSR College of Education" => ["General Department"]
    ];
    $checkin_date = $_GET['checkin'] ?? null;
    $checkout_date = $_GET['checkout'] ?? null;
    $bookRoomId = (int)($_GET['book_room'] ?? 0);

    echo '<h4>Create New Guesthouse Booking</h4>';

    // Step 1: Date Selection
    if (!$checkin_date || !$checkout_date) {
        echo '<div class="card p-4 shadow-sm" style="max-width: 600px;">
            <h5 class="card-title">Step 1: Select Your Dates</h5>
            <p class="text-muted small">Enter your desired check-in and check-out dates to see available rooms.</p>
            <form method="GET" action="?">
                <input type="hidden" name="page" value="staff">
                <input type="hidden" name="tab" value="booking">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Check-in Date</label>
                        <input type="date" name="checkin" class="form-control" min="' . h(date('Y-m-d')) . '" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Check-out Date</label>
                        <input type="date" name="checkout" class="form-control" min="' . h(date('Y-m-d', strtotime('+1 day'))) . '" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3 w-100">Check Availability</button>
            </form>
        </div>';
        return;
    }

    // Step 2: Room Selection (if dates are provided but no room is selected)
    if ($checkin_date && $checkout_date && !$bookRoomId) {
        $st = $db->prepare("SELECT DISTINCT room_id FROM bookings WHERE status IN ('pending','approved','blocked') AND NOT (checkout <= ? OR checkin >= ?)");
        $st->execute([$checkin_date, $checkout_date]);
        $unavailable_room_ids = $st->fetchAll(PDO::FETCH_COLUMN);
        
        $all_rooms = $db->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);

        echo '<h5>Step 2: Select an Available Room</h5>';
        echo '<p>Showing rooms available from <strong>' . h($checkin_date) . '</strong> to <strong>' . h($checkout_date) . '</strong>. <a href="?page=staff&tab=booking">Change dates</a></p>';

        if (empty($all_rooms)) {
            echo '<div class="alert alert-warning">No rooms have been configured by the admin.</div>';
            return;
        }

        echo '<div class="row">';
        foreach ($all_rooms as $room) {
            $is_available = !in_array($room['id'], $unavailable_room_ids);
            $images = json_decode($room['images'], true) ?: [];
            if (empty($images)) {
                $images = ['default_room.png'];
            }
            echo '<div class="col-md-4 mb-4"><div class="card h-100 ' . (!$is_available ? 'bg-light' : '') . '">
                    <img src="rooms/' . h($images[0]) . '" class="card-img-top" style="height:200px; object-fit:cover;' . (!$is_available ? 'filter: grayscale(80%);' : '') . '">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">' . h($room['title']) . '</h5>
                        <p class="card-text small">Room: ' . h($room['room_number']) . ' | Capacity: ' . h($room['capacity']) . '</p>';
            if ($is_available) {
                echo '<a href="?page=staff&tab=booking&checkin=' . h($checkin_date) . '&checkout=' . h($checkout_date) . '&book_room=' . h($room['id']) . '" class="btn btn-primary mt-auto">Book This Room</a>';
            } else {
                echo '<button class="btn btn-secondary mt-auto" disabled>Booked</button>';
            }
            echo '</div></div></div>';
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
 echo '<div class="col-12"><label>College</label><select name="staff_college" id="collegeSelect" class="form-select" required><option value="">-- Select College --</option>';
    foreach ($collegeDepartments as $college => $depts) {
        echo '<option value="' . h($college) . '">' . h($college) . '</option>';
    }
    echo '</select></div>';

    // Department dropdown (changes dynamically)
    echo '<div class="col-12"><label>Department</label><select name="staff_dept" id="deptSelect" class="form-select" required><option value="">-- Select Department --</option></select></div>';
            
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
     echo '<script>
        const collegeDepartments = ' . json_encode($collegeDepartments) . ';
        const collegeSelect = document.getElementById("collegeSelect");
        const deptSelect = document.getElementById("deptSelect");

        collegeSelect.addEventListener("change", function() {
            const selectedCollege = this.value;
            deptSelect.innerHTML = "<option value=\'\'>-- Select Department --</option>";
            if (collegeDepartments[selectedCollege]) {
                collegeDepartments[selectedCollege].forEach(dept => {
                    const opt = document.createElement("option");
                    opt.value = dept;
                    opt.textContent = dept;
                    deptSelect.appendChild(opt);
                });
            }
        });
    </script>';
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
        $st = $db->prepare("SELECT DISTINCT room_id FROM bookings WHERE status IN ('pending','approved','blocked') AND NOT (datetime(checkout) <= ? OR datetime(checkin) >= ?)");
        $st->execute([$checkin_date, $checkout_date]);
        $unavailable_room_ids = $st->fetchAll(PDO::FETCH_COLUMN);

        $all_rooms = $db->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);

        echo '<h5>Step 2: Select an Available Room</h5>';
        echo '<p>Showing rooms available from <strong>' . h($checkin_date) . '</strong> to <strong>' . h($checkout_date) . '</strong>. <a href="?page=admin&tab=book">Change dates</a></p>';

        if (empty($all_rooms)) {
            echo '<div class="alert alert-warning">No rooms have been configured.</div>';
            return;
        }

        echo '<div class="row">';
        foreach ($all_rooms as $room) {
            $is_available = !in_array($room['id'], $unavailable_room_ids);
            $images = json_decode($room['images'], true) ?: [];
            if (empty($images)) {
                $images = ['default_room.png'];
            }
            echo '<div class="col-md-4 mb-4"><div class="card h-100 ' . (!$is_available ? 'bg-light' : '') . '">
                    <img src="rooms/' . h($images[0]) . '" class="card-img-top" style="height:200px; object-fit:cover;' . (!$is_available ? 'filter: grayscale(80%);' : '') . '">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">' . h($room['title']) . '</h5>
                        <p class="card-text small">Room: ' . h($room['room_number']) . ' | Capacity: ' . h($room['capacity']) . '</p>';
            if ($is_available) {
                echo '<a href="?page=admin&tab=book&checkin=' . h($checkin_date) . '&checkout=' . h($checkout_date) . '&book_room=' . h($room['id']) . '" class="btn btn-primary mt-auto">Book This Room</a>';
            } else {
                echo '<button class="btn btn-secondary mt-auto" disabled>Booked</button>';
            }
            echo '</div></div></div>';
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
            echo '<div class="col-12"><label class="form-label">Admin Phone</label><input type="text" name="staff_id" class="form-control" value="' . h($currentUser['mobile'] ?? '') . '" readonly></div>';
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

/** Helper to render Admin Block Room Form. */
function render_admin_block_room(): void {
    global $db;
    $checkin_date = $_GET['checkin'] ?? null;
    $checkout_date = $_GET['checkout'] ?? null;
    $blockRoomId = (int)($_GET['block_room'] ?? 0);
    $reason = $_GET['reason'] ?? '';

    echo '<h4>Block a Room for Emergency/Maintenance</h4>';

    // Step 1: Date Selection
    if (!$checkin_date || !$checkout_date) {
        echo '<div class="card p-4 shadow-sm" style="max-width: 600px;">
            <h5 class="card-title">Step 1: Select Dates to Block</h5>
            <form method="GET" action="?">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="tab" value="block">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Block From</label>
                        <input type="datetime-local" name="checkin" class="form-control" min="' . h(date('Y-m-d\TH:i')) . '" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Block Until</label>
                        <input type="datetime-local" name="checkout" class="form-control" min="' . h(date('Y-m-d\TH:i')) . '" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3 w-100">Check Rooms</button>
            </form>
        </div>';
        return;
    }

    // Step 2: Room Selection
    if ($checkin_date && $checkout_date && !$blockRoomId) {
        $st = $db->prepare("SELECT DISTINCT room_id FROM bookings WHERE status IN ('pending','approved','blocked') AND NOT (datetime(checkout) <= ? OR datetime(checkin) >= ?)");
        $st->execute([$checkin_date, $checkout_date]);
        $unavailable_room_ids = $st->fetchAll(PDO::FETCH_COLUMN);

        $all_rooms = $db->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);

        echo '<h5>Step 2: Select a Room to Block</h5>';
        echo '<p>Blocking from <strong>' . h($checkin_date) . '</strong> to <strong>' . h($checkout_date) . '</strong>. <a href="?page=admin&tab=block">Change dates</a></p>';

        echo '<div class="row">';
        foreach ($all_rooms as $room) {
            $is_available = !in_array($room['id'], $unavailable_room_ids);
            echo '<div class="col-md-4 mb-4"><div class="card h-100 ' . (!$is_available ? 'bg-light' : '') . '">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">' . h($room['title']) . '</h5>
                        <p class="card-text small">Room: ' . h($room['room_number']) . '</p>';
            if ($is_available) {
                echo '<a href="?page=admin&tab=block&checkin=' . h($checkin_date) . '&checkout=' . h($checkout_date) . '&block_room=' . h($room['id']) . '" class="btn btn-danger mt-auto">Block This Room</a>';
            } else {
                echo '<button class="btn btn-secondary mt-auto" disabled>Booked/Blocked</button>';
            }
            echo '</div></div></div>';
        }
        echo '</div>';
        return;
    }

    // Step 3: Reason and Confirmation
    $st = $db->prepare("SELECT * FROM rooms WHERE id = ?"); $st->execute([$blockRoomId]); $room = $st->fetch(PDO::FETCH_ASSOC);
    if (!$room) { flash("Invalid room selected."); header('Location: ?page=admin&tab=block'); exit; }

    echo '<div class="card p-4 shadow-sm" style="max-width: 600px;">
            <h5 class="card-title">Step 3: Confirm Block</h5>
            <p>You are blocking room <strong>' . h($room['room_number']) . '</strong> from <strong>' . h($checkin_date) . '</strong> to <strong>' . h($checkout_date) . '</strong>.</p>
            <form method="POST" action="?page=admin&tab=block">
                <input type="hidden" name="admin_block_room" value="1">
                <input type="hidden" name="room_id" value="' . h($blockRoomId) . '">
                <input type="hidden" name="checkin" value="' . h($checkin_date) . '">
                <input type="hidden" name="checkout" value="' . h($checkout_date) . '">
                <div class="mb-3">
                    <label class="form-label">Reason for Blocking (e.g., Emergency, Maintenance)</label>
                    <input type="text" name="reason" class="form-control" value="' . h($reason) . '" required>
                </div>
                <button type="submit" class="btn btn-danger w-100">Confirm and Block Room</button>
            </form>
        </div>';
}

/** Helper to render Today's Food Report. */
function render_food_report(): void {
    global $db, $page, $tab;

    // Step 1: Get current date or submitted date
    $order_date = $_POST['order_date'] ?? date('Y-m-d');

    echo '<h4>Place Food Order</h4>';

    // Step 2: Order Form
    echo '<div class="card p-4 shadow-sm bg-light">
        <form method="POST" action="?page=' . $page . '&tab=' . h($tab) . '">
            <input type="hidden" name="place_food_order" value="1">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Date of Order</label>
                    <input type="date" name="order_date" class="form-control" value="' . h($order_date) . '" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Reason / Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g., Staff Lunch, Meeting, Event" required>
                </div>

                <hr>

                <div class="col-md-4">
                    <label class="form-label small">Morning Refreshment</label>
                    <input type="number" name="morning_refreshment" class="form-control" value="0" min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label small">Breakfast (Veg / Non-Veg)</label>
                    <div class="input-group">
                        <input type="number" name="breakfast_veg" class="form-control" placeholder="Veg" value="0" min="0">
                        <input type="number" name="breakfast_nonveg" class="form-control" placeholder="Non-Veg" value="0" min="0">
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label small">Lunch (Veg / Non-Veg)</label>
                    <div class="input-group">
                        <input type="number" name="lunch_veg" class="form-control" placeholder="Veg" value="0" min="0">
                        <input type="number" name="lunch_nonveg" class="form-control" placeholder="Non-Veg" value="0" min="0">
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label small">Evening Refreshment</label>
                    <input type="number" name="evening_refreshment" class="form-control" value="0" min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label small">Dinner (Veg / Non-Veg)</label>
                    <div class="input-group">
                        <input type="number" name="dinner_veg" class="form-control" placeholder="Veg" value="0" min="0">
                        <input type="number" name="dinner_nonveg" class="form-control" placeholder="Non-Veg" value="0" min="0">
                    </div>
                </div>

                <div class="col-12 mt-3 text-end">
                    <button type="submit" class="btn btn-success px-4">Place Order</button>
                </div>
            </div>
        </form>
    </div>';

    // Step 3: Handle Form Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_food_order'])) {
        try {
            $stmt = $db->prepare("INSERT INTO food_orders 
                (order_for_date, notes, morning_refreshment, breakfast_veg, breakfast_nonveg,
                 lunch_veg, lunch_nonveg, evening_refreshment, dinner_veg, dinner_nonveg)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['order_date'],
                $_POST['notes'],
                (int)$_POST['morning_refreshment'],
                (int)$_POST['breakfast_veg'],
                (int)$_POST['breakfast_nonveg'],
                (int)$_POST['lunch_veg'],
                (int)$_POST['lunch_nonveg'],
                (int)$_POST['evening_refreshment'],
                (int)$_POST['dinner_veg'],
                (int)$_POST['dinner_nonveg'],
            ]);
            echo '<div class="alert alert-success mt-3">✅ Food order placed successfully for ' . h(date('F j, Y', strtotime($_POST['order_date']))) . '.</div>';
        } catch (PDOException $e) {
            echo '<div class="alert alert-danger mt-3">❌ Error placing order. Please try again.</div>';
            error_log("Food Order Insert Error: " . $e->getMessage());
        }
    }
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

    $action = $_GET['action'] ?? '';
    $room_id = $_GET['id'] ?? '';

    // --- EDIT ROOM MODE ---
    if ($action === 'edit' && $room_id) {
        try {
            $stmt = $db->prepare("SELECT * FROM rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$room) {
                echo '<div class="alert alert-danger">Room not found.</div>';
                return;
            }
        } catch (PDOException $e) {
            echo '<div class="alert alert-danger">Error loading room details.</div>';
            return;
        }

        $images = json_decode($room['images'], true) ?: [];
        $image_count = count($images);
        $slots_left = 4 - $image_count;

        echo '<h4 class="mb-3">Edit Room: ' . h($room['room_number']) . '</h4>';
        echo '<a href="?page=admin&tab=rooms" class="btn btn-secondary mb-4"><i class="fas fa-arrow-left"></i> Back to Manage Rooms</a>';

        echo '<div class="card p-4 shadow-sm">';
        echo '<form method="POST" enctype="multipart/form-data" action="?page=admin&tab=rooms">
                <input type="hidden" name="room_action" value="update">
                <input type="hidden" name="room_id" value="' . h($room['id']) . '">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Room Number</label>
                        <input type="text" name="room_number" class="form-control" value="' . h($room['room_number']) . '" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">Title</label>
                        <input type="text" name="title" class="form-control" value="' . h($room['title']) . '" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Capacity</label>
                        <input type="number" name="capacity" class="form-control" value="' . h($room['capacity']) . '" min="1" required>
                    </div>
                </div>

                <hr>
                <h6>Current Room Images (' . $image_count . '/4 used)</h6>
                <div id="room-images" class="row g-3 mb-3">';

        // --- Existing images display with delete buttons ---
        if (!empty($images)) {
            foreach ($images as $img) {
                $imageId = h(pathinfo($img, PATHINFO_FILENAME));
                $imgPath = h( 'rooms/' . $img);

                echo '<div class="col-md-3 text-center image-box mb-3" id="image-box-' . $imageId . '" style="display:flex; flex-direction: column; align-items: center;">
                        <img src="' . $imgPath . '" alt="Room Image" class="img-fluid rounded mb-2" style="height:100px;object-fit:cover;">
                        <button type="button" class="btn btn-danger btn-sm w-100"
                                onclick="deleteRoomImage(' . h($room['id']) . ', \'' . h($img) . '\', \'image-box-' . $imageId . '\')">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                      </div>';
            }
        } else {
            echo '<div class="col-12 text-muted small">No images uploaded yet.</div>';
        }

        echo '</div>';

        // --- Upload new images section ---
        if ($slots_left > 0) {
            echo '<p class="small text-muted mt-2">You can upload ' . $slots_left . ' more image(s):</p>
                  <div class="row g-2">';
            for ($i = 1; $i <= $slots_left; $i++) {
                echo '<div class="col-md-3">
                        <input type="file" name="img' . $i . '" class="form-control form-control-sm">
                      </div>';
            }
            echo '</div>';
        }

        echo '<div class="mt-4 text-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Changes
                </button>
              </div>
            </form>
          </div>';

        // --- JS for deleting image instantly ---
        echo '<script>
        async function deleteRoomImage(roomId, imageName, imageBoxId) {
            if (!confirm("Delete this image permanently?")) return;

            const formData = new FormData();
            formData.append("delete_image_action", "1");
            formData.append("room_id", roomId);
            formData.append("image_name", imageName);

            try {
                const response = await fetch("?page=admin&tab=rooms", {
                    method: "POST",
                    body: formData
                });
                if (response.ok) {
                    const imageBox = document.getElementById(imageBoxId);
                    if (imageBox) imageBox.remove(); // Remove the image from UI
                } else {
                    alert("Failed to delete image. Please try again.");
                }
            } catch (err) {
                alert("Error deleting image.");
            }
        }
        </script>';

        return;
    }

    // --- DEFAULT MANAGE ROOMS VIEW ---
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

    // --- Room List Display ---
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
                echo '<button class="carousel-control-prev" type="button" data-bs-target="#carousel' . $room['id'] . '" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon"></span>
                      </button>
                      <button class="carousel-control-next" type="button" data-bs-target="#carousel' . $room['id'] . '" data-bs-slide="next">
                        <span class="carousel-control-next-icon"></span>
                      </button>';
            }
            echo '</div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title">' . h($room['room_number']) . ' - ' . h($room['title']) . '</h5>
                            <span class="badge bg-info"><i class="fas fa-users"></i> ' . h($room['capacity']) . '</span>
                        </div>
                        <p class="text-muted small">Images: ' . count($images) . '</p>
                        <a href="?page=admin&tab=rooms&action=edit&id=' . h($room['id']) . '" class="btn btn-sm btn-outline-primary me-2">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <form method="POST" class="d-inline-block">
                            <input type="hidden" name="room_action" value="delete">
                            <input type="hidden" name="room_id" value="' . h($room['id']) . '">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'Delete room ' . h($room['room_number']) . '?\')">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>';
        }
    }
    echo '</div>';
}

/** Helper to render Profile Edit Form. */
function page_profile(): void {
    echo'<br>';
    require_login();
    global $currentUser;

    echo '<div class="d-flex justify-content-center align-items-center" style="min-height: calc(100vh - 250px);">
        <div class="card p-4 shadow-lg" style="max-width: 600px; width: 100%;">
            <div class="card-body">
                <h2 class="card-title text-center text-primary mb-4 fw-bold"><i class="fas fa-user-edit me-2"></i> Edit Your Profile</h2>
                <form action="?page=profile" method="POST">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" value="' . h($currentUser['username']) . '" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="' . h($currentUser['email']) . '" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mobile</label>
                        <input type="text" name="mobile" class="form-control" value="' . h($currentUser['mobile']) . '">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Staff ID</label>
                        <input type="text" name="staff_id" class="form-control" value="' . h($currentUser['staff_id']) . '">
                    </div>
                    <hr class="my-4">
                    <!-- Hidden password fields -->
                    <div id="changePasswordFields" class="d-none">
                        <p class="text-muted small">To change your password, enter a new one below. Otherwise, leave these fields blank.</p>
                        <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control"></div>
                        <div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control"></div>
                        <hr class="my-4">
                    </div>

                    <div class="mb-3 bg-light p-3 rounded">
                        <label class="form-label fw-bold">Current Password (Required to save changes)</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <a href="#" onclick="event.preventDefault(); document.getElementById(\'changePasswordFields\').classList.toggle(\'d-none\');" class="d-block text-center small mb-3">Change Password?</a>

                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-2"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>
    <br>
    <br>';
}

// page_receipt extracted to pages/receipt.php
/* function page_receipt(): void {
    global $db, $collegeName, $adminSigPath;
    $bid = (int)($_GET['id'] ?? 0);
    
    try {
        $st = $db->prepare("
            SELECT b.*, r.room_number, r.title as room_title, 
                   u.username as staff_username, u.mobile as staff_mobile,
                   approver.username as approver_username
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN users u ON b.staff_user_id = u.id
            LEFT JOIN users approver ON b.approved_by = approver.id WHERE b.id = ?");
        $st->execute([$bid]);
        $booking = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $booking = null;
        error_log("Receipt Load Error: " . $e->getMessage());
    }
    
    if (!$booking) {
        layout_header("Error");
        echo '<div class="alert alert-danger mt-5">Booking not found or not yet approved.</div>';
        
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
            <div class="col-6 text-end"><strong>Status:</strong> <span class="badge bg-success">' . h(ucfirst($booking['status'])) . '</span></div>
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
            <img src="' . h($adminSigPath) . '" style="height: 60px; width: 150px; object-fit: contain; border-bottom: 1px solid #000; display: block; margin: 0 auto;">
                <p>_________________________</p>
                <p>Staff In-Charge Signature</p>
            </div>
            <div class="col-6 text-center">
                <img src="' . h($adminSigPath) . '" style="height: 60px; width: 150px; object-fit: contain; border-bottom: 1px solid #000; display: block; margin: 0 auto;">
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
} */

// ======================================================================================
// 4. Main Router (Moved to the end of the file)
// ======================================================================================

switch ($page) {
    case 'home':
        layout_header("Home"); // This call starts outputting HTML
        page_home();
        layout_footer();
 // This function also outputs HTML
        break;
    case 'login':
        layout_header("Login");
        page_login();
        layout_footer();

        break;
    case 'register':
        layout_header("Register");
        page_register();
        layout_footer();

        break;
    case 'contact':
        layout_header("Contact");
        page_contact();
        layout_footer();

        break;
    case 'admin':
        require_login();
        if (strpos(($currentUser['role'] ?? ''), 'admin') === false) {
            flash("Access denied.");
            header('Location: ?page=staff'); exit;
        }
        layout_header("Admin Dashboard");
        page_admin();
        layout_footer();
        break;
    case 'profile':
        require_login();
        layout_header("Profile");
        page_profile();
        layout_footer();
        break;
    case 'staff':
        require_login();
        if (($currentUser['role'] ?? '') !== 'staff') {
            flash("Access denied.");
            header("Location: ?page=login"); exit;
        }
        layout_header("Staff Portal");
        page_staff();
        layout_footer();
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
        layout_footer();
        break;
}

// The end.