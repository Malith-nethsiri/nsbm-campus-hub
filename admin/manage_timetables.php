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

function campusHubParseTimeRange(string $value): ?array
{
    if (!preg_match(
        '/^\s*(\d{1,2})[.:](\d{2})\s*(am|pm)\s*-\s*(\d{1,2})[.:](\d{2})\s*(am|pm)\s*$/i',
        $value,
        $matches
    )) {
        return null;
    }

    $toMinutes = static function (string $hour, string $minute, string $meridiem): ?int {
        $hourValue = (int) $hour;
        $minuteValue = (int) $minute;
        if ($hourValue < 1 || $hourValue > 12 || $minuteValue > 59) {
            return null;
        }

        $hourValue %= 12;
        if (strtolower($meridiem) === 'pm') {
            $hourValue += 12;
        }

        return $hourValue * 60 + $minuteValue;
    };

    $startMinutes = $toMinutes($matches[1], $matches[2], $matches[3]);
    $endMinutes = $toMinutes($matches[4], $matches[5], $matches[6]);
    if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
        return null;
    }

    return [
        sprintf('%02d:%02d:00', intdiv($startMinutes, 60), $startMinutes % 60),
        sprintf('%02d:%02d:00', intdiv($endMinutes, 60), $endMinutes % 60),
    ];
}

function campusHubParse24HourTime(string $value): ?DateTimeImmutable
{
    if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $value)) {
        return null;
    }

    $format = strlen($value) === 8 ? '!H:i:s' : '!H:i';
    $time = DateTimeImmutable::createFromFormat($format, $value);
    $issues = DateTimeImmutable::getLastErrors();
    if (
        $time === false
        || ($issues !== false && ($issues['warning_count'] > 0 || $issues['error_count'] > 0))
        || $time->format(strlen($value) === 8 ? 'H:i:s' : 'H:i') !== $value
    ) {
        return null;
    }

    return $time;
}

