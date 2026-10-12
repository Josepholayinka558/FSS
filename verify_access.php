<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/csrf.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $entered_code = trim($_POST['access_code']);
    
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'exam_access_code'");
    $stmt->execute();
    $actual_code = $stmt->fetchColumn();

    if ($entered_code === $actual_code) {
        $_SESSION['access_granted'] = true;
        header('Location: student_login.php');
        exit;
    } else {
        $error = 'Invalid Access Code. Please contact the exam invigilator.';
    }
    }
}

include('includes/header.php');
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
                        <i class="fas fa-shield-halved me-2" style="color: var(--color-primary);"></i>
                        Secure Exam Access
                    </div>
                </div>
                <h1 class="mb-4" style="font-size: 2.5rem;">
                    Secure access<br>to your examinations
                </h1>
                <p class="lead mb-5" style="font-size: 1.05rem;">
                    Enter the exam access code provided by your invigilator to continue 
                    to the student login portal.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <div style="display: flex; align-items: flex-start;">
                        <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: #ecfdf5; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas fa-lock" style="color: #059669; font-size: 0.9rem;"></i>
                        </div>
                        <div class="ms-3">
                            <div style="font-weight: 600; font-size: 0.9rem;">Protected Sessions</div>
                            <div style="font-size: 0.82rem; color: var(--color-text-muted);">All exam sessions are tracked and secured.</div>
                        </div>
                    </div>
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
                            <i class="fas fa-lock-keyhole" style="font-size: 1.5rem; color: var(--color-primary);"></i>
                        </div>
                        <h4 class="mb-2">Exam Access Control</h4>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">
                            Computer Science Department
                        </p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2 mb-4" role="alert">
                            <i class="fas fa-triangle-exclamation me-2"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form action="verify_access.php" method="POST">
                        <?php fss_csrf_field(); ?>
                        <div class="mb-4">
                            <label for="access_code" class="form-label">Access Code</label>
                            <input type="text" 
                                   name="access_code" 
                                   id="access_code" 
                                   class="form-control form-control-lg text-center" 
                                   placeholder="Enter the 6-digit code"
                                   style="font-size: 1.25rem; letter-spacing: 0.35em; font-weight: 600;"
                                   required 
                                   autofocus 
                                   autocomplete="off"
                                   maxlength="20">
                        </div>
                        <div class="d-grid mb-4">
                            <button type="submit" name="verify_code" class="btn btn-primary btn-lg py-3">
                                Continue to Login <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </form>

                    <div class="text-center pt-3" style="border-top: 1px solid var(--color-border-light);">
                        <a href="index.php" class="text-decoration-none" style="font-size: 0.9rem; color: var(--color-text-secondary); font-weight: 500;">
                            <i class="fas fa-arrow-left me-2"></i> Back to Homepage
                        </a>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <p class="text-muted mb-0" style="font-size: 0.82rem;">
                        <i class="fas fa-info-circle me-1"></i> 
                        Contact your invigilator if you need assistance.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('includes/footer.php'); ?>
