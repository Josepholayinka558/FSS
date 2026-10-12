<?php
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/audit_log.php');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$adminId = (int)$_SESSION['admin_id'];

$message = '';
$error = '';
$newStudentId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_student'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }

    if (!$error) {
        $matric_no     = trim($_POST['matric_no'] ?? '');
        $reg_no        = trim($_POST['reg_no'] ?? '');
        $name          = trim($_POST['name'] ?? '');
        $class_id      = (int)($_POST['class_id'] ?? 0);
        $department    = trim($_POST['department'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $password      = $_POST['password'] ?? '';
        $programme     = $_POST['programme'] ?? null;
        $semester      = $_POST['semester'] ?? null;
        $academic_session = trim($_POST['academic_session'] ?? '');
        $account_status = $_POST['account_status'] ?? 'active';

        if (!empty($matric_no) && !empty($reg_no) && !empty($name) && $class_id > 0 && !empty($department)) {
            try {
                $cols  = 'matric_number, registration_number, name, class_id, department';
                $ph    = '?,?,?,?,?';
                $vals  = [$matric_no, $reg_no, $name, $class_id, $department];

                if ($email !== '') { $cols .= ', email'; $ph .= ',?'; $vals[] = $email; }
                if ($password !== '') { $cols .= ', password'; $ph .= ',?'; $vals[] = password_hash($password, PASSWORD_DEFAULT); }
                if ($programme)       { $cols .= ', programme';       $ph .= ',?'; $vals[] = $programme; }
                if ($semester)        { $cols .= ', semester';        $ph .= ',?'; $vals[] = $semester; }
                if ($academic_session !== '') { $cols .= ', academic_session'; $ph .= ',?'; $vals[] = $academic_session; }
                $cols .= ', account_status'; $ph .= ',?'; $vals[] = in_array($account_status, ['active','suspended','graduated'], true) ? $account_status : 'active';

                $stmt = $pdo->prepare("INSERT INTO students ($cols) VALUES ($ph)");
                $stmt->execute($vals);
                $newStudentId = (int)$pdo->lastInsertId();

                // If class implies programme but none provided, backfill.
                if (!$programme) {
                    $cp = $pdo->prepare("SELECT programme FROM classes WHERE id = ? LIMIT 1");
                    $cp->execute([$class_id]); $pv = $cp->fetchColumn();
                    if ($pv) {
                        $pdo->prepare("UPDATE students SET programme = ? WHERE id = ?")->execute([$pv, $newStudentId]);
                    }
                }

                fss_audit('admin', 'admin_register_student', 'student', $newStudentId, [
                    'matric_number' => $matric_no,
                    'email_set' => $email !== '',
                    'password_set' => $password !== '',
                ], $adminId);

                $message = 'Student registered successfully. Scroll down to register biometric information.';
            } catch (PDOException $e) {
                $error = 'Error: Matric Number or Registration Number may already exist.';
            }
        } else {
            $error = 'All core fields are required.';
        }
    }
}

$classes = $pdo->query('SELECT * FROM classes ORDER BY name ASC')->fetchAll();
include(__DIR__ . '/../includes/header.php');
include(__DIR__ . '/includes/navbar.php');
?>

<div class="container mt-4 mb-5">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <ul class="nav nav-tabs mb-4" id="regTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-info" type="button">1. Profile</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link<?php echo $newStudentId ? '' : ' disabled'; ?>" data-bs-toggle="tab" data-bs-target="#tab-face" type="button" <?php echo $newStudentId ? '' : 'disabled'; ?>>
                        2. Face Biometric <span id="faceBadgeNav" class="badge bg-secondary ms-1">NOT REGISTERED</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link<?php echo $newStudentId ? '' : ' disabled'; ?>" data-bs-toggle="tab" data-bs-target="#tab-fp" type="button" <?php echo $newStudentId ? '' : 'disabled'; ?>>
                        3. Fingerprint Biometric <span id="fpBadgeNav" class="badge bg-secondary ms-1">NOT REGISTERED</span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="regTabsContent">
                <div class="tab-pane fade show active" id="tab-info">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Register New Student</h5>
                            <?php if ($newStudentId): ?>
                                <a href="edit_student.php?id=<?php echo $newStudentId; ?>" class="btn btn-sm btn-primary">Open Full Profile &rarr;</a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-4">
                            <?php if ($message): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                            <?php endif; ?>
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>

                            <form action="register_student.php" method="POST" id="profileForm">
                                <?php echo fss_csrf_field(); ?>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Matric Number</label>
                                        <input type="text" name="matric_no" class="form-control" placeholder="FSSOYO/ND/CS/2024/001" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Registration Number</label>
                                        <input type="text" name="reg_no" class="form-control" placeholder="FSSREG/2024/001" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="name" class="form-control" placeholder="Surname Firstname Middlename" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email <span class="text-muted">(optional)</span></label>
                                        <input type="email" name="email" class="form-control" placeholder="student@fss-oyo.edu.ng">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Programme</label>
                                        <select name="programme" class="form-select">
                                            <option value="">(Auto from class)</option>
                                            <option value="ND">ND (National Diploma)</option>
                                            <option value="HND">HND (Higher National Diploma)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Class (Level)</label>
                                        <select name="class_id" class="form-select" required>
                                            <option value="">Select</option>
                                            <?php foreach ($classes as $c): ?>
                                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['programme'] ?? ''); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Semester</label>
                                        <select name="semester" class="form-select">
                                            <option value="">—</option>
                                            <option value="1st">1st Semester</option>
                                            <option value="2nd">2nd Semester</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Academic Session</label>
                                        <input type="text" name="academic_session" class="form-control" placeholder="2024/2025">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label d-block">Department</label>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="department" id="dept_cs" value="Computer Science" checked required>
                                            <label class="form-check-label" for="dept_cs">Computer Science</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Account Status</label>
                                        <select name="account_status" class="form-select">
                                            <option value="active" selected>Active</option>
                                            <option value="suspended">Suspended</option>
                                            <option value="graduated">Graduated</option>
                                        </select>
                                    </div>
                                    <div class="col-md-9">
                                        <label class="form-label">Initial Password <span class="text-muted">(optional)</span></label>
                                        <div class="input-group">
                                            <input type="text" name="password" id="pwField" class="form-control" placeholder="Leave blank to set later via admin profile">
                                            <button class="btn btn-outline-secondary" type="button" id="pwGenBtn"><i class="fas fa-wand-magic-sparkles me-1"></i>Generate</button>
                                        </div>
                                        <div class="form-text">If provided, the student must enter this password at Stage 1 login alongside their Matric No and Name.</div>
                                    </div>
                                    <div class="col-md-3 align-self-end">
                                        <button type="submit" name="register_student" class="btn btn-primary w-100">
                                            <i class="fas fa-save me-1"></i>Register Student
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <?php if (!$newStudentId): ?>
                                <div class="alert alert-info mt-4 small" role="alert">
                                    <i class="fas fa-circle-info me-1"></i>
                                    After registering, you will be able to proceed to the Face and Fingerprint biometric registration tabs for this student.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer text-center small">
                            <a href="manage_students.php" class="text-decoration-none">&larr; View All Students</a>
                        </div>
                    </div>
                </div>

                <?php if ($newStudentId): ?>
                <input type="hidden" id="newStudentId" value="<?php echo $newStudentId; ?>">
                <input type="hidden" id="csrfToken" value="<?php echo fss_csrf_token(); ?>">

                <div class="tab-pane fade" id="tab-face">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-camera me-2"></i>Face Registration — <span id="faceStudent"><?php echo htmlspecialchars($name); ?></span></h5>
                            <span id="faceBadgeTop" class="badge bg-secondary">NOT REGISTERED</span>
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
                                        <li>Click <strong>Capture &amp; Register</strong>. A face descriptor will be extracted and stored.</li>
                                    </ol>
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-primary" id="faceCapBtn"><i class="fas fa-camera me-2"></i>Capture &amp; Register Face</button>
                                        <button class="btn btn-outline-success" id="faceSentinelBtn"><i class="fas fa-flask me-2"></i>Create Test Sentinel (demo)</button>
                                        <button class="btn btn-outline-danger" id="faceResetBtn"><i class="fas fa-rotate me-2"></i>Reset Face Registration</button>
                                    </div>
                                    <div id="faceMsg" class="small mt-3"></div>
                                    <hr>
                                    <div class="small text-muted">
                                        <strong>Integration note:</strong> on capture we store a biometric template (128-d descriptor from face-api.js or SDK).
                                        For commercial SDKs, replace the capture body with your SDK call and pass the template into the save endpoint via format field.
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
                            <span id="fpBadgeTop" class="badge bg-secondary">NOT REGISTERED</span>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="p-5 text-center" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); border: 2px dashed var(--color-border);">
                                        <i class="fas fa-fingerprint" style="font-size: 4rem; color: var(--color-primary);"></i>
                                        <div class="mt-3 small">Place the registered finger on the scanner.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6>Scan Steps</h6>
                                    <ol class="small">
                                        <li>Connect a supported fingerprint scanner and initialize its SDK driver.</li>
                                        <li>Click <strong>Capture Fingerprint</strong> below &mdash; the SDK callback should place the template into the Token field.</li>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
