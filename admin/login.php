<?php
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/audit_log.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password !== '') {
        $ip = function_exists('fss_request_ip') ? fss_request_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

        if (function_exists('fss_rate_limit_exceeded') && fss_rate_limit_exceeded('ip', null, 'admin_login_failed', 300, 15)) {
            $error = 'Too many failed login attempts. Please try again in a few minutes.';
        } else {
            $admin = false;
            try {
                $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $admin = $stmt->fetch();
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (strpos($msg, '194') !== false || strpos($msg, '1030') !== false || strpos($msg, '1146') !== false) {
                    $error = 'Database corruption detected for table \'admins\' (missing tablespace). Please follow the manual fix instructions on the admin Dashboard (after logging in via a backup account) or remove the orphaned \'admins.ibd\' file in the MySQL data directory and restart MySQL, then re-run the install SQL. Automatic table rebuild is disabled to protect data integrity.';
                } else {
                    $error = 'Database Error: ' . $msg;
                }
                $admin = false;
            }

            if ($admin && password_verify($password, $admin['password'])) {
                session_regenerate_id(true);

                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_login_at'] = time();
                $_SESSION['admin_login_ip'] = $ip;

                try {
                    fss_audit('admin', 'admin_login', 'admin', (int)$admin['id'], [
                        'username' => $username,
                        'ip' => $ip,
                        'user_agent' => function_exists('fss_user_agent') ? fss_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    ], (int)$admin['id']);
                } catch (Throwable $e) {}

                header('Location: dashboard.php');
                exit;
            } else {
                try {
                    fss_audit('ip', 'admin_login_failed', null, null, [
                        'username' => $username,
                        'ip' => $ip,
                        'user_agent' => function_exists('fss_user_agent') ? fss_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    ], null);
                } catch (Throwable $e) {}
                $error = 'Invalid username or password.';
            }
        }
    } else {
        $error = 'Please enter both username and password.';
    }
}

include(__DIR__ . '/../includes/header.php');
?>
<div class="min-vh-100 d-flex align-items-center" style="background: var(--color-bg-secondary);">
    <div class="container">
        <div class="row justify-content-center align-items-center g-5 py-5">
            <div class="col-lg-5 d-none d-lg-block">
                <div class="mb-5">
                    <a href="../index.php" class="d-flex align-items-center text-decoration-none mb-5">
                       <img src="https://fss-oyo.edu.ng/images/FSS_LOGO.png" alt="FSS OYO Logo" style="height: 42px; border-radius: var(--radius-md); object-fit: contain;" class="me-3" onerror="this.style.display='none'">
                        <div>
                            <h5 class="mb-0" style="color: var(--color-text);">FSS OYO</h5>
                            <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500;">CBT Admin</span>
                        </div>
                    </a>
                </div>
                <div class="mb-5">
                    <div style="display: inline-flex; align-items: center; padding: 0.4rem 0.9rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 100px; font-size: 0.8rem; font-weight: 500; color: var(--color-text-secondary); margin-bottom: 1.75rem;">
                        <i class="fas fa-shield-halved me-2" style="color: var(--color-primary);"></i>
                        Secured Administration Panel
                    </div>
                </div>
                <h1 class="mb-4" style="font-size: 2.5rem;">
                    Manage exams,<br>students &amp; results
                </h1>
                <p class="lead mb-5" style="font-size: 1.05rem;">
                    Centralized control panel for the Federal School of Surveying CBT platform — biometric-stage-2 examinations, continuous assessment, and results approval workflows.
                </p>

                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="card p-3">
                            <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.4rem;"><i class="fas fa-id-card me-1 text-primary"></i>Face + Fingerprint</div>
                            <div class="fw-bold text-sm">Dual Biometric Auth</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card p-3">
                            <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.4rem;"><i class="fas fa-triangle-exclamation me-1 text-warning"></i>Offence Meter</div>
                            <div class="fw-bold text-sm">Live DQ Enforcement</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card p-3">
                            <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.4rem;"><i class="fas fa-chart-line me-1 text-success"></i>Approvals</div>
                            <div class="fw-bold text-sm">Hold / Approve Flow</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card p-3">
                            <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.4rem;"><i class="fas fa-book me-1" style="color:#9333ea;"></i>ND / HND Levels</div>
                            <div class="fw-bold text-sm">Polytechnic Structure</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card p-4 p-md-5 shadow-sm">
                    <div class="text-center mb-4 d-lg-none">
                        <a href="../index.php" class="d-flex align-items-center justify-content-center text-decoration-none mb-4">
                            <img src="../assets/kingsparkl.jpg" alt="FSS OYO Logo" style="height: 48px; width: 48px; border-radius: var(--radius-md); object-fit: cover;" class="me-3" onerror="this.style.display='none'">
                            <div class="text-start">
                                <h5 class="mb-0" style="color: var(--color-text);">FSS OYO</h5>
                                <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500;">CBT Admin</span>
                            </div>
                        </a>
                    </div>

                    <div class="text-center mb-5">
                        <div style="width: 64px; height: 64px; border-radius: var(--radius-lg); background: var(--color-bg-tertiary); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                            <i class="fas fa-user-lock" style="font-size: 1.5rem; color: var(--color-primary);"></i>
                        </div>
                        <h4 class="mb-2">Administrator Login</h4>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">
                            FSS OYO — Computer Science Dept. CBT Portal
                        </p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2 mb-4" role="alert">
                            <i class="fas fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form action="login.php" method="POST" autocomplete="off">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" name="username" id="username" class="form-control" placeholder="Enter admin username" required autofocus autocomplete="username">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                            </div>
                        </div>
                        <div class="d-grid mb-4">
                            <button type="submit" class="btn btn-primary btn-lg py-3">
                                Sign In to Admin <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </form>

                    <div class="row g-3 pt-3" style="border-top: 1px solid var(--color-border-light);">
                        <div class="col-sm-6 text-center text-sm-start">
                            <a href="../index.php" class="text-decoration-none" style="font-size: 0.85rem; color: var(--color-text-secondary); font-weight: 500;">
                                <i class="fas fa-house me-1"></i> Homepage
                            </a>
                        </div>
                        <div class="col-sm-6 text-center text-sm-end">
                            <a href="../student_login.php" class="text-decoration-none" style="font-size: 0.85rem; color: var(--color-text-secondary); font-weight: 500;">
                                <i class="fas fa-user-graduate me-1"></i> Student Portal
                            </a>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <p class="text-muted mb-0" style="font-size: 0.82rem;">
                        <i class="fas fa-shield-halved me-1"></i> 
                        Default on first install: <code class="px-1 bg-light border rounded">admin</code> / <code class="px-1 bg-light border rounded">password</code> — change immediately.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
