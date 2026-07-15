<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$targetType = 'student';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--target=')) {
        $targetType = strtolower(trim(substr($argument, 9)));
    }
}

if (! in_array($targetType, ['student', 'staff'], true)) {
    echo '[' . date('Y-m-d H:i:s') . '] Invalid target type. Use student or staff.' . PHP_EOL;
    exit(1);
}

$pdo = database_connect($config['db']);

$logger = static function (string $message) use ($pdo): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $line . PHP_EOL;
    mail_worker_heartbeat($pdo, $message);
};

$logger('Background sender daemon started for ' . ucfirst($targetType) . '.');

try {
    while (mail_worker_should_continue($pdo, $targetType)) {
        $control = mail_worker_control($pdo);
        $runtimeConfig = mail_worker_runtime_config($pdo, $config['mail'] ?? []);
        $intervalSeconds = mail_batch_interval_seconds($runtimeConfig);
        $activeBatchKey = trim((string) ($control['active_batch_key'] ?? '')) ?: null;
        $summary = mail_worker_process($pdo, $runtimeConfig, $logger, $targetType, $activeBatchKey);

        $logger(sprintf(
            'Cycle complete for %s: sent=%d failed=%d pending=%d remaining=%d rate_limited=%s batch_size=%d interval=%d',
            $targetType,
            (int) $summary['sent'],
            (int) $summary['failed'],
            (int) $summary['pending'],
            (int) $summary['remaining'],
            $summary['rate_limited'] ? 'yes' : 'no',
            mail_batch_size($runtimeConfig),
            $intervalSeconds
        ));

        if ($activeBatchKey !== null && (int) $summary['remaining'] < 1) {
            mail_worker_mark_stopped($pdo, 'Background sender completed the current batch for ' . ucfirst($targetType) . '.');
            break;
        }

        for ($second = 0; $second < $intervalSeconds; $second++) {
            if (! mail_worker_should_continue($pdo, $targetType)) {
                break 2;
            }

            sleep(1);
        }
    }

    $finalControl = mail_worker_control($pdo);
    if (($finalControl['status'] ?? '') === 'running') {
        mail_worker_mark_stopped($pdo, 'Background sender stopped for ' . ucfirst($targetType) . '.');
    }
} catch (Throwable $exception) {
    mail_worker_mark_stopped($pdo, 'Background sender failed: ' . $exception->getMessage());
    echo '[' . date('Y-m-d H:i:s') . '] Background sender failed: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
