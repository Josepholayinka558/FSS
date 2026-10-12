<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
include('includes/header.php');

try {
    $student_count = $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
} catch (PDOException $e) { $student_count = 0; }

try {
    $subject_count = $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
} catch (PDOException $e) { $subject_count = 0; }

try {
    $exam_count = $pdo->query('SELECT COUNT(*) FROM exams WHERE published = 1 OR published_obj = 1 OR published_theory = 1')->fetchColumn();
} catch (PDOException $e) { $exam_count = 0; }

try {
    $question_count = $pdo->query('SELECT COUNT(*) FROM questions q UNION ALL SELECT COUNT(*) FROM question_bank qb')->fetchAll();
    $total_questions = ($question_count[0]['COUNT(*)'] ?? 0) + ($question_count[1]['COUNT(*)'] ?? 0);
} catch (PDOException $e) { $total_questions = 0; }
?>

<nav class="navbar sticky-top">
    <div class="container">
        <a href="index.php" class="navbar-brand d-flex align-items-center">
            <img src="https://fss-oyo.edu.ng/images/FSS_LOGO.png" alt="FSS OYO Logo" style="height: 42px; border-radius: var(--radius-md); object-fit: contain;" class="me-3" onerror="this.style.display='none'">
            <div>
                <h5 class="mb-0">FSS OYO</h5>
                <span style="font-size: 0.75rem; color: var(--color-text-muted); font-weight: 500;">CBT Portal</span>
            </div>
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="admin/login.php" class="btn btn-outline-primary btn-sm d-none d-md-inline-flex">
                <i class="fas fa-shield-halved me-2"></i> Admin
            </a>
            <a href="verify_access.php" class="btn btn-primary btn-sm">
                Get Started <i class="fas fa-arrow-right ms-2"></i>
            </a>
        </div>
    </div>
</nav>

