<?php
declare(strict_types=1);

$typeOptions = [
    'all' => 'Staff and Students',
    'student' => 'Students',
    'staff' => 'Staff',
];
$mailMode = $mailMode ?? 'rejected';
$mailBatchSize = (int) ($mailBatchSize ?? 10);
$pendingMailCount = (int) ($pendingMailCount ?? 0);
$collectionMessage = $collectionMessage ?? 'Disregard initial mails sent to collect ID cards from ICT South Core (If you received the previous notification).' . "\n\n" . 'Your printed ID card is ready for collection. Please visit the ID card collection point in your respective colleges.';
$isPrintedMode = $mailMode === 'printed';
$modeTitle = $isPrintedMode ? 'Send Printed ID Card Collection Emails' : 'Send Rejected ID Card Emails';
$modeDescription = $isPrintedMode
    ? 'Only approved records with printed ID cards can be emailed here.'
    : 'Status is fixed to rejected so only rejected applications can be emailed here.';
$statusBadgeClass = $isPrintedMode ? 'text-bg-success' : 'text-bg-danger';
$statusIcon = $isPrintedMode ? 'bi-check2-circle' : 'bi-x-circle';
$statusText = $isPrintedMode ? 'Printed and Approved' : 'Rejected';
$matchedText = $isPrintedMode ? 'printed and approved records matched' : 'rejected records matched';
$sendAction = $isPrintedMode ? 'send_collection_mail' : 'send_rejected_mail';
$sendButtonClass = $isPrintedMode ? 'btn-success' : 'btn-danger';
$sendHeading = $isPrintedMode ? 'Send to %s printed ID card recipients' : 'Send to %s rejected applicants';
$sendDescription = $isPrintedMode
    ? 'Each click sends the next small batch with the applicant name, identifier, department, and your collection instructions.'
    : 'Each click sends the next small batch with the applicant name, identifier, department, and rejection reason.';
$previewHeading = $isPrintedMode ? 'Printed records selected for email' : 'Rejected records selected for email';
$formatDateTime = static function ($value): string {
    $value = trim((string) $value);

    if ($value === '') {
        return 'N/A';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y g:i A');
    } catch (Throwable) {
        return $value;
    }
};
$matchedEmails = array_values(array_filter($records, static function (array $record): bool {
    $email = trim((string) ($record['email'] ?? ''));

    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}));
