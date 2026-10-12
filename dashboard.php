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
$department = trim($_SESSION['department']);
$student_name = $_SESSION['student_name'];
$matric_number = $_SESSION['matric_number'] ?? 'N/A';

$stmt = $pdo->prepare("
    SELECT e.*, s.course_code, s.name as subject_name 
    FROM exams e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE e.class_id = ?
    AND (e.published = 1 OR e.published_obj = 1 OR e.published_theory = 1)
    AND FIND_IN_SET(?, REPLACE(e.departments, ' ', ''))
    ORDER BY e.created_at DESC
");
$stmt->execute([$class_id, $department]);
$exams = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT exam_id, status, score FROM results WHERE student_id = ?');
$stmt->execute([$student_id]);
$results_data = $stmt->fetchAll(PDO::FETCH_UNIQUE);

$stmt = $pdo->prepare('SELECT registration_number FROM students WHERE id = ?');
$stmt->execute([$student_id]);
$reg_no = $stmt->fetchColumn();

$class_stmt = $pdo->prepare('SELECT name FROM classes WHERE id = ?');
$class_stmt->execute([$class_id]);
$class_name = $class_stmt->fetchColumn();

$total_exams = count($exams);
$taken_exams = 0;
$pending_exams = 0;
$in_progress = 0;
$total_score = 0;
$graded_count = 0;

foreach ($exams as $exam) {
    $status = $results_data[$exam['id']]['status'] ?? 'not_started';
    if ($status === 'submitted' || $status === 'graded') {
        $taken_exams++;
        if ($status === 'graded' && isset($results_data[$exam['id']]['score'])) {
            $total_score += $results_data[$exam['id']]['score'];
            $graded_count++;
        }
    } elseif ($status === 'in_progress') {
        $in_progress++;
    } else {
        $pending_exams++;
    }
}

$avg_score = $graded_count > 0 ? number_format($total_score / $graded_count, 1) : 'N/A';

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
        <a href="dashboard.php" class="sidebar-link active">
            <i class="fas fa-layout-grid"></i> Dashboard
        </a>
        <a href="#exams" class="sidebar-link">
            <i class="fas fa-clipboard-list"></i> Examinations
        </a>
        <a href="past_questions.php" class="sidebar-link">
            <i class="fas fa-book-open"></i> Past Questions
        </a>
    </nav>

    <div class="sidebar-section-title">Account</div>
    <nav class="sidebar-nav">
        <a href="#profile" class="sidebar-link">
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
                <?php
                $hour = date('H');
                if ($hour < 12) { $greeting = 'Good morning'; }
                elseif ($hour < 18) { $greeting = 'Good afternoon'; }
                else { $greeting = 'Good evening'; }
                ?>
                <h5 class="mb-0"><?php echo $greeting; ?>, <?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?> 👋</h5>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success d-none d-sm-inline-flex">
                <i class="fas fa-circle me-1" style="font-size: 0.55rem;"></i> Online
            </span>
        </div>
    </div>

    <div class="page-content">
        <div class="page-header">
            <h2 class="mb-1">Dashboard</h2>
            <p class="text-muted mb-0" style="font-size: 0.95rem;">Here's an overview of your examinations and academic progress today.</p>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #eff6ff; color: #2563eb;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-label">Total Exams</div>
                    <div class="stat-value"><?php echo $total_exams; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        Available this semester
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fffbeb; color: #d97706;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?php echo $pending_exams; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        Exams to be taken
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ecfdf5; color: #059669;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo $taken_exams; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        Exams submitted
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fdf4ff; color: #9333ea;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-label">Avg. Score</div>
                    <div class="stat-value"><?php echo $avg_score; ?><?php echo is_numeric($avg_score) ? '%' : ''; ?></div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                        <?php echo $graded_count; ?> graded exam<?php echo $graded_count != 1 ? 's' : ''; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card mb-4" id="exams">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Available Examinations</h5>
                        <span class="badge bg-secondary"><?php echo $total_exams; ?> Total</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($exams): ?>
                            <div class="table-responsive" style="border: none; border-radius: 0;">
                                <table class="table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Exam</th>
                                            <th>Course</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($exams as $exam): 
                                            $status = $results_data[$exam['id']]['status'] ?? 'not_started';
                                            $is_taken = ($status === 'submitted' || $status === 'graded');
                                            $is_in_progress = ($status === 'in_progress');
                                            $score = $results_data[$exam['id']]['score'] ?? null;
                                        ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 600; color: var(--color-text);"><?php echo htmlspecialchars($exam['title']); ?></div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.85rem;"><?php echo htmlspecialchars($exam['course_code']); ?></div>
                                                    <div style="font-size: 0.8rem; color: var(--color-text-muted);"><?php echo htmlspecialchars($exam['subject_name']); ?></div>
                                                </td>
                                                <td>
                                                    <span style="font-size: 0.9rem; font-weight: 500;"><?php echo $exam['duration']; ?> mins</span>
                                                </td>
                                                <td>
                                                    <?php if ($is_taken): ?>
                                                        <?php if ($status === 'graded'): ?>
                                                            <span class="badge bg-success"><i class="fas fa-check me-1"></i> Graded: <?php echo $score; ?>%</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-info"><i class="fas fa-clock me-1"></i> Awaiting Result</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($is_in_progress): ?>
                                                        <span class="badge bg-warning"><i class="fas fa-pause me-1"></i> In Progress</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i> Available</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($is_taken): ?>
                                                        <a href="result.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-outline-primary btn-sm">
                                                            <i class="fas fa-eye me-1"></i> View
                                                        </a>
                                                    <?php elseif ($is_in_progress): ?>
                                                        <a href="exam.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-warning btn-sm">
                                                            <i class="fas fa-play me-1"></i> Resume
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="exam_instructions.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-play me-1"></i> Start
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 px-4">
                                <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--color-bg-tertiary); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                                    <i class="fas fa-inbox" style="font-size: 1.4rem; color: var(--color-text-muted);"></i>
                                </div>
                                <h5 class="mb-2">No exams available</h5>
                                <p class="text-muted mb-0" style="font-size: 0.9rem;">Please check back later or contact your departmental coordinator.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                                    <a href="past_questions.php" class="quick-action-btn">
                                        <i class="fas fa-book-open"></i>
                                        <div>
                                            <div style="font-weight: 600;">Past Questions</div>
                                            <div style="font-size: 0.8rem; color: var(--color-text-muted);">Practice with past exam questions</div>
                                        </div>
                                    </a>
                                    <a href="#profile" class="quick-action-btn">
                                        <i class="fas fa-user-circle"></i>
                                        <div>
                                            <div style="font-weight: 600;">View Profile</div>
                                            <div style="font-size: 0.8rem; color: var(--color-text-muted);">Check your student details</div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Exam Status</h5>
                            </div>
                            <div class="card-body" style="padding-top: 1rem;">
                                <div class="mb-3 pb-3" style="border-bottom: 1px solid var(--color-border-light);">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span style="font-size: 0.85rem; color: var(--color-text-secondary);">Completed</span>
                                        <span style="font-weight: 700; color: var(--color-text);"><?php echo $taken_exams; ?>/<?php echo max($total_exams, 1); ?></span>
                                    </div>
                                    <div style="height: 6px; background: var(--color-bg-tertiary); border-radius: 100px; overflow: hidden;">
                                        <div style="width: <?php echo $total_exams > 0 ? ($taken_exams / $total_exams * 100) : 0; ?>%; height: 100%; background: #059669; border-radius: 100px;"></div>
                                    </div>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-2">
                                            <span style="width: 8px; height: 8px; background: #059669; border-radius: 50%;"></span>
                                            <span style="font-size: 0.85rem; color: var(--color-text-secondary);">Completed</span>
                                        </div>
                                        <span style="font-weight: 600; font-size: 0.85rem;"><?php echo $taken_exams; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-2">
                                            <span style="width: 8px; height: 8px; background: #d97706; border-radius: 50%;"></span>
                                            <span style="font-size: 0.85rem; color: var(--color-text-secondary);">Pending</span>
                                        </div>
                                        <span style="font-weight: 600; font-size: 0.85rem;"><?php echo $pending_exams; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-2">
                                            <span style="width: 8px; height: 8px; background: #2563eb; border-radius: 50%;"></span>
                                            <span style="font-size: 0.85rem; color: var(--color-text-secondary);">In Progress</span>
                                        </div>
                                        <span style="font-weight: 600; font-size: 0.85rem;"><?php echo $in_progress; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card mb-4" id="profile">
                    <div class="card-header">
                        <h5 class="mb-0">Student Profile</h5>
                    </div>
                    <div class="card-body text-center pt-4">
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, var(--color-primary) 0%, #334155 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; color: #fff; font-size: 1.75rem; font-weight: 700;">
                            <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                        </div>
                        <h5 class="mb-1"><?php echo htmlspecialchars($student_name); ?></h5>
                        <span class="badge bg-info mb-3"><?php echo htmlspecialchars($department); ?></span>

                        <div style="text-align: left; margin-top: 1.5rem;">
                            <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid var(--color-border-light);">
                                <span style="font-size: 0.85rem; color: var(--color-text-muted);">Matric No</span>
                                <span style="font-size: 0.85rem; font-weight: 600;"><?php echo htmlspecialchars($matric_number); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid var(--color-border-light);">
                                <span style="font-size: 0.85rem; color: var(--color-text-muted);">Reg No</span>
                                <span style="font-size: 0.85rem; font-weight: 600;"><?php echo htmlspecialchars($reg_no); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center py-2">
                                <span style="font-size: 0.85rem; color: var(--color-text-muted);">Level</span>
                                <span style="font-size: 0.85rem; font-weight: 600;"><?php echo htmlspecialchars($class_name); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Need Help?</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-start mb-3">
                            <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: #eff6ff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class="fas fa-info-circle" style="color: #2563eb;"></i>
                            </div>
                            <div class="ms-3">
                                <div style="font-weight: 600; font-size: 0.9rem;">Exam Access</div>
                                <div style="font-size: 0.82rem; color: var(--color-text-muted); line-height: 1.5;">Contact your invigilator or departmental coordinator for the exam access code.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-start">
                            <div style="width: 36px; height: 36px; border-radius: var(--radius-sm); background: #ecfdf5; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class="fas fa-life-ring" style="color: #059669;"></i>
                            </div>
                            <div class="ms-3">
                                <div style="font-weight: 600; font-size: 0.9rem;">Technical Issues</div>
                                <div style="font-size: 0.82rem; color: var(--color-text-muted); line-height: 1.5;">Do not refresh during exam. Alert the invigilator for any system problems.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
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
