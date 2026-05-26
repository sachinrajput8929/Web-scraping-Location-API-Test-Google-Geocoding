<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$collegeId = (int) ($argv[1] ?? $_GET['college_id'] ?? 0);

if ($collegeId <= 0) {
    http_response_code(400);
    echo 'Invalid college id';
    exit(1);
}

$conn = db();
ensureScraperSchema($conn);

$collegeName = getCollegeNameById($conn, $collegeId);

if ($collegeName === '') {
    http_response_code(400);
    echo 'College name not found';
    exit(1);
}

$queued = queueCollegeScraper($conn, $collegeId, $collegeName, false);

if (!$queued['ok']) {
    http_response_code(500);
    echo $queued['error'];
    exit(1);
}

echo 'Scraping completed for: ' . $collegeName;
