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
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    )");
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'exam_access_code'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('exam_access_code', '123456')");
    }
} catch (PDOException $e) {
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        activity_type VARCHAR(50) NOT NULL,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_access_code'])) {
    fss_verify_csrf();
    $new_code = trim($_POST['access_code']);
    if (!empty($new_code)) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'exam_access_code'");
        $stmt->execute();
        $old_code = $stmt->fetchColumn();
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'exam_access_code'");
        $stmt->execute([$new_code]);
        fss_audit('admin', 'access_code_updated', 'system_setting', null, [
            'old_code' => $old_code,
            'new_code' => $new_code
        ], $admin_id);
        $message = "Exam Access Code updated successfully to: <b>" . htmlspecialchars($new_code) . "</b>";
    } else {
        $error = "Access code cannot be empty.";
    }
}

$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'exam_access_code'");
$stmt->execute();
$current_access_code = $stmt->fetchColumn() ?: '123456';

$admin_username = $_SESSION['admin_username'];

try {
    $student_count = $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
} catch (PDOException $e) { $student_count = 0; }

try {
    $subject_count = $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
} catch (PDOException $e) { $subject_count = 0; }

try {
    $exam_count = $pdo->query('SELECT COUNT(*) FROM exams')->fetchColumn();
} catch (PDOException $e) { $exam_count = 0; }

try {
    $published_exam_count = $pdo->query("SELECT COUNT(*) FROM exams WHERE published = 1 OR published_obj = 1 OR published_theory = 1")->fetchColumn();
} catch (PDOException $e) { $published_exam_count = 0; }

try {
    $question_count = $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
} catch (PDOException $e) { $question_count = 0; }

try {
    $bank_count = $pdo->query('SELECT COUNT(*) FROM question_bank')->fetchColumn();
} catch (PDOException $e) {
    $bank_count = 0;
}

try {
    $results_count = $pdo->query('SELECT COUNT(*) FROM results')->fetchColumn();
} catch (PDOException $e) { $results_count = 0; }

try {
    $graded_count = $pdo->query("SELECT COUNT(*) FROM results WHERE status = 'graded'")->fetchColumn();
} catch (PDOException $e) { $graded_count = 0; }

$db_corruption_notices = [];
$manual_fix_steps = function ($tableName, $ibdPathHint = 'C:\\xampp\\mysql\\data\\fsscbt_db\\') {
    $safeName = htmlspecialchars($tableName);
    $ibdFile = htmlspecialchars(rtrim($ibdPathHint, '\\/') . DIRECTORY_SEPARATOR . $tableName . '.ibd');
    return "<b>Database corruption detected for table <code>{$safeName}</code> (Missing/Invalid Tablespace / Unknown column).</b>"
        . "<br><b>AUTOMATIC REBUILD DISABLED TO PROTECT YOUR DATA.</b> Never allow an automated tool to DROP+RECREATE a production table — all student records, biometrics, offences, results, and exam configurations in <code>{$safeName}</code> would be permanently lost."
        . "<br><br><b>Manual repair steps (XAMPP):</b>"
        . "<ol class=\"mb-0 mt-1 small\">"
        . "<li>Open <b>XAMPP Control Panel</b> and click <b>Stop</b> next to MySQL.</li>"
        . "<li>Using File Explorer, <b>RENAME</b> the orphaned InnoDB tablespace file away: <code>{$ibdFile}</code> (do NOT delete it yet — move it to your Desktop as a backup just in case).</li>"
        . "<li>Return to XAMPP Control Panel and click <b>Start</b> next to MySQL.</li>"
        . "<li><b>Restore only the affected table</b> from your most recent nightly / pre-upgrade MySQL backup (use <code>mysql fsscbt_db &lt; backup_table_{$safeName}.sql</code> or phpMyAdmin Import). If you have no backup, run the guarded ALTER/CREATE-only portion of <code>database_migration_v2.sql</code> for table <code>{$safeName}</code> to rebuild the empty schema, then re-import the CSV/seed data.</li>"
        . "<li>If this error repeats every XAMPP restart, your MySQL data directory may have a stale <code>#ibd*</code> temporary file or InnoDB log corruption — see the XAMPP forums for <code>innodb_force_recovery=1..6</code> procedure (start with 1).</li>"
        . "</ol>";
};

