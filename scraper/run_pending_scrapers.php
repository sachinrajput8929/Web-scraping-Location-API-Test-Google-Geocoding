<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$conn = db();
ensureScraperSchema($conn);

$result = $conn->query(
    "SELECT id,
            COALESCE(NULLIF(college_name,''), NULLIF(clg_name,'')) AS college_name
       FROM colleges
      WHERE scrape_status IN ('pending','failed')
      ORDER BY id ASC
      LIMIT 10"
);

$processed = 0;
$failed = 0;

while ($row = $result->fetch_assoc()) {
    $collegeId = (int) $row['id'];
    $collegeName = trim((string) $row['college_name']);

    if ($collegeName === '') {
        markCollegeScrapeFailed($conn, $collegeId, 'College name is missing for scraping.');
        $failed++;
        continue;
    }

    $queued = queueCollegeScraper($conn, $collegeId, $collegeName, false);

    if ($queued['ok']) {
        $processed++;
    } else {
        $failed++;
    }
}

echo 'Pending scraper run completed. Success: ' . $processed . ', Failed: ' . $failed;
