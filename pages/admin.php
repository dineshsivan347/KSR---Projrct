<?php

if (!function_exists('page_admin')) {
function page_admin(): void {
	require_login();
	global $currentUser, $tab, $db, $roomsDir, $collegeName;
	if (strpos($currentUser['role'] ?? '', 'admin') === false) { flash('Access denied.'); header('Location: ?page=staff'); exit; }
	$tab = $tab ?: 'pending';
	echo '<div class="content-card mt-5">'
		. '<div class="d-flex justify-content-between align-items-center mb-4">'
		. '<h2 class="text-dark fw-bold"><i class="fas fa-user-shield me-2"></i> Admin Dashboard - ' . h($currentUser['username']) . '</h2>'
		. '<div class="badge bg-primary fs-6 p-2">Role: ' . h($currentUser['role']) . '</div>'
		. '</div>'
		. '<ul class="nav nav-tabs" id="adminTabs" role="tablist" style="gap: 0.6rem;">'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'pending' ? 'active' : '') . '" href="?page=admin&tab=pending"><i class="fas fa-clock me-1"></i> Pending Bookings</a></li>'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'rooms' ? 'active' : '') . '" href="?page=admin&tab=rooms"><i class="fas fa-door-open me-1"></i> Room Management</a></li>'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'book' ? 'active' : '') . '" href="?page=admin&tab=book"><i class="fas fa-calendar-plus me-1"></i> Book Room</a></li>'
		. '<a class="nav-link ' . ($tab === 'block' ? 'active' : '') . '" href="?page=admin&tab=block"><i class="fas fa-ban fa-fw me-2"></i> Block Room</a>'
		. '<a class="nav-link ' . ($tab === 'food' ? 'active' : '') . '" href="?page=admin&tab=food"><i class="fas fa-utensils fa-fw me-2"></i> Today Food</a>'
		. '<li class="nav-item"><a class="nav-link ' . ($tab === 'history' ? 'active' : '') . '" href="?page=admin&tab=history"><i class="fas fa-history me-1"></i> Booking History</a></li>'
		. '</ul>'
		. '<div class="tab-content pt-3">';
	if ($tab === 'pending') { render_admin_bookings_table('pending'); }
	elseif ($tab === 'rooms') { render_admin_rooms_management(); }
	elseif ($tab === 'book') { render_admin_create_booking(); }
	elseif ($tab === 'history') { render_admin_bookings_table('history'); }
	elseif ($tab === 'block') { render_admin_block_room(); }
	elseif ($tab === 'food') { render_food_report(); }
	echo '</div></div>';
}
}


