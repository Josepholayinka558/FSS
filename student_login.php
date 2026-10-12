<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/audit_log.php');

if (!isset($_SESSION['access_granted'])) {
    header('Location: verify_access.php');
    exit;
}

$error = '';
$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matric_no = trim($_POST['matric_no'] ?? '');
    $name      = trim($_POST['name']      ?? '');
    $password  = $_POST['password'] ?? '';

    if (fss_rate_limit_exceeded('student', 0, 'student_login_failed', 300, 15)) {
        $error = 'Too many failed attempts. Please wait 5 minutes and try again, or contact an administrator.';
    } elseif (!empty($matric_no) && !empty($name)) {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE matric_number = ? AND name = ? LIMIT 1');
        $stmt->execute([$matric_no, $name]);
        $student = $stmt->fetch();

        if ($student) {
            $hasPassword = !empty($student['password']);
            $passwordOk = true;

            if ($hasPassword) {
                $passwordOk = password_verify((string)$password, (string)$student['password']);
            } else {
                // Migration transition: existing students without a password are
                // allowed in with matric+name but a warning is surfaced to encourage
                // admin to set initial passwords. We still audit this event.
                $warning = 'Your account has not been secured with a password yet. Please contact your administrator to set one.';
            }

            $accountActive = ($student['account_status'] ?? 'active') === 'active';

            if (!$passwordOk) {
                $error = 'Invalid password for the provided Matric Number and Name.';
                fss_audit('student', 'student_login_failed', 'student', (int)$student['id'], [
                    'reason' => 'wrong_password',
                    'matric_number' => $student['matric_number'],
                ], (int)$student['id']);
            } elseif (!$accountActive) {
                $error = 'Your account is currently ' . htmlspecialchars($student['account_status']) . '. Contact the exam officer.';
                fss_audit('student', 'student_login_failed', 'student', (int)$student['id'], [
                    'reason' => 'account_' . $student['account_status'],
                ], (int)$student['id']);
            } else {
                session_regenerate_id(true);

                $_SESSION['student_id']     = (int)$student['id'];
                $_SESSION['student_name']   = $student['name'];
                $_SESSION['class_id']       = (int)$student['class_id'];
                $_SESSION['department']     = $student['department'];
                $_SESSION['matric_number']  = $student['matric_number'];
                $_SESSION['programme']      = $student['programme'] ?? null;
                $_SESSION['login_ts']       = time();

                $pdo->prepare("
                    INSERT INTO student_activity_log (student_id, activity_type, details, login_time)
                    VALUES (?, 'Login', ?, CURRENT_TIMESTAMP)
                ")->execute([(int)$student['id'], 'Student logged into portal (password=' . ($hasPassword ? 'yes' : 'notset') . ')']);

                fss_audit('student', 'student_login', 'student', (int)$student['id'], [
                    'matric_number' => $student['matric_number'],
                    'has_password'  => $hasPassword,
                ], (int)$student['id']);

                header('Location: dashboard.php');
                exit;
            }
        } else {
            $error = 'Invalid Matric Number or Name. Please check and try again.';
            fss_audit('student', 'student_login_failed', null, null, [
                'reason' => 'unknown_student',
                'supplied_matric' => $matric_no,
                'supplied_name'   => $name,
            ], 0);
        }
    } else {
        $error = 'Please enter both Matric Number and Name';
    }
}

include(__DIR__ . '/includes/header.php');
?>

