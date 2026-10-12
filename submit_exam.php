<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/audit_log.php');

if (!isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}

$student_id = (int)$_SESSION['student_id'];
$exam_id = (int)($_POST['exam_id'] ?? 0);
$student_answers = $_POST['answers'] ?? [];
$attempt_id = (int)($_POST['attempt_id'] ?? 0);
$auto_reason = $_POST['auto_submit_reason'] ?? null; // 'disqualified' | 'timeout' | null (manual)
$class_id = (int)$_SESSION['class_id'];
$department = trim($_SESSION['department']);

if ($exam_id < 1) {
    header('Location: dashboard.php');
    exit;
}

// Re-validate exam access
$stmt = $pdo->prepare('
    SELECT * FROM exams
    WHERE id = ?
      AND class_id = ?
      AND (published = 1 OR published_obj = 1 OR published_theory = 1)
      AND FIND_IN_SET(?, REPLACE(departments, " ", ""))
');
$stmt->execute([$exam_id, $class_id, $department]);
$exam = $stmt->fetch();
if (!$exam) {
    header('Location: dashboard.php');
    exit;
}

// --- Authoritative attempt lock + status re-read ---
$pdo->beginTransaction();
$stmt = $pdo->prepare("SELECT * FROM results WHERE student_id = ? AND exam_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
$stmt->execute([$student_id, $exam_id]);
$existing_result = $stmt->fetch();

$max_offences = (int)($exam['max_offences'] ?? 3);
if ($max_offences < 1) $max_offences = 3;

$terminalStates = ['submitted','auto_submitted','graded','disqualified','expired'];

if (!$existing_result) {
    // Fallback: shouldn't happen but create attempt just in case (never let student lose work).
    $in = $pdo->prepare("INSERT INTO results (student_id, exam_id, start_time, status, ip_address, user_agent, offence_count) VALUES (?,?,?,?,?,?,0)");
    $in->execute([$student_id, $exam_id, date('Y-m-d H:i:s'), 'in_progress', fss_request_ip(), fss_user_agent()]);
    $attempt_id = (int)$pdo->lastInsertId();
    $existing_result = ['id' => $attempt_id, 'status' => 'in_progress', 'offence_count' => 0, 'disqualification_reason' => null];
} else {
    $attempt_id = (int)$existing_result['id'];
}

// Already terminal? NEVER overwrite a DQ with a late submission, but redirect to result cleanly.
if (in_array($existing_result['status'], $terminalStates, true)) {
    $pdo->rollBack();
    header('Location: result.php?exam_id=' . $exam_id);
    exit;
}

// --- Grading ---
$stmt = $pdo->prepare('SELECT question_id, question_source FROM exam_questions WHERE exam_id = ?');
$stmt->execute([$exam_id]);
$question_mappings = $stmt->fetchAll();

$questions = [];
$total_mcq_marks = 0;
$earned_mcq_marks = 0;
$total_mcq_questions = 0;
$correct_mcq_answers = 0;
$has_theory = false;

foreach ($question_mappings as $mapping) {
    $src = $mapping['question_source'] ?? 'questions';
    if ($src === 'question_bank') {
        $q_stmt = $pdo->prepare("SELECT id, type, correct_answer, 1 AS marks FROM question_bank WHERE id = ?");
    } else {
        $q_stmt = $pdo->prepare("SELECT id, type, correct_answer, 1 AS marks FROM questions WHERE id = ?");
    }
    $q_stmt->execute([$mapping['question_id']]);
    $q = $q_stmt->fetch();
    if (!$q) continue;
    $qtype = $q['type'] === 'objective' ? 'mcq' : $q['type'];
    if ($qtype === 'theory') { $has_theory = true; }
    $marks = (float)($q['marks'] ?? 1);
    if ($marks <= 0) $marks = 1;
    $questions[] = ['id' => (int)$q['id'], 'type' => $qtype, 'correct_answer' => (string)($q['correct_answer'] ?? ''), 'marks' => $marks];
}

foreach ($questions as $q) {
    if ($q['type'] !== 'mcq') continue;
    $total_mcq_questions++;
    $total_mcq_marks += $q['marks'];
    $s_ans = (string)($student_answers[$q['id']] ?? '');
    if ($s_ans !== '' && $q['correct_answer'] !== '' &&
        strcasecmp($s_ans, $q['correct_answer']) === 0) {
        $correct_mcq_answers++;
        $earned_mcq_marks += $q['marks'];
    }
}

$score = $total_mcq_marks > 0 ? round(($earned_mcq_marks / $total_mcq_marks) * 100, 2) : 0.0;
$answersJson = json_encode($student_answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// --- Determine final status ---
$prev_status = (string)$existing_result['status'];
$final_status = 'submitted';
$auto_ts = null;
$disq_reason = $existing_result['disqualification_reason'] ?? null;

if ($prev_status === 'disqualified' || $auto_reason === 'disqualified') {
    $final_status = 'disqualified';
    if (empty($disq_reason)) {
        $offCount = (int)($existing_result['offence_count'] ?? 0);
        $disq_reason = "Reached maximum offence limit ($max_offences). Offence count: $offCount.";
    }
} elseif ($auto_reason === 'timeout') {
    $final_status = 'auto_submitted';
    $auto_ts = date('Y-m-d H:i:s');
} elseif (!$has_theory) {
    $final_status = 'graded';
}

$up = $pdo->prepare("
    UPDATE results
       SET score                     = ?,
           answers                   = ?,
           status                    = ?,
           submitted_at              = CURRENT_TIMESTAMP,
           total_questions           = ?,
           correct_answers           = ?,
           auto_submitted_at         = COALESCE(auto_submitted_at, ?),
           disqualification_reason   = COALESCE(disqualification_reason, ?),
           ip_address                = COALESCE(ip_address, ?),
           user_agent                = COALESCE(user_agent, ?),
           result_display_status     = CASE
                                           WHEN ? = 'graded' AND result_display_status = 'pending'
                                           THEN 'approved'
                                           ELSE result_display_status
                                       END
     WHERE id = ?
");
$up->execute([
    $score, $answersJson, $final_status,
    $total_mcq_questions, $correct_mcq_answers,
    $auto_ts,
    $disq_reason,
    fss_request_ip(), fss_user_agent(),
    $final_status,
    $attempt_id,
]);

// Also mirror session answers to session
$_SESSION['exam_answers_' . $exam_id] = is_array($student_answers) ? $student_answers : [];

$pdo->commit();

// --- Activity + Audit ---
try {
    $exam_name_stmt = $pdo->prepare("SELECT title FROM exams WHERE id = ? LIMIT 1");
    $exam_name_stmt->execute([$exam_id]);
    $exam_name = (string)$exam_name_stmt->fetchColumn();

    $actTypeMap = [
        'disqualified'  => ['Exam Disqualified', 'exam_disqualified'],
        'auto_submitted'=> ['Exam Auto Submitted', 'exam_auto_submitted'],
        'graded'        => ['Exam Finished', 'exam_submitted'],
        'submitted'     => ['Exam Finished', 'exam_submitted'],
    ];
    [$actName, $auditAction] = $actTypeMap[$final_status] ?? ['Exam Finished', 'exam_submitted'];

    $log_stmt = $pdo->prepare("INSERT INTO student_activity_log (student_id, activity_type, details, login_time) VALUES (?,?,?,CURRENT_TIMESTAMP)");
    $log_stmt->execute([$student_id, $actName, $actName . ': ' . $exam_name . ' (Score: ' . $score . '%, Status: ' . $final_status . ')']);

    fss_audit('student', $auditAction, 'exam_attempt', $attempt_id, [
        'exam_id'              => $exam_id,
        'score'                => $score,
        'total_mcq_questions'  => $total_mcq_questions,
        'correct_mcq_answers'  => $correct_mcq_answers,
        'final_status'         => $final_status,
        'offence_count'        => (int)($existing_result['offence_count'] ?? 0),
        'max_offences'         => $max_offences,
        'disqualification_reason' => $disq_reason,
    ], $student_id);
} catch (Throwable $_) { /* never crash on audit failure */ }

// Clear session-held biometric flags for THIS exam specifically so next exam must re-auth.
unset($_SESSION['biometric_face_for_exam'], $_SESSION['biometric_face_verified_ts'],
      $_SESSION['biometric_fingerprint_for_exam'], $_SESSION['biometric_fingerprint_verified_ts']);

header('Location: result.php?exam_id=' . $exam_id);
exit;
?>
