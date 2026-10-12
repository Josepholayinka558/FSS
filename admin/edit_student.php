<?php
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/audit_log.php');
require_once(__DIR__ . '/../services/BiometricServices.php');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$adminId = (int)$_SESSION['admin_id'];

$message = '';
$error   = '';
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($student_id < 1) {
    header('Location: manage_students.php');
    exit;
}

$stmt = $pdo->prepare('SELECT s.*, c.name as class_name, c.programme as class_programme FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ?');
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: manage_students.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }

    if (!$error) {
        $matric_no      = trim($_POST['matric_no'] ?? '');
        $reg_no         = trim($_POST['reg_no'] ?? '');
        $name           = trim($_POST['name'] ?? '');
        $class_id       = (int)($_POST['class_id'] ?? 0);
        $department     = trim($_POST['department'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $programme      = $_POST['programme'] ?? null;
        $semester       = $_POST['semester'] ?? null;
        $academic_session = trim($_POST['academic_session'] ?? '');
        $account_status = $_POST['account_status'] ?? 'active';

        if (!in_array($account_status, ['active','suspended','graduated'], true)) $account_status = 'active';

        if (!empty($matric_no) && !empty($reg_no) && !empty($name) && $class_id > 0 && !empty($department)) {
            try {
                $sets  = ['matric_number = ?', 'registration_number = ?', 'name = ?', 'class_id = ?', 'department = ?', 'email = ?', 'programme = ?', 'semester = ?', 'academic_session = ?', 'account_status = ?'];
                $vals  = [$matric_no, $reg_no, $name, $class_id, $department, $email === '' ? null : $email, $programme, $semester, $academic_session === '' ? null : $academic_session, $account_status];

                $sql  = 'UPDATE students SET ' . implode(', ', $sets) . ' WHERE id = ?';
                $vals[] = $student_id;
                $pdo->prepare($sql)->execute($vals);

                if (!$programme) {
                    $cp = $pdo->prepare("SELECT programme FROM classes WHERE id = ? LIMIT 1");
                    $cp->execute([$class_id]); $pv = $cp->fetchColumn();
                    if ($pv) {
                        $pdo->prepare("UPDATE students SET programme = ? WHERE id = ?")->execute([$pv, $student_id]);
                    }
                }

                fss_audit('admin', 'admin_update_student', 'student', $student_id, [
                    'matric_number' => $matric_no,
                    'email_updated' => true,
                    'account_status' => $account_status,
                ], $adminId);

                $message = 'Student profile updated successfully.';

                $stmt = $pdo->prepare('SELECT s.*, c.name as class_name, c.programme as class_programme FROM students s LEFT JOIN classes c ON s.class_id = c.id WHERE s.id = ?');
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
            } catch (PDOException $e) {
                $error = 'Error updating student. Matric Number or Registration Number may already exist.';
            }
        } else {
            $error = 'All core fields (Matric, Reg No, Name, Class, Department) are required.';
        }
    }
}

$faceSvc = new FaceRecognitionService($pdo);
$fpSvc   = new FingerprintService($pdo);
$hasFace = $faceSvc->hasRegisteredFace($student_id);
$hasFp   = $fpSvc->hasRegisteredFingerprint($student_id);

$classes = $pdo->query('SELECT * FROM classes ORDER BY name ASC')->fetchAll();

include(__DIR__ . '/../includes/header.php');
include(__DIR__ . '/includes/navbar.php');
?>
<div class="container mt-4 mb-5">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="fas fa-user-pen me-2"></i>Edit Student Profile</h4>
                <a href="manage_students.php" class="btn btn-outline-secondary btn-sm">&larr; All Students</a>
            </div>

            <div class="card shadow-sm mb-3 border-primary">
                <div class="card-body py-3 px-4 small">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-4"><strong><?php echo htmlspecialchars($student['name']); ?></strong><br><span class="text-muted"><?php echo htmlspecialchars($student['matric_number']); ?> / <?php echo htmlspecialchars($student['registration_number']); ?></span></div>
                        <div class="col-md-3"><i class="fas fa-layer-group me-1 text-muted"></i><?php echo htmlspecialchars($student['class_name'] ?? '—'); ?> <span class="badge bg-light text-dark"><?php echo htmlspecialchars($student['programme'] ?? $student['class_programme'] ?? '—'); ?></span></div>
                        <div class="col-md-2"><i class="fas fa-id-card me-1 text-muted"></i>Face: <span id="faceBadgeHead" class="badge <?php echo $hasFace ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $hasFace ? 'REGISTERED' : 'NOT REGISTERED'; ?></span></div>
                        <div class="col-md-2"><i class="fas fa-fingerprint me-1 text-muted"></i>FP: <span id="fpBadgeHead" class="badge <?php echo $hasFp ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $hasFp ? 'REGISTERED' : 'NOT REGISTERED'; ?></span></div>
                        <div class="col-md-1 text-end">
                            <span class="badge <?php echo $student['account_status'] === 'active' ? 'bg-success' : ($student['account_status'] === 'suspended' ? 'bg-warning text-dark' : 'bg-secondary'); ?>"><?php echo htmlspecialchars(ucfirst($student['account_status'] ?: 'active')); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <ul class="nav nav-tabs mb-4" id="editTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-info" type="button">1. Profile &amp; Password</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-face" type="button">
                        2. Face Biometric <span id="faceBadgeNav" class="badge ms-1 <?php echo $hasFace ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $hasFace ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-fp" type="button">
                        3. Fingerprint Biometric <span id="fpBadgeNav" class="badge ms-1 <?php echo $hasFp ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $hasFp ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="editTabsContent">
                <div class="tab-pane fade show active" id="tab-info">
                    <div class="card shadow-sm">
                        <div class="card-header"><h5 class="mb-0"><i class="fas fa-user-gear me-2"></i>Student Profile</h5></div>
                        <div class="card-body p-4">
                            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                            <form action="edit_student.php?id=<?php echo $student_id; ?>" method="POST" id="profileForm">
                                <?php echo fss_csrf_field(); ?>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Matric Number</label>
                                        <input type="text" name="matric_no" class="form-control" value="<?php echo htmlspecialchars($student['matric_number']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Registration Number</label>
                                        <input type="text" name="reg_no" class="form-control" value="<?php echo htmlspecialchars($student['registration_number']); ?>" required>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($student['name']); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" placeholder="student@fss-oyo.edu.ng">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Programme</label>
                                        <select name="programme" class="form-select">
                                            <option value="">(Auto from class)</option>
                                            <option value="ND" <?php echo ($student['programme'] ?? '') === 'ND' ? 'selected' : ''; ?>>ND (National Diploma)</option>
                                            <option value="HND" <?php echo ($student['programme'] ?? '') === 'HND' ? 'selected' : ''; ?>>HND (Higher National Diploma)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Class (Level)</label>
                                        <select name="class_id" class="form-select" required>
                                            <option value="">Select</option>
                                            <?php foreach ($classes as $c): ?>
                                                <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$student['class_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['programme'] ?? ''); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Semester</label>
                                        <select name="semester" class="form-select">
                                            <option value="">—</option>
                                            <option value="1st" <?php echo ($student['semester'] ?? '') === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                                            <option value="2nd" <?php echo ($student['semester'] ?? '') === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Academic Session</label>
                                        <input type="text" name="academic_session" class="form-control" placeholder="2024/2025" value="<?php echo htmlspecialchars($student['academic_session'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label d-block">Department</label>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="department" id="dept_cs" value="Computer Science" <?php echo $student['department'] === 'Computer Science' ? 'checked' : ''; ?> required>
                                            <label class="form-check-label" for="dept_cs">Computer Science</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Account Status</label>
                                        <select name="account_status" class="form-select">
                                            <option value="active" <?php echo ($student['account_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="suspended" <?php echo ($student['account_status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                            <option value="graduated" <?php echo ($student['account_status'] ?? '') === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                                        </select>
                                    </div>
                                </div>

                                <hr class="my-4">
                                <h6 class="mb-3"><i class="fas fa-key me-2 text-muted"></i>Student Password (Stage-1 Login)</h6>
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-9">
                                        <?php if (empty($student['password'])): ?>
                                            <div class="alert alert-warning py-2 small mb-0">
                                                <i class="fas fa-triangle-exclamation me-1"></i>No password is currently set. The student can still log in with Matric No + Full Name (migration-friendly mode) but biometric stage-2 is required to start an exam. Generate a password below to enforce strong Stage-1 auth.
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-success py-2 small mb-0">
                                                <i class="fas fa-shield-halved me-1"></i>A password hash is stored for this student. They must enter the password at Stage-1 in addition to their Matric No and Name.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3 text-md-end">
                                        <button type="button" class="btn btn-outline-primary w-100" id="pwGenBtn"><i class="fas fa-wand-magic-sparkles me-1"></i>Generate &amp; Save New Password</button>
                                    </div>
                                </div>
                                <div id="pwResult" class="mt-3"></div>

                                <div class="mt-4 pt-3 border-top">
                                    <div class="d-flex justify-content-between">
                                        <a href="manage_students.php" class="btn btn-outline-secondary">Cancel</a>
                                        <button type="submit" name="update_student" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <input type="hidden" id="studentId" value="<?php echo $student_id; ?>">
                <input type="hidden" id="csrfToken" value="<?php echo fss_csrf_token(); ?>">

                <div class="tab-pane fade" id="tab-face">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-camera me-2"></i>Face Registration — <span id="faceStudent"><?php echo htmlspecialchars($student['name']); ?></span></h5>
                            <span id="faceBadgeTop" class="badge <?php echo $hasFace ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $hasFace ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div style="aspect-ratio: 4/3; background: #000; border-radius: var(--radius-md); overflow: hidden; position: relative;">
                                        <video id="faceVideo" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover;transform:scaleX(-1);"></video>
                                        <canvas id="faceCanvas" style="position:absolute;inset:0;width:100%;height:100%;"></canvas>
                                        <div id="faceOverlay" class="position-absolute top-50 start-50 translate-middle text-white text-center small px-3" style="pointer-events:none;">Camera loading…</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6>Capture Steps</h6>
                                    <ol class="small">
                                        <li>Ensure good lighting and a clear frontal face.</li>
                                        <li>Remove caps, masks, and sunglasses.</li>
                                        <li>Click <strong>Capture &amp; Register</strong>. A 128-d face descriptor will be extracted and stored as the biometric template.</li>
                                    </ol>
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-primary" id="faceCapBtn"><i class="fas fa-camera me-2"></i>Capture &amp; Register Face</button>
                                        <button class="btn btn-outline-success" id="faceSentinelBtn"><i class="fas fa-flask me-2"></i>Create Test Sentinel (demo)</button>
                                        <button class="btn btn-outline-danger" id="faceResetBtn"><i class="fas fa-rotate me-2"></i>Reset Face Registration</button>
                                    </div>
                                    <div id="faceMsg" class="small mt-3"></div>
                                    <hr>
                                    <div class="small text-muted">
                                        <strong>Integration note:</strong> on capture we store a biometric template (128-d descriptor). For commercial SDKs, replace capture body with your SDK call and pass the template into the save endpoint via the format field.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-fp">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-fingerprint me-2"></i>Fingerprint Registration</h5>
                            <span id="fpBadgeTop" class="badge <?php echo $hasFp ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $hasFp ? 'REGISTERED' : 'NOT REGISTERED'; ?></span>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="p-5 text-center" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); border: 2px dashed var(--color-border);">
                                        <i class="fas fa-fingerprint" style="font-size: 4rem; color: var(--color-primary);"></i>
                                        <div class="mt-3 small">Place the student's registered finger on the scanner when prompted.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6>Scan Steps</h6>
                                    <ol class="small">
                                        <li>Connect a supported fingerprint scanner and initialize its SDK driver.</li>
                                        <li>Click <strong>Capture Fingerprint</strong> below — the SDK callback should place the template into the Token field.</li>
                                        <li>Click <strong>Save Template</strong> to persist it into the student record.</li>
                                    </ol>
                                    <label class="form-label small mt-2">Scanner Template / Token (Base64 or SDK format)</label>
                                    <div class="input-group mb-2">
                                        <input type="text" id="fpTemplate" class="form-control font-monospace" placeholder="sdk_template_b64 or admin-issued test sentinel">
                                        <button class="btn btn-outline-secondary" id="fpScanBtn" type="button"><i class="fas fa-hand me-1"></i>Capture</button>
                                    </div>
                                    <label class="form-label small">Format / SDK</label>
                                    <div class="input-group mb-3">
                                        <select id="fpFormat" class="form-select">
                                            <option value="sdk_template_b64">SDK Template (Base64)</option>
                                            <option value="digitalpersona">DigitalPersona / Crossmatch</option>
                                            <option value="secugen">SecuGen</option>
                                            <option value="futronic">Futronic</option>
                                            <option value="test_sentinel">Demo Test Sentinel</option>
                                        </select>
                                        <button class="btn btn-primary" id="fpSaveBtn"><i class="fas fa-save me-1"></i>Save Template</button>
                                    </div>
                                    <div class="d-grid gap-2 mb-3">
                                        <button class="btn btn-outline-success" id="fpSentinelBtn"><i class="fas fa-flask me-2"></i>Create Test Sentinel (demo)</button>
                                        <button class="btn btn-outline-danger" id="fpResetBtn"><i class="fas fa-rotate me-2"></i>Reset Fingerprint Registration</button>
                                    </div>
                                    <div id="fpMsg" class="small"></div>
                                    <hr>
                                    <div class="small text-muted">
                                        <strong>SDK integration point:</strong> inside the Capture button handler, call your fingerprint SDK's
                                        <code>capture()</code> method and set <code>document.getElementById('fpTemplate').value = response.templateB64;</code> then click Save.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
<script>
const STUDENT_ID = parseInt(document.getElementById('studentId').value, 10);
const CSRF = document.getElementById('csrfToken').value;

document.getElementById('pwGenBtn').addEventListener('click', async function () {
    const manual = prompt('Enter a custom password (or leave blank to auto-generate a 12-character one):', '');
    if (manual === null) return;
    const target = document.getElementById('pwResult');
    target.innerHTML = '<div class="alert alert-info py-2 small"><i class="fas fa-spinner fa-spin me-1"></i>Generating &amp; hashing password…</div>';
    try {
        const res = await fetch('../api/admin_generate_password.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ student_id: STUDENT_ID, custom_password: manual || null })
        });
        const data = await res.json();
        if (data.ok) {
            target.innerHTML =
                '<div class="alert alert-success">' +
                    '<i class="fas fa-circle-check me-1"></i>Password saved (hash stored).<br>' +
                    '<strong>Copy this cleartext ONCE and deliver securely to the student:</strong>' +
                    '<div class="input-group mt-2">' +
                        '<input readonly class="form-control font-monospace" id="pwCopy" value="' + data.cleartext + '">' +
                        '<button class="btn btn-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'pwCopy\').value)">Copy</button>' +
                    '</div>' +
                '</div>';
        } else {
            target.innerHTML = '<div class="alert alert-danger py-2 small">' + (data.error || 'Failed') + '</div>';
        }
    } catch (e) {
        target.innerHTML = '<div class="alert alert-danger py-2 small">' + e.message + '</div>';
    }
});

async function biometricAction(body) {
    body.student_id = STUDENT_ID;
    const res = await fetch('../api/admin_biometric.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(body)
    });
    return res.json();
}
function setBadge(id, registered) {
    var t = registered ? 'REGISTERED' : 'NOT REGISTERED';
    var cls = registered ? 'bg-success' : 'bg-secondary';
    var el = document.getElementById(id); if (!el) return;
    el.textContent = t;
    el.className = 'badge ' + cls + (id.indexOf('Nav') >= 0 || id.indexOf('Head') >= 0 ? ' ms-1' : '');
}
function setBadgesAll(which, registered) {
    if (which === 'face') { setBadge('faceBadgeTop', registered); setBadge('faceBadgeNav', registered); setBadge('faceBadgeHead', registered); }
    if (which === 'fp')   { setBadge('fpBadgeTop',   registered); setBadge('fpBadgeNav',   registered); setBadge('fpBadgeHead',   registered); }
}
function setMsg(id, text, isError) {
    var el = document.getElementById(id); if (!el) return;
    el.innerHTML = (isError ? '<span class="text-danger">' : '<span class="text-success">') + text + '</span>';
}

(async function initFace(){
    try {
        await window.faceApi.nets.ssdMobilenetv1.loadFromUri('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
        await window.faceApi.loadFaceLandmarkModel('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
        await window.faceApi.loadFaceRecognitionModel('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
    } catch(_) {}
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' }, audio: false });
        const v = document.getElementById('faceVideo'); if (!v) return;
        v.srcObject = stream;
        await new Promise(r => v.onloadedmetadata = r);
        document.getElementById('faceOverlay').textContent = '';
    } catch(e) {
        var ov = document.getElementById('faceOverlay'); if (ov) ov.innerHTML = '<span class="text-danger">Camera not available. Use the Test Sentinel option for demos.</span>';
    }
})();

document.addEventListener('click', function (e) {
    var id = e.target.id;
    if (id === 'faceCapBtn') {
        (async () => {
            const video = document.getElementById('faceVideo');
            setMsg('faceMsg', 'Capturing face descriptor…');
            try {
                const result = await window.faceApi.detectSingleFace(video).withFaceLandmarks().withFaceDescriptor();
                if (!result) throw new Error('No face detected in frame. Please adjust position and lighting.');
                const desc = Array.from(result.descriptor);
                const data = await biometricAction({ action: 'register_face', descriptor: desc, format: 'face-api.js_v1', metadata: { captured_via: 'admin_edit_faceapi' } });
                if (data.ok) { setBadgesAll('face', true); setMsg('faceMsg', '✅ Face registered successfully (registration #' + data.registration_id + ').'); }
                else throw new Error(data.error || 'Failed to save face template.');
            } catch (err) { setMsg('faceMsg', '❌ ' + err.message, true); }
        })();
    }
    if (id === 'faceSentinelBtn') {
        (async () => {
            setMsg('faceMsg', 'Generating test sentinel descriptor…');
            const data = await biometricAction({ action: 'create_test_sentinel_face' });
            if (data.ok) {
                setBadgesAll('face', true);
                window._faceSentinel = data.sentinel_descriptor;
                setMsg('faceMsg',
                    '✅ Test sentinel stored (registration #' + data.registration_id + ').<br>' +
                    '<strong>Copy this descriptor JSON and paste it at the student verify prompt (demo-only):</strong><br>' +
                    '<pre class="mt-2 p-2 bg-light small" style="max-height:120px;overflow:auto;">' + JSON.stringify(data.sentinel_descriptor) + '</pre>' +
                    '<button class="btn btn-sm btn-secondary mt-2" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent)">Copy to clipboard</button>'
                , false);
            } else setMsg('faceMsg', '❌ ' + (data.error||'Failed'), true);
        })();
    }
    if (id === 'faceResetBtn') {
        if (!confirm('Reset face registration? Student will have to re-register.')) return;
        (async () => {
            const data = await biometricAction({ action: 'reset_face' });
            if (data.ok) { setBadgesAll('face', false); setMsg('faceMsg','✅ Reset.'); }
            else setMsg('faceMsg','❌ '+data.error, true);
        })();
    }
    if (id === 'fpScanBtn') {
        alert('[SDK INTEGRATION POINT] Your fingerprint scanner SDK capture method goes here.\n\nFor demos on hardware-less machines: use the Create Test Sentinel button instead, or paste your test token manually.');
    }
    if (id === 'fpSaveBtn') {
        (async () => {
            const tpl = document.getElementById('fpTemplate').value.trim();
            if (!tpl) { setMsg('fpMsg','❌ Capture or paste a token first.', true); return; }
            const fmt = document.getElementById('fpFormat').value;
            setMsg('fpMsg', 'Saving fingerprint template…');
            const data = await biometricAction({ action: 'register_fingerprint', template_b64: tpl, format: fmt, metadata: { captured_via: 'admin_edit_sdk' } });
            if (data.ok) { setBadgesAll('fp', true); setMsg('fpMsg','✅ Fingerprint template saved (registration #'+data.registration_id+').'); }
            else setMsg('fpMsg','❌ '+data.error, true);
        })();
    }
    if (id === 'fpSentinelBtn') {
        (async () => {
            setMsg('fpMsg','Generating test sentinel token…');
            const data = await biometricAction({ action: 'create_test_sentinel_fingerprint' });
            if (data.ok) {
                setBadgesAll('fp', true);
                setMsg('fpMsg',
                    '✅ Demo test sentinel stored (registration #' + data.registration_id + ').<br>' +
                    '<strong>Copy &amp; securely deliver this token to the student for demo verification:</strong><br>' +
                    '<div class="input-group mt-2"><input readonly class="form-control font-monospace" value="'+data.sentinel_token+'">' +
                    '<button class="btn btn-secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy</button></div>'
                , false);
            } else setMsg('fpMsg','❌ '+data.error, true);
        })();
    }
    if (id === 'fpResetBtn') {
        if (!confirm('Reset fingerprint registration?')) return;
        (async () => {
            const data = await biometricAction({ action: 'reset_fingerprint' });
            if (data.ok) { setBadgesAll('fp', false); setMsg('fpMsg','✅ Reset.'); }
            else setMsg('fpMsg','❌ '+data.error, true);
        })();
    }
});
</script>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
