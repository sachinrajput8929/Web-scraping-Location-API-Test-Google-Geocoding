<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$conn = db();
ensureScraperSchema($conn);

$collegeId = (int) ($_GET['id'] ?? 0);
if ($collegeId <= 0) {
    redirect('add_college.php?error=' . urlencode('Invalid college selected.'));
}

$stmt = $conn->prepare('SELECT * FROM colleges WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $collegeId);
$stmt->execute();
$college = $stmt->get_result()->fetch_assoc();

if (!$college) {
    redirect('add_college.php?error=' . urlencode('College not found.'));
}

function splitList(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
}

function jsonList(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

$name = (string) ($college['college_name'] ?: $college['clg_name']);
$location = trim((string) $college['city'] . ', ' . (string) $college['state'] . ' ' . (string) $college['pincode'], ' ,');
$courses = splitList((string) ($college['courses'] ?: $college['course_name']));
$fees = splitList((string) $college['fees']);
$facilities = splitList((string) $college['facilities']);
$images = array_values(array_filter(jsonList((string) $college['images']), 'is_string'));
$sourcePages = array_values(array_filter(jsonList((string) $college['source_pages']), 'is_string'));
$importantLinks = jsonList((string) $college['important_links']);

if ((string) $college['logo'] !== '' && !in_array((string) $college['logo'], $images, true)) {
    array_unshift($images, (string) $college['logo']);
}

$socialLinks = [
    'Facebook' => (string) ($college['facebook'] ?? ''),
    'Instagram' => (string) ($college['instagram'] ?? ''),
    'Twitter/X' => (string) ($college['twitter'] ?? ''),
    'LinkedIn' => (string) ($college['linkedin'] ?? ''),
];

$description = (string) ($college['short_description'] ?: ($college['seo_description'] ?: 'Scraped college profile details are shown below.'));
$longDescription = (string) ($college['description'] ?: ($college['long_description'] ?: 'No full description collected yet.'));
$mapQuery = trim($name . ' ' . (string) $college['address'] . ' ' . $location);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($name) ?> - College Scraping Profile</title>
    <style>
        :root {
            --ink: #172033;
            --muted: #667085;
            --paper: #ffffff;
            --line: #d9e2ef;
            --sky: #e8f3ff;
            --mint: #e8fff4;
            --rose: #fff0f3;
            --amber: #fff6df;
            --blue: #1e5ca8;
            --green: #08745f;
            --red: #b42318;
            --violet: #6441a5;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background:
                linear-gradient(135deg, rgba(232,243,255,.9), rgba(232,255,244,.85)),
                #eef3f8;
        }
        a { color: var(--blue); }
        .topbar {
            min-height: 64px;
            padding: 14px 28px;
            background: #101828;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .topbar a { color: #fff; font-weight: 700; text-decoration: none; }
        .shell { width: min(1220px, calc(100% - 32px)); margin: 24px auto 40px; }
        .hero {
            display: grid;
            grid-template-columns: 150px 1fr auto;
            gap: 22px;
            align-items: center;
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fbff 55%, #eefaf5 100%);
            box-shadow: 0 18px 44px rgba(16,24,40,.12);
        }
        .logo-box {
            width: 150px;
            aspect-ratio: 1;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            display: grid;
            place-items: center;
            overflow: hidden;
        }
        .logo-box img { width: 100%; height: 100%; object-fit: contain; padding: 12px; }
        .logo-fallback { font-size: 34px; font-weight: 800; color: var(--blue); }
        h1 { margin: 0 0 8px; font-size: clamp(26px, 4vw, 42px); line-height: 1.08; letter-spacing: 0; }
        h2 { margin: 0 0 14px; font-size: 19px; }
        h3 { margin: 0 0 10px; font-size: 15px; color: var(--muted); text-transform: uppercase; }
        .subtitle { margin: 0; max-width: 760px; color: #475467; line-height: 1.55; }
        .hero-actions { display: flex; flex-direction: column; gap: 10px; min-width: 180px; }
        .button {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 7px;
            color: #fff;
            background: var(--blue);
            font-weight: 700;
            text-decoration: none;
            text-align: center;
        }
        .button.green { background: var(--green); }
        .button.violet { background: var(--violet); }
        .status {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            background: var(--sky);
            color: var(--blue);
        }
        .completed { background: #dcfce7; color: var(--green); }
        .failed { background: #fee2e2; color: var(--red); }
        .pending { background: var(--amber); color: #9a5b00; }
        .facts {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 16px;
        }
        .fact {
            min-height: 110px;
            padding: 16px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--paper);
            box-shadow: 0 10px 28px rgba(16,24,40,.06);
        }
        .fact:nth-child(1) { background: var(--sky); }
        .fact:nth-child(2) { background: var(--mint); }
        .fact:nth-child(3) { background: var(--amber); }
        .fact:nth-child(4) { background: var(--rose); }
        .label { display: block; color: var(--muted); font-size: 12px; font-weight: 800; margin-bottom: 7px; text-transform: uppercase; }
        .fact strong { display: block; font-size: 18px; line-height: 1.35; overflow-wrap: anywhere; }
        .grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 16px; margin-top: 16px; }
        .panel {
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--paper);
            box-shadow: 0 10px 28px rgba(16,24,40,.06);
        }
        .value { line-height: 1.58; white-space: pre-wrap; overflow-wrap: anywhere; }
        .field { padding: 12px 0; border-bottom: 1px solid #edf1f7; }
        .field:last-child { border-bottom: 0; }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #f2f6ff;
            border: 1px solid #d9e5ff;
            color: #24405f;
            font-size: 13px;
            font-weight: 700;
        }
        .fee-chip { background: #fff8e8; border-color: #ffe1a3; color: #875200; }
        .facility-chip { background: #eafff6; border-color: #b8f1dc; color: #08745f; }
        .link-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px; }
        .link-card {
            display: block;
            min-height: 48px;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: #f8fbff;
            color: var(--blue);
            font-weight: 800;
            text-decoration: none;
            overflow-wrap: anywhere;
        }
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }
        .gallery img {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: contain;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
        }
        .empty { color: var(--muted); line-height: 1.55; }
        .error-box {
            margin-top: 16px;
            padding: 14px;
            border-radius: 8px;
            background: #fee2e2;
            color: #7f1d1d;
            border: 1px solid #fca5a5;
        }
        @media (max-width: 920px) {
            .hero { grid-template-columns: 1fr; }
            .logo-box { width: 116px; }
            .hero-actions { flex-direction: row; flex-wrap: wrap; }
            .facts, .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <strong>College Auto Data Collection</strong>
        <a href="add_college.php">Back to Dashboard</a>
    </header>

    <main class="shell">
        <section class="hero">
            <div class="logo-box">
                <?php if ((string) $college['logo'] !== ''): ?>
                    <a href="<?= e($college['logo']) ?>" target="_blank" rel="noopener">
                        <img src="<?= e($college['logo']) ?>" alt="<?= e($name) ?> logo">
                    </a>
                <?php else: ?>
                    <div class="logo-fallback"><?= e(strtoupper(substr($name, 0, 1))) ?></div>
                <?php endif; ?>
            </div>

            <div>
                <span class="status <?= e((string) $college['scrape_status']) ?>"><?= e((string) $college['scrape_status']) ?></span>
                <h1><?= e($name) ?></h1>
                <p class="subtitle"><?= e($description) ?></p>
                <?php if ($location !== ''): ?>
                    <p class="subtitle"><strong>Location:</strong> <?= e($location) ?></p>
                <?php endif; ?>
            </div>

            <div class="hero-actions">
                <?php if ((string) $college['website'] !== ''): ?>
                    <a class="button green" href="<?= e($college['website']) ?>" target="_blank" rel="noopener">Open Website</a>
                <?php endif; ?>
                <?php if ($mapQuery !== ''): ?>
                    <a class="button violet" href="https://www.google.com/maps/search/?api=1&query=<?= e(urlencode($mapQuery)) ?>" target="_blank" rel="noopener">Open Map</a>
                <?php endif; ?>
                <a class="button" href="../scraper/run_college_scraper.php?college_id=<?= (int) $college['id'] ?>">Run Scraper</a>
            </div>
        </section>

        <?php if ((string) $college['scrape_error'] !== ''): ?>
            <div class="error-box"><?= e($college['scrape_error']) ?></div>
        <?php endif; ?>

        <section class="facts">
            <div class="fact"><span class="label">Email</span><strong><?= e($college['email'] ?: 'Pending') ?></strong></div>
            <div class="fact"><span class="label">Phone</span><strong><?= e($college['phone'] ?: 'Pending') ?></strong></div>
            <div class="fact"><span class="label">Fees</span><strong><?= e($fees[0] ?? 'Pending') ?></strong></div>
            <div class="fact"><span class="label">Courses Found</span><strong><?= e((string) count($courses)) ?></strong></div>
        </section>

        <section class="grid">
            <div class="panel">
                <h2>College Information</h2>
                <div class="field"><span class="label">Website</span><div class="value"><?= (string) $college['website'] !== '' ? '<a href="' . e($college['website']) . '" target="_blank" rel="noopener">' . e($college['website']) . '</a>' : 'Pending' ?></div></div>
                <div class="field"><span class="label">Address</span><div class="value"><?= e($college['address'] ?: 'Pending') ?></div></div>
                <div class="field"><span class="label">Coordinates</span><div class="value"><?= e(trim((string) $college['latitude'] . ', ' . (string) $college['longitude'], ' ,') ?: 'Pending') ?></div></div>
                <div class="field"><span class="label">Last Scraped</span><div class="value"><?= e($college['scraped_at'] ?: 'Not scraped yet') ?></div></div>
            </div>

            <div class="panel">
                <h2>Fees Per Year</h2>
                <?php if ($fees === []): ?>
                    <div class="empty">No fee details found yet.</div>
                <?php else: ?>
                    <div class="chips">
                        <?php foreach ($fees as $fee): ?>
                            <span class="chip fee-chip"><?= e($fee) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="grid">
            <div class="panel">
                <h2>Courses</h2>
                <?php if ($courses === []): ?>
                    <div class="empty">No courses found yet.</div>
                <?php else: ?>
                    <div class="chips">
                        <?php foreach ($courses as $course): ?>
                            <span class="chip"><?= e($course) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2>Facilities</h2>
                <?php if ($facilities === []): ?>
                    <div class="empty">No facilities found yet.</div>
                <?php else: ?>
                    <div class="chips">
                        <?php foreach ($facilities as $facility): ?>
                            <span class="chip facility-chip"><?= e($facility) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="grid">
            <div class="panel">
                <h2>Social Media</h2>
                <?php if (count(array_filter($socialLinks)) === 0): ?>
                    <div class="empty">No social media links collected yet.</div>
                <?php else: ?>
                    <div class="link-grid">
                        <?php foreach ($socialLinks as $label => $url): ?>
                            <?php if ($url !== ''): ?>
                                <a class="link-card" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($label) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2>Important Website Links</h2>
                <?php if ($importantLinks === []): ?>
                    <div class="empty">No important website links collected yet.</div>
                <?php else: ?>
                    <div class="link-grid">
                        <?php foreach ($importantLinks as $category => $link): ?>
                            <?php if (is_array($link) && !empty($link['url'])): ?>
                                <a class="link-card" href="<?= e((string) $link['url']) ?>" target="_blank" rel="noopener"><?= e(ucfirst((string) $category)) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel" style="margin-top:16px;">
            <h2>Images And Logo</h2>
            <?php if ($images === []): ?>
                <div class="empty">No image URLs collected yet.</div>
            <?php else: ?>
                <div class="gallery">
                    <?php foreach ($images as $image): ?>
                        <a href="<?= e($image) ?>" target="_blank" rel="noopener">
                            <img src="<?= e($image) ?>" alt="<?= e($name) ?> image">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="grid">
            <div class="panel">
                <h2>SEO Content</h2>
                <div class="field"><span class="label">SEO Title</span><div class="value"><?= e($college['seo_title'] ?: 'Pending') ?></div></div>
                <div class="field"><span class="label">Meta Description</span><div class="value"><?= e($college['seo_description'] ?: 'Pending') ?></div></div>
                <div class="field"><span class="label">Keywords</span><div class="value"><?= e($college['seo_keywords'] ?: 'Pending') ?></div></div>
                <div class="field"><span class="label">SEO Body</span><div class="value"><?= e($college['seo_content'] ?: 'Pending') ?></div></div>
            </div>

            <div class="panel">
                <h2>Full Scraped Description</h2>
                <div class="value"><?= e($longDescription) ?></div>
            </div>
        </section>

        <section class="panel" style="margin-top:16px;">
            <h2>Source Pages Crawled</h2>
            <?php if ($sourcePages === []): ?>
                <div class="empty">No source pages recorded yet.</div>
            <?php else: ?>
                <div class="link-grid">
                    <?php foreach ($sourcePages as $page): ?>
                        <a class="link-card" href="<?= e($page) ?>" target="_blank" rel="noopener"><?= e($page) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
