<?php

declare(strict_types=1);

function database_connect(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function auth_user(): ?array
{
    return isset($_SESSION['report_auth_user']) && is_array($_SESSION['report_auth_user'])
        ? $_SESSION['report_auth_user']
        : null;
}

function auth_check(): bool
{
    return auth_user() !== null;
}

function auth_require(string $context = 'json'): void
{
    if (auth_check()) {
        return;
    }

    http_response_code(401);

    if ($context === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Authentication required.']);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'Authentication required.';
    exit;
}

function auth_login(PDO $pdo, string $email, string $password): ?array
{
    $statement = $pdo->prepare(
        "SELECT id, name, email, password, role, is_active, profile_photo
         FROM users
         WHERE email = :email
         LIMIT 1"
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

    if (! $user || (int) ($user['is_active'] ?? 0) !== 1) {
        return null;
    }

    if (! in_array((string) $user['role'], ['super', 'admin'], true)) {
        return null;
    }

    if (! password_verify($password, (string) $user['password'])) {
        return null;
    }

    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')->execute([
        'id' => $user['id'],
    ]);

    session_regenerate_id(true);

    $payload = [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'profile_photo' => $user['profile_photo'],
    ];

    $_SESSION['report_auth_user'] = $payload;
    session_write_close();

    return $payload;
}

function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function report_filters_from_request(array $query): array
{
    $type = strtolower(trim((string) ($query['type'] ?? 'all')));
    $status = strtolower(trim((string) ($query['status'] ?? 'all')));
    $status2 = strtolower(trim((string) ($query['status_2'] ?? ($query['request_status'] ?? 'all'))));
    $search = trim((string) ($query['search'] ?? ''));
    $department = trim((string) ($query['department'] ?? ''));
    $validStatuses = ['all', 'submitted', 'not_submitted', 'submitted_not_printed', 'printed', 'not_printed', 'approved', 'rejected', 'pending', 'collected', 'not_collected', 'printed_approved'];

    if (! in_array($type, ['all', 'student', 'staff'], true)) {
        $type = 'all';
    }

    if (! in_array($status, $validStatuses, true)) {
        $status = 'all';
    }

    if (! in_array($status2, $validStatuses, true)) {
        $status2 = 'all';
    }

    if (in_array($status, ['approved', 'rejected', 'pending'], true) && $status2 === 'all') {
        $status2 = $status;
        $status = 'all';
    }

    if ($status === 'printed_approved') {
        $status = 'printed';
        $status2 = 'approved';
    }

    if ($status2 === 'printed_approved') {
        $status2 = 'approved';

        if ($status === 'all') {
            $status = 'printed';
        }
    }

    return [
        'type' => $type,
        'status' => $status,
        'status_2' => $status2,
        'search' => $search,
        'department' => $department,
    ];
}

function mail_filters_from_request(array $query): array
{
    $mode = mail_mode_from_request($query);
    $filters = report_filters_from_request($query);

    if ($mode === 'printed') {
        $filters['status'] = 'printed';
        $filters['status_2'] = 'approved';
    } else {
        $filters['status'] = 'all';
        $filters['status_2'] = 'rejected';
    }

    return $filters;
}

function mail_mode_from_request(array $query): string
{
    $mode = strtolower(trim((string) ($query['mail_mode'] ?? 'rejected')));

    return in_array($mode, ['rejected', 'printed'], true) ? $mode : 'rejected';
}

function report_filter_label(array $filters): string
{
    $typeLabels = [
        'all' => 'Staff and Student Records',
        'student' => 'Student Records',
        'staff' => 'Staff Records',
    ];

    $statusLabels = [
        'all' => 'All statuses',
        'submitted' => 'Submitted',
        'not_submitted' => 'Not Submitted',
        'submitted_not_printed' => 'Submitted but Not Printed',
        'printed' => 'Printed',
        'not_printed' => 'Not Printed',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'pending' => 'Pending',
        'collected' => 'Collected',
        'not_collected' => 'Not Collected',
        'printed_approved' => 'Printed and Approved',
    ];

    $label = $typeLabels[$filters['type']] . ' / ' . $statusLabels[$filters['status']];

    if (($filters['status_2'] ?? 'all') !== 'all') {
        $label .= ' / ' . $statusLabels[$filters['status_2']];
    }

    if (($filters['department'] ?? '') !== '') {
        $label .= ' / ' . $filters['department'];
    }

    return $label;
}

function report_query_string(array $filters, array $extra = []): string
{
    return http_build_query(array_filter(array_merge($filters, $extra), static function ($value): bool {
        return $value !== null && $value !== '';
    }));
}

function report_fetch_records(PDO $pdo, array $filters, ?int $limit = null): array
{
    $queries = [];
    $params = [];

    if ($filters['type'] !== 'staff') {
        $queries[] = report_build_student_query($filters, $params);
    }

    $studentOnlyStatus = in_array($filters['status'], ['collected', 'not_collected'], true)
        || in_array($filters['status_2'] ?? 'all', ['collected', 'not_collected'], true);

    if ($filters['type'] !== 'student' && ! $studentOnlyStatus) {
        $queries[] = report_build_staff_query($filters, $params);
    }

    if ($queries === []) {
        return [];
    }

    $sql = implode(' UNION ALL ', $queries) . ' ORDER BY record_type ASC, department ASC, full_name ASC';

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function report_build_student_query(array $filters, array &$params): string
{
    $conditions = ['archived_at IS NULL'];
    report_apply_common_filters('student', $conditions, $params, $filters);

    return "
        SELECT
            'student' AS record_type,
            id,
            matric_no AS primary_identifier,
            reference_number,
            CONCAT_WS(' ', last_name, first_name, other_name) AS full_name,
            department,
            graduation_year AS extra_meta,
            email,
            card_request_type,
            card_request_status,
            card_request_rejection_reason,
            is_submitted,
            is_printed,
            card_printed_at,
            is_collected,
            card_issued_at,
            card_expires_at,
            created_at,
            updated_at
        FROM student
        WHERE " . implode(' AND ', $conditions);
}

function report_build_staff_query(array $filters, array &$params): string
{
    $conditions = ['1 = 1'];
    report_apply_common_filters('staff', $conditions, $params, $filters);

    return "
        SELECT
            'staff' AS record_type,
            id,
            pf_number AS primary_identifier,
            reference_number,
            CONCAT_WS(' ', last_name, first_name, other_name) AS full_name,
            department,
            CONCAT_WS(' / ', `rank`, category) AS extra_meta,
            email,
            card_request_type,
            card_request_status,
            card_request_rejection_reason,
            is_submitted,
            is_printed,
            card_printed_at,
            NULL AS is_collected,
            card_issued_at,
            card_expires_at,
            created_at,
            updated_at
        FROM staff
        WHERE " . implode(' AND ', $conditions);
}

function report_apply_common_filters(string $table, array &$conditions, array &$params, array $filters): void
{
    report_apply_status_filter($table, $conditions, $filters['status']);
    report_apply_status_filter($table, $conditions, $filters['status_2'] ?? 'all');

    if ($filters['search'] !== '') {
        $conditions[] = '(' . implode(' OR ', [
            'first_name LIKE :search',
            'last_name LIKE :search',
            'other_name LIKE :search',
            ($table === 'student' ? 'matric_no' : 'pf_number') . ' LIKE :search',
            'reference_number LIKE :search',
            'department LIKE :search',
            'email LIKE :search',
        ]) . ')';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (($filters['department'] ?? '') !== '') {
        $conditions[] = 'department = :department';
        $params['department'] = $filters['department'];
    }
}

function report_apply_status_filter(string $table, array &$conditions, string $status): void
{
    switch ($status) {
        case 'submitted':
            $conditions[] = 'is_submitted = 1';
            break;
        case 'not_submitted':
            $conditions[] = '(is_submitted = 0 OR is_submitted IS NULL)';
            break;
        case 'submitted_not_printed':
            $conditions[] = 'is_submitted = 1';
            $conditions[] = '(is_printed = 0 OR is_printed IS NULL)';
            break;
        case 'printed':
            $conditions[] = 'is_printed = 1';
            break;
        case 'printed_approved':
            $conditions[] = 'is_printed = 1';
            $conditions[] = "card_request_status = 'approved'";
            break;
        case 'not_printed':
            $conditions[] = '(is_printed = 0 OR is_printed IS NULL)';
            break;
        case 'approved':
            $conditions[] = "card_request_status = 'approved'";
            break;
        case 'rejected':
            $conditions[] = "card_request_status = 'rejected'";
            break;
        case 'pending':
            $conditions[] = "(card_request_status IS NULL OR card_request_status = '' OR card_request_status = 'pending')";
            break;
        case 'collected':
            if ($table === 'student') {
                $conditions[] = 'is_collected = 1';
            }
            break;
        case 'not_collected':
            if ($table === 'student') {
                $conditions[] = 'is_collected = 0';
            }
            break;
    }
}

function report_summarize(array $records): array
{
    $summary = [
        'total' => count($records),
        'students' => 0,
        'staff' => 0,
        'submitted' => 0,
        'submitted_not_printed' => 0,
        'printed' => 0,
        'not_printed' => 0,
        'approved' => 0,
        'rejected' => 0,
        'collected' => 0,
    ];

    foreach ($records as $record) {
        $summary[$record['record_type'] === 'student' ? 'students' : 'staff']++;

        if ((int) $record['is_submitted'] === 1) {
            $summary['submitted']++;

            if ((int) $record['is_printed'] !== 1) {
                $summary['submitted_not_printed']++;
            }
        }

        if ((int) $record['is_printed'] === 1) {
            $summary['printed']++;
        } else {
            $summary['not_printed']++;
        }

        if (($record['card_request_status'] ?? '') === 'approved') {
            $summary['approved']++;
        }

        if (($record['card_request_status'] ?? '') === 'rejected') {
            $summary['rejected']++;
        }

        if (($record['is_collected'] ?? null) !== null && (int) $record['is_collected'] === 1) {
            $summary['collected']++;
        }
    }

    return $summary;
}

function report_fetch_sidebar_stats(PDO $pdo, string $search = ''): array
{
    return [
        'staff' => report_fetch_type_status_counts($pdo, 'staff', $search),
        'student' => report_fetch_type_status_counts($pdo, 'student', $search),
    ];
}

function report_fetch_departments(PDO $pdo, string $type = 'all'): array
{
    $queries = [];

    if ($type !== 'staff') {
        $queries[] = "SELECT DISTINCT department FROM student WHERE archived_at IS NULL AND department IS NOT NULL AND department <> ''";
    }

    if ($type !== 'student') {
        $queries[] = "SELECT DISTINCT department FROM staff WHERE department IS NOT NULL AND department <> ''";
    }

    if ($queries === []) {
        return [];
    }

    $statement = $pdo->query('SELECT DISTINCT department FROM (' . implode(' UNION ', $queries) . ') departments ORDER BY department ASC');

    return array_values(array_map(static function (array $row): string {
        return (string) $row['department'];
    }, $statement->fetchAll()));
}

function report_fetch_type_status_counts(PDO $pdo, string $table, string $search): array
{
    $identifier = $table === 'student' ? 'matric_no' : 'pf_number';
    $conditions = [];
    $params = [];

    if ($table === 'student') {
        $conditions[] = 'archived_at IS NULL';
    }

    if ($search !== '') {
        $conditions[] = '(' . implode(' OR ', [
            'first_name LIKE :search',
            'last_name LIKE :search',
            'other_name LIKE :search',
            $identifier . ' LIKE :search',
            'reference_number LIKE :search',
            'department LIKE :search',
            'email LIKE :search',
        ]) . ')';
        $params['search'] = '%' . $search . '%';
    }

    $where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
    $collectedSelect = $table === 'student'
        ? "SUM(CASE WHEN is_collected = 1 THEN 1 ELSE 0 END) AS collected_count,
           SUM(CASE WHEN is_collected = 0 THEN 1 ELSE 0 END) AS not_collected_count"
        : "0 AS collected_count,
           0 AS not_collected_count";

    $statement = $pdo->prepare("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN is_submitted = 1 THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN is_submitted = 0 OR is_submitted IS NULL THEN 1 ELSE 0 END) AS not_submitted_count,
            SUM(CASE WHEN is_submitted = 1 AND (is_printed = 0 OR is_printed IS NULL) THEN 1 ELSE 0 END) AS submitted_not_printed_count,
            SUM(CASE WHEN is_printed = 1 THEN 1 ELSE 0 END) AS printed_count,
            SUM(CASE WHEN is_printed = 0 OR is_printed IS NULL THEN 1 ELSE 0 END) AS not_printed_count,
            SUM(CASE WHEN card_request_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN card_request_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN card_request_status IS NULL OR card_request_status = '' OR card_request_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            {$collectedSelect}
        FROM {$table}
        WHERE {$where}
    ");
    $statement->execute($params);
    $row = $statement->fetch() ?: [];

    return [
        'all' => (int) ($row['total_count'] ?? 0),
        'submitted' => (int) ($row['submitted_count'] ?? 0),
        'submitted_not_printed' => (int) ($row['submitted_not_printed_count'] ?? 0),
        'printed' => (int) ($row['printed_count'] ?? 0),
        'rejected' => (int) ($row['rejected_count'] ?? 0),
        'not_submitted' => (int) ($row['not_submitted_count'] ?? 0),
        'not_printed' => (int) ($row['not_printed_count'] ?? 0),
        'approved' => (int) ($row['approved_count'] ?? 0),
        'pending' => (int) ($row['pending_count'] ?? 0),
        'collected' => (int) ($row['collected_count'] ?? 0),
        'not_collected' => (int) ($row['not_collected_count'] ?? 0),
    ];
}

function record_printing_date_ensure_columns(PDO $pdo): void
{
    foreach (['student', 'staff'] as $table) {
        auto_mail_add_column_if_missing($pdo, $table, 'card_printed_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `is_printed`');
    }
}

function record_printing_date_capture(PDO $pdo): void
{
    foreach (['student', 'staff'] as $table) {
        $where = $table === 'student'
            ? 'is_printed = 1 AND card_printed_at IS NULL AND archived_at IS NULL'
            : 'is_printed = 1 AND card_printed_at IS NULL';

        $pdo->exec("
            UPDATE {$table}
            SET card_printed_at = COALESCE(updated_at, card_issued_at, NOW())
            WHERE {$where}
        ");
    }
}

function mail_default_collection_message(): string
{
    return 'Disregard initial mails sent to collect ID cards from ICT South Core.' . "\n\n" . 'Your printed ID card is ready for collection. Please visit the ID card collection point in your respective colleges with a valid means of identification.';
}

function mail_batch_size(array $mailConfig): int
{
    $batchSize = (int) ($mailConfig['batch_size'] ?? 4);

    return max(1, min(500, $batchSize));
}

function mail_retry_limit(array $mailConfig): int
{
    return max(1, (int) ($mailConfig['max_retries'] ?? 3));
}

function mail_retry_delay_seconds(array $mailConfig): int
{
    return max(60, (int) ($mailConfig['retry_delay_seconds'] ?? 300));
}

function mail_batch_interval_seconds(array $mailConfig): int
{
    return max(1, (int) ($mailConfig['batch_interval_seconds'] ?? 120));
}

function mail_send_delay_microseconds(array $mailConfig): int
{
    return max(0, (int) ($mailConfig['send_delay_microseconds'] ?? 350000));
}

function auto_mail_process(PDO $pdo, array $mailConfig, int $batchSize = 20): array
{
    auto_mail_ensure_table($pdo);

    $summary = [
        'rejected' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
        'printed' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
    ];
    $jobs = array_merge(
        auto_mail_fetch_jobs($pdo, 'rejected', max(1, (int) floor($batchSize / 2))),
        auto_mail_fetch_jobs($pdo, 'printed', max(1, (int) ceil($batchSize / 2)))
    );

    foreach ($jobs as $job) {
        $type = (string) $job['notification_type'];
        $email = trim((string) ($job['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            auto_mail_record_attempt($pdo, $job, false, 'Invalid or missing email address.');
            $summary[$type]['skipped']++;
            continue;
        }

        try {
            if ($type === 'printed') {
                smtp_send_mail(
                    $mailConfig,
                    $email,
                    (string) ($job['full_name'] ?: $email),
                    'Printed ID Card Ready for Collection',
                    collection_mail_body($job, mail_default_collection_message())
                );
            } else {
                smtp_send_mail(
                    $mailConfig,
                    $email,
                    (string) ($job['full_name'] ?: $email),
                    'Rejected ID Card Application',
                    rejected_mail_body($job)
                );
            }

            auto_mail_record_attempt($pdo, $job, true, null);
            $summary[$type]['sent']++;
        } catch (Throwable $exception) {
            auto_mail_record_attempt($pdo, $job, false, $exception->getMessage());
            $summary[$type]['failed']++;
        }
    }

    return $summary;
}

function auto_mail_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_notification_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(20) NOT NULL,
            record_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(30) NOT NULL,
            status_signature CHAR(64) NOT NULL,
            recipient_email VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_attempt_at TIMESTAMP NULL DEFAULT NULL,
            last_error TEXT NULL,
            batch_key VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY mail_notification_unique (record_type, record_id, notification_type, status_signature),
            KEY mail_notification_lookup (record_type, record_id, notification_type),
            KEY mail_notification_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    auto_mail_add_column_if_missing($pdo, 'mail_notification_log', 'recipient_email', 'VARCHAR(255) NULL');
    auto_mail_add_column_if_missing($pdo, 'mail_notification_log', 'status', "VARCHAR(30) NOT NULL DEFAULT 'pending'");
    auto_mail_add_column_if_missing($pdo, 'mail_notification_log', 'retry_count', 'INT UNSIGNED NOT NULL DEFAULT 0');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_email_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(20) NOT NULL,
            record_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(30) NOT NULL,
            status_signature CHAR(64) NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_name VARCHAR(255) NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_at TIMESTAMP NULL DEFAULT NULL,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            last_error TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY mail_queue_unique (record_type, record_id, notification_type, status_signature),
            KEY mail_queue_status_available (status, available_at),
            KEY mail_queue_sent_at (sent_at),
            KEY mail_queue_recipient (recipient_email),
            KEY mail_queue_batch (batch_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    auto_mail_add_column_if_missing($pdo, 'mail_email_queue', 'batch_key', 'VARCHAR(64) NULL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_worker_control (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            status VARCHAR(30) NOT NULL DEFAULT 'stopped',
            target_type VARCHAR(20) NOT NULL DEFAULT 'student',
            pid VARCHAR(80) NULL,
            started_at TIMESTAMP NULL DEFAULT NULL,
            stopped_at TIMESTAMP NULL DEFAULT NULL,
            heartbeat_at TIMESTAMP NULL DEFAULT NULL,
            batch_size INT UNSIGNED NOT NULL DEFAULT 4,
            interval_seconds INT UNSIGNED NOT NULL DEFAULT 120,
            active_batch_key VARCHAR(64) NULL,
            last_cycle_at TIMESTAMP NULL DEFAULT NULL,
            worker_mode VARCHAR(30) NOT NULL DEFAULT 'browser',
            last_message TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    auto_mail_add_column_if_missing($pdo, 'mail_worker_control', 'batch_size', 'INT UNSIGNED NOT NULL DEFAULT 4');
    auto_mail_add_column_if_missing($pdo, 'mail_worker_control', 'interval_seconds', 'INT UNSIGNED NOT NULL DEFAULT 120');
    auto_mail_add_column_if_missing($pdo, 'mail_worker_control', 'active_batch_key', 'VARCHAR(64) NULL');
    auto_mail_add_column_if_missing($pdo, 'mail_worker_control', 'last_cycle_at', 'TIMESTAMP NULL DEFAULT NULL');
    auto_mail_add_column_if_missing($pdo, 'mail_worker_control', 'worker_mode', "VARCHAR(30) NOT NULL DEFAULT 'browser'");

    $pdo->exec("
        INSERT IGNORE INTO mail_worker_control (id, status, target_type, last_message)
        VALUES (1, 'stopped', 'student', 'Background sender is stopped.')
    ");
}

function auto_mail_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $statement = $pdo->prepare("
        SELECT COUNT(*) AS column_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    if ((int) ($statement->fetch()['column_exists'] ?? 0) === 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }
}

function auto_mail_fetch_jobs(PDO $pdo, string $notificationType, int $limit): array
{
    $records = report_fetch_records($pdo, [
        'type' => 'all',
        'status' => $notificationType === 'printed' ? 'printed_approved' : 'rejected',
        'search' => '',
        'department' => '',
    ]);
    $jobs = [];

    foreach ($records as $record) {
        $record['notification_type'] = $notificationType;
        $record['status_signature'] = auto_mail_status_signature($record, $notificationType);

        if (auto_mail_should_send($pdo, $record)) {
            $jobs[] = $record;
        }

        if (count($jobs) >= $limit) {
            break;
        }
    }

    return $jobs;
}

function auto_mail_status_signature(array $record, string $notificationType): string
{
    return hash('sha256', implode('|', [
        $notificationType,
        (string) ($record['card_request_status'] ?? ''),
        (string) ($record['is_printed'] ?? ''),
        (string) ($record['card_request_rejection_reason'] ?? ''),
        (string) ($record['card_issued_at'] ?? ''),
        (string) ($record['email'] ?? ''),
        (string) ($record['updated_at'] ?? ''),
    ]));
}

function auto_mail_should_send(PDO $pdo, array $record): bool
{
    $statement = $pdo->prepare("
        SELECT sent_at
        FROM mail_notification_log
        WHERE record_type = :record_type
          AND record_id = :record_id
          AND notification_type = :notification_type
          AND status_signature = :status_signature
        LIMIT 1
    ");
    $statement->execute([
        'record_type' => $record['record_type'],
        'record_id' => $record['id'],
        'notification_type' => $record['notification_type'],
        'status_signature' => $record['status_signature'],
    ]);
    $existing = $statement->fetch();

    return ! $existing || $existing['sent_at'] === null;
}

function mail_pending_count(PDO $pdo, array $records, string $notificationType): int
{
    auto_mail_ensure_table($pdo);

    $pending = 0;

    foreach ($records as $record) {
        $email = trim((string) ($record['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }

        $record['notification_type'] = $notificationType;
        $record['status_signature'] = auto_mail_status_signature($record, $notificationType);

        if (auto_mail_should_send($pdo, $record)) {
            $pending++;
        }
    }

    return $pending;
}

function auto_mail_record_attempt(PDO $pdo, array $record, bool $sent, ?string $error): void
{
    $statement = $pdo->prepare("
        INSERT INTO mail_notification_log (
            record_type,
            record_id,
            notification_type,
            status_signature,
            sent_at,
            attempts,
            last_attempt_at,
            last_error
        ) VALUES (
            :record_type,
            :record_id,
            :notification_type,
            :status_signature,
            :sent_at,
            1,
            NOW(),
            :last_error
        )
        ON DUPLICATE KEY UPDATE
            sent_at = IF(:was_sent = 1, NOW(), sent_at),
            attempts = attempts + 1,
            last_attempt_at = NOW(),
            last_error = :last_error_update
    ");
    $statement->execute([
        'record_type' => $record['record_type'],
        'record_id' => $record['id'],
        'notification_type' => $record['notification_type'],
        'status_signature' => $record['status_signature'],
        'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
        'last_error' => $error,
        'was_sent' => $sent ? 1 : 0,
        'last_error_update' => $error,
    ]);
}

function mail_log_status(PDO $pdo, array $record, string $status, ?string $error = null, ?int $retryCount = null): void
{
    $sent = $status === 'sent';
    $attempts = max(1, (int) ($record['attempts'] ?? 1));
    $retryCount = $retryCount ?? max(0, $attempts - 1);

    $statement = $pdo->prepare("
        INSERT INTO mail_notification_log (
            record_type,
            record_id,
            notification_type,
            status_signature,
            recipient_email,
            status,
            retry_count,
            sent_at,
            attempts,
            last_attempt_at,
            last_error
        ) VALUES (
            :record_type,
            :record_id,
            :notification_type,
            :status_signature,
            :recipient_email,
            :status,
            :retry_count,
            :sent_at,
            :attempts,
            NOW(),
            :last_error
        )
        ON DUPLICATE KEY UPDATE
            recipient_email = VALUES(recipient_email),
            status = VALUES(status),
            retry_count = VALUES(retry_count),
            sent_at = IF(:was_sent = 1, NOW(), sent_at),
            attempts = GREATEST(attempts, VALUES(attempts)),
            last_attempt_at = NOW(),
            last_error = VALUES(last_error)
    ");
    $statement->execute([
        'record_type' => $record['record_type'],
        'record_id' => $record['record_id'] ?? $record['id'],
        'notification_type' => $record['notification_type'],
        'status_signature' => $record['status_signature'],
        'recipient_email' => $record['recipient_email'] ?? $record['email'] ?? null,
        'status' => $status,
        'retry_count' => $retryCount,
        'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
        'attempts' => $attempts,
        'last_error' => $error,
        'was_sent' => $sent ? 1 : 0,
    ]);
}

function mail_was_sent(PDO $pdo, array $record, string $notificationType): bool
{
    $statement = $pdo->prepare("
        SELECT id
        FROM mail_notification_log
        WHERE record_type = :record_type
          AND record_id = :record_id
          AND notification_type = :notification_type
          AND status_signature = :status_signature
          AND sent_at IS NOT NULL
        LIMIT 1
    ");
    $statement->execute([
        'record_type' => $record['record_type'],
        'record_id' => $record['id'],
        'notification_type' => $notificationType,
        'status_signature' => $record['status_signature'],
    ]);

    return (bool) $statement->fetch();
}

function mail_has_active_queue_job(PDO $pdo, array $record, string $notificationType): bool
{
    $statement = $pdo->prepare("
        SELECT id
        FROM mail_email_queue
        WHERE record_type = :record_type
          AND record_id = :record_id
          AND notification_type = :notification_type
          AND status_signature = :status_signature
          AND status IN ('queued', 'processing')
        LIMIT 1
    ");
    $statement->execute([
        'record_type' => $record['record_type'],
        'record_id' => $record['id'],
        'notification_type' => $notificationType,
        'status_signature' => $record['status_signature'],
    ]);

    return (bool) $statement->fetch();
}

function mail_attach_active_queue_job_to_batch(PDO $pdo, array $record, string $notificationType, string $batchKey): bool
{
    $statement = $pdo->prepare("
        UPDATE mail_email_queue
        SET batch_key = :batch_key
        WHERE record_type = :record_type
          AND record_id = :record_id
          AND notification_type = :notification_type
          AND status_signature = :status_signature
          AND status IN ('queued', 'processing')
        LIMIT 1
    ");
    $statement->execute([
        'batch_key' => $batchKey,
        'record_type' => $record['record_type'],
        'record_id' => $record['id'],
        'notification_type' => $notificationType,
        'status_signature' => $record['status_signature'],
    ]);

    return $statement->rowCount() > 0;
}

function mail_new_batch_key(): string
{
    return date('YmdHis') . '-' . bin2hex(random_bytes(6));
}

function mail_enqueue_records(PDO $pdo, array $records, string $notificationType, string $collectionMessage = '', ?int $limit = null, ?string $batchKey = null): array
{
    auto_mail_ensure_table($pdo);

    $limit = $limit !== null ? max(1, $limit) : null;
    $batchKey = $batchKey !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $batchKey) === 1
        ? $batchKey
        : mail_new_batch_key();
    $result = [
        'queued' => 0,
        'skipped' => 0,
        'already_sent' => 0,
        'already_queued' => 0,
        'remaining' => 0,
        'batch_limit' => $limit,
        'batch_key' => $batchKey,
        'errors' => [],
    ];
    $collectionMessage = trim($collectionMessage) !== '' ? trim($collectionMessage) : mail_default_collection_message();
    $batchMembers = 0;

    $insert = $pdo->prepare("
        INSERT IGNORE INTO mail_email_queue (
            record_type,
            record_id,
            notification_type,
            status_signature,
            recipient_email,
            recipient_name,
            subject,
            body,
            status,
            batch_key,
            available_at
        ) VALUES (
            :record_type,
            :record_id,
            :notification_type,
            :status_signature,
            :recipient_email,
            :recipient_name,
            :subject,
            :body,
            'queued',
            :batch_key,
            NOW()
        )
    ");

    foreach ($records as $record) {
        $record['notification_type'] = $notificationType;
        $record['status_signature'] = auto_mail_status_signature($record, $notificationType);
        $email = trim((string) ($record['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $record['recipient_email'] = $email;
            mail_log_status($pdo, $record, 'skipped', 'Invalid or missing email address.', 0);
            $result['skipped']++;
            continue;
        }

        if (mail_was_sent($pdo, $record, $notificationType)) {
            $result['already_sent']++;
            continue;
        }

        if (mail_has_active_queue_job($pdo, $record, $notificationType)) {
            if (mail_attach_active_queue_job_to_batch($pdo, $record, $notificationType, $batchKey)) {
                $batchMembers++;
            }

            $result['already_queued']++;
            if ($limit !== null && $batchMembers >= $limit) {
                break;
            }

            continue;
        }

        $subject = $notificationType === 'printed'
            ? 'Printed ID Card Ready for Collection'
            : 'Rejected ID Card Application';
        $body = $notificationType === 'printed'
            ? collection_mail_body($record, $collectionMessage)
            : rejected_mail_body($record);

        $insert->execute([
            'record_type' => $record['record_type'],
            'record_id' => $record['id'],
            'notification_type' => $notificationType,
            'status_signature' => $record['status_signature'],
            'recipient_email' => $email,
            'recipient_name' => (string) ($record['full_name'] ?: $email),
            'subject' => $subject,
            'body' => $body,
            'batch_key' => $batchKey,
        ]);

        if ($insert->rowCount() > 0) {
            $result['queued']++;
            $batchMembers++;
            $record['recipient_email'] = $email;
            mail_log_status($pdo, $record, 'queued', null, 0);

            if ($limit !== null && $batchMembers >= $limit) {
                break;
            }
        } else {
            $result['already_queued']++;
        }
    }

    $result['remaining'] = mail_queue_stats($pdo, $batchKey)['pending'];

    return $result;
}

function mail_delivery_pending_count(PDO $pdo, array $records, string $notificationType): int
{
    auto_mail_ensure_table($pdo);

    $pending = 0;

    foreach ($records as $record) {
        $email = trim((string) ($record['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }

        $record['status_signature'] = auto_mail_status_signature($record, $notificationType);

        if (! mail_was_sent($pdo, $record, $notificationType)) {
            $pending++;
        }
    }

    return $pending;
}

function mail_queue_stats(PDO $pdo, ?string $batchKey = null): array
{
    auto_mail_ensure_table($pdo);

    $stats = [
        'queued' => 0,
        'pending' => 0,
        'processing' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'remaining' => 0,
    ];
    $conditions = [];
    $params = [];

    if ($batchKey !== null && $batchKey !== '') {
        $conditions[] = 'batch_key = :batch_key';
        $params['batch_key'] = $batchKey;
    }

    $whereSql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $statement = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM mail_email_queue
        {$whereSql}
        GROUP BY status
    ");
    $statement->execute($params);

    foreach ($statement->fetchAll() as $row) {
        $status = (string) $row['status'];
        $stats[$status] = (int) $row['total'];
        if (in_array($status, ['queued', 'processing'], true)) {
            $stats['remaining'] += (int) $row['total'];
        }
    }

    $logStatement = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM mail_notification_log
        WHERE status IN ('skipped')
        GROUP BY status
    ");

    foreach ($logStatement->fetchAll() as $row) {
        $stats[(string) $row['status']] = (int) $row['total'];
    }

    $stats['pending'] = $stats['queued'] ?? 0;

    return $stats;
}

function mail_queue_target_stats(PDO $pdo, ?string $batchKey = null): array
{
    auto_mail_ensure_table($pdo);

    $stats = [
        'student' => ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0],
        'staff' => ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0],
    ];
    $conditions = ["record_type IN ('student', 'staff')"];
    $params = [];

    if ($batchKey !== null && $batchKey !== '') {
        $conditions[] = 'batch_key = :batch_key';
        $params['batch_key'] = $batchKey;
    }

    $statement = $pdo->prepare("
        SELECT record_type, status, COUNT(*) AS total
        FROM mail_email_queue
        WHERE " . implode(' AND ', $conditions) . "
        GROUP BY record_type, status
    ");
    $statement->execute($params);

    foreach ($statement->fetchAll() as $row) {
        $type = (string) $row['record_type'];
        $status = (string) $row['status'];
        $total = (int) $row['total'];

        if (! isset($stats[$type])) {
            continue;
        }

        if (array_key_exists($status, $stats[$type])) {
            $stats[$type][$status] = $total;
        }

        if (in_array($status, ['queued', 'processing'], true)) {
            $stats[$type]['remaining'] += $total;
        }
    }

    return $stats;
}

function mail_history_filters_from_request(array $query): array
{
    $type = strtolower(trim((string) ($query['mail_type'] ?? 'rejected')));
    $recordType = strtolower(trim((string) ($query['record_type'] ?? 'student')));
    $status = strtolower(trim((string) ($query['mail_status'] ?? 'sent')));
    $search = trim((string) ($query['search'] ?? ''));

    if (! in_array($type, ['rejected', 'printed'], true)) {
        $type = 'rejected';
    }

    if (! in_array($recordType, ['student', 'staff'], true)) {
        $recordType = 'student';
    }

    if (! in_array($status, ['all', 'queued', 'processing', 'sent', 'failed'], true)) {
        $status = 'sent';
    }

    return [
        'mail_type' => $type,
        'record_type' => $recordType,
        'mail_status' => $status,
        'search' => $search,
    ];
}

function mail_history_counts(PDO $pdo): array
{
    auto_mail_ensure_table($pdo);

    $counts = [
        'rejected' => [
            'student' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
            'staff' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
        ],
        'printed' => [
            'student' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
            'staff' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
        ],
    ];
    $statement = $pdo->query("
        SELECT notification_type, record_type, status, COUNT(*) AS total
        FROM mail_email_queue
        WHERE notification_type IN ('rejected', 'printed')
          AND record_type IN ('student', 'staff')
        GROUP BY notification_type, record_type, status
    ");

    foreach ($statement->fetchAll() as $row) {
        $type = (string) $row['notification_type'];
        $recordType = (string) $row['record_type'];
        $status = (string) $row['status'];
        $total = (int) $row['total'];

        if (! isset($counts[$type][$recordType])) {
            continue;
        }

        $counts[$type][$recordType]['all'] += $total;

        if (array_key_exists($status, $counts[$type][$recordType])) {
            $counts[$type][$recordType][$status] += $total;
        }
    }

    return $counts;
}

function mail_history_records(PDO $pdo, array $filters, int $limit = 200): array
{
    auto_mail_ensure_table($pdo);

    $conditions = [
        'q.notification_type = :notification_type',
        'q.record_type = :record_type',
    ];
    $params = [
        'notification_type' => $filters['mail_type'],
        'record_type' => $filters['record_type'],
    ];

    if ($filters['mail_status'] !== 'all') {
        $conditions[] = 'q.status = :status';
        $params['status'] = $filters['mail_status'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = '(' . implode(' OR ', [
            'q.recipient_email LIKE :search',
            'q.recipient_name LIKE :search',
            'q.subject LIKE :search',
            'q.last_error LIKE :search',
        ]) . ')';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    $limit = max(1, min(500, $limit));
    $statement = $pdo->prepare("
        SELECT
            q.*,
            l.retry_count,
            l.last_attempt_at AS log_last_attempt_at
        FROM mail_email_queue q
        LEFT JOIN mail_notification_log l
          ON l.record_type = q.record_type
         AND l.record_id = q.record_id
         AND l.notification_type = q.notification_type
         AND l.status_signature = q.status_signature
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY
            CASE WHEN q.status = 'failed' THEN 0 WHEN q.status = 'queued' THEN 1 ELSE 2 END,
            COALESCE(q.sent_at, q.updated_at, q.created_at) DESC
        LIMIT {$limit}
    ");
    $statement->execute($params);

    return $statement->fetchAll();
}

function mail_retry_failed_job(PDO $pdo, int $queueId): bool
{
    auto_mail_ensure_table($pdo);

    $statement = $pdo->prepare("
        UPDATE mail_email_queue
        SET status = 'queued',
            attempts = 0,
            available_at = NOW(),
            locked_at = NULL,
            last_error = NULL,
            updated_at = NOW()
        WHERE id = :id
          AND status = 'failed'
        LIMIT 1
    ");
    $statement->execute(['id' => $queueId]);

    if ($statement->rowCount() < 1) {
        return false;
    }

    $jobStatement = $pdo->prepare('SELECT * FROM mail_email_queue WHERE id = :id LIMIT 1');
    $jobStatement->execute(['id' => $queueId]);
    $job = $jobStatement->fetch();

    if ($job) {
        $job['attempts'] = 0;
        mail_log_status($pdo, $job, 'queued', null, 0);
    }

    return true;
}

function mail_recent_sent_count(PDO $pdo, int $intervalSeconds, ?string $batchKey = null): int
{
    $intervalSeconds = max(1, $intervalSeconds);
    $conditions = [
        "status = 'sent'",
        "sent_at >= DATE_SUB(NOW(), INTERVAL {$intervalSeconds} SECOND)",
    ];
    $params = [];

    if ($batchKey !== null && $batchKey !== '') {
        $conditions[] = 'batch_key = :batch_key';
        $params['batch_key'] = $batchKey;
    }

    $statement = $pdo->prepare("
        SELECT COUNT(*) AS sent_count
        FROM mail_email_queue
        WHERE " . implode(' AND ', $conditions) . "
    ");
    $statement->execute($params);

    return (int) ($statement->fetch()['sent_count'] ?? 0);
}

function mail_worker_claim_jobs(PDO $pdo, int $limit, int $maxRetries, ?string $recordType = null, ?string $batchKey = null): array
{
    if ($limit < 1) {
        return [];
    }

    $pdo->exec("
        UPDATE mail_email_queue
        SET status = 'queued',
            locked_at = NULL
        WHERE status = 'processing'
          AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");

    $conditions = [
        "status IN ('queued', 'failed')",
        'attempts < :max_retries',
        'available_at <= NOW()',
    ];
    $params = ['max_retries' => $maxRetries];

    if (in_array($recordType, ['student', 'staff'], true)) {
        $conditions[] = 'record_type = :record_type';
        $params['record_type'] = $recordType;
    }

    if ($batchKey !== null && $batchKey !== '') {
        $conditions[] = 'batch_key = :batch_key';
        $params['batch_key'] = $batchKey;
    }

    $statement = $pdo->prepare("
        SELECT *
        FROM mail_email_queue
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY available_at ASC, id ASC
        LIMIT {$limit}
    ");
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value, $key === 'max_retries' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->execute();
    $jobs = $statement->fetchAll();

    if ($jobs === []) {
        return [];
    }

    $ids = array_map(static fn (array $job): int => (int) $job['id'], $jobs);
    $pdo->exec('UPDATE mail_email_queue SET status = "processing", locked_at = NOW() WHERE id IN (' . implode(',', $ids) . ')');

    return $jobs;
}

function mail_worker_process(PDO $pdo, array $mailConfig, ?callable $logger = null, ?string $recordType = null, ?string $batchKey = null): array
{
    auto_mail_ensure_table($pdo);

    $batchSize = mail_batch_size($mailConfig);
    $intervalSeconds = mail_batch_interval_seconds($mailConfig);
    $maxRetries = mail_retry_limit($mailConfig);
    $retryDelay = mail_retry_delay_seconds($mailConfig);
    $sendDelay = mail_send_delay_microseconds($mailConfig);
    $recentSent = mail_recent_sent_count($pdo, $intervalSeconds, $batchKey);
    $slots = max(0, $batchSize - $recentSent);
    $summary = [
        'sent' => 0,
        'failed' => 0,
        'pending' => 0,
        'remaining' => 0,
        'rate_limited' => $slots === 0,
        'errors' => [],
    ];

    $log = static function (string $message) use ($logger): void {
        if ($logger !== null) {
            $logger($message);
        }
    };

    if ($slots === 0) {
        $stats = mail_queue_stats($pdo, $batchKey);
        $summary['pending'] = $stats['pending'];
        $summary['remaining'] = $stats['remaining'];
        $log(sprintf('Rate limit active: %d emails were already sent in the last %d seconds.', $recentSent, $intervalSeconds));
        return $summary;
    }

    $jobs = mail_worker_claim_jobs($pdo, $slots, $maxRetries, $recordType, $batchKey);
    $summary['pending'] = count($jobs);
    $session = null;

    try {
        foreach ($jobs as $job) {
            $attempt = (int) $job['attempts'] + 1;

            try {
                if ($session === null) {
                    $session = smtp_open_session($mailConfig);
                    $log('SMTP session opened.');
                }

                smtp_send_mail_with_session(
                    $session,
                    (string) $job['recipient_email'],
                    (string) ($job['recipient_name'] ?: $job['recipient_email']),
                    (string) $job['subject'],
                    (string) $job['body']
                );

                $update = $pdo->prepare("
                    UPDATE mail_email_queue
                    SET status = 'sent',
                        attempts = :attempts,
                        sent_at = NOW(),
                        last_error = NULL,
                        locked_at = NULL
                    WHERE id = :id
                ");
                $update->execute(['attempts' => $attempt, 'id' => $job['id']]);

                $job['attempts'] = $attempt;
                mail_log_status($pdo, $job, 'sent', null, max(0, $attempt - 1));
                $summary['sent']++;
                $log(sprintf('Sent %s to %s (%d/%d).', $job['notification_type'], $job['recipient_email'], $summary['sent'], $slots));

                if ($sendDelay > 0) {
                    usleep($sendDelay);
                }
            } catch (Throwable $exception) {
                $finalFailure = $attempt >= $maxRetries;
                $status = $finalFailure ? 'failed' : 'queued';
                $availableAt = $finalFailure
                    ? date('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s', time() + $retryDelay);

                $update = $pdo->prepare("
                    UPDATE mail_email_queue
                    SET status = :status,
                        attempts = :attempts,
                        available_at = :available_at,
                        locked_at = NULL,
                        last_error = :last_error
                    WHERE id = :id
                ");
                $update->execute([
                    'status' => $status,
                    'attempts' => $attempt,
                    'available_at' => $availableAt,
                    'last_error' => $exception->getMessage(),
                    'id' => $job['id'],
                ]);

                $job['attempts'] = $attempt;
                mail_log_status($pdo, $job, $finalFailure ? 'failed' : 'retrying', $exception->getMessage(), max(0, $attempt - 1));
                $summary['failed']++;
                $summary['errors'][] = sprintf('%s: %s', $job['recipient_email'], $exception->getMessage());
                $log(sprintf(
                    'Failed %s to %s on attempt %d/%d. %s',
                    $job['notification_type'],
                    $job['recipient_email'],
                    $attempt,
                    $maxRetries,
                    $exception->getMessage()
                ));
            }
        }
    } finally {
        if ($session !== null) {
            smtp_close_session($session);
            $log('SMTP session closed.');
        }
    }

    $stats = mail_queue_stats($pdo, $batchKey);
    $summary['pending'] = $stats['pending'];
    $summary['remaining'] = $stats['remaining'];
    $log(sprintf('Batch summary: sent=%d failed=%d pending=%d remaining=%d.', $summary['sent'], $summary['failed'], $summary['pending'], $summary['remaining']));

    return $summary;
}

function mail_worker_control(PDO $pdo): array
{
    auto_mail_ensure_table($pdo);

    $statement = $pdo->query('SELECT * FROM mail_worker_control WHERE id = 1 LIMIT 1');
    $control = $statement->fetch();

    return $control ?: [
        'status' => 'stopped',
        'target_type' => 'student',
        'pid' => null,
        'started_at' => null,
        'stopped_at' => null,
        'heartbeat_at' => null,
        'batch_size' => 4,
        'interval_seconds' => 120,
        'active_batch_key' => null,
        'last_cycle_at' => null,
        'worker_mode' => 'browser',
        'last_message' => 'Background sender is stopped.',
    ];
}

function mail_worker_reconcile_control(PDO $pdo, array $mailConfig = []): array
{
    $control = mail_worker_control($pdo);

    if (($control['status'] ?? '') !== 'running') {
        return $control;
    }

    $runtimeInterval = (int) ($control['interval_seconds'] ?? mail_batch_interval_seconds($mailConfig));
    $staleAfter = max(180, max(1, $runtimeInterval) + 90);
    $ageStatement = $pdo->query("
        SELECT TIMESTAMPDIFF(SECOND, COALESCE(heartbeat_at, started_at), NOW()) AS heartbeat_age
        FROM mail_worker_control
        WHERE id = 1
        LIMIT 1
    ");
    $heartbeatAge = $ageStatement !== false ? $ageStatement->fetchColumn() : false;

    if ($heartbeatAge === false || $heartbeatAge === null || (int) $heartbeatAge > $staleAfter) {
        mail_worker_mark_stopped($pdo, 'Background sender stopped because no heartbeat was received.');
        return mail_worker_control($pdo);
    }

    return $control;
}

function mail_worker_start(PDO $pdo, array $config, string $targetType, ?int $batchSize = null, ?int $intervalSeconds = null, ?string $batchKey = null): array
{
    auto_mail_ensure_table($pdo);

    if (! in_array($targetType, ['student', 'staff'], true)) {
        return ['type' => 'warning', 'text' => 'Choose either Students or Staff before starting the sender.'];
    }

    $control = mail_worker_reconcile_control($pdo, $config['mail'] ?? []);

    if (($control['status'] ?? '') === 'running') {
        return [
            'type' => 'warning',
            'text' => 'Background sender is already running for ' . ucfirst((string) $control['target_type']) . '. Terminate it before starting another sender.',
        ];
    }

    $pid = (string) getmypid();
    $batchSize = max(1, min(500, (int) ($batchSize ?? mail_batch_size($config['mail'] ?? []))));
    $intervalSeconds = max(1, min(86400, (int) ($intervalSeconds ?? mail_batch_interval_seconds($config['mail'] ?? []))));
    $batchKey = $batchKey !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $batchKey) === 1 ? $batchKey : null;
    $statement = $pdo->prepare("
        UPDATE mail_worker_control
        SET status = 'running',
            target_type = :target_type,
            pid = :pid,
            started_at = NOW(),
            stopped_at = NULL,
            heartbeat_at = NOW(),
            batch_size = :batch_size,
            interval_seconds = :interval_seconds,
            active_batch_key = :active_batch_key,
            last_cycle_at = NULL,
            worker_mode = 'browser',
            last_message = :message
        WHERE id = 1
    ");
    $statement->execute([
        'target_type' => $targetType,
        'pid' => $pid,
        'batch_size' => $batchSize,
        'interval_seconds' => $intervalSeconds,
        'active_batch_key' => $batchKey,
        'message' => 'Browser sender ready for ' . ucfirst($targetType) . ': ' . number_format($batchSize) . ' emails every ' . number_format($intervalSeconds) . ' seconds. Keep this page open.',
    ]);

    try {
        mail_worker_spawn_daemon($config, $targetType);
        $pdo->prepare("
            UPDATE mail_worker_control
            SET worker_mode = 'daemon',
                last_message = :message
            WHERE id = 1
        ")->execute(['message' => 'Background sender started for ' . ucfirst($targetType) . ': ' . number_format($batchSize) . ' emails every ' . number_format($intervalSeconds) . ' seconds.']);

        return ['type' => 'success', 'text' => 'Background sender started for ' . ucfirst($targetType) . ': ' . number_format($batchSize) . ' emails every ' . number_format($intervalSeconds) . ' seconds.'];
    } catch (Throwable $exception) {
        mail_worker_heartbeat($pdo, 'Browser sender active because server background processes are unavailable. Keep this page open.');
    }

    return ['type' => 'success', 'text' => 'Browser sender started. Keep this page open to send ' . number_format($batchSize) . ' emails every ' . number_format($intervalSeconds) . ' seconds.'];
}

function mail_worker_stop(PDO $pdo): array
{
    auto_mail_ensure_table($pdo);

    $control = mail_worker_control($pdo);

    if (($control['status'] ?? '') !== 'running') {
        return ['type' => 'warning', 'text' => 'Background sender is not currently running.'];
    }

    $pdo->prepare("
        UPDATE mail_worker_control
        SET status = 'stopped',
            stopped_at = NOW(),
            last_message = :message
        WHERE id = 1
    ")->execute(['message' => 'Terminate requested. Sender will stop after the current batch.']);

    return ['type' => 'success', 'text' => 'Terminate requested. The sender will stop after the current batch.'];
}

function mail_worker_should_continue(PDO $pdo, string $targetType): bool
{
    $control = mail_worker_reconcile_control($pdo);

    return ($control['status'] ?? '') === 'running'
        && ($control['target_type'] ?? '') === $targetType;
}

function mail_worker_runtime_config(PDO $pdo, array $mailConfig): array
{
    $control = mail_worker_control($pdo);
    $runtimeConfig = $mailConfig;
    $runtimeConfig['batch_size'] = max(1, min(500, (int) ($control['batch_size'] ?? mail_batch_size($mailConfig))));
    $runtimeConfig['batch_interval_seconds'] = max(1, min(86400, (int) ($control['interval_seconds'] ?? mail_batch_interval_seconds($mailConfig))));

    return $runtimeConfig;
}

function mail_worker_browser_tick(PDO $pdo, array $mailConfig): array
{
    auto_mail_ensure_table($pdo);

    $control = mail_worker_reconcile_control($pdo, $mailConfig);

    if (($control['status'] ?? '') !== 'running') {
        return [
            'ran' => false,
            'message' => 'Sender is not running.',
            'summary' => ['sent' => 0, 'failed' => 0, 'pending' => 0, 'remaining' => 0, 'rate_limited' => false, 'errors' => []],
        ];
    }

    $targetType = (string) ($control['target_type'] ?? 'student');
    $batchKey = trim((string) ($control['active_batch_key'] ?? '')) ?: null;
    $intervalSeconds = max(1, (int) ($control['interval_seconds'] ?? mail_batch_interval_seconds($mailConfig)));
    $dueStatement = $pdo->query("
        SELECT last_cycle_at IS NULL OR TIMESTAMPDIFF(SECOND, last_cycle_at, NOW()) >= {$intervalSeconds} AS is_due
        FROM mail_worker_control
        WHERE id = 1
        LIMIT 1
    ");
    $isDue = $dueStatement !== false && (int) $dueStatement->fetchColumn() === 1;

    mail_worker_heartbeat($pdo, 'Browser sender is waiting for the next batch.');

    if (! $isDue) {
        return [
            'ran' => false,
            'message' => 'Waiting for the next send interval.',
            'summary' => ['sent' => 0, 'failed' => 0, 'pending' => 0, 'remaining' => mail_queue_stats($pdo, $batchKey)['remaining'], 'rate_limited' => true, 'errors' => []],
        ];
    }

    $pdo->exec('UPDATE mail_worker_control SET last_cycle_at = NOW() WHERE id = 1');
    $runtimeConfig = mail_worker_runtime_config($pdo, $mailConfig);
    $summary = mail_worker_process($pdo, $runtimeConfig, static function (string $message) use ($pdo): void {
        mail_worker_heartbeat($pdo, $message);
    }, $targetType, $batchKey);

    if ($batchKey !== null && (int) $summary['remaining'] < 1) {
        mail_worker_mark_stopped($pdo, 'Browser sender completed the current batch for ' . ucfirst($targetType) . '.');
    }

    return [
        'ran' => true,
        'message' => 'Send batch processed.',
        'summary' => $summary,
    ];
}

function mail_worker_heartbeat(PDO $pdo, string $message): void
{
    $statement = $pdo->prepare("
        UPDATE mail_worker_control
        SET heartbeat_at = NOW(),
            last_message = :message
        WHERE id = 1
    ");
    $statement->execute(['message' => $message]);
}

function mail_worker_mark_stopped(PDO $pdo, string $message): void
{
    $statement = $pdo->prepare("
        UPDATE mail_worker_control
        SET status = 'stopped',
            stopped_at = NOW(),
            last_message = :message
        WHERE id = 1
    ");
    $statement->execute(['message' => $message]);
}

function mail_worker_spawn_daemon(array $config, string $targetType): void
{
    $phpBinary = (string) ($config['mail']['php_binary'] ?? PHP_BINARY);
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mail-worker-daemon.php';
    $logFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail-worker-daemon.log';

    if (! is_file($script)) {
        throw new RuntimeException('Missing worker daemon script.');
    }

    if ($phpBinary === '') {
        throw new RuntimeException('PHP binary path is empty.');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cmd /C start "" /B ' . escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($script)
            . ' --target=' . escapeshellarg($targetType)
            . ' >> ' . escapeshellarg($logFile)
            . ' 2>&1';
        pclose(popen($command, 'r'));
        return;
    }

    $command = 'nohup ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($script) . ' --target=' . escapeshellarg($targetType) . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    exec($command);
}

function rejected_mail_send_to_records(array $records, array $mailConfig, ?PDO $pdo = null, ?int $batchSize = null): array
{
    $result = [
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'already_sent' => 0,
        'remaining' => 0,
        'batch_limit' => $batchSize ?? count($records),
        'errors' => [],
    ];
    $attempted = 0;
    $limit = $batchSize !== null ? max(1, $batchSize) : null;

    foreach ($records as $record) {
        if ($pdo !== null) {
            $record['notification_type'] = 'rejected';
            $record['status_signature'] = auto_mail_status_signature($record, 'rejected');

            if (! auto_mail_should_send($pdo, $record)) {
                $result['already_sent']++;
                continue;
            }
        }

        if ($limit !== null && $attempted >= $limit) {
            $result['remaining']++;
            continue;
        }

        $attempted++;
        $email = trim((string) ($record['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            if ($pdo !== null) {
                auto_mail_record_attempt($pdo, $record, false, 'Invalid or missing email address.');
            }
            $result['skipped']++;
            continue;
        }

        $subject = 'Rejected ID Card Application';
        $body = rejected_mail_body($record);

        try {
            smtp_send_mail($mailConfig, $email, (string) ($record['full_name'] ?: $email), $subject, $body);
            usleep(250000);
            if ($pdo !== null) {
                auto_mail_record_attempt($pdo, $record, true, null);
            }
            $result['sent']++;
        } catch (Throwable $exception) {
            if ($pdo !== null) {
                auto_mail_record_attempt($pdo, $record, false, $exception->getMessage());
            }
            $result['failed']++;
            $result['errors'][] = sprintf(
                '%s (%s): %s',
                (string) ($record['primary_identifier'] ?: 'No identifier'),
                $email,
                $exception->getMessage()
            );
        }
    }

    return $result;
}

function collection_mail_send_to_records(array $records, array $mailConfig, string $generalMessage, ?PDO $pdo = null, ?int $batchSize = null): array
{
    $result = [
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'already_sent' => 0,
        'remaining' => 0,
        'batch_limit' => $batchSize ?? count($records),
        'errors' => [],
    ];
    $attempted = 0;
    $limit = $batchSize !== null ? max(1, $batchSize) : null;

    $generalMessage = trim($generalMessage);

    if ($generalMessage === '') {
        $generalMessage = mail_default_collection_message();
    }

    foreach ($records as $record) {
        if ($pdo !== null) {
            $record['notification_type'] = 'printed';
            $record['status_signature'] = auto_mail_status_signature($record, 'printed');

            if (! auto_mail_should_send($pdo, $record)) {
                $result['already_sent']++;
                continue;
            }
        }

        if ($limit !== null && $attempted >= $limit) {
            $result['remaining']++;
            continue;
        }

        $attempted++;
        $email = trim((string) ($record['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            if ($pdo !== null) {
                auto_mail_record_attempt($pdo, $record, false, 'Invalid or missing email address.');
            }
            $result['skipped']++;
            continue;
        }

        $subject = 'Printed ID Card Ready for Collection';
        $body = collection_mail_body($record, $generalMessage);

        try {
            smtp_send_mail($mailConfig, $email, (string) ($record['full_name'] ?: $email), $subject, $body);
            usleep(250000);
            if ($pdo !== null) {
                auto_mail_record_attempt($pdo, $record, true, null);
            }
            $result['sent']++;
        } catch (Throwable $exception) {
            if ($pdo !== null) {
                auto_mail_record_attempt($pdo, $record, false, $exception->getMessage());
            }
            $result['failed']++;
            $result['errors'][] = sprintf(
                '%s (%s): %s',
                (string) ($record['primary_identifier'] ?: 'No identifier'),
                $email,
                $exception->getMessage()
            );
        }
    }

    return $result;
}

function rejected_mail_body(array $record): string
{
    $name = trim((string) ($record['full_name'] ?? ''));
    $identifier = trim((string) ($record['primary_identifier'] ?? ''));
    $type = ucfirst((string) ($record['record_type'] ?? 'record'));
    $department = trim((string) ($record['department'] ?? ''));
    $reason = trim((string) ($record['card_request_rejection_reason'] ?? ''));

    if ($reason === '') {
        $reason = 'Your submitted ID card details require correction before approval.';
    }

    $displayName = $name !== '' ? $name : 'Applicant';
    $displayIdentifier = $identifier !== '' ? $identifier : 'N/A';
    $displayDepartment = $department !== '' ? $department : 'N/A';

    return '<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rejected ID Card Application</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#101828;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;margin:0;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.10);">
                    <tr>
                        <td style="background:#101828;padding:26px 30px;color:#ffffff;">
                            <div style="display:inline-block;background:#dc2626;color:#ffffff;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Action Required</div>
                            <h1 style="margin:16px 0 0;font-size:24px;line-height:1.25;font-weight:800;">ID Card Application Rejected</h1>
                            <p style="margin:8px 0 0;color:#d0d5dd;font-size:14px;line-height:1.6;">Joseph Sarwuan Tarka University, Makurdi</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.7;">Dear <strong>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                            <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#475467;">Your ID card application was reviewed and rejected. Please check the details below, make the requested correction, and resubmit your application on the ID card portal.</p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f8fafc;border:1px solid #e4e7ec;border-radius:14px;overflow:hidden;">
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Record Type</td>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;font-size:14px;font-weight:700;color:#101828;">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Identifier</td>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;font-size:14px;font-weight:700;color:#101828;">' . htmlspecialchars($displayIdentifier, ENT_QUOTES, 'UTF-8') . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Department</td>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;font-size:14px;font-weight:700;color:#101828;">' . htmlspecialchars($displayDepartment, ENT_QUOTES, 'UTF-8') . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;vertical-align:top;">Reason</td>
                                    <td style="padding:14px 16px;font-size:14px;line-height:1.6;color:#101828;">' . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . '</td>
                                </tr>
                            </table>

                            <div style="margin-top:24px;padding:16px 18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;color:#9a3412;font-size:14px;line-height:1.6;">
                                Please correct the rejected item carefully before resubmitting, so your ID card can be approved without another delay.
                            </div>

                            <p style="margin:26px 0 0;font-size:15px;line-height:1.7;color:#475467;">Regards,<br><strong style="color:#101828;">JoSTUM ID Card Records Team</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 30px;background:#f8fafc;border-top:1px solid #e4e7ec;color:#667085;font-size:12px;line-height:1.6;text-align:center;">
                            This message was sent because your ID card application status is currently rejected.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

function collection_mail_body(array $record, string $generalMessage): string
{
    $name = trim((string) ($record['full_name'] ?? ''));
    $identifier = trim((string) ($record['primary_identifier'] ?? ''));
    $type = ucfirst((string) ($record['record_type'] ?? 'record'));
    $department = trim((string) ($record['department'] ?? ''));
    $issuedAt = trim((string) ($record['card_issued_at'] ?? ''));
    $displayName = $name !== '' ? $name : 'Applicant';
    $displayIdentifier = $identifier !== '' ? $identifier : 'N/A';
    $displayDepartment = $department !== '' ? $department : 'N/A';
    $displayIssuedAt = $issuedAt !== '' ? $issuedAt : 'N/A';

    return '<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Printed ID Card Ready for Collection</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#101828;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;margin:0;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.10);">
                    <tr>
                        <td style="background:#064e3b;padding:26px 30px;color:#ffffff;">
                            <div style="display:inline-block;background:#16a34a;color:#ffffff;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Ready for Collection</div>
                            <h1 style="margin:16px 0 0;font-size:24px;line-height:1.25;font-weight:800;">Your Printed ID Card Is Ready</h1>
                            <p style="margin:8px 0 0;color:#d1fae5;font-size:14px;line-height:1.6;">Joseph Sarwuan Tarka University, Makurdi</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.7;">Dear <strong>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                            <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#475467;">Your ID card application has been approved and the card has been printed.</p>

                            <div style="margin:0 0 24px;padding:18px 20px;background:#ecfdf3;border:1px solid #bbf7d0;border-radius:14px;color:#166534;font-size:15px;line-height:1.7;">
                                ' . nl2br(htmlspecialchars($generalMessage, ENT_QUOTES, 'UTF-8')) . '
                            </div>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;background:#f8fafc;border:1px solid #e4e7ec;border-radius:14px;overflow:hidden;">
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Record Type</td>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;font-size:14px;font-weight:700;color:#101828;">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Identifier</td>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;font-size:14px;font-weight:700;color:#101828;">' . htmlspecialchars($displayIdentifier, ENT_QUOTES, 'UTF-8') . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Department</td>
                                    <td style="padding:14px 16px;border-bottom:1px solid #e4e7ec;font-size:14px;font-weight:700;color:#101828;">' . htmlspecialchars($displayDepartment, ENT_QUOTES, 'UTF-8') . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;color:#667085;font-size:12px;text-transform:uppercase;font-weight:800;letter-spacing:.04em;">Issued At</td>
                                    <td style="padding:14px 16px;font-size:14px;font-weight:700;color:#101828;">' . htmlspecialchars($displayIssuedAt, ENT_QUOTES, 'UTF-8') . '</td>
                                </tr>
                            </table>

                            <p style="margin:26px 0 0;font-size:15px;line-height:1.7;color:#475467;">Regards,<br><strong style="color:#101828;">JoSTUM ID Card Records Team</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 30px;background:#f8fafc;border-top:1px solid #e4e7ec;color:#667085;font-size:12px;line-height:1.6;text-align:center;">
                            This message was sent because your ID card is marked approved and printed.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

function smtp_send_mail(array $config, string $toEmail, string $toName, string $subject, string $body): void
{
    $session = smtp_open_session($config);

    try {
        smtp_send_mail_with_session($session, $toEmail, $toName, $subject, $body);
    } finally {
        smtp_close_session($session);
    }
}

function smtp_open_session(array $config): array
{
    $host = (string) ($config['host'] ?? '');
    $port = (int) ($config['port'] ?? 587);
    $username = (string) ($config['username'] ?? '');
    $password = (string) ($config['password'] ?? '');
    $fromEmail = (string) ($config['from_email'] ?? $username);
    $fromName = (string) ($config['from_name'] ?? $fromEmail);
    $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
        throw new RuntimeException('Mail configuration is incomplete.');
    }

    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 20);

    if (! is_resource($socket)) {
        throw new RuntimeException('Could not connect to SMTP server: ' . $errstr);
    }

    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . smtp_local_hostname(), [250]);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);

            if (! stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable TLS encryption.');
            }

            smtp_command($socket, 'EHLO ' . smtp_local_hostname(), [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);

        return [
            'socket' => $socket,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
    } catch (Throwable $exception) {
        fclose($socket);
        throw $exception;
    }
}

function smtp_send_mail_with_session(array $session, string $toEmail, string $toName, string $subject, string $body): void
{
    $socket = $session['socket'];
    $fromEmail = (string) $session['from_email'];
    $fromName = (string) $session['from_name'];

    smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
    smtp_command($socket, 'DATA', [354]);

    $headers = [
        'From: ' . smtp_format_address($fromEmail, $fromName),
        'To: ' . smtp_format_address($toEmail, $toName),
        'Subject: ' . smtp_header_encode($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Date: ' . date(DATE_RFC2822),
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.";
    smtp_command($socket, $message, [250]);
    smtp_command($socket, 'RSET', [250]);
}

function smtp_close_session(array $session): void
{
    $socket = $session['socket'] ?? null;

    if (is_resource($socket)) {
        try {
            smtp_command($socket, 'QUIT', [221]);
        } catch (Throwable) {
        }

        fclose($socket);
    }
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");

    return smtp_expect($socket, $expectedCodes);
}

function smtp_expect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);

    if (! in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }

    return $response;
}

function smtp_header_encode(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_format_address(string $email, string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return '<' . $email . '>';
    }

    return smtp_header_encode($name) . ' <' . $email . '>';
}

function smtp_local_hostname(): string
{
    $host = gethostname();

    return is_string($host) && $host !== '' ? $host : 'localhost';
}

function render_view(string $template, array $data = []): string
{
    extract($data, EXTR_SKIP);

    ob_start();
    require dirname(__DIR__) . '/templates/' . $template . '.php';
    return (string) ob_get_clean();
}

function ensure_writable_directory(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function configure_dompdf_options(): Dompdf\Options
{
    $storagePath = dirname(__DIR__) . '/storage/dompdf';
    $tempPath = $storagePath . '/temp';
    $fontPath = $storagePath . '/fonts';

    ensure_writable_directory($tempPath);
    ensure_writable_directory($fontPath);

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isFontSubsettingEnabled', false);
    $options->set('tempDir', $tempPath);
    $options->set('fontDir', $fontPath);
    $options->set('fontCache', $fontPath);
    $options->set('chroot', dirname(__DIR__));
    $options->setDefaultFont('Helvetica');

    return $options;
}

function log_pdf_error($error): void
{
    $message = $error instanceof Throwable
        ? $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine()
        : $error;

    $logPath = dirname(__DIR__) . '/storage/pdf-error.log';
    @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}
