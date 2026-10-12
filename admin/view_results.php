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
        $action    = $_POST['action'] ?? '';
        $result_id = (int)($_POST['result_id'] ?? 0);
        $exam_id_q = (int)($_POST['exam_id'] ?? 0);
        $qsuffix   = $exam_id_q ? '?exam_id=' . $exam_id_q : '';

        if ($result_id > 0 && $action === 'reset') {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM exam_offences WHERE attempt_id = ?')->execute([$result_id]);
                $pdo->prepare('DELETE FROM results WHERE id = ?')->execute([$result_id]);
                $pdo->commit();
                fss_audit('admin', 'admin_reset_attempt', 'result', $result_id, [], $adminId);
                $message = 'Exam attempt has been reset successfully. The student can now start the exam again.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error resetting attempt: ' . $e->getMessage();
            }
        }

        if ($result_id > 0 && $action === 'approve') {
            $pdo->prepare("UPDATE results SET result_display_status = 'approved' WHERE id = ?")->execute([$result_id]);
            fss_audit('admin', 'admin_approve_result', 'result', $result_id, [], $adminId);
            $message = 'Result approved for student viewing.';
        }

        if ($result_id > 0 && $action === 'hold') {
            $pdo->prepare("UPDATE results SET result_display_status = 'held' WHERE id = ?")->execute([$result_id]);
            fss_audit('admin', 'admin_hold_result', 'result', $result_id, [], $adminId);
            $message = 'Result has been placed on hold.';
        }
    }
}

$exam_id = (int)($_GET['exam_id'] ?? 0);

function statusBadgeClass($status) {
    switch ($status) {
        case 'graded': return 'bg-success';
        case 'submitted': return 'bg-warning text-dark';
        case 'auto_submitted': return 'bg-info text-dark';
        case 'disqualified': return 'bg-danger';
        case 'expired': return 'bg-secondary';
        case 'in_progress': return 'bg-primary';
        case 'not_started': return 'bg-light text-dark border';
        default: return 'bg-secondary';
    }
}

function attemptStatusLabel($status) {
    static $map = [
        'not_started'    => 'NOT_STARTED',
        'in_progress'    => 'ACTIVE',
        'submitted'      => 'SUBMITTED',
        'auto_submitted' => 'AUTO_SUBMITTED',
        'graded'         => 'GRADED',
        'disqualified'   => 'DISQUALIFIED',
        'expired'        => 'EXPIRED',
    ];
    return $map[$status] ?? strtoupper((string)$status);
}