$mailQueueStats = $mailQueueStats ?? ['queued' => 0, 'pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0];
$mailQueueTargetStats = $mailQueueTargetStats ?? [
    'student' => ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0],
    'staff' => ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0],
];
$mailWorkerControl = $mailWorkerControl ?? ['status' => 'stopped', 'target_type' => 'student', 'heartbeat_at' => null, 'last_message' => 'Background sender is stopped.'];
$mailWorkerMessage = $mailWorkerMessage ?? null;
$displayRecords = $displayRecords ?? $records;
$previewPerPageOptions = $previewPerPageOptions ?? [25, 50, 75, 100, 500];
$previewPerPage = (int) ($previewPerPage ?? 25);
$previewPage = (int) ($previewPage ?? 1);
$previewTotalPages = (int) ($previewTotalPages ?? 1);
$previewTotalRecords = (int) ($previewTotalRecords ?? count($records));
$previewOffset = (int) ($previewOffset ?? 0);
$mailSendLimit = max(1, min($pendingMailCount > 0 ? $pendingMailCount : 1, (int) ($mailSendLimit ?? min(100, max(1, $pendingMailCount)))));
$workerBatchSize = max(1, min(500, (int) ($workerBatchSize ?? $mailBatchSize)));
$workerIntervalSeconds = max(1, min(86400, (int) ($workerIntervalSeconds ?? ($config['mail']['batch_interval_seconds'] ?? 120))));
$currentMailBatchKey = trim((string) ($currentMailBatchKey ?? ''));
$workerRunning = ($mailWorkerControl['status'] ?? '') === 'running';
$workerTarget = (string) ($mailWorkerControl['target_type'] ?? 'student');
$workerCommand = 'php bin/mail-worker.php';
$mailStatusUrl = 'index.php?' . http_build_query(array_filter([
    'action' => 'mail-status',
    'mail_mode' => $mailMode,
    'type' => $filters['type'],
    'status' => $mailMode === 'printed' ? 'printed_approved' : 'rejected',
    'department' => $filters['department'] ?? '',
    'search' => $filters['search'],
    'batch_key' => $currentMailBatchKey,
], static fn ($value): bool => $value !== ''));
$mailWorkerControlUrl = 'index.php?action=mail-worker-control';
$mailWorkerTickUrl = 'index.php?action=mail-worker-tick';
$previewPageLink = static function (int $page) use ($filters, $mailMode, $collectionMessage, $previewPerPage): string {
    return 'index.php?' . http_build_query(array_filter([
        'action' => 'send-mail',
        'mail_mode' => $mailMode,
        'type' => $filters['type'],
        'status' => $mailMode === 'printed' ? 'printed_approved' : 'rejected',
        'department' => $filters['department'] ?? '',
        'search' => $filters['search'],
        'collection_message' => $mailMode === 'printed' ? $collectionMessage : '',
        'preview_per_page' => (string) $previewPerPage,
        'preview_page' => (string) $page,
    ], static fn ($value): bool => $value !== ''));
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Send Mail - <?= htmlspecialchars($config['app']['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --app-bg: #f4f7fb;
            --sidebar-bg: #101828;
            --sidebar-muted: #98a2b3;
            --brand-blue: #2563eb;
            --brand-green: #16a34a;
            --panel-border: #e4e7ec;
        }

        body {
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, .12), transparent 30%),
                linear-gradient(180deg, #f8fbff 0%, var(--app-bg) 100%);
            color: #0f172a;
        }

        .app-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 282px minmax(0, 1fr);
        }

        .sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            background: var(--sidebar-bg);
            color: #fff;
            padding: 22px 18px;
            display: flex;
            flex-direction: column;
        }

        .brand-box {
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--brand-blue), var(--brand-green));
            font-weight: 800;
        }

        .sidebar .nav-link {
            color: #d0d5dd;
            border-radius: 12px;
            padding: 10px 12px;
            font-weight: 700;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: #fff;
            background: rgba(255, 255, 255, .12);
        }

        .sidebar-label {
            color: var(--sidebar-muted);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .sidebar-account {
            margin-top: auto;
        }

        .main-content {
            padding: 24px;
        }

        .top-header,
        .soft-card,
        .filter-card,
        .records-card {
            border: 1px solid rgba(228, 231, 236, .9);
            box-shadow: 0 18px 45px rgba(15, 23, 42, .07);
            backdrop-filter: blur(16px);
        }

        .top-header,
        .soft-card,
        .filter-card {
            background: rgba(255, 255, 255, .88);
        }

        .queue-tile {
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            background: #fff;
            padding: 18px;
            min-height: 118px;
        }

        .queue-tile strong {
            font-size: clamp(1.65rem, 4vw, 2.35rem);
            line-height: 1;
        }

        .sender-panel {
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            background: #fff;
            padding: 18px;
            min-height: 100%;
        }

        .sender-panel.active {
            border-color: var(--brand-blue);
            box-shadow: 0 14px 32px rgba(37, 99, 235, .14);
        }

        .app-tabs {
            display: inline-flex;
            gap: 6px;
            padding: 6px;
            border: 1px solid var(--panel-border);
            border-radius: 16px;
            background: #fff;
        }

        .app-tabs .btn {
            border-radius: 12px;
            border: 0;
        }

        .form-control,
        .form-select,
        .btn {
            border-radius: 12px;
        }

        .header-icon {
            width: 52px;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            color: #fff;
            background: linear-gradient(135deg, var(--brand-blue), var(--brand-green));
            font-size: 1.35rem;
        }

        .department-pill {
            background: #eef6ff;
            color: #175cd3;
        }

        .table th {
            white-space: nowrap;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        @media (max-width: 991.98px) {
            .app-shell {
                display: block;
            }

            .sidebar {
                position: static;
                height: auto;
                border-radius: 0 0 24px 24px;
            }

            .main-content {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="brand-box">JS</div>
            <div>
                <p class="sidebar-label mb-1">JoSTUM</p>
                <h1 class="h5 mb-0">Report Centre</h1>
            </div>
        </div>

        <div class="mb-4">
            <p class="sidebar-label mb-2">Record Types</p>
            <nav class="nav flex-column gap-1">
                <a class="nav-link" href="index.php?type=all"><i class="bi bi-collection me-2"></i>Staff and Students</a>
                <a class="nav-link" href="index.php?type=student"><i class="bi bi-mortarboard me-2"></i>Students</a>
                <a class="nav-link" href="index.php?type=staff"><i class="bi bi-person-badge me-2"></i>Staff</a>
            </nav>
        </div>

        <div class="mb-4">
            <p class="sidebar-label mb-2">Tools</p>
            <nav class="nav flex-column gap-1">
                <a class="nav-link active" href="index.php?action=send-mail">
                    <i class="bi bi-envelope-paper me-2"></i>Send Mail
                </a>
                <a class="nav-link" href="index.php?action=mail-records">
                    <i class="bi bi-inboxes me-2"></i>Mail Records
                </a>
                <a class="nav-link" href="index.php?action=mail-records&mail_type=rejected&record_type=student&mail_status=sent">
                    <i class="bi bi-x-circle me-2"></i>Rejected Students
                </a>
                <a class="nav-link" href="index.php?action=mail-records&mail_type=rejected&record_type=staff&mail_status=sent">
                    <i class="bi bi-x-circle me-2"></i>Rejected Staff
                </a>
                <a class="nav-link" href="index.php?action=mail-records&mail_type=printed&record_type=student&mail_status=sent">
                    <i class="bi bi-check2-circle me-2"></i>Printed Students
                </a>
                <a class="nav-link" href="index.php?action=mail-records&mail_type=printed&record_type=staff&mail_status=sent">
                    <i class="bi bi-check2-circle me-2"></i>Printed Staff
                </a>
            </nav>
        </div>

        <div class="mb-4">
            <p class="sidebar-label mb-2">Background Sender</p>
            <nav class="nav flex-column gap-1">
                <a class="nav-link" href="#student-sender"><i class="bi bi-mortarboard me-2"></i>Students Sender</a>
                <a class="nav-link" href="#staff-sender"><i class="bi bi-person-badge me-2"></i>Staff Sender</a>
            </nav>
        </div>

        <div class="sidebar-account">
            <div class="rounded-4 p-3 mb-3" style="background: rgba(255,255,255,.09);">
                <p class="sidebar-label mb-1">Signed In</p>
                <p class="fw-bold mb-0 text-truncate"><?= htmlspecialchars((string) ($user['name'] ?? 'Admin')) ?></p>
                <p class="small mb-0 text-white-50 text-truncate"><?= htmlspecialchars((string) ($user['role'] ?? '')) ?></p>
            </div>

            <form method="post" class="d-grid">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</button>
            </form>
        </div>
    </aside>

    <main class="main-content">
        <header class="card top-header rounded-4 mb-4">
            <div class="card-body p-4 d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                <div class="d-flex align-items-start gap-3">
                    <span class="header-icon"><i class="bi bi-envelope-paper"></i></span>
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1"><?= htmlspecialchars($config['app']['university_name']) ?></p>
                        <h2 class="h3 fw-bold mb-1"><?= htmlspecialchars($modeTitle) ?></h2>
                        <p class="text-secondary mb-0"><?= htmlspecialchars($modeDescription) ?></p>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge rounded-pill <?= $statusBadgeClass ?> px-3 py-2"><i class="bi <?= $statusIcon ?> me-1"></i><?= htmlspecialchars($statusText) ?></span>
                    <span class="badge rounded-pill department-pill px-3 py-2">
                        <i class="bi bi-building me-1"></i><?= htmlspecialchars(($filters['department'] ?? '') !== '' ? $filters['department'] : 'All departments') ?>
                    </span>
                </div>
            </div>
        </header>

        <?php if (is_array($mailResult)): ?>
            <section class="alert <?= ($mailResult['skipped'] ?? 0) > 0 ? 'alert-warning' : 'alert-success' ?> rounded-4">
                <strong>Queue updated.</strong>
                Limit selected: <?= ($mailResult['batch_limit'] ?? null) === null ? 'All pending' : number_format((int) $mailResult['batch_limit']) ?>,
                Queued: <?= number_format((int) ($mailResult['queued'] ?? 0)) ?>,
                Already queued: <?= number_format((int) ($mailResult['already_queued'] ?? 0)) ?>,
                Already sent: <?= number_format((int) ($mailResult['already_sent'] ?? 0)) ?>,
                Skipped invalid emails: <?= number_format((int) ($mailResult['skipped'] ?? 0)) ?>,
                Current batch: <?= number_format((int) ($mailResult['remaining'] ?? 0)) ?> still waiting or processing.
                <?php if (($mailResult['errors'] ?? []) !== []): ?>
                    <hr>
                    <ul class="mb-0">
                        <?php foreach (array_slice($mailResult['errors'], 0, 8) as $error): ?>
                            <li><?= htmlspecialchars((string) $error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (is_array($mailWorkerMessage)): ?>
            <section class="alert alert-<?= htmlspecialchars((string) $mailWorkerMessage['type']) ?> rounded-4">
                <?= htmlspecialchars((string) $mailWorkerMessage['text']) ?>
            </section>
        <?php endif; ?>

        <section class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="queue-tile">
                    <span class="small text-uppercase fw-bold text-secondary">Queued In Batch</span>
                    <strong class="d-block mt-2" data-live-count="queue.queued"><?= number_format((int) ($mailQueueStats['queued'] ?? 0)) ?></strong>
                    <span class="text-secondary small">Waiting for worker</span>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="queue-tile">
                    <span class="small text-uppercase fw-bold text-secondary">Processing Batch</span>
                    <strong class="d-block mt-2" data-live-count="queue.processing"><?= number_format((int) ($mailQueueStats['processing'] ?? 0)) ?></strong>
                    <span class="text-secondary small">Locked by worker</span>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="queue-tile">
                    <span class="small text-uppercase fw-bold text-secondary">Sent From Batch</span>
                    <strong class="d-block mt-2 text-success" data-live-count="queue.sent"><?= number_format((int) ($mailQueueStats['sent'] ?? 0)) ?></strong>
                    <span class="text-secondary small">Delivered successfully</span>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="queue-tile">
                    <span class="small text-uppercase fw-bold text-secondary">Failed In Batch</span>
                    <strong class="d-block mt-2 text-danger" data-live-count="queue.failed"><?= number_format((int) ($mailQueueStats['failed'] ?? 0)) ?></strong>
                    <span class="text-secondary small">After retry limit</span>
                </div>
            </div>
        </section>

        <section class="card soft-card rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-3">
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">Background Sender</p>
                        <h3 class="h5 mb-1"><?= $workerRunning ? 'Running for ' . htmlspecialchars(ucfirst($workerTarget)) : 'Sender is stopped' ?></h3>
                        <p class="text-secondary mb-0">
                            Start the sender for Students or Staff. It keeps working in the background using the mail count and time interval you enter below.
                        </p>
                    </div>
                    <div class="text-xl-end">
                        <span class="badge rounded-pill <?= $workerRunning ? 'text-bg-success' : 'text-bg-secondary' ?> px-3 py-2" data-worker-status-badge>
                            <i class="bi <?= $workerRunning ? 'bi-play-circle' : 'bi-stop-circle' ?> me-1"></i><?= $workerRunning ? 'Running' : 'Stopped' ?>
                        </span>
                        <p class="small text-secondary mb-0 mt-2" data-worker-message><?= htmlspecialchars((string) ($mailWorkerControl['last_message'] ?? '')) ?></p>
                        <?php if (($mailWorkerControl['heartbeat_at'] ?? null) !== null): ?>
                            <p class="small text-secondary mb-0">Last heartbeat: <?= htmlspecialchars((string) $mailWorkerControl['heartbeat_at']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3">
                    <?php foreach (['student' => 'Students', 'staff' => 'Staff'] as $targetValue => $targetLabel): ?>
                        <?php $targetStats = $mailQueueTargetStats[$targetValue] ?? ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0]; ?>
                        <div class="col-12 col-xl-6" id="<?= htmlspecialchars($targetValue) ?>-sender">
                            <div class="sender-panel <?= $workerRunning && $workerTarget === $targetValue ? 'active' : '' ?>">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                                    <div>
                                        <p class="text-uppercase small fw-bold <?= $targetValue === 'student' ? 'text-success' : 'text-primary' ?> mb-1"><?= htmlspecialchars($targetLabel) ?> Sender</p>
                                        <h4 class="h5 mb-2"><span data-live-count="target.<?= htmlspecialchars($targetValue) ?>.remaining"><?= number_format((int) $targetStats['remaining']) ?></span> waiting or processing</h4>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge rounded-pill text-bg-secondary">Queued <span data-live-count="target.<?= htmlspecialchars($targetValue) ?>.queued"><?= number_format((int) $targetStats['queued']) ?></span></span>
                                            <span class="badge rounded-pill text-bg-primary">Processing <span data-live-count="target.<?= htmlspecialchars($targetValue) ?>.processing"><?= number_format((int) $targetStats['processing']) ?></span></span>
                                            <span class="badge rounded-pill text-bg-success">Sent <span data-live-count="target.<?= htmlspecialchars($targetValue) ?>.sent"><?= number_format((int) $targetStats['sent']) ?></span></span>
                                            <span class="badge rounded-pill text-bg-danger">Failed <span data-live-count="target.<?= htmlspecialchars($targetValue) ?>.failed"><?= number_format((int) $targetStats['failed']) ?></span></span>
                                        </div>
                                    </div>
                                    <div class="d-grid gap-2 align-self-lg-center">
                                        <div class="row g-2">
                                            <label class="col-6 form-label mb-0">
                                                <span class="small fw-bold text-uppercase">Mails</span>
                                                <input class="form-control mt-1" type="number" min="1" max="500" name="worker_batch_size" value="<?= $workerBatchSize ?>" data-worker-batch-size>
                                            </label>
                                            <label class="col-6 form-label mb-0">
                                                <span class="small fw-bold text-uppercase">Seconds</span>
                                                <input class="form-control mt-1" type="number" min="1" max="86400" name="worker_interval_seconds" value="<?= $workerIntervalSeconds ?>" data-worker-interval-seconds>
                                            </label>
                                        </div>
                                        <form method="post" class="d-grid" data-worker-form>
                                            <input type="hidden" name="action" value="mail-worker-control">
                                            <input type="hidden" name="mail_action" value="start_background_sender">
                                            <input type="hidden" name="worker_target" value="<?= htmlspecialchars($targetValue) ?>">
                                            <input type="hidden" name="batch_key" value="<?= htmlspecialchars($currentMailBatchKey) ?>" data-current-batch-key>
                                            <input type="hidden" name="worker_batch_size" value="<?= $workerBatchSize ?>" data-worker-batch-size-hidden>
                                            <input type="hidden" name="worker_interval_seconds" value="<?= $workerIntervalSeconds ?>" data-worker-interval-seconds-hidden>
                                            <input type="hidden" name="mail_mode" value="<?= htmlspecialchars((string) $mailMode) ?>">
                                            <input type="hidden" name="type" value="<?= htmlspecialchars($filters['type']) ?>">
                                            <input type="hidden" name="status" value="<?= $isPrintedMode ? 'printed_approved' : 'rejected' ?>">
                                            <input type="hidden" name="department" value="<?= htmlspecialchars((string) ($filters['department'] ?? '')) ?>">
                                            <input type="hidden" name="search" value="<?= htmlspecialchars($filters['search']) ?>">
                                            <input type="hidden" name="preview_per_page" value="<?= (int) $previewPerPage ?>">
                                            <input type="hidden" name="preview_page" value="<?= (int) $previewPage ?>">
                                            <?php if ($isPrintedMode): ?>
                                                <input type="hidden" name="collection_message" value="<?= htmlspecialchars((string) $collectionMessage) ?>">
                                            <?php endif; ?>
                                            <button class="btn btn-success" type="submit" data-worker-start="<?= htmlspecialchars($targetValue) ?>" <?= $workerRunning ? 'disabled' : '' ?>>
                                                <i class="bi bi-play-fill me-2"></i>Initiate
                                            </button>
                                        </form>
                                        <form method="post" class="d-grid" data-worker-form>
                                            <input type="hidden" name="action" value="mail-worker-control">
                                            <input type="hidden" name="mail_action" value="stop_background_sender">
                                            <input type="hidden" name="worker_target" value="<?= htmlspecialchars($targetValue) ?>">
                                            <input type="hidden" name="batch_key" value="<?= htmlspecialchars($currentMailBatchKey) ?>" data-current-batch-key>
                                            <input type="hidden" name="worker_batch_size" value="<?= $workerBatchSize ?>" data-worker-batch-size-hidden>
                                            <input type="hidden" name="worker_interval_seconds" value="<?= $workerIntervalSeconds ?>" data-worker-interval-seconds-hidden>
                                            <input type="hidden" name="mail_mode" value="<?= htmlspecialchars((string) $mailMode) ?>">
                                            <input type="hidden" name="type" value="<?= htmlspecialchars($filters['type']) ?>">
                                            <input type="hidden" name="status" value="<?= $isPrintedMode ? 'printed_approved' : 'rejected' ?>">
                                            <input type="hidden" name="department" value="<?= htmlspecialchars((string) ($filters['department'] ?? '')) ?>">
                                            <input type="hidden" name="search" value="<?= htmlspecialchars($filters['search']) ?>">
                                            <input type="hidden" name="preview_per_page" value="<?= (int) $previewPerPage ?>">
                                            <input type="hidden" name="preview_page" value="<?= (int) $previewPage ?>">
                                            <?php if ($isPrintedMode): ?>
                                                <input type="hidden" name="collection_message" value="<?= htmlspecialchars((string) $collectionMessage) ?>">
                                            <?php endif; ?>
                                            <button class="btn btn-outline-danger" type="submit" data-worker-stop="<?= htmlspecialchars($targetValue) ?>" <?= ! ($workerRunning && $workerTarget === $targetValue) ? 'disabled' : '' ?>>
                                                <i class="bi bi-stop-fill me-2"></i>Terminate
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card filter-card rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">Email Filters</p>
                        <h3 class="h5 mb-0"><?= number_format(count($records)) ?> <?= htmlspecialchars($matchedText) ?></h3>
                    </div>
                    <div class="app-tabs align-self-start">
                        <span class="btn btn-sm btn-light"><?= number_format(count($matchedEmails)) ?> valid emails</span>
                        <span class="btn btn-sm btn-primary"><span data-live-count="pending"><?= number_format($pendingMailCount) ?></span> unsent</span>
                    </div>
                </div>

                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="send-mail">
                    <input type="hidden" name="status" value="<?= $isPrintedMode ? 'printed_approved' : 'rejected' ?>">
                    <input type="hidden" name="preview_page" value="1">

                    <label class="col-12 col-md-6 col-xl-3 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Message Type</span>
                        <select class="form-select mt-1" name="mail_mode">
                            <option value="rejected" <?= ! $isPrintedMode ? 'selected' : '' ?>>Rejected ID Card</option>
                            <option value="printed" <?= $isPrintedMode ? 'selected' : '' ?>>Printed ID Card</option>
                        </select>
                    </label>

                    <label class="col-12 col-md-6 col-xl-2 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Record Type</span>
                        <select class="form-select mt-1" name="type">
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="col-12 col-md-6 col-xl-2 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Status</span>
                        <select class="form-select mt-1" disabled>
                            <option><?= htmlspecialchars($statusText) ?></option>
                        </select>
                    </label>

                    <label class="col-12 col-md-6 col-xl-2 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Department</span>
                        <select class="form-select mt-1" name="department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= htmlspecialchars($department) ?>" <?= ($filters['department'] ?? '') === $department ? 'selected' : '' ?>><?= htmlspecialchars($department) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="col-12 col-md-6 col-xl-2 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Search</span>
                        <input class="form-control mt-1" name="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="Matric no or PF no">
                    </label>

                    <label class="col-12 col-md-6 col-xl-1 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Show</span>
                        <select class="form-select mt-1" name="preview_per_page">
                            <?php foreach ($previewPerPageOptions as $option): ?>
                                <option value="<?= (int) $option ?>" <?= $previewPerPage === (int) $option ? 'selected' : '' ?>><?= number_format((int) $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ($isPrintedMode): ?>
                        <label class="col-12 form-label mb-0">
                            <span class="small fw-bold text-uppercase">Collection Message</span>
                            <textarea class="form-control mt-1" name="collection_message" rows="3" placeholder="Tell staff and students where and when to collect their printed ID cards."><?= htmlspecialchars((string) $collectionMessage) ?></textarea>
                        </label>
                    <?php endif; ?>

                    <div class="col-12 col-xl-1 d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
                        <a class="btn btn-outline-secondary" href="index.php?action=send-mail">Reset</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="card soft-card rounded-4 mb-4">
            <div class="card-body p-4 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <p class="text-uppercase small fw-bold <?= $isPrintedMode ? 'text-success' : 'text-danger' ?> mb-1">Send Email</p>
                    <h3 class="h5 mb-1"><?= sprintf(htmlspecialchars($sendHeading), '<span data-live-count="pending">' . number_format($pendingMailCount) . '</span>') ?></h3>
                    <p class="text-secondary mb-0">
                        <?= htmlspecialchars($sendDescription) ?>
                        This button adds recipients to the background queue. The worker sends <?= number_format($mailBatchSize) ?> emails every <?= number_format((int) ($config['mail']['batch_interval_seconds'] ?? 120)) ?> seconds with retry protection.
                        <?php if ($pendingMailCount === 0): ?>
                            All matching recipients for this filter have already been sent successfully.
                        <?php endif; ?>
                    </p>
                    <p class="small text-secondary mb-0 mt-2">Worker command: <code><?= htmlspecialchars($workerCommand) ?></code></p>
                </div>
                <form method="post" class="d-grid gap-2" style="min-width: min(100%, 260px);">
                    <input type="hidden" name="action" value="send-mail">
                    <input type="hidden" name="mail_action" value="<?= htmlspecialchars($sendAction) ?>">
                    <input type="hidden" name="mail_mode" value="<?= htmlspecialchars((string) $mailMode) ?>">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($filters['type']) ?>">
                    <input type="hidden" name="status" value="<?= $isPrintedMode ? 'printed_approved' : 'rejected' ?>">
                    <input type="hidden" name="department" value="<?= htmlspecialchars((string) ($filters['department'] ?? '')) ?>">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($filters['search']) ?>">
                    <input type="hidden" name="preview_per_page" value="<?= (int) $previewPerPage ?>">
                    <input type="hidden" name="preview_page" value="<?= (int) $previewPage ?>">
                    <?php if ($isPrintedMode): ?>
                        <input type="hidden" name="collection_message" value="<?= htmlspecialchars((string) $collectionMessage) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="batch_key" value="<?= htmlspecialchars($currentMailBatchKey) ?>" data-current-batch-key>
                    <label class="form-label mb-0">
                        <span class="small fw-bold text-uppercase">Emails to Queue</span>
                        <input
                            class="form-control mt-1"
                            type="number"
                            name="mail_send_limit"
                            min="1"
                            max="<?= max(1, $pendingMailCount) ?>"
                            value="<?= $mailSendLimit ?>"
                            data-send-limit-input
                            <?= $pendingMailCount === 0 ? 'disabled' : '' ?>
                        >
                    </label>
                    <div class="row g-2">
                        <label class="col-6 form-label mb-0">
                            <span class="small fw-bold text-uppercase">Mails Per Run</span>
                            <input class="form-control mt-1" type="number" name="worker_batch_size" min="1" max="500" value="<?= $workerBatchSize ?>" data-worker-batch-size>
                        </label>
                        <label class="col-6 form-label mb-0">
                            <span class="small fw-bold text-uppercase">Every Seconds</span>
                            <input class="form-control mt-1" type="number" name="worker_interval_seconds" min="1" max="86400" value="<?= $workerIntervalSeconds ?>" data-worker-interval-seconds>
                        </label>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Live mail progress" style="height: 10px;">
                        <div class="progress-bar <?= $isPrintedMode ? 'bg-success' : 'bg-danger' ?>" data-mail-progress style="width: 0%"></div>
                    </div>
                    <p class="small text-secondary mb-0" data-live-summary>
                        Live count starts when the sender is running.
                    </p>
                    <button class="btn <?= $sendButtonClass ?> btn-lg" type="submit" <?= $pendingMailCount === 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-send me-2"></i><?= $pendingMailCount === 0 ? 'All Sent' : 'Queue Recipients' ?>
                    </button>
                </form>
            </div>
        </section>

        <section class="card records-card rounded-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">Recipients Preview</p>
                        <h3 class="h5 mb-0"><?= htmlspecialchars($previewHeading) ?></h3>
                        <p class="text-secondary mb-0">
                            Showing <?= number_format($previewTotalRecords === 0 ? 0 : $previewOffset + 1) ?>-<?= number_format(min($previewOffset + $previewPerPage, $previewTotalRecords)) ?>
                            of <?= number_format($previewTotalRecords) ?> matched records.
                        </p>
                    </div>
                    <form method="get" class="d-flex flex-wrap align-items-end gap-2">
                        <input type="hidden" name="action" value="send-mail">
                        <input type="hidden" name="mail_mode" value="<?= htmlspecialchars((string) $mailMode) ?>">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($filters['type']) ?>">
                        <input type="hidden" name="status" value="<?= $isPrintedMode ? 'printed_approved' : 'rejected' ?>">
                        <input type="hidden" name="department" value="<?= htmlspecialchars((string) ($filters['department'] ?? '')) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($filters['search']) ?>">
                        <input type="hidden" name="preview_page" value="1">
                        <?php if ($isPrintedMode): ?>
                            <input type="hidden" name="collection_message" value="<?= htmlspecialchars((string) $collectionMessage) ?>">
                        <?php endif; ?>
                        <label class="form-label mb-0">
                            <span class="small fw-bold text-uppercase">Show</span>
                            <select class="form-select mt-1" name="preview_per_page" onchange="this.form.submit()">
                                <?php foreach ($previewPerPageOptions as $option): ?>
                                    <option value="<?= (int) $option ?>" <?= $previewPerPage === (int) $option ? 'selected' : '' ?>><?= number_format((int) $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </form>
                </div>

                <?php if ($records === []): ?>
                    <div class="alert alert-info mb-0">No <?= htmlspecialchars($isPrintedMode ? 'printed and approved' : 'rejected') ?> records matched this filter.</div>
                <?php else: ?>
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                        <span class="text-secondary small">Viewing <?= number_format($previewPerPage) ?> records per page.</span>
                        <nav aria-label="Recipients preview pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $previewPage <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($previewPageLink(max(1, $previewPage - 1))) ?>">Previous</a>
                                </li>
                                <?php
                                    $startPage = max(1, $previewPage - 2);
                                    $endPage = min($previewTotalPages, $previewPage + 2);
                                ?>
                                <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                                    <li class="page-item <?= $previewPage === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars($previewPageLink($page)) ?>"><?= number_format($page) ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $previewPage >= $previewTotalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($previewPageLink(min($previewTotalPages, $previewPage + 1))) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Identifier</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th><?= $isPrintedMode ? 'Printed At' : 'Reason' ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($displayRecords as $record): ?>
                                <tr>
                                    <td><span class="badge <?= $record['record_type'] === 'staff' ? 'text-bg-primary' : 'text-bg-success' ?>"><?= htmlspecialchars(ucfirst((string) $record['record_type'])) ?></span></td>
                                    <td class="fw-semibold"><?= htmlspecialchars((string) ($record['primary_identifier'] ?: 'N/A')) ?></td>
                                    <td><?= htmlspecialchars((string) ($record['full_name'] ?: 'N/A')) ?></td>
                                    <td><?= htmlspecialchars((string) ($record['email'] ?: 'N/A')) ?></td>
                                    <td><?= htmlspecialchars((string) ($record['department'] ?: 'N/A')) ?></td>
                                    <td class="text-secondary">
                                        <?= $isPrintedMode ? htmlspecialchars($formatDateTime($record['card_printed_at'] ?? '')) : htmlspecialchars((string) ($record['card_request_rejection_reason'] ?: 'N/A')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script>
(() => {
    const statusUrl = <?= json_encode($mailStatusUrl, JSON_THROW_ON_ERROR) ?>;
    const workerControlUrl = <?= json_encode($mailWorkerControlUrl, JSON_THROW_ON_ERROR) ?>;
    const workerTickUrl = <?= json_encode($mailWorkerTickUrl, JSON_THROW_ON_ERROR) ?>;
    const formatter = new Intl.NumberFormat();
    const progressBar = document.querySelector('[data-mail-progress]');
    const summary = document.querySelector('[data-live-summary]');
    const limitInput = document.querySelector('[data-send-limit-input]');
    const workerBadge = document.querySelector('[data-worker-status-badge]');
    const workerMessage = document.querySelector('[data-worker-message]');
    let currentBatchKey = <?= json_encode($currentMailBatchKey, JSON_THROW_ON_ERROR) ?>;
    let tickRunning = false;

    const getPath = (source, path) => path.split('.').reduce((value, key) => {
        if (value === undefined || value === null) {
            return undefined;
        }

        return value[key];
    }, source);

    const setCount = (key, value) => {
        document.querySelectorAll(`[data-live-count="${key}"]`).forEach((element) => {
            element.textContent = formatter.format(Number(value || 0));
        });
    };

    const syncWorkerSettings = () => {
        const batchSizeValue = document.querySelector('[data-worker-batch-size]')?.value || '<?= $workerBatchSize ?>';
        const intervalValue = document.querySelector('[data-worker-interval-seconds]')?.value || '<?= $workerIntervalSeconds ?>';

        document.querySelectorAll('[data-worker-batch-size-hidden]').forEach((input) => {
            input.value = batchSizeValue;
        });
        document.querySelectorAll('[data-worker-interval-seconds-hidden]').forEach((input) => {
            input.value = intervalValue;
        });
        document.querySelectorAll('[data-current-batch-key]').forEach((input) => {
            input.value = currentBatchKey;
        });
    };

    const updateWorkerUi = (workerControl = {}) => {
        const running = workerControl.status === 'running';
        const target = workerControl.target_type || '';

        if (workerBadge) {
            workerBadge.className = `badge rounded-pill ${running ? 'text-bg-success' : 'text-bg-secondary'} px-3 py-2`;
            workerBadge.innerHTML = `<i class="bi ${running ? 'bi-play-circle' : 'bi-stop-circle'} me-1"></i>${running ? 'Running' : 'Stopped'}`;
        }

        if (workerMessage && workerControl.last_message) {
            workerMessage.textContent = workerControl.last_message;
        }

        document.querySelectorAll('[data-worker-start]').forEach((button) => {
            button.disabled = running;
            button.innerHTML = '<i class="bi bi-play-fill me-2"></i>Initiate';
        });

        document.querySelectorAll('[data-worker-stop]').forEach((button) => {
            button.disabled = !(running && button.dataset.workerStop === target);
            button.innerHTML = '<i class="bi bi-stop-fill me-2"></i>Terminate';
        });
    };

    const setStartingUi = (target) => {
        document.querySelectorAll('[data-worker-start]').forEach((button) => {
            button.disabled = true;
            button.innerHTML = '<i class="bi bi-play-fill me-2"></i>Initiate';
        });

        document.querySelectorAll('[data-worker-stop]').forEach((button) => {
            button.disabled = button.dataset.workerStop !== target;
            button.innerHTML = '<i class="bi bi-stop-fill me-2"></i>Terminate';
        });

        if (workerBadge) {
            workerBadge.className = 'badge rounded-pill text-bg-success px-3 py-2';
            workerBadge.innerHTML = '<i class="bi bi-play-circle me-1"></i>Starting';
        }
    };

    const applyStatus = (data) => {
        const queueStats = data.queueStats || {};
        const targetStats = data.targetStats || {};
        const workerControl = data.workerControl || {};

        if (data.batchKey) {
            currentBatchKey = data.batchKey;
            syncWorkerSettings();
        }

        setCount('pending', data.pendingMailCount || 0);
        ['queued', 'processing', 'sent', 'failed'].forEach((status) => {
            setCount(`queue.${status}`, queueStats[status] || 0);
        });
        ['student', 'staff'].forEach((type) => {
            ['queued', 'processing', 'sent', 'failed', 'remaining'].forEach((status) => {
                setCount(`target.${type}.${status}`, getPath(targetStats, `${type}.${status}`) || 0);
            });
        });

        updateWorkerUi(workerControl);

        if (limitInput) {
            const pending = Math.max(0, Number(data.pendingMailCount || 0));
            limitInput.max = String(Math.max(1, pending));

            if (!limitInput.disabled && Number(limitInput.value || 0) > pending && pending > 0) {
                limitInput.value = String(pending);
            }
        }

        if (progressBar && summary) {
            const selectedLimit = Math.max(1, Number(limitInput?.value || 1));
            const sentCount = Math.max(0, Number(queueStats.sent || 0));
            const failedCount = Math.max(0, Number(queueStats.failed || 0));
            const completed = Math.min(selectedLimit, sentCount + failedCount);
            const percent = Math.min(100, Math.round((completed / selectedLimit) * 100));
            const runningText = workerControl.status === 'running'
                ? `Sender running for ${workerControl.target_type || 'records'} at ${formatter.format(workerControl.batch_size || 0)} mails every ${formatter.format(workerControl.interval_seconds || 0)} seconds`
                : 'Sender stopped';

            progressBar.style.width = `${percent}%`;
            summary.textContent = `${runningText}. Sent card will count ${formatter.format(sentCount)} now, then ${formatter.format(sentCount + 1)} after the next successful delivery. Queued ${formatter.format(queueStats.queued || 0)}, processing ${formatter.format(queueStats.processing || 0)}, failed ${formatter.format(failedCount)}.`;
        }
    };

    const refreshStatus = async () => {
        try {
            const response = await fetch(statusUrl, {
                headers: {Accept: 'application/json'},
                cache: 'no-store',
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            applyStatus(data);
        } catch (error) {
            if (summary) {
                summary.textContent = 'Live count is temporarily unavailable.';
            }
        }
    };

    const runBrowserTick = async () => {
        if (tickRunning) {
            return;
        }

        tickRunning = true;
        const formData = new FormData();
        formData.set('batch_key', currentBatchKey);
        formData.set('mail_mode', <?= json_encode((string) $mailMode, JSON_THROW_ON_ERROR) ?>);
        formData.set('type', <?= json_encode((string) $filters['type'], JSON_THROW_ON_ERROR) ?>);
        formData.set('status', <?= json_encode($isPrintedMode ? 'printed_approved' : 'rejected', JSON_THROW_ON_ERROR) ?>);
        formData.set('department', <?= json_encode((string) ($filters['department'] ?? ''), JSON_THROW_ON_ERROR) ?>);
        formData.set('search', <?= json_encode((string) $filters['search'], JSON_THROW_ON_ERROR) ?>);

        try {
            const response = await fetch(workerTickUrl, {
                method: 'POST',
                body: formData,
                headers: {Accept: 'application/json'},
            });

            if (response.ok) {
                applyStatus(await response.json());
            }
        } catch (error) {
            if (workerMessage) {
                workerMessage.textContent = 'Browser sender could not reach the server. Keep the page open and check your connection.';
            }
        } finally {
            tickRunning = false;
        }
    };

    document.querySelectorAll('[data-worker-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const button = form.querySelector('button[type="submit"]');
            const formData = new FormData(form);
            const isStart = formData.get('mail_action') === 'start_background_sender';
            const target = String(formData.get('worker_target') || '');
            syncWorkerSettings();
            formData.set('batch_key', currentBatchKey);
            formData.set('worker_batch_size', document.querySelector('[data-worker-batch-size]')?.value || '<?= $workerBatchSize ?>');
            formData.set('worker_interval_seconds', document.querySelector('[data-worker-interval-seconds]')?.value || '<?= $workerIntervalSeconds ?>');

            if (isStart) {
                setStartingUi(target);
            }

            if (button) {
                button.disabled = true;
                button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${isStart ? 'Starting' : 'Stopping'}`;
            }

            if (workerMessage) {
                workerMessage.textContent = isStart ? 'Starting background sender...' : 'Stopping background sender...';
            }

            try {
                const response = await fetch(workerControlUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {Accept: 'application/json'},
                });

                const data = await response.json();

                if (workerMessage && data.message?.text) {
                    workerMessage.textContent = data.message.text;
                }

                applyStatus(data);
                if (isStart) {
                    runBrowserTick();
                }
            } catch (error) {
                if (workerMessage) {
                    workerMessage.textContent = 'The sender request failed. Please try again.';
                }
            } finally {
                refreshStatus();
            }
        });
    });

    document.querySelectorAll('[data-worker-batch-size], [data-worker-interval-seconds]').forEach((input) => {
        input.addEventListener('input', () => {
            const selector = input.matches('[data-worker-batch-size]')
                ? '[data-worker-batch-size]'
                : '[data-worker-interval-seconds]';

            document.querySelectorAll(selector).forEach((peer) => {
                if (peer !== input) {
                    peer.value = input.value;
                }
            });
            syncWorkerSettings();
            refreshStatus();
        });
    });

    syncWorkerSettings();
    refreshStatus();
    window.setInterval(async () => {
        await refreshStatus();

        if (workerBadge?.textContent?.includes('Running')) {
            runBrowserTick();
        }
    }, 1000);
})();
</script>
</body>
</html>