function campusHubParseSourceTimetable(array $rows, array $daysOfWeek): array
{
    $errors = [];
    $faculty = '';
    $degreePrograms = [];
    $year = null;
    $semester = null;
    $batch = '';
    $weekdayColumns = [];
    $dateMap = [];
    $records = [];
    $headerFound = false;
    $maxRows = 10000;

    foreach ($rows as $rowIndex => $row) {
        $row = array_map(static fn ($cell): string => trim((string) $cell), $row);
        if ($rowIndex === 0 && isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
        }
        $rowText = implode(' ', array_filter($row, static fn (string $cell): bool => $cell !== ''));

        if (!$headerFound) {
            $foundDays = [];
            foreach ($row as $column => $cell) {
                foreach ($daysOfWeek as $day) {
                    if (strcasecmp($cell, $day) === 0) {
                        $foundDays[$column] = $day;
                        break;
                    }
                }
            }
            if (count($foundDays) >= 5) {
                $weekdayColumns = $foundDays;
                $headerFound = true;
                continue;
            }

            if (preg_match('/Faculty\s+of\s+.+/i', $rowText, $matches)) {
                $faculty = trim($matches[0]);
            }
            if (preg_match(
                '/Year\s+0*(\d+)\s+Semester\s+0*(\d+).*?-\s*(.+?)\s+Batch\b/i',
                $rowText,
                $matches
            )) {
                $year = (int) $matches[1];
                $semester = (int) $matches[2];
                $batch = trim($matches[3]);
            }

            $program = trim((string) ($row[0] ?? ''));
            if (preg_match('/^(?:BSc|Bachelor|Diploma|Higher\s+Diploma)\b/i', $program)) {
                $program = preg_replace('/\s+/u', ' ', $program) ?? $program;
                $degreePrograms[] = $program;
            }
            continue;
        }

        $firstCell = trim((string) ($row[0] ?? ''));
        if ($firstCell !== '' && ctype_digit($firstCell)) {
            $weekDates = [];
            foreach ($weekdayColumns as $column => $day) {
                $dateText = trim((string) ($row[$column] ?? ''));
                if ($dateText === '') {
                    continue;
                }

                if (!preg_match('/^\d{1,2}-[A-Za-z]{3}-\d{2,4}$/', $dateText)) {
                    $errors[] = 'CSV row ' . ($rowIndex + 1) . ": invalid timetable date '{$dateText}'.";
                    break 2;
                }
                $dateFormat = strlen(substr($dateText, strrpos($dateText, '-') + 1)) === 2
                    ? '!j-M-y'
                    : '!j-M-Y';
                $date = DateTimeImmutable::createFromFormat($dateFormat, $dateText);
                $dateIssues = DateTimeImmutable::getLastErrors();
                if (
                    $date === false
                    || ($dateIssues !== false && ($dateIssues['warning_count'] > 0 || $dateIssues['error_count'] > 0))
                    || strcasecmp($date->format('l'), $day) !== 0
                ) {
                    $errors[] = 'CSV row ' . ($rowIndex + 1) . ": '{$dateText}' is not a valid {$day} date.";
                    break 2;
                }
                $weekDates[$column] = $date->format('Y-m-d');
            }

            if ($weekDates !== []) {
                if (count($weekDates) !== count($weekdayColumns)) {
                    $errors[] = 'CSV row ' . ($rowIndex + 1) . ': a week row must include a date for each weekday column.';
                    break;
                }
                $dateMap = $weekDates;
            }
            continue;
        }

        $timeRange = campusHubParseTimeRange($firstCell);
        if ($timeRange === null) {
            continue;
        }
        if ($dateMap === []) {
            $errors[] = 'CSV row ' . ($rowIndex + 1) . ': timetable time row appears before its week/date row.';
            break;
        }

        foreach ($weekdayColumns as $column => $day) {
            $module = preg_replace('/\s+/u', ' ', trim((string) ($row[$column] ?? '')))
                ?? trim((string) ($row[$column] ?? ''));
            if ($module === '') {
                continue;
            }
            if (mb_strlen($module) > 160) {
                $errors[] = 'CSV row ' . ($rowIndex + 1) . ': module text exceeds 160 characters.';
                break 2;
            }
            $records[] = [
                'faculty' => $faculty,
                'degree_program' => implode(' / ', $degreePrograms),
                'year' => $year,
                'semester' => $semester,
                'batch' => $batch,
                'day_of_week' => $day,
                'class_date' => $dateMap[$column],
                'start_time' => $timeRange[0],
                'end_time' => $timeRange[1],
                'module_name' => $module,
            ];
            if (count($records) > $maxRows) {
                $errors[] = 'A single import cannot contain more than 10,000 timetable entries.';
                break 2;
            }
        }
    }

    if (!$headerFound) {
        $errors[] = 'Could not find a weekday header with at least five weekday columns.';
    }
    if ($faculty === '' || $year === null || $semester === null || $batch === '' || $degreePrograms === []) {
        $errors[] = 'Could not read the faculty, degree program, year, semester, and batch from the timetable title rows.';
    }
    if ($year !== null && ($year < 1 || $year > 8)) {
        $errors[] = 'The timetable title has an unsupported year; expected a value from 1 to 8.';
    }
    if ($semester !== null && ($semester < 1 || $semester > 3)) {
        $errors[] = 'The timetable title has an unsupported semester; expected a value from 1 to 3.';
    }
    if (mb_strlen($faculty) > 120 || mb_strlen($batch) > 80 || mb_strlen(implode(' / ', $degreePrograms)) > 160) {
        $errors[] = 'Timetable title metadata exceeds the database field limits.';
    }
    if ($records === [] && $errors === []) {
        $errors[] = 'No scheduled classes or activities were found in the CSV.';
    }

    return ['records' => $records, 'errors' => $errors];
}

