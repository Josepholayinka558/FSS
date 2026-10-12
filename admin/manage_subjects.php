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

try {
    $col = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'programme'")->fetchColumn();
    if (!$col) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN programme ENUM('ND', 'HND', 'All') NOT NULL DEFAULT 'All' AFTER course_title");
    }
} catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course'])) {
    fss_verify_csrf();
    $course_code = trim($_POST['course_code']);
    $name = trim($_POST['course_name']);
    $course_title = trim($_POST['course_title']);
    $programme = isset($_POST['programme']) && in_array($_POST['programme'], ['ND','HND','All']) ? $_POST['programme'] : 'All';
    if (!empty($course_code) && !empty($name) && !empty($course_title)) {
        try {
            $stmt = $pdo->prepare('INSERT INTO subjects (course_code, name, course_title, programme) VALUES (?, ?, ?, ?)');
            $stmt->execute([$course_code, $name, $course_title, $programme]);
            $subject_id = (int)$pdo->lastInsertId();
            fss_audit('admin', 'subject_added', 'subject', $subject_id, [
                'course_code' => $course_code,
                'name' => $name,
                'course_title' => $course_title,
                'programme' => $programme
            ], $admin_id);
            $message = 'Course added successfully';
        } catch (PDOException $e) {
            $error = 'Error: Course code may already exist.';
        }
    } else {
        $error = 'All fields are required';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_course'])) {
    fss_verify_csrf();
    $id = (int)($_POST['subject_id'] ?? 0);
    if ($id > 0) {
        try {
            $row = $pdo->prepare('SELECT id, course_code, name, course_title, programme FROM subjects WHERE id = ?');
            $row->execute([$id]);
            $subject = $row->fetch();
            $stmt = $pdo->prepare('DELETE FROM subjects WHERE id = ?');
            $stmt->execute([$id]);
            if ($subject) {
                fss_audit('admin', 'subject_deleted', 'subject', $id, [
                    'course_code' => $subject['course_code'],
                    'name' => $subject['name'],
                    'course_title' => $subject['course_title'],
                    'programme' => $subject['programme']
                ], $admin_id);
            }
            $message = 'Course deleted successfully';
        } catch (PDOException $e) {
            $error = 'Error deleting course. It might be linked to questions or exams.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_course'])) {
    fss_verify_csrf();
    $id = (int)($_POST['subject_id'] ?? 0);
    $course_code = trim($_POST['course_code'] ?? '');
    $name = trim($_POST['course_name'] ?? '');
    $course_title = trim($_POST['course_title'] ?? '');
    $programme = isset($_POST['programme']) && in_array($_POST['programme'], ['ND','HND','All']) ? $_POST['programme'] : 'All';
    if ($id > 0 && !empty($course_code) && !empty($name) && !empty($course_title)) {
        try {
            $before = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
            $before->execute([$id]);
            $before_row = $before->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare('UPDATE subjects SET course_code = ?, name = ?, course_title = ?, programme = ? WHERE id = ?');
            $stmt->execute([$course_code, $name, $course_title, $programme, $id]);
            fss_audit('admin', 'subject_edited', 'subject', $id, [
                'before' => $before_row,
                'after' => [
                    'course_code' => $course_code,
                    'name' => $name,
                    'course_title' => $course_title,
                    'programme' => $programme
                ]
            ], $admin_id);
            $message = 'Course updated successfully';
        } catch (PDOException $e) {
            $error = 'Error updating course: ' . $e->getMessage();
        }
    } else {
        $error = 'All fields are required';
    }
}

$filter_programme = $_GET['filter_programme'] ?? '';
$sql = 'SELECT * FROM subjects';
$params = [];
if (in_array($filter_programme, ['ND','HND','All'])) {
    $sql .= ' WHERE programme = ?';
    $params[] = $filter_programme;
}
$sql .= ' ORDER BY programme ASC, course_code ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subjects = $stmt->fetchAll();