<section class="hero-section">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div style="display: inline-flex; align-items: center; padding: 0.4rem 0.9rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 100px; font-size: 0.8rem; font-weight: 500; color: var(--color-text-secondary); margin-bottom: 1.75rem;">
                    <i class="fas fa-graduation-cap me-2" style="color: var(--color-primary);"></i>
                    Computer Science Department
                    <span style="width: 5px; height: 5px; background: var(--color-border); border-radius: 50%; margin: 0 0.7rem;"></span>
                    <i class="fas fa-clock me-2" style="font-size: 0.75rem;"></i> 24/7 Access
                </div>

                <h1 class="mb-4">
                    Computer-Based Testing<br>
                    <span style="color: var(--color-text-secondary); font-weight: 600;">Made Simple &amp; Secure</span>
                </h1>

                <p class="lead mb-5" style="max-width: 520px;">
                    FSS OYO Polytechnic's CBT Portal enables seamless examination management, 
                    real-time assessment, and secure academic evaluation for the Computer Science department.
                </p>

                <div class="d-flex flex-wrap gap-3 mb-5">
                    <a href="verify_access.php" class="btn btn-primary btn-lg">
                        Enter Portal <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                    <a href="#features" class="btn btn-outline-primary btn-lg">
                        Learn More
                    </a>
                </div>

                <div class="d-flex align-items-center gap-4">
                    <div>
                        <div style="font-size: 1.75rem; font-weight: 800; line-height: 1; letter-spacing: -0.02em;" class="mb-1"><?php echo $exam_count; ?></div>
                        <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em;">Active Exams</div>
                    </div>
                    <div style="width: 1px; height: 36px; background: var(--color-border);"></div>
                    <div>
                        <div style="font-size: 1.75rem; font-weight: 800; line-height: 1; letter-spacing: -0.02em;" class="mb-1"><?php echo $subject_count; ?></div>
                        <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em;">Courses</div>
                    </div>
                    <div style="width: 1px; height: 36px; background: var(--color-border);"></div>
                    <div>
                        <div style="font-size: 1.75rem; font-weight: 800; line-height: 1; letter-spacing: -0.02em;" class="mb-1"><?php echo $total_questions; ?></div>
                        <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em;">Questions</div>
                    </div>
                </div>
            </div>

            <!-- <div class="col-lg-6">
                <div class="showcase-image">
                    <img src="https://cdn.guardian.ng/wp-content/uploads/2025/02/1_1.jpg" 
                         alt="Academic Environment" 
                         onerror="this.parentElement.innerHTML = '<div class=\'image-fallback\'><i class=\'fas fa-university me-3\'></i>FSS OYO Polytechnic Campus</div>';">
                    <div style="position: absolute; top: 1.25rem; left: 1.25rem; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border: 1px solid var(--color-border); border-radius: 100px; padding: 0.45rem 0.9rem; font-size: 0.75rem; font-weight: 600; color: var(--color-text); display: inline-flex; align-items: center; box-shadow: var(--shadow-sm);">
                        <span style="width: 7px; height: 7px; background: var(--color-success); border-radius: 50%; margin-right: 0.5rem;"></span>
                        Live Now
                    </div> -->
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-5 mb-4 mb-lg-0">
                <div class="category-nav mb-4">
                    <a href="#" class="active">View All</a>
                    <a href="verify_access.php">Exams</a>
                    <a href="past_questions.php">Past Questions</a>
                    <a href="student_login.php">Results</a>
                    <a href="admin/login.php">Admin</a>
                </div>
                <h2 class="mb-4">
                    Everything you need for<br>academic assessment
                </h2>
                <p class="lead mb-4">
                    From creating exams to viewing instant results, our platform streamlines 
                    the entire assessment process for both students and administrators.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-secondary"><i class="fas fa-check me-1"></i> Objective Questions</span>
                    <span class="badge bg-secondary"><i class="fas fa-check me-1"></i> Theory Questions</span>
                    <span class="badge bg-secondary"><i class="fas fa-check me-1"></i> Auto-Grading</span>
                    <span class="badge bg-secondary"><i class="fas fa-check me-1"></i> Timer Support</span>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card border-0" style="overflow: hidden; box-shadow: var(--shadow-lg);">
                    <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-border); display: flex; align-items: center; justify-content: space-between;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="display: flex; gap: 0.4rem;">
                                <span style="width: 11px; height: 11px; background: #ef4444; border-radius: 50%;"></span>
                                <span style="width: 11px; height: 11px; background: #f59e0b; border-radius: 50%;"></span>
                                <span style="width: 11px; height: 11px; background: #10b981; border-radius: 50%;"></span>
                            </div>
                            <span style="font-size: 0.8rem; font-weight: 500; color: var(--color-text-secondary);">CBT Examination Portal</span>
                        </div>
                        <span class="badge bg-success">Active</span>
                    </div>
                    <div style="aspect-ratio: 16/10; overflow: hidden;">
                        <img src="https://cdn.guardian.ng/wp-content/uploads/2025/02/1_1.jpg" 
                             alt="Examination Interface Preview" 
                             style="width: 100%; height: 100%; object-fit: cover;"
                             onerror="this.parentElement.innerHTML = '<div class=\'image-fallback h-100 w-100\'><div class=\'text-center\'><i class=\'fas fa-desktop fa-2x mb-3 d-block\'></i>Examination Dashboard</div></div>';">
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-secondary); margin-bottom: 0.75rem;">Features</div>
            <h2 class="mb-3">Built for academic excellence</h2>
            <p class="lead mx-auto" style="max-width: 600px;">
                A comprehensive platform designed to manage every aspect of computer-based testing 
                in a polytechnic environment.
            </p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-file-circle-plus"></i>
                    </div>
                    <h4>Exam Management</h4>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        Create, schedule, and publish exams with flexible configurations for 
                        duration, question types, and class-specific access.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h4>Question Bank</h4>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        Rich question bank supporting both MCQ and theory formats with 
                        detailed explanations, organized by course and level.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h4>Secure Testing</h4>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        Access code protection, auto-submit on timer expiry, session 
                        tracking, and detailed activity logging for all exam sessions.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h4>Instant Results</h4>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        Objective questions graded automatically. Theory grading module 
                        with result approval workflow and downloadable reports.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h4>Student Management</h4>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        Register and manage students across ND1, ND2, HND1, and HND2 
                        classes with unique matriculation and registration numbers.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <h4>Admin Control</h4>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">
                        Comprehensive admin dashboard with access code management, 
                        activity monitoring, and full control over courses and exams.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="text-center mb-5">
            <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-secondary); margin-bottom: 0.75rem;">Platform Overview</div>
            <h2 class="mb-3">System statistics</h2>
            <p class="lead mx-auto" style="max-width: 560px;">
                Real numbers from our CBT platform, updated continuously as 
                students and administrators use the system.
            </p>
        </div>

        <div class="row g-4">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #eff6ff; color: #2563eb;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-label">Students</div>
                    <div class="stat-value"><?php echo $student_count; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        Registered across all levels
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ecfdf5; color: #059669;">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-label">Courses</div>
                    <div class="stat-value"><?php echo $subject_count; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        ND &amp; HND curriculum
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fffbeb; color: #d97706;">
                        <i class="fas fa-clipboard"></i>
                    </div>
                    <div class="stat-label">Active Exams</div>
                    <div class="stat-value"><?php echo $exam_count; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        Currently published
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fef2f2; color: #dc2626;">
                        <i class="fas fa-circle-question"></i>
                    </div>
                    <div class="stat-label">Questions</div>
                    <div class="stat-value"><?php echo $total_questions; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        MCQ + Theory format
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="card" style="background: var(--color-primary); border-color: var(--color-primary);">
            <div class="card-body py-5 px-4 px-md-5">
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <h2 style="color: #fff; font-weight: 700;" class="mb-2">
                            Ready to begin your assessment?
                        </h2>
                        <p style="color: rgba(255,255,255,0.7); font-size: 1.05rem; margin: 0;">
                            Enter the access code provided by your invigilator to access the student portal and start your examination.
                        </p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <a href="verify_access.php" class="btn btn-lg" style="background: #fff; color: var(--color-primary); font-weight: 600;">
                            Enter Student Portal <i class="fas fa-arrow-right ms-2"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<footer style="border-top: 1px solid var(--color-border); padding: 3rem 0 2rem; background: var(--color-bg);">
    <div class="container">
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-center mb-3">
                    <img src="https://fss-oyo.edu.ng/images/FSS_LOGO.png" alt="FSS OYO Logo" style="height: 42px; border-radius: var(--radius-md); object-fit: contain;" class="me-3" onerror="this.style.display='none'">
                    <div>
                        <h5 class="mb-0">FSS OYO</h5>
                        <span style="font-size: 0.75rem; color: var(--color-text-muted); font-weight: 500;">Polytechnic CBT Portal</span>
                    </div>
                </div>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Computer Science Department's official platform for 
                    computer-based testing and academic assessment.
                </p>
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <h6 class="mb-3">Portal</h6>
                <ul class="list-unstyled mb-0" style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <li><a href="verify_access.php" class="text-muted text-decoration-none" style="font-size: 0.9rem;">Student Login</a></li>
                    <li><a href="past_questions.php" class="text-muted text-decoration-none" style="font-size: 0.9rem;">Past Questions</a></li>
                    <li><a href="admin/login.php" class="text-muted text-decoration-none" style="font-size: 0.9rem;">Admin Login</a></li>
                </ul>
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <h6 class="mb-3">Department</h6>
                <ul class="list-unstyled mb-0" style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <li><span class="text-muted" style="font-size: 0.9rem;">ND1 &mdash; ND2</span></li>
                    <li><span class="text-muted" style="font-size: 0.9rem;">HND1 &mdash; HND2</span></li>
                    <li><span class="text-muted" style="font-size: 0.9rem;">Computer Science</span></li>
                </ul>
            </div>

            <div class="col-md-12 col-lg-4">
                <h6 class="mb-3">Academic Levels</h6>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-secondary">ND1</span>
                    <span class="badge bg-secondary">ND2</span>
                    <span class="badge bg-secondary">HND1</span>
                    <span class="badge bg-secondary">HND2</span>
                </div>
            </div>
        </div>
        <div style="border-top: 1px solid var(--color-border-light); padding-top: 1.5rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
            <p class="mb-0 text-muted" style="font-size: 0.85rem;">
                &copy; <?php echo date('Y'); ?> FSS OYO Polytechnic. Computer Science Department. All rights reserved.
            </p>
            <div class="d-flex align-items-center gap-2" style="font-size: 0.85rem; color: var(--color-text-muted);">
                <span style="width: 7px; height: 7px; background: var(--color-success); border-radius: 50%;"></span>
                System Online
            </div>
        </div>
    </div>
</footer>

<?php include('includes/footer.php'); ?>
