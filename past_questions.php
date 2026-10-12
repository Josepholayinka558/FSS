<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/csrf.php');

if (!isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$class_id = $_SESSION['class_id'];
$department = $_SESSION['department'];
$student_name = $_SESSION['student_name'];
$matric_number = $_SESSION['matric_number'] ?? 'N/A';

$subjects = $pdo->query("SELECT DISTINCT subject FROM question_bank WHERE is_past_question = 1 ORDER BY subject ASC")->fetchAll(PDO::FETCH_COLUMN);
$classes = $pdo->query("SELECT DISTINCT class FROM question_bank WHERE is_past_question = 1 ORDER BY class ASC")->fetchAll(PDO::FETCH_COLUMN);

$subject_filter = $_GET['subject'] ?? '';
$class_filter = $_GET['class'] ?? '';

$query = "SELECT * FROM question_bank WHERE is_past_question = 1";
$params = [];

if (!empty($subject_filter)) {
    $query .= " AND subject = ?";
    $params[] = $subject_filter;
}
if (!empty($class_filter)) {
    $query .= " AND class = ?";
    $params[] = $class_filter;
}

$query .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$past_questions = $stmt->fetchAll();

include('includes/header.php');
?>

<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="https://fss-oyo.edu.ng/images/FSS_LOGO.png" alt="FSS Logo" style="height: 38px; object-fit: contain;" onerror="this.style.display='none'">
        <div>
            <h5>FSS OYO</h5>
            <span>CBT Portal</span>
        </div>
    </div>

    <div class="sidebar-section-title">Main</div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link">
            <i class="fas fa-layout-grid"></i> Dashboard
        </a>
        <a href="dashboard.php#exams" class="sidebar-link">
            <i class="fas fa-clipboard-list"></i> Examinations
        </a>
        <a href="past_questions.php" class="sidebar-link active">
            <i class="fas fa-book-open"></i> Past Questions
        </a>
    </nav>

    <div class="sidebar-section-title">Account</div>
    <nav class="sidebar-nav">
        <a href="dashboard.php#profile" class="sidebar-link">
            <i class="fas fa-user-circle"></i> My Profile
        </a>
        <a href="logout.php" class="sidebar-link" style="color: #dc2626;">
            <i class="fas fa-right-from-bracket"></i> Logout
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="d-flex align-items-center mb-3">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--color-bg-tertiary); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--color-text);">
                <?php echo strtoupper(substr($student_name, 0, 1)); ?>
            </div>
            <div class="ms-3" style="min-width: 0;">
                <div style="font-weight: 600; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($student_name); ?></div>
                <div style="font-size: 0.75rem; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($matric_number); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="backdrop-overlay" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<div class="main-content">
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-primary btn-sm mobile-nav-toggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="greeting-text">
                <h5 class="mb-0">Past Questions Library</h5>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-outline-primary btn-sm d-none d-sm-inline-flex">
                <i class="fas fa-arrow-left me-2"></i> Dashboard
            </a>
            <span class="badge bg-success d-none d-sm-inline-flex">
                <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i> Online
            </span>
        </div>
    </div>

    <div class="page-content">
        <div class="page-header">
            <h2 class="mb-1">Past Questions</h2>
            <p class="text-muted mb-0" style="font-size: 0.95rem;">Review and practice with previous examination questions from the department.</p>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i> Filter Questions</h5>
            </div>
            <div class="card-body">
                <form action="past_questions.php" method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="subject" class="form-label">Course</label>
                        <select name="subject" id="subject" class="form-select">
                            <option value="">All Courses</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?php echo htmlspecialchars($sub); ?>" <?php echo $subject_filter === $sub ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="class" class="form-label">Class</label>
                        <select name="class" id="class" class="form-select">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo htmlspecialchars($cls); ?>" <?php echo $class_filter === $cls ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($past_questions)): ?>
            <div class="card">
                <div class="card-body text-center py-5 px-4">
                    <div style="width: 64px; height: 64px; border-radius: 50%; background: var(--color-bg-tertiary); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                        <i class="fas fa-search" style="font-size: 1.5rem; color: var(--color-text-muted);"></i>
                    </div>
                    <h5 class="mb-2">No past questions found</h5>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Try changing your filters or check back later for more questions.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($past_questions as $q): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body p-4">
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-info"><?php echo htmlspecialchars($q['subject']); ?></span>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($q['class']); ?></span>
                                        <?php if (!empty($q['course_code'])): ?>
                                            <span class="badge bg-info" style="background: #f0f9ff !important; color: #0369a1 !important;"><?php echo htmlspecialchars($q['course_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge bg-<?php echo $q['type'] === 'objective' ? 'success' : 'warning'; ?>">
                                        <i class="fas fa-<?php echo $q['type'] === 'objective' ? 'list-check' : 'pen'; ?> me-1"></i>
                                        <?php echo ucfirst($q['type']); ?>
                                    </span>
                                </div>
                                
                                <h5 class="mb-3" style="line-height: 1.5; font-weight: 600;"><?php echo htmlspecialchars($q['question']); ?></h5>
                                
                                <?php if ($q['image']): ?>
                                    <div class="mb-3">
                                        <img src="uploads/questions/<?php echo $q['image']; ?>" class="img-fluid rounded" style="max-height: 300px; border: 1px solid var(--color-border);" onerror="this.style.display='none'">
                                    </div>
                                <?php endif; ?>

                                <?php if ($q['type'] === 'objective'): ?>
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-6"><div style="padding: 0.75rem 1rem; background: var(--color-bg-secondary); border-radius: var(--radius-md); font-size: 0.9rem;"><strong class="me-2">A.</strong><?php echo htmlspecialchars($q['option_a']); ?></div></div>
                                        <div class="col-md-6"><div style="padding: 0.75rem 1rem; background: var(--color-bg-secondary); border-radius: var(--radius-md); font-size: 0.9rem;"><strong class="me-2">B.</strong><?php echo htmlspecialchars($q['option_b']); ?></div></div>
                                        <div class="col-md-6"><div style="padding: 0.75rem 1rem; background: var(--color-bg-secondary); border-radius: var(--radius-md); font-size: 0.9rem;"><strong class="me-2">C.</strong><?php echo htmlspecialchars($q['option_c']); ?></div></div>
                                        <div class="col-md-6"><div style="padding: 0.75rem 1rem; background: var(--color-bg-secondary); border-radius: var(--radius-md); font-size: 0.9rem;"><strong class="me-2">D.</strong><?php echo htmlspecialchars($q['option_d']); ?></div></div>
                                    </div>
                                    <div>
                                        <button class="btn btn-outline-primary btn-sm" onclick="this.nextElementSibling.classList.toggle('d-none'); if (this.nextElementSibling.classList.contains('d-none')) { this.innerHTML = '<i class=\'fas fa-eye me-1\'></i> Show Answer'; } else { this.innerHTML = '<i class=\'fas fa-eye-slash me-1\'></i> Hide Answer'; }">
                                            <i class="fas fa-eye me-1"></i> Show Answer
                                        </button>
                                        <div class="mt-2 p-3 d-none" style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius-md); color: #065f46; font-weight: 600; font-size: 0.9rem;">
                                            <i class="fas fa-circle-check me-2"></i> Correct Answer: <strong><?php echo htmlspecialchars($q['correct_answer']); ?></strong>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-3">
                                        <span class="badge bg-warning">
                                            <i class="fas fa-pen me-1"></i> Theory Question &mdash; Practice writing your answer
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($q['explanation']): ?>
                                    <div class="mt-3 p-3" style="background: var(--color-bg-secondary); border-radius: var(--radius-md); font-size: 0.88rem; color: var(--color-text-secondary); line-height: 1.6;">
                                        <strong class="me-1" style="color: var(--color-text);">Explanation:</strong><?php echo htmlspecialchars($q['explanation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    sidebar.classList.toggle('open');
    backdrop.classList.toggle('show');
}
</script>

<?php include('includes/footer.php'); ?>