$edit_subject = null;
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $es = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
    $es->execute([$eid]);
    $edit_subject = $es->fetch();
}
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
                <li class="nav-item"><a class="nav-link" href="manage_classes.php">Classes</a></li>
                <li class="nav-item"><a class="nav-link active" href="manage_subjects.php">Courses</a></li>
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
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo $edit_subject ? 'Edit Course' : 'Add New Course'; ?></h5>
                    <?php if ($edit_subject): ?>
                        <a href="manage_subjects.php" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form action="manage_subjects.php" method="POST">
                        <?php fss_csrf_field(); ?>
                        <?php if ($edit_subject): ?>
                            <input type="hidden" name="subject_id" value="<?php echo (int)$edit_subject['id']; ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="course_code" class="form-label">Course Code</label>
                            <input type="text" name="course_code" id="course_code" class="form-control"
                                   placeholder="e.g. COM 111 / SWD 411" required
                                   value="<?php echo htmlspecialchars($edit_subject['course_code'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="course_name" class="form-label">Course Name (Short)</label>
                            <input type="text" name="course_name" id="course_name" class="form-control"
                                   placeholder="e.g. Intro to Computing" required
                                   value="<?php echo htmlspecialchars($edit_subject['name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="course_title" class="form-label">Full Course Title</label>
                            <input type="text" name="course_title" id="course_title" class="form-control"
                                   placeholder="e.g. Introduction to Computer Science" required
                                   value="<?php echo htmlspecialchars($edit_subject['course_title'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="programme" class="form-label">Programme</label>
                            <select name="programme" id="programme" class="form-select" required>
                                <option value="All" <?php echo (($edit_subject['programme'] ?? 'All') === 'All') ? 'selected' : ''; ?>>All (ND &amp; HND)</option>
                                <option value="ND" <?php echo (($edit_subject['programme'] ?? '') === 'ND') ? 'selected' : ''; ?>>ND (National Diploma)</option>
                                <option value="HND" <?php echo (($edit_subject['programme'] ?? '') === 'HND') ? 'selected' : ''; ?>>HND (Higher National Diploma)</option>
                            </select>
                            <div class="form-text" style="font-size: 0.78rem;">ND = COM 1xx/2xx style. HND = CSC/SWD 3xx/4xx style.</div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="<?php echo $edit_subject ? 'edit_course' : 'add_course'; ?>"
                                    class="btn btn-<?php echo $edit_subject ? 'warning' : 'primary'; ?>">
                                <i class="fas fa-<?php echo $edit_subject ? 'pen' : 'plus-circle'; ?> me-2"></i>
                                <?php echo $edit_subject ? 'Update Course' : 'Add Course'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <h5 class="mb-0">Course List <span class="badge bg-secondary ms-2"><?php echo count($subjects); ?></span></h5>
                    <div class="d-flex gap-2">
                        <form method="GET" class="d-flex gap-2 align-items-center">
                            <label class="form-label mb-0 small text-muted">Filter Programme:</label>
                            <select name="filter_programme" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">All</option>
                                <option value="ND" <?php echo $filter_programme === 'ND' ? 'selected' : ''; ?>>ND only</option>
                                <option value="HND" <?php echo $filter_programme === 'HND' ? 'selected' : ''; ?>>HND only</option>
                                <option value="All" <?php echo $filter_programme === 'All' ? 'selected' : ''; ?>>Programme=All</option>
                            </select>
                            <?php if ($filter_programme): ?>
                                <a href="manage_subjects.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Code</th>
                                    <th>Course Name</th>
                                    <th>Full Title</th>
                                    <th>Programme</th>
                                    <th style="width: 170px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($subjects)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5">
                                            <i class="fas fa-inbox me-2"></i> No courses found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($subjects as $subject): ?>
                                    <tr <?php echo $edit_subject && (int)$edit_subject['id'] === (int)$subject['id'] ? 'class="table-warning"' : ''; ?>>
                                        <td class="small text-muted"><?php echo (int)$subject['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($subject['course_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($subject['name']); ?></td>
                                        <td class="small"><?php echo htmlspecialchars($subject['course_title']); ?></td>
                                        <td>
                                            <?php
                                                $p = $subject['programme'] ?? 'All';
                                                if ($p === 'ND') {
                                                    echo '<span class="badge bg-primary">ND</span>';
                                                } elseif ($p === 'HND') {
                                                    echo '<span class="badge bg-info text-dark">HND</span>';
                                                } else {
                                                    echo '<span class="badge bg-secondary">All</span>';
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="manage_subjects.php?edit=<?php echo (int)$subject['id']; ?>"
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="Edit"><i class="fas fa-pen"></i></a>
                                                <form method="POST" class="d-inline"
                                                      onsubmit="return confirm('Delete course &quot;<?php echo htmlspecialchars($subject['course_code']); ?>&quot;? It may unlink from questions/exams.');">
                                                    <?php fss_csrf_field(); ?>
                                                    <input type="hidden" name="subject_id" value="<?php echo (int)$subject['id']; ?>">
                                                    <button type="submit" name="delete_course"
                                                            class="btn btn-sm btn-danger" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
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
