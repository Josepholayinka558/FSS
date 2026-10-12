-- ============================================================
-- FSS CBT EXAMINATION SYSTEM — DATABASE MIGRATION v3
-- Scope: Federal School of Surveying, Computer Science Dept.
-- Levels: ND1 / ND2 / HND1 / HND2
-- Rules: NON-DESTRUCTIVE only. No DROP, no destructive ALTER.
--        All schema changes conditional on information_schema check.
--        All data inserts use INSERT IGNORE (no data loss, no dup errors).
--        Idempotent — safe to run multiple times.
-- Run AFTER database_migration_v2.sql has been applied.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+01:00'; -- Africa/Lagos

-- ----------------------------------------------------------
-- 1. ADD faculty COLUMN (label-only for single-school scope)
--    Default = 'Federal School of Surveying' per institution.
-- ----------------------------------------------------------

-- students.faculty
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'students'
               AND COLUMN_NAME  = 'faculty');
SET @sql = IF(@col = 0,
    'ALTER TABLE students ADD COLUMN faculty VARCHAR(150) NOT NULL DEFAULT ''Federal School of Surveying'' AFTER department',
    'SELECT ''students.faculty already exists — skip'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- classes.faculty
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'classes'
               AND COLUMN_NAME  = 'faculty');
SET @sql = IF(@col = 0,
    'ALTER TABLE classes ADD COLUMN faculty VARCHAR(150) NOT NULL DEFAULT ''Federal School of Surveying'' AFTER programme',
    'SELECT ''classes.faculty already exists — skip'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- exams.faculty
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'exams'
               AND COLUMN_NAME  = 'faculty');
SET @sql = IF(@col = 0,
    'ALTER TABLE exams ADD COLUMN faculty VARCHAR(150) NOT NULL DEFAULT ''Federal School of Surveying'' AFTER academic_session',
    'SELECT ''exams.faculty already exists — skip'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------
-- 2. ENSURE subjects.programme COLUMN EXISTS
--    manage_subjects.php applies this ALTER defensively at
--    runtime; apply declaratively here so column always exists.
-- ----------------------------------------------------------
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'subjects'
               AND COLUMN_NAME  = 'programme');
SET @sql = IF(@col = 0,
    'ALTER TABLE subjects ADD COLUMN programme ENUM(''ND'', ''HND'', ''All'') NOT NULL DEFAULT ''All'' AFTER course_title',
    'SELECT ''subjects.programme already exists — skip'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------
-- 3. INSERT IGNORE ND COM-STYLE COURSES (user requested)
--    Pattern: COM 111 … COM 2xx  (ND = National Diploma 1xx/2xx)
--    Matches placeholder hint in manage_subjects.php L194 / L216.
-- ----------------------------------------------------------
INSERT IGNORE INTO subjects (course_code, name, course_title, programme) VALUES
('COM 111', 'Intro to Computing',        'Introduction to Computer Science',                   'ND'),
('COM 112', 'Computer Appreciation',     'Computer Appreciation & Office Applications',       'ND'),
('COM 121', 'Programming I (Basic)',     'Introduction to Programming using BASIC / Scratch', 'ND'),
('COM 122', 'Mathematics I',             'Computation & Algebra for Computer Science I',      'ND'),
('COM 211', 'Data Structures',           'Data Structures & Algorithms',                      'ND'),
('COM 212', 'Programming II',            'Advanced Programming (Python / Java)',              'ND'),
('COM 221', 'Database Fundamentals',     'Introduction to Database Systems (SQL)',            'ND'),
('COM 222', 'System Analysis',           'System Analysis & Design',                          'ND');

-- ----------------------------------------------------------
-- 4. INSERT IGNORE HND SWD-STYLE COURSES (user requested)
--    Pattern: SWD 311 … SWD 4xx  (HND = Higher National Diploma 3xx/4xx, SWD = Software/Web Development)
--    Matches placeholder hint in manage_subjects.php L194 / L216.
-- ----------------------------------------------------------
INSERT IGNORE INTO subjects (course_code, name, course_title, programme) VALUES
('SWD 311', 'Web Design I',              'Web Design & Front-End Development I (HTML/CSS/JS)','HND'),
('SWD 312', 'Software Eng I',            'Software Engineering Principles & UML',             'HND'),
('SWD 321', 'Object-Oriented Programming','OOP with PHP / Java / C#',                        'HND'),
('SWD 322', 'Database Administration',   'Relational DB Administration & Optimization',      'HND'),
('SWD 411', 'Web Design II',             'Full-Stack Web Development (PHP + MySQL / JS SPA)','HND'),
('SWD 412', 'Software Eng II',           'Software Project Management & Agile Practices',    'HND'),
('SWD 421', 'Surveying Software Dev',    'Software Development for Surveying & GIS Apps',    'HND'),
('SWD 422', 'Networking & Security',     'Computer Networking & Cyber Security Basics',      'HND');

-- ----------------------------------------------------------
-- 5. BACKFILL programme in subjects where NULL or empty
--    (only rows that predated the column default)
-- ----------------------------------------------------------
UPDATE subjects SET programme = 'All' WHERE programme IS NULL OR programme = '';

-- ----------------------------------------------------------
-- 6. BACKFILL classes.programme for any legacy class rows
--    where migration v2 backfill may have missed (idempotent).
-- ----------------------------------------------------------
UPDATE classes SET programme = 'ND'  WHERE programme IS NULL AND name LIKE 'ND%';
UPDATE classes SET programme = 'HND' WHERE programme IS NULL AND name LIKE 'HND%';
UPDATE classes SET programme = 'ND'  WHERE programme = ''   AND name LIKE 'ND%';
UPDATE classes SET programme = 'HND' WHERE programme = ''   AND name LIKE 'HND%';

-- ============================================================
-- END of migration v3
-- ============================================================
