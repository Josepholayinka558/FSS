<?php
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/csrf.php');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

function attemptStatusLabel($status) {
    static $map = [
        'not_started'    => 'NOT_STARTED',
        'in_progress'    => 'ACTIVE',
        'submitted'      => 'SUBMITTED',
        'auto_submitted' => 'AUTO_SUBMITTED',
        'graded'         => 'GRADED',
        'disqualified'   => 'DISQUALIFIED',
        'expired'        => 'EXPIRED',
    ];
    return $map[$status] ?? strtoupper((string)$status);
}

$exam_id = $_GET['exam_id'] ?? 0;

if ($exam_id) {
    $stmt = $pdo->prepare('
        SELECT r.*, s.name as student_name, s.matric_number, s.registration_number, e.title as exam_title 
        FROM results r 
        JOIN students s ON r.student_id = s.id 
        JOIN exams e ON r.exam_id = e.id
        WHERE r.exam_id = ? 
        ORDER BY r.submitted_at DESC
    ');
    $stmt->execute([$exam_id]);
    $results = $stmt->fetchAll();
    $filename = "Results_" . str_replace(' ', '_', $results[0]['exam_title'] ?? 'Exam') . "_" . date('Y-m-d') . ".csv";
} else {
    $results = $pdo->query('
        SELECT r.*, s.name as student_name, s.matric_number, s.registration_number, e.title as exam_title 
        FROM results r 
        JOIN students s ON r.student_id = s.id 
        JOIN exams e ON r.exam_id = e.id 
        ORDER BY r.submitted_at DESC
    ')->fetchAll();
    $filename = "All_Exam_Results_" . date('Y-m-d') . ".csv";
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, ['Matric Number', 'Registration Number', 'Student Name', 'Exam Title', 'Score (%)', 'Status', 'Offences', 'Submitted At']);

foreach ($results as $row) {
    fputcsv($output, [
        $row['matric_number'],
        $row['registration_number'],
        $row['student_name'],
        $row['exam_title'],
        $row['score'],
        attemptStatusLabel($row['status'] ?? ''),
        (int)($row['offence_count'] ?? 0),
        $row['submitted_at']
    ]);
}

fclose($output);
exit;
