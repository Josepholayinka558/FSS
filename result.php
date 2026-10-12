<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/csrf.php');

if (!isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$exam_id = $_GET['exam_id'] ?? 0;

if (!$exam_id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch exam details
$stmt = $pdo->prepare('
    SELECT e.*, s.name as subject_name 
    FROM exams e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE e.id = ?
');
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

// Fetch result details
$stmt = $pdo->prepare('SELECT * FROM results WHERE student_id = ? AND exam_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$student_id, $exam_id]);
$result = $stmt->fetch();

if (!$result) {
    header('Location: dashboard.php?error=result_not_found');
    exit;
}

// Result Visibility Logic
$show_result = false;
$held_message = false;

if ($result['result_display_status'] === 'approved') {
    $show_result = true;
} elseif ($result['result_display_status'] === 'held') {
    $held_message = "Your result has been placed on hold. Please contact your departmental coordinator or HOD.";
} else {
    // pending
    $held_message = "Your result is currently being processed. Please check back later or contact the exam officer.";
}

?>

<?php include('includes/header.php'); ?>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <img src="assets/kingsparkl.jpg" alt="FSS OYO Logo" style="height: 40px; border-radius: 5px;" class="me-2" onerror="this.src='https://via.placeholder.com/40?text=FSS'">
            FSS OYO CBT
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#studentNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="studentNav">
            <div class="navbar-nav ms-auto align-items-center">
                <span class="nav-link text-white me-3">Welcome, <?php echo htmlspecialchars($_SESSION['student_name']); ?></span>
                <a class="nav-link btn btn-outline-light btn-sm px-3 mt-2 mt-lg-0" href="logout.php">Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-success text-white py-3">
                    <h4 class="mb-0">Exam Result</h4>
                </div>
                <div class="card-body p-5 text-center">
                    <?php if ($show_result): ?>
                        <h2 class="mb-2"><?php echo htmlspecialchars($exam['title']); ?></h2>
                        <p class="text-muted mb-4"><?php echo htmlspecialchars($exam['subject_name']); ?></p>
                        
                        <div class="result-circle mx-auto my-4 d-flex flex-column justify-content-center align-items-center rounded-circle border border-5 border-success" style="width: 200px; height: 200px;">
                            <span class="display-3 fw-bold"><?php echo round($result['score']); ?>%</span>
                            <span class="text-muted fw-bold">SCORE</span>
                        </div>

                        <?php if ($result['status'] === 'submitted'): ?>
                            <div class="alert alert-info mt-4">
                                <strong>Note:</strong> Your exam includes theory questions and is currently being graded by your lecturer. The score above only reflects your MCQ performance.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="py-4">
                            <div class="mb-4">
                                <i class="bi bi-shield-lock text-warning" style="font-size: 4rem;"></i>
                            </div>
                            <h3 class="text-dark mb-3"><?php echo $result['result_display_status'] === 'held' ? 'Result Held' : 'Awaiting Approval'; ?></h3>
                            <div class="alert alert-warning py-3">
                                <p class="mb-0 fs-5"><?php echo $held_message; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-5">
                        <a href="dashboard.php" class="btn btn-primary btn-lg px-5">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('includes/footer.php'); ?>
