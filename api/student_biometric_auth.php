<?php
/**
 * api/student_biometric_auth.php
 *
 * Student Stage-2 dual-authentication endpoint.
 *
 * Two actions:
 *   action=verify_face
 *     POST body: { descriptor: [128 floats from face-api.js], exam_id?: int, attempt_id?: int }
 *   action=verify_fingerprint
 *     POST body: { scan_token: string from SDK/hardware, exam_id?: int, attempt_id?: int }
 *
 * If exam_id or attempt_id are provided, the corresponding attempt row's
 * face_auth_status / fingerprint_auth_status columns are updated after a
 * successful verification so the exam page can re-gate.
 *
 * Success sets these session flags, which the exam instructions page and
 * exam page require before allowing entry:
 *   $_SESSION['biometric_face_verified_ts']       — timestamp
 *   $_SESSION['biometric_face_for_exam']          — exam id or 'any'
 *   $_SESSION['biometric_fingerprint_verified_ts']
 *   $_SESSION['biometric_fingerprint_for_exam']
 *
 * The session bindings are deliberately narrow: if a student verifies for
 * exam X, the flag can't be reused for exam Y (unless exam is omitted, in
 * which case 'any' is used and the exam_instructions gate can fall back to
 * a per-exam re-verify flow if desired).
 */
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/audit_log.php');
require_once(__DIR__ . '/../services/BiometricServices.php');

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Student not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw = file_get_contents('php://input');
$body = [];
if (!empty($raw)) {
    $j = json_decode($raw, true);
    if (is_array($j)) $body = $j;
}
$body = array_merge($_POST, $body);

$studentId = (int)$_SESSION['student_id'];
$action = (string)($body['action'] ?? '');
$examId    = (int)($body['exam_id']    ?? 0);
$attemptId = (int)($body['attempt_id'] ?? 0);
$bindingKey = $examId > 0 ? $examId : 'any';

try {
    $face = new FaceRecognitionService($pdo);
    $fp   = new FingerprintService($pdo);

    if ($action === 'verify_face') {
        $desc = $body['descriptor'] ?? null;
        if (!is_array($desc) || count($desc) === 0) {
            throw new RuntimeException('No face descriptor supplied');
        }
        $result = $face->verify($studentId, array_values($desc));
        if ($result['matched']) {
            $_SESSION['biometric_face_verified_ts'] = time();
            $_SESSION['biometric_face_for_exam']    = $bindingKey;
            fss_audit('student', 'face_verify_pass', 'student', $studentId, [
                'score'       => $result['score'],
                'integration' => $result['integration'],
                'exam_id'     => $examId > 0 ? $examId : null,
                'attempt_id'  => $attemptId > 0 ? $attemptId : null,
            ], $studentId);
            if ($attemptId > 0) {
                $pdo->prepare("UPDATE results SET face_auth_status = 'passed' WHERE id = ? AND student_id = ?")
                    ->execute([$attemptId, $studentId]);
            }
        } else {
            fss_audit('student', 'face_verify_fail', 'student', $studentId, [
                'score'       => $result['score'],
                'message'     => $result['message'],
                'integration' => $result['integration'],
                'exam_id'     => $examId > 0 ? $examId : null,
            ], $studentId);
        }
        echo json_encode($result + [
            'ok' => true,
            'action' => 'face',
            'session_binding' => $bindingKey,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'verify_fingerprint') {
        $token = (string)($body['scan_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('No fingerprint scan token supplied');
        }
        $result = $fp->verify($studentId, $token);
        if ($result['matched']) {
            $_SESSION['biometric_fingerprint_verified_ts'] = time();
            $_SESSION['biometric_fingerprint_for_exam']    = $bindingKey;
            fss_audit('student', 'fingerprint_verify_pass', 'student', $studentId, [
                'integration' => $result['integration'],
                'exam_id'     => $examId > 0 ? $examId : null,
                'attempt_id'  => $attemptId > 0 ? $attemptId : null,
            ], $studentId);
            if ($attemptId > 0) {
                $pdo->prepare("UPDATE results SET fingerprint_auth_status = 'passed' WHERE id = ? AND student_id = ?")
                    ->execute([$attemptId, $studentId]);
            }
        } else {
            fss_audit('student', 'fingerprint_verify_fail', 'student', $studentId, [
                'message'     => $result['message'],
                'integration' => $result['integration'],
                'exam_id'     => $examId > 0 ? $examId : null,
            ], $studentId);
        }
        echo json_encode($result + [
            'ok' => true,
            'action' => 'fingerprint',
            'session_binding' => $bindingKey,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false, 'matched' => false,
        'error' => $e->getMessage(),
        'action' => $action,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
