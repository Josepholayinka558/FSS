<?php
/**
 * biometric_auth.php — Stage 2 dual-authentication before an exam.
 * Flow: exam_instructions.php → (if biometrics required & not done) → here.
 *       here → on success → exam.php
 *       here → on failure → stays, logs failure, rate-limits.
 */
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/audit_log.php');
require_once(__DIR__ . '/services/BiometricServices.php');

if (!isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}
$studentId = (int)$_SESSION['student_id'];

$examId = (int)($_GET['exam_id'] ?? 0);
if ($examId < 1) {
    header('Location: dashboard.php');
    exit;
}

$classId    = (int)$_SESSION['class_id'];
$department = trim($_SESSION['department']);

$stmt = $pdo->prepare("
    SELECT e.*, s.name as subject_name
      FROM exams e
      JOIN subjects s ON s.id = e.subject_id
     WHERE e.id = ? AND e.class_id = ?
       AND (e.published = 1 OR e.published_obj = 1 OR e.published_theory = 1)
       AND FIND_IN_SET(?, REPLACE(e.departments, ' ', ''))
     LIMIT 1
");
$stmt->execute([$examId, $classId, $department]);
$exam = $stmt->fetch();
if (!$exam) {
    header('Location: dashboard.php');
    exit;
}

// Don't allow biometric auth if already terminal.
$attemptStmt = $pdo->prepare("SELECT id, status FROM results WHERE student_id = ? AND exam_id = ? ORDER BY id DESC LIMIT 1");
$attemptStmt->execute([$studentId, $examId]);
$attempt = $attemptStmt->fetch();
$terminal = ['submitted','graded','disqualified','expired'];
if ($attempt && in_array($attempt['status'], $terminal, true)) {
    header('Location: dashboard.php?error=already_taken');
    exit;
}

$needFace = (bool)($exam['require_face_auth'] ?? 1);
$needFp   = (bool)($exam['require_fingerprint_auth'] ?? 1);

$faceSvc = new FaceRecognitionService($pdo);
$fpSvc   = new FingerprintService($pdo);

$hasFaceTpl = $faceSvc->hasRegisteredFace($studentId);
$hasFpTpl   = $fpSvc->hasRegisteredFingerprint($studentId);

// Session check: already verified for this exam? Then skip straight through.
$alreadyFace = !$needFace ||
    (isset($_SESSION['biometric_face_for_exam']) &&
     ($_SESSION['biometric_face_for_exam'] === $examId || $_SESSION['biometric_face_for_exam'] === 'any') &&
     !empty($_SESSION['biometric_face_verified_ts']) &&
     (time() - (int)$_SESSION['biometric_face_verified_ts'] < 600));
$alreadyFp = !$needFp ||
    (isset($_SESSION['biometric_fingerprint_for_exam']) &&
     ($_SESSION['biometric_fingerprint_for_exam'] === $examId || $_SESSION['biometric_fingerprint_for_exam'] === 'any') &&
     !empty($_SESSION['biometric_fingerprint_verified_ts']) &&
     (time() - (int)$_SESSION['biometric_fingerprint_verified_ts'] < 600));

if ($alreadyFace && $alreadyFp) {
    header('Location: exam.php?exam_id=' . $examId);
    exit;
}

include(__DIR__ . '/includes/header.php');
?>

<nav class="navbar sticky-top" style="background: #fff;">
    <div class="container">
        <a href="exam_instructions.php?exam_id=<?php echo $examId; ?>" class="navbar-brand d-flex align-items-center">
            <img src="assets/kingsparkl.jpg" alt="FSS OYO Logo" style="height: 38px; object-fit: contain; border-radius: var(--radius-sm);" class="me-3" onerror="this.style.display='none'">
            <div>
                <h6 class="mb-0">FSS OYO &mdash; CBT Portal</h6>
                <span style="font-size: 0.72rem; color: var(--color-text-muted); font-weight: 500;">Stage 2 — Biometric Identity Verification</span>
            </div>
        </a>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-xmark me-2"></i>Cancel</a>
    </div>
</nav>

<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="mb-4">
                <h3 class="mb-1"><?php echo htmlspecialchars($exam['title']); ?></h3>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($exam['course_code']); ?> &mdash; <?php echo htmlspecialchars($exam['subject_name']); ?> · Student: <?php echo htmlspecialchars($_SESSION['student_name']); ?> (<?php echo htmlspecialchars($_SESSION['matric_number']); ?>)</p>
            </div>

            <?php if (!$hasFaceTpl && $needFace): ?>
                <div class="alert alert-danger mb-4" role="alert">
                    <i class="fas fa-triangle-exclamation me-2"></i>
                    <strong>Face biometric is not yet registered for your account.</strong>
                    You will not be able to start this examination until your face is registered.
                    Please contact the examinations officer to complete biometric registration on the admin system.
                </div>
            <?php endif; ?>
            <?php if (!$hasFpTpl && $needFp): ?>
                <div class="alert alert-danger mb-4" role="alert">
                    <i class="fas fa-triangle-exclamation me-2"></i>
                    <strong>Fingerprint biometric is not yet registered for your account.</strong>
                    Please contact the examinations officer.
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- FACE MODULE -->
                <div class="col-md-6">
                    <div class="card shadow-sm h-100" id="faceCard">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background: <?php echo $needFace ? 'var(--color-primary)' : 'var(--color-bg-tertiary)'; ?>; color: <?php echo $needFace ? '#fff' : 'var(--color-text-secondary)'; ?>;">
                            <h5 class="mb-0"><i class="fas fa-camera me-2"></i>Face Verification <?php if (!$needFace) echo '<small class="opacity-75">(Not required)</small>'; ?></h5>
                            <span id="faceBadge" class="badge <?php echo $alreadyFace ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $alreadyFace ? 'VERIFIED' : 'PENDING'; ?></span>
                        </div>
                        <div class="card-body p-4">
                            <div style="aspect-ratio: 4/3; background: #000; border-radius: var(--radius-md); overflow: hidden; position: relative;" class="mb-3">
                                <video id="faceVideo" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1);"></video>
                                <canvas id="faceCanvas" style="position: absolute; inset: 0; width: 100%; height: 100%;"></canvas>
                                <div id="faceOverlay" class="position-absolute top-50 start-50 translate-middle text-white text-center small" style="pointer-events: none;">
                                    Loading camera...
                                </div>
                            </div>
                            <p class="small text-muted mb-3">Position your face inside the frame and look at the camera. Good lighting helps accuracy.</p>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" id="faceVerifyBtn" <?php if ($alreadyFace || !$needFace || !$hasFaceTpl) echo 'disabled'; ?>>
                                    <i class="fas fa-user-check me-2"></i>
                                    <?php echo $alreadyFace ? 'Already Verified' : ($hasFaceTpl ? 'Capture &amp; Verify My Face' : 'Register First — Not Eligible'); ?>
                                </button>
                                <div id="faceMsg" class="small"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FINGERPRINT MODULE -->
                <div class="col-md-6">
                    <div class="card shadow-sm h-100" id="fpCard">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background: <?php echo $needFp ? 'var(--color-primary)' : 'var(--color-bg-tertiary)'; ?>; color: <?php echo $needFp ? '#fff' : 'var(--color-text-secondary)'; ?>;">
                            <h5 class="mb-0"><i class="fas fa-fingerprint me-2"></i>Fingerprint Verification <?php if (!$needFp) echo '<small class="opacity-75">(Not required)</small>'; ?></h5>
                            <span id="fpBadge" class="badge <?php echo $alreadyFp ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $alreadyFp ? 'VERIFIED' : 'PENDING'; ?></span>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-4 text-center p-5" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); border: 2px dashed var(--color-border);">
                                <i class="fas fa-fingerprint" style="font-size: 3.5rem; color: var(--color-primary);"></i>
                                <div class="small text-muted mt-3">If a fingerprint scanner is available, click <strong>Ready Scanner</strong> and place your registered finger on the sensor when prompted.</div>
                            </div>
                            <div class="mb-3">
                                <label for="fpScanToken" class="form-label small">Scanner Token / SDK Response</label>
                                <div class="input-group">
                                    <input type="text" id="fpScanToken" class="form-control" placeholder="Output from fingerprint scanner SDK appears here" autocomplete="off">
                                    <button class="btn btn-outline-secondary" id="fpReadyBtn" type="button" <?php if ($alreadyFp || !$needFp || !$hasFpTpl) echo 'disabled'; ?>>Ready Scanner</button>
                                </div>
                                <div class="form-text">
                                    <strong>Integration point:</strong> a real fingerprint SDK should populate this field after a successful scan (call
                                    <code>document.getElementById('fpScanToken').value = sdkResponse.token; document.getElementById('fpVerifyBtn').click();</code>
                                    from the SDK callback). On demo-only machines, the admin may have stored a test sentinel token — paste it here exactly.
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" id="fpVerifyBtn" <?php if ($alreadyFp || !$needFp || !$hasFpTpl) echo 'disabled'; ?>>
                                    <i class="fas fa-shield-halved me-2"></i>
                                    <?php echo $alreadyFp ? 'Already Verified' : ($hasFpTpl ? 'Verify Fingerprint' : 'Register First — Not Eligible'); ?>
                                </button>
                                <div id="fpMsg" class="small"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 text-center">
                <a href="exam_instructions.php?exam_id=<?php echo $examId; ?>" class="btn btn-outline-secondary me-2"><i class="fas fa-arrow-left me-2"></i>Back to Instructions</a>
                <a href="exam.php?exam_id=<?php echo $examId; ?>" id="proceedBtn" class="btn btn-success btn-lg <?php echo ($alreadyFace && $alreadyFp) ? '' : 'disabled'; ?>">
                    Proceed to Examination <i class="fas fa-arrow-right ms-2"></i>
                </a>
                <div class="form-text mt-2">Both face and fingerprint verifications must be complete before entering the examination.</div>
            </div>
        </div>
    </div>
