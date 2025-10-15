<?php

function page_register(): void {
	echo '<div class="d-flex justify-content-center align-items-center" style="min-height: calc(100vh - 180px);">
		<div class="card p-4 shadow-lg" style="max-width: 500px; width: 100%;">
			<div class="card-body">
				<h2 class="card-title text-center text-primary mb-4 fw-bold">Staff Registration</h2>
				<form action="?page=register" method="POST">
					<div class="row g-3">
						<div class="col-md-6"><label class="form-label">Username</label><input type="text" name="username" class="form-control" required></div>
						<div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
						<div class="col-12"><label class="form-label">Mobile Number</label><input type="text" name="mobile" class="form-control"></div>
						<div class="col-12">
							<label class="form-label">College</label>
							<select name="college" id="collegeSelect" class="form-select" required>
								<option value="">-- Select College --</option>
							</select>
						</div>
						<div class="col-12">
							<label class="form-label">Department</label>
							<select name="department" id="deptSelect" class="form-select" required>
								<option value="">-- Select Department --</option>
							</select>
						</div>
					</div>
					<script>
						const collegeDepartments = {"K.S.Rangasamy College of Technology (Autonomous)": ["Artificial Intelligence and Data Science", "Biotechnology", "Civil Engineering", "Computer Science and Engineering", "Electrical and Electronics Engineering", "Electronics and Communication Engineering", "Food Technology", "Information Technology", "Mechanical Engineering", "Mechatronics Engineering"], "K.S.R. College of Engineering": ["Artificial Intelligence and Data Science", "Biomedical Engineering", "Civil Engineering", "Computer Science and Engineering", "Electrical and Electronics Engineering", "Electronics and Communication Engineering", "Information Technology", "Mechanical Engineering"], "KSR Institute for Engineering and Technology (KSRIET)": ["Computer Science and Engineering", "Electrical and Electronics Engineering", "Electronics and Communication Engineering", "Information Technology", "Mechanical Engineering"], "K.S. Rangasamy College of Arts and Science (KSRCAS)": ["Biochemistry", "Biotechnology", "Business Administration", "Chemistry", "Commerce", "Computer Applications", "Computer Science", "English", "Mathematics", "Microbiology", "Physics", "Tamil"], "KS Rangasamy College of Nursing": ["General Nursing"], "KSR Institute of Dental Science and Research (KSRIDSR)": ["General Dentistry"], "K.S.Rangasamy College of Allied Health Science": ["General Department"], "K.S.Rangasamy College Of Pharmacy": ["General Department"], "KSR College of Education": ["General Department"]};
						const collegeSelect = document.getElementById("collegeSelect");
						const deptSelect = document.getElementById("deptSelect");
						Object.keys(collegeDepartments).forEach(college => { collegeSelect.options.add(new Option(college, college)); });
						collegeSelect.addEventListener("change", function() { const selectedCollege = this.value; deptSelect.innerHTML = "<option value=\'\'>-- Select Department --</option>"; if (collegeDepartments[selectedCollege]) { collegeDepartments[selectedCollege].forEach(dept => deptSelect.options.add(new Option(dept, dept))); } });
					</script>
					<div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" placeholder="Create a password" required></div>
					<button type="submit" class="btn btn-primary w-100 mt-3"><i class="fas fa-user-plus me-1"></i> Register</button>
				</form>
				<p class="mt-3 text-center small">Already have an account? <a href="?page=login" class="text-primary fw-bold">Login</a></p>
			</div>
		</div>
	</div>';
}