$expectedHeaders = [
    'faculty',
    'degree_program',
    'year',
    'semester',
    'batch',
    'day_of_week',
    'start_time',
    'end_time',
    'module_name',
];
$daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $file = $_FILES['timetable_csv'] ?? null;

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $errors[] = 'Your form session expired. Please try again.';
    } elseif (
        !is_array($file)
        || !isset($file['error'], $file['tmp_name'], $file['size'])
        || !is_int($file['error'])
        || !is_string($file['tmp_name'])
        || !is_int($file['size'])
    ) {
        $errors[] = 'Choose a CSV file to upload.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = match ((int) $file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the permitted size.',
            UPLOAD_ERR_NO_FILE => 'Choose a CSV file to upload.',
            default => 'The file could not be uploaded. Please try again.',
        };
    } elseif ($file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'The CSV file must be smaller than 5 MB.';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'The uploaded file could not be verified.';
    }

    if ($errors === []) {
        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            $errors[] = 'The uploaded CSV file could not be opened.';
        } else {
            $csvRows = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $csvRows[] = $row;
            }
            fclose($handle);

            $firstRow = $csvRows[0] ?? [];
            if (isset($firstRow[0])) {
                $firstRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $firstRow[0]) ?? (string) $firstRow[0];
            }
            $headers = array_map(
                static fn ($header): string => trim((string) $header),
                $firstRow
            );

            if ($headers === $expectedHeaders) {
                $records = [];
                foreach (array_slice($csvRows, 1) as $index => $row) {
                    $rowNumber = $index + 2;
                    if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                        continue;
                    }
                    if (count($row) !== count($expectedHeaders)) {
                        $errors[] = "CSV row {$rowNumber}: expected " . count($expectedHeaders) . ' columns.';
                        break;
                    }
                    $record = array_combine(
                        $expectedHeaders,
                        array_map(static fn ($value): string => trim((string) $value), $row)
                    );
                    if ($record === false) {
                        $errors[] = "CSV row {$rowNumber}: could not read this row.";
                        break;
                    }
                    foreach (['faculty' => 120, 'degree_program' => 160, 'batch' => 80, 'module_name' => 160] as $field => $limit) {
                        if ($record[$field] === '' || mb_strlen($record[$field]) > $limit) {
                            $errors[] = "CSV row {$rowNumber}: {$field} is required and must be no more than {$limit} characters.";
                            break 2;
                        }
                    }
                    $year = filter_var($record['year'], FILTER_VALIDATE_INT);
                    $semester = filter_var($record['semester'], FILTER_VALIDATE_INT);
                    if ($year === false || $year < 1 || $year > 8 || $semester === false || $semester < 1 || $semester > 3) {
                        $errors[] = "CSV row {$rowNumber}: year must be 1–8 and semester must be 1–3.";
                        break;
                    }
                    if (!in_array($record['day_of_week'], $daysOfWeek, true)) {
                        $errors[] = "CSV row {$rowNumber}: day_of_week must be a weekday name such as Monday.";
                        break;
                    }
                    $start = campusHubParse24HourTime($record['start_time']);
                    $end = campusHubParse24HourTime($record['end_time']);
                    if ($start === null || $end === null || $end <= $start) {
                        $errors[] = "CSV row {$rowNumber}: start/end times must be valid 24-hour times with end after start.";
                        break;
                    }
                    $records[] = [
                        'faculty' => $record['faculty'],
                        'degree_program' => $record['degree_program'],
                        'year' => $year,
                        'semester' => $semester,
                        'batch' => $record['batch'],
                        'day_of_week' => $record['day_of_week'],
                        'class_date' => null,
                        'start_time' => $start->format('H:i:s'),
                        'end_time' => $end->format('H:i:s'),
                        'module_name' => $record['module_name'],
                    ];
                    if (count($records) > 10000) {
                        $errors[] = 'A single import cannot contain more than 10,000 timetable entries.';
                        break;
                    }
                }
                if ($records === [] && $errors === []) {
                    $errors[] = 'The CSV contains no timetable entries to import.';
                }
            } else {
                $parsed = campusHubParseSourceTimetable($csvRows, $daysOfWeek);
                $records = $parsed['records'];
                $errors = array_merge($errors, $parsed['errors']);
            }

            if ($errors === []) {
                $insertStatement = $pdo->prepare(
                    'INSERT INTO timetables '
                    . '(faculty, degree_program, year, semester, batch, day_of_week, class_date, start_time, end_time, module_name) '
                    . 'VALUES (:faculty, :degree_program, :year, :semester, :batch, :day_of_week, :class_date, '
                    . ':start_time, :end_time, :module_name)'
                );

                try {
                    $pdo->beginTransaction();
                    foreach ($records as $record) {
                        $insertStatement->execute($record);
                    }
                    $pdo->commit();

                    $_SESSION['timetable_import_flash'] = count($records);
                    header('Location: manage_timetables.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Campus Hub timetable import failed: ' . $exception->getMessage());
                    http_response_code(500);
                    $errors[] = 'The timetable import failed and no rows were saved. Please check the file and try again.';
                }
            }
        }
    }
}

