<?php
require_once('../includes/security_bootstrap.php');
require_once('../db.php');
require_once('../includes/csrf.php');
require_once('../includes/audit_log.php');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    fss_verify_csrf();
    $id = (int)($_POST['question_id'] ?? 0);
    if ($id > 0) {
        try {
            $before = $pdo->prepare('SELECT q.id, q.type, q.correct_answer, s.course_code, s.name as subject_name FROM questions q LEFT JOIN subjects s ON q.subject_id = s.id WHERE q.id = ?');
            $before->execute([$id]);
            $before_row = $before->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare('DELETE FROM questions WHERE id = ?');
            $stmt->execute([$id]);
            fss_audit('admin', 'question_deleted', 'question', $id, [
                'snapshot' => $before_row
            ], $admin_id);
            $message = 'Question deleted successfully';
        } catch (PDOException $e) {
            $error = 'Error deleting question. It might be linked to an exam.';
        }
    }
}

$questions = $pdo->query('SELECT q.*, s.course_code, s.name as subject_name FROM questions q JOIN subjects s ON q.subject_id = s.id ORDER BY q.id DESC')->fetchAll();
?>

<?php include('../includes/header.php'); ?>

<?php include('includes/navbar.php'); ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Manage Questions</h2>
        <a href="add_question.php" class="btn btn-primary">Add New Question</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course</th>
                            <th>Type</th>
                            <th>Question</th>
                            <th>Answer</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($questions as $q): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($q['course_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($q['subject_name']); ?></td>
                                <td><span class="badge bg-<?php echo $q['type'] === 'mcq' ? 'info' : 'warning'; ?> text-dark"><?php echo strtoupper($q['type']); ?></span></td>
                                <td><?php echo htmlspecialchars(substr($q['question_text'], 0, 100)) . (strlen($q['question_text']) > 100 ? '...' : ''); ?></td>
                                <td><?php echo $q['type'] === 'mcq' ? 'Option ' . $q['correct_answer'] : 'Manual Grading'; ?></td>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this question?');">
                                        <?php fss_csrf_field(); ?>
                                        <input type="hidden" name="question_id" value="<?php echo (int)$q['id']; ?>">
                                        <button type="submit" name="delete_question" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>
