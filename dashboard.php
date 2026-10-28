<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

if (
    !isset($_SESSION['user_id'], $_SESSION['name'], $_SESSION['role'])
    || !in_array($_SESSION['role'], ['admin', 'organizer', 'student'], true)
) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$role = (string) $_SESSION['role'];
$roleContent = [
    'admin' => [
        'label' => 'Administrator',
        'description' => 'Oversee campus events, facilities, clubs, and scheduling activity.',
        'cards' => [
            ['title' => 'Manage facilities', 'description' => 'Add and review campus venues and their capacities.', 'href' => 'admin/manage_facilities.php', 'action' => 'Open facilities'],
            ['title' => 'Manage timetables', 'description' => 'Import institutional timetable slots from a CSV file.', 'href' => 'admin/manage_timetables.php', 'action' => 'Open timetables'],
            ['title' => 'Propose an event', 'description' => 'Check a proposed event against student timetables.', 'href' => 'organizer/propose_event.php', 'action' => 'Propose event'],
            ['title' => 'Manage events', 'description' => 'Review proposals and publish or update event status.', 'href' => 'admin/manage_events.php', 'action' => 'Manage events'],
            ['title' => 'Platform overview', 'description' => 'Administration tools will appear here as the hub grows.'],
        ],
    ],
    'organizer' => [
        'label' => 'Event Organizer',
        'description' => 'Plan campus activities and keep your event details in one place.',
        'cards' => [
            ['title' => 'Propose an event', 'description' => 'Check your event time against the selected batches and save a draft.', 'href' => 'organizer/propose_event.php', 'action' => 'Propose event'],
            ['title' => 'Your events', 'description' => 'Review your proposals and see their moderation status.', 'href' => 'organizer/propose_event.php#my-proposals', 'action' => 'View my events'],
            ['title' => 'Find a venue', 'description' => 'Explore facility options when planning an event.'],
            ['title' => 'Reach your audience', 'description' => 'Target event announcements to the right batches.'],
        ],
    ],
    'student' => [
        'label' => 'Student',
        'description' => 'Keep up with campus activities and plan around your studies.',
        'cards' => [
            ['title' => 'Discover events', 'description' => 'Browse published campus events and manage your RSVPs.', 'href' => 'student/browse_events.php', 'action' => 'Browse events'],
            ['title' => 'Your timetable', 'description' => 'See academic scheduling alongside campus activities.'],
            ['title' => 'Clubs and communities', 'description' => 'Explore clubs and find ways to get involved.'],
        ],
    ],
];

$content = $roleContent[$role];
$name = htmlspecialchars((string) $_SESSION['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$roleLabel = htmlspecialchars($content['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | NSBM Campus Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand app-navbar navbar-dark">
    <div class="container dashboard-shell">
        <a class="navbar-brand fw-semibold" href="dashboard.php">NSBM Campus Hub</a>
        <div class="d-flex align-items-center gap-3">
            <span class="d-none d-sm-inline text-white-50"><?= $name ?></span>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button class="btn btn-outline-light btn-sm" type="submit">Sign out</button>
            </form>
        </div>
    </div>
</nav>

<main class="container dashboard-shell py-5">
    <section class="dashboard-hero mb-4">
        <span class="badge rounded-pill text-bg-light mb-3"><?= $roleLabel ?></span>
        <h1 class="h2 mb-2">Welcome, <?= $name ?>.</h1>
        <p class="mb-0 text-white-50"><?= htmlspecialchars($content['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </section>

    <div class="d-flex align-items-end justify-content-between mb-3">
        <div>
            <h2 class="h4 mb-1">Your campus hub</h2>
            <p class="text-muted mb-0">A starting point for your NSBM campus activities.</p>
        </div>
    </div>
    <div class="row g-3">
        <?php foreach ($content['cards'] as $card): ?>
            <div class="col-md-6 col-lg-4">
                <section class="card dashboard-card">
                    <div class="card-body p-4">
                        <h3 class="card-title h5"><?= htmlspecialchars($card['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                        <p class="card-text text-muted mb-0"><?= htmlspecialchars($card['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php if (isset($card['href'], $card['action'])): ?>
                            <a class="btn btn-sm btn-outline-primary mt-3"
                               href="<?= htmlspecialchars($card['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?= htmlspecialchars($card['action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
