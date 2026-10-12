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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_exam'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }

    if (!$error) {
        $title       = trim($_POST['title'] ?? '');
        $class_id    = (int)($_POST['class_id'] ?? 0);
        $subject_id  = (int)($_POST['subject_id'] ?? 0);
        $duration    = (int)($_POST['duration'] ?? 0);
        $departments = isset($_POST['departments']) && is_array($_POST['departments']) ? implode(',', $_POST['departments']) : '';
        $selected_questions = $_POST['questions'] ?? [];
        $question_sources   = $_POST['question_sources'] ?? [];

        $max_offences  = (int)($_POST['max_offences'] ?? 3);
        if ($max_offences < 1) $max_offences = 3;
        $exam_type      = in_array($_POST['exam_type'] ?? 'standard', ['ca','mid_semester','exam','quiz','assignment','practical','standard']) ? $_POST['exam_type'] : 'standard';
        $semester       = in_array($_POST['semester'] ?? '', ['1st','2nd']) ? $_POST['semester'] : null;
        $academic_session = trim($_POST['academic_session'] ?? '');
        $require_face_auth   = !empty($_POST['require_face_auth']) ? 1 : 0;
        $require_fingerprint_auth = !empty($_POST['require_fingerprint_auth']) ? 1 : 0;
        $require_fullscreen = !empty($_POST['require_fullscreen']) ? 1 : 0;
        $instructions   = trim($_POST['instructions'] ?? '');

        if (!empty($title) && $class_id > 0 && $subject_id > 0 && $duration > 0 && !empty($departments) && !empty($selected_questions)) {
            try {
                $pdo->beginTransaction();

                $cols = 'title, class_id, subject_id, duration, departments, max_offences, exam_type, require_face_auth, require_fingerprint_auth, require_fullscreen';
                $ph   = '?,?,?,?,?,?,?,?,?,?';
                $vals = [$title, $class_id, $subject_id, $duration, $departments, $max_offences, $exam_type, $require_face_auth, $require_fingerprint_auth, $require_fullscreen];

                if ($semester) { $cols .= ', semester'; $ph .= ',?'; $vals[] = $semester; }
                if ($academic_session !== '') { $cols .= ', academic_session'; $ph .= ',?'; $vals[] = $academic_session; }
                if ($instructions !== '') { $cols .= ', instructions'; $ph .= ',?'; $vals[] = $instructions; }

                $stmt = $pdo->prepare("INSERT INTO exams ($cols) VALUES ($ph)");
                $stmt->execute($vals);
                $exam_id = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO exam_questions (exam_id, question_id, question_source) VALUES (?, ?, ?)');
                foreach ($selected_questions as $q_id) {
                    $q_id = (int)$q_id;
                    if ($q_id < 1) continue;
                    $source = $question_sources[$q_id] ?? 'questions';
                    if (!in_array($source, ['questions','question_bank'], true)) $source = 'questions';
                    $stmt->execute([$exam_id, $q_id, $source]);
                }

                $pdo->commit();
                fss_audit('admin', 'admin_create_exam', 'exam', $exam_id, [
                    'title' => $title, 'max_offences' => $max_offences, 'exam_type' => $exam_type,
                    'require_face_auth' => $require_face_auth, 'require_fingerprint_auth' => $require_fingerprint_auth,
                ], $adminId);
                $message = 'Exam created successfully. <a href="manage_exams.php" class="alert-link">Manage exams &rarr;</a>';
                unset($_SESSION['selected_question_ids']);
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Error creating exam: ' . $e->getMessage();
            }
        } else {
            $error = 'All fields including at least one department and one question are required.';
        }
    }
}

$from_bank = isset($_GET['from_bank']) && isset($_SESSION['selected_question_ids']);
$bank_questions = [];
if ($from_bank) {
    try {
        $placeholders = implode(',', array_fill(0, count($_SESSION['selected_question_ids']), '?'));
        $stmt = $pdo->prepare("SELECT * FROM question_bank WHERE id IN ($placeholders)");
        $stmt->execute($_SESSION['selected_question_ids']);
        $bank_questions = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Question Bank table error: ' . $e->getMessage();
        $from_bank = false;
    }
}

$classes = $pdo->query('SELECT * FROM classes ORDER BY name ASC')->fetchAll();
$subjects = $pdo->query('SELECT * FROM subjects ORDER BY course_code ASC')->fetchAll();

