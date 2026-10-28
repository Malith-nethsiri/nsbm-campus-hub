<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require __DIR__ . '/config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$name = '';
$email = '';
$role = 'student';
$batch = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $role = (string) ($_POST['role'] ?? '');
    $batch = trim((string) ($_POST['batch'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $adminSetupKey = (string) ($_POST['admin_setup_key'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'Your form session expired. Please try again.';
    }
    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = 'Enter a name of no more than 120 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 12 || strlen($password) > 255) {
        $errors[] = 'Your password must be between 12 and 255 characters.';
    }
    if (!in_array($role, ['student', 'organizer', 'admin'], true)) {
        $errors[] = 'Choose a valid account role.';
    }
    if ($role === 'student' && $batch === '') {
        $errors[] = 'Enter your batch for a student account.';
    }
    if (mb_strlen($batch) > 80) {
        $errors[] = 'Batch must be no more than 80 characters.';
    }

    if ($role === 'admin') {
        $configuredAdminKey = getenv('ADMIN_SETUP_KEY');
        if ($configuredAdminKey === false || $configuredAdminKey === '') {
            $errors[] = 'Admin registration is currently disabled.';
        } elseif ($adminSetupKey === '' || !hash_equals($configuredAdminKey, $adminSetupKey)) {
            $errors[] = 'The Admin setup key is invalid.';
        }
    }

    if ($errors === []) {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO users (name, email, password, role, batch) '
                . 'VALUES (:name, :email, :password, :role, :batch)'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'batch' => $batch !== '' ? $batch : null,
            ]);

            header('Location: index.php?registered=1');
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors[] = 'An account with that email address already exists.';
            } else {
                error_log('Campus Hub registration failed: ' . $exception->getMessage());
                http_response_code(500);
                $errors[] = 'We could not create your account right now. Please try again later.';
            }
        }
    }
}

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | NSBM Campus Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<main class="auth-layout">
    <section class="auth-aside">
        <div class="brand-mark">
            <span class="brand-icon" aria-hidden="true">N</span>
            <span>NSBM Campus Hub</span>
        </div>
        <div>
            <p class="text-uppercase small fw-semibold mb-2">Your campus, in sync</p>
            <h1>Join the campus community.</h1>
            <p>Create an account to stay connected with events, clubs, and smarter scheduling at NSBM.</p>
        </div>
        <p class="aside-footer small mb-0">NSBM Campus Event &amp; Smart Scheduling Hub</p>
    </section>

    <section class="auth-content">
        <div class="auth-card">
            <h2 class="h3">Create your account</h2>
            <p class="text-muted mb-4">Enter your details to get started.</p>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= $escape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="register.php" class="needs-validation" data-register-form novalidate>
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">

                <div class="mb-3">
                    <label for="name" class="form-label">Full name</label>
                    <input class="form-control" id="name" name="name" type="text" maxlength="120"
                           autocomplete="name" value="<?= $escape($name) ?>" required>
                    <div class="invalid-feedback">Enter your name.</div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input class="form-control" id="email" name="email" type="email" maxlength="255"
                           autocomplete="email" value="<?= $escape($email) ?>" required>
                    <div class="invalid-feedback">Enter a valid email address.</div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input class="form-control" id="password" name="password" type="password"
                           minlength="12" maxlength="255" autocomplete="new-password" required>
                    <div class="form-text">Use at least 12 characters.</div>
                    <div class="invalid-feedback">Use a password with at least 12 characters.</div>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label">Account role</label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="student" <?= $role === 'student' ? 'selected' : '' ?>>Student</option>
                        <option value="organizer" <?= $role === 'organizer' ? 'selected' : '' ?>>Organizer</option>
                        <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin (setup key required)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="batch" class="form-label">Batch</label>
                    <input class="form-control" id="batch" name="batch" type="text" maxlength="80"
                           value="<?= $escape($batch) ?>" aria-describedby="batch-help">

                    <div class="form-text" id="batch-help">Required for students; optional for organizers and admins.</div>
                </div>

                <div class="mb-3" id="admin-key-group" hidden>
                    <label for="admin_setup_key" class="form-label">Admin setup key</label>
                    <input class="form-control" id="admin_setup_key" name="admin_setup_key" type="password"
                           autocomplete="off">
                    <div class="form-text">Admin registration is only available when a setup key is configured.</div>
                </div>

                <button class="btn btn-primary btn-lg w-100 mt-2" type="submit">Create account</button>
            </form>

            <p class="text-center text-muted mt-4 mb-0">
                Already registered? <a href="index.php">Sign in</a>
            </p>
        </div>
    </section>
</main>
<script src="assets/js/script.js" defer></script>
</body>
</html>
