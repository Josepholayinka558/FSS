-- =====================================================================
-- FSS OYO CBT SYSTEM — DATABASE MIGRATION v2 (POLYTECHNIC UPGRADE)
-- Non-destructive. Idempotent (safe to re-run). No data loss.
-- Apply AFTER original database.sql.
-- Target: Federal School of Surveying, Computer Science Department
-- Levels: ND1 / ND2 / HND1 / HND2
-- =====================================================================

USE fsscbt_db;

-- ---------------------------------------------------------------------
-- 1. EXTEND `classes` — add programme (ND or HND) denormalized for perf
-- ---------------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'classes'
                     AND COLUMN_NAME = 'programme');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE classes ADD COLUMN programme ENUM(''ND'',''HND'') NULL AFTER name',
    'SELECT ''programme already exists in classes'' AS skip');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: ND1 ND2 -> ND; HND1 HND2 -> HND
UPDATE classes SET programme = 'ND'  WHERE name IN ('ND1','ND2')  AND (programme IS NULL OR programme='');
UPDATE classes SET programme = 'HND' WHERE name IN ('HND1','HND2') AND (programme IS NULL OR programme='');

-- ---------------------------------------------------------------------
-- 2. EXTEND `students` — full polytechnic student profile
-- ---------------------------------------------------------------------
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='email');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN email VARCHAR(150) NULL AFTER name','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='password');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN password VARCHAR(255) NULL AFTER email','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='programme');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN programme ENUM(''ND'',''HND'') NULL AFTER password','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='semester');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN semester ENUM(''1st'',''2nd'') NULL AFTER programme','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='academic_session');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN academic_session VARCHAR(20) NULL AFTER semester','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='account_status');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN account_status ENUM(''active'',''suspended'',''graduated'') NOT NULL DEFAULT ''active'' AFTER academic_session','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='face_registered');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN face_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER account_status','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='fingerprint_registered');
SET @sql = IF(@col=0,'ALTER TABLE students ADD COLUMN fingerprint_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER face_registered','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill student.programme from their class
UPDATE students s JOIN classes c ON s.class_id = c.id
   SET s.programme = c.programme WHERE s.programme IS NULL AND c.programme IS NOT NULL;

-- ---------------------------------------------------------------------
-- 3. EXTEND `exams` — offence config + metadata
-- ---------------------------------------------------------------------
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='max_offences');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN max_offences INT NOT NULL DEFAULT 3 AFTER duration','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='exam_type');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN exam_type VARCHAR(50) NOT NULL DEFAULT ''End-of-Semester Examination'' AFTER max_offences','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='instructions');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN instructions TEXT NULL AFTER exam_type','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='semester');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN semester ENUM(''1st'',''2nd'') NULL AFTER instructions','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='academic_session');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN academic_session VARCHAR(20) NULL AFTER semester','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='require_face_auth');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN require_face_auth TINYINT(1) NOT NULL DEFAULT 1 AFTER academic_session','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='require_fingerprint_auth');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN require_fingerprint_auth TINYINT(1) NOT NULL DEFAULT 1 AFTER require_face_auth','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exams' AND COLUMN_NAME='require_fullscreen');
SET @sql = IF(@col=0,'ALTER TABLE exams ADD COLUMN require_fullscreen TINYINT(1) NOT NULL DEFAULT 1 AFTER require_fingerprint_auth','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. EXTEND `results` (this is the exam_attempts table) — DQ + auth
-- ---------------------------------------------------------------------
-- NOTE: MODIFY COLUMN on ENUM requires listing ALL values, not just new ones.
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='status');
SET @sql = IF(@col=0,
    'ALTER TABLE results ADD COLUMN status ENUM(''not_started'',''in_progress'',''submitted'',''auto_submitted'',''graded'',''disqualified'',''expired'') NOT NULL DEFAULT ''not_started'' AFTER start_time',
    'ALTER TABLE results MODIFY COLUMN status ENUM(''not_started'',''in_progress'',''submitted'',''auto_submitted'',''graded'',''disqualified'',''expired'') NOT NULL DEFAULT ''not_started''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing in-progress rows were DEFAULT 'in_progress' originally; keep them correct.
-- (MySQL will keep the existing stored value when it matches the new enum.)

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='offence_count');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN offence_count INT NOT NULL DEFAULT 0 AFTER status','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='disqualification_reason');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN disqualification_reason TEXT NULL AFTER offence_count','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='face_auth_status');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN face_auth_status ENUM(''pending'',''passed'',''failed'',''not_required'') NOT NULL DEFAULT ''pending'' AFTER disqualification_reason','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='fingerprint_auth_status');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN fingerprint_auth_status ENUM(''pending'',''passed'',''failed'',''not_required'') NOT NULL DEFAULT ''pending'' AFTER face_auth_status','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='disqualified_at');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN disqualified_at TIMESTAMP NULL DEFAULT NULL AFTER fingerprint_auth_status','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='auto_submitted_at');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN auto_submitted_at TIMESTAMP NULL DEFAULT NULL AFTER disqualified_at','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='ip_address');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN ip_address VARCHAR(45) NULL AFTER auto_submitted_at','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='results' AND COLUMN_NAME='user_agent');
SET @sql = IF(@col=0,'ALTER TABLE results ADD COLUMN user_agent TEXT NULL AFTER ip_address','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Attempt id used for biometric/offence logs — make sure 0 in-progress rows have explicit IP/UA on next resume.

-- ---------------------------------------------------------------------
-- 5. NEW TABLE: biometric_registrations
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS biometric_registrations (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    student_id         INT NOT NULL,
    biometric_type     ENUM('face','fingerprint') NOT NULL,
    template_data      LONGTEXT NULL,
    template_format    VARCHAR(100) NOT NULL DEFAULT 'unknown',
    source_metadata    JSON NULL,
    registered_by      INT NULL,
    status             ENUM('active','superseded','revoked') NOT NULL DEFAULT 'active',
    registered_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id)    REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (registered_by) REFERENCES admins(id)   ON DELETE SET NULL,
    INDEX idx_student_type (student_id, biometric_type),
    INDEX idx_active (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. NEW TABLE: exam_offences
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exam_offences (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id      INT NOT NULL,
    student_id      INT NOT NULL,
    exam_id         INT NOT NULL,
    offence_type    ENUM('tab_switch','visibility_change','fullscreen_exit',
                         'camera_disabled','no_face','face_mismatch',
                         'multiple_faces','other') NOT NULL,
    description     VARCHAR(255) NULL,
    offence_number  INT NOT NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      TEXT NULL,
    client_payload  JSON NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES results(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id)    REFERENCES exams(id)    ON DELETE CASCADE,
    INDEX idx_attempt_time (attempt_id, created_at),
    INDEX idx_student_time (student_id, created_at),
    INDEX idx_dedup (attempt_id, offence_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. NEW TABLE: audit_log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    actor_type      ENUM('admin','student','system') NOT NULL,
    actor_id        INT NOT NULL DEFAULT 0,
    action          VARCHAR(100) NOT NULL,
    entity_type     VARCHAR(50) NULL,
    entity_id       INT NULL,
    details         JSON NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actor_time (actor_type, actor_id, created_at),
    INDEX idx_action_time (action, created_at),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fix default rows in results where status enum expansion left valid values.
-- (No-op because stored enum values matched by index, but be explicit about empty->not_started.)
UPDATE results SET status = 'not_started' WHERE status = '' OR status IS NULL;
