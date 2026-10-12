CREATE DATABASE IF NOT EXISTS ;
USE fsscbt_db;

-- Table for Classes (ND1, ND2, HND1, HND2)
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
);

-- Table for Courses (formerly Subjects)
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    course_title VARCHAR(200) NOT NULL
);

-- Table for Students
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matric_number VARCHAR(50) NOT NULL UNIQUE,
    registration_number VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    class_id INT,
    department ENUM('Computer Science') NOT NULL DEFAULT 'Computer Science',
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
);

-- Table for Admins
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

-- Table for Questions
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT,
    type ENUM('mcq', 'theory') NOT NULL,
    question_text TEXT NOT NULL,
    option_a TEXT,
    option_b TEXT,
    option_c TEXT,
    option_d TEXT,
    correct_answer ENUM('A', 'B', 'C', 'D'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- Table for Exams
CREATE TABLE IF NOT EXISTS exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    class_id INT,
    subject_id INT,
    duration INT NOT NULL,
    published TINYINT(1) DEFAULT 0,
    published_obj TINYINT(1) DEFAULT 0,
    published_theory TINYINT(1) DEFAULT 0,
    departments SET('Computer Science') NOT NULL DEFAULT 'Computer Science',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- Table for Exam-Question Mapping
CREATE TABLE IF NOT EXISTS exam_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT,
    question_id INT,
    question_source ENUM('questions', 'question_bank') NOT NULL DEFAULT 'questions',
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

-- Table for Results and Student Answers
CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    exam_id INT,
    score DECIMAL(5,2) DEFAULT 0.00,
    answers JSON,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    start_time TIMESTAMP NULL DEFAULT NULL,
    status ENUM('in_progress', 'submitted', 'graded') DEFAULT 'in_progress',
    result_display_status ENUM('pending', 'approved', 'held') DEFAULT 'pending',
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

-- Table for System Settings
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT
);

-- Table for Student Activity Log
CREATE TABLE IF NOT EXISTS student_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    activity_type VARCHAR(100) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Table for Question Bank
CREATE TABLE IF NOT EXISTS question_bank (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_number INT,
    question TEXT NOT NULL,
    type ENUM('objective', 'theory') NOT NULL,
    option_a TEXT,
    option_b TEXT,
    option_c TEXT,
    option_d TEXT,
    correct_answer VARCHAR(10),
    explanation TEXT,
    image VARCHAR(255),
    subject VARCHAR(100),
    course_code VARCHAR(20),
    class VARCHAR(50),
    department SET('Computer Science') NOT NULL,
    is_past_question TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sample Data
INSERT INTO admins (username, password) VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
INSERT INTO system_settings (setting_key, setting_value) VALUES ('exam_access_code', '123456');

-- Polytechnic Classes
INSERT INTO classes (name) VALUES ('ND1'), ('ND2'), ('HND1'), ('HND2');

-- Computer Science Department Courses (ND Level)
INSERT INTO subjects (course_code, name, course_title) VALUES 
('CSC 101', 'Introduction to Computer Science', 'Introduction to Computer Science'),
('CSC 102', 'Computer Application Packages', 'Computer Application Packages'),
('MTH 101', 'General Mathematics I', 'General Mathematics I'),
('MTH 102', 'General Mathematics II', 'General Mathematics II'),
('GST 101', 'Use of English', 'Use of English'),
('GST 102', 'Nigerian Peoples and Culture', 'Nigerian Peoples and Culture'),
('PHY 101', 'General Physics I', 'General Physics I'),
('PHY 102', 'General Physics II', 'General Physics II'),
('CHM 101', 'General Chemistry I', 'General Chemistry I'),
('BIO 101', 'General Biology', 'General Biology'),

-- Computer Science Department Courses (ND II Level)
('CSC 201', 'Programming with BASIC', 'Programming with BASIC'),
('CSC 202', 'Digital Electronics', 'Digital Electronics'),
('CSC 203', 'Data Processing', 'Data Processing'),
('CSC 204', 'Computer Architecture', 'Computer Architecture'),
('CSC 205', 'Operating System Fundamentals', 'Operating System Fundamentals'),
('MTH 201', 'Business Statistics', 'Business Statistics'),
('GST 201', 'Entrepreneurship Development', 'Entrepreneurship Development'),

-- Computer Science Department Courses (HND I Level)
('CSC 301', 'Advanced Programming (C/C++)', 'Advanced Programming with C/C++'),
('CSC 302', 'Database Management System', 'Database Management System'),
('CSC 303', 'System Analysis and Design', 'System Analysis and Design'),
('CSC 304', 'Web Design and Development', 'Web Design and Development'),
('CSC 305', 'Data Structures and Algorithms', 'Data Structures and Algorithms'),
('CSC 306', 'Discrete Mathematics', 'Discrete Mathematics'),
('CSC 307', 'Linear Algebra', 'Linear Algebra'),
('GST 301', 'Research Methodology', 'Research Methodology'),

-- Computer Science Department Courses (HND II Level)
('CSC 401', 'Object Oriented Programming (Java/Python)', 'Object Oriented Programming with Java/Python'),
('CSC 402', 'Software Engineering', 'Software Engineering'),
('CSC 403', 'Computer Networks', 'Computer Networks'),
('CSC 404', 'Artificial Intelligence', 'Artificial Intelligence'),
('CSC 405', 'Information Security', 'Information Security'),
('CSC 406', 'Mobile Application Development', 'Mobile Application Development'),
('CSC 407', 'Project Management', 'Project Management'),
('CSC 408', 'Final Year Project', 'Final Year Project');