if ($exam_id) {
    $stmt = $pdo->prepare('
        SELECT r.*, s.name as student_name, s.registration_number, s.matric_number
        FROM results r 
        JOIN students s ON r.student_id = s.id 
        WHERE r.exam_id = ? 
        ORDER BY r.submitted_at DESC
    ');
    $stmt->execute([$exam_id]);
    $results = $stmt->fetchAll();
    
    $stmt = $pdo->prepare('SELECT e.*, s.course_code, s.name as subject_name FROM exams e LEFT JOIN subjects s ON e.subject_id = s.id WHERE e.id = ?');
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch();
    $exam_title = $exam ? ($exam['course_code'] ? $exam['course_code'] . ' — ' : '') . ($exam['title'] ?? 'Exam #' . $exam_id) : null;
} else {
    $results = $pdo->query('
        SELECT r.*, s.name as student_name, s.registration_number, s.matric_number, e.title as exam_title 
        FROM results r 
        JOIN students s ON r.student_id = s.id 
        JOIN exams e ON r.exam_id = e.id 
        ORDER BY r.submitted_at DESC
    ')->fetchAll();
    $exam = null;
}

$attemptIds = [];
foreach ($results as $r) $attemptIds[] = (int)$r['id'];

$offenceCounts = [];
$offenceRows   = [];
if ($attemptIds) {
    $ph = implode(',', array_fill(0, count($attemptIds), '?'));
    $stmt = $pdo->prepare("SELECT attempt_id, COUNT(*) as cnt FROM exam_offences WHERE attempt_id IN ($ph) GROUP BY attempt_id");
    $stmt->execute($attemptIds);
    foreach ($stmt->fetchAll() as $row) $offenceCounts[(int)$row['attempt_id']] = (int)$row['cnt'];

    $stmt = $pdo->prepare("SELECT * FROM exam_offences WHERE attempt_id IN ($ph) ORDER BY attempt_id, offence_number ASC, created_at ASC");
    $stmt->execute($attemptIds);
    foreach ($stmt->fetchAll() as $row) {
        $aid = (int)$row['attempt_id'];
        if (!isset($offenceRows[$aid])) $offenceRows[$aid] = [];
        $offenceRows[$aid][] = $row;
    }
}

$maxOffencesByExam = [];
if ($exam_id && $exam) {
    $maxOffencesByExam[$exam_id] = (int)($exam['max_offences'] ?? 3);
} else {
    $eids = [];
    foreach ($results as $r) $eids[(int)$r['exam_id']] = true;
    if ($eids) {
        $eidsList = array_keys($eids);
        $ph = implode(',', array_fill(0, count($eidsList), '?'));
        $stmt = $pdo->prepare("SELECT id, max_offences FROM exams WHERE id IN ($ph)");
        $stmt->execute($eidsList);
        foreach ($stmt->fetchAll() as $row) $maxOffencesByExam[(int)$row['id']] = (int)($row['max_offences'] ?? 3);
    }
}

include(__DIR__ . '/../includes/header.php');
include(__DIR__ . '/includes/navbar.php');
?>
<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><?php echo isset($exam_title) ? 'Results: ' . htmlspecialchars($exam_title) : 'All Exam Results'; ?></h2>
            <?php if ($exam): ?>
                <div class="text-muted small">
                    <?php echo htmlspecialchars($exam['semester'] ?? '—'); ?> / <?php echo htmlspecialchars($exam['academic_session'] ?? '—'); ?>
                    &nbsp;·&nbsp; Exam type: <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $exam['exam_type'] ?? 'standard'))); ?></strong>
                    &nbsp;·&nbsp; Max offences: <strong><?php echo (int)($exam['max_offences'] ?? 3); ?></strong>
                    &nbsp;·&nbsp; Auth:
                    <?php if (!empty($exam['require_face_auth'])): ?><span class="badge bg-primary ms-1">Face</span><?php endif; ?>
                    <?php if (!empty($exam['require_fingerprint_auth'])): ?><span class="badge bg-primary ms-1">FP</span><?php endif; ?>
                    <?php if (empty($exam['require_face_auth']) && empty($exam['require_fingerprint_auth'])): ?><span class="text-muted">None</span><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <a href="download_results.php<?php echo isset($exam_id) && $exam_id ? '?exam_id='.$exam_id : ''; ?>" class="btn btn-success">
                <i class="fas fa-download me-1"></i> Download CSV
            </a>
            <?php if (isset($exam_title)): ?>
                <a href="view_results.php" class="btn btn-outline-secondary">View All Results</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Matric No</th>
                            <th>Reg No</th>
                            <th>Student Name</th>
                            <?php if (!isset($exam_title)): ?>
                                <th>Exam Title</th>
                            <?php endif; ?>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Auth</th>
                            <th class="text-center">Offences</th>
                            <th>Submitted At</th>
                            <th class="text-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r):
                            $rid      = (int)$r['id'];
                            $ocount   = $offenceCounts[$rid] ?? 0;
                            $omax     = $maxOffencesByExam[(int)$r['exam_id']] ?? 3;
                            $dqRow    = !empty($r['disqualification_reason']) || ($r['status'] ?? '') === 'disqualified';
                            $oCls     = $dqRow ? 'bg-danger' : ($ocount === 0 ? 'bg-light text-dark border' : ($ocount >= $omax - 1 ? 'bg-warning text-dark' : 'bg-info text-dark'));
                        ?>
                            <tr class="<?php echo $dqRow ? 'table-danger bg-opacity-25' : ''; ?>">
                                <td><strong><?php echo htmlspecialchars($r['matric_number'] ?? '—'); ?></strong></td>
                                <td><?php echo htmlspecialchars($r['registration_number'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($r['student_name']); ?></td>
                                <?php if (!isset($exam_title)): ?>
                                    <td><?php echo htmlspecialchars($r['exam_title'] ?? '—'); ?></td>
                                <?php endif; ?>
                                <td>
                                    <strong><?php echo is_numeric($r['score'] ?? null) ? round((float)$r['score']) : '—'; ?>%</strong>
                                    <div class="small text-muted"><?php echo (int)($r['correct_answers'] ?? 0); ?> / <?php echo (int)($r['total_questions'] ?? 0); ?></div>
                                </td>
                                <td>
                                    <span class="badge <?php echo statusBadgeClass($r['status'] ?? 'not_started'); ?>">
                                        <?php echo attemptStatusLabel($r['status'] ?? 'not_started'); ?>
                                    </span>
                                    <?php if (!empty($r['disqualification_reason'])): ?>
                                        <br><small class="text-danger fw-semibold" title="<?php echo htmlspecialchars($r['disqualification_reason']); ?>"><i class="fas fa-triangle-exclamation me-1"></i>DQ: <?php echo htmlspecialchars(trim(strlen($r['disqualification_reason']) > 40 ? substr($r['disqualification_reason'], 0, 40) . '…' : $r['disqualification_reason'])); ?></small>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted">
                                        Display: 
                                        <span class="fw-bold text-<?php 
                                            echo ($r['result_display_status'] ?? 'pending') === 'approved' ? 'success' : (($r['result_display_status'] ?? '') === 'held' ? 'danger' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($r['result_display_status'] ?? 'pending'); ?>
                                        </span>
                                    </small>
                                </td>
                                <td class="small text-nowrap">
                                    <span title="Face auth" class="badge <?php echo ($r['face_auth_status'] ?? '') === 'passed' ? 'bg-success' : 'bg-secondary'; ?>">
                                        <i class="fas fa-id-card me-1"></i><?php echo ucfirst($r['face_auth_status'] ?? 'none'); ?>
                                    </span>
                                    <span title="Fingerprint auth" class="badge mt-1 <?php echo ($r['fingerprint_auth_status'] ?? '') === 'passed' ? 'bg-success' : 'bg-secondary'; ?>">
                                        <i class="fas fa-fingerprint me-1"></i><?php echo ucfirst($r['fingerprint_auth_status'] ?? 'none'); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $oCls; ?> d-inline-flex align-items-center justify-content-center" style="min-width: 72px;">
                                        <?php echo (int)$ocount; ?> / <?php echo (int)$omax; ?>
                                    </span>
                                </td>
                                <td class="small text-nowrap">
                                    <?php
                                        $ts = $r['submitted_at'] ?? ($r['auto_submitted_at'] ?? null);
                                        echo $ts ? date('M d, Y H:i', strtotime($ts)) : '—';
                                        if (!empty($r['auto_submitted_at']) && ($r['status'] ?? '') === 'auto_submitted') echo '<br><small class="text-muted">(auto)</small>';
                                    ?>
                                </td>
                                <td class="text-nowrap">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if (($r['status'] ?? '') !== 'in_progress'): ?>
                                            <a href="grade_theory.php?result_id=<?php echo $rid; ?>" class="btn btn-primary"><i class="fas fa-eye me-1"></i>View/Grade</a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-info <?php echo $ocount ? '' : 'disabled'; ?>" <?php echo $ocount ? 'data-bs-toggle="modal" data-bs-target="#offHistModal" data-offences="'.htmlspecialchars(json_encode($offenceRows[$rid] ?? []), ENT_QUOTES).'" data-name="'.htmlspecialchars($r['student_name'], ENT_QUOTES).'"' : ''; ?>>
                                            <i class="fas fa-list-check me-1"></i>Offences
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                            <span class="visually-hidden">More</span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if (($r['status'] ?? '') !== 'in_progress'): ?>
                                                <?php if (($r['result_display_status'] ?? '') !== 'approved'): ?>
                                                    <li>
                                                        <form method="POST">
                                                            <?php echo fss_csrf_field(); ?>
                                                            <input type="hidden" name="action" value="approve">
                                                            <input type="hidden" name="result_id" value="<?php echo $rid; ?>">
                                                            <?php if ($exam_id): ?><input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>"><?php endif; ?>
                                                            <button class="dropdown-item text-success" type="submit"><i class="fas fa-check me-1"></i>Approve Display</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (($r['result_display_status'] ?? '') !== 'held'): ?>
                                                    <li>
                                                        <form method="POST">
                                                            <?php echo fss_csrf_field(); ?>
                                                            <input type="hidden" name="action" value="hold">
                                                            <input type="hidden" name="result_id" value="<?php echo $rid; ?>">
                                                            <?php if ($exam_id): ?><input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>"><?php endif; ?>
                                                            <button class="dropdown-item text-warning" type="submit"><i class="fas fa-pause me-1"></i>Hold Result</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('Are you sure you want to RESET this attempt? All progress, offences, and score will be permanently lost, and the student will be able to re-take the exam.');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reset">
                                                    <input type="hidden" name="result_id" value="<?php echo $rid; ?>">
                                                    <?php if ($exam_id): ?><input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>"><?php endif; ?>
                                                    <button class="dropdown-item text-danger" type="submit"><i class="fas fa-rotate me-1"></i>Reset Attempt</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$results): ?>
                            <tr><td colspan="10" class="text-center text-muted py-5">No results recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="offHistModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Offence History — <span id="offHistName" class="text-primary"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="offHistTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>IP / Browser</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="offHistBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('offHistModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget; if (!btn) return;
        var data = [], name = '';
        try { data = JSON.parse(btn.getAttribute('data-offences') || '[]'); } catch (_) { data = []; }
        name = btn.getAttribute('data-name') || '';
        document.getElementById('offHistName').textContent = name;
        var body = document.getElementById('offHistBody');
        if (!data || data.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5">No offences recorded for this attempt.</td></tr>';
            return;
        }
        var html = '';
        data.forEach(function (o) {
            var cls = '';
            if (o.offence_type === 'tab_switch' || o.offence_type === 'visibility_change') cls = 'bg-warning text-dark';
            else if (o.offence_type === 'fullscreen_exit') cls = 'bg-info text-dark';
            else if (o.offence_type === 'no_face' || o.offence_type === 'multiple_faces' || o.offence_type === 'camera_disabled') cls = 'bg-primary';
            else if (o.offence_type === 'face_mismatch') cls = 'bg-danger';
            else cls = 'bg-secondary';
            html += '<tr>' +
                '<td><strong>' + (o.offence_number ?? '—') + '</strong></td>' +
                '<td><span class="badge ' + cls + '">' + (o.offence_type ?? '') + '</span></td>' +
                '<td class="small">' + (o.description ? o.description.replace(/</g,'&lt;').replace(/>/g,'&gt;') : '—') + '</td>' +
                '<td class="small text-muted text-nowrap">' +
                    '<div><i class="fas fa-globe me-1"></i>' + (o.ip_address || '—') + '</div>' +
                    '<div class="mt-1" title="' + (o.user_agent ? (o.user_agent+'').replace(/"/g,'&quot;') : '') + '"><i class="fas fa-window-maximize me-1"></i>' +
                        (o.user_agent ? (o.user_agent.length > 48 ? o.user_agent.substring(0, 48) + '…' : o.user_agent) : '—') +
                    '</div>' +
                '</td>' +
                '<td class="small text-nowrap">' + (o.created_at ? new Date(o.created_at.replace(' ','T')).toLocaleString() : '—') + '</td>' +
            '</tr>';
        });
        body.innerHTML = html;
    });
})();
</script>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
