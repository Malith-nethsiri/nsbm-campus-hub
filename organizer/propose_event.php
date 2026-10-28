<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

if (!in_array($_SESSION['role'] ?? null, ['organizer', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

if (!isset($_SESSION['user_id']) || filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) === false) {
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

$facilitiesStatement = $pdo->query(
    'SELECT id, facility_name, capacity, location FROM facilities ORDER BY facility_name'
);
$facilities = $facilitiesStatement->fetchAll();

$batchesStatement = $pdo->query(
    'SELECT DISTINCT batch FROM timetables WHERE batch <> \'\' ORDER BY batch'
);
$availableBatches = array_map(
    static fn (array $row): string => (string) $row['batch'],
    $batchesStatement->fetchAll()
);

$errors = [];
$clashes = [];
$title = '';
$description = '';
$eventDate = '';
$startTime = '';
$endTime = '';
$facilityId = '';
$selectedBatches = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $readPostString = static function (string $key): string {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? $value : '';
    };

    $title = trim($readPostString('title'));
    $description = trim($readPostString('description'));
    $eventDate = trim($readPostString('event_date'));
    $startTime = trim($readPostString('start_time'));
    $endTime = trim($readPostString('end_time'));
    $facilityId = trim($readPostString('facility_id'));
    $csrfToken = $readPostString('csrf_token');
    $postedBatches = $_POST['target_batches'] ?? [];

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }

    if ($title === '' || mb_strlen($title) > 200) {
        $errors[] = 'Enter an event title of no more than 200 characters.';
    }

    if (!is_array($postedBatches)) {
        $errors[] = 'Choose one or more target batches.';
    } else {
        $selectedBatches = array_values(array_unique(array_filter(
            array_map(
                static fn ($batch): string => is_string($batch) ? trim($batch) : '',
                $postedBatches
            ),
            static fn (string $batch): bool => $batch !== ''
        )));
        if ($selectedBatches === []) {
            $errors[] = 'Choose at least one target batch.';
        }
    }

    $validEventDate = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        $validEventDate === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $validEventDate->format('Y-m-d') !== $eventDate
    ) {
        $errors[] = 'Choose a valid event date.';
        $validEventDate = null;
    }

    $start = null;
    if (preg_match('/^\d{2}:\d{2}$/', $startTime)) {
        $start = DateTimeImmutable::createFromFormat('!H:i', $startTime);
    }
    $end = null;
    if (preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        $end = DateTimeImmutable::createFromFormat('!H:i', $endTime);
    }
    if (
        $start === false || $end === false
        || $start === null || $end === null
        || $start->format('H:i') !== $startTime
        || $end->format('H:i') !== $endTime
        || $end <= $start
    ) {
        $errors[] = 'Choose valid 24-hour start and end times, with the end later than the start.';
    }

    $validatedFacilityId = filter_var($facilityId, FILTER_VALIDATE_INT);
    $facilityExists = false;
    if ($validatedFacilityId !== false && $validatedFacilityId > 0) {
        foreach ($facilities as $facility) {
            if ((int) $facility['id'] === $validatedFacilityId) {
                $facilityExists = true;
                break;
            }
        }
    }
    if (!$facilityExists) {
        $errors[] = 'Choose an available campus facility.';
    }

    if (strlen($description) > 65535) {
        $errors[] = 'The event description is too long.';
    }

    if ($selectedBatches !== []) {
        $knownBatches = array_fill_keys($availableBatches, true);
        foreach ($selectedBatches as $batch) {
            if (!isset($knownBatches[$batch])) {
                $errors[] = 'One or more selected batches are no longer available. Refresh and select again.';
                break;
            }
            if (mb_strlen($batch) > 80) {
                $errors[] = 'A selected batch name exceeds the supported length.';
                break;
            }
        }
    }

    if ($errors === [] && $validEventDate !== null && $start !== null && $end !== null) {
        $dayOfWeek = $validEventDate->format('l');
        $batchPlaceholders = [];
        $parameters = [
            'event_date' => $eventDate,
            'day_of_week' => $dayOfWeek,
            'proposed_start' => $start->format('H:i:s'),
            'proposed_end' => $end->format('H:i:s'),
        ];
        foreach ($selectedBatches as $index => $batch) {
            $placeholder = ':batch_' . $index;
            $batchPlaceholders[] = $placeholder;
            $parameters['batch_' . $index] = $batch;
        }

        $clashStatement = $pdo->prepare(
            'SELECT batch, module_name, start_time, end_time '
            . 'FROM timetables '
            . 'WHERE batch IN (' . implode(', ', $batchPlaceholders) . ') '
            . 'AND (class_date = :event_date OR (class_date IS NULL AND day_of_week = :day_of_week)) '
            . 'AND start_time < :proposed_end AND end_time > :proposed_start '
            . 'ORDER BY batch, start_time, module_name'
        );
        $clashStatement->execute($parameters);
        $clashes = $clashStatement->fetchAll();
    }

    if ($errors === [] && $clashes === []) {
        try {
            $pdo->beginTransaction();
            $eventStatement = $pdo->prepare(
                'INSERT INTO events '
                . '(title, description, organizer_id, event_date, start_time, end_time, facility_id) '
                . 'VALUES (:title, :description, :organizer_id, :event_date, :start_time, :end_time, :facility_id)'
            );
            $eventStatement->execute([
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'organizer_id' => (int) $_SESSION['user_id'],
                'event_date' => $eventDate,
                'start_time' => $startTime . ':00',
                'end_time' => $endTime . ':00',
                'facility_id' => $validatedFacilityId,
            ]);

            $eventId = (int) $pdo->lastInsertId();
            $targetStatement = $pdo->prepare(
                'INSERT INTO event_target_batches (event_id, batch_name) VALUES (:event_id, :batch_name)'
            );
            foreach ($selectedBatches as $batch) {
                $targetStatement->execute([
                    'event_id' => $eventId,
                    'batch_name' => $batch,
                ]);
            }
            $pdo->commit();

            $_SESSION['event_proposal_flash'] = 'Your event proposal was saved as a draft.';
            header('Location: propose_event.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Campus Hub event proposal failed: ' . $exception->getMessage());
            http_response_code(500);
            $errors[] = 'The proposal could not be saved. No event or target batches were committed.';
        }
    }
}

