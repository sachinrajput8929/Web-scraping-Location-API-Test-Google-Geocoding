<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$conn = db();
ensureLeadSchema($conn);

$message = (string) ($_GET['message'] ?? '');
$error = (string) ($_GET['error'] ?? '');

function redirectLeads(string $type, string $message): never
{
    redirect('leads.php?' . $type . '=' . urlencode($message));
}

function decimalOrNull(?string $value): ?float
{
    $value = trim((string) $value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function importStudentCsv(mysqli $conn, string $tmpFile): array
{
    $handle = fopen($tmpFile, 'r');
    if ($handle === false) {
        return ['inserted' => 0, 'skipped' => 0];
    }

    $inserted = 0;
    $skipped = 0;
    $geocoded = 0;
    $locationMissing = 0;
    $rowNumber = 0;
    $stmt = $conn->prepare(
        'INSERT INTO students (name, phone, email, course_interest, city, state, latitude, longitude)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    while (($row = fgetcsv($handle, 2000, ',')) !== false) {
        $rowNumber++;
        if ($row === [null] || count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $firstCell = mb_strtolower(trim((string) ($row[0] ?? '')));
        if ($rowNumber === 1 && in_array($firstCell, ['name', 'student name', 'student_name'], true)) {
            continue;
        }

        $name = normalizeLeadText((string) ($row[0] ?? ''));
        $phone = normalizePhone((string) ($row[1] ?? ''));
        $email = normalizeLeadText(mb_strtolower((string) ($row[2] ?? '')));
        $course = normalizeLeadText((string) ($row[3] ?? ''));
        $city = normalizeLeadText((string) ($row[4] ?? ''), 100);
        $state = normalizeLeadText((string) ($row[5] ?? ''), 100);
        $latitude = decimalOrNull((string) ($row[6] ?? ''));
        $longitude = decimalOrNull((string) ($row[7] ?? ''));

        if ($name === '' || $course === '' || ($phone === '' && $email === '')) {
            $skipped++;
            continue;
        }

        if (($latitude === null || $longitude === null) && ($city !== '' || $state !== '')) {
            $location = geocodeStudentLocation($city, $state);
            if ($location['lat'] !== null && $location['lng'] !== null) {
                $latitude = (float) $location['lat'];
                $longitude = (float) $location['lng'];
                $geocoded++;
            } else {
                $locationMissing++;
            }
        }

        $stmt->bind_param('ssssssdd', $name, $phone, $email, $course, $city, $state, $latitude, $longitude);
        $stmt->execute();
        $inserted++;
    }

    $stmt->close();
    fclose($handle);

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'geocoded' => $geocoded,
        'location_missing' => $locationMissing,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload') {
        if (!isset($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            redirectLeads('error', 'Please choose a CSV file to upload.');
        }

        $extension = strtolower(pathinfo((string) $_FILES['csv']['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            redirectLeads('error', 'Only CSV files are supported.');
        }

        $result = importStudentCsv($conn, (string) $_FILES['csv']['tmp_name']);
        $removed = removeDuplicateStudents($conn);
        $stats = distributeStudentLeads($conn);
        redirectLeads(
            'message',
            'CSV imported: ' . $result['inserted'] . ' students added, '
            . $result['skipped'] . ' rows skipped, '
            . $result['geocoded'] . ' locations geocoded from city/state, '
            . $removed . ' duplicates removed, '
            . $stats['sent'] . ' leads distributed automatically.'
        );
    }

    if ($action === 'dedupe') {
        $removed = removeDuplicateStudents($conn);
        redirectLeads('message', $removed . ' duplicate student leads removed.');
    }

    if ($action === 'distribute') {
        $stats = distributeStudentLeads($conn);
        redirectLeads(
            'message',
            'Distribution complete: ' . $stats['sent'] . ' leads sent from ' . $stats['checked'] . ' eligible students.'
        );
    }
}

$totals = [
    'students' => 0,
    'colleges' => 0,
    'today' => 0,
    'all_leads' => 0,
    'ready_students' => 0,
];

$totals['students'] = (int) ($conn->query('SELECT COUNT(*) AS total FROM students')->fetch_assoc()['total'] ?? 0);
$totals['colleges'] = (int) ($conn->query('SELECT COUNT(*) AS total FROM colleges WHERE latitude IS NOT NULL AND longitude IS NOT NULL')->fetch_assoc()['total'] ?? 0);
$totals['today'] = (int) ($conn->query("SELECT COUNT(*) AS total FROM lead_distribution WHERE distributed_date = CURDATE()")->fetch_assoc()['total'] ?? 0);
$totals['all_leads'] = (int) ($conn->query('SELECT COUNT(*) AS total FROM lead_distribution')->fetch_assoc()['total'] ?? 0);
$totals['ready_students'] = (int) ($conn->query("SELECT COUNT(*) AS total FROM students WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND course_interest IS NOT NULL AND course_interest <> ''")->fetch_assoc()['total'] ?? 0);

$recentStudents = $conn->query(
    "SELECT id, name, phone, email, course_interest, city, state, latitude, longitude, uploaded_at
     FROM students
     ORDER BY uploaded_at DESC, id DESC
     LIMIT 12"
);

$recentLeads = $conn->query(
    "SELECT ld.id, ld.distributed_date, ld.status, ld.distance_km,
            s.name AS student_name, s.phone, s.email, s.course_interest,
            COALESCE(NULLIF(c.college_name,''), c.clg_name) AS college_name,
            c.email AS college_email, c.city AS college_city, c.state AS college_state
     FROM lead_distribution ld
     INNER JOIN students s ON s.id = ld.student_id
     INNER JOIN colleges c ON c.id = ld.college_id
     ORDER BY ld.id DESC
     LIMIT 20"
);

$collegeWise = $conn->query(
    "SELECT COALESCE(NULLIF(c.college_name,''), c.clg_name) AS college_name,
            c.city, c.state,
            COUNT(ld.id) AS total,
            SUM(CASE WHEN ld.distributed_date = CURDATE() THEN 1 ELSE 0 END) AS today_total
     FROM lead_distribution ld
     INNER JOIN colleges c ON c.id = ld.college_id
     GROUP BY c.id, college_name, c.city, c.state
     ORDER BY today_total DESC, total DESC
     LIMIT 10"
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Lead Distribution</title>
    <style>
        :root {
            --ink: #172033;
            --muted: #667085;
            --paper: #ffffff;
            --line: #dbe4ef;
            --soft: #f5f8fc;
            --teal: #08745f;
            --blue: #215ea8;
            --plum: #6f3fa0;
            --amber: #9a5b00;
            --red: #b42318;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background:
                linear-gradient(135deg, rgba(232,247,244,.95), rgba(239,244,255,.94)),
                #edf3f8;
        }

        a { color: var(--blue); }
        .topbar {
            min-height: 66px;
            padding: 16px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: #101828;
            color: #fff;
        }

        .brand { font-size: 19px; font-weight: 800; }
        .nav { display: flex; flex-wrap: wrap; gap: 10px; }
        .nav a {
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 6px;
            background: rgba(255,255,255,.12);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
        }

        .shell { width: min(1240px, calc(100% - 32px)); margin: 26px auto 42px; }
        .hero {
            display: grid;
            grid-template-columns: minmax(280px, 430px) 1fr;
            gap: 16px;
            align-items: stretch;
        }

        .panel {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 16px 38px rgba(16,24,40,.08);
        }

        .upload-panel { padding: 22px; }
        h1 { margin: 0 0 9px; font-size: 30px; line-height: 1.14; }
        h2 { margin: 0; font-size: 20px; }
        .lede { margin: 0 0 20px; color: var(--muted); line-height: 1.55; }

        .msg {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 14px;
            line-height: 1.45;
        }
        .ok { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
        .bad { background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }

        label { display: block; margin-bottom: 8px; font-weight: 800; }
        input[type="file"] {
            width: 100%;
            min-height: 48px;
            padding: 12px;
            border: 1px solid #b9c3d3;
            border-radius: 6px;
            background: #fff;
        }

        .hint { margin: 8px 0 0; color: var(--muted); font-size: 12px; line-height: 1.45; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        button, .button {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border: 0;
            border-radius: 6px;
            color: #fff;
            background: var(--teal);
            font-weight: 800;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }
        .button-blue, button.button-blue { background: var(--blue); }
        .button-plum, button.button-plum { background: var(--plum); }
        .button-amber, button.button-amber { background: var(--amber); }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(105px, 1fr));
            gap: 12px;
            padding: 16px;
        }
        .stat {
            min-height: 112px;
            padding: 15px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--soft);
        }
        .stat:nth-child(1) { background: #ecfdf3; }
        .stat:nth-child(2) { background: #eef4ff; }
        .stat:nth-child(3) { background: #fff7ed; }
        .stat:nth-child(4) { background: #fdf2fa; }
        .stat:nth-child(5) { background: #f8fafc; }
        .stat strong { display: block; margin-bottom: 6px; font-size: 30px; }
        .stat span { color: #475467; font-size: 12px; font-weight: 800; text-transform: uppercase; }

        .toolbar {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
            margin: 16px 0;
        }
        .tool {
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 148px;
        }
        .tool strong { display: block; margin-bottom: 7px; font-size: 16px; }
        .tool p { margin: 0; color: var(--muted); line-height: 1.45; font-size: 13px; }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin: 24px 0 12px;
        }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 920px; border-collapse: collapse; }
        th, td {
            padding: 13px 15px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f8fafc;
            color: #475467;
            font-size: 12px;
            text-transform: uppercase;
        }

        .name { font-weight: 800; margin-bottom: 4px; }
        .meta { color: var(--muted); font-size: 13px; line-height: 1.45; }
        .badge {
            display: inline-flex;
            min-height: 28px;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            background: #eef4ff;
            color: var(--blue);
            font-size: 12px;
            font-weight: 800;
        }
        .empty { padding: 30px; text-align: center; color: var(--muted); }

        @media (max-width: 940px) {
            .topbar { align-items: flex-start; flex-direction: column; }
            .hero, .toolbar { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(2, minmax(130px, 1fr)); }
            button, .button { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">Student Lead Distribution</div>
        <nav class="nav">
            <a href="add_college.php">College Module</a>
            <a href="leads.php">Lead Dashboard</a>
            <a href="api_status.php">Location Check</a>
        </nav>
    </header>

    <main class="shell">
        <section class="hero">
            <div class="panel upload-panel">
                <h1>Lead Intake</h1>
                <p class="lede">Upload student enquiries, clean duplicates, find latitude and longitude from city/state when missing using free local or OpenStreetMap lookup, then distribute each lead to one matching college within 50 KM while keeping every college under 5 leads per day.</p>

                <?php if ($message !== ''): ?>
                    <div class="msg ok"><?= e($message) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="msg bad"><?= e($error) ?></div>
                <?php endif; ?>

                <form action="leads.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <label for="csv">Student CSV</label>
                    <input id="csv" name="csv" type="file" accept=".csv,text/csv" required>
                    <p class="hint">Columns: name, phone, email, course_interest, city, state, latitude, longitude. Latitude and longitude are optional; city/state will be geocoded automatically without a paid key when possible. A header row is allowed.</p>
                    <div class="actions">
                        <button type="submit">Upload CSV</button>
                        <a class="button button-blue" href="sample_students.csv">Sample CSV</a>
                    </div>
                </form>
            </div>

            <div class="panel stats">
                <div class="stat"><strong><?= $totals['students'] ?></strong><span>Total Students</span></div>
                <div class="stat"><strong><?= $totals['ready_students'] ?></strong><span>Ready To Match</span></div>
                <div class="stat"><strong><?= $totals['colleges'] ?></strong><span>Mapped Colleges</span></div>
                <div class="stat"><strong><?= $totals['today'] ?></strong><span>Leads Today</span></div>
                <div class="stat"><strong><?= $totals['all_leads'] ?></strong><span>All Sent</span></div>
            </div>
        </section>

        <section class="toolbar">
            <form class="panel tool" action="leads.php" method="post">
                <input type="hidden" name="action" value="dedupe">
                <div>
                    <strong>Remove Duplicate Leads</strong>
                    <p>Deletes later rows with the same phone or email, keeping the earliest student record.</p>
                </div>
                <div class="actions"><button class="button-amber" type="submit">Clean Duplicates</button></div>
            </form>

            <form class="panel tool" action="leads.php" method="post">
                <input type="hidden" name="action" value="distribute">
                <div>
                    <strong>Run Distribution</strong>
                    <p>Matches course interest and 50 KM radius, shuffles colleges, then respects the 5 leads/day limit.</p>
                </div>
                <div class="actions"><button class="button-plum" type="submit">Distribute Now</button></div>
            </form>

            <div class="panel tool">
                <div>
                    <strong>Cron Command</strong>
                    <p>Run this daily from server cron for automation: php admin/distribute_leads.php</p>
                </div>
                <div class="actions"><a class="button button-blue" href="distribute_leads.php">Run Script</a></div>
            </div>
        </section>

        <div class="section-head">
            <h2>Recent Distributed Leads</h2>
            <span class="badge">Latest 20</span>
        </div>
        <section class="panel table-wrap">
            <?php if ($recentLeads->num_rows === 0): ?>
                <div class="empty">No leads distributed yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>College</th>
                            <th>Distance</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($lead = $recentLeads->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="name"><?= e($lead['student_name']) ?></div>
                                    <div class="meta"><?= e(trim((string) $lead['phone'] . ' ' . (string) $lead['email'])) ?></div>
                                </td>
                                <td><span class="badge"><?= e($lead['course_interest']) ?></span></td>
                                <td>
                                    <div class="name"><?= e($lead['college_name']) ?></div>
                                    <div class="meta"><?= e(trim((string) $lead['college_city'] . ', ' . (string) $lead['college_state'], ' ,')) ?></div>
                                    <?php if ((string) $lead['college_email'] !== ''): ?>
                                        <div class="meta"><?= e($lead['college_email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($lead['distance_km'] !== null ? (string) $lead['distance_km'] . ' KM' : 'Not saved') ?></td>
                                <td><?= e($lead['distributed_date']) ?></td>
                                <td><span class="badge"><?= e($lead['status']) ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <div class="section-head">
            <h2>Recent Student Uploads</h2>
            <span class="badge">Latest 12</span>
        </div>
        <section class="panel table-wrap">
            <?php if ($recentStudents->num_rows === 0): ?>
                <div class="empty">Upload a student CSV to begin.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course Interest</th>
                            <th>Location</th>
                            <th>Coordinates</th>
                            <th>Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($student = $recentStudents->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="name"><?= e($student['name']) ?></div>
                                    <div class="meta"><?= e(trim((string) $student['phone'] . ' ' . (string) $student['email'])) ?></div>
                                </td>
                                <td><span class="badge"><?= e($student['course_interest'] ?: 'Missing') ?></span></td>
                                <td><?= e(trim((string) $student['city'] . ', ' . (string) $student['state'], ' ,') ?: 'Missing') ?></td>
                                <td><?= e(trim((string) $student['latitude'] . ', ' . (string) $student['longitude'], ' ,') ?: 'Missing') ?></td>
                                <td><?= e($student['uploaded_at']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <div class="section-head">
            <h2>College Wise Lead Report</h2>
            <span class="badge">Top 10</span>
        </div>
        <section class="panel table-wrap">
            <?php if ($collegeWise->num_rows === 0): ?>
                <div class="empty">College lead reports appear after distribution.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>College</th>
                            <th>Location</th>
                            <th>Today</th>
                            <th>Total Leads</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $collegeWise->fetch_assoc()): ?>
                            <tr>
                                <td><div class="name"><?= e($row['college_name']) ?></div></td>
                                <td><?= e(trim((string) $row['city'] . ', ' . (string) $row['state'], ' ,') ?: 'Missing') ?></td>
                                <td><span class="badge"><?= (int) $row['today_total'] ?></span></td>
                                <td><span class="badge"><?= (int) $row['total'] ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
