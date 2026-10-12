<?php
if (!isset($pdo)) {
    require_once('../db.php');
}

try {
    $nav_subjects = $pdo->query("SELECT DISTINCT subject FROM question_bank ORDER BY subject ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $nav_subjects = [];
}

$current_page = basename($_SERVER['PHP_SELF']);
$admin_username = $_SESSION['admin_username'] ?? 'Admin';

$active_section = '';
if (in_array($current_page, ['dashboard.php'])) $active_section = 'dashboard';
if (in_array($current_page, ['manage_exams.php', 'create_exam.php'])) $active_section = 'exams';
if (in_array($current_page, ['manage_questions.php', 'add_question.php', 'question_bank.php', 'upload_questions.php', 'get_questions.php'])) $active_section = 'questions';
if (in_array($current_page, ['manage_students.php', 'register_student.php', 'edit_student.php', 'student_activity.php'])) $active_section = 'students';
if (in_array($current_page, ['manage_subjects.php'])) $active_section = 'subjects';
if (in_array($current_page, ['manage_classes.php'])) $active_section = 'classes';
if (in_array($current_page, ['view_results.php', 'download_results.php', 'grade_theory.php'])) $active_section = 'results';
if (in_array($current_page, ['profile.php'])) $active_section = 'profile';
?>

<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="https://fss-oyo.edu.ng/images/FSS_LOGO.png" alt="FSS OYO Logo" style="height: 42px; border-radius: var(--radius-md); object-fit: contain;" class="me-3" onerror="this.style.display='none'">
           <div>
            <h5>FSS OYO</h5>
            <span>Admin Panel</span>
        </div>
    </div>

    <div class="sidebar-section-title">Overview</div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link <?php echo $active_section == 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-layout-grid"></i> Dashboard
        </a>
    </nav>

    <div class="sidebar-section-title">Management</div>
    <nav class="sidebar-nav">
        <a href="manage_exams.php" class="sidebar-link <?php echo $active_section == 'exams' ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-list"></i> Examinations
        </a>
        <a href="manage_questions.php" class="sidebar-link <?php echo $active_section == 'questions' ? 'active' : ''; ?>">
            <i class="fas fa-circle-question"></i> Questions
        </a>
        <a href="question_bank.php" class="sidebar-link">
            <i class="fas fa-database"></i> Question Bank
        </a>
        <a href="manage_students.php" class="sidebar-link <?php echo $active_section == 'students' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> Students
        </a>
    </nav>

    <div class="sidebar-section-title">Academic</div>
    <nav class="sidebar-nav">
        <a href="manage_subjects.php" class="sidebar-link <?php echo $active_section == 'subjects' ? 'active' : ''; ?>">
            <i class="fas fa-book"></i> Courses
        </a>
        <a href="manage_classes.php" class="sidebar-link <?php echo $active_section == 'classes' ? 'active' : ''; ?>">
            <i class="fas fa-layer-group"></i> Classes
        </a>
        <a href="view_results.php" class="sidebar-link <?php echo $active_section == 'results' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i> Results
        </a>
    </nav>

    <div class="sidebar-section-title">System</div>
    <nav class="sidebar-nav">
        <a href="profile.php" class="sidebar-link <?php echo $active_section == 'profile' ? 'active' : ''; ?>">
            <i class="fas fa-user-circle"></i> Profile
        </a>
        <a href="logout.php" class="sidebar-link" style="color: #dc2626;">
            <i class="fas fa-right-from-bracket"></i> Logout
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="d-flex align-items-center mb-3">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--color-bg-tertiary); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--color-text);">
                <?php echo strtoupper(substr($admin_username, 0, 1)); ?>
            </div>
            <div class="ms-3" style="min-width: 0;">
                <div style="font-weight: 600; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($admin_username); ?></div>
                <div style="font-size: 0.75rem; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Administrator</div>
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
                <?php
                $hour = date('H');
                if ($hour < 12) { $greeting = 'Good morning'; }
                elseif ($hour < 18) { $greeting = 'Good afternoon'; }
                else { $greeting = 'Good evening'; }
                ?>
                <h5 class="mb-0"><?php echo $greeting; ?>, <?php echo htmlspecialchars($admin_username); ?> 👋</h5>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="../index.php" class="btn btn-outline-primary btn-sm d-none d-md-inline-flex">
                <i class="fas fa-house me-2"></i> View Site
            </a>
            <span class="badge bg-success d-none d-sm-inline-flex">
                <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i> Online
            </span>
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
