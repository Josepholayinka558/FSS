<?php
require_once('../includes/security_bootstrap.php');
require_once('../db.php');
require_once('../includes/csrf.php');
require_once('../includes/audit_log.php');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$message = '';
$error = '';
$preview_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && isset($_POST['preview_csv'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $file = $_FILES['csv_file']['tmp_name'];
    $selected_class = $_POST['class_id'];
    $selected_subject = $_POST['subject_id'];
    $selected_departments = isset($_POST['departments']) ? $_POST['departments'] : [];
    $question_type = $_POST['question_type']; // 'objective' or 'theory'

    if (empty($selected_class) || empty($selected_subject) || empty($selected_departments) || empty($question_type)) {
        $error = 'Please select Class, Subject, Department, and Question Type.';
    } elseif (($handle = fopen($file, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ",");
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($question_type === 'objective') {
                // Expected format for OBJ: question_number,question,option_a,option_b,option_c,option_d,correct_answer,explanation,image
                if (count($data) >= 7) {
                    $preview_data[] = [
                        'question_number' => $data[0],
                        'question' => $data[1],
                        'type' => 'objective',
                        'option_a' => $data[2],
                        'option_b' => $data[3],
                        'option_c' => $data[4],
                        'option_d' => $data[5],
                        'correct_answer' => $data[6],
                        'explanation' => $data[7] ?? '',
                        'image' => $data[8] ?? '',
                        'subject_id' => $selected_subject,
                        'class_id' => $selected_class,
                        'departments' => $selected_departments
                    ];
                }
            } else {
                // Expected format for Theory: question_number,question
                if (count($data) >= 2) {
                    $preview_data[] = [
                        'question_number' => $data[0],
                        'question' => $data[1],
                        'type' => 'theory',
                        'option_a' => '',
                        'option_b' => '',
                        'option_c' => '',
                        'option_d' => '',
                        'correct_answer' => '',
                        'explanation' => '',
                        'image' => '',
                        'subject_id' => $selected_subject,
                        'class_id' => $selected_class,
                        'departments' => $selected_departments
                    ];
                }
            }
        }
        fclose($handle);

        // Handle Image Uploads during Preview
        $image_filenames = [];
        if (!empty($_FILES['images']['name'][0])) {
            $upload_dir = '../uploads/questions/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if (empty($tmp_name)) continue;
                $filename = $_FILES['images']['name'][$key];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    $new_filename = uniqid() . '_' . $filename;
                    if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                        $image_filenames[$filename] = $new_filename;
                    }
                }
            }
        }

        $_SESSION['preview_data'] = $preview_data;
        $_SESSION['upload_meta'] = [
            'class_id' => $selected_class,
            'subject_id' => $selected_subject,
            'departments' => $selected_departments,
            'question_type' => $question_type,
            'create_exam' => isset($_POST['create_exam']) ? 1 : 0,
            'exam_duration' => $_POST['exam_duration'] ?? 30,
            'image_filenames' => $image_filenames
        ];
    } else {
        $error = 'Failed to open CSV file.';
    }
    }
}

