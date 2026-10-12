<?php
session_start();
require_once('../db.php');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$subject_id = $_GET['subject_id'] ?? 0;
$class_id = $_GET['class_id'] ?? 0;
$departments = $_GET['departments'] ?? '';

if ($subject_id && $class_id) {
    $stmt_subject = $pdo->prepare("SELECT course_code, name FROM subjects WHERE id = ?");
    $stmt_subject->execute([$subject_id]);
    $subj = $stmt_subject->fetch();
    $subject_name = $subj['name'] ?? '';
    $course_code = $subj['course_code'] ?? '';

    $stmt_class = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt_class->execute([$class_id]);
    $class_name = $stmt_class->fetchColumn();

    if (!$subject_name || !$class_name) {
        echo json_encode([]);
        exit;
    }

    $stmt1 = $pdo->prepare('SELECT id, question_text, type, "questions" as source, created_at FROM questions WHERE subject_id = ? ORDER BY id DESC');
    $stmt1->execute([$subject_id]);
    $q1 = $stmt1->fetchAll();

    $q2 = [];
    try {
        $q2_query = "SELECT id, question as question_text, CASE WHEN type='objective' THEN 'mcq' ELSE 'theory' END as type, 'question_bank' as source, created_at FROM question_bank WHERE (subject = ? OR course_code = ?) AND class = ?";
        $q2_params = [$subject_name, $course_code, $class_name];

        if (!empty($departments)) {
            $depts = explode(',', $departments);
            $dept_conditions = [];
            foreach ($depts as $d) {
                if (empty($d)) continue;
                $dept_conditions[] = "FIND_IN_SET(?, department)";
                $q2_params[] = trim($d);
            }
            if (!empty($dept_conditions)) {
                $q2_query .= " AND (" . implode(" OR ", $dept_conditions) . ")";
            }
        }

        $q2_query .= " ORDER BY id DESC";
        $stmt2 = $pdo->prepare($q2_query);
        $stmt2->execute($q2_params);
        $q2 = $stmt2->fetchAll();
    } catch (PDOException $e) {
        // Just return empty array for q2 if table doesn't exist
    }

    $all_questions = array_merge($q1, $q2);
    
    // Sort by created_at desc to show newly added first
    usort($all_questions, function($a, $b) {
        $timeA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
        $timeB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
        return $timeB - $timeA;
    });

    header('Content-Type: application/json');
    echo json_encode($all_questions);
} else {
    echo json_encode([]);
}
?>
