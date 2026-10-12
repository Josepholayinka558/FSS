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

function getExamSectionCounts(PDO $pdo, $examId)
{
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN (
                (COALESCE(eq.question_source, 'questions') = 'question_bank' AND qb.type = 'objective')
                OR
                (COALESCE(eq.question_source, 'questions') <> 'question_bank' AND q.type = 'mcq')
            ) THEN 1 ELSE 0 END) AS obj_count,
            SUM(CASE WHEN (
                (COALESCE(eq.question_source, 'questions') = 'question_bank' AND qb.type = 'theory')
                OR
                (COALESCE(eq.question_source, 'questions') <> 'question_bank' AND q.type = 'theory')
            ) THEN 1 ELSE 0 END) AS theory_count
        FROM exam_questions eq
        LEFT JOIN questions q ON q.id = eq.question_id
        LEFT JOIN question_bank qb ON qb.id = eq.question_id
        WHERE eq.exam_id = ?
    ");
    $stmt->execute([$examId]);
    $counts = $stmt->fetch();
    return [
        'obj_count' => (int)($counts['obj_count'] ?? 0),
        'theory_count' => (int)($counts['theory_count'] ?? 0),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }

    if (!$error) {
        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['exam_id'] ?? 0);

        if ($id > 0) {
            if ($action === 'publish_obj') {
                $counts = getExamSectionCounts($pdo, $id);
                if ($counts['obj_count'] < 1) {
                    $error = 'Objective section is empty and cannot be published.';
                } else {
                    $pdo->prepare('UPDATE exams SET published_obj = 1 WHERE id = ?')->execute([$id]);
                    fss_audit('admin', 'admin_publish_exam_section', 'exam', $id, ['section' => 'objective'], $adminId);
                    $message = 'Objective section published successfully.';
                }
            }

            if ($action === 'unpublish_obj') {
                $pdo->prepare('UPDATE exams SET published_obj = 0 WHERE id = ?')->execute([$id]);
                fss_audit('admin', 'admin_unpublish_exam_section', 'exam', $id, ['section' => 'objective'], $adminId);
                $message = 'Objective section unpublished successfully.';
            }

            if ($action === 'publish_theory') {
                $counts = getExamSectionCounts($pdo, $id);
                if ($counts['theory_count'] < 1) {
                    $error = 'Theory section is empty and cannot be published.';
                } else {
                    $pdo->prepare('UPDATE exams SET published_theory = 1 WHERE id = ?')->execute([$id]);
                    fss_audit('admin', 'admin_publish_exam_section', 'exam', $id, ['section' => 'theory'], $adminId);
                    $message = 'Theory section published successfully.';
                }
            }

            if ($action === 'unpublish_theory') {
                $pdo->prepare('UPDATE exams SET published_theory = 0 WHERE id = ?')->execute([$id]);
                fss_audit('admin', 'admin_unpublish_exam_section', 'exam', $id, ['section' => 'theory'], $adminId);
                $message = 'Theory section unpublished successfully.';
            }

            if ($action === 'reset_results') {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM exam_offences WHERE attempt_id IN (SELECT id FROM results WHERE exam_id = ?)')->execute([$id]);
                    $pdo->prepare('DELETE FROM results WHERE exam_id = ?')->execute([$id]);
                    $pdo->commit();
                    fss_audit('admin', 'admin_reset_exam_results', 'exam', $id, [], $adminId);
                    $message = 'All student attempts &amp; results for this exam have been reset.';
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Error resetting results: ' . $e->getMessage();
                }
            }

            if ($action === 'delete') {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM exam_questions WHERE exam_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM exam_offences WHERE attempt_id IN (SELECT id FROM results WHERE exam_id = ?)')->execute([$id]);
                    $pdo->prepare('DELETE FROM results WHERE exam_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM exams WHERE id = ?')->execute([$id]);
                    $pdo->commit();
                    fss_audit('admin', 'admin_delete_exam', 'exam', $id, [], $adminId);
                    $message = 'Exam deleted successfully.';
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Error deleting exam. It might be linked to records that cannot be removed: ' . $e->getMessage();
                }
            }

            if ($action === 'update_settings') {
                $max_offences = (int)($_POST['max_offences'] ?? 3);
                if ($max_offences < 1) $max_offences = 3;
                $exam_type = in_array($_POST['exam_type'] ?? 'standard', ['ca','mid_semester','exam','quiz','assignment','practical','standard']) ? $_POST['exam_type'] : 'standard';
                $semester  = in_array($_POST['semester'] ?? '', ['1st','2nd']) ? $_POST['semester'] : null;
                $academic_session = trim($_POST['academic_session'] ?? '');
                $require_face = !empty($_POST['require_face_auth']) ? 1 : 0;
                $require_fp   = !empty($_POST['require_fingerprint_auth']) ? 1 : 0;
                $require_fs   = !empty($_POST['require_fullscreen']) ? 1 : 0;
                $instructions = trim($_POST['instructions'] ?? '');

                try {
                    $pdo->prepare('UPDATE exams SET max_offences = ?, exam_type = ?, semester = ?, academic_session = ?, require_face_auth = ?, require_fingerprint_auth = ?, require_fullscreen = ?, instructions = ? WHERE id = ?')
                        ->execute([$max_offences, $exam_type, $semester, $academic_session === '' ? null : $academic_session, $require_face, $require_fp, $require_fs, $instructions === '' ? null : $instructions, $id]);
                    fss_audit('admin', 'admin_update_exam_settings', 'exam', $id, ['max_offences' => $max_offences, 'exam_type' => $exam_type, 'face' => $require_face, 'fp' => $require_fp], $adminId);
                    $message = 'Exam settings updated.';
                } catch (PDOException $e) {
                    $error = 'Failed to update settings: ' . $e->getMessage();
                }
            }
        }
    }
}

