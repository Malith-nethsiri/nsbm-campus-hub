<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

if (
    ($_SESSION['role'] ?? null) !== 'student'
    || !isset($_SESSION['user_id'])
    || filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) === false
) {
    header('Location: ../index.php');
    exit;
}

require __DIR__ . '/../config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$userId = (int) $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $postedEventId = $_POST['event_id'] ?? null;
    $eventId = is_string($postedEventId)
        ? filter_var($postedEventId, FILTER_VALIDATE_INT)
        : false;
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }
    if ($eventId === false || $eventId < 1) {
        $errors[] = 'The selected event is invalid.';
    }
    if (!in_array($action, ['rsvp', 'cancel'], true)) {
        $errors[] = 'The requested RSVP action is invalid.';
    }

    if ($errors === []) {
        try {
            if ($action === 'rsvp') {
                $registerStatement = $pdo->prepare(
                    'INSERT IGNORE INTO event_registrations (event_id, user_id) '
                    . 'SELECT id, :user_id FROM events WHERE id = :event_id AND status = \'published\''
                );
                $registerStatement->execute([
                    'user_id' => $userId,
                    'event_id' => $eventId,
                ]);
                if ($registerStatement->rowCount() > 0) {
                    $_SESSION['event_rsvp_flash'] = 'You are registered for this event.';
                } else {
                    $publishedStatement = $pdo->prepare(
                        'SELECT status FROM events WHERE id = :id'
                    );
                    $publishedStatement->execute(['id' => $eventId]);
                    if ($publishedStatement->fetchColumn() !== 'published') {
                        $errors[] = 'This event is no longer available for registration.';
                        http_response_code(404);
                    } else {
                        $_SESSION['event_rsvp_flash'] = 'You are already registered for this event.';
                    }
                }
            } else {
                $deleteStatement = $pdo->prepare(
                    'DELETE FROM event_registrations '
                    . 'WHERE event_id = :event_id AND user_id = :user_id'
                );
                $deleteStatement->execute([
                    'event_id' => $eventId,
                    'user_id' => $userId,
                ]);
                $_SESSION['event_rsvp_flash'] = $deleteStatement->rowCount() > 0
                    ? 'Your registration has been cancelled.'
                    : 'You were not registered for this event.';
            }

            if ($errors === []) {
                header('Location: browse_events.php');
                exit;
            }
        } catch (PDOException $exception) {
            error_log('Campus Hub RSVP action failed: ' . $exception->getMessage());
            http_response_code(500);
            $errors[] = 'Your registration could not be updated. Please try again later.';
        }
    }
}

$successMessage = (string) ($_SESSION['event_rsvp_flash'] ?? '');
unset($_SESSION['event_rsvp_flash']);

$eventsStatement = $pdo->prepare(
    'SELECT e.id, e.title, e.description, e.event_date, e.start_time, e.end_time, '
    . 'u.name AS organizer_name, f.facility_name, f.location AS facility_location, '
    . 'EXISTS ('
    . 'SELECT 1 FROM event_registrations er '
    . 'WHERE er.event_id = e.id AND er.user_id = :user_id'
    . ') AS is_registered, '
    . '(SELECT COUNT(*) FROM event_registrations er_count WHERE er_count.event_id = e.id) AS registration_count '
    . 'FROM events e '
    . 'INNER JOIN users u ON u.id = e.organizer_id '
    . 'LEFT JOIN facilities f ON f.id = e.facility_id '
    . 'WHERE e.status = \'published\' '
    . 'ORDER BY e.event_date ASC, e.start_time ASC, e.id ASC'
);
$eventsStatement->execute(['user_id' => $userId]);
$events = $eventsStatement->fetchAll();

