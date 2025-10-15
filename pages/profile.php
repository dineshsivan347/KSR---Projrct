<?php

if (!function_exists('page_profile')) {
function page_profile(): void {
	echo '<br>';
	require_login();
	global $currentUser;
	echo '<div class="d-flex justify-content-center align-items-center" style="min-height: calc(100vh - 250px);">'
		. '<div class="card p-4 shadow-lg" style="max-width: 600px; width: 100%;">'
		. '<div class="card-body">'
		. '<h2 class="card-title text-center text-primary mb-4 fw-bold"><i class="fas fa-user-edit me-2"></i> Edit Your Profile</h2>'
		. '<form action="?page=profile" method="POST">'
		. '<input type="hidden" name="update_profile" value="1">'
		. '<div class="mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="' . h($currentUser['username']) . '" required></div>'
		. '<div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="' . h($currentUser['email']) . '" required></div>'
		. '<div class="mb-3"><label class="form-label">Mobile</label><input type="text" name="mobile" class="form-control" value="' . h($currentUser['mobile']) . '"></div>'
		. '<div class="mb-3"><label class="form-label">Staff ID</label><input type="text" name="staff_id" class="form-control" value="' . h($currentUser['staff_id']) . '"></div>'
		. '<hr class="my-4">'
		. '<div id="changePasswordFields" class="d-none">'
		. '<p class="text-muted small">To change your password, enter a new one below. Otherwise, leave these fields blank.</p>'
		. '<div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control"></div>'
		. '<div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control"></div>'
		. '<hr class="my-4">'
		. '</div>'
		. '<div class="mb-3 bg-light p-3 rounded">'
		. '<label class="form-label fw-bold">Current Password (Required to save changes)</label>'
		. '<input type="password" name="current_password" class="form-control" required>'
		. '</div>'
		. '<a href="#" onclick="event.preventDefault(); document.getElementById(\'changePasswordFields\').classList.toggle(\'d-none\');" class="d-block text-center small mb-3">Change Password?</a>'
		. '<button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-2"></i> Save Changes</button>'
		. '</form>'
		. '</div>'
		. '</div>'
		. '</div><br><br>';
}
}


