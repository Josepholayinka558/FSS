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

// Bulk Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $selected_ids = is_array($_POST['selected_ids'] ?? null) ? array_map('intval', $_POST['selected_ids']) : [];
    $action = $_POST['action'] ?? '';

    if (!empty($selected_ids)) {
        if ($action === 'delete') {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM question_bank WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                fss_audit('admin', 'question_bank_bulk_delete', 'question_bank', null, ['ids'=>$selected_ids, 'count'=>count($selected_ids)], $adminId);
                $message = count($selected_ids) . ' questions deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Error deleting questions: ' . $e->getMessage();
            }
        } elseif ($action === 'add_to_exam') {
            $_SESSION['selected_question_ids'] = $selected_ids;
            header('Location: create_exam.php?from_bank=1');
            exit;
        } elseif ($action === 'push_to_past') {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $stmt = $pdo->prepare("UPDATE question_bank SET is_past_question = 1 WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                fss_audit('admin', 'question_bank_pushed_past', 'question_bank', null, ['ids'=>$selected_ids, 'count'=>count($selected_ids)], $adminId);
                $message = count($selected_ids) . ' questions pushed to Past Questions successfully.';
            } catch (PDOException $e) {
                $error = 'Error pushing to past questions: ' . $e->getMessage();
            }
        }
    } else {
        $error = 'No questions selected.';
    }
    }
}

// Filters
$subject_filter = $_GET['subject'] ?? '';
$class_filter = $_GET['class'] ?? '';
$type_filter = $_GET['type'] ?? '';

$query = "SELECT * FROM question_bank WHERE 1=1";
$params = [];

if (!empty($subject_filter)) {
    $query .= " AND subject = ?";
    $params[] = $subject_filter;
}
if (!empty($class_filter)) {
    $query .= " AND class = ?";
    $params[] = $class_filter;
}
if (!empty($type_filter)) {
    $query .= " AND type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY id DESC";
$questions = [];
$subjects = [];
$classes = [];

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $questions = $stmt->fetchAll();

    // Get unique subjects and classes for filters
    $subjects = $pdo->query("SELECT DISTINCT subject FROM question_bank ORDER BY subject ASC")->fetchAll(PDO::FETCH_COLUMN);
    $classes = $pdo->query("SELECT DISTINCT class FROM question_bank ORDER BY class ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '1146') !== false) {
        $error = 'Question Bank table does not exist. Please check the Dashboard for instructions on fixing the "Orphaned Tablespace" issue.';
    } else {
        $error = 'Database error: ' . $e->getMessage();
    }
}

?>

<?php include('../includes/header.php'); ?>

<?php include('includes/navbar.php'); ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Question Bank</h2>
        <a href="upload_questions.php" class="btn btn-primary">Upload New Questions</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form action="question_bank.php" method="GET" class="row align-items-end">
                <div class="col-md-3 mb-2 mb-md-0">
                    <label for="subject" class="form-label">Course</label>
                    <select name="subject" id="subject" class="form-select">
                        <option value="">All Courses</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?php echo htmlspecialchars($sub); ?>" <?php echo $subject_filter === $sub ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2 mb-md-0">
                    <label for="class" class="form-label">Class</label>
                    <select name="class" id="class" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?php echo htmlspecialchars($cls); ?>" <?php echo $class_filter === $cls ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2 mb-md-0">
                    <label for="type" class="form-label">Type</label>
                    <select name="type" id="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="objective" <?php echo $type_filter === 'objective' ? 'selected' : ''; ?>>Objective</option>
                        <option value="theory" <?php echo $type_filter === 'theory' ? 'selected' : ''; ?>>Theory</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-secondary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Questions Table -->
    <form action="question_bank.php" method="POST" id="bulkForm">
        <?php fss_csrf_field(); ?>
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center">
                    <select name="action" class="form-select form-select-sm me-2" style="width: 200px;">
                        <option value="">Bulk Actions</option>
                        <option value="add_to_exam">Add to Exam</option>
                        <option value="push_to_past">Push to Past Questions</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" name="bulk_action" class="btn btn-sm btn-dark">Apply</button>
                </div>
                <div class="text-muted small">Total: <?php echo count($questions); ?> questions</div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
                                <th>Question</th>
                                <th>Type</th>
                                <th>Course/Level</th>
                                <th>Image</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $q): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_ids[]" value="<?php echo $q['id']; ?>" class="selectItem"></td>
                                    <td>
                                        <div class="fw-bold mb-1"><?php echo htmlspecialchars($q['question']); ?></div>
                                        <?php if ($q['type'] === 'objective'): ?>
                                            <div class="small text-muted">
                                                A: <?php echo htmlspecialchars($q['option_a']); ?> | 
                                                B: <?php echo htmlspecialchars($q['option_b']); ?> | 
                                                C: <?php echo htmlspecialchars($q['option_c']); ?> | 
                                                D: <?php echo htmlspecialchars($q['option_d']); ?>
                                            </div>
                                            <div class="small fw-bold text-success">Ans: <?php echo htmlspecialchars($q['correct_answer']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?php echo $q['type'] === 'objective' ? 'primary' : 'info'; ?>"><?php echo ucfirst($q['type']); ?></span></td>
                                    <td><?php echo htmlspecialchars($q['subject'] . ' / ' . $q['class']); ?></td>
                                    <td>
                                        <?php if ($q['image']): ?>
                                            <a href="../uploads/questions/<?php echo $q['image']; ?>" target="_blank">
                                                <img src="../uploads/questions/<?php echo $q['image']; ?>" style="height: 30px; width: 30px; object-fit: cover; border-radius: 4px;">
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo date('M d, Y', strtotime($q['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.selectItem');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
</script>

<?php include('../includes/footer.php'); ?>