try {
    $pdo->query("SELECT matric_number, department FROM students LIMIT 1");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '194') !== false || strpos($e->getMessage(), '1030') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
        $db_corruption_notices[] = $manual_fix_steps('students') . " <div class=\"mt-2 small text-muted\">Raw error: " . htmlspecialchars($e->getMessage()) . "</div>";
    } else {
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN matric_number VARCHAR(50) NOT NULL UNIQUE AFTER id");
        } catch (PDOException $e2) {}
        try {
            $pdo->exec("ALTER TABLE students MODIFY COLUMN department ENUM('Computer Science') NOT NULL DEFAULT 'Computer Science'");
        } catch (PDOException $e2) {}
    }
}

try {
    $pdo->query("SELECT departments FROM exams LIMIT 1");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '194') !== false || strpos($e->getMessage(), '1030') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
        $db_corruption_notices[] = $manual_fix_steps('exams') . " <div class=\"mt-2 small text-muted\">Raw error: " . htmlspecialchars($e->getMessage()) . "</div>";
    } else {
        try {
            $pdo->exec("ALTER TABLE exams MODIFY COLUMN departments SET('Computer Science') NOT NULL DEFAULT 'Computer Science'");
        } catch (PDOException $e2) {}
    }
}

try {
    $pdo->query("SELECT published_obj FROM exams LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE exams ADD COLUMN published_obj TINYINT(1) DEFAULT 0 AFTER published");
        $pdo->exec("ALTER TABLE exams ADD COLUMN published_theory TINYINT(1) DEFAULT 0 AFTER published_obj");
    } catch (PDOException $e2) {}
}

try {
    $pdo->query("SELECT start_time FROM results LIMIT 1");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '194') !== false || strpos($e->getMessage(), '1030') !== false) {
        $db_corruption_notices[] = $manual_fix_steps('results') . " <div class=\"mt-2 small text-muted\">Raw error: " . htmlspecialchars($e->getMessage()) . "</div>";
    } else {
        try {
            $pdo->exec("ALTER TABLE results ADD COLUMN start_time TIMESTAMP NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE results MODIFY COLUMN status ENUM('not_started','in_progress','submitted','auto_submitted','graded','disqualified','expired') DEFAULT 'in_progress'");
        } catch (PDOException $e2) {}
    }
}

try {
    $pdo->query("SELECT course_code, course_title FROM subjects LIMIT 1");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '194') !== false || strpos($e->getMessage(), '1030') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
        $db_corruption_notices[] = $manual_fix_steps('subjects') . " <div class=\"mt-2 small text-muted\">Raw error: " . htmlspecialchars($e->getMessage()) . "</div>";
    } else {
        try {
            $pdo->exec("ALTER TABLE subjects ADD COLUMN course_code VARCHAR(20) NOT NULL UNIQUE FIRST");
        } catch (PDOException $e2) {}
        try {
            $pdo->exec("ALTER TABLE subjects ADD COLUMN course_title VARCHAR(200) NOT NULL AFTER name");
        } catch (PDOException $e2) {}
    }
}

