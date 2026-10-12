<?php
/**
 * services/config.php — runtime configuration for biometric & monitoring.
 *
 * IMPORTANT DEFINITIONS:
 *   FSS_BIOMETRIC_TEST_MODE = true
 *       -> Permits the "test sentinel" flows for demos on hardware-less
 *          machines.  It DOES NOT make verifications always pass.
 *          - Face recognition still requires real descriptor matching
 *            OR an explicit admin-created TEST-MODE FACE TEMPLATE
 *            (a stored sentinel descriptor that only matches its own value).
 *          - Fingerprint still requires the EXACT same sentinel string
 *            the admin stored as a test template — any other value FAILS.
 *
 *   FSS_BIOMETRIC_TEST_MODE = false  (PRODUCTION)
 *       -> Test sentinel templates are IGNORED during verification.
 *       -> Any call without a real SDK descriptor is rejected.
 *
 * The hardware SDK integration points are marked clearly in
 * FaceRecognitionService::sdkMatchDescriptors() and
 * FingerprintService::sdkVerifyTemplate() — replace those bodies
 * with the real SDK calls once the device driver / SDK is installed.
 */
if (!defined('FSS_BIOMETRIC_TEST_MODE')) {
    define('FSS_BIOMETRIC_TEST_MODE', true);
}
if (!defined('FSS_FACE_MATCH_THRESHOLD')) {
    define('FSS_FACE_MATCH_THRESHOLD', 0.55); // lower = stricter (Euclidean distance)
}
if (!defined('FSS_OFFENCE_DEDUP_SECONDS')) {
    define('FSS_OFFENCE_DEDUP_SECONDS', 3); // ignore same offence type within window
}
if (!defined('FSS_HIDDEN_TAB_MIN_SECONDS')) {
    define('FSS_HIDDEN_TAB_MIN_SECONDS', 1); // must stay hidden ≥1s before counting
}
if (!defined('FSS_NO_FACE_OFFENCE_SECONDS')) {
    define('FSS_NO_FACE_OFFENCE_SECONDS', 10); // no face for ≥10s → offence
}
