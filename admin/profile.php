<?php
require_once(__DIR__ . '/../includes/security_bootstrap.php');
require_once(__DIR__ . '/../db.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/audit_log.php');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$message = '';
$error = '';

$stmt = $pdo->prepare('SELECT username FROM admins WHERE id = ?');
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { fss_verify_csrf(); } catch (Throwable $e) {
        $error = 'Security token expired or invalid. Please refresh and try again.';
    }
    if (!$error) {
    $new_username = trim($_POST['username']);
    $new_password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($new_username)) {
        $error = 'Username cannot be empty.';
    } else {
        try {
            if (!empty($new_password)) {
                if ($new_password !== $confirm_password) {
                    $error = 'Passwords do not match.';
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE admins SET username = ?, password = ? WHERE id = ?');
                    $stmt->execute([$new_username, $hashed_password, $admin_id]);
                    $_SESSION['admin_username'] = $new_username;
                    $message = 'Profile and password updated successfully.';
                }
            } else {
                $stmt = $pdo->prepare('UPDATE admins SET username = ? WHERE id = ?');
                $stmt->execute([$new_username, $admin_id]);
                $_SESSION['admin_username'] = $new_username;
                $message = 'Profile updated successfully.';
            }
            
            $admin['username'] = $new_username;
            
            fss_audit('admin', 'admin_profile_updated', 'admin', $admin_id, [
                'new_username' => $new_username,
                'password_changed' => !empty($new_password),
            ], $admin_id);
        } catch (PDOException $e) {
            $error = 'Error updating profile. Username might already be taken.';
        }
    }
    }
}
?>

<?php include('../includes/header.php'); ?>

<?php include('includes/navbar.php'); ?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Admin Profile Settings</h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <form action="profile.php" method="POST">
                        <?php fss_csrf_field(); ?>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($admin['username']); ?>" required>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label for="password" class="form-label">New Password (leave blank to keep current)</label>
                            <input type="password" name="password" id="password" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control">
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">Update Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>
