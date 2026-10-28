<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

if (($_SESSION['role'] ?? null) !== 'admin') {
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
$statuses = ['draft', 'published', 'cancelled', 'completed'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $postedEventId = $_POST['event_id'] ?? null;
    $eventId = is_string($postedEventId)
        ? filter_var($postedEventId, FILTER_VALIDATE_INT)
        : false;
    $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }
    if ($eventId === false || $eventId < 1) {
        $errors[] = 'The selected event is invalid.';
    }
    if (!in_array($status, $statuses, true)) {
        $errors[] = 'Choose a valid event status.';
    }

    if ($errors === []) {
        try {
            $existsStatement = $pdo->prepare('SELECT id FROM events WHERE id = :id');
            $existsStatement->execute(['id' => $eventId]);
            if ($existsStatement->fetchColumn() === false) {
                http_response_code(404);
                $errors[] = 'The selected event could not be found.';
            } else {
                $updateStatement = $pdo->prepare('UPDATE events SET status = :status WHERE id = :id');
                $updateStatement->execute([
                    'status' => $status,
                    'id' => $eventId,
                ]);
                $_SESSION['event_moderation_flash'] = 'Event status updated to '
                    . ucfirst($status) . '.';
                header('Location: manage_events.php');
                exit;
            }
        } catch (PDOException $exception) {
            error_log('Campus Hub event moderation failed: ' . $exception->getMessage());
            http_response_code(500);
            $errors[] = 'The event status could not be updated. Please try again later.';
        }
    }
}

$successMessage = (string) ($_SESSION['event_moderation_flash'] ?? '');
unset($_SESSION['event_moderation_flash']);

$eventsStatement = $pdo->query(
    'SELECT e.id, e.title, e.description, e.event_date, e.start_time, e.end_time, e.status, '
    . 'u.name AS organizer_name, f.facility_name, f.location AS facility_location '
    . 'FROM events e '
    . 'INNER JOIN users u ON u.id = e.organizer_id '
    . 'LEFT JOIN facilities f ON f.id = e.facility_id '
    . 'ORDER BY e.event_date ASC, e.start_time ASC, e.id ASC'
);
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Events | NSBM Campus Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand app-navbar navbar-dark">
    <div class="container dashboard-shell">
        <a class="navbar-brand fw-semibold" href="../dashboard.php">NSBM Campus Hub</a>
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-light btn-sm" href="../organizer/propose_event.php">Propose event</a>
            <a class="btn btn-outline-light btn-sm" href="../dashboard.php">Dashboard</a>
        </div>
    </div>
</nav>

<main class="container dashboard-shell py-5">
    <header class="mb-4">
        <p class="text-uppercase small fw-semibold text-primary mb-2">Administration</p>
        <h1 class="h2 mb-2">Event moderation</h1>
        <p class="text-muted mb-0">Review proposals and manage event publication status.</p>
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

    <section class="card dashboard-card">
        <div class="card-body p-4">
            <?php if ($events === []): ?>
                <p class="text-muted mb-0">There are no event proposals to review.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Date &amp; time</th>
                            <th scope="col">Venue</th>
                            <th scope="col">Organizer</th>
                            <th scope="col">Target batches</th>
                            <th scope="col">Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php $eventBatches = $batchMap[(int) $event['id']] ?? []; ?>
                            <tr>
                                <td class="text-break">
                                    <strong><?= $escape((string) $event['title']) ?></strong>
                                    <?php if ((string) ($event['description'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?= nl2br($escape((string) $event['description'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <?= $escape(date('d M Y', strtotime((string) $event['event_date']))) ?>
                                    <div class="small text-muted">
                                        <?= $escape(substr((string) $event['start_time'], 0, 5)) ?>
                                        &ndash; <?= $escape(substr((string) $event['end_time'], 0, 5)) ?>
                                    </div>
                                </td>
                                <td>
                                    <?= $escape((string) ($event['facility_name'] ?? 'Facility unavailable')) ?>
                                    <?php if (!empty($event['facility_location'])): ?>
                                        <div class="small text-muted"><?= $escape((string) $event['facility_location']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $escape((string) $event['organizer_name']) ?></td>
                                <td>
                                    <?php if ($eventBatches === []): ?>
                                        <span class="text-muted">No batches</span>
                                    <?php else: ?>
                                        <?= $escape(implode(', ', $eventBatches)) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="manage_events.php" class="d-flex gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="event_id" value="<?= $escape((string) $event['id']) ?>">
                                        <select class="form-select form-select-sm" name="status" aria-label="Status for <?= $escape((string) $event['title']) ?>">
                                            <?php foreach ($statuses as $status): ?>
                                                <option value="<?= $escape($status) ?>"
                                                    <?= $event['status'] === $status ? 'selected' : '' ?>>
                                                    <?= $escape(ucfirst($status)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-primary text-nowrap" type="submit">Update</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