$batchMap = [];
if ($events !== []) {
    $eventIds = array_map(static fn (array $event): int => (int) $event['id'], $events);
    $placeholders = [];
    $parameters = [];
    foreach ($eventIds as $index => $id) {
        $placeholder = ':event_' . $index;
        $placeholders[] = $placeholder;
        $parameters['event_' . $index] = $id;
    }

    $batchesStatement = $pdo->prepare(
        'SELECT event_id, batch_name FROM event_target_batches '
        . 'WHERE event_id IN (' . implode(', ', $placeholders) . ') ORDER BY batch_name'
    );
    $batchesStatement->execute($parameters);
    foreach ($batchesStatement->fetchAll() as $row) {
        $batchMap[(int) $row['event_id']][] = (string) $row['batch_name'];
    }
}

$escapedName = $escape((string) ($_SESSION['name'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Discover Events | NSBM Campus Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand app-navbar navbar-dark">
    <div class="container dashboard-shell">
        <a class="navbar-brand fw-semibold" href="../dashboard.php">NSBM Campus Hub</a>
        <div class="d-flex align-items-center gap-3">
            <span class="d-none d-sm-inline text-white-50"><?= $escapedName ?></span>
            <a class="btn btn-outline-light btn-sm" href="../dashboard.php">Dashboard</a>
        </div>
    </div>
</nav>

<main class="container dashboard-shell py-5">
    <header class="mb-4">
        <p class="text-uppercase small fw-semibold text-primary mb-2">Student portal</p>
        <h1 class="h2 mb-2">Discover campus events</h1>
        <p class="text-muted mb-0">Explore published events and register for the ones you want to attend.</p>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= $escape($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($events === []): ?>
        <section class="card dashboard-card">
            <div class="card-body p-5 text-center">
                <h2 class="h4">No published events yet</h2>
                <p class="text-muted mb-0">Check back soon for upcoming campus activities.</p>
            </div>
        </section>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($events as $event): ?>
                <?php $eventBatches = $batchMap[(int) $event['id']] ?? []; ?>
                <div class="col-md-6 col-xl-4">
                    <article class="card dashboard-card">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <span class="badge rounded-pill text-bg-success">Published</span>
                                <span class="small text-muted">
                                    <?= $escape((string) $event['registration_count']) ?>
                                    <?= (int) $event['registration_count'] === 1 ? 'attendee' : 'attendees' ?>
                                </span>
                            </div>
                            <h2 class="card-title h4"><?= $escape((string) $event['title']) ?></h2>
                            <?php if ((string) ($event['description'] ?? '') !== ''): ?>
                                <p class="text-muted"><?= nl2br($escape((string) $event['description'])) ?></p>
                            <?php endif; ?>

                            <dl class="small mb-3">
                                <dt>Date &amp; time</dt>
                                <dd>
                                    <?= $escape(date('l, d M Y', strtotime((string) $event['event_date']))) ?><br>
                                    <?= $escape(substr((string) $event['start_time'], 0, 5)) ?>
                                    &ndash; <?= $escape(substr((string) $event['end_time'], 0, 5)) ?>
                                </dd>
                                <dt>Venue</dt>
                                <dd>
                                    <?= $escape((string) ($event['facility_name'] ?? 'Venue to be confirmed')) ?>
                                    <?php if (!empty($event['facility_location'])): ?>
                                        <span class="text-muted"> &middot; <?= $escape((string) $event['facility_location']) ?></span>
                                    <?php endif; ?>
                                </dd>
                                <dt>Organizer</dt>
                                <dd><?= $escape((string) $event['organizer_name']) ?></dd>
                                <dt>Target batches</dt>
                                <dd class="mb-0">
                                    <?= $eventBatches === [] ? 'Open to all' : $escape(implode(', ', $eventBatches)) ?>
                                </dd>
                            </dl>

                            <form method="post" action="browse_events.php" class="mt-auto">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="event_id" value="<?= $escape((string) $event['id']) ?>">
                                <?php if ((int) $event['is_registered'] === 1): ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <button class="btn btn-outline-secondary w-100" type="submit">Cancel RSVP</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="rsvp">
                                    <button class="btn btn-primary w-100" type="submit">RSVP / Register</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
