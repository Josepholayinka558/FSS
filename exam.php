<?php
require_once(__DIR__ . '/includes/security_bootstrap.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/includes/audit_log.php');

if (!isset($_SESSION['student_id'])) {
    header('Location: student_login.php');
    exit;
}

$exam_id = (int)($_GET['exam_id'] ?? 0);
if ($exam_id < 1) {
    header('Location: dashboard.php');
    exit;
}

$student_id = (int)$_SESSION['student_id'];
$class_id   = (int)$_SESSION['class_id'];
$department = trim($_SESSION['department']);

// Fetch exam details
$stmt = $pdo->prepare('
    SELECT e.*, s.name as subject_name
    FROM exams e
    JOIN subjects s ON e.subject_id = s.id
    WHERE e.id = ?
      AND e.class_id = ?
      AND (e.published = 1 OR e.published_obj = 1 OR e.published_theory = 1)
      AND FIND_IN_SET(?, REPLACE(e.departments, " ", ""))
');
$stmt->execute([$exam_id, $class_id, $department]);
$exam = $stmt->fetch();

if (!$exam) {
    header('Location: dashboard.php');
    exit;
}

// ---- BIOMETRIC RE-GATE (server-side, on every exam load) ----
$needFace = (bool)($exam['require_face_auth'] ?? 1);
$needFp   = (bool)($exam['require_fingerprint_auth'] ?? 1);
$faceOk = !$needFace || (
    isset($_SESSION['biometric_face_for_exam']) &&
    ($_SESSION['biometric_face_for_exam'] === $exam_id || $_SESSION['biometric_face_for_exam'] === 'any') &&
    !empty($_SESSION['biometric_face_verified_ts']) &&
    (time() - (int)$_SESSION['biometric_face_verified_ts'] < 600)
);
$fpOk = !$needFp || (
    isset($_SESSION['biometric_fingerprint_for_exam']) &&
    ($_SESSION['biometric_fingerprint_for_exam'] === $exam_id || $_SESSION['biometric_fingerprint_for_exam'] === 'any') &&
    !empty($_SESSION['biometric_fingerprint_verified_ts']) &&
    (time() - (int)$_SESSION['biometric_fingerprint_verified_ts'] < 600)
);
if (!$faceOk || !$fpOk) {
    header('Location: biometric_auth.php?exam_id=' . $exam_id);
    exit;
}

// Fetch questions
$stmt = $pdo->prepare('SELECT * FROM exam_questions WHERE exam_id = ?');
$stmt->execute([$exam_id]);
$mappings = $stmt->fetchAll();

$obj_questions = [];
$theory_questions = [];
foreach ($mappings as $map) {
    if ($map['question_source'] === 'question_bank') {
        $q_stmt = $pdo->prepare('SELECT *, question as question_text, CASE WHEN type="objective" THEN "mcq" ELSE "theory" END as type FROM question_bank WHERE id = ?');
    } else {
        $q_stmt = $pdo->prepare('SELECT *, "" as image FROM questions WHERE id = ?');
    }
    $q_stmt->execute([$map['question_id']]);
    $q = $q_stmt->fetch();
    if ($q) {
        if ($q['type'] === 'mcq' && ($exam['published'] || $exam['published_obj'])) {
            $obj_questions[] = $q;
        } elseif ($q['type'] === 'theory' && ($exam['published'] || $exam['published_theory'])) {
            $theory_questions[] = $q;
        }
    }
}

$sortFunc = function($a, $b) {
    $numA = $a['question_number'] ?? $a['id'];
    $numB = $b['question_number'] ?? $b['id'];
    return $numA - $numB;
};
usort($obj_questions, $sortFunc);
usort($theory_questions, $sortFunc);

if (empty($obj_questions) && empty($theory_questions)) {
    header('Location: dashboard.php?error=not_published');
    exit;
}

// =================================================================
// SERVER-SIDE DQ / TERMINAL STATE RE-GATE ON EVERY PAGE LOAD
// -----------------------------------------------------------------
// This is the non-bypassable authority: even if a student deletes
// all JS client-side, refreshes, opens a new tab, or edits DOM,
// the PHP server still re-locks them when they load this page.
// =================================================================
$stmt = $pdo->prepare('SELECT * FROM results WHERE student_id = ? AND exam_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$student_id, $exam_id]);
$attempt = $stmt->fetch();

$terminalStates = ['submitted','auto_submitted','graded','disqualified','expired'];
$attempt_id = null;

if ($attempt) {
    $attempt_id = (int)$attempt['id'];
    if (in_array($attempt['status'], $terminalStates, true)) {
        if ($attempt['status'] === 'disqualified') {
            // Show DQ notice page; do NOT render any questions.
            $dqReason = $attempt['disqualification_reason'] ?: 'Exceeded maximum allowed offences.';
            $dqCount  = (int)($attempt['offence_count'] ?? 0);
            $dqMax    = (int)($exam['max_offences'] ?? 3);
            $dqTime   = $attempt['disqualified_at'] ?? '';
            include(__DIR__ . '/includes/header.php');
            echo '<div class="container py-5"><div class="row justify-content-center"><div class="col-lg-8">';
            echo '<div class="card border-danger shadow-lg">';
            echo '<div class="card-header bg-danger text-white text-center p-4"><h3 class="mb-0"><i class="fas fa-ban me-2"></i>EXAMINATION DISQUALIFIED</h3></div>';
            echo '<div class="card-body p-5 text-center">';
            echo '<div style="font-size: 5rem; color: var(--color-danger); margin-bottom: 1rem;"><i class="fas fa-circle-xmark"></i></div>';
            echo '<h4 class="mb-3">This examination attempt has been automatically terminated.</h4>';
            echo '<div class="table-responsive mt-4"><table class="table table-bordered align-middle"><tbody>';
            echo '<tr><th style="width: 40%;">Student</th><td>' . htmlspecialchars($_SESSION['student_name']) . ' (' . htmlspecialchars($_SESSION['matric_number']) . ')</td></tr>';
            echo '<tr><th>Course</th><td>' . htmlspecialchars($exam['course_code'] . ' — ' . $exam['subject_name']) . '</td></tr>';
            echo '<tr><th>Offence Count</th><td class="fw-bold text-danger">' . $dqCount . ' / ' . $dqMax . '</td></tr>';
            echo '<tr><th>Date / Time</th><td>' . htmlspecialchars($dqTime) . '</td></tr>';
            echo '<tr><th>Reason</th><td>' . htmlspecialchars($dqReason) . '</td></tr>';
            echo '</tbody></table></div>';
            echo '<div class="alert alert-warning mt-4 text-start" role="alert"><i class="fas fa-circle-info me-2"></i>This disqualification is final and recorded in the audit log. The exam attempt was automatically submitted at the time of termination with partial answers preserved. If you believe this was in error, contact your examinations officer with your matric number and course code.</div>';
            echo '<div class="mt-4"><a href="dashboard.php" class="btn btn-primary btn-lg px-5"><i class="fas fa-arrow-left me-2"></i>Return to Dashboard</a></div>';
            echo '</div></div></div></div></div>';
            include(__DIR__ . '/includes/footer.php');
            exit;
        }
        // Already submitted / graded / expired: redirect to dashboard.
        header('Location: dashboard.php?error=already_taken');
        exit;
    }
    // Attempt is active (in_progress / not_started)
    $start_time = strtotime($attempt['start_time']);
    $initial_offence_count = (int)($attempt['offence_count'] ?? 0);
    $attemptIdForJs = (int)$attempt['id'];
} else {
    // New attempt
    $start_time = time();
    $initial_offence_count = 0;
    $faceStatus = $needFace ? 'passed' : 'not_required';
    $fpStatus   = $needFp   ? 'passed' : 'not_required';
    $stmt = $pdo->prepare('INSERT INTO results (student_id, exam_id, start_time, status, ip_address, user_agent, face_auth_status, fingerprint_auth_status, offence_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
    $stmt->execute([
        $student_id, $exam_id, date('Y-m-d H:i:s', $start_time),
        'in_progress', fss_request_ip(), fss_user_agent(), $faceStatus, $fpStatus,
    ]);
    $attempt_id = (int)$pdo->lastInsertId();
    $attemptIdForJs = $attempt_id;

    $pdo->prepare("INSERT INTO student_activity_log (student_id, activity_type, details, login_time) VALUES (?, 'Exam Started', ?, CURRENT_TIMESTAMP)")
        ->execute([$student_id, "Started Exam: " . $exam['title'] . " (attempt #" . $attempt_id . ")"]);

    fss_audit('student', 'exam_started', 'exam_attempt', $attempt_id, [
        'exam_id' => $exam_id,
        'face_required' => $needFace,
        'fingerprint_required' => $needFp,
    ], $student_id);
}

// Stable MCQ shuffle per (registration_number + exam_id)
if (!empty($obj_questions)) {
    $reg_stmt = $pdo->prepare('SELECT registration_number FROM students WHERE id = ?');
    $reg_stmt->execute([$student_id]);
    $registration_number = (string) ($reg_stmt->fetchColumn() ?: $student_id);
    $shuffle_seed = $registration_number . '-' . $exam_id;
    usort($obj_questions, function ($a, $b) use ($shuffle_seed) {
        $hashA = md5($shuffle_seed . '-' . ($a['question_number'] ?? $a['id']));
        $hashB = md5($shuffle_seed . '-' . ($b['question_number'] ?? $b['id']));
        return strcmp($hashA, $hashB);
    });
}

$duration_seconds = (int)$exam['duration'] * 60;
$end_time = $start_time + $duration_seconds;
$remaining_seconds = max(0, $end_time - time());
$max_offences = (int)($exam['max_offences'] ?? 3);
if ($max_offences < 1) $max_offences = 3;
$require_fullscreen = (bool)($exam['require_fullscreen'] ?? 1);
$require_face_monitoring = $needFace;

// Pre-populate attemptIdForJs into session so record_offence can re-verify ownership.
$_SESSION['active_attempt_id_' . $exam_id] = $attemptIdForJs;

include(__DIR__ . '/includes/header.php');
?>

<nav class="navbar navbar-dark bg-primary sticky-top shadow-sm py-2">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a class="navbar-brand d-flex align-items-center mb-0" href="#">
            <img src="assets/kingsparkl.jpg" alt="FSS OYO Logo" style="height: 30px; border-radius: 5px;" class="me-2" onerror="this.style.display='none'">
            <span class="fs-6 fs-md-5">FSS OYO CBT</span>
        </a>
        <div class="order-3 order-md-2 d-flex align-items-center gap-2 flex-wrap justify-content-center" style="min-width: 260px;">
            <div id="offenceMeter" class="rounded px-3 py-1 text-white fw-bold" style="background: var(--color-success); font-size: 0.9rem;">
                <i class="fas fa-gauge-high me-1"></i>
                Offences: <span id="offenceCount">0</span> / <?php echo $max_offences; ?>
                <span class="badge bg-white text-dark ms-2 opacity-75" id="offenceStatus" style="font-size: 0.68rem;">ACTIVE</span>
            </div>
            <div id="timer" class="text-white fw-bold fs-6 fs-md-4 bg-dark rounded px-3 py-1">
                Time: <span id="time_left">00:00:00</span>
            </div>
        </div>
        <div class="text-white small me-md-3 order-2 order-md-3 text-center text-md-start">
             <span class="d-none d-sm-inline">Welcome, </span><?php echo htmlspecialchars($_SESSION['student_name']); ?>
             <small class="opacity-75">(<?php echo htmlspecialchars($_SESSION['matric_number']); ?>)</small>
        </div>
    </div>
</nav>

<!-- DQ Overlay (JS-triggered; server also re-renders a full DQ page on reload.) -->
<div id="dqOverlay" class="modal fade" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-danger">
      <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-ban me-2"></i>EXAMINATION TERMINATED — DISQUALIFIED</h5></div>
      <div class="modal-body p-4">
        <div class="text-center mb-3" style="color: var(--color-danger); font-size: 3rem;"><i class="fas fa-circle-xmark"></i></div>
        <p class="text-center">You have reached the maximum allowed offences. Your examination has been <strong>automatically submitted and disqualified</strong>.</p>
        <div class="table-responsive mt-4"><table class="table table-sm align-middle"><tbody>
          <tr><th style="width: 35%;">Final Offence Count</th><td class="fw-bold text-danger" id="dqFinalCount">0 / <?php echo $max_offences; ?></td></tr>
          <tr><th>Reason</th><td id="dqReason">—</td></tr>
        </tbody></table></div>
        <div class="alert alert-warning mt-3 small"><i class="fas fa-circle-info me-1"></i>This decision is recorded server-side and cannot be undone from this browser.</div>
      </div>
      <div class="modal-footer">
        <a href="dashboard.php" class="btn btn-danger px-4">Return to Dashboard</a>
      </div>
    </div>
  </div>
</div>

<div class="container mt-4 mb-5 pb-5">
    <div class="row">
        <div class="col-md-9 mx-auto">
            <h2 class="mb-1 text-center"><?php echo htmlspecialchars($exam['title']); ?></h2>
            <p class="text-center text-muted mb-4">
                <?php echo htmlspecialchars($exam['course_code']); ?> &mdash; <?php echo htmlspecialchars($exam['subject_name']); ?>
                <?php if (!empty($exam['exam_type'])) echo ' · ' . htmlspecialchars($exam['exam_type']); ?>
            </p>
            <hr>

            <form id="examForm" action="submit_exam.php" method="POST">
                <input type="hidden" name="exam_id" value="<?php echo (int)$exam_id; ?>">
                <input type="hidden" name="attempt_id" value="<?php echo (int)$attemptIdForJs; ?>">

                <?php if (!empty($obj_questions)): ?>
                    <div class="alert alert-dark fw-bold mb-4">SECTION A: OBJECTIVE QUESTIONS</div>
                    <?php foreach ($obj_questions as $index => $q): $qid = (int)$q['id']; ?>
                        <div class="card shadow-sm border-0 mb-4 question-card" data-qid="<?php echo $qid; ?>">
                            <div class="card-header bg-white border-0 pt-4">
                                <h5 class="mb-0 text-primary">Question <?php echo $q['question_number'] ?: ($index + 1); ?></h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="fs-5 mb-4"><?php echo htmlspecialchars($q['question_text']); ?></p>
                                <?php if (!empty($q['image'])): ?>
                                    <div class="mb-4 text-center">
                                        <img src="uploads/questions/<?php echo htmlspecialchars($q['image']); ?>" alt="Question Diagram" class="img-fluid rounded shadow-sm border" style="max-height: 400px;">
                                    </div>
                                <?php endif; ?>
                                <div class="options-container">
                                    <?php foreach (['A','B','C','D'] as $optKey): $prop = 'option_' . strtolower($optKey); if (empty($q[$prop])) continue; ?>
                                    <div class="form-check mb-3 p-3 border rounded">
                                        <input class="form-check-input ms-0 me-3" type="radio" name="answers[<?php echo $qid; ?>]" id="q<?php echo $qid . $optKey; ?>" value="<?php echo $optKey; ?>" onchange="window.__examAutosave && __examAutosave()">
                                        <label class="form-check-label w-100" for="q<?php echo $qid . $optKey; ?>"><?php echo $optKey; ?>. <?php echo htmlspecialchars($q[$prop]); ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($theory_questions)): ?>
                    <div class="alert alert-dark fw-bold mb-4 mt-5">SECTION B: THEORY QUESTIONS</div>
                    <?php foreach ($theory_questions as $index => $q): $qid = (int)$q['id']; ?>
                        <div class="card shadow-sm border-0 mb-4 question-card" data-qid="<?php echo $qid; ?>">
                            <div class="card-header bg-white border-0 pt-4">
                                <h5 class="mb-0 text-primary">Question <?php echo $q['question_number'] ?: ($index + 1); ?></h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="fs-5 mb-4"><?php echo htmlspecialchars($q['question_text']); ?></p>
                                <?php if (!empty($q['image'])): ?>
                                    <div class="mb-4 text-center">
                                        <img src="uploads/questions/<?php echo htmlspecialchars($q['image']); ?>" alt="Question Diagram" class="img-fluid rounded shadow-sm border" style="max-height: 400px;">
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <textarea name="answers[<?php echo $qid; ?>]" class="form-control theory-input" rows="6" placeholder="Type your theory answer here..." oninput="window.__examAutosave && __examAutosave()"></textarea>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="text-center mt-5 mb-5">
                    <button type="submit" id="submit_btn" class="btn btn-primary btn-lg px-5 shadow-sm" onclick="return confirm('Are you sure you want to submit your exam? This action is final.')">Submit Final Answers</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.min.js"></script>
<script>
(function () {
    // ======= Constants from server =======
    const EXAM_ID         = <?php echo (int)$exam_id; ?>;
    const ATTEMPT_ID      = <?php echo (int)$attemptIdForJs; ?>;
    const MAX_OFFENCES    = <?php echo (int)$max_offences; ?>;
    const REMAINING_SEC   = <?php echo (int)$remaining_seconds; ?>;
    const REQUIRE_FS      = <?php echo $require_fullscreen ? 'true' : 'false'; ?>;
    const REQUIRE_FACE_M  = <?php echo $require_face_monitoring ? 'true' : 'false'; ?>;
    const NO_FACE_SECONDS = <?php echo (int)(($exam['require_face_auth'] ?? 1) ? 10 : 0); ?>;

    let remainingTime = REMAINING_SEC;
    let offenceCount  = <?php echo (int)$initial_offence_count; ?>;
    let disqualified  = false;
    let lastOffenceTs = {};  // offence_type -> last POSTed ms timestamp for dedup
    let lastSentPayload = 0; // ms
    let hiddenSince = null;  // ms timestamp when visibility went hidden

    const timerDisplay   = document.getElementById('time_left');
    const examForm       = document.getElementById('examForm');
    const offenceCountEl = document.getElementById('offenceCount');
    const offenceStatus  = document.getElementById('offenceStatus');
    const offenceMeter   = document.getElementById('offenceMeter');
    const dqOverlay      = new (bootstrap?.Modal || function(){})(document.getElementById('dqOverlay'), {});

    // --- Offence meter visual state ---
    function renderOffenceMeter() {
        offenceCountEl.textContent = offenceCount;
        const ratio = MAX_OFFENCES <= 0 ? 1 : (offenceCount / MAX_OFFENCES);
        offenceMeter.style.background = disqualified
            ? 'var(--color-danger)'
            : (ratio >= 0.67 ? 'var(--color-danger)' : ratio >= 0.34 ? '#d97706' : 'var(--color-success)');
        offenceStatus.textContent = disqualified ? 'DISQUALIFIED' : (ratio >= 0.67 ? 'CRITICAL' : ratio >= 0.34 ? 'WARNING' : 'ACTIVE');
        offenceStatus.className = 'badge ms-2 opacity-100' + (disqualified ? ' bg-dark text-white' : ' bg-white text-dark');
    }
    renderOffenceMeter();

    function disqualifyUi(reason, finalCount) {
        disqualified = true;
        if (finalCount != null) offenceCount = finalCount;
        renderOffenceMeter();
        document.getElementById('dqFinalCount').textContent = offenceCount + ' / ' + MAX_OFFENCES;
        document.getElementById('dqReason').textContent = reason || 'Maximum offence limit reached.';
        try { dqOverlay.show(); } catch (_) {}
        // Lock all inputs.
        document.querySelectorAll('#examForm input, #examForm textarea, #examForm button').forEach(function (el) {
            if (el.id === 'submit_btn') { el.disabled = true; el.style.display = 'none'; }
            else el.disabled = true;
        });
        // Force a form submission to preserve answers server-side immediately.
        // Submit via ajax first; if success, clear interval & disable unload guard.
        submitExamViaAjax(true).finally(function () {
            clearInterval(timerInterval);
            window.onbeforeunload = null;
        });
    }

    // --- Authoritative offence recording ---
    function recordOffence(type, description, durationMs) {
        if (disqualified) return Promise.resolve({disqualified:true});
        const now = Date.now();
        if (lastOffenceTs[type] && (now - lastOffenceTs[type]) < (3 * 1000)) {
            return Promise.resolve({deduped:true});
        }
        lastOffenceTs[type] = now;
        return fetch('api/record_offence.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                attempt_id: ATTEMPT_ID,
                offence_type: type,
                description: description || type,
                duration_ms: durationMs || 0,
                client_payload: { ua: navigator.userAgent, url: location.href }
            })
        }).then(r => r.json()).then(function (data) {
            if (!data) return data;
            if (typeof data.offenceCount === 'number') offenceCount = data.offenceCount;
            renderOffenceMeter();
            if (data.disqualified) {
                disqualifyUi(data.disqualifyReason, data.offenceCount);
            }
            return data;
        }).catch(function () { /* ignore network flutter */ });
    }

    // --- Timer ---
    function updateTimer() {
        if (disqualified) { clearInterval(timerInterval); return; }
        if (remainingTime <= 0) {
            clearInterval(timerInterval);
            timerDisplay.innerHTML = '00:00:00';
            // Time is up — submit via POST (auto_submitted).
            window.onbeforeunload = null;
            submitExamViaAjax(true).finally(function () {
                location.href = 'result.php?exam_id=' + EXAM_ID;
            });
            return;
        }
        const h = Math.floor(remainingTime / 3600);
        const m = Math.floor((remainingTime % 3600) / 60);
        const s = remainingTime % 60;
        timerDisplay.innerHTML =
            String(h).padStart(2,'0') + ':' +
            String(m).padStart(2,'0') + ':' +
            String(s).padStart(2,'0');
        if (remainingTime < 300) {
            timerDisplay.parentElement.classList.remove('bg-dark');
            timerDisplay.parentElement.classList.add('bg-danger');
        }
        remainingTime--;
    }
    updateTimer();
    const timerInterval = setInterval(updateTimer, 1000);

    // --- Autosave answers periodically (partial preservation for DQ) ---
    function collectAnswersJson() {
        const formData = new FormData(examForm);
        const out = {};
        formData.forEach(function (v, k) {
            const m = /^answers\[(\d+)\]$/.exec(k);
            if (m) out[m[1]] = v;
        });
        // Merge theory + mcq into session-stored JSON also sent to server via beacon.
        return out;
    }
    function postAnswersAutosave(force) {
        if (disqualified) return;
        const now = Date.now();
        if (!force && now - lastSentPayload < 15000) return;
        lastSentPayload = now;
        const payload = JSON.stringify({
            attempt_id: ATTEMPT_ID,
            exam_id: EXAM_ID,
            answers: collectAnswersJson()
        });
        if (navigator.sendBeacon) {
            try {
                const blob = new Blob([payload], { type: 'application/json' });
                navigator.sendBeacon('api/save_answers_beacon.php', blob);
                return;
            } catch (_) {}
        }
        fetch('api/save_answers_beacon.php', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: payload
        }).catch(function () {});
    }
    window.__examAutosave = function () { postAnswersAutosave(false); };
    setInterval(function () { postAnswersAutosave(false); }, 20000);
    setInterval(function () { postAnswersAutosave(true); }, 60000);

    function submitExamViaAjax(isTimeoutOrDq) {
        window.onbeforeunload = null;
        const formData = new FormData(examForm);
        if (isTimeoutOrDq) {
            if (!formData.has('exam_id')) formData.append('exam_id', EXAM_ID);
            if (!formData.has('attempt_id')) formData.append('attempt_id', ATTEMPT_ID);
            formData.append('auto_submit_reason', isTimeoutOrDq === true ? (disqualified ? 'disqualified' : 'timeout') : 'manual');
        }
        return fetch('submit_exam.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(r => r.text()).catch(function () {});
    }
    examForm.addEventListener('submit', function () { postAnswersAutosave(true); });

    // --- Unload guard ---
    window.onbeforeunload = function (e) {
        if (disqualified) return;
        postAnswersAutosave(true);
        (e || window.event).returnValue = 'Leaving the exam page is an offence and may disqualify you.';
        return 'Leaving the exam page is an offence and may disqualify you.';
    };

    // --- Fullscreen enforcement ---
    function enterFullscreen() {
        const el = document.documentElement;
        const fn = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
        if (fn) { try { fn.call(el); } catch (_) {} }
    }
    if (REQUIRE_FS) {
        try { enterFullscreen(); } catch (_) {}
        document.addEventListener('fullscreenchange', function () {
            if (!document.fullscreenElement && REQUIRE_FS && !disqualified && remainingTime > 0) {
                recordOffence('fullscreen_exit', 'Left fullscreen mode during examination');
                setTimeout(enterFullscreen, 500);
            }
        });
        document.addEventListener('webkitfullscreenchange', function () {
            if (!document.webkitIsFullScreen && REQUIRE_FS && !disqualified && remainingTime > 0) {
                recordOffence('fullscreen_exit', 'Left fullscreen mode (webkit)');
                setTimeout(enterFullscreen, 500);
            }
        });
    }

    // --- Tab / visibility monitoring ---
    // Only record an offence if document stays hidden for >= 1 full second; this
    // prevents rapid alt-tab blink from spurious offence increments.
    document.addEventListener('visibilitychange', function () {
        if (disqualified) return;
        if (document.visibilityState === 'hidden') {
            hiddenSince = Date.now();
        } else if (hiddenSince) {
            const ms = Date.now() - hiddenSince;
            hiddenSince = null;
            if (ms >= 1000) {
                recordOffence('tab_switch', 'Switched away from examination tab/window', ms);
            }
        }
    });
    window.addEventListener('blur', function () {
        if (disqualified) return;
        // Backup: if window loses focus but visibility doesn't flip (OS focus),
        // still record once dedup window (handled inside recordOffence) applies.
        // No offence here unless followed by hidden state; but we'll capture it
        // with a lower severity as 'visibility_change' after a short duration.
        setTimeout(function () {
            if (document.hasFocus && !document.hasFocus()) {
                recordOffence('visibility_change', 'Window lost focus', 0);
            }
        }, 800);
    });

    // --- Face monitoring during exam (continuous, when models available) ---
    let noFaceSince = null;
    let faceModelsLoaded = false;
    let faceVideoEl = null;
    let stream = null;

    function checkFaceMonitoring() {
        if (!REQUIRE_FACE_M || typeof window.faceApi === 'undefined') return;
        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:fixed;right:10px;bottom:10px;width:180px;height:140px;border-radius:10px;overflow:hidden;z-index:1050;border:2px solid rgba(255,255,255,0.6);background:#000;';
        const video = document.createElement('video');
        video.autoplay = true; video.playsInline = true; video.muted = true; video.style.cssText = 'width:100%;height:100%;object-fit:cover;transform:scaleX(-1);';
        const canvas = document.createElement('canvas');
        canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;';
        const badge = document.createElement('div');
        badge.style.cssText = 'position:absolute;left:6px;top:6px;padding:2px 6px;border-radius:6px;background:rgba(16,185,129,0.9);color:#fff;font-size:10px;font-weight:700;';
        badge.textContent = 'MONITORING';
        wrap.appendChild(video); wrap.appendChild(canvas); wrap.appendChild(badge);
        document.body.appendChild(wrap);
        faceVideoEl = video;

        Promise.resolve().then(async function () {
            try {
                await window.faceApi.nets.ssdMobilenetv1.loadFromUri('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
                await window.faceApi.loadFaceLandmarkModel('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
                await window.faceApi.loadFaceRecognitionModel('https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model');
                faceModelsLoaded = true;
            } catch (e) { badge.textContent = 'MODELS FAIL'; badge.style.background = '#d97706'; return; }
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { width: 320, height: 240, facingMode: 'user' }, audio: false });
                video.srcObject = stream;
                await new Promise(r => video.onloadedmetadata = r);
            } catch (e) {
                badge.textContent = 'CAM OFF';
                badge.style.background = '#dc2626';
                recordOffence('camera_disabled', 'Camera could not be accessed or was disabled during examination');
                return;
            }
            loop();
        });

        async function loop() {
            if (disqualified) return;
            const displaySize = { width: video.videoWidth || 180, height: video.videoHeight || 140 };
            window.faceApi.matchDimensions(canvas, displaySize);
            try {
                const detections = await window.faceApi
                    .detectAllFaces(video)
                    .withFaceLandmarks()
                    .withFaceDescriptors();
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Draw boxes
                const resized = window.faceApi.resizeResults(detections, displaySize);
                resized.forEach(function (det) {
                    const b = new window.faceApi.DrawBox(det.detection.box, { boxColor: '#10b981', lineWidth: 1 });
                    if (b?.draw) b.draw(canvas);
                });

                const n = detections.length;
                if (n === 0) {
                    if (!noFaceSince) noFaceSince = Date.now();
                    const secs = (Date.now() - noFaceSince) / 1000;
                    badge.textContent = 'NO FACE ' + Math.floor(secs) + 's';
                    badge.style.background = secs >= NO_FACE_SECONDS ? '#dc2626' : '#d97706';
                    if (secs >= NO_FACE_SECONDS) {
                        recordOffence('no_face', 'No face detected before camera', Math.round(secs * 1000));
                        noFaceSince = Date.now() + 5000; // cooldown so re-offence is not immediately re-triggered
                    }
                } else {
                    noFaceSince = null;
                    if (n > 1) {
                        badge.textContent = n + ' FACES';
                        badge.style.background = '#dc2626';
                        recordOffence('multiple_faces', 'Multiple faces detected during examination: ' + n);
                    } else {
                        badge.textContent = 'MONITORING';
                        badge.style.background = '#10b981';
                    }
                }
            } catch (_) {}
            setTimeout(loop, 1500);
        }

        // Track stream end (device disconnected, cam revoked mid-exam).
        video.addEventListener('ended', function () {
            recordOffence('camera_disabled', 'Camera stream ended mid-exam');
        });
    }
    window.addEventListener('load', function () { setTimeout(checkFaceMonitoring, 2000); });
})();
</script>

<style>
    .form-check:hover {
        background-color: #f1f8ff !important;
        border-color: var(--color-primary) !important;
        cursor: pointer;
    }
    .form-check-input:checked + .form-check-label {
        font-weight: bold;
        color: var(--color-primary);
    }
</style>

<?php include(__DIR__ . '/includes/footer.php'); ?>