<script>
document.getElementById('pwGenBtn').addEventListener('click', function () {
    var s = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&';
    var o = ''; for (var i=0;i<12;i++) o += s.charAt(Math.floor(Math.random()*s.length));
    document.getElementById('pwField').value = o;
});
</script>
<?php if ($newStudentId): ?>
<script>
const STUDENT_ID = <?php echo (int)$newStudentId; ?>;
const CSRF = document.getElementById('csrfToken').value;

async function biometricAction(body) {
    body.student_id = STUDENT_ID;
    const res = await fetch('../api/admin_biometric.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(body)
    });
    return res.json();
}
function setBadge(id, registered) {
    var t = registered ? 'REGISTERED' : 'NOT REGISTERED';
    var cls = registered ? 'bg-success' : 'bg-secondary';
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = t;
    el.className = 'badge ' + cls + (id.indexOf('Nav') >= 0 ? ' ms-1' : '');
}
function setMsg(id, text, isError) {
    var el = document.getElementById(id);
    el.innerHTML = (isError ? '<span class="text-danger">' : '<span class="text-success">') + text + '</span>';
}

// ---- FACE ----
(async function initFace(){
    try {
        await window.faceApi.nets.ssdMobilenetv1.loadFromUri('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
        await window.faceApi.loadFaceLandmarkModel('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
        await window.faceApi.loadFaceRecognitionModel('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
    } catch(_) {}
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' }, audio: false });
        const v = document.getElementById('faceVideo');
        v.srcObject = stream;
        await new Promise(r => v.onloadedmetadata = r);
        document.getElementById('faceOverlay').textContent = '';
    } catch(e) {
        document.getElementById('faceOverlay').innerHTML = '<span class="text-danger">Camera not available. Use the Test Sentinel option for demos.</span>';
    }
})();

document.getElementById('faceCapBtn').addEventListener('click', async function () {
    const video = document.getElementById('faceVideo');
    setMsg('faceMsg', 'Capturing face descriptor…');
    try {
        const result = await window.faceApi.detectSingleFace(video).withFaceLandmarks().withFaceDescriptor();
        if (!result) throw new Error('No face detected in frame. Please adjust your position and lighting.');
        const desc = Array.from(result.descriptor);
        const data = await biometricAction({ action: 'register_face', descriptor: desc, format: 'face-api.js_v1', metadata: { captured_via: 'admin_capture_faceapi' } });
        if (data.ok) {
            setBadge('faceBadgeTop', true); setBadge('faceBadgeNav', true);
            setMsg('faceMsg', '✅ Face registered successfully (registration #' + data.registration_id + ').');
        } else {
            throw new Error(data.error || 'Failed to save face template.');
        }
    } catch (e) { setMsg('faceMsg', '❌ ' + e.message, true); }
});
document.getElementById('faceSentinelBtn').addEventListener('click', async function () {
    setMsg('faceMsg', 'Generating test sentinel descriptor…');
    const data = await biometricAction({ action: 'create_test_sentinel_face' });
    if (data.ok) {
        setBadge('faceBadgeTop', true); setBadge('faceBadgeNav', true);
        window._faceSentinel = data.sentinel_descriptor;
        setMsg('faceMsg',
            '✅ Test sentinel stored (registration #' + data.registration_id + ').<br>' +
            '<strong>Copy this descriptor JSON and paste it at the student verify prompt (demo-only):</strong><br>' +
            '<pre class="mt-2 p-2 bg-light small" style="max-height:120px;overflow:auto;">' + JSON.stringify(data.sentinel_descriptor) + '</pre>' +
            '<button class="btn btn-sm btn-secondary mt-2" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent)">Copy to clipboard</button>'
        , false);
    } else setMsg('faceMsg', '❌ ' + (data.error||'Failed'), true);
});
document.getElementById('faceResetBtn').addEventListener('click', async function () {
    if (!confirm('Reset face registration? Student will have to re-register.')) return;
    const data = await biometricAction({ action: 'reset_face' });
    if (data.ok) { setBadge('faceBadgeTop', false); setBadge('faceBadgeNav', false); setMsg('faceMsg','✅ Reset.'); }
    else setMsg('faceMsg','❌ '+data.error, true);
});

// ---- FINGERPRINT ----
document.getElementById('fpScanBtn').addEventListener('click', function () {
    // ################################################################
    // # FINGERPRINT SDK INTEGRATION POINT (Admin capture)
    // ################################################################
    // Call your fingerprint SDK's capture method here.  When it
    // returns, populate fpTemplate with the base64 template.
    // Example pseudocode:
    //   FingerprintSDK.capture({ quality: 50, timeout: 10 })
    //       .then(res => { document.getElementById('fpTemplate').value = res.templateB64; })
    //       .catch(err => alert(err.message));
    alert('[SDK INTEGRATION POINT] Your fingerprint scanner SDK capture method goes here.\n\nFor demos on hardware-less machines: use the Create Test Sentinel button instead, or paste your test token manually.');
});
document.getElementById('fpSaveBtn').addEventListener('click', async function () {
    const tpl = document.getElementById('fpTemplate').value.trim();
    if (!tpl) { setMsg('fpMsg','❌ Capture or paste a token first.', true); return; }
    const fmt = document.getElementById('fpFormat').value;
    setMsg('fpMsg', 'Saving fingerprint template…');
    const data = await biometricAction({ action: 'register_fingerprint', template_b64: tpl, format: fmt, metadata: { captured_via: 'admin_capture_sdk' } });
    if (data.ok) { setBadge('fpBadgeTop', true); setBadge('fpBadgeNav', true); setMsg('fpMsg','✅ Fingerprint template saved (registration #'+data.registration_id+').'); }
    else setMsg('fpMsg','❌ '+data.error, true);
});
document.getElementById('fpSentinelBtn').addEventListener('click', async function () {
    setMsg('fpMsg','Generating test sentinel token…');
    const data = await biometricAction({ action: 'create_test_sentinel_fingerprint' });
    if (data.ok) {
        setBadge('fpBadgeTop', true); setBadge('fpBadgeNav', true);
        setMsg('fpMsg',
            '✅ Demo test sentinel stored (registration #' + data.registration_id + ').<br>' +
            '<strong>Copy &amp; securely deliver this token to the student for demo verification:</strong><br>' +
            '<div class="input-group mt-2"><input readonly class="form-control font-monospace" value="'+data.sentinel_token+'">' +
            '<button class="btn btn-secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy</button></div>'
        , false);
    } else setMsg('fpMsg','❌ '+data.error, true);
});
document.getElementById('fpResetBtn').addEventListener('click', async function () {
    if (!confirm('Reset fingerprint registration?')) return;
    const data = await biometricAction({ action: 'reset_fingerprint' });
    if (data.ok) { setBadge('fpBadgeTop', false); setBadge('fpBadgeNav', false); setMsg('fpMsg','✅ Reset.'); }
    else setMsg('fpMsg','❌ '+data.error, true);
});
</script>
<?php endif; ?>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
