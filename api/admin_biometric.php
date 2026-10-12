<?php
/**
 * api/admin_biometric.php
 *
 * Admin-only endpoint.
 * Actions (all POST, CSRF-protected):
 *   action = register_face
 *     student_id, descriptor[], format, metadata[]
 *   action = register_fingerprint
 *     student_id, template_b64, format, metadata[]
 *   action = reset_face
 *     student_id
 *   action = reset_fingerprint
 *     student_id
 *   action = create_test_sentinel_face
 *     student_id   -> generates a random test-sentinel descriptor and registers it
 *   action = create_test_sentinel_fingerprint
 *     student_id   -> generates a random test-sentinel token, registers it, and returns it once to the admin UI for display.
 */
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/audit_log.php');
require_once(__DIR__ . '/../services/BiometricServices.php');

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated as admin']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
try {
    fss_verify_csrf();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF failure']);
    exit;
}

$raw = file_get_contents('php://input');
$body = [];
if (!empty($raw)) {
    $j = json_decode($raw, true);
    if (is_array($j)) {
        $body = $j;
    }
}
$body = array_merge($_POST, $body);

$action = (string)($body['action'] ?? '');
$studentId = (int)($body['student_id'] ?? 0);
if ($studentId < 1) {
    echo json_encode(['ok' => false, 'error' => 'Missing student_id']);
    exit;
}
$exists = $pdo->prepare("SELECT id FROM students WHERE id = ? LIMIT 1");
$exists->execute([$studentId]);
if (!$exists->fetchColumn()) {
    echo json_encode(['ok' => false, 'error' => 'Unknown student']);
    exit;
}
$adminId = (int)$_SESSION['admin_id'];

try {
    $face = new FaceRecognitionService($pdo);
    $fp   = new FingerprintService($pdo);

    switch ($action) {
        case 'register_face':
            $desc = $body['descriptor'] ?? null;
            if (!is_array($desc) || count($desc) === 0) {
                throw new RuntimeException('Missing face descriptor');
            }
            $format = (string)($body['format'] ?? 'face-api.js_v1');
            $meta   = $body['metadata'] ?? null;
            $id = $face->registerTemplate($studentId, array_values($desc), $format, is_array($meta) ? $meta : null, $adminId);
            echo json_encode([
                'ok' => true, 'registration_id' => $id,
                'face_registered' => $face->hasRegisteredFace($studentId),
            ], JSON_UNESCAPED_SLASHES);
            break;

        case 'register_fingerprint':
            $tpl = (string)($body['template_b64'] ?? '');
            if ($tpl === '') {
                throw new RuntimeException('Missing fingerprint template');
            }
            $format = (string)($body['format'] ?? 'sdk_template_b64');
            $meta   = $body['metadata'] ?? null;
            $id = $fp->registerTemplate($studentId, $tpl, $format, is_array($meta) ? $meta : null, $adminId);
            echo json_encode([
                'ok' => true, 'registration_id' => $id,
                'fingerprint_registered' => $fp->hasRegisteredFingerprint($studentId),
            ], JSON_UNESCAPED_SLASHES);
            break;

        case 'reset_face':
            $face->resetFace($studentId, $adminId);
            echo json_encode(['ok' => true, 'face_registered' => false]);
            break;

        case 'reset_fingerprint':
            $fp->resetFingerprint($studentId, $adminId);
            echo json_encode(['ok' => true, 'fingerprint_registered' => false]);
            break;

        case 'create_test_sentinel_face':
            // Deterministic random 128-float descriptor. Returned once so the admin
            // UI can hand the same value back to the browser during verify.
            $sentinel = [];
            for ($i = 0; $i < 128; $i++) {
                $sentinel[] = round((mt_rand() / mt_getrandmax()) * 2 - 1, 6);
            }
            $id = $face->registerTemplate($studentId, $sentinel, 'test_sentinel', [
                'note' => 'Test sentinel. NOT A REAL FACE. Auto-generated for demo only.',
            ], $adminId);
            echo json_encode([
                'ok' => true,
                'registration_id' => $id,
                'sentinel_descriptor' => $sentinel,
                'face_registered' => true,
                'warning' => 'This is a DEMO test sentinel, NOT a real face scan. In production test-mode must be disabled.'
            ], JSON_UNESCAPED_SLASHES);
            break;

        case 'create_test_sentinel_fingerprint':
            $sentinel = 'FSS-FP-TEST-' . bin2hex(random_bytes(12));
            $id = $fp->registerTemplate($studentId, $sentinel, 'test_sentinel', [
                'note' => 'Test sentinel. NOT from real fingerprint hardware. Auto-generated for demo only.',
            ], $adminId);
            echo json_encode([
                'ok' => true,
                'registration_id' => $id,
                'sentinel_token' => $sentinel,
                'fingerprint_registered' => true,
                'warning' => 'This is a DEMO test sentinel, NOT a real fingerprint scan. Store it securely for demo verification. In production test-mode must be disabled.'
            ], JSON_UNESCAPED_SLASHES);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
