<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$checks = [];
$config = require __DIR__ . '/../config/config.php';

try {
    $conn = db();
    ensureScraperSchema($conn);
    ensureLeadSchema($conn);
    $checks[] = 'OK: MySQL connected and colleges table ready';
    $checks[] = 'OK: students and lead_distribution tables ready';
} catch (Throwable $e) {
    $checks[] = 'FAIL: Database - ' . $e->getMessage();
}

$python = resolvePythonBinary();
if ($python !== '') {
    $checks[] = 'OK: Python found at ' . $python;
} else {
    $checks[] = 'FAIL: Python not found (install Python 3 or set config.php python_path)';
}

$script = realpath(__DIR__ . '/../scraper/scraper.py');
$checks[] = is_file($script) ? 'OK: scraper.py exists' : 'FAIL: scraper.py missing';

$localPackages = realpath(__DIR__ . '/../scraper/python_packages/pymysql');
$vendor = realpath(__DIR__ . '/../vendor/python/pymysql');
$checks[] = is_dir($localPackages) || is_dir($vendor)
    ? 'OK: Python packages found'
    : 'FAIL: Python packages missing (run pip install -t scraper/python_packages pymysql requests beautifulsoup4 lxml googlesearch-python)';

if ($python !== '' && is_file($script)) {
    $localPackageDir = realpath(__DIR__ . '/../scraper/python_packages') ?: '';
    $vendorPackageDir = realpath(__DIR__ . '/../vendor/python') ?: '';
    $packageDir = $localPackageDir !== '' ? $localPackageDir : $vendorPackageDir;
    $cmd = escapeshellarg($python) . ' -c "import sys; sys.path.insert(0, r\''
        . str_replace('\\', '\\\\', $packageDir)
        . '\'); import pymysql, requests, bs4; print(\'OK: Python imports\')" 2>&1';
    $output = shell_exec($cmd) ?: '';
    $checks[] = trim($output) !== '' ? trim($output) : 'FAIL: Python import test produced no output';
}

$googleKey = trim((string) ($config['apis']['google_geocoding_key'] ?? ''));
if ($googleKey !== '') {
    $checks[] = 'OK: Google Geocoding API key configured';
} else {
    $checks[] = 'WARN: Google Geocoding API key missing; student city/state geocoding will use OpenStreetMap fallback';
}

echo "Web Scraper Setup Check\n";
echo str_repeat('=', 40) . "\n";
foreach ($checks as $line) {
    echo $line . "\n";
}
echo "\nOpen: http://localhost/web-scraper/admin/add_college.php\n";
echo "Open: http://localhost/web-scraper/admin/leads.php\n";
echo "Open: http://localhost/web-scraper/admin/api_status.php\n";