// Handle final upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['final_upload'])) {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $preview_data = $_SESSION['preview_data'] ?? [];
    $meta = $_SESSION['upload_meta'] ?? [];
    $success_count = 0;
    $errors = [];
    $uploaded_question_ids = [];

    if (empty($meta) || empty($preview_data)) {
        $error = 'Session data expired or missing. Please re-upload the CSV file.';
    } else {
        // Fetch actual class and subject names for the database
        $stmt_class = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
        $stmt_class->execute([$meta['class_id'] ?? 0]);
        $class_name = $stmt_class->fetchColumn();

        $stmt_subject = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
        $stmt_subject->execute([$meta['subject_id'] ?? 0]);
        $subject_name = $stmt_subject->fetchColumn();

        $departments = is_array($meta['departments']) ? $meta['departments'] : [];
        $dept_string = implode(',', $departments);

        $image_filenames = $meta['image_filenames'] ?? [];

        if (!empty($preview_data)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO question_bank (question_number, question, type, option_a, option_b, option_c, option_d, correct_answer, explanation, image, subject, class, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                foreach ($preview_data as $row) {
                    // Validation
                    if (empty($row['question'])) {
                        $errors[] = "Missing question text.";
                        continue;
                    }

                    // Map image filename if it was uploaded
                    $image_to_save = '';
                    if (!empty($row['image']) && isset($image_filenames[$row['image']])) {
                        $image_to_save = $image_filenames[$row['image']];
                    }

                    try {
                        $stmt->execute([
                            $row['question_number'],
                            $row['question'],
                            $row['type'],
                            $row['option_a'],
                            $row['option_b'],
                            $row['option_c'],
                            $row['option_d'],
                            $row['correct_answer'],
                            $row['explanation'],
                            $image_to_save,
                            $subject_name,
                            $class_name,
                            $dept_string
                        ]);
                        $uploaded_question_ids[] = $pdo->lastInsertId();
                        $success_count++;
                    } catch (PDOException $e) {
                        $errors[] = "Database error: " . $e->getMessage();
                    }
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), '1146') !== false) {
                    $error = 'Question Bank table does not exist. Please check the Dashboard for instructions on fixing the "Orphaned Tablespace" issue.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
                $preview_data = []; // Clear to stop further processing
            }

            // Auto-create exam if requested
            if (isset($meta['create_exam']) && $meta['create_exam'] && !empty($uploaded_question_ids)) {
                try {
                    $pdo->beginTransaction();
                    $exam_title = $subject_name . " Exam (" . date('M d, Y') . ")";
                    $stmt_exam = $pdo->prepare("INSERT INTO exams (title, class_id, subject_id, duration, departments) VALUES (?, ?, ?, ?, ?)");
                    $stmt_exam->execute([$exam_title, $meta['class_id'], $meta['subject_id'], $meta['exam_duration'], $dept_string]);
                    $exam_id = $pdo->lastInsertId();

                    $stmt_eq = $pdo->prepare("INSERT INTO exam_questions (exam_id, question_id, question_source) VALUES (?, ?, 'question_bank')");
                    foreach ($uploaded_question_ids as $qid) {
                        $stmt_eq->execute([$exam_id, $qid]);
                    }
                    $pdo->commit();
                    $message .= " Exam created automatically.";
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errors[] = "Failed to auto-create exam: " . $e->getMessage();
                }
            }

            $message = "$success_count questions uploaded successfully." . ($message ?? '');
            if (!empty($errors)) {
                $error = count($errors) . " errors occurred during upload.";
            }
            fss_audit('admin', 'question_bank_bulk_upload', 'question_bank', null, [
                'count'         => $success_count,
                'class_id'      => $meta['class_id'] ?? null,
                'subject_id'    => $meta['subject_id'] ?? null,
                'error_count'   => count($errors),
                'auto_create_exam' => !empty($meta['create_exam']),
            ], $admin_id);
            unset($_SESSION['preview_data']);
            unset($_SESSION['upload_meta']);
        }
    }
    }
}

