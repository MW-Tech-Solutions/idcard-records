<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = database_connect($config['db']);
$startedAt = date('Y-m-d H:i:s');
$targetType = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--target=')) {
        $targetType = strtolower(trim(substr($argument, 9)));
    }
}

if (! in_array($targetType, ['student', 'staff', null], true)) {
    echo '[' . date('Y-m-d H:i:s') . '] Invalid target type. Use student or staff.' . PHP_EOL;
    exit(1);
}

$logger = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

$logger('Mail worker started.');

try {
    $summary = mail_worker_process($pdo, $config['mail'] ?? [], $logger, $targetType);
    $logger(sprintf(
        'Done. sent=%d failed=%d pending=%d remaining=%d rate_limited=%s',
        (int) $summary['sent'],
        (int) $summary['failed'],
        (int) $summary['pending'],
        (int) $summary['remaining'],
        $summary['rate_limited'] ? 'yes' : 'no'
    ));
} catch (Throwable $exception) {
    $logger('Worker failed: ' . $exception->getMessage());
    exit(1);
}

$logger('Mail worker finished. Started at ' . $startedAt . '.');
