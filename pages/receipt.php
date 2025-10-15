<?php

if (!function_exists('page_receipt')) {
function page_receipt(): void {
	global $db, $collegeName, $adminSigPath;
	$bid = (int)($_GET['id'] ?? 0);
	try {
		$st = $db->prepare("SELECT b.*, r.room_number, r.title as room_title, u.username as staff_username, u.mobile as staff_mobile, approver.username as approver_username FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN users u ON b.staff_user_id = u.id LEFT JOIN users approver ON b.approved_by = approver.id WHERE b.id = ?");
		$st->execute([$bid]);
		$booking = $st->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) { $booking = null; }
	if (!$booking) { layout_header('Error'); echo '<div class="alert alert-danger mt-5">Booking not found or not yet approved.</div>'; return; }
	layout_header("Booking Receipt #{$bid}");
	echo '<div class="card p-5 mt-5 shadow-lg" id="receipt-area" style="max-width: 800px; margin: 50px auto; border: 1px solid #ddd;">'
		. '<div class="text-center mb-4">'
		. '<h1 class="text-primary fw-bolder">' . h($collegeName) . '</h1>'
		. '<h3 class="text-secondary">Guesthouse Booking Receipt</h3>'
		. '</div><hr>'
		. '<div class="row mb-3 small">'
		. '<div class="col-6"><strong>Booking ID:</strong> #' . h($booking['id']) . '</div>'
		. '<div class="col-6 text-end"><strong>Status:</strong> <span class="badge bg-success">' . h(ucfirst($booking['status'])) . '</span></div>'
		. '</div>'
		. '<div class="row mb-4 small">'
		. '<div class="col-6"><strong>Date Generated:</strong> ' . h(date('Y-m-d H:i:s')) . '</div>'
		. '<div class="col-6 text-end"><strong>Staff Username:</strong> ' . h($booking['staff_username']) . ' (' . h($booking['staff_id']) . ')</div>'
		. '</div>'
		. '<h5 class="text-primary mt-3 mb-2"><i class="fas fa-calendar-check me-1"></i> Booking Details</h5>'
		. '<div class="table-responsive small"><table class="table table-bordered table-sm">'
		. '<tr><th>Room</th><td>' . h($booking['room_number']) . ' - ' . h($booking['room_title']) . '</td></tr>'
		. '<tr><th>Check-in</th><td>' . h(date('Y-m-d H:i', strtotime($booking['checkin']))) . '</td></tr>'
		. '<tr><th>Check-out</th><td>' . h(date('Y-m-d H:i', strtotime($booking['checkout']))) . '</td></tr>'
		. '<tr><th>Guest(s)</th><td>' . h($booking['guest_name']) . ' (' . h($booking['guest_no']) . ' Pax)</td></tr>'
		. '<tr><th>Guest Designation</th><td>' . h($booking['guest_designation']) . ' from ' . h($booking['guest_college_org']) . '</td></tr>'
		. '</table></div>'
		. '<h5 class="text-primary mt-4 mb-2"><i class="fas fa-utensils me-1"></i> Meal Requirements (Pax)</h5>'
		. '<div class="table-responsive small"><table class="table table-bordered table-sm text-center">'
		. '<thead><tr><th>Refreshment (M/E)</th><th>Breakfast (V/NV)</th><th>Lunch (V/NV)</th><th>Dinner (V/NV)</th></tr></thead>'
		. '<tbody><tr>'
		. '<td>' . h($booking['morning_refreshment']) . ' / ' . h($booking['evening_refreshment']) . '</td>'
		. '<td>' . h($booking['breakfast_veg']) . ' / ' . h($booking['breakfast_nonveg']) . '</td>'
		. '<td>' . h($booking['lunch_veg']) . ' / ' . h($booking['lunch_nonveg']) . '</td>'
		. '<td>' . h($booking['dinner_veg']) . ' / ' . h($booking['dinner_nonveg']) . '</td>'
		. '</tr></tbody></table></div>'
		. '<div class="row mt-5">'
		. '<div class="col-6 text-center"><img src="' . h($adminSigPath) . '" style="height: 60px; width: 150px; object-fit: contain; border-bottom: 1px solid #000; display: block; margin: 0 auto;"><p>_________________________</p><p>Staff In-Charge Signature</p></div>'
		. '<div class="col-6 text-center"><img src="' . h($adminSigPath) . '" style="height: 60px; width: 150px; object-fit: contain; border-bottom: 1px solid #000; display: block; margin: 0 auto;"><p>_________________________</p><p>Approved By (Admin)</p></div>'
		. '</div>'
		. '<div class="text-center mt-4">'
		. '<button class="btn btn-info print-button" onclick="window.print()"><i class="fas fa-print me-2"></i> Print Receipt</button>'
		. '<a href="?page=' . (is_logged_in() ? 'staff' : 'admin') . '" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i> Go Back</a>'
		. '</div>'
		. '</div>';
	echo '<style>@media print {.print-button, .navbar, .footer { display: none !important; } #receipt-area { margin: 0 auto; border: none !important; box-shadow: none !important; } body { background: none !important; }}</style>';
	layout_footer();
	return;
}
}


