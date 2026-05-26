<?php

declare(strict_types=1);

function db(): mysqli
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    $config = require __DIR__ . '/../config/config.php';
    $db = $config['db'];

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn = new mysqli(
        $db['host'],
        $db['user'],
        $db['password'],
        $db['database']
    );
    $conn->set_charset($db['charset']);

    return $conn;
}

