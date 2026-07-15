<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$resetUrl = $config['app']['portal_reset_url'] ?? 'https://jostumservices.com/forgot-password';
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    if ($action === 'logout') {
        auth_logout();
        header('Location: index.php');
        exit;
    }

    if ($action === 'login') {
        $pdo = database_connect($config['db']);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $loginError = 'Enter your email and password.';
        } else {
            $user = auth_login($pdo, $email, $password);

            if ($user !== null) {
                header('Location: index.php');
                exit;
            }

            $loginError = 'Invalid login details or account access is restricted.';
        }
    }
}

if (! auth_check()) {
    echo render_view('login', [
        'config' => $config,
        'loginError' => $loginError,
        'resetUrl' => $resetUrl,
    ]);
    exit;
}

$filters = report_filters_from_request($_GET);
$pdo = database_connect($config['db']);
$action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';
$autoMailSummary = null;
auto_mail_ensure_table($pdo);
record_printing_date_ensure_columns($pdo);
record_printing_date_capture($pdo);

if ($action === 'pdf') {
    ini_set('memory_limit', '2048M');
    set_time_limit(900);

    register_shutdown_function(static function (): void {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log_pdf_error($error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        }
    });

    $vendor = dirname(__DIR__) . '/vendor/autoload.php';

    if (! file_exists($vendor)) {
        http_response_code(500);
        echo 'DOMPDF is not installed yet. Run composer install first.';
        exit;
    }

    require $vendor;

    $pdfRowLimit = max(1, (int) ($config['pdf']['max_records'] ?? 5000));
    $pdfRecords = report_fetch_records($pdo, $filters, $pdfRowLimit + 1);
    $hasMoreRows = count($pdfRecords) > $pdfRowLimit;

    if ($hasMoreRows) {
        $pdfRecords = array_slice($pdfRecords, 0, $pdfRowLimit);
    }

    $summary = report_summarize($pdfRecords);
    $pdfNotice = $hasMoreRows
        ? sprintf(
            'This PDF contains the first %d matching rows to prevent PDF memory failure on shared hosting. Use the department, status, or search filters to print a smaller complete set.',
            $pdfRowLimit
        )
        : null;

    try {
        $dompdf = new Dompdf\Dompdf(configure_dompdf_options());
        $html = render_view('pdf-report', [
            'config' => $config,
            'filters' => $filters,
            'records' => $pdfRecords,
            'summary' => $summary,
            'pdfNotice' => $pdfNotice,
            'generatedAt' => new DateTimeImmutable('now'),
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = sprintf(
            'jostum-id-report-%s-%s-%s.pdf',
            $filters['type'],
            $filters['status'],
            $filters['status_2'] ?? 'all'
        );

        $dompdf->stream($filename, [
            'Attachment' => ($_GET['download'] ?? '0') === '1',
        ]);
    } catch (Throwable $exception) {
        log_pdf_error($exception);
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PDF generation failed. Check storage/pdf-error.log on the server. Error: ' . $exception->getMessage();
    }
    exit;
}

if ($action === 'mail-status') {
    $mailRequest = $_GET;
    $batchKey = trim((string) ($mailRequest['batch_key'] ?? ($_SESSION['current_mail_batch_key'] ?? '')));
    $mailMode = mail_mode_from_request($mailRequest);
    $notificationType = $mailMode === 'printed' ? 'printed' : 'rejected';
    $mailFilters = mail_filters_from_request($mailRequest);
    $mailRecords = report_fetch_records($pdo, $mailFilters);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'pendingMailCount' => mail_delivery_pending_count($pdo, $mailRecords, $notificationType),
        'queueStats' => mail_queue_stats($pdo, $batchKey !== '' ? $batchKey : null),
        'targetStats' => mail_queue_target_stats($pdo, $batchKey !== '' ? $batchKey : null),
        'workerControl' => mail_worker_reconcile_control($pdo, $config['mail'] ?? []),
        'batchKey' => $batchKey,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'mail-worker-control') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['type' => 'danger', 'text' => 'Invalid request method.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $postAction = strtolower(trim((string) ($_POST['mail_action'] ?? '')));
    $workerTarget = strtolower(trim((string) ($_POST['worker_target'] ?? 'student')));
    $batchKey = trim((string) ($_POST['batch_key'] ?? ($_SESSION['current_mail_batch_key'] ?? '')));
    $workerBatchSize = (int) ($_POST['worker_batch_size'] ?? ($config['mail']['batch_size'] ?? 4));
    $workerIntervalSeconds = (int) ($_POST['worker_interval_seconds'] ?? ($config['mail']['batch_interval_seconds'] ?? 120));
    $message = match ($postAction) {
        'start_background_sender' => mail_worker_start($pdo, $config, $workerTarget, $workerBatchSize, $workerIntervalSeconds, $batchKey !== '' ? $batchKey : null),
        'stop_background_sender' => mail_worker_stop($pdo),
        default => ['type' => 'warning', 'text' => 'Unknown sender action.'],
    };
    $mailMode = mail_mode_from_request($_POST);
    $notificationType = $mailMode === 'printed' ? 'printed' : 'rejected';
    $mailFilters = mail_filters_from_request($_POST);
    $mailRecords = report_fetch_records($pdo, $mailFilters);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'message' => $message,
        'pendingMailCount' => mail_delivery_pending_count($pdo, $mailRecords, $notificationType),
        'queueStats' => mail_queue_stats($pdo, $batchKey !== '' ? $batchKey : null),
        'targetStats' => mail_queue_target_stats($pdo, $batchKey !== '' ? $batchKey : null),
        'workerControl' => mail_worker_reconcile_control($pdo, $config['mail'] ?? []),
        'batchKey' => $batchKey,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'mail-worker-tick') {
    $batchKey = trim((string) ($_POST['batch_key'] ?? $_GET['batch_key'] ?? ($_SESSION['current_mail_batch_key'] ?? '')));
    $tick = mail_worker_browser_tick($pdo, $config['mail'] ?? []);
    $mailMode = mail_mode_from_request($_POST + $_GET);
    $notificationType = $mailMode === 'printed' ? 'printed' : 'rejected';
    $mailFilters = mail_filters_from_request($_POST + $_GET);
    $mailRecords = report_fetch_records($pdo, $mailFilters);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'tick' => $tick,
        'pendingMailCount' => mail_delivery_pending_count($pdo, $mailRecords, $notificationType),
        'queueStats' => mail_queue_stats($pdo, $batchKey !== '' ? $batchKey : null),
        'targetStats' => mail_queue_target_stats($pdo, $batchKey !== '' ? $batchKey : null),
        'workerControl' => mail_worker_reconcile_control($pdo, $config['mail'] ?? []),
        'batchKey' => $batchKey,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'send-mail') {
    $mailRequest = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $mailMode = mail_mode_from_request($mailRequest);
    $collectionMessage = trim((string) ($mailRequest['collection_message'] ?? mail_default_collection_message()));
    $mailBatchSize = mail_batch_size($config['mail'] ?? []);
    $mailFilters = mail_filters_from_request($mailRequest);
    $mailRecords = report_fetch_records($pdo, $mailFilters);
    $mailPreviewPerPageOptions = [25, 50, 75, 100, 500];
    $mailPreviewPerPage = (int) ($mailRequest['preview_per_page'] ?? 25);
    $mailPreviewPerPage = in_array($mailPreviewPerPage, $mailPreviewPerPageOptions, true) ? $mailPreviewPerPage : 25;
    $mailPreviewPage = max(1, (int) ($mailRequest['preview_page'] ?? 1));
    $mailTotalRecords = count($mailRecords);
    $mailTotalPages = max(1, (int) ceil($mailTotalRecords / $mailPreviewPerPage));
    $mailPreviewPage = min($mailPreviewPage, $mailTotalPages);
    $mailPreviewOffset = ($mailPreviewPage - 1) * $mailPreviewPerPage;
    $mailDisplayRecords = array_slice($mailRecords, $mailPreviewOffset, $mailPreviewPerPage);
    $mailResult = null;
    $mailWorkerMessage = null;
    $notificationType = $mailMode === 'printed' ? 'printed' : 'rejected';
    $pendingBeforePost = mail_delivery_pending_count($pdo, $mailRecords, $notificationType);
    $currentMailBatchKey = trim((string) ($_SESSION['current_mail_batch_key'] ?? ''));
    $workerBatchSize = max(1, min(500, (int) ($mailRequest['worker_batch_size'] ?? ($config['mail']['batch_size'] ?? 4))));
    $workerIntervalSeconds = max(1, min(86400, (int) ($mailRequest['worker_interval_seconds'] ?? ($config['mail']['batch_interval_seconds'] ?? 120))));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = strtolower(trim((string) ($_POST['mail_action'] ?? '')));
        $workerTarget = strtolower(trim((string) ($_POST['worker_target'] ?? 'student')));
        $mailSendLimit = (int) ($_POST['mail_send_limit'] ?? 0);
        $mailSendLimit = $mailSendLimit > 0 ? min($mailSendLimit, max(1, $pendingBeforePost)) : null;

        if ($postAction === 'send_rejected_mail') {
            $mailResult = mail_enqueue_records($pdo, $mailRecords, 'rejected', '', $mailSendLimit);
            $currentMailBatchKey = (string) ($mailResult['batch_key'] ?? '');
            $_SESSION['current_mail_batch_key'] = $currentMailBatchKey;
        } elseif ($postAction === 'send_collection_mail') {
            $mailResult = mail_enqueue_records($pdo, $mailRecords, 'printed', $collectionMessage, $mailSendLimit);
            $currentMailBatchKey = (string) ($mailResult['batch_key'] ?? '');
            $_SESSION['current_mail_batch_key'] = $currentMailBatchKey;
        } elseif ($postAction === 'start_background_sender') {
            $mailWorkerMessage = mail_worker_start($pdo, $config, $workerTarget, $workerBatchSize, $workerIntervalSeconds, $currentMailBatchKey !== '' ? $currentMailBatchKey : null);
        } elseif ($postAction === 'stop_background_sender') {
            $mailWorkerMessage = mail_worker_stop($pdo);
        }
    }

    $pendingMailCount = mail_delivery_pending_count($pdo, $mailRecords, $notificationType);
    $mailDepartments = report_fetch_departments($pdo, $mailFilters['type']);
    $mailQueueStats = mail_queue_stats($pdo, $currentMailBatchKey !== '' ? $currentMailBatchKey : null);
    $mailQueueTargetStats = mail_queue_target_stats($pdo, $currentMailBatchKey !== '' ? $currentMailBatchKey : null);
    $mailWorkerControl = mail_worker_reconcile_control($pdo, $config['mail'] ?? []);

    echo render_view('send-mail', [
        'config' => $config,
        'filters' => $mailFilters,
        'records' => $mailRecords,
        'displayRecords' => $mailDisplayRecords,
        'departments' => $mailDepartments,
        'mailResult' => $mailResult,
        'mailMode' => $mailMode,
        'collectionMessage' => $collectionMessage,
        'mailBatchSize' => $mailBatchSize,
        'mailSendLimit' => (int) ($mailRequest['mail_send_limit'] ?? min(100, max(1, $pendingMailCount))),
        'workerBatchSize' => $workerBatchSize,
        'workerIntervalSeconds' => $workerIntervalSeconds,
        'currentMailBatchKey' => $currentMailBatchKey,
        'pendingMailCount' => $pendingMailCount,
        'mailQueueStats' => $mailQueueStats,
        'mailQueueTargetStats' => $mailQueueTargetStats,
        'mailWorkerControl' => $mailWorkerControl,
        'mailWorkerMessage' => $mailWorkerMessage,
        'previewPerPageOptions' => $mailPreviewPerPageOptions,
        'previewPerPage' => $mailPreviewPerPage,
        'previewPage' => $mailPreviewPage,
        'previewTotalPages' => $mailTotalPages,
        'previewTotalRecords' => $mailTotalRecords,
        'previewOffset' => $mailPreviewOffset,
        'autoMailSummary' => $autoMailSummary,
        'user' => auth_user(),
    ]);
    exit;
}

if ($action === 'mail-records') {
    $mailHistoryMessage = null;
    $mailHistoryRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? array_merge($_GET, $_POST)
        : $_GET;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mailAction = strtolower(trim((string) ($_POST['mail_action'] ?? '')));
        $queueId = (int) ($_POST['queue_id'] ?? 0);

        if ($mailAction === 'retry_failed_mail' && $queueId > 0) {
            $mailHistoryMessage = mail_retry_failed_job($pdo, $queueId)
                ? ['type' => 'success', 'text' => 'Failed email has been returned to the queue for resending.']
                : ['type' => 'warning', 'text' => 'That email could not be resent because it is no longer failed or was not found.'];
        }
    }

    $mailHistoryFilters = mail_history_filters_from_request($mailHistoryRequest);
    $mailHistoryRecords = mail_history_records($pdo, $mailHistoryFilters);
    $mailHistoryCounts = mail_history_counts($pdo);

    echo render_view('mail-records', [
        'config' => $config,
        'filters' => $mailHistoryFilters,
        'records' => $mailHistoryRecords,
        'counts' => $mailHistoryCounts,
        'message' => $mailHistoryMessage,
        'mailQueueStats' => mail_queue_stats($pdo),
        'user' => auth_user(),
    ]);
    exit;
}

$records = report_fetch_records($pdo, $filters);
$summary = report_summarize($records);
$departments = report_fetch_departments($pdo, $filters['type']);
$pdfRowLimit = max(1, (int) ($config['pdf']['max_records'] ?? 5000));
$allowedPerPage = [25, 50, 75, 100];
$perPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPage, $allowedPerPage, true) ? $perPage : 25;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalRecords = count($records);
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$currentPage = min($currentPage, $totalPages);
$pageOffset = ($currentPage - 1) * $perPage;
$displayRecords = array_slice($records, $pageOffset, $perPage);

echo render_view('dashboard', [
    'config' => $config,
    'filters' => $filters,
    'records' => $records,
    'displayRecords' => $displayRecords,
    'summary' => $summary,
    'departments' => $departments,
    'perPage' => $perPage,
    'currentPage' => $currentPage,
    'totalPages' => $totalPages,
    'totalRecords' => $totalRecords,
    'pageOffset' => $pageOffset,
    'pdfRowLimit' => $pdfRowLimit,
    'autoMailSummary' => $autoMailSummary,
    'mailQueueStats' => mail_queue_stats($pdo),
    'user' => auth_user(),
]);
