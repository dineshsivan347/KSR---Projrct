<?php

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


