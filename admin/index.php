<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$conn = db();
ensureLeadSchema($conn);

$collegeStats = [
    'total' => 0,
    'completed' => 0,
    'pending' => 0,
    'failed' => 0,
];

$result = $conn->query("SELECT scrape_status, COUNT(*) AS total FROM colleges GROUP BY scrape_status");
while ($row = $result->fetch_assoc()) {
    $status = (string) $row['scrape_status'];
    $count = (int) $row['total'];
    $collegeStats[$status] = $count;
    $collegeStats['total'] += $count;
}

$leadStats = [
    'students' => (int) ($conn->query('SELECT COUNT(*) AS total FROM students')->fetch_assoc()['total'] ?? 0),
    'today' => (int) ($conn->query("SELECT COUNT(*) AS total FROM lead_distribution WHERE distributed_date = CURDATE()")->fetch_assoc()['total'] ?? 0),
    'all' => (int) ($conn->query('SELECT COUNT(*) AS total FROM lead_distribution')->fetch_assoc()['total'] ?? 0),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TopColleges Automation Admin</title>
    <style>
        :root {
            --ink: #172033;
            --muted: #667085;
            --paper: #ffffff;
            --line: #dbe4ef;
            --green: #08745f;
            --blue: #215ea8;
            --plum: #6f3fa0;
            --amber: #9a5b00;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background: linear-gradient(135deg, #eef7f4, #f1f5ff);
        }

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
        .shell { width: min(1180px, calc(100% - 32px)); margin: 30px auto; }
        .hero { margin-bottom: 18px; }
        h1 { margin: 0 0 8px; font-size: 34px; line-height: 1.12; }
        .lede { margin: 0; max-width: 780px; color: var(--muted); line-height: 1.55; }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(280px, 1fr));
            gap: 16px;
        }

        .module {
            min-height: 360px;
            padding: 22px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--paper);
            box-shadow: 0 16px 38px rgba(16,24,40,.08);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .module h2 { margin: 0 0 10px; font-size: 24px; }
        .module p { margin: 0; color: var(--muted); line-height: 1.55; }
        .rules {
            margin: 18px 0;
            display: grid;
            gap: 9px;
        }
        .rule {
            padding: 10px 12px;
            border-radius: 6px;
            background: #f8fafc;
            border: 1px solid var(--line);
            font-weight: 700;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(90px, 1fr));
            gap: 10px;
            margin: 18px 0;
        }
        .stat {
            padding: 13px;
            border-radius: 8px;
            background: #f6f8fb;
            border: 1px solid var(--line);
        }
        .stat strong { display: block; font-size: 25px; margin-bottom: 4px; }
        .stat span { color: #475467; font-size: 12px; font-weight: 800; text-transform: uppercase; }

        .actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .button {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 6px;
            color: #fff;
            background: var(--green);
            font-weight: 800;
            text-decoration: none;
        }
        .blue { background: var(--blue); }
        .plum { background: var(--plum); }
        .amber { background: var(--amber); }

        @media (max-width: 820px) {
            .grid { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
            .button { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">TopColleges.co.in Automation</div>
        <div>Core PHP + Python + MySQL</div>
    </header>

    <main class="shell">
        <section class="hero">
            <h1>Complete Automation System</h1>
            <p class="lede">Two connected admin modules for automatic college data collection and student lead distribution.</p>
        </section>

        <section class="grid">
            <article class="module">
                <div>
                    <h2>System 1: College Auto Data Collection</h2>
                    <p>Admin adds a college name, then the scraper discovers the official website, extracts details, saves the database record, and generates SEO fields.</p>
                    <div class="rules">
                        <div class="rule">Search internet automatically</div>
                        <div class="rule">Fetch college details</div>
                        <div class="rule">Save data into database</div>
                        <div class="rule">Generate SEO content</div>
                    </div>
                    <div class="stats">
                        <div class="stat"><strong><?= $collegeStats['total'] ?></strong><span>Total</span></div>
                        <div class="stat"><strong><?= $collegeStats['completed'] ?></strong><span>Complete</span></div>
                        <div class="stat"><strong><?= $collegeStats['pending'] + $collegeStats['failed'] ?></strong><span>Needs Work</span></div>
                    </div>
                </div>
                <div class="actions">
                    <a class="button" href="add_college.php">Open College Module</a>
                    <a class="button blue" href="../scraper/run_pending_scrapers.php">Run Pending</a>
                </div>
            </article>

            <article class="module">
                <div>
                    <h2>System 2: Student Lead Distribution</h2>
                    <p>Student CSV upload now finds missing latitude/longitude from city and state using free local coordinates or OpenStreetMap fallback, then triggers duplicate cleanup and automatic distribution using course match, 50 KM radius, random assignment, and a daily college cap.</p>
                    <div class="rules">
                        <div class="rule">Match nearby colleges within 50 KM radius</div>
                        <div class="rule">Match course interest</div>
                        <div class="rule">Distribute leads randomly</div>
                        <div class="rule">Send maximum 5 leads daily to each college</div>
                    </div>
                    <div class="stats">
                        <div class="stat"><strong><?= $leadStats['students'] ?></strong><span>Students</span></div>
                        <div class="stat"><strong><?= $leadStats['today'] ?></strong><span>Today</span></div>
                        <div class="stat"><strong><?= $leadStats['all'] ?></strong><span>All Leads</span></div>
                    </div>
                </div>
                <div class="actions">
                    <a class="button plum" href="leads.php">Open Lead Module</a>
                    <a class="button amber" href="distribute_leads.php">Run Distribution</a>
                    <a class="button blue" href="api_status.php">Check Location API</a>
                </div>
            </article>
        </section>
    </main>
</body>
</html>