$classes = [];
$subjects = [];
try {
    $classes = $pdo->query("SELECT * FROM classes ORDER BY name ASC")->fetchAll();
    $subjects = $pdo->query("SELECT * FROM subjects ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    // Ignore corruption errors
}
?>

<?php include('../includes/header.php'); ?>

<?php include('includes/navbar.php'); ?>

<div class="container mt-4 mb-5">
    <div class="row">
        <div class="col-12">
            <h2>Upload Questions</h2>
            <hr>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo $error; ?>
                    <?php if (isset($errors)): ?>
                        <ul class="mb-0">
                            <?php foreach ($errors as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form action="upload_questions.php" method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="class_id" class="form-label">Target Class</label>
                                <select name="class_id" id="class_id" class="form-select" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="subject_id" class="form-label">Target Course</label>
                                <select name="subject_id" id="subject_id" class="form-select" required>
                                    <option value="">Select Course</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>" <?php echo (isset($_GET['subject_id']) && $_GET['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(($subject['course_code'] ? $subject['course_code'] . ' - ' : '') . $subject['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label d-block">Question Type</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="question_type" id="type_obj" value="objective" required checked>
                                    <label class="form-check-label" for="type_obj">Objective (OBJ)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="question_type" id="type_theory" value="theory" required>
                                    <label class="form-check-label" for="type_theory">Theory</label>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label d-block">Target Departments</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="departments[]" id="dept_science" value="Science" checked>
                                    <label class="form-check-label" for="dept_science">Science</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="departments[]" id="dept_art" value="Art" checked>
                                    <label class="form-check-label" for="dept_art">Art</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="departments[]" id="dept_commercial" value="Commercial" checked>
                                    <label class="form-check-label" for="dept_commercial">Commercial</label>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="csv_file" class="form-label">CSV File</label>
                                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                                <div class="form-text mt-2">
                                    <strong>Format for OBJ:</strong> question_number, question, option_a, option_b, option_c, option_d, correct_answer (A/B/C/D), explanation, image_filename<br>
                                    <strong>Format for Theory:</strong> question_number, question
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="images" class="form-label">Upload Images (Diagrams)</label>
                                <input type="file" name="images[]" id="images" class="form-control" multiple accept="image/png, image/jpeg, image/jpg">
                                <small class="text-muted">Filename in CSV must match the uploaded filename.</small>
                            </div>
                            <div class="col-12 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="create_exam" id="create_exam" value="1">
                                    <label class="form-check-label fw-bold" for="create_exam">
                                        Auto-create Exam from these questions
                                    </label>
                                </div>
                                <div id="duration_box" class="mt-2" style="display:none;">
                                    <label for="exam_duration" class="form-label small">Exam Duration (minutes)</label>
                                    <input type="number" name="exam_duration" id="exam_duration" class="form-control form-control-sm" style="max-width: 150px;" value="30">
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="preview_csv" class="btn btn-primary">Preview Questions</button>
                    </form>
                </div>
            </div>

            <script>
                document.getElementById('create_exam').addEventListener('change', function() {
                    document.getElementById('duration_box').style.display = this.checked ? 'block' : 'none';
                });
            </script>

            <?php if (!empty($preview_data)): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Preview Questions</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Question</th>
                                        <th>Type</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($preview_data as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['question_number']); ?></td>
                                            <td><?php echo htmlspecialchars($row['question']); ?></td>
                                            <td><span class="badge bg-info"><?php echo ucfirst($row['type']); ?></span></td>
                                            <td>
                                                <?php if ($row['type'] === 'objective'): ?>
                                                    <small>
                                                        A: <?php echo htmlspecialchars($row['option_a']); ?> | 
                                                        B: <?php echo htmlspecialchars($row['option_b']); ?> | 
                                                        C: <?php echo htmlspecialchars($row['option_c']); ?> | 
                                                        D: <?php echo htmlspecialchars($row['option_d']); ?> <br>
                                                        <strong>Correct: <?php echo htmlspecialchars($row['correct_answer']); ?></strong>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">Theory question</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 p-3 bg-light border rounded">
                            <h6>Selected Metadata:</h6>
                            <p class="mb-1 small"><strong>Class (Level):</strong> <span id="meta_class">...</span></p>
                            <p class="mb-1 small"><strong>Course:</strong> <span id="meta_subject">...</span></p>
                            <p class="mb-0 small"><strong>Department:</strong> <span id="meta_depts">...</span></p>
                        </div>
                        <form action="upload_questions.php" method="POST">
                            <?php fss_csrf_field(); ?>
                            <button type="submit" name="final_upload" class="btn btn-success btn-lg px-5 mt-3">Confirm and Upload All</button>
                            <a href="upload_questions.php" class="btn btn-outline-secondary btn-lg px-5 mt-3">Cancel</a>
                        </form>
                    </div>
                </div>
                <script>
                    // Set metadata display in preview
                    document.getElementById('meta_class').innerText = document.querySelector('#class_id option:checked').text;
                    document.getElementById('meta_subject').innerText = document.querySelector('#subject_id option:checked').text;
                    let depts = [];
                    document.querySelectorAll('input[name="departments[]"]:checked').forEach(el => depts.push(el.nextElementSibling.innerText));
                    document.getElementById('meta_depts').innerText = depts.join(', ');
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>
