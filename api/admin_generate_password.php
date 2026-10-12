<?php
/**
 * api/admin_generate_password.php
 *   POST: { student_id, password?, auto: bool }
 *   Sets a student's password (either admin-provided or auto-generated).
 *   Returns the cleartext password once so the admin can copy/deliver it.
 */
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/audit_log.php');

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['admin_id'])) {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not admin']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST']); exit;
}
try { fss_verify_csrf(); } catch (Throwable $e) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
}

$raw = file_get_contents('php://input');
$body = [];
if ($raw) { $j = json_decode($raw,true); if (is_array($j)) $body = $j; }
$body = array_merge($_POST, $body);

$sid = (int)($body['student_id'] ?? 0);
if ($sid < 1) { echo json_encode(['ok'=>false,'error'=>'student_id']); exit; }

$stmt = $pdo->prepare("SELECT id, name, matric_number FROM students WHERE id = ? LIMIT 1");
$stmt->execute([$sid]); $s = $stmt->fetch();
if (!$s) { echo json_encode(['ok'=>false,'error'=>'Unknown student']); exit; }

$auto = !empty($body['auto']) || empty($body['password']);
$pw = $auto ? substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*'),0,12) : (string)$body['password'];

if (strlen($pw) < 6) { echo json_encode(['ok'=>false,'error'=>'Password too short (min 6)']); exit; }

$hash = password_hash($pw, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE students SET password = ? WHERE id = ?")->execute([$hash, $sid]);

fss_audit('admin', 'admin_set_student_password', 'student', $sid, [
    'auto_generated' => $auto,
    'matric_number' => $s['matric_number'],
], (int)$_SESSION['admin_id']);

echo json_encode([
    'ok' => true,
    'auto' => $auto,
    'password' => $pw,  // returned ONCE for copy/delivery; not stored
    'matric_number' => $s['matric_number'],
    'student_name' => $s['name'],
], JSON_UNESCAPED_SLASHES);
