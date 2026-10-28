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

$errors = [];
$facilityName = '';
$capacity = '';
$location = '';
$editingFacility = null;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId === false || $editId < 1) {
        http_response_code(400);
        $errors[] = 'The requested facility ID is invalid.';
    } else {
        $editStatement = $pdo->prepare(
            'SELECT id, facility_name, capacity, location FROM facilities WHERE id = :id'
        );
        $editStatement->execute(['id' => $editId]);
        $editingFacility = $editStatement->fetch();
        if ($editingFacility === false) {
            http_response_code(404);
            $errors[] = 'The requested facility was not found.';
        } else {
            $facilityName = (string) $editingFacility['facility_name'];
            $capacity = (string) $editingFacility['capacity'];
            $location = (string) $editingFacility['location'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    $facilityName = trim((string) ($_POST['facility_name'] ?? ''));
    $capacity = trim((string) ($_POST['capacity'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $facilityId = filter_var($_POST['facility_id'] ?? null, FILTER_VALIDATE_INT);
    if ($action === 'update' && $facilityId !== false && $facilityId > 0) {
        $editingFacility = ['id' => $facilityId];
    }

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'Your form session expired. Please try again.';
    }
    if (!in_array($action, ['create', 'update', 'delete'], true)) {
        $errors[] = 'The requested facility action is invalid.';
    }
    if ($action !== 'delete') {
        if ($facilityName === '' || mb_strlen($facilityName) > 160) {
            $errors[] = 'Enter a facility name of no more than 160 characters.';
        }
        $validatedCapacity = filter_var($capacity, FILTER_VALIDATE_INT);
        if ($validatedCapacity === false || $validatedCapacity < 1 || $validatedCapacity > 4294967295) {
            $errors[] = 'Capacity must be a whole number between 1 and 4,294,967,295.';
        }
        if ($location === '' || mb_strlen($location) > 255) {
            $errors[] = 'Enter a location of no more than 255 characters.';
        }
    } else {
        $validatedCapacity = false;
    }
    if (
        in_array($action, ['update', 'delete'], true)
        && ($facilityId === false || $facilityId < 1)
    ) {
        $errors[] = 'The requested facility ID is invalid.';
    }

    if ($errors === [] && $action === 'delete') {
        try {
            $statement = $pdo->prepare('DELETE FROM facilities WHERE id = :id');
            $statement->execute(['id' => $facilityId]);
            if ($statement->rowCount() === 0) {
                http_response_code(404);
                $errors[] = 'The facility was not found or has already been removed.';
            } else {
                $_SESSION['facility_flash'] = 'Facility deleted successfully.';
            }
        } catch (PDOException $exception) {
            error_log('Campus Hub facility deletion failed: ' . $exception->getMessage());
            http_response_code(500);
            $errors[] = 'We could not delete this facility right now. Please try again later.';
        }

        if ($errors === []) {
            header('Location: manage_facilities.php');
            exit;
        }
    }

    if ($errors === [] && in_array($action, ['create', 'update'], true)) {
        try {
            if ($action === 'update') {
                $existsStatement = $pdo->prepare('SELECT id FROM facilities WHERE id = :id');
                $existsStatement->execute(['id' => $facilityId]);
                if ($existsStatement->fetchColumn() === false) {
                    http_response_code(404);
                    $errors[] = 'The facility was not found or has already been removed.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE facilities SET facility_name = :facility_name, capacity = :capacity, '
                        . 'location = :location WHERE id = :id'
                    );
                    $statement->execute([
                        'facility_name' => $facilityName,
                        'capacity' => $validatedCapacity,
                        'location' => $location,
                        'id' => $facilityId,
                    ]);
                    $_SESSION['facility_flash'] = 'Facility updated successfully.';
                }
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO facilities (facility_name, capacity, location) '
                    . 'VALUES (:facility_name, :capacity, :location)'
                );
                $statement->execute([
                    'facility_name' => $facilityName,
                    'capacity' => $validatedCapacity,
                    'location' => $location,
                ]);
                $_SESSION['facility_flash'] = 'Facility added successfully.';
            }

            if ($errors === []) {
                header('Location: manage_facilities.php');
                exit;
            }
        } catch (PDOException $exception) {
            error_log('Campus Hub facility save failed: ' . $exception->getMessage());
            http_response_code(500);
            $errors[] = 'We could not save this facility right now. Please try again later.';
        }
    }
}

$flash = (string) ($_SESSION['facility_flash'] ?? '');
unset($_SESSION['facility_flash']);

$statement = $pdo->query(
    'SELECT id, facility_name, capacity, location FROM facilities ORDER BY facility_name, id'
);
$facilities = $statement->fetchAll();

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
    <title>Manage Facilities | NSBM Campus Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand app-navbar navbar-dark">
    <div class="container dashboard-shell">
        <a class="navbar-brand fw-semibold" href="../dashboard.php">NSBM Campus Hub</a>
        <div class="d-flex align-items-center gap-3">
            <a class="btn btn-outline-light btn-sm" href="manage_timetables.php">Manage timetables</a>
            <a class="btn btn-outline-light btn-sm" href="../dashboard.php">Dashboard</a>
        </div>
    </div>
</nav>

<main class="container dashboard-shell py-5">
    <header class="mb-4">
        <p class="text-uppercase small fw-semibold text-primary mb-2">Administration</p>
        <h1 class="h2 mb-2">Campus facilities</h1>
        <p class="text-muted mb-0">Add and review the venues available for campus events.</p>
    </header>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success" role="status"><?= $escape($flash) ?></div>
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

    <section class="card dashboard-card mb-4">
        <div class="card-body p-4">
            <h2 class="h5 card-title mb-3"><?= $editingFacility !== null ? 'Edit facility' : 'Add a facility' ?></h2>
            <form method="post" action="manage_facilities.php" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="<?= $editingFacility !== null ? 'update' : 'create' ?>">
                <?php if ($editingFacility !== null): ?>
                    <input type="hidden" name="facility_id" value="<?= $escape((string) $editingFacility['id']) ?>">
                <?php endif; ?>
                <div class="col-md-5">
                    <label class="form-label" for="facility_name">Facility name</label>
                    <input class="form-control" id="facility_name" name="facility_name" type="text"
                           maxlength="160" value="<?= $escape($facilityName) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="capacity">Capacity (seats)</label>
                    <input class="form-control" id="capacity" name="capacity" type="number"
                           min="1" max="4294967295" step="1" value="<?= $escape($capacity) ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="location">Building / area</label>
                    <input class="form-control" id="location" name="location" type="text"
                           maxlength="255" value="<?= $escape($location) ?>" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">
                        <?= $editingFacility !== null ? 'Save changes' : 'Add facility' ?>
                    </button>
                    <?php if ($editingFacility !== null): ?>
                        <a class="btn btn-outline-secondary" href="manage_facilities.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="card dashboard-card">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 card-title mb-0">Existing facilities</h2>
                <span class="badge rounded-pill text-bg-light"><?= count($facilities) ?> total</span>
            </div>
            <?php if ($facilities === []): ?>
                <p class="text-muted mb-0">No facilities have been added yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th scope="col">Facility</th>
                            <th scope="col">Capacity</th>
                            <th scope="col">Location</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($facilities as $facility): ?>
                            <tr>
                                <th scope="row"><?= $escape((string) $facility['facility_name']) ?></th>
                                <td><?= $escape((string) $facility['capacity']) ?> seats</td>
                                <td><?= $escape((string) $facility['location']) ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="manage_facilities.php?edit=<?= $escape((string) $facility['id']) ?>">Edit</a>
                                    <form method="post" action="manage_facilities.php" class="d-inline"
                                          onsubmit="return confirm('Delete this facility?')">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="facility_id" value="<?= $escape((string) $facility['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
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