$questionBankReady = false;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS question_bank ( 
        id INT AUTO_INCREMENT PRIMARY KEY, 
        question_number INT,
        question TEXT NOT NULL, 
        type ENUM('objective','theory') NOT NULL, 
        option_a TEXT, 
        option_b TEXT, 
        option_c TEXT, 
        option_d TEXT, 
        correct_answer VARCHAR(10), 
        explanation TEXT, 
        subject VARCHAR(100), 
        course_code VARCHAR(20),
        class VARCHAR(50), 
        department SET('Computer Science') NOT NULL,
        image VARCHAR(255), 
        is_past_question TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
    )");
    $questionBankReady = true;
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '1813') !== false || strpos($e->getMessage(), '194') !== false || strpos($e->getMessage(), '1030') !== false) {
        $db_corruption_notices[] = $manual_fix_steps('question_bank')
            . " <div class=\"mt-2 text-muted small\">This table held all CSC/GST/MTH/PHY seed questions. Do NOT recreate it empty — restore the CSC101–408 / GST / MTH / PHY rows from your backup CSV or the original install SQL. Raw error: " . htmlspecialchars($e->getMessage()) . "</div>";
    } else {
        $db_corruption_notices[] = "<b>Database error (question_bank):</b> " . htmlspecialchars($e->getMessage())
            . " <br><span class=\"text-muted small\">AUTOMATIC REBUILD OF question_bank IS DISABLED. All CSC/GST/MTH/PHY seeds would be lost. Restore from backup, or — if this is a fresh install — re-run <code>database_migration_v2.sql</code> question_bank section + seed CSVs.</span>";
    }
}

if ($questionBankReady) {
    try {
        $pdo->query("SELECT department, course_code FROM question_bank LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE question_bank ADD COLUMN department SET('Computer Science') NOT NULL AFTER class");
        } catch (PDOException $e2) {}
        try {
            $pdo->exec("ALTER TABLE question_bank ADD COLUMN course_code VARCHAR(20) AFTER subject");
        } catch (PDOException $e2) {}
    }

    try {
        $pdo->query("SELECT is_past_question FROM question_bank LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE question_bank ADD COLUMN is_past_question TINYINT(1) DEFAULT 0");
        } catch (PDOException $e2) {}
    }

    try {
        $pdo->query("SELECT question_number FROM question_bank LIMIT 1");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE question_bank ADD COLUMN question_number INT AFTER id");
        } catch (PDOException $e2) {}
    }
}

try {
    $pdo->query("SELECT question_source FROM exam_questions LIMIT 1");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), '194') !== false || strpos($e->getMessage(), '1030') !== false) {
        $db_corruption_notices[] = $manual_fix_steps('exam_questions') . " <div class=\"mt-2 small text-muted\">Raw error: " . htmlspecialchars($e->getMessage()) . "</div>";
    } else {
        try {
            $pdo->exec("ALTER TABLE exam_questions ADD COLUMN question_source ENUM('questions', 'question_bank') NOT NULL DEFAULT 'questions'");
            if (!$message) {
                $message = "Database updated successfully with new Question Bank support.";
            }
        } catch (PDOException $e2) {}
    }
}

try {
    $pdo->exec("ALTER TABLE exam_questions DROP FOREIGN KEY exam_questions_ibfk_2");
} catch (PDOException $e) {}

try {
    $pdo->query("SELECT created_at FROM questions LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE questions ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    } catch (PDOException $e2) {}
}

try {
    $pdo->query("SELECT result_display_status FROM results LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE results ADD COLUMN result_display_status ENUM('pending', 'approved', 'held') DEFAULT 'pending'");
    } catch (PDOException $e2) {}
}

include('../includes/header.php');
include('includes/navbar.php');
?>

