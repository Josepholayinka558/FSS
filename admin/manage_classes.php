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

// Add Class
if (isset($_POST['add_class'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $name = trim($_POST['class_name']);
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare('INSERT INTO classes (name) VALUES (?)');
            $stmt->execute([$name]);
            $cid = (int)$pdo->lastInsertId();
            fss_audit('admin', 'class_added', 'class', $cid, ['name'=>$name], $adminId);
            $message = 'Class added successfully';
        } catch (PDOException $e) {
            $error = 'Error: Class may already exist.';
        }
    } else {
        $error = 'Class name is required';
    }
    }
}

// Delete Class (POST only, CSRF protected — no GET delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_class'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $id = (int)($_POST['class_id'] ?? 0);
    if ($id > 0) {
        try {
            $before = $pdo->prepare('SELECT name FROM classes WHERE id = ?');
            $before->execute([$id]);
            $beforeRow = $before->fetch();
            $stmt = $pdo->prepare('DELETE FROM classes WHERE id = ?');
            $stmt->execute([$id]);
            if ($beforeRow) {
                fss_audit('admin', 'class_deleted', 'class', $id, ['name'=>$beforeRow['name']], $adminId);
            }
            $message = 'Class deleted successfully';
        } catch (PDOException $e) {
            $error = 'Error deleting class. It might be linked to students or exams.';
        }
    }
    }
}

$classes = $pdo->query('SELECT * FROM classes ORDER BY name ASC')->fetchAll();
?>

<?php include('../includes/header.php'); ?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
          <img src="https://fss-oyo.edu.ng/images/FSS_LOGO.png" alt="FSS OYO Logo" style="height: 42px; border-radius: var(--radius-md); object-fit: contain;" class="me-3" onerror="this.style.display='none'">
             FSS OYO CBT Admin
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link active" href="manage_classes.php">Classes</a></li>
                <li class="nav-item"><a class="nav-link" href="manage_subjects.php">Courses</a></li>
                <li class="nav-item"><a class="nav-link" href="question_bank.php">Question Bank</a></li>
                <li class="nav-item"><a class="nav-link" href="upload_questions.php">Upload</a></li>
                <li class="nav-item"><a class="nav-link" href="manage_exams.php">Exams</a></li>
                <li class="nav-item"><a class="nav-link" href="view_results.php">Results</a></li>
            </ul>
            <div class="navbar-nav align-items-center">
                <a class="nav-link me-3" href="profile.php">
                    <i class="bi bi-person-circle"></i> Profile
                </a>
                <a class="nav-link btn btn-outline-danger btn-sm text-white mt-2 mt-lg-0" href="logout.php">Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Add New Class</h5>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form action="manage_classes.php" method="POST">
                        <?php fss_csrf_field(); ?>
                        <div class="mb-3">
                            <label for="class_name" class="form-label">Class Name</label>
                            <input type="text" name="class_name" id="class_name" class="form-control" placeholder="e.g. ND1, ND2, HND1, HND2" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="add_class" class="btn btn-primary">Add Class</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Class List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Class Name</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td><?php echo $class['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($class['name']); ?></strong></td>
                                        <td>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('Are you sure you want to delete this class? It might be linked to students or exams.');">
                                                <?php fss_csrf_field(); ?>
                                                <input type="hidden" name="class_id" value="<?php echo (int)$class['id']; ?>">
                                                <button type="submit" name="delete_class"
                                                        class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash me-1"></i> Delete
                                                </button>
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
    </div>
</div>

<?php include('../includes/footer.php'); ?>
