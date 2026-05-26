<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$conn = db();
ensureScraperSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $collegeName = trim(preg_replace('/\s+/', ' ', str_replace(['?', '|'], ' ', (string) ($_POST['college_name'] ?? ''))) ?? '');
    $city = trim(preg_replace('/\s+/', ' ', str_replace(['?', '|'], ' ', (string) ($_POST['city'] ?? ''))) ?? '');
    $state = trim(preg_replace('/\s+/', ' ', str_replace(['?', '|'], ' ', (string) ($_POST['state'] ?? ''))) ?? '');
    $pincode = trim((string) ($_POST['pincode'] ?? ''));
    $pincode = preg_replace('/[^0-9]/', '', $pincode) ?? '';

    if ($collegeName === '' || mb_strlen($collegeName) < 2) {
        redirect('add_college.php?error=' . urlencode('Please enter a valid college name.'));
    }

    if (mb_strlen($collegeName) > 255) {
        redirect('add_college.php?error=' . urlencode('College name is too long (max 255 characters).'));
    }

    if ($city !== '' && mb_strlen($city) > 100) {
        redirect('add_college.php?error=' . urlencode('City is too long (max 100 characters).'));
    }

    if ($state !== '' && mb_strlen($state) > 100) {
        redirect('add_college.php?error=' . urlencode('State is too long (max 100 characters).'));
    }

    if ($pincode !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
        redirect('add_college.php?error=' . urlencode('Please enter a valid 6 digit Indian pincode, or leave it blank.'));
    }

    $slug = uniqueCollegeSlug($conn, $collegeName);
    $status = 'pending';
    $empty = '';

    $stmt = $conn->prepare(
        'INSERT INTO colleges (college_name, clg_name, city, state, pincode, slug, scrape_status, facebook, instagram, twitter, linkedin)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssssssss', $collegeName, $collegeName, $city, $state, $pincode, $slug, $status, $empty, $empty, $empty, $empty);
    $stmt->execute();

    $collegeId = (int) $stmt->insert_id;
    $stmt->close();

    if ($collegeId <= 0) {
        redirect('add_college.php?error=' . urlencode('Could not save the college. Please try again.'));
    }

    set_time_limit(300);
    $queued = queueCollegeScraper($conn, $collegeId, $collegeName, false);
    if (!$queued['ok']) {
        redirect('add_college.php?error=' . urlencode($queued['error']));
    }

    redirect('add_college.php?message=' . urlencode('College added and scraped successfully. Data saved to database.'));
}

$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';

$stats = [
    'total' => 0,
    'pending' => 0,
    'running' => 0,
    'completed' => 0,
    'failed' => 0,
];

$statsResult = $conn->query(
    "SELECT scrape_status, COUNT(*) AS total
     FROM colleges
     GROUP BY scrape_status"
);

while ($row = $statsResult->fetch_assoc()) {
    $status = (string) $row['scrape_status'];
    $count = (int) $row['total'];
    $stats[$status] = $count;
    $stats['total'] += $count;
}