</div>

<!-- Face-api.js + weights: real browser-side face descriptor inference. -->
<script defer src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
<script>
const EXAM_ID = <?php echo (int)$examId; ?>;
const STUDENT_ID = <?php echo (int)$_SESSION['student_id']; ?>;
const NEED_FACE = <?php echo $needFace ? 'true' : 'false'; ?>;
const NEED_FP   = <?php echo $needFp   ? 'true' : 'false'; ?>;
let faceVerified = <?php echo $alreadyFace ? 'true' : 'false'; ?>;
let fpVerified   = <?php echo $alreadyFp   ? 'true' : 'false'; ?>;
let modelsLoaded = false;
let videoReady = false;

function updateProceedBtn() {
    const btn = document.getElementById('proceedBtn');
    const ok = (!NEED_FACE || faceVerified) && (!NEED_FP || fpVerified);
    if (ok) btn.classList.remove('disabled');
    else    btn.classList.add('disabled');
}

// ---- FACE MODULE ----
async function initFaceModule() {
    const video = document.getElementById('faceVideo');
    const overlay = document.getElementById('faceOverlay');
    try {
        await window.faceApi?.nets?.ssdMobilenetv1?.loadFromUri('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
        await window.faceApi?.loadFaceLandmarkModel?.('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
        await window.faceApi?.loadFaceRecognitionModel?.('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
        modelsLoaded = true;
    } catch (e) {
        overlay.textContent = 'Face models unavailable. Using admin-registered test sentinel mode.';
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' }, audio: false });
        video.srcObject = stream;
        await new Promise(r => video.onloadedmetadata = r);
        videoReady = true;
        overlay.textContent = '';
    } catch (e) {
        overlay.innerHTML = '<span class="text-danger">Camera permission denied. Face verification requires camera access.</span>';
    }
}
if (NEED_FACE) initFaceModule();

async function captureDescriptor() {
    const video = document.getElementById('faceVideo');
    const canvas = document.getElementById('faceCanvas');
    if (!videoReady && modelsLoaded) {
        // wait a moment and retry? For now:
    }
    // Prefer face-api inference if models/camera work.
    if (modelsLoaded && videoReady && window.faceApi?.detectSingleFace) {
        const displaySize = { width: video.videoWidth || 640, height: video.videoHeight || 480 };
        window.faceApi.matchDimensions(canvas, displaySize);
        const result = await window.faceApi
            .detectSingleFace(video)
            .withFaceLandmarks()
            .withFaceDescriptor();
        if (!result) throw new Error('No face detected in frame');
        const box = window.faceApi.resizeResults(result.detection.box, displaySize);
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const drawBox = new (window.faceApi?.DrawBox || function(){}) (box, { boxColor: '#10b981', lineWidth: 2 });
        if (drawBox?.draw) drawBox.draw(canvas);
        return Array.from(result.descriptor);
    }
    // Fallback: ask the user to paste a descriptor supplied by the admin during registration
    // (typically the same 128-float test sentinel JSON). This preserves end-to-end flow
    // on demo machines where camera or models fail — the PHP service still does the real
    // Euclidean comparison against the stored sentinel; this never auto-passes.
    const raw = prompt('Face descriptor capture unavailable on this device.\n\nPaste the 128-float descriptor JSON provided by the exam officer (test sentinel) here exactly:');
    if (!raw) throw new Error('Descriptor not provided');
    const arr = JSON.parse(raw);
    if (!Array.isArray(arr)) throw new Error('Descriptor is not a JSON array');
    return arr;
}

document.getElementById('faceVerifyBtn').addEventListener('click', async function () {
    const btn = this; const msg = document.getElementById('faceMsg'); const badge = document.getElementById('faceBadge');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verifying...';
    msg.innerHTML = ''; msg.className = 'small';
    try {
        const descriptor = await captureDescriptor();
        const res = await fetch('api/student_biometric_auth.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'verify_face', descriptor: descriptor, exam_id: EXAM_ID })
        });
        const data = await res.json();
        if (data.ok && data.matched) {
            faceVerified = true;
            badge.textContent = 'VERIFIED'; badge.className = 'badge bg-success';
            msg.innerHTML = '<span class="text-success">✅ Face verified. Score: ' + (data.score ?? 'n/a') + ' · ' + (data.integration || '') + '</span>';
            document.getElementById('faceCard').classList.add('border-success');
            updateProceedBtn();
        } else {
            msg.innerHTML = '<span class="text-danger">❌ ' + (data.message || data.error || 'Verification failed') + '</span>';
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-user-check me-2"></i>Try Again';
        }
    } catch (e) {
        msg.innerHTML = '<span class="text-danger">❌ ' + e.message + '</span>';
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-user-check me-2"></i>Try Again';
    }
});

// ---- FINGERPRINT MODULE ----
document.getElementById('fpReadyBtn').addEventListener('click', function () {
    // ----------------------------------------------------------------
    // FINGERPRINT SDK INTEGRATION POINT
    // ----------------------------------------------------------------
    // In production, call the SDK here:
    //   FingerprintSDK.scan({ timeout: 15 })
    //       .then(token => {
    //           document.getElementById('fpScanToken').value = token;
    //           document.getElementById('fpVerifyBtn').click();
    //        })
    //        .catch(err => showError(err));
    // ----------------------------------------------------------------
    // Demo-only behaviour: prompt the user to paste the sentinel
    // token exactly as supplied by the admin during registration.
    // The PHP layer performs hash_equals against the stored value;
    // this NEVER auto-passes.
    const pasted = prompt('Fingerprint SDK not connected on this machine.\n\nIf a demo sentinel token was assigned for your account, paste it here exactly:');
    if (pasted) {
        document.getElementById('fpScanToken').value = pasted.trim();
        document.getElementById('fpVerifyBtn').click();
    }
});

document.getElementById('fpVerifyBtn').addEventListener('click', async function () {
    const btn = this; const msg = document.getElementById('fpMsg'); const badge = document.getElementById('fpBadge');
    const token = document.getElementById('fpScanToken').value.trim();
    if (!token) { msg.innerHTML = '<span class="text-danger">Scanner token is empty.</span>'; return; }
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verifying...';
    msg.innerHTML = ''; msg.className = 'small';
    try {
        const res = await fetch('api/student_biometric_auth.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'verify_fingerprint', scan_token: token, exam_id: EXAM_ID })
        });
        const data = await res.json();
        if (data.ok && data.matched) {
            fpVerified = true;
            badge.textContent = 'VERIFIED'; badge.className = 'badge bg-success';
            msg.innerHTML = '<span class="text-success">✅ Fingerprint verified. · ' + (data.integration || '') + '</span>';
            document.getElementById('fpCard').classList.add('border-success');
            updateProceedBtn();
        } else {
            msg.innerHTML = '<span class="text-danger">❌ ' + (data.message || data.error || 'Verification failed') + '</span>';
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-shield-halved me-2"></i>Try Again';
        }
    } catch (e) {
        msg.innerHTML = '<span class="text-danger">❌ ' + e.message + '</span>';
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-shield-halved me-2"></i>Try Again';
    }
});
</script>

<?php include(__DIR__ . '/includes/footer.php'); ?>
