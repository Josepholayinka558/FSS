<?php
/**
 * api/record_offence.php
 * -------------------------------------------------------------------
 * AUTHORITATIVE offence-recording endpoint.
 *
 * RULES (security-critical — DO NOT weaken):
 *   1. Only accessible to an authenticated student whose session owns
 *      the specified attempt (exam_attempt = results row).
 *   2. offence_number is NEVER accepted from the client. Server
 *      computes it: COUNT(prior rows for attempt) + 1.
 *   3. Same (attempt_id, offence_type) within
 *      FSS_OFFENCE_DEDUP_SECONDS is silently ignored to prevent a
 *      single user action from spamming the count.
 *   4. When offence_number reaches exams.max_offences:
 *        - attempt.status = 'disqualified'
 *        - attempt.disqualified_at = NOW()
 *        - attempt.disqualification_reason populated
 *        - Student's in-progress answers are saved (partial score)
 *        - Audit entries written
 *   5. A student whose attempt is already in a terminal state
 *      (submitted / auto_submitted / disqualified / graded / expired)
 *      is returned an immediate lock response; no new offence is
 *      recorded, but the client UI still gets instructed to lock.
 * -------------------------------------------------------------------
 * Accepted request: POST (application/x-www-form-urlencoded or JSON)
 *   {
 *     attempt_id:     int (required — the results.id)
 *     offence_type:   string enum (required)
 *     description:    string (optional)
 *     duration_ms:    int (optional, only for timed events like no_face)
 *     client_payload: object (optional — stored for audit only, never trusted)
 *   }
 * Response: JSON
 *   {
 *     ok:              bool
 *     offenceRecorded: bool   // FALSE if dedupe-skipped or already terminal
 *     offenceCount:    int    // server-authoritative count AFTER processing
 *     maxOffences:     int
 *     disqualified:    bool
 *     disqualifyReason:string|null
 *     attemptStatus:   string
 *     offenceId:       int|null
 *   }
 */
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/audit_log.php');
require_once(__DIR__ . '/../services/config.php');

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST required', 405);
}
if (empty($_SESSION['student_id'])) {
    fail('Not authenticated', 401);
}
$studentId = (int)$_SESSION['student_id'];

// Accept either JSON or form body.
$raw = file_get_contents('php://input');
$body = [];
if (!empty($raw)) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $body = $json;
    }
}
$body = array_merge($_POST, $body);

$attemptId   = (int)($body['attempt_id']   ?? 0);
$offenceType = (string)($body['offence_type'] ?? '');
$description = trim((string)($body['description'] ?? ''));
$durationMs  = (int)($body['duration_ms'] ?? 0);
$clientPayload = $body['client_payload'] ?? null;

if ($attemptId < 1) {
    fail('Missing attempt_id');
}
$validTypes = ['tab_switch','visibility_change','fullscreen_exit','camera_disabled','no_face','face_mismatch','multiple_faces','other'];
if (!in_array($offenceType, $validTypes, true)) {
    fail('Invalid offence_type');
}

$allowedTerminalTransitions = [
    'tab_switch'          => true,
    'visibility_change'   => true,
    'fullscreen_exit'     => true,
    'camera_disabled'     => true,
    'no_face'             => true,
    'face_mismatch'       => true,
    'multiple_faces'      => true,
    'other'               => true,
];

