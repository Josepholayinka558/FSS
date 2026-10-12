<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/audit_log.php');

if (!isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}

$exam_id = (int)($_GET['exam_id'] ?? 0);
if ($exam_id < 1) {
    header('Location: dashboard.php');
    exit;
}

$class_id   = (int)$_SESSION['class_id'];
$department = trim($_SESSION['department']);
$student_name = $_SESSION['student_name'];
$student_id   = (int)$_SESSION['student_id'];

$stmt = $pdo->prepare('
    SELECT e.*, s.name as subject_name
    FROM exams e
    JOIN subjects s ON e.subject_id = s.id
    WHERE e.id = ?
    AND e.class_id = ?
    AND (e.published = 1 OR e.published_obj = 1 OR e.published_theory = 1)
    AND FIND_IN_SET(?, REPLACE(e.departments, " ", ""))
');
$stmt->execute([$exam_id, $class_id, $department]);
$exam = $stmt->fetch();

if (!$exam) {
    header('Location: dashboard.php');
    exit;
}

// If already in a terminal state, show dashboard.
$stmt = $pdo->prepare('SELECT id, status, offence_count, disqualification_reason FROM results WHERE student_id = ? AND exam_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$student_id, $exam_id]);
$attempt = $stmt->fetch();
$terminalStates = ['submitted','auto_submitted','graded','disqualified','expired'];
if ($attempt && in_array($attempt['status'], $terminalStates, true)) {
    header('Location: dashboard.php?error=already_taken');
    exit;
}

// ---- BIOMETRIC GATE ----
// If exam requires face and/or fingerprint and these have NOT been verified for
// this exam in session, redirect to the biometric auth page first.
$needFace = (bool)($exam['require_face_auth'] ?? 1);
$needFp   = (bool)($exam['require_fingerprint_auth'] ?? 1);

$faceOk = !$needFace ||
    (isset($_SESSION['biometric_face_for_exam']) &&
     ($_SESSION['biometric_face_for_exam'] === $exam_id || $_SESSION['biometric_face_for_exam'] === 'any') &&
     !empty($_SESSION['biometric_face_verified_ts']) &&
     (time() - (int)$_SESSION['biometric_face_verified_ts'] < 600));
$fpOk = !$needFp ||
    (isset($_SESSION['biometric_fingerprint_for_exam']) &&
     ($_SESSION['biometric_fingerprint_for_exam'] === $exam_id || $_SESSION['biometric_fingerprint_for_exam'] === 'any') &&
     !empty($_SESSION['biometric_fingerprint_verified_ts']) &&
     (time() - (int)$_SESSION['biometric_fingerprint_verified_ts'] < 600));

if (!$faceOk || !$fpOk) {
    header('Location: biometric_auth.php?exam_id=' . $exam_id);
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?');
$stmt->execute([$exam_id]);
$question_count = (int)$stmt->fetchColumn();

$max_offences = (int)($exam['max_offences'] ?? 3);
if ($max_offences < 1) $max_offences = 3;

include(__DIR__ . '/includes/header.php');
?>

<nav class="navbar sticky-top">
    <div class="container">
        <a href="dashboard.php" class="navbar-brand d-flex align-items-center">
            <img src="assets/kingsparkl.jpg" alt="FSS OYO Logo" style="height: 38px; border-radius: var(--radius-sm); object-fit: contain;" class="me-3" onerror="this.style.display='none'">
            <div>
                <h6 class="mb-0">FSS OYO</h6>
                <span style="font-size: 0.72rem; color: var(--color-text-muted); font-weight: 500;">CBT Portal</span>
            </div>
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="badge bg-success d-none d-sm-inline-flex"><i class="fas fa-face-smile me-1"></i>Face Verified</span>
            <span class="badge bg-success d-none d-sm-inline-flex"><i class="fas fa-fingerprint me-1"></i>Fingerprint Verified</span>
            <span class="badge bg-info d-none d-sm-inline-flex">
                <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($student_name); ?>
            </span>
            <a href="dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-arrow-left me-2"></i> Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card shadow-sm">
                <div class="card-header" style="background: var(--color-primary); border-color: var(--color-primary); color: #fff; border-radius: var(--radius-lg) var(--radius-lg) 0 0 !important;">
                    <h4 class="mb-0" style="color: #fff;"><i class="fas fa-circle-info me-2"></i> Examination Instructions &amp; Conduct Agreement</h4>
                </div>
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-5 pb-4" style="border-bottom: 1px solid var(--color-border-light);">
                        <div style="display: inline-flex; align-items: center; padding: 0.4rem 0.9rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 100px; font-size: 0.78rem; font-weight: 600; color: var(--color-text-secondary); margin-bottom: 1.25rem; letter-spacing: 0.04em; text-transform: uppercase;">
                            Examination Briefing
                        </div>
                        <h2 class="mb-2"><?php echo htmlspecialchars($exam['title']); ?></h2>
                        <p class="text-muted mb-2" style="font-size: 0.95rem;">
                            <strong><?php echo htmlspecialchars($exam['course_code']); ?></strong> &mdash; <?php echo htmlspecialchars($exam['subject_name']); ?>
                        </p>
                        <?php if (!empty($exam['exam_type']) || !empty($exam['semester']) || !empty($exam['academic_session'])): ?>
                        <p class="text-muted mb-0" style="font-size: 0.88rem;">
                            <?php echo htmlspecialchars($exam['exam_type'] ?? ''); ?>
                            <?php if (!empty($exam['semester'])) echo ' · ' . htmlspecialchars($exam['semester']) . ' Semester'; ?>
                            <?php if (!empty($exam['academic_session'])) echo ' · Session ' . htmlspecialchars($exam['academic_session']); ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3 g-md-4 mb-5">
                        <div class="col-md-3">
                            <div class="text-center p-3" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); height: 100%;">
                                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted); margin-bottom: 0.35rem;">Questions</div>
                                <div style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1;"><?php echo $question_count; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); height: 100%;">
                                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted); margin-bottom: 0.35rem;">Duration</div>
                                <div style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1;"><?php echo (int)$exam['duration']; ?><span style="font-size: 1rem; font-weight: 500; color: var(--color-text-muted); margin-left: 0.2rem;">min</span></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); height: 100%;">
                                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted); margin-bottom: 0.35rem;">Max Offences</div>
                                <div style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1; color: var(--color-danger);"><?php echo $max_offences; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); height: 100%;">
                                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted); margin-bottom: 0.35rem;">Format</div>
                                <div style="font-size: 1.1rem; font-weight: 700; letter-spacing: -0.01em; line-height: 1.2; padding-top: 0.3rem;">MCQ &amp; Theory</div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($exam['instructions'])): ?>
                        <div class="alert alert-primary mb-4 py-3" role="alert">
                            <i class="fas fa-circle-info me-2"></i> <strong>Exam-specific instructions:</strong><br>
                            <?php echo nl2br(htmlspecialchars($exam['instructions'])); ?>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-danger mb-4 py-3" role="alert">
                        <i class="fas fa-triangle-exclamation me-2"></i>
                        <strong>OFFENCE MONITORING IS ACTIVE.</strong>
                        Switching tabs/windows, leaving fullscreen, disabling your camera, or detected impersonation will be recorded as offences.
                        Reaching <strong><?php echo $max_offences; ?> offence(s)</strong> will <strong>automatically disqualify</strong> you and submit the exam immediately.
                    </div>

                    <div class="mb-5">
                        <h5 class="mb-3">General Instructions</h5>
                        <div class="list-group">
                            <div class="list-group-item d-flex align-items-start">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #eff6ff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 0.85rem; margin-top: 0.05rem;">
                                    <i class="fas fa-check" style="color: #2563eb; font-size: 0.75rem;"></i>
                                </div>
                                <div style="font-size: 0.92rem;">The examination consists of <strong><?php echo $question_count; ?> questions</strong> with a total duration of <strong><?php echo (int)$exam['duration']; ?> minutes</strong>.</div>
                            </div>
                            <div class="list-group-item d-flex align-items-start">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #ecfdf5; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 0.85rem; margin-top: 0.05rem;">
                                    <i class="fas fa-check" style="color: #059669; font-size: 0.75rem;"></i>
                                </div>
                                <div style="font-size: 0.92rem;">Clicking <strong>Start Now</strong> will enter fullscreen and the timer will start immediately.</div>
                            </div>
                            <div class="list-group-item d-flex align-items-start">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #fffbeb; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 0.85rem; margin-top: 0.05rem;">
                                    <i class="fas fa-check" style="color: #d97706; font-size: 0.75rem;"></i>
                                </div>
                                <div style="font-size: 0.92rem;">Your exam will <strong>auto-submit</strong> automatically when the allotted time expires or you are disqualified.</div>
                            </div>
                            <div class="list-group-item d-flex align-items-start">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #fef2f2; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 0.85rem; margin-top: 0.05rem;">
                                    <i class="fas fa-check" style="color: #dc2626; font-size: 0.75rem;"></i>
                                </div>
                                <div style="font-size: 0.92rem;"><strong>Do not refresh</strong> the page or close your browser during the examination. Remain in the tab at all times.</div>
                            </div>
                            <div class="list-group-item d-flex align-items-start">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #fdf4ff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 0.85rem; margin-top: 0.05rem;">
                                    <i class="fas fa-check" style="color: #9333ea; font-size: 0.75rem;"></i>
                                </div>
                                <div style="font-size: 0.92rem;">Keep the camera active. Face monitoring will run throughout the session where supported by your device.</div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center pt-3">
                        <a href="dashboard.php" class="btn btn-outline-primary btn-lg px-5 me-2">
                            <i class="fas fa-arrow-left me-2"></i> Go Back
                        </a>
                        <a href="exam.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-primary btn-lg px-5" id="startBtn">
                            Start Now <i class="fas fa-play ms-2"></i>
                        </a>
                        <div class="form-text mt-2">By starting the examination you agree to the conduct rules above.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('startBtn').addEventListener('click', function (e) {
    // Try to enter fullscreen right from instructions click.
    var el = document.documentElement;
    var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
    try { if (fn) fn.call(el); } catch (_) {}
});
</script>

<?php include(__DIR__ . '/includes/footer.php'); ?>
