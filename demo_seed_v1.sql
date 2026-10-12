-- =================================================================
-- FSS CBT — Demo Fixture Seed (Phase 5 scenarios 1-16)
-- Idempotent: DELETE existing demo rows first, then INSERT.
-- Safe to re-run repeatedly. No damage to unrelated data.
-- =================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------
-- 1. DEMO STUDENT — ND1 Computer Science (Adebayo Tunde)
--    Password: student123   (bcrypt hash generated via PHP CLI)
-- -----------------------------------------------------------------
DELETE FROM students WHERE matric_number = 'FSS/ND/2024/001';
INSERT INTO students
    (matric_number, registration_number, name, email, password,
     programme, semester, academic_session, account_status,
     face_registered, fingerprint_registered, class_id,
     department, faculty)
VALUES
    ('FSS/ND/2024/001',
     'FSSND2024001',
     'Adebayo Tunde',
     'tunde.adebayo@fss-oyo.edu.ng',
     '$2y$10$HDHIpuqXkR4e30yfdyvY2O0GMMnZiPjAyZBdKNtLqNhYm09v3Ed1e',
     'ND', '1st', '2024/2025', 'active',
     1, 1, 1,
     'Computer Science', 'Federal School of Surveying');

SET @DEMO_STUDENT_ID = LAST_INSERT_ID();

-- -----------------------------------------------------------------
-- 2. BIOMETRIC REGISTRATIONS — test_sentinel format
--    * Face: 128 identical floats (euclidean distance vs same = 0.0,
--      which is well below 0.55 threshold. exactKeyVector matches.)
--      BAD face sentinel = slightly different vector so dist > 0.55.
--    * Fingerprint: exact string — must be replayed byte-for-byte.
-- -----------------------------------------------------------------
DELETE FROM biometric_registrations
 WHERE student_id = @DEMO_STUDENT_ID
   AND biometric_type IN ('face','fingerprint');

-- Face sentinel (good) — 128 * 0.123
SET @FACE_GOOD_JSON = CONCAT('[', REPEAT('0.123,', 127), '0.123]');

INSERT INTO biometric_registrations
    (student_id, biometric_type, template_data, template_format,
     source_metadata, registered_by, status)
VALUES
    (@DEMO_STUDENT_ID, 'face', @FACE_GOOD_JSON, 'test_sentinel',
     '{"quality_score":0.99,"device":"demo-sentinel","admin_note":"Phase 5 good face vector"}',
     1, 'active');

-- Fingerprint sentinel (good) — exact string
SET @FP_GOOD_TOKEN = 'FSS_FP_TEST_SENTINEL_ND1_ADEBAYO_2024_v1';

INSERT INTO biometric_registrations
    (student_id, biometric_type, template_data, template_format,
     source_metadata, registered_by, status)
VALUES
    (@DEMO_STUDENT_ID, 'fingerprint', @FP_GOOD_TOKEN, 'test_sentinel',
     '{"device":"demo-sentinel","capture_count":3,"admin_note":"Phase 5 good fp token"}',
     1, 'active');

-- -----------------------------------------------------------------
-- 3. THREE MCQ QUESTIONS — Subject = COM 111 (id=34 — Intro to CS)
-- -----------------------------------------------------------------
DELETE FROM questions
 WHERE subject_id = 34
   AND question_text IN (
       'Which of these is the brain of a computer?',
       '1 Kilobyte equals how many bytes?',
       'Which language is known as the "mother of all languages"?'
   );

INSERT INTO questions
    (subject_id, type, question_text,
     option_a, option_b, option_c, option_d, correct_answer)
VALUES
    (34, 'mcq',
     'Which of these is the brain of a computer?',
     'Monitor', 'CPU', 'Keyboard', 'Hard Disk', 'B'),
    (34, 'mcq',
     '1 Kilobyte equals how many bytes?',
     '100', '512', '1024', '2048', 'C'),
    (34, 'mcq',
     'Which language is known as the "mother of all languages"?',
     'Python', 'Java', 'C', 'Assembly', 'C');

SET @Q1_ID := (SELECT id FROM questions WHERE subject_id=34
               AND question_text='Which of these is the brain of a computer?');
SET @Q2_ID := (SELECT id FROM questions WHERE subject_id=34
               AND question_text='1 Kilobyte equals how many bytes?');
SET @Q3_ID := (SELECT id FROM questions WHERE subject_id=34
               AND question_text='Which language is known as the "mother of all languages"?');

-- -----------------------------------------------------------------
-- 4. EXAM — COM 111 Mid-Semester Test (ND1, 5 min, max_offences=3)
-- -----------------------------------------------------------------
DELETE FROM exams
 WHERE title = 'COM 111 Mid-Semester Test (ND1 Demo)';

INSERT INTO exams
    (title, class_id, subject_id, duration, max_offences, exam_type,
     instructions, semester, academic_session, faculty,
     require_face_auth, require_fingerprint_auth, require_fullscreen,
     published, published_obj, published_theory, departments)
VALUES
    ('COM 111 Mid-Semester Test (ND1 Demo)',
     1, 34, 5, 3, 'Mid-Semester Test',
     'Answer all 3 questions. Tab switching, losing fullscreen, camera
      off, or no face detected each count as 1 offence. Maximum 3
      offences = automatic disqualification. Good luck!',
     '1st', '2024/2025', 'Federal School of Surveying',
     1, 1, 1,
     1, 1, 0, 'Computer Science');

SET @EXAM_ID = LAST_INSERT_ID();

-- Link 3 MCQs to this exam via exam_questions
DELETE FROM exam_questions WHERE exam_id = @EXAM_ID;
INSERT INTO exam_questions (exam_id, question_id, question_source) VALUES
    (@EXAM_ID, @Q1_ID, 'questions'),
    (@EXAM_ID, @Q2_ID, 'questions'),
    (@EXAM_ID, @Q3_ID, 'questions');

-- -----------------------------------------------------------------
-- 5. CLEAN UP STALE RESULTS / OFFENCES for a pristine demo DB state
--    (only rows that belong to our demo student + demo exam)
-- -----------------------------------------------------------------
DELETE eo FROM exam_offences eo
 JOIN results r ON eo.attempt_id = r.id
 WHERE r.student_id = @DEMO_STUDENT_ID AND r.exam_id = @EXAM_ID;
DELETE FROM results WHERE student_id = @DEMO_STUDENT_ID AND exam_id = @EXAM_ID;
