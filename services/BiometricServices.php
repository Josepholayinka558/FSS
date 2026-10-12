<?php
/**
 * services/BiometricServices.php
 * ----------------------------------------------------------------
 * FaceRecognitionService + FingerprintService
 *
 * Both services follow a strict template-store + verify-against-template
 * pattern. The HARDWARE / SDK integration points are isolated inside
 * exactly ONE method per service:
 *
 *   FaceRecognitionService::sdkMatchDescriptors()
 *   FingerprintService::sdkVerifyTemplate()
 *
 * Replace those two methods' bodies with the calls to your real,
 * supported SDK / device driver — you will NOT need to touch any
 * other part of the CBT codebase when you do.
 *
 * Template storage: `biometric_registrations` table (see migration v2).
 * Each student may have multiple rows; only rows with status='active'
 * are considered during verification.
 * ----------------------------------------------------------------
 */
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/../includes/audit_log.php');

class FaceRecognitionService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Register or replace a face template for a student.
     * @param int    $studentId
     * @param array  $descriptor   128-float descriptor array from face-api.js or SDK
     * @param string $format       e.g. 'face-api.js_v1', 'arcface_v2', 'test_sentinel'
     * @param ?array  $metadata    sdk_version, quality_score, device_name, etc.
     * @param ?int    $adminId
     * @return int  new registration row id
     */
    public function registerTemplate(int $studentId, array $descriptor, string $format, ?array $metadata = null, ?int $adminId = null): int
    {
        $this->pdo->beginTransaction();
        try {
            // Supersede all prior active face rows for this student.
            $up = $this->pdo->prepare("
                UPDATE biometric_registrations
                   SET status = 'superseded'
                 WHERE student_id = ? AND biometric_type = 'face' AND status = 'active'
            ");
            $up->execute([$studentId]);

            $json = json_encode(array_values($descriptor), JSON_UNESCAPED_SLASHES);
            $meta = $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $ins = $this->pdo->prepare("
                INSERT INTO biometric_registrations
                    (student_id, biometric_type, template_data, template_format, source_metadata, registered_by)
                VALUES (?,?,?,?,?,?)
            ");
            $ins->execute([$studentId, 'face', $json, $format, $meta, $adminId]);
            $id = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("UPDATE students SET face_registered = 1 WHERE id = ?")
                ->execute([$studentId]);

            $this->pdo->commit();

            fss_audit('admin', 'biometric_register_face', 'student', $studentId, [
                'registration_id' => $id,
                'format' => $format,
            ], $adminId);

            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resetFace(int $studentId, ?int $adminId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
                UPDATE biometric_registrations SET status = 'revoked'
                 WHERE student_id = ? AND biometric_type = 'face' AND status != 'revoked'
            ")->execute([$studentId]);
            $this->pdo->prepare("UPDATE students SET face_registered = 0 WHERE id = ?")
                ->execute([$studentId]);
            $this->pdo->commit();
            fss_audit('admin', 'biometric_reset_face', 'student', $studentId, [], $adminId);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hasRegisteredFace(int $studentId): bool
    {
        $s = $this->pdo->prepare("
            SELECT COUNT(*) FROM biometric_registrations
            WHERE student_id = ? AND biometric_type = 'face' AND status = 'active'
        ");
        $s->execute([$studentId]);
        return (int)$s->fetchColumn() > 0;
    }

    public function getActiveFaceRow(int $studentId): ?array
    {
        $s = $this->pdo->prepare("
            SELECT * FROM biometric_registrations
            WHERE student_id = ? AND biometric_type = 'face' AND status = 'active'
            ORDER BY id DESC LIMIT 1
        ");
        $s->execute([$studentId]);
        $row = $s->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Verify a live face descriptor against the registered template.
     * @return array{matched:bool, score:?float, message:string, integration:string}
     */
    public function verify(int $studentId, array $liveDescriptor, float $threshold = FSS_FACE_MATCH_THRESHOLD): array
    {
        $row = $this->getActiveFaceRow($studentId);
        if (!$row) {
            return ['matched' => false, 'score' => null, 'message' => 'No face template registered', 'integration' => 'none'];
        }

        $tpl = @json_decode((string)$row['template_data'], true);
        if (!is_array($tpl)) {
            return ['matched' => false, 'score' => null, 'message' => 'Corrupt registered template', 'integration' => 'error'];
        }

        $format = (string)$row['template_format'];

        // =================================================================
        // REAL SDK CONNECTION POINT
        // -----------------------------------------------------------------
        // If $format is produced by a physical device / commercial SDK,
        // dispatch to sdkMatchDescriptors() and replace the body of that
        // method with the real SDK call.
        // =================================================================
        if ($format !== 'test_sentinel') {
            return $this->sdkMatchDescriptors($studentId, $tpl, $liveDescriptor, $format, $threshold);
        }

        // ---- Test-only sentinel matching ----
        // Only succeeds if admin previously stored a sentinel template
        // AND the EXACT same descriptor values are replayed during verify.
        // This NEVER auto-passes. It proves end-to-end integration works
        // on hardware-less demo machines without faking a match.
        if (!FSS_BIOMETRIC_TEST_MODE) {
            return ['matched' => false, 'score' => null, 'message' => 'Test-mode templates are disabled in production', 'integration' => 'blocked'];
        }
        $dist = $this->euclidean($tpl, $liveDescriptor);
        $matched = $dist <= $threshold && $this->exactKeyVector($tpl) === $this->exactKeyVector($liveDescriptor);
        return [
            'matched'   => $matched,
            'score'     => round($dist, 6),
            'message'   => $matched ? 'Test sentinel matched' : 'Test sentinel mismatch',
            'integration' => 'test_sentinel',
        ];
    }

    // =====================================================================
    // SDK INTEGRATION POINT — FACE
    // ---------------------------------------------------------------------
    // Replace this method body with the call to your actual face SDK.
    // Input: registered template + live descriptor + format string.
    // Output: same array shape as verify().
    // DO NOT fake a "matched: true" return here in production; only return
    // true when the SDK genuinely confirms the identity.
    // =====================================================================
    protected function sdkMatchDescriptors(int $studentId, array $registeredTpl, array $liveTpl, string $format, float $threshold): array
    {
        // TODO: INTEGRATE HARDWARE / SDK HERE
        // Example (pseudocode — replace entirely when SDK is installed):
        //   $result = MyFaceSDK::compare($registeredTpl, $liveTpl);
        //   return [
        //       'matched'     => (bool)$result->isMatch,
        //       'score'       => (float)$result->distance,
        //       'message'     => $result->details,
        //       'integration' => $format,
        //   ];
        //
        // Until SDK is installed, we fall back to pure math on the
        // descriptor vectors. This is a REAL comparison (Euclidean L2
        // distance against threshold). It is NOT a fake "always pass".
        if (count($registeredTpl) !== count($liveTpl)) {
            return ['matched' => false, 'score' => null, 'message' => 'Descriptor dimension mismatch', 'integration' => $format . ' (fallback)'];
        }
        $dist = $this->euclidean($registeredTpl, $liveTpl);
        $matched = $dist <= $threshold;
        return [
            'matched'     => $matched,
            'score'       => round($dist, 6),
            'message'     => $matched ? 'Fallback L2 match' : 'Fallback L2 distance exceeded threshold',
            'integration' => $format . ' (fallback math — SDK NOT YET CONNECTED)',
        ];
    }

    final protected function euclidean(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return INF;
        }
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $d = (float)$v - (float)$b[$i];
            $sum += $d * $d;
        }
        return sqrt($sum);
    }

    final protected function exactKeyVector(array $a): string
    {
        $out = [];
        foreach ($a as $v) {
            $out[] = number_format((float)$v, 8, '.', '');
        }
        return implode(',', $out);
    }
}

class FingerprintService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function registerTemplate(int $studentId, string $templateB64, string $format, ?array $metadata = null, ?int $adminId = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
                UPDATE biometric_registrations SET status = 'superseded'
                 WHERE student_id = ? AND biometric_type = 'fingerprint' AND status = 'active'
            ")->execute([$studentId]);

            $meta = $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ins = $this->pdo->prepare("
                INSERT INTO biometric_registrations
                    (student_id, biometric_type, template_data, template_format, source_metadata, registered_by)
                VALUES (?,?,?,?,?,?)
            ");
            $ins->execute([$studentId, 'fingerprint', $templateB64, $format, $meta, $adminId]);
            $id = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("UPDATE students SET fingerprint_registered = 1 WHERE id = ?")
                ->execute([$studentId]);

            $this->pdo->commit();
            fss_audit('admin', 'biometric_register_fingerprint', 'student', $studentId, [
                'registration_id' => $id,
                'format' => $format,
            ], $adminId);

            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resetFingerprint(int $studentId, ?int $adminId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
                UPDATE biometric_registrations SET status = 'revoked'
                 WHERE student_id = ? AND biometric_type = 'fingerprint' AND status != 'revoked'
            ")->execute([$studentId]);
            $this->pdo->prepare("UPDATE students SET fingerprint_registered = 0 WHERE id = ?")
                ->execute([$studentId]);
            $this->pdo->commit();
            fss_audit('admin', 'biometric_reset_fingerprint', 'student', $studentId, [], $adminId);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hasRegisteredFingerprint(int $studentId): bool
    {
        $s = $this->pdo->prepare("
            SELECT COUNT(*) FROM biometric_registrations
            WHERE student_id = ? AND biometric_type = 'fingerprint' AND status = 'active'
        ");
        $s->execute([$studentId]);
        return (int)$s->fetchColumn() > 0;
    }

    public function getActiveFingerprintRow(int $studentId): ?array
    {
        $s = $this->pdo->prepare("
            SELECT * FROM biometric_registrations
            WHERE student_id = ? AND biometric_type = 'fingerprint' AND status = 'active'
            ORDER BY id DESC LIMIT 1
        ");
        $s->execute([$studentId]);
        $row = $s->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Verify a fingerprint scan.
     * $scanToken is the opaque template / feature-vector / match-token
     * produced by the fingerprint SDK after a successful scan.
     * It MUST come from the hardware SDK. Do NOT accept user-typed strings.
     *
     * @return array{matched:bool, score:?float, message:string, integration:string}
     */
    public function verify(int $studentId, string $scanToken): array
    {
        $row = $this->getActiveFingerprintRow($studentId);
        if (!$row) {
            return ['matched' => false, 'score' => null, 'message' => 'No fingerprint template registered', 'integration' => 'none'];
        }

        $stored = (string)$row['template_data'];
        $format = (string)$row['template_format'];

        // =================================================================
        // REAL SDK CONNECTION POINT
        // -----------------------------------------------------------------
        // Most fingerprint SDKs return a boolean + score directly:
        //   SDK.verify(registeredTemplateId, liveScanHandle) -> {match, score}
        // Replace the body of sdkVerifyTemplate() with that call.
        // =================================================================
        if ($format !== 'test_sentinel') {
            return $this->sdkVerifyTemplate($studentId, $stored, $scanToken, $format);
        }

        // ---- Test sentinel only ----
        // Only passes if EXACT sentinel token replayed.
        // Never auto-passes. Never grants access to a scanner-less student
        // unless the admin explicitly stored a sentinel AND that same
        // sentinel is provided again during verify.
        if (!FSS_BIOMETRIC_TEST_MODE) {
            return ['matched' => false, 'score' => null, 'message' => 'Test-mode templates are disabled in production', 'integration' => 'blocked'];
        }
        if ($scanToken === '') {
            return ['matched' => false, 'score' => null, 'message' => 'Empty fingerprint scan token', 'integration' => 'test_sentinel'];
        }
        $matched = hash_equals($stored, $scanToken);
        return [
            'matched'     => $matched,
            'score'       => $matched ? 1.0 : 0.0,
            'message'     => $matched ? 'Test sentinel matched' : 'Test sentinel mismatch',
            'integration' => 'test_sentinel',
        ];
    }

    // =====================================================================
    // SDK INTEGRATION POINT — FINGERPRINT
    // ---------------------------------------------------------------------
    // This method is the ONLY place where fingerprint hardware/SDK code
    // should live. Everything else (workflow, UI, audit, DQ) is already
    // wired against the return shape of this method.
    //
    // Typical SDK onboarding steps:
    //   1. Install vendor PHP extension / COM object / REST client.
    //   2. During admin registration, call SDK.captureTemplate() and
    //      pass its output into registerTemplate() above.
    //   3. During student verify, call SDK.captureAndMatch() or
    //      SDK.verifyTemplate($storedTemplate, $liveScanId) and
    //      return its boolean + score below.
    //
    // DO NOT return matched:true as a default here; only return true
    // when the SDK has genuinely confirmed the fingerprint identity.
    // =====================================================================
    protected function sdkVerifyTemplate(int $studentId, string $registeredTpl, string $liveToken, string $format): array
    {
        // TODO: INTEGRATE FINGERPRINT HARDWARE SDK HERE
        // Example (pseudocode):
        //   $sdk = new DigitalPersonaSDK();
        //   $result = $sdk->verify($registeredTpl, $liveToken);
        //   return [
        //       'matched'     => (bool)$result->matched,
        //       'score'       => (float)$result->fara_score,
        //       'message'     => $result->details ?? '',
        //       'integration' => $format,
        //   ];
        //
        // Until SDK is present: hard-fail with a clear error. We do NOT
        // fall back to string equality for real formats because that would
        // defeat the purpose of fingerprint biometrics (templates are
        // never byte-identical across captures for real hardware).
        return [
            'matched'     => false,
            'score'       => null,
            'message'     => "Fingerprint SDK not integrated for format '$format' — see BiometricServices.php, FingerprintService::sdkVerifyTemplate()",
            'integration' => $format . ' (SDK NOT CONNECTED)',
        ];
    }
}
