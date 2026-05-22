<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

$DB_HOST = 'db5020492258.hosting-data.io';
$DB_NAME = 'dbs15690997';
$DB_USER = 'dbu216810';
$DB_PASS = 'Mikesch241079!';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

    $pdo = new PDO(
        $dsn,
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

if (!function_exists('now_hhmm')) {
    function now_hhmm(): string
    {
        return (new DateTimeImmutable())->format('H:i');
    }
}

if (!function_exists('is_time_field')) {
    function is_time_field(string $k): bool
    {
        static $t = [
            'workStart',
            'arriveWU',
            'departWU',
            'arriveH',
            'departH',
            'workEnd',
        ];

        return in_array($k, $t, true);
    }
}

if (!function_exists('allow_hall')) {
    function allow_hall(?string $v): string
    {
        $v = (string)($v ?? '');

        return in_array($v, ['28', '28C', '34'], true) ? $v : '';
    }
}