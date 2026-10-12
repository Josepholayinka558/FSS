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
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_log') {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }

    if (!$error) {
        $log_id = (int)($_POST['log_id'] ?? 0);
        if ($log_id > 0) {
            try {
                $pdo->prepare('DELETE FROM student_activity_log WHERE id = ?')->execute([$log_id]);
                fss_audit('admin', 'admin_delete_activity_log', 'student_activity_log', $log_id, [], $adminId);
                $message = 'Log entry deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to delete log entry.';
            }
        }
    }
}

$query = "
    SELECT l.*, s.name as student_name, s.registration_number, s.matric_number
    FROM student_activity_log l 
    JOIN students s ON l.student_id = s.id 
    ORDER BY l.created_at DESC 
    LIMIT 150
";
$logs = $pdo->query($query)->fetchAll();

include(__DIR__ . '/../includes/header.php');
include(__DIR__ . '/includes/navbar.php');
?>
<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="mb-0"><i class="fas fa-clock-rotate-left me-2"></i> Student Activity Log</h2>
        <div class="d-flex gap-2">
            <a href="view_results.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-chart-column me-1"></i>Exam Results</a>
            <button onclick="window.location.reload()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-rotate me-1"></i> Refresh Logs
            </button>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($message); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Time</th>
                            <th>Student</th>
                            <th>Activity</th>
                            <th>Details</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">No activity logs found yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="small text-nowrap"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($log['student_name']); ?></div>
                                        <div class="small"><strong><?php echo htmlspecialchars($log['matric_number'] ?? '—'); ?></strong></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($log['registration_number'] ?? '—'); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                        $badge_class = 'bg-secondary';
                                        $type = $log['activity_type'] ?? '';
                                        if ($type === 'Login' || stripos($type, 'login') !== false) $badge_class = 'bg-primary';
                                        elseif ($type === 'Exam Started') $badge_class = 'bg-warning text-dark';
                                        elseif ($type === 'Exam Finished' || $type === 'Exam Submitted') $badge_class = 'bg-success';
                                        elseif (stripos($type, 'fail') !== false || stripos($type, 'disqualif') !== false) $badge_class = 'bg-danger';
                                        elseif (stripos($type, 'biometric') !== false || stripos($type, 'verify') !== false) $badge_class = 'bg-info text-dark';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo htmlspecialchars($type); ?>
                                        </span>
                                    </td>
                                    <td class="small"><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                                    <td class="text-nowrap">
                                        <form method="POST" onsubmit="return confirm('Delete this log entry? This cannot be undone.');">
                                            <?php echo fss_csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_log">
                                            <input type="hidden" name="log_id" value="<?php echo (int)$log['id']; ?>">
                                            <button type="submit" class="btn btn-link text-danger p-0 text-decoration-none" title="Delete log entry">
                                                <i class="fas fa-trash-can me-1"></i>Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include(__DIR__ . '/../includes/footer.php'); ?>