include(__DIR__ . '/../includes/header.php');
include(__DIR__ . '/includes/navbar.php');
?>
<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="mb-0">Create New Exam</h2>
        <a href="manage_exams.php" class="btn btn-outline-secondary btn-sm">&larr; Manage Exams</a>
    </div>
    <hr>

    <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form action="create_exam.php" method="POST" id="examForm">
        <?php echo fss_csrf_field(); ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-file-lines me-2"></i>Exam Metadata</h5></div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="title" class="form-label">Exam Title</label>
                        <input type="text" name="title" id="title" class="form-control" placeholder="e.g. CSC 101 Mid-Semester Examination" required>
                    </div>
                    <div class="col-md-4">
                        <label for="duration" class="form-label">Duration (minutes)</label>
                        <input type="number" name="duration" id="duration" min="1" step="1" class="form-control" placeholder="e.g. 60" required>
                    </div>
                    <div class="col-md-3">
                        <label for="class_id" class="form-label">Target Class (Level)</label>
                        <select name="class_id" id="class_id" class="form-select" required onchange="loadQuestions()">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo (int)$class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['programme'] ?? ''); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="subject_id" class="form-label">Course</label>
                        <select name="subject_id" id="subject_id" class="form-select" required onchange="loadQuestions()">
                            <option value="">Select Course</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo (int)$subject['id']; ?>"><?php echo htmlspecialchars($subject['course_code'] . ' — ' . $subject['name']); ?></option>
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
                    <div class="col-md-3">
                        <label class="form-label">Examination Type</label>
                        <select name="exam_type" class="form-select">
                            <option value="standard" selected>Standard / General</option>
                            <option value="ca">Continuous Assessment (CA)</option>
                            <option value="quiz">Quiz</option>
                            <option value="assignment">Assignment</option>
                            <option value="mid_semester">Mid-Semester</option>
                            <option value="exam">Main Examination</option>
                            <option value="practical">Practical / Lab</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="max_offences">Max Offences Before DQ</label>
                        <input type="number" min="1" step="1" value="3" id="max_offences" name="max_offences" class="form-control">
                        <div class="form-text">Reaching this count auto-submits and disqualifies the student.</div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label d-block">Target Departments</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input dept-check" type="checkbox" name="departments[]" id="dept_cs" value="Computer Science" checked onchange="loadQuestions()">
                            <label class="form-check-label" for="dept_cs">Computer Science</label>
                        </div>
                        <small class="text-muted d-block mt-1">Select the department(s) that should take this exam.</small>
                    </div>
                </div>

                <hr class="my-4">

                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Exam Instructions (shown to the student on the instructions page)</label>
                        <textarea name="instructions" rows="3" class="form-control" placeholder="Example: Attempt all questions. Use of mobile phones is prohibited. Each question carries equal marks unless otherwise stated."></textarea>
                    </div>
                </div>

                <hr class="my-4">

                <h6><i class="fas fa-shield-halved me-2 text-primary"></i>Security &amp; Authentication Requirements</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="require_face_auth" name="require_face_auth">
                            <label class="form-check-label fw-semibold" for="require_face_auth">Require Face Verification</label>
                            <div class="form-text">Stage-2 biometric: face must match registered descriptor before exam starts; continuous monitoring during exam.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="require_fingerprint_auth" name="require_fingerprint_auth">
                            <label class="form-check-label fw-semibold" for="require_fingerprint_auth">Require Fingerprint Verification</label>
                            <div class="form-text">Stage-2 biometric: fingerprint scan required before exam starts (via hardware SDK).</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="require_fullscreen" name="require_fullscreen" checked>
                            <label class="form-check-label fw-semibold" for="require_fullscreen">Enforce Fullscreen</label>
                            <div class="form-text">Exam enters browser fullscreen on start. Exiting fullscreen counts as an offence.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Select Questions</h5>
            </div>
            <div class="card-body p-4">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="selectAllQuestions">
                    <label class="form-check-label fw-semibold" for="selectAllQuestions">
                        Select all questions at once
                    </label>
                </div>
                <?php if ($from_bank): ?>
                    <p class="text-success fw-bold mb-3"><i class="fas fa-check-circle-fill me-1"></i> <?php echo count($bank_questions); ?> questions selected from Question Bank.</p>
                    <div id="questionsContainer" class="list-group">
                        <?php foreach ($bank_questions as $q): ?>
                            <div class="list-group-item d-flex align-items-center">
                                <input class="form-check-input me-3" type="checkbox" name="questions[]" value="<?php echo (int)$q['id']; ?>" id="q<?php echo (int)$q['id']; ?>" checked>
                                <label class="form-check-label w-100" for="q<?php echo (int)$q['id']; ?>">
                                    <strong>[<?php echo strtoupper($q['type']); ?>]</strong> <?php echo htmlspecialchars(substr($q['question'], 0, 150)); ?><?php echo strlen($q['question']) > 150 ? '…' : ''; ?>
                                    <input type="hidden" name="question_sources[<?php echo (int)$q['id']; ?>]" value="question_bank">
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-3">Questions will appear after you select a course.</p>
                    <div id="questionsContainer" class="list-group"></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" name="create_exam" class="btn btn-primary btn-lg px-5"><i class="fas fa-save me-1"></i>Create Exam</button>
            <a href="manage_exams.php" class="btn btn-outline-secondary btn-lg px-5">Cancel</a>
        </div>
    </form>