$importedCount = $_SESSION['timetable_import_flash'] ?? null;
unset($_SESSION['timetable_import_flash']);

$recentStatement = $pdo->query(
    'SELECT id, faculty, degree_program, year, semester, batch, day_of_week, class_date, '
    . 'start_time, end_time, module_name FROM timetables ORDER BY id DESC LIMIT 50'
);
$recentEntries = $recentStatement->fetchAll();

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
    <title>Manage Timetables | NSBM Campus Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand app-navbar navbar-dark">
    <div class="container dashboard-shell">
        <a class="navbar-brand fw-semibold" href="../dashboard.php">NSBM Campus Hub</a>
        <div class="d-flex align-items-center gap-3">
            <a class="btn btn-outline-light btn-sm" href="manage_facilities.php">Manage facilities</a>
            <a class="btn btn-outline-light btn-sm" href="../dashboard.php">Dashboard</a>
        </div>
    </div>
</nav>

<main class="container dashboard-shell py-5">
    <header class="mb-4">
        <p class="text-uppercase small fw-semibold text-primary mb-2">Administration</p>
        <h1 class="h2 mb-2">Timetable importer</h1>
        <p class="text-muted mb-0">Import NSBM timetable exports while preserving their class dates and periods.</p>
    </header>

    <?php if (is_int($importedCount)): ?>
        <div class="alert alert-success" role="status">
            Successfully imported <?= $escape((string) $importedCount) ?> timetable
            <?= $importedCount === 1 ? 'slot' : 'slots' ?>.
        </div>
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
            <h2 class="h5 card-title">Upload timetable CSV</h2>
            <p class="text-muted">
                Upload the timetable CSV exported from the NSBM timetable website. The importer
                reads the faculty, degree program, year, semester, batch, weekday/date grid,
                class periods, and module names from the sheet. It also accepts the earlier
                structured CSV format with these columns:
                <code><?= $escape(implode(', ', $expectedHeaders)) ?></code>.
            </p>
            <form method="post" action="manage_timetables.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                <div class="mb-3">
                    <label for="timetable_csv" class="form-label">CSV file (maximum 5 MB)</label>
                    <input class="form-control" id="timetable_csv" name="timetable_csv"
                           type="file" accept=".csv,text/csv" required>
                </div>
                <button class="btn btn-primary" type="submit">Import timetable</button>
            </form>
        </div>
    </section>

    <section class="card dashboard-card">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 card-title mb-0">Recently added timetable entries</h2>
                <span class="badge rounded-pill text-bg-light">Latest <?= count($recentEntries) ?></span>
            </div>
            <?php if ($recentEntries === []): ?>
                <p class="text-muted mb-0">No timetable entries have been imported yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th scope="col">Faculty / degree</th>
                            <th scope="col">Year / semester</th>
                            <th scope="col">Batch</th>
                            <th scope="col">Date / day</th>
                            <th scope="col">Time</th>
                            <th scope="col">Module</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentEntries as $entry): ?>
                            <tr>
                                <td>
                                    <strong><?= $escape((string) $entry['faculty']) ?></strong>
                                    <div class="small text-muted"><?= $escape((string) $entry['degree_program']) ?></div>
                                </td>
                                <td><?= $escape((string) $entry['year']) ?> / <?= $escape((string) $entry['semester']) ?></td>
                                <td><?= $escape((string) $entry['batch']) ?></td>
                                <td>
                                    <?php if ($entry['class_date'] !== null): ?>
                                        <?= $escape(date('d M Y', strtotime((string) $entry['class_date']))) ?>
                                        <div class="small text-muted"><?= $escape((string) $entry['day_of_week']) ?></div>
                                    <?php else: ?>
                                        <?= $escape((string) $entry['day_of_week']) ?>
                                        <div class="small text-muted">Recurring / date unspecified</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $escape(substr((string) $entry['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $entry['end_time'], 0, 5)) ?></td>
                                <td><?= $escape((string) $entry['module_name']) ?></td>
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
