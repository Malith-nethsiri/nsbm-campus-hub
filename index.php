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

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Your form session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'The email or password you entered is incorrect.';
    } else {
        $statement = $pdo->prepare(
            'SELECT id, name, email, password, role FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if ($user !== false && password_verify($password, $user['password'])) {
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $updatePassword = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $updatePassword->execute([
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $user['id'],
                ]);
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: dashboard.php');
            exit;
        }

        $error = 'The email or password you entered is incorrect.';
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
    <title>Sign in | NSBM Campus Hub</title>
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
            <h1>Make every campus moment count.</h1>
            <p>Discover events, connect with clubs, and keep your academic schedule and campus life moving together.</p>
        </div>
        <p class="aside-footer small mb-0">NSBM Campus Event &amp; Smart Scheduling Hub</p>
    </section>

    <section class="auth-content">
        <div class="auth-card">
            <h2 class="h3">Welcome back</h2>
            <p class="text-muted mb-4">Sign in to continue to your campus hub.</p>

            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success" role="status">Account created. You can now sign in.</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
            <?php endif; ?>

            <form method="post" action="index.php" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input class="form-control" id="email" name="email" type="email" maxlength="255"
                           autocomplete="email" value="<?= $escape($email) ?>" required>
                    <div class="invalid-feedback">Enter a valid email address.</div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input class="form-control" id="password" name="password" type="password"
                           autocomplete="current-password" required>
                    <div class="invalid-feedback">Enter your password.</div>
                </div>

                <button class="btn btn-primary btn-lg w-100 mt-2" type="submit">Sign in</button>
            </form>

            <p class="text-center text-muted mt-4 mb-0">
                New to the hub? <a href="register.php">Create an account</a>
            </p>
        </div>
    </section>
</main>
<script src="assets/js/script.js" defer></script>
</body>
</html>