$recent = $conn->query(
    "SELECT id, COALESCE(NULLIF(college_name,''), clg_name) AS college_name, clg_name, website, city, state, course_name, courses, fees, seo_title, logo, images, scrape_status, scrape_error, scraped_at, added_on
     FROM colleges
     ORDER BY id DESC
     LIMIT 12"
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>College Auto Data Collection</title>
    <style>
        :root {
            --ink: #172033;
            --muted: #677085;
            --line: #dfe5ef;
            --paper: #ffffff;
            --soft: #f4f7fb;
            --green: #12715b;
            --blue: #215ea8;
            --amber: #9a5b00;
            --red: #b42318;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background: #eef3f8;
        }

        .topbar {
            background: #101828;
            color: #fff;
            padding: 18px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .brand { font-size: 19px; font-weight: 700; letter-spacing: 0; }
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
        .pill {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.12);
            color: #e9eef7;
            font-size: 13px;
        }

        .shell {
            width: min(1180px, calc(100% - 32px));
            margin: 28px auto;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(280px, 420px) 1fr;
            gap: 18px;
            align-items: stretch;
        }

        .panel {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 14px 34px rgba(16,24,40,.08);
        }

        .form-panel { padding: 24px; }
        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            line-height: 1.18;
        }

        .lede {
            margin: 0 0 22px;
            color: var(--muted);
            line-height: 1.55;
        }

        label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .input-row {
            display: flex;
            gap: 10px;
        }

        .field-stack {
            display: grid;
            gap: 12px;
        }

        .location-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        input, select {
            width: 100%;
            min-height: 46px;
            padding: 11px 12px;
            border: 1px solid #b9c3d3;
            border-radius: 6px;
            color: var(--ink);
            font-size: 15px;
            background: #fff;
        }

        .helper {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
        }

        button, .button {
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 16px;
            border: 0;
            border-radius: 6px;
            background: var(--green);
            color: #fff;
            font-weight: 700;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .button.secondary {
            background: #24405f;
        }

        .msg {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            line-height: 1.45;
        }

        .ok { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
        .bad { background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(110px, 1fr));
            gap: 12px;
            padding: 18px;
        }

        .stat {
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--soft);
        }

        .stat strong {
            display: block;
            font-size: 28px;
            margin-bottom: 4px;
        }

        .stat span {
            color: var(--muted);
            font-size: 13px;
            text-transform: uppercase;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 26px 0 12px;
        }

        h2 {
            margin: 0;
            font-size: 20px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th, td {
            padding: 14px 16px;
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

        .college-name {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .meta {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.4;
        }

        .status {
            display: inline-flex;
            min-height: 26px;
            align-items: center;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .pending { background: #fff7ed; color: var(--amber); }
        .running { background: #e0f2fe; color: var(--blue); }
        .completed { background: #dcfce7; color: var(--green); }
        .failed { background: #fee2e2; color: var(--red); }

        .thumb {
            width: 56px;
            height: 56px;
            border-radius: 6px;
            object-fit: contain;
            border: 1px solid var(--line);
            background: #fff;
        }

        .thumb-fallback {
            width: 56px;
            height: 56px;
            display: grid;
            place-items: center;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: var(--soft);
            color: var(--muted);
            font-weight: 700;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .mini-link {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 6px 9px;
            border-radius: 6px;
            background: #eef4ff;
            color: var(--blue);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .empty {
            padding: 32px;
            text-align: center;
            color: var(--muted);
        }

        a { color: var(--blue); }

        @media (max-width: 880px) {
            .topbar { align-items: flex-start; flex-direction: column; }
            .hero { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
            .input-row { flex-direction: column; }
            .location-row { grid-template-columns: 1fr; }
            button, .button { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">College Auto Data Collection</div>
        <nav class="nav">
            <a href="add_college.php">College Module</a>
            <a href="leads.php">Lead Module</a>
        </nav>
        <div class="pill">Local database: top_college</div>
    </header>

    <main class="shell">
        <section class="hero">
            <div class="panel form-panel">
                <h1>Add College</h1>
                <p class="lede">Enter a college name. State and pincode are optional search hints for better accuracy. The system finds the official website and saves public college details to the database.</p>

                <?php if ($message !== ''): ?>
                    <div class="msg ok"><?= e($message) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="msg bad"><?= e($error) ?></div>
                <?php endif; ?>

                <form action="add_college.php" method="post">
                    <div class="field-stack">
                        <div>
                            <label for="college_name">College Name</label>
                            <input id="college_name" name="college_name" type="text" placeholder="Example: Delhi College of Arts and Commerce" required>
                            <p class="helper">Mandatory. You can still type "College Name, City, State" if you know the city.</p>
                        </div>
                        <div class="location-row">
                            <div>
                                <label for="city">City</label>
                                <input id="city" name="city" type="text" placeholder="Optional">
                            </div>
                            <div>
                                <label for="state">State</label>
                                <input id="state" name="state" type="text" placeholder="Optional">
                            </div>
                        </div>
                        <div class="location-row">
                            <div>
                                <label for="pincode">Pincode</label>
                                <input id="pincode" name="pincode" type="text" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" placeholder="Optional">
                            </div>
                            <div></div>
                        </div>
                        <p class="helper">Data comes from the official college website only. City, state, and pincode help Google/Bing choose the correct college when names are similar.</p>
                        <button type="submit">Start Scraping</button>
                    </div>
                </form>
            </div>

            <div class="panel stats" aria-label="Scraping status">
                <div class="stat"><strong><?= $stats['total'] ?></strong><span>Total</span></div>
                <div class="stat"><strong><?= $stats['pending'] ?></strong><span>Pending</span></div>
                <div class="stat"><strong><?= $stats['running'] ?></strong><span>Running</span></div>
                <div class="stat"><strong><?= $stats['completed'] ?></strong><span>Completed</span></div>
                <div class="stat"><strong><?= $stats['failed'] ?></strong><span>Failed</span></div>
            </div>
        </section>

        <div class="section-head">
            <h2>Recent Colleges</h2>
            <a class="button secondary" href="../scraper/run_pending_scrapers.php">Run Pending Now</a>
        </div>

        <section class="panel table-wrap">
            <?php if ($recent->num_rows === 0): ?>
                <div class="empty">No colleges added yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>College</th>
                            <th>Image</th>
                            <th>Status</th>
                            <th>Website</th>
                            <th>Courses</th>
                            <th>Fees</th>
                            <th>SEO Title</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $recent->fetch_assoc()): ?>
                            <?php $status = (string) ($row['scrape_status'] ?: 'pending'); ?>
                            <?php
                            $image = (string) ($row['logo'] ?? '');
                            if ($image === '' && (string) ($row['images'] ?? '') !== '') {
                                $decodedImages = json_decode((string) $row['images'], true);
                                if (is_array($decodedImages) && isset($decodedImages[0])) {
                                    $image = (string) $decodedImages[0];
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="college-name"><?= e($row['college_name'] ?: $row['clg_name']) ?></div>
                                    <div class="meta"><?= e(trim((string) $row['city'] . ', ' . (string) $row['state'], ' ,')) ?></div>
                                    <?php if ($status === 'failed' && (string) $row['scrape_error'] !== ''): ?>
                                        <div class="meta"><?= e($row['scrape_error']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($image !== ''): ?>
                                        <a href="<?= e($image) ?>" target="_blank" rel="noopener">
                                            <img class="thumb" src="<?= e($image) ?>" alt="<?= e($row['college_name'] ?: 'College image') ?>">
                                        </a>
                                    <?php else: ?>
                                        <div class="thumb-fallback">IMG</div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="status <?= e($status) ?>"><?= e($status) ?></span></td>
                                <td>
                                    <?php if ((string) $row['website'] !== ''): ?>
                                        <a href="<?= e($row['website']) ?>" target="_blank" rel="noopener">Open site</a>
                                    <?php else: ?>
                                        <span class="meta">Waiting</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($row['courses'] ?: ($row['course_name'] ?: 'Pending')) ?></td>
                                <td><?= e($row['fees'] ?: 'Pending') ?></td>
                                <td><?= e($row['seo_title'] ?: 'Pending') ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="mini-link" href="view_college.php?id=<?= (int) $row['id'] ?>">View</a>
                                        <a class="mini-link" href="../scraper/run_college_scraper.php?college_id=<?= (int) $row['id'] ?>">Run</a>
                                    </div>
                                    <div class="meta"><?= e($row['scraped_at'] ?: $row['added_on']) ?></div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