$pdo->beginTransaction();
try {
    // Lock the attempt row for the duration of this transaction.
    $stmt = $pdo->prepare("
        SELECT r.*, e.max_offences, e.require_face_auth, e.require_fingerprint_auth
          FROM results r
          JOIN exams   e ON e.id = r.exam_id
         WHERE r.id = ? LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        $pdo->rollBack();
        fail('Unknown attempt', 404);
    }
    if ((int)$attempt['student_id'] !== $studentId) {
        $pdo->rollBack();
        fail('Attempt does not belong to current student', 403);
    }

    $maxOffences = (int)($attempt['max_offences'] ?? 3);
    if ($maxOffences < 1) {
        $maxOffences = 3;
    }

    $terminalStates = ['submitted','auto_submitted','graded','disqualified','expired'];
    $alreadyTerminal = in_array($attempt['status'], $terminalStates, true);

    // Current server-authoritative count BEFORE we insert anything.
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_offences WHERE attempt_id = ?");
    $countStmt->execute([$attemptId]);
    $currentCount = (int)$countStmt->fetchColumn();

    $offenceRecorded = false;
    $offenceId = null;
    $disqualifyReason = null;
    $disqualifiedNow = false;

    if ($alreadyTerminal) {
        // Do NOT insert new offence. Just report the stable state back.
        $responseCount = $currentCount;
        if ($attempt['status'] === 'disqualified') {
            $disqualifyReason = $attempt['disqualification_reason'] ?? 'Disqualified';
        }
    } else {
        // Dedupe window check.
        $dedup = $pdo->prepare("
            SELECT id FROM exam_offences
             WHERE attempt_id = ? AND offence_type = ?
               AND created_at >= NOW() - INTERVAL ? SECOND
             ORDER BY id DESC LIMIT 1
        ");
        $dedup->execute([$attemptId, $offenceType, FSS_OFFENCE_DEDUP_SECONDS]);
        $recent = $dedup->fetch();

        if ($recent) {
            $responseCount = $currentCount;
        } else {
            $newNumber = $currentCount + 1;
            $ins = $pdo->prepare("
                INSERT INTO exam_offences
                    (attempt_id, student_id, exam_id, offence_type, description, offence_number,
                     ip_address, user_agent, client_payload)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $payloadJson = $clientPayload === null ? null : (is_string($clientPayload) ? $clientPayload : json_encode($clientPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $humanDesc = $description !== '' ? $description : $offenceType;
            if ($durationMs > 0) {
                $humanDesc .= ' (' . round($durationMs / 1000, 1) . 's)';
            }
            $ins->execute([
                $attemptId,
                $studentId,
                (int)$attempt['exam_id'],
                $offenceType,
                $humanDesc,
                $newNumber,
                fss_request_ip(),
                fss_user_agent(),
                $payloadJson,
            ]);
            $offenceId = (int)$pdo->lastInsertId();
            $offenceRecorded = true;
            $responseCount = $newNumber;

            // Update denormalized counter on attempt row.
            $pdo->prepare("UPDATE results SET offence_count = ? WHERE id = ?")
                ->execute([$newNumber, $attemptId]);

            fss_audit('student', 'offence_recorded', 'exam_attempt', $attemptId, [
                'offence_id'     => $offenceId,
                'offence_type'   => $offenceType,
                'offence_number' => $newNumber,
                'description'    => $humanDesc,
                'exam_id'        => (int)$attempt['exam_id'],
            ], $studentId);

            // ---- DISQUALIFICATION CHECK ----
            if ($newNumber >= $maxOffences) {
                $disqualifyReason = "Exceeded maximum allowed offences ($maxOffences). Offence #$newNumber was: $humanDesc.";
                $score = auto_submit_partial($pdo, $attemptId, $attempt, 'disqualified');

                $pdo->prepare("
                    UPDATE results
                       SET status = 'disqualified',
                           disqualification_reason = ?,
                           disqualified_at = CURRENT_TIMESTAMP,
                           score = CASE WHEN score IS NULL OR score = '' THEN ? ELSE score END,
                           total_questions = CASE WHEN (total_questions IS NULL OR total_questions = 0) THEN ? ELSE total_questions END,
                           correct_answers = CASE WHEN (correct_answers IS NULL OR correct_answers = 0) THEN ? ELSE correct_answers END,
                           ip_address = COALESCE(ip_address, ?),
                           user_agent = COALESCE(user_agent, ?)
                     WHERE id = ?
                ")->execute([
                    $disqualifyReason,
                    $score['score'],
                    $score['total_questions'],
                    $score['correct_answers'],
                    fss_request_ip(),
                    fss_user_agent(),
                    $attemptId,
                ]);

                $pdo->prepare("
                    INSERT INTO student_activity_log (student_id, activity_type, details, login_time)
                    VALUES (?, 'Exam Disqualified', ?, CURRENT_TIMESTAMP)
                ")->execute([$studentId, 'Exam attempt #' . $attemptId . ' — ' . $disqualifyReason]);

                fss_audit('student', 'exam_disqualified', 'exam_attempt', $attemptId, [
                    'exam_id' => (int)$attempt['exam_id'],
                    'offence_count' => $newNumber,
                    'max_offences' => $maxOffences,
                    'reason' => $disqualifyReason,
                    'final_score' => $score['score'],
                ], $studentId);

                $disqualifiedNow = true;
            }
        }
    }

    // Re-read status so client gets post-DQ state.
    $statusStmt = $pdo->prepare("SELECT status, disqualification_reason, offence_count FROM results WHERE id = ? LIMIT 1");
    $statusStmt->execute([$attemptId]);
    $final = $statusStmt->fetch();

    $pdo->commit();

    echo json_encode([
        'ok'                => true,
        'offenceRecorded'   => $offenceRecorded,
        'offenceCount'      => (int)($final['offence_count'] ?? $responseCount),
        'maxOffences'       => $maxOffences,
        'disqualified'      => ($final['status'] ?? '') === 'disqualified',
        'disqualifiedNow'   => $disqualifiedNow,
        'disqualifyReason'  => $final['disqualification_reason'] ?? $disqualifyReason,
        'attemptStatus'     => $final['status'] ?? $attempt['status'],
        'offenceId'         => $offenceId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('record_offence failure: ' . $e->getMessage());
    fail('Server error processing offence', 500);
}

/**
 * Replicate the MCQ-grading logic from submit_exam.php against the
 * student's most recently submitted / in-memory answers, so that a
 * disqualified attempt still receives a partial score (for audit /
 * appeal / transparency).  Returns [score, total_questions, correct_answers].
 */
function auto_submit_partial(PDO $pdo, int $attemptId, array $attempt, string $newStatus): array
{
    $studentId = (int)$attempt['student_id'];
    $examId    = (int)$attempt['exam_id'];

    // Answers were stashed in results.answers for this attempt by the
    // existing save-answer flow. If JSON present, grade it; else 0.
    $answersStmt = $pdo->prepare("SELECT answers FROM results WHERE id = ? LIMIT 1");
    $answersStmt->execute([$attemptId]);
    $answersRaw = $answersStmt->fetchColumn();
    $studentAnswers = [];
    if (!empty($answersRaw)) {
        $decoded = @json_decode($answersRaw, true);
        if (is_array($decoded)) {
            $studentAnswers = $decoded;
        }
    }
    // Fallback: merge in anything stashed in $_SESSION for this attempt.
    if (!empty($_SESSION['exam_answers_' . $examId]) && is_array($_SESSION['exam_answers_' . $examId])) {
        $studentAnswers = $studentAnswers + $_SESSION['exam_answers_' . $examId];
    }

    $eq = $pdo->prepare("
        SELECT eq.question_id, eq.question_source,
               CASE eq.question_source
                  WHEN 'questions'      THEN q.correct_answer
                  WHEN 'question_bank'  THEN qb.correct_answer
               END AS correct_answer,
               CASE eq.question_source
                  WHEN 'questions'      THEN 1
                  WHEN 'question_bank'  THEN 1
               END AS marks
          FROM exam_questions eq
          LEFT JOIN questions q     ON q.id  = eq.question_id AND eq.question_source = 'questions'
          LEFT JOIN question_bank qb ON qb.id = eq.question_id AND eq.question_source = 'question_bank'
         WHERE eq.exam_id = ?
    ");
    $eq->execute([$examId]);
    $rows = $eq->fetchAll();

    $total = count($rows);
    $correct = 0;
    $marksEarned = 0;
    $marksTotal  = 0;
    foreach ($rows as $r) {
        $marks = (float)($r['marks'] ?? 1);
        if ($marks <= 0) $marks = 1;
        $marksTotal += $marks;
        $qid = (int)$r['question_id'];
        if (!empty($r['correct_answer']) &&
            isset($studentAnswers[$qid]) &&
            strcasecmp((string)$studentAnswers[$qid], (string)$r['correct_answer']) === 0
        ) {
            $correct++;
            $marksEarned += $marks;
        }
    }
    $score = $marksTotal > 0 ? round(($marksEarned / $marksTotal) * 100, 2) : 0.0;

    return [
        'score'             => $score,
        'total_questions'   => $total,
        'correct_answers'   => $correct,
    ];
}
