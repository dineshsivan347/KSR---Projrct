<?php

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

/** Helper: get current user record from DB. */
function current_user(): ?array {
    global $db;
    if (!is_logged_in()) return null;
    try {
        $st = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $st->execute([$_SESSION['user_id']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        return $user === false ? null : $user;
    } catch (PDOException $e) {
        error_log("DB Error fetching current user: " . $e->getMessage());
        return null;
    }
}


