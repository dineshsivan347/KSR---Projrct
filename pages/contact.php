<?php

function page_contact(): void {
	global $collegeName;
	echo "<div class='container my-5'>";
	echo "<div class='card contact-card shadow-lg' style='border-radius:22px; max-width:900px; margin:auto; background:#ffffff; box-shadow:0 8px 32px rgba(102,126,234,0.12);'>";
	echo "<div class='row g-0'>";
	echo "<div class='col-md-6 p-5'>";
	echo "<div class='text-center mb-4'>";
	echo "<i class='fas fa-address-book fa-3x text-primary mb-2' style='animation: bounceIn 1s;'></i>";
	echo "<h2 class='mb-2' style='font-weight:700;'>Contact Us</h2>";
	echo "<p class='text-muted' style='font-size:1.1em;'>We'd love to hear from you!<br> Reach out for any guesthouse queries, bookings, or feedback.</p>";
	echo "</div>";
	echo "<div class='mb-3'><i class='fas fa-user-tie text-info me-2'></i> <strong>Admin:</strong> <a href='mailto:principal@ksrce.ac.in'>principal@ksrce.ac.in</a></div>";
	echo "<div class='mb-3'><i class='fas fa-phone-alt text-success me-2'></i> <strong>Phone:</strong> <a href='tel:+914288274213'>+91 4288 - 274213</a></div>";
	echo "<div class='mb-3'><i class='fas fa-envelope text-warning me-2'></i> <strong>Email:</strong> <a href='mailto:guesthouse@ksrce.ac.in'>guesthouse@ksrce.ac.in</a></div>";
	echo "<div class='mb-3'><i class='fas fa-map-marker-alt text-danger me-2'></i> <strong>Address:</strong> KSR College of Engineering, Tiruchengode, Tamil Nadu, India</div>";
	echo "<div class='d-flex justify-content-center gap-3 mt-4'>";
	echo "<a href='https://www.facebook.com/share/12LqWyZxAuD/' target='_blank' class='btn btn-outline-primary rounded-circle' style='width:44px; height:44px; display:flex; align-items:center; justify-content:center;'><i class='fab fa-facebook-f'></i></a>";
	echo "<a href='https://x.com/ksrceofficial?t=28p8b4Fe09aERx3Dr75FUQ&s=08' target='_blank' class='btn btn-outline-info rounded-circle' style='width:44px; height:44px; display:flex; align-items:center; justify-content:center;'><i class='fab fa-twitter'></i></a>";
	echo "<a href='https://www.instagram.com/ksrce_official?utm_source=qr&igsh=MWJzMTYzaTJjeHdoNw==' target='_blank' class='btn btn-outline-danger rounded-circle' style='width:44px; height:44px; display:flex; align-items:center; justify-content:center;'><i class='fab fa-instagram'></i></a>";
	echo "<a href='https://www.linkedin.com/company/k-s-r-college-of-engineering-autonomous/' target='_blank' class='btn btn-outline-secondary rounded-circle' style='width:44px; height:44px; display:flex; align-items:center; justify-content:center;'><i class='fab fa-linkedin-in'></i></a>";
	echo "</div>";
	echo "</div>";
	echo "<div class='col-md-6' style='padding: 1rem;'>";
	echo "<iframe src='https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3911.6003125343896!2d77.82544532481398!3d11.36387678882284!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3ba966a853152cd5%3A0x868a5d4c4885bf20!2sKSR%20Kalvi%20Nagar%2C%20Tamil%20Nadu%20637215!5e0!3m2!1sen!2sin!4v1759860048876!5m2!1sen!2sin' width='100%' height='100%' style='min-height:340px; border:none; border-radius: 0 22px 22px 0;' allowfullscreen='' loading='lazy'></iframe>";
	echo "</div>";
	echo "</div>";
	echo "</div>";
	echo "</div>";
}


