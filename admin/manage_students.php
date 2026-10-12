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
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }

    if (!$error) {
        $action = $_POST['action'] ?? '';
        $sid    = (int)($_POST['student_id'] ?? 0);

        if ($sid > 0 && $action === 'delete') {
            try {
                $pdo->prepare('DELETE FROM biometric_registrations WHERE student_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM exam_offences WHERE attempt_id IN (SELECT id FROM results WHERE student_id = ?)')->execute([$sid]);
                $pdo->prepare('DELETE FROM results WHERE student_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$sid]);
                fss_audit('admin', 'admin_delete_student', 'student', $sid, [], $adminId);
                $message = 'Student deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Error deleting student. They may have related records that cannot be removed: ' . $e->getMessage();
            }
        }

        if ($sid > 0 && $action === 'reset_face') {
            try {
                require_once(__DIR__ . '/../services/BiometricServices.php');
                $svc = new FaceRecognitionService($pdo);
                $svc->resetFace($sid, $adminId);
                $message = 'Face registration reset for the student.';
            } catch (Throwable $e) {
                $error = 'Reset failed: ' . $e->getMessage();
            }
        }

        if ($sid > 0 && $action === 'reset_fingerprint') {
            try {
                require_once(__DIR__ . '/../services/BiometricServices.php');
                $svc = new FingerprintService($pdo);
                $svc->resetFingerprint($sid, $adminId);
                $message = 'Fingerprint registration reset for the student.';
            } catch (Throwable $e) {
                $error = 'Reset failed: ' . $e->getMessage();
            }
        }

        if ($sid > 0 && $action === 'generate_password') {
            $custom = $_POST['custom_password'] ?? null;
            try {
                $chars  = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&';
                $clear  = is_string($custom) && trim($custom) !== '' ? trim($custom) : substr(str_shuffle(str_repeat($chars, 3)), 0, 12);
                $hash   = password_hash($clear, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE students SET password = ? WHERE id = ?')->execute([$hash, $sid]);
                fss_audit('admin', 'admin_set_student_password', 'student', $sid, ['via' => 'manage_students_row'], $adminId);
                $message =
                    'Password generated &amp; stored (hash). Copy this cleartext ONCE and deliver to the student: ' .
                    '<div class="input-group mt-2 w-75">' .
                        '<input readonly class="form-control font-monospace" id="msPwCopy" value="' . htmlspecialchars($clear) . '">' .
                        '<button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById(\'msPwCopy\').value)">Copy</button>' .
                    '</div>';
            } catch (Throwable $e) {
                $error = 'Failed to set password: ' . $e->getMessage();
            }
        }

        if ($sid > 0 && ($action === 'activate' || $action === 'suspend' || $action === 'graduate')) {
            $map = ['activate' => 'active', 'suspend' => 'suspended', 'graduate' => 'graduated'];
            $status = $map[$action] ?? 'active';
            try {
                $pdo->prepare('UPDATE students SET account_status = ? WHERE id = ?')->execute([$status, $sid]);
                fss_audit('admin', 'admin_set_student_status', 'student', $sid, ['new_status' => $status], $adminId);
                $message = 'Student account status updated to ' . ucfirst($status) . '.';
            } catch (Throwable $e) {
                $error = 'Status update failed.';
            }
        }
    }
}

$students = $pdo->query('
    SELECT s.*, c.name as class_name, c.programme as class_programme,
           (SELECT 1 FROM biometric_registrations br WHERE br.student_id = s.id AND br.biometric_type = \'face\' AND br.status = \'active\' LIMIT 1) as has_face,
           (SELECT 1 FROM biometric_registrations br WHERE br.student_id = s.id AND br.biometric_type = \'fingerprint\' AND br.status = \'active\' LIMIT 1) as has_fp
    FROM students s 
    LEFT JOIN classes c ON s.class_id = c.id 
    ORDER BY s.id DESC
')->fetchAll();

$attemptCounts = [];
try {
    $rows = $pdo->query('
        SELECT student_id, COUNT(*) as total,
               SUM(CASE WHEN status = \'disqualified\' THEN 1 ELSE 0 END) as dq
        FROM results GROUP BY student_id
    ')->fetchAll();
    foreach ($rows as $r) { $attemptCounts[(int)$r['student_id']] = ['total' => (int)$r['total'], 'dq' => (int)$r['dq']]; }
} catch (Throwable $e) {}

include(__DIR__ . '/../includes/header.php');
include(__DIR__ . '/includes/navbar.php');
?>
<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Manage Students</h2>
            <div class="text-muted small">Total: <?php echo count($students); ?> student<?php echo count($students) === 1 ? '' : 's'; ?> — Federal School of Surveying, Computer Science Department</div>
        </div>
        <a href="register_student.php" class="btn btn-primary"><i class="fas fa-user-plus me-1"></i>Register New Student</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="msTable">
                    <thead class="table-light">
                        <tr>
                            <th>Matric No</th>
                            <th>Reg No</th>
                            <th>Name</th>
                            <th>Class</th>
                            <th>Programme</th>
                            <th>Sem / Session</th>
                            <th>Face</th>
                            <th>Fingerprint</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th class="text-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s):
                            $sid = (int)$s['id'];
                            $ac  = $attemptCounts[$sid] ?? ['total' => 0, 'dq' => 0];
                            $prog = $s['programme'] ?: ($s['class_programme'] ?? '—');
                            $semSesh = trim(($s['semester'] ? $s['semester'] . ' ' : '') . ($s['academic_session'] ?? ''));
                            if ($semSesh === '') $semSesh = '—';
                            $statusCls = ($s['account_status'] ?? 'active') === 'active' ? 'bg-success'
                                : (($s['account_status'] ?? '') === 'suspended' ? 'bg-warning text-dark' : 'bg-secondary');
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($s['matric_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($s['registration_number']); ?></td>
                                <td><?php echo htmlspecialchars($s['name']); ?><?php if (!empty($s['email'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($s['email']); ?></small><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($s['class_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($prog); ?></td>
                                <td class="small"><?php echo htmlspecialchars($semSesh); ?></td>
                                <td>
                                    <span class="badge <?php echo !empty($s['has_face']) ? 'bg-success' : 'bg-secondary'; ?>">
                                        <i class="fas fa-id-card me-1"></i><?php echo !empty($s['has_face']) ? 'YES' : 'NO'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo !empty($s['has_fp']) ? 'bg-success' : 'bg-secondary'; ?>">
                                        <i class="fas fa-fingerprint me-1"></i><?php echo !empty($s['has_fp']) ? 'YES' : 'NO'; ?>
                                    </span>
                                </td>
                                <td><span class="badge <?php echo $statusCls; ?>"><?php echo htmlspecialchars(ucfirst($s['account_status'] ?? 'active')); ?></span></td>
                                <td class="small text-nowrap">
                                    <span title="Total attempts"><?php echo (int)$ac['total']; ?></span>
                                    <?php if ($ac['dq'] > 0): ?>
                                        <span class="badge bg-danger ms-1" title="Disqualified attempts">DQ <?php echo (int)$ac['dq']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="edit_student.php?id=<?php echo $sid; ?>" class="btn btn-outline-primary"><i class="fas fa-pen me-1"></i>Edit</a>
                                        <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                            <span class="visually-hidden">More</span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><h6 class="dropdown-header">Biometrics</h6></li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('Reset FACE registration for <?php echo htmlspecialchars(addslashes($s['name'])); ?>?');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reset_face">
                                                    <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                    <button class="dropdown-item text-warning" type="submit"><i class="fas fa-id-card me-1"></i>Reset Face</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('Reset FINGERPRINT registration for <?php echo htmlspecialchars(addslashes($s['name'])); ?>?');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reset_fingerprint">
                                                    <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                    <button class="dropdown-item text-warning" type="submit"><i class="fas fa-fingerprint me-1"></i>Reset Fingerprint</button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><h6 class="dropdown-header">Password</h6></li>
                                            <li>
                                                <button type="button" class="dropdown-item text-primary" onclick="msGenPw(<?php echo $sid; ?>, '<?php echo htmlspecialchars(addslashes($s['name'])); ?>')"><i class="fas fa-key me-1"></i>Generate / Set Password…</button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><h6 class="dropdown-header">Account</h6></li>
                                            <?php if (($s['account_status'] ?? 'active') !== 'active'): ?>
                                            <li>
                                                <form method="POST">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="activate">
                                                    <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                    <button class="dropdown-item text-success" type="submit"><i class="fas fa-check me-1"></i>Mark Active</button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            <?php if (($s['account_status'] ?? '') !== 'suspended'): ?>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('Suspend <?php echo htmlspecialchars(addslashes($s['name'])); ?>? They will not be able to log in or take exams.');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="suspend">
                                                    <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                    <button class="dropdown-item text-warning" type="submit"><i class="fas fa-ban me-1"></i>Suspend</button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            <?php if (($s['account_status'] ?? '') !== 'graduated'): ?>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('Mark as graduated?');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="graduate">
                                                    <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                    <button class="dropdown-item" type="submit"><i class="fas fa-graduation-cap me-1"></i>Mark Graduated</button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('PERMANENTLY DELETE <?php echo htmlspecialchars(addslashes($s['name'])); ?> and all related biometrics, offences, and attempts? This cannot be undone.');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                                                    <button class="dropdown-item text-danger" type="submit"><i class="fas fa-trash me-1"></i>Delete Student</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$students): ?>
                            <tr><td colspan="11" class="text-center text-muted py-5">No students registered yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="msGenPwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" id="msGenPwForm" class="modal-content">
            <?php echo fss_csrf_field(); ?>
            <input type="hidden" name="action" value="generate_password">
            <input type="hidden" name="student_id" id="msGenPwSid" value="">
            <div class="modal-header">
                <h5 class="modal-title">Generate / Set Password — <span id="msGenPwName" class="text-primary"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Custom password (optional — leave empty to auto-generate 12-char):</label>
                <div class="input-group">
                    <input type="text" name="custom_password" id="msGenPwVal" class="form-control font-monospace" placeholder="Leave blank to auto-generate">
                    <button type="button" class="btn btn-outline-secondary" id="msGenPwRandom"><i class="fas fa-wand-magic-sparkles me-1"></i>Random</button>
                </div>
                <div class="form-text">If left blank, a strong 12-character password will be auto-generated and shown to you ONCE after save.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save &amp; Show Cleartext</button>
            </div>
        </form>
    </div>
</div>

<script>
function msGenPw(sid, name) {
    document.getElementById('msGenPwSid').value = sid;
    document.getElementById('msGenPwName').textContent = name;
    document.getElementById('msGenPwVal').value = '';
    (bootstrap.Modal.getOrCreateInstance(document.getElementById('msGenPwModal'))).show();
}
document.getElementById('msGenPwRandom').addEventListener('click', function () {
    var s = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&';
    var o = ''; for (var i = 0; i < 12; i++) o += s.charAt(Math.floor(Math.random() * s.length));
    document.getElementById('msGenPwVal').value = o;
});
</script>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
