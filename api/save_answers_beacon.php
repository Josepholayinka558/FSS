<?php
/**
 * api/save_answers_beacon.php
 *
 * Receives periodic answer autosave (called via navigator.sendBeacon during exam.js on every input change + interval.
 * Writes answers JSON into results.answers so the disqualification auto_submit_partial can grade
 * picks up the latest answers.
 *
 * Security:
 *   - Student must be authenticated (session student_id)
 *   - Attempt must belong to that student
 *   - Attempt must not be in a terminal state
 */
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');

header('Content-Type: text/plain');

if (empty($_SESSION['student_id'])) {
    http_response_code(401);
    echo 'NA';
    exit;
}
$studentId = (int)$_SESSION['student_id'];

$raw = file_get_contents('php://input');
if (!$raw) {
    // Beacon might also be sent as multipart/form-data via fetch fallback; accept $_POST also.
    if (isset($_POST['attempt_id'])) {
        $body = $_POST;
    } else {
        http_response_code(400); echo 'EMPTY'; exit;
    }
} else {
    $body = @json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400); echo 'BAD'; exit;
    }
}

$attemptId = (int)($body['attempt_id'] ?? 0);
$examId    = (int)($body['exam_id'] ?? 0);
$answers   = $body['answers'] ?? null;

if ($attemptId < 1 || $examId < 1 || !is_array($answers)) {
    http_response_code(400); echo 'BAD'; exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id, student_id, status, exam_id FROM results WHERE id = ? FOR UPDATE");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    if (!$attempt) { $pdo->rollBack(); http_response_code(404); echo 'NO_ATTEMPT'; exit; }
    if ((int)$attempt['student_id'] !== $studentId) { $pdo->rollBack(); http_response_code(403); echo 'NOT_OWNER'; exit; }
    if ((int)$attempt['exam_id'] !== $examId) { $pdo->rollBack(); http_response_code(400); echo 'EXAM_MISMATCH'; exit; }

    $terminal = ['submitted','auto_submitted','graded','disqualified','expired'];
    if (in_array($attempt['status'], $terminal, true)) {
        $pdo->rollBack();
        echo 'TERMINAL'; exit;
    }

    $answersJson = json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pdo->prepare("UPDATE results SET answers = ? WHERE id = ?")
        ->execute([$answersJson, $attemptId]);
    $pdo->commit();

    // Also stash in session for quick fallback if DB write interrupted before disqualification.
    $_SESSION['exam_answers_' . $examId] = $answers;

    echo 'OK';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo 'ERR';
}
