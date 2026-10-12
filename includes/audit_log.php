<?php
/**
 * Universal audit-log writer.
 * Writes to audit_log table. Also writes a compact row into the
 * existing legacy student_activity_log for backwards compatibility
 * when the actor is a student.
 */
require_once(__DIR__ . '/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');

function fss_audit(
    string $actor_type,
    string $action,
    ?string $entity_type = null,
    ?int $entity_id = null,
    ?array $details = null,
    ?int $actor_id = null
): void {
    global $pdo;

    if ($actor_id === null) {
        if ($actor_type === 'student' && isset($_SESSION['student_id'])) {
            $actor_id = (int)$_SESSION['student_id'];
        } elseif ($actor_type === 'admin' && isset($_SESSION['admin_id'])) {
            $actor_id = (int)$_SESSION['admin_id'];
        } else {
            $actor_id = 0;
        }
    }

    $detailsJson = $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, details, ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $actor_type,
            $actor_id,
            $action,
            $entity_type,
            $entity_id,
            $detailsJson,
            fss_request_ip(),
            fss_user_agent(),
        ]);
    } catch (Throwable $e) {
        // Never crash the request because audit-log failed.
        error_log('FSS audit_log failure: ' . $e->getMessage());
    }

    // Mirror student-centric events into legacy student_activity_log.
    if ($actor_type === 'student' && $actor_id > 0) {
        $legacyMap = [
            'student_login'         => 'Login',
            'student_login_failed'  => 'Login Failed',
            'exam_started'          => 'Exam Started',
            'exam_submitted'        => 'Exam Finished',
            'exam_auto_submitted'   => 'Exam Auto Submitted',
            'exam_disqualified'     => 'Exam Disqualified',
            'offence_recorded'      => 'Offence Recorded',
            'face_verify_pass'      => 'Face Verify Pass',
            'face_verify_fail'      => 'Face Verify Fail',
            'fingerprint_verify_pass'=> 'Fingerprint Verify Pass',
            'fingerprint_verify_fail'=> 'Fingerprint Verify Fail',
        ];
        if (isset($legacyMap[$action])) {
            try {
                $s = $pdo->prepare("SELECT matric_number FROM students WHERE id = ? LIMIT 1");
                $s->execute([$actor_id]);
                $row = $s->fetch();
                $matric = $row['matric_number'] ?? 'UNKNOWN';
                $desc = $legacyMap[$action];
                if ($entity_type === 'exam_attempt' && $entity_id > 0) {
                    $e = $pdo->prepare("SELECT e.title FROM results r JOIN exams e ON e.id=r.exam_id WHERE r.id=? LIMIT 1");
                    $e->execute([$entity_id]);
                    $er = $e->fetch();
                    if ($er) {
                        $desc .= ' — ' . $er['title'];
                    }
                }
                $i = $pdo->prepare("INSERT INTO student_activity_log (student_id, activity_type, details, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
                $i->execute([$actor_id, $legacyMap[$action] ?? $action, $desc]);
            } catch (Throwable $e) {
                error_log('FSS legacy activity mirror failure: ' . $e->getMessage());
            }
        }
    }
}

/**
 * Simple rate-limiter: count identical $action for this actor within window.
 * Returns true if $max was exceeded.
 */
function fss_rate_limit_exceeded(string $actor_type, ?int $actor_id, string $action, int $seconds = 60, int $max = 10): bool
{
    global $pdo;
    $actorId = $actor_id ?? 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM audit_log
            WHERE actor_type = ? AND actor_id = ? AND action = ?
              AND created_at >= NOW() - INTERVAL ? SECOND
              AND ip_address = ?
        ");
        $stmt->execute([$actor_type, $actorId, $action, $seconds, fss_request_ip()]);
        $count = (int)$stmt->fetchColumn();
        return $count >= $max;
    } catch (Throwable $e) {
        return false;
    }
}
