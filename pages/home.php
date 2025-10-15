<?php

function page_home(): void {
	global $db, $roomsDir;
	$rooms = $db->query("SELECT * FROM rooms ORDER BY room_number ASC")->fetchAll(PDO::FETCH_ASSOC);

	echo '<div class="text-center my-5">
		<h1 class="display-3 fw-bolder text-primary animate__animated animate__fadeInDown">Welcome to the KSREI Guesthouse</h1>
		<p class="lead text-secondary animate__animated animate__fadeInUp">Modern, comfortable, and centrally located accommodation for our esteemed guests.</p>
	</div>';

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

	echo '<h2 class="section-title text-center"><i class="fas fa-bed me-2"></i> Our Rooms & Suites</h2>';
	echo '<div class="row">';
	if (empty($rooms)) {
		echo '<div class="col-12"><div class="alert alert-warning">No rooms have been added yet.</div></div>';
	} else {
		foreach ($rooms as $room) {
			$images = json_decode($room['images'], true) ?: [];
			if (empty($images)) { $images = ['default_room.png']; }
			echo '<div  class="col-md-4 mb-4">
				<div class="room-card">
					<div id="carousel' . $room['id'] . '" class="carousel slide" data-bs-ride="carousel">
						<div class="carousel-inner">';
							foreach ($images as $index => $img) {
								echo '<div class="carousel-item ' . ($index === 0 ? 'active' : '') . '">
									<img src="rooms/' . h($img) . '" class="d-block w-100 " alt="Room Image">
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