$successMessage = (string) ($_SESSION['event_proposal_flash'] ?? '');
unset($_SESSION['event_proposal_flash']);
$escapedCsrfToken = $escape($_SESSION['csrf_token']);
$escapedName = $escape((string) ($_SESSION['name'] ?? ''));

$myEventsStatement = $pdo->prepare(
    'SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.status, '
    . 'f.facility_name FROM events e '
    . 'LEFT JOIN facilities f ON f.id = e.facility_id '
    . 'WHERE e.organizer_id = :organizer_id '
    . 'ORDER BY e.id DESC'
);
$myEventsStatement->execute(['organizer_id' => (int) $_SESSION['user_id']]);
$myEvents = $myEventsStatement->fetchAll();

$myEventBatchMap = [];
if ($myEvents !== []) {
    $eventIds = array_map(static fn (array $event): int => (int) $event['id'], $myEvents);
    $placeholders = [];
    $parameters = [];
    foreach ($eventIds as $index => $eventId) {
        $placeholder = ':event_' . $index;
        $placeholders[] = $placeholder;
        $parameters['event_' . $index] = $eventId;
    }

    $myBatchesStatement = $pdo->prepare(
        'SELECT event_id, batch_name FROM event_target_batches '
        . 'WHERE event_id IN (' . implode(', ', $placeholders) . ') ORDER BY batch_name'
    );
    $myBatchesStatement->execute($parameters);
    foreach ($myBatchesStatement->fetchAll() as $row) {
        $myEventBatchMap[(int) $row['event_id']][] = (string) $row['batch_name'];
    }
}