<div class="page-content">
    <?php if (!empty($db_corruption_notices)): ?>
        <?php foreach ($db_corruption_notices as $noticeIdx => $notice): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-triangle-exclamation me-2"></i>
                <?php echo $notice; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h2 class="mb-1">Admin Dashboard</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Here's what's happening with your CBT platform today.</p>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label">Students</div>
                <div class="stat-value"><?php echo $student_count; ?></div>
                <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                    Registered students
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #ecfdf5; color: #059669;">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-label">Courses</div>
                <div class="stat-value"><?php echo $subject_count; ?></div>
                <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                    ND &amp; HND levels
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #fffbeb; color: #d97706;">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-label">Exams</div>
                <div class="stat-value"><?php echo $exam_count; ?></div>
                <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                    <?php echo $published_exam_count; ?> published
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: #fdf4ff; color: #9333ea;">
                    <i class="fas fa-circle-question"></i>
                </div>
                <div class="stat-label">Questions</div>
                <div class="stat-value"><?php echo $question_count + $bank_count; ?></div>
                <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                    MCQ + Theory formats
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-xl-4">
            <div class="card h-100" style="background: var(--color-primary); border-color: var(--color-primary); color: #fff;">
                <div class="card-body py-4">
                    <h5 class="mb-3" style="color: #fff;"><i class="fas fa-shield-halved me-2"></i> Exam Access Code</h5>
                    <p style="font-size: 0.88rem; color: rgba(255,255,255,0.7); margin-bottom: 1.25rem;">
                        Students must enter this code before logging in for any examination.
                    </p>
                    <form action="dashboard.php" method="POST">
                        <?php fss_csrf_field(); ?>
                        <div class="input-group">
                            <input type="text" 
                                   name="access_code" 
                                   class="form-control fw-bold text-center" 
                                   value="<?php echo htmlspecialchars($current_access_code); ?>" 
                                   placeholder="Enter Code"
                                   style="font-size: 1.1rem; letter-spacing: 0.15em;">
                            <button type="submit" name="update_access_code" class="btn btn-warning fw-bold" style="color: var(--color-primary);">
                                Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="row g-4 h-100">
                <div class="col-sm-6">
                    <div class="card h-100">
                        <div class="card-body py-4">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                <h5 class="mb-0">Results Overview</h5>
                                <span class="badge bg-info"><?php echo $results_count; ?> Total</span>
                            </div>
                            <div class="mb-3 pb-3" style="border-bottom: 1px solid var(--color-border-light);">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span style="font-size: 0.85rem; color: var(--color-text-secondary);">Graded</span>
                                    <span style="font-weight: 700; color: var(--color-text);"><?php echo $graded_count; ?>/<?php echo max($results_count, 1); ?></span>
                                </div>
                                <div style="height: 6px; background: var(--color-bg-tertiary); border-radius: 100px; overflow: hidden;">
                                    <div style="width: <?php echo $results_count > 0 ? ($graded_count / $results_count * 100) : 0; ?>%; height: 100%; background: #059669; border-radius: 100px;"></div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.75rem;">
                                <div style="flex: 1; padding: 0.75rem; background: var(--color-bg-secondary); border-radius: var(--radius-md); text-align: center;">
                                    <div style="font-weight: 800; font-size: 1.4rem; letter-spacing: -0.02em; line-height: 1;"><?php echo $results_count; ?></div>
                                    <div style="font-size: 0.7rem; color: var(--color-text-muted); font-weight: 500; margin-top: 0.25rem;">Submissions</div>
                                </div>
                                <div style="flex: 1; padding: 0.75rem; background: var(--color-bg-secondary); border-radius: var(--radius-md); text-align: center;">
                                    <div style="font-weight: 800; font-size: 1.4rem; letter-spacing: -0.02em; line-height: 1;"><?php echo $graded_count; ?></div>
                                    <div style="font-size: 0.7rem; color: var(--color-text-muted); font-weight: 500; margin-top: 0.25rem;">Graded</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="card h-100">
                        <div class="card-body py-4">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                <h5 class="mb-0">Quick Actions</h5>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <a href="create_exam.php" class="quick-action-btn">
                                    <i class="fas fa-file-circle-plus"></i>
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.9rem;">Create Exam</div>
                                        <div style="font-size: 0.78rem; color: var(--color-text-muted);">Schedule a new examination</div>
                                    </div>
                                </a>
                                <a href="register_student.php" class="quick-action-btn">
                                    <i class="fas fa-user-plus"></i>
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.9rem;">Register Student</div>
                                        <div style="font-size: 0.78rem; color: var(--color-text-muted);">Add new student record</div>
                                    </div>
                                </a>
                                <a href="upload_questions.php" class="quick-action-btn">
                                    <i class="fas fa-cloud-arrow-up"></i>
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.9rem;">Upload Questions</div>
                                        <div style="font-size: 0.78rem; color: var(--color-text-muted);">Bulk import questions</div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Examinations</h5>
                    <a href="manage_exams.php" class="btn btn-outline-primary btn-sm">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php
                    $recent_exams = [];
                    try {
                        $recent_exams = $pdo->query('SELECT e.*, s.course_code, s.name as subject_name FROM exams e JOIN subjects s ON e.subject_id = s.id ORDER BY e.created_at DESC LIMIT 6')->fetchAll();
                    } catch (PDOException $e) {}
                    if ($recent_exams):
                    ?>
                        <div class="table-responsive" style="border: none; border-radius: 0;">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>Exam Title</th>
                                        <th>Course</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_exams as $exam): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600; font-size: 0.9rem;"><?php echo htmlspecialchars($exam['title']); ?></div>
                                            </td>
                                            <td>
                                                <span style="font-size: 0.85rem; font-weight: 500;"><?php echo htmlspecialchars($exam['course_code']); ?></span>
                                            </td>
                                            <td>
                                                <span style="font-size: 0.85rem; font-weight: 500;"><?php echo (int)$exam['duration']; ?> mins</span>
                                            </td>
                                            <td>
                                                <?php if (!empty($exam['published']) || !empty($exam['published_obj']) || !empty($exam['published_theory'])): ?>
                                                    <span class="badge bg-success"><i class="fas fa-circle me-1" style="font-size: 0.45rem;"></i> Published</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><i class="fas fa-circle me-1" style="font-size: 0.45rem;"></i> Draft</span>
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
                            <h5 class="mb-2">No exams yet</h5>
                            <p class="text-muted mb-0" style="font-size: 0.9rem;">Get started by creating your first examination.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">System Management</h5>
                </div>
                <div class="card-body">
                    <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                        <a href="manage_exams.php" class="sidebar-link" style="border: 1px solid var(--color-border-light); border-radius: var(--radius-md);">
                            <i class="fas fa-clipboard-list"></i>
                            <div>
                                <div style="font-weight: 600; font-size: 0.9rem;">Manage Examinations</div>
                                <div style="font-size: 0.78rem; color: var(--color-text-muted);">Create, edit, publish exams</div>
                            </div>
                        </a>
                        <a href="manage_students.php" class="sidebar-link" style="border: 1px solid var(--color-border-light); border-radius: var(--radius-md);">
                            <i class="fas fa-users"></i>
                            <div>
                                <div style="font-weight: 600; font-size: 0.9rem;">Manage Students</div>
                                <div style="font-size: 0.78rem; color: var(--color-text-muted);">Register, edit student records</div>
                            </div>
                        </a>
                        <a href="manage_questions.php" class="sidebar-link" style="border: 1px solid var(--color-border-light); border-radius: var(--radius-md);">
                            <i class="fas fa-circle-question"></i>
                            <div>
                                <div style="font-weight: 600; font-size: 0.9rem;">Manage Questions</div>
                                <div style="font-size: 0.78rem; color: var(--color-text-muted);">Add, edit exam questions</div>
                            </div>
                        </a>
                        <a href="view_results.php" class="sidebar-link" style="border: 1px solid var(--color-border-light); border-radius: var(--radius-md);">
                            <i class="fas fa-chart-line"></i>
                            <div>
                                <div style="font-weight: 600; font-size: 0.9rem;">View Results</div>
                                <div style="font-size: 0.78rem; color: var(--color-text-muted);">Grade theory, download reports</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</div>

<?php include('../includes/footer.php'); ?>