</div>

<script>
function updateSelectAllState() {
    const selectAll = document.getElementById('selectAllQuestions');
    const questionChecks = Array.from(document.querySelectorAll('#questionsContainer input[name="questions[]"]'));

    if (questionChecks.length === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
        return;
    }

    const checkedCount = questionChecks.filter(cb => cb.checked).length;
    selectAll.checked = checkedCount === questionChecks.length;
    selectAll.indeterminate = checkedCount > 0 && checkedCount < questionChecks.length;
}

function bindQuestionCheckboxEvents() {
    const questionChecks = document.querySelectorAll('#questionsContainer input[name="questions[]"]');
    questionChecks.forEach(cb => { cb.addEventListener('change', updateSelectAllState); });
    updateSelectAllState();
}

document.getElementById('selectAllQuestions').addEventListener('change', function () {
    const questionChecks = document.querySelectorAll('#questionsContainer input[name="questions[]"]');
    questionChecks.forEach(cb => { cb.checked = this.checked; });
    this.indeterminate = false;
});

async function loadQuestions() {
    const subjectId = document.getElementById('subject_id').value;
    const classId = document.getElementById('class_id').value;
    const depts = Array.from(document.querySelectorAll('.dept-check:checked')).map(cb => cb.value).join(',');
    const container = document.getElementById('questionsContainer');

    if (!subjectId || !classId) {
        container.innerHTML = '<p class="text-muted p-3 mb-0">Select Class and Course to see questions.</p>';
        updateSelectAllState();
        return;
    }

    container.innerHTML = '<p class="text-center p-3 mb-0">Loading questions…</p>';

    try {
        const response = await fetch(`get_questions.php?subject_id=${subjectId}&class_id=${classId}&departments=${encodeURIComponent(depts)}`);
        const data = await response.json();

        if (!Array.isArray(data) || data.length === 0) {
            container.innerHTML = '<p class="text-danger p-3 mb-0">No questions found for the selected criteria. Add questions via Questions / Question Bank first.</p>';
            updateSelectAllState();
            return;
        }

        let html = '';
        const now = new Date();
        data.forEach(q => {
            const createdDate = q.created_at ? new Date(q.created_at) : null;
            const isNew = createdDate && ((now - createdDate) < (1000 * 60 * 60 * 24));
            const qid = parseInt(q.id, 10);
            const qtext = (q.question_text || q.question || '').toString();
            const qtype = (q.type || 'objective').toString().toUpperCase();
            const source = q.source || 'questions';
            html += `
                <div class="list-group-item d-flex align-items-center">
                    <input class="form-check-input me-3" type="checkbox" name="questions[]" value="${qid}" id="q${qid}">
                    <label class="form-check-label w-100" for="q${qid}">
                        <div class="d-flex justify-content-between align-items-start">
                            <span>
                                <span class="badge bg-light text-dark border me-2">${qtype}</span>
                                ${qtext.substring(0, 200)}${qtext.length > 200 ? '…' : ''}
                             </span>
                             ${isNew ? '<span class="badge bg-success ms-2 flex-shrink-0">New</span>' : ''}
                         </div>
                         <input type="hidden" name="question_sources[${qid}]" value="${source}">
                     </label>
                 </div>`;
        });
        container.innerHTML = html;
        bindQuestionCheckboxEvents();
    } catch (err) {
        console.error('Fetch error:', err);
        container.innerHTML = `<p class="text-danger p-3 mb-0">Error loading questions: ${err.message}</p>`;
        updateSelectAllState();
    }
}

bindQuestionCheckboxEvents();
</script>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
