<?php

if (!function_exists('page_staff')) {
function page_staff(): void {
	require_login();
	global $currentUser, $tab, $db, $collegeName;
	if (!$currentUser || ($currentUser['role'] ?? '') != 'staff') { flash('Access denied.'); header('Location: ?page=login'); exit; }
	$tab = $tab ?: 'booking';
	echo '<div class="content-card mt-5">'
		. '<div class="d-flex justify-content-between align-items-center mb-4">'
		. '<h2 class="text-dark fw-bold"><i class="fas fa-hotel me-2"></i> Staff Booking Portal - ' . h($currentUser['username']) . '</h2>'
		. '<div class="d-flex gap-2"><div class="badge bg-primary fs-6 p-2">User ID: ' . h($currentUser['id']) . '</div><div class="badge bg-info fs-6 p-2">Staff ID: ' . h($currentUser['staff_id']) . '</div></div>'
		. '</div>'
		. '<ul class="nav nav-tabs" id="staffTabs" role="tablist" style="gap: 0.5rem;">'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'booking' ? 'active' : '') . '" href="?page=staff&tab=booking"><i class="fas fa-calendar-plus me-1"></i> Create Booking</a></li>'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'status' ? 'active' : '') . '" href="?page=staff&tab=status"><i class="fas fa-list-alt me-1"></i> My Bookings Status</a></li>'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'food' ? 'active' : '') . '" href="?page=staff&tab=food"><i class="fas fa-utensils fa-fw me-2"></i> Today Food</a></li>'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'history' ? 'active' : '') . '" href="?page=staff&tab=history"><i class="fas fa-list-alt me-1"></i> My History</a></li>'
		. '</ul>'
		. '<div class="tab-content pt-3">';
	if ($tab === 'booking') { render_staff_create_booking(); }
	elseif ($tab === 'status') { render_staff_bookings_status(); }
	elseif ($tab === 'history') { render_staff_history(); }
	elseif ($tab === 'food') { render_food_report(); }
	else { render_staff_create_booking(); }
	echo '</div></div>';
}
}


