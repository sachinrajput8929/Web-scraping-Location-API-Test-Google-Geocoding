<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$conn = db();
$stats = distributeStudentLeads($conn);

if (PHP_SAPI === 'cli') {
    echo 'Lead distribution complete' . PHP_EOL;
    echo 'Checked: ' . $stats['checked'] . PHP_EOL;
    echo 'Sent: ' . $stats['sent'] . PHP_EOL;
    echo 'Skipped existing: ' . $stats['skipped_existing'] . PHP_EOL;
    echo 'Skipped no location: ' . $stats['skipped_no_location'] . PHP_EOL;
    echo 'Skipped no match: ' . $stats['skipped_no_match'] . PHP_EOL;
    exit;
}

redirect(
    'leads.php?message=' . urlencode(
        'Distribution complete: ' . $stats['sent'] . ' leads sent from ' . $stats['checked'] . ' eligible students.'
    )
);
