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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $type = in_array($_POST['type'] ?? '', ['mcq','theory'], true) ? $_POST['type'] : 'mcq';
    $question_text = trim($_POST['question_text'] ?? '');

    if ($type === 'mcq') {
        $option_a = trim($_POST['option_a'] ?? '');
        $option_b = trim($_POST['option_b'] ?? '');
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = trim($_POST['correct_answer'] ?? '');
        
        $stmt = $pdo->prepare('INSERT INTO questions (subject_id, type, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$subject_id, $type, $question_text, $option_a, $option_b, $option_c, $option_d, $correct_answer]);
        $qid = (int)$pdo->lastInsertId();
        fss_audit('admin', 'question_added', 'question', $qid, ['subject_id'=>$subject_id, 'type'=>$type], $adminId);
    } else {
        $stmt = $pdo->prepare('INSERT INTO questions (subject_id, type, question_text) VALUES (?, ?, ?)');
        $stmt->execute([$subject_id, $type, $question_text]);
        $qid = (int)$pdo->lastInsertId();
        fss_audit('admin', 'question_added', 'question', $qid, ['subject_id'=>$subject_id, 'type'=>$type], $adminId);
    }
    $message = 'Question added successfully';
    }
}

$subjects = $pdo->query('SELECT * FROM subjects ORDER BY course_code ASC')->fetchAll();
?>

<?php include('../includes/header.php'); ?>

<?php include('includes/navbar.php'); ?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Add New Question</h5>
            <a href="manage_questions.php" class="btn btn-sm btn-outline-light">View All Questions</a>
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>

            <form action="add_question.php" method="POST" id="questionForm">
                <?php fss_csrf_field(); ?>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="subject_id" class="form-label">Course</label>
                        <select name="subject_id" id="subject_id" class="form-select" required>
                            <option value="">Select Course</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['course_code'] . ' - ' . $subject['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="type" class="form-label">Question Type</label>
                        <select name="type" id="type" class="form-select" required onchange="toggleMCQOptions(this.value)">
                            <option value="mcq">Multiple Choice (MCQ)</option>
                            <option value="theory">Theory</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="question_text" class="form-label">Question Text</label>
                    <textarea name="question_text" id="question_text" class="form-control" rows="3" required></textarea>
                </div>

                <div id="mcqOptions">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="option_a" class="form-label">Option A</label>
                            <input type="text" name="option_a" id="option_a" class="form-control mcq-input">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="option_b" class="form-label">Option B</label>
                            <input type="text" name="option_b" id="option_b" class="form-control mcq-input">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="option_c" class="form-label">Option C</label>
                            <input type="text" name="option_c" id="option_c" class="form-control mcq-input">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="option_d" class="form-label">Option D</label>
                            <input type="text" name="option_d" id="option_d" class="form-control mcq-input">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="correct_answer" class="form-label">Correct Answer</label>
                        <select name="correct_answer" id="correct_answer" class="form-select mcq-input">
                            <option value="A">Option A</option>
                            <option value="B">Option B</option>
                            <option value="C">Option C</option>
                            <option value="D">Option D</option>
                        </select>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" name="add_question" class="btn btn-primary">Save Question</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleMCQOptions(type) {
    const mcqDiv = document.getElementById('mcqOptions');
    const inputs = mcqDiv.querySelectorAll('.mcq-input');
    if (type === 'theory') {
        mcqDiv.style.display = 'none';
        inputs.forEach(input => input.required = false);
    } else {
        mcqDiv.style.display = 'block';
        inputs.forEach(input => input.required = true);
    }
}
</script>

<?php include('../includes/footer.php'); ?>