<div class="min-vh-100 d-flex align-items-center" style="background: var(--color-bg-secondary);">
    <div class="container">
        <div class="row justify-content-center align-items-center g-5 py-5">
            <div class="col-lg-5 d-none d-lg-block">
                <div class="mb-5">
                    <a href="index.php" class="d-flex align-items-center text-decoration-none mb-5">
                      <img src="https://fss-oyo.edu.ng/images/FSS_LOGO.png" alt="FSS OYO Logo" style="height: 42px; border-radius: var(--radius-md); object-fit: contain;" class="me-3" onerror="this.style.display='none'">
             <div>
                            <h5 class="mb-0" style="color: var(--color-text);">FSS OYO</h5>
                            <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500;">CBT Portal</span>
                        </div>
                    </a>
                </div>
                <div class="mb-5">
                    <div style="display: inline-flex; align-items: center; padding: 0.4rem 0.9rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 100px; font-size: 0.8rem; font-weight: 500; color: var(--color-text-secondary); margin-bottom: 1.75rem;">
                        <i class="fas fa-user-graduate me-2" style="color: var(--color-primary);"></i>
                        Student Portal &mdash; Polytechnic CBT
                    </div>
                </div>
                <h1 class="mb-4" style="font-size: 2.5rem;">
                    Welcome back,<br>ready to excel?
                </h1>
                <p class="lead mb-5" style="font-size: 1.05rem;">
                    Sign in with your credentials. After a successful login you will
                    be required to complete a dual biometric verification (face +
                    fingerprint) before any examination can begin.
                </p>

                <div class="card p-4 mb-4" style="border-left: 3px solid var(--color-primary);">
                    <h6 class="mb-3"><i class="fas fa-check-circle me-2" style="color: var(--color-success);"></i> Before you start</h6>
                    <ul class="list-unstyled mb-0" style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <li style="font-size: 0.88rem; color: var(--color-text-secondary);"><i class="fas fa-circle me-2" style="font-size: 0.35rem; color: var(--color-text-muted);"></i>Allow camera access for face verification</li>
                        <li style="font-size: 0.88rem; color: var(--color-text-secondary);"><i class="fas fa-circle me-2" style="font-size: 0.35rem; color: var(--color-text-muted);"></i>Have your fingerprint scanner ready</li>
                        <li style="font-size: 0.88rem; color: var(--color-text-secondary);"><i class="fas fa-circle me-2" style="font-size: 0.35rem; color: var(--color-text-muted);"></i>Do not refresh the page during the examination</li>
                        <li style="font-size: 0.88rem; color: var(--color-text-secondary);"><i class="fas fa-circle me-2" style="font-size: 0.35rem; color: var(--color-text-muted);"></i>Examination offences are tracked and can disqualify you automatically</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card p-4 p-md-5">
                    <div class="text-center mb-4 d-lg-none">
                        <a href="index.php" class="d-flex align-items-center justify-content-center text-decoration-none mb-4">
                            <img src="assets/kingsparkl.jpg" alt="FSS OYO Logo" style="height: 48px; width: 48px; border-radius: var(--radius-md); object-fit: cover;" class="me-3" onerror="this.style.display='none'">
                            <div class="text-start">
                                <h5 class="mb-0" style="color: var(--color-text);">FSS OYO</h5>
                                <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500;">CBT Portal</span>
                            </div>
                        </a>
                    </div>

                    <div class="text-center mb-5">
                        <div style="width: 64px; height: 64px; border-radius: var(--radius-lg); background: var(--color-bg-tertiary); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                            <i class="fas fa-user-graduate" style="font-size: 1.5rem; color: var(--color-primary);"></i>
                        </div>
                        <h4 class="mb-2">Student Login</h4>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">
                            FSS OYO Polytechnic &mdash; Computer Science Department
                        </p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2 mb-4" role="alert">
                            <i class="fas fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($warning): ?>
                        <div class="alert alert-warning py-2 mb-4" role="alert">
                            <i class="fas fa-circle-info me-2"></i> <?php echo htmlspecialchars($warning); ?>
                        </div>
                    <?php endif; ?>

                    <form action="student_login.php" method="POST" id="studentLoginForm">
                        <div class="mb-3">
                            <label for="matric_no" class="form-label">Matric Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                <input type="text" name="matric_no" id="matric_no" class="form-control" placeholder="e.g. FSSOYO/ND/CS/2024/001" required autocomplete="username">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" name="name" id="name" class="form-control" placeholder="Enter your full name as registered" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label">Password <small class="text-muted">(if assigned)</small></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" id="togglePw" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="form-text">If you have not been assigned a password yet, you may leave this field blank for this login.</div>
                        </div>
                        <div class="d-grid mb-4">
                            <button type="submit" class="btn btn-primary btn-lg py-3">
                                Login to Portal <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </form>

                    <div class="text-center pt-3" style="border-top: 1px solid var(--color-border-light);">
                        <a href="index.php" class="text-decoration-none" style="font-size: 0.9rem; color: var(--color-text-secondary); font-weight: 500;">
                            <i class="fas fa-house me-2"></i> Back to Homepage
                        </a>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <p class="text-muted mb-0" style="font-size: 0.82rem;">
                        <i class="fas fa-shield-halved me-1"></i>
                        Your session is protected and activity is logged.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('togglePw').addEventListener('click', function () {
    var i = this.querySelector('i');
    var p = document.getElementById('password');
    if (p.type === 'password') { p.type = 'text'; i.classList.replace('fa-eye','fa-eye-slash'); }
    else                       { p.type = 'password'; i.classList.replace('fa-eye-slash','fa-eye'); }
});
</script>

<?php include(__DIR__ . '/includes/footer.php'); ?>