$statusClasses = [
    'draft' => 'text-bg-warning',
    'published' => 'text-bg-success',
    'cancelled' => 'text-bg-danger',
    'completed' => 'text-bg-secondary',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Propose an Event | NSBM Campus Hub</title>
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
        <p class="text-uppercase small fw-semibold text-primary mb-2">Event planning</p>
        <h1 class="h2 mb-2">Propose a campus event</h1>
        <p class="text-muted mb-0">Check your audience's timetable before submitting an event draft.</p>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= $escape($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <h2 class="h6 alert-heading">Please correct the following:</h2>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($clashes !== []): ?>
        <div class="alert alert-warning border-warning" role="alert">
            <h2 class="h5 alert-heading">Timetable clash detected</h2>
            <p>The proposal was not saved. Choose another time or adjust the target batches:</p>
            <ul class="mb-0">
                <?php foreach ($clashes as $clash): ?>
                    <li>
                        <strong><?= $escape((string) $clash['batch']) ?></strong>:
                        <?= $escape((string) $clash['module_name']) ?>,
                        <?= $escape(substr((string) $clash['start_time'], 0, 5)) ?>
                        &ndash; <?= $escape(substr((string) $clash['end_time'], 0, 5)) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card dashboard-card">
        <div class="card-body p-4 p-lg-5">
            <?php if ($facilities === []): ?>
                <div class="alert alert-info mb-0" role="status">
                    No facilities are available yet. Ask an administrator to add a venue before proposing an event.
                </div>
            <?php elseif ($availableBatches === []): ?>
                <div class="alert alert-info mb-0" role="status">
                    No timetable batches are available yet. Ask an administrator to import the timetable before proposing an event.
                </div>
            <?php else: ?>
                <form method="post" action="propose_event.php">
                    <input type="hidden" name="csrf_token" value="<?= $escapedCsrfToken ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="title">Event title</label>
                            <input class="form-control" id="title" name="title" type="text"
                                   maxlength="200" value="<?= $escape($title) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description"
                                      rows="4" maxlength="65535"><?= $escape($description) ?></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="event_date">Event date</label>
                            <input class="form-control" id="event_date" name="event_date" type="date"
                                   value="<?= $escape($eventDate) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="start_time">Start time</label>
                            <input class="form-control" id="start_time" name="start_time" type="time"
                                   value="<?= $escape($startTime) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="end_time">End time</label>
                            <input class="form-control" id="end_time" name="end_time" type="time"
                                   value="<?= $escape($endTime) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="facility_id">Campus facility</label>
                            <select class="form-select" id="facility_id" name="facility_id" required>
                                <option value="">Choose a venue</option>
                                <?php foreach ($facilities as $facility): ?>
                                    <?php
                                    $facilityOption = $facility['facility_name']
                                        . ' (' . $facility['capacity'] . ' seats) — ' . $facility['location'];
                                    ?>
                                    <option value="<?= $escape((string) $facility['id']) ?>"
                                        <?= $facilityId === (string) $facility['id'] ? 'selected' : '' ?>>
                                        <?= $escape((string) $facilityOption) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <fieldset class="col-12">
                            <legend class="form-label fs-6">Target audience batches</legend>
                            <p class="form-text mt-0">Choose one or more batches. Their timetable slots will be checked for conflicts.</p>
                            <div class="row g-2">
                                <?php foreach ($availableBatches as $index => $batch): ?>
                                    <?php $checkboxId = 'target_batch_' . $index; ?>
                                    <div class="col-sm-6 col-lg-4">
                                        <div class="form-check border rounded-3 p-3 ps-5 h-100">
                                            <input class="form-check-input" id="<?= $escape($checkboxId) ?>"
                                                   name="target_batches[]" type="checkbox"
                                                   value="<?= $escape($batch) ?>"
                                                <?= in_array($batch, $selectedBatches, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="<?= $escape($checkboxId) ?>">
                                                <?= $escape($batch) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <div class="col-12 pt-2">
                            <button class="btn btn-primary btn-lg" type="submit">Check clashes &amp; save draft</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="card dashboard-card mt-4" id="my-proposals">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h4 card-title mb-1">My event proposals</h2>
                    <p class="text-muted mb-0">Only events submitted from your account are listed here.</p>
                </div>
                <span class="badge rounded-pill text-bg-light"><?= count($myEvents) ?> total</span>
            </div>

            <?php if ($myEvents === []): ?>
                <p class="text-muted mb-0">You have not submitted any event proposals yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Date &amp; time</th>
                            <th scope="col">Facility</th>
                            <th scope="col">Target batches</th>
                            <th scope="col">Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($myEvents as $myEvent): ?>
                            <?php
                            $eventStatus = (string) $myEvent['status'];
                            $eventBatches = $myEventBatchMap[(int) $myEvent['id']] ?? [];
                            ?>
                            <tr>
                                <th scope="row"><?= $escape((string) $myEvent['title']) ?></th>
                                <td class="text-nowrap">
                                    <?= $escape(date('d M Y', strtotime((string) $myEvent['event_date']))) ?>
                                    <div class="small text-muted">
                                        <?= $escape(substr((string) $myEvent['start_time'], 0, 5)) ?>
                                        &ndash; <?= $escape(substr((string) $myEvent['end_time'], 0, 5)) ?>
                                    </div>
                                </td>
                                <td><?= $escape((string) ($myEvent['facility_name'] ?? 'Facility unavailable')) ?></td>
                                <td><?= $eventBatches === [] ? 'None' : $escape(implode(', ', $eventBatches)) ?></td>
                                <td>
                                    <span class="badge <?= $escape($statusClasses[$eventStatus] ?? 'text-bg-secondary') ?>">
                                        <?= $escape(ucfirst($eventStatus)) ?>
                                    </span>
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