$exams = [];
try {
    $exams = $pdo->query('
        SELECT e.*, c.name as class_name, c.programme as class_programme, s.course_code, s.name as subject_name, 
               (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) as question_count,
               (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id) as attempt_count,
               (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id AND r.status = \'disqualified\') as dq_count
        FROM exams e 
        JOIN classes c ON e.class_id = c.id 
        JOIN subjects s ON e.subject_id = s.id 
        ORDER BY e.created_at DESC
    ')->fetchAll();
} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
    if (strpos($e->getMessage(), '194') !== false || strpos($e->getMessage(), '1030') !== false) {
        $error = 'Database corruption detected (Missing Tablespace). Please follow the manual fix instructions provided on the Dashboard.';
    }
}

foreach ($exams as &$exam) {
    $counts = getExamSectionCounts($pdo, (int)$exam['id']);
    $exam['obj_count'] = $counts['obj_count'];
    $exam['theory_count'] = $counts['theory_count'];
}
unset($exam);

function examTypeLabel($t) {
    static $map = [
        'ca' => ['CA', 'bg-info text-dark'],
        'mid_semester' => ['Mid-Sem', 'bg-primary'],
        'exam' => ['Exam', 'bg-dark'],
        'quiz' => ['Quiz', 'bg-warning text-dark'],
        'assignment' => ['Assign', 'bg-secondary'],
        'practical' => ['Pract', 'bg-success'],
        'standard' => ['Std', 'bg-light text-dark border'],
    ];
    $m = $map[$t] ?? ['Std', 'bg-light text-dark border'];
    return '<span class="badge ' . $m[1] . '">' . $m[0] . '</span>';
}

include(__DIR__ . '/../includes/header.php');
include(__DIR__ . '/includes/navbar.php');
?>
<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="mb-1">Manage Exams</h2>
            <div class="text-muted small">Total: <?php echo count($exams); ?> exam<?php echo count($exams) === 1 ? '' : 's'; ?> — FSS Computer Science Department</div>
        </div>
        <a href="create_exam.php" class="btn btn-primary"><i class="fas fa-plus-circle me-1"></i>Create New Exam</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Exam</th>
                            <th>Type</th>
                            <th>Class / Prog</th>
                            <th>Course</th>
                            <th>Sem / Sess</th>
                            <th>Q</th>
                            <th>Time</th>
                            <th>DQ</th>
                            <th>Auth / FS</th>
                            <th>Publish</th>
                            <th class="text-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exams as $e):
                            $eid = (int)$e['id'];
                            $dqMax = (int)($e['max_offences'] ?? 3);
                            $dqCount = (int)($e['dq_count'] ?? 0);
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($e['title']); ?></div>
                                    <small class="text-muted">
                                        <a href="view_results.php?exam_id=<?php echo $eid; ?>" class="text-decoration-none">
                                            <i class="fas fa-chart-line me-1"></i>Attempts: <?php echo (int)($e['attempt_count'] ?? 0); ?><?php if ($dqCount > 0): ?> · <span class="text-danger">DQ: <?php echo $dqCount; ?></span><?php endif; ?>
                                        </a>
                                    </small>
                                </td>
                                <td><?php echo examTypeLabel($e['exam_type'] ?? 'standard'); ?></td>
                                <td class="small">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($e['class_name']); ?></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($e['class_programme'] ?? ''); ?></div>
                                </td>
                                <td class="small">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($e['course_code']); ?></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($e['subject_name']); ?></div>
                                </td>
                                <td class="small text-nowrap">
                                    <div><?php echo htmlspecialchars($e['semester'] ?? '—'); ?></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($e['academic_session'] ?? '—'); ?></div>
                                </td>
                                <td class="small text-nowrap text-center">
                                    <span class="badge bg-light text-dark border"><?php echo (int)$e['question_count']; ?></span>
                                    <div class="text-muted mt-1">OBJ: <?php echo (int)$e['obj_count']; ?> · THY: <?php echo (int)$e['theory_count']; ?></div>
                                </td>
                                <td class="small text-nowrap"><?php echo (int)$e['duration']; ?> mins</td>
                                <td class="text-center">
                                    <span class="badge <?php echo $dqCount > 0 ? 'bg-warning text-dark' : 'bg-light text-dark border'; ?>" title="Max offences before DQ">Max <?php echo $dqMax; ?></span>
                                </td>
                                <td class="small">
                                    <div class="d-flex flex-wrap gap-1 mb-1">
                                        <?php if (!empty($e['require_face_auth'])): ?><span class="badge bg-primary"><i class="fas fa-id-card me-1"></i>Face</span><?php else: ?><span class="badge bg-secondary"><i class="fas fa-id-card me-1"></i>Face Off</span><?php endif; ?>
                                        <?php if (!empty($e['require_fingerprint_auth'])): ?><span class="badge bg-primary"><i class="fas fa-fingerprint me-1"></i>FP</span><?php else: ?><span class="badge bg-secondary"><i class="fas fa-fingerprint me-1"></i>FP Off</span><?php endif; ?>
                                        <?php if (!empty($e['require_fullscreen'])): ?><span class="badge bg-dark text-white"><i class="fas fa-expand me-1"></i>FS</span><?php else: ?><span class="badge bg-light text-dark border"><i class="fas fa-expand me-1"></i>FS Off</span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="small text-nowrap">
                                    <div class="d-flex flex-column gap-1">
                                        <div>
                                            <span class="text-muted me-1">OBJ</span>
                                            <?php if (!$e['published_obj']): ?>
                                                <?php if ((int)$e['obj_count'] < 1): ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Objective section is empty">Publish</button>
                                                <?php else: ?>
                                                    <form method="POST" class="d-inline">
                                                        <?php echo fss_csrf_field(); ?>
                                                        <input type="hidden" name="action" value="publish_obj">
                                                        <input type="hidden" name="exam_id" value="<?php echo $eid; ?>">
                                                        <button class="btn btn-outline-success btn-sm" type="submit">Publish</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="unpublish_obj">
                                                    <input type="hidden" name="exam_id" value="<?php echo $eid; ?>">
                                                    <button class="btn btn-success btn-sm" type="submit"><i class="fas fa-check me-1"></i>Published</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <span class="text-muted me-1">THY</span>
                                            <?php if (!$e['published_theory']): ?>
                                                <?php if ((int)$e['theory_count'] < 1): ?>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Theory section is empty">Publish</button>
                                                <?php else: ?>
                                                    <form method="POST" class="d-inline">
                                                        <?php echo fss_csrf_field(); ?>
                                                        <input type="hidden" name="action" value="publish_theory">
                                                        <input type="hidden" name="exam_id" value="<?php echo $eid; ?>">
                                                        <button class="btn btn-outline-info btn-sm" type="submit">Publish</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="unpublish_theory">
                                                    <input type="hidden" name="exam_id" value="<?php echo $eid; ?>">
                                                    <button class="btn btn-info btn-sm text-white" type="submit"><i class="fas fa-check me-1"></i>Published</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="view_results.php?exam_id=<?php echo $eid; ?>" class="btn btn-outline-primary"><i class="fas fa-chart-column me-1"></i>Results</a>
                                        <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                            <span class="visually-hidden">More</span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#editExamModal" data-exam='<?php echo json_encode(array_map(function ($v) { return is_string($v) ? $v : $v; }, [
                                                'id' => (int)$e['id'],
                                                'title' => $e['title'],
                                                'exam_type' => $e['exam_type'] ?? 'standard',
                                                'semester' => $e['semester'] ?? '',
                                                'academic_session' => $e['academic_session'] ?? '',
                                                'max_offences' => (int)($e['max_offences'] ?? 3),
                                                'require_face_auth' => !empty($e['require_face_auth']) ? 1 : 0,
                                                'require_fingerprint_auth' => !empty($e['require_fingerprint_auth']) ? 1 : 0,
                                                'require_fullscreen' => !empty($e['require_fullscreen']) ? 1 : 0,
                                                'instructions' => $e['instructions'] ?? '',
                                            ]), ENT_QUOTES, 'UTF-8'); ?>'><i class="fas fa-gear me-1"></i>Edit Settings</button></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('Reset ALL results, attempts, and offences for exam «<?php echo htmlspecialchars(addslashes($e['title'])); ?>»? Students will be able to re-take it. This cannot be undone.');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reset_results">
                                                    <input type="hidden" name="exam_id" value="<?php echo $eid; ?>">
                                                    <button class="dropdown-item text-warning" type="submit"><i class="fas fa-rotate me-1"></i>Reset All Results</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" onsubmit="return confirm('PERMANENTLY DELETE exam «<?php echo htmlspecialchars(addslashes($e['title'])); ?>» and all related questions, attempts, offences, and results? This cannot be undone.');">
                                                    <?php echo fss_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="exam_id" value="<?php echo $eid; ?>">
                                                    <button class="dropdown-item text-danger" type="submit"><i class="fas fa-trash me-1"></i>Delete Exam</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$exams): ?>
                            <tr><td colspan="11" class="text-center text-muted py-5">No exams yet. <a href="create_exam.php" class="text-decoration-none">Create the first exam &rarr;</a></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editExamModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content" id="editExamForm">
            <?php echo fss_csrf_field(); ?>
            <input type="hidden" name="action" value="update_settings">
            <input type="hidden" name="exam_id" id="eeId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Edit Exam Settings — <span id="eeTitle" class="text-primary"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Exam Type</label>
                        <select name="exam_type" id="eeType" class="form-select">
                            <option value="standard">Standard / General</option>
                            <option value="ca">Continuous Assessment (CA)</option>
                            <option value="quiz">Quiz</option>
                            <option value="assignment">Assignment</option>
                            <option value="mid_semester">Mid-Semester</option>
                            <option value="exam">Main Examination</option>
                            <option value="practical">Practical / Lab</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" id="eeSem" class="form-select">
                            <option value="">—</option>
                            <option value="1st">1st</option>
                            <option value="2nd">2nd</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Academic Session</label>
                        <input type="text" name="academic_session" id="eeSess" class="form-control" placeholder="2024/2025">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max Offences</label>
                        <input type="number" min="1" step="1" name="max_offences" id="eeMax" class="form-control" value="3">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Instructions (shown on exam instructions page)</label>
                        <textarea name="instructions" id="eeInstr" rows="3" class="form-control" placeholder="Exam rules / instructions for students"></textarea>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" name="require_face_auth" type="checkbox" role="switch" id="eeFace">
                            <label class="form-check-label fw-semibold" for="eeFace">Require Face Auth</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" name="require_fingerprint_auth" type="checkbox" role="switch" id="eeFp">
                            <label class="form-check-label fw-semibold" for="eeFp">Require Fingerprint Auth</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" name="require_fullscreen" type="checkbox" role="switch" id="eeFs">
                            <label class="form-check-label fw-semibold" for="eeFs">Enforce Fullscreen</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('editExamModal'); if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget; if (!btn) return;
        var data = {};
        try { data = JSON.parse(btn.getAttribute('data-exam') || '{}'); } catch (_) { data = {}; }
        document.getElementById('eeId').value = data.id || '';
        document.getElementById('eeTitle').textContent = data.title || '';
        document.getElementById('eeType').value = data.exam_type || 'standard';
        document.getElementById('eeSem').value = data.semester || '';
        document.getElementById('eeSess').value = data.academic_session || '';
        document.getElementById('eeMax').value = data.max_offences || 3;
        document.getElementById('eeInstr').value = data.instructions || '';
        document.getElementById('eeFace').checked = !!data.require_face_auth;
        document.getElementById('eeFp').checked = !!data.require_fingerprint_auth;
        document.getElementById('eeFs').checked = !!data.require_fullscreen;
    });
})();
</script>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
