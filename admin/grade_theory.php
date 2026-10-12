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

$result_id = (int)($_GET['result_id'] ?? 0);
if (!$result_id) {
    header('Location: view_results.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT r.*, s.name as student_name, s.registration_number, s.matric_number, e.title as exam_title 
    FROM results r 
    JOIN students s ON r.student_id = s.id 
    JOIN exams e ON r.exam_id = e.id 
    WHERE r.id = ?
');
$stmt->execute([$result_id]);
$result = $stmt->fetch();

if (!$result) {
    header('Location: view_results.php');
    exit;
}

$exam_id = $result['exam_id'];
$student_answers = json_decode($result['answers'], true);

$stmt = $pdo->prepare('SELECT * FROM exam_questions WHERE exam_id = ?');
$stmt->execute([$exam_id]);
$mappings = $stmt->fetchAll();

$questions = [];
foreach ($mappings as $map) {
    try {
        if ($map['question_source'] === 'question_bank') {
            $q_stmt = $pdo->prepare('SELECT *, question as question_text, CASE WHEN type="objective" THEN "mcq" ELSE "theory" END as type FROM question_bank WHERE id = ?');
        } else {
            $q_stmt = $pdo->prepare('SELECT *, "" as image FROM questions WHERE id = ?');
        }
        $q_stmt->execute([$map['question_id']]);
        $q = $q_stmt->fetch();
        if ($q) {
            $questions[] = $q;
        }
    } catch (PDOException $e) {
        continue;
    }
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_score'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!isset($error) || !$error) {
        $new_score = $_POST['new_score'];
        $before = $result['score'];
        $stmt = $pdo->prepare('UPDATE results SET score = ?, status = "graded" WHERE id = ?');
        $stmt->execute([$new_score, $result_id]);
        fss_audit('admin', 'theory_score_updated', 'exam_attempt', $result_id, [
            'before_score' => $before,
            'after_score'  => $new_score,
        ], $adminId);
        $message = 'Score updated successfully';
        $result['score'] = $new_score;
        $result['status'] = 'graded';
    }
}

?>

<?php include('../includes/header.php'); ?>

<?php include('includes/navbar.php'); ?>

<div class="container mt-4 mb-5 pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Grade Exam: <?php echo htmlspecialchars($result['exam_title']); ?></h2>
        <a href="view_results.php" class="btn btn-outline-secondary">Back to Results</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Student Info</h5>
                </div>
                <div class="card-body">
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($result['student_name']); ?></p>
                    <p><strong>Matric No:</strong> <span class="fw-bold"><?php echo htmlspecialchars($result['matric_number']); ?></span></p>
                    <p><strong>Reg No:</strong> <?php echo htmlspecialchars($result['registration_number']); ?></p>
                    <hr>
                    <form action="grade_theory.php?result_id=<?php echo $result_id; ?>" method="POST">
                        <?php fss_csrf_field(); ?>
                        <div class="mb-3">
                            <label for="new_score" class="form-label">Final Score (%)</label>
                            <input type="number" step="0.01" name="new_score" id="new_score" class="form-control" value="<?php echo $result['score']; ?>" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="update_score" class="btn btn-primary">Update Final Score</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Answers Review</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($questions as $index => $q): 
                        $answer = $student_answers[$q['id']] ?? 'No answer provided';
                        $is_correct = ($q['type'] === 'mcq' && $answer === $q['correct_answer']);
                    ?>
                        <div class="mb-5 border-bottom pb-4">
                            <h6 class="text-primary">Question <?php echo $index + 1; ?> (<?php echo strtoupper($q['type']); ?>)</h6>
                            <p class="fs-5 fw-bold mb-3"><?php echo htmlspecialchars($q['question_text']); ?></p>
                            
                            <?php if ($q['type'] === 'mcq'): ?>
                                <div class="row g-2 mb-3">
                                    <div class="col-6"><span class="<?php echo $q['correct_answer'] === 'A' ? 'text-success fw-bold' : ''; ?>">A. <?php echo htmlspecialchars($q['option_a']); ?></span></div>
                                    <div class="col-6"><span class="<?php echo $q['correct_answer'] === 'B' ? 'text-success fw-bold' : ''; ?>">B. <?php echo htmlspecialchars($q['option_b']); ?></span></div>
                                    <div class="col-6"><span class="<?php echo $q['correct_answer'] === 'C' ? 'text-success fw-bold' : ''; ?>">C. <?php echo htmlspecialchars($q['option_c']); ?></span></div>
                                    <div class="col-6"><span class="<?php echo $q['correct_answer'] === 'D' ? 'text-success fw-bold' : ''; ?>">D. <?php echo htmlspecialchars($q['option_d']); ?></span></div>
                                </div>
                                <div class="alert alert-<?php echo $is_correct ? 'success' : 'danger'; ?> py-2">
                                    <strong>Student Answer:</strong> <?php echo htmlspecialchars($answer); ?> 
                                    <?php if ($is_correct): ?>
                                        <i class="bi bi-check-circle-fill"></i> (Correct)
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill"></i> (Incorrect - Correct was <?php echo $q['correct_answer']; ?>)
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="p-3 bg-light border rounded mb-2">
                                    <strong>Student Answer:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($answer)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>
