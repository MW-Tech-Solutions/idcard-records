<?php
declare(strict_types=1);

$filters = $filters ?? ['mail_type' => 'rejected', 'record_type' => 'student', 'mail_status' => 'sent', 'search' => ''];
$records = $records ?? [];
$counts = $counts ?? [
    'rejected' => [
        'student' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
        'staff' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
    ],
    'printed' => [
        'student' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
        'staff' => ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
    ],
];
$mailType = (string) $filters['mail_type'];
$recordType = (string) $filters['record_type'];
$mailStatus = (string) $filters['mail_status'];
$recordLabel = $recordType === 'staff' ? 'Staff' : 'Students';
$typeTitle = ($mailType === 'printed' ? 'Printed' : 'Rejected') . ' ' . $recordLabel . ' Mail Records';
$typeDescription = $mailType === 'printed'
    ? 'Successful, queued, and failed printed ID card collection emails for ' . strtolower($recordLabel) . '.'
    : 'Successful, queued, and failed rejected application emails for ' . strtolower($recordLabel) . '.';
$statusOptions = [
    'sent' => 'Successful',
    'failed' => 'Failed',
    'queued' => 'Queued',
    'processing' => 'Processing',
    'all' => 'All',
];
$statusBadge = static function (string $status): string {
    return match ($status) {
        'sent' => 'text-bg-success',
        'failed' => 'text-bg-danger',
        'processing' => 'text-bg-primary',
        default => 'text-bg-secondary',
    };
};
$typeLink = static function (string $type, string $recordType) use ($filters): string {
    return 'index.php?' . http_build_query([
        'action' => 'mail-records',
        'mail_type' => $type,
        'record_type' => $recordType,
        'mail_status' => $filters['mail_status'],
        'search' => $filters['search'],
    ]);
};
$currentCounts = $counts[$mailType][$recordType] ?? ['all' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail Records - <?= htmlspecialchars($config['app']['name']) ?></title>
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
        .records-card {
            border: 1px solid rgba(228, 231, 236, .9);
            box-shadow: 0 18px 45px rgba(15, 23, 42, .07);
            background: rgba(255, 255, 255, .9);
            backdrop-filter: blur(16px);
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

        .mail-tab {
            display: block;
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            background: #fff;
            color: #0f172a;
            padding: 16px;
            text-decoration: none;
            min-height: 116px;
        }

        .mail-tab.active {
            border-color: var(--brand-blue);
            box-shadow: 0 14px 32px rgba(37, 99, 235, .15);
        }

        .mail-tab strong {
            font-size: clamp(1.55rem, 4vw, 2.2rem);
            line-height: 1;
        }

        .form-control,
        .form-select,
        .btn {
            border-radius: 12px;
        }

        .table th {
            white-space: nowrap;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .error-cell {
            max-width: 320px;
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
                <a class="nav-link" href="index.php?action=send-mail">
                    <i class="bi bi-envelope-paper me-2"></i>Send Mail
                </a>
                <a class="nav-link" href="index.php?action=mail-records">
                    <i class="bi bi-inboxes me-2"></i>Mail Records
                </a>
                <a class="nav-link <?= $mailType === 'rejected' && $recordType === 'student' ? 'active' : '' ?>" href="index.php?action=mail-records&mail_type=rejected&record_type=student&mail_status=sent">
                    <i class="bi bi-x-circle me-2"></i>Rejected Students
                </a>
                <a class="nav-link <?= $mailType === 'rejected' && $recordType === 'staff' ? 'active' : '' ?>" href="index.php?action=mail-records&mail_type=rejected&record_type=staff&mail_status=sent">
                    <i class="bi bi-x-circle me-2"></i>Rejected Staff
                </a>
                <a class="nav-link <?= $mailType === 'printed' && $recordType === 'student' ? 'active' : '' ?>" href="index.php?action=mail-records&mail_type=printed&record_type=student&mail_status=sent">
                    <i class="bi bi-check2-circle me-2"></i>Printed Students
                </a>
                <a class="nav-link <?= $mailType === 'printed' && $recordType === 'staff' ? 'active' : '' ?>" href="index.php?action=mail-records&mail_type=printed&record_type=staff&mail_status=sent">
                    <i class="bi bi-check2-circle me-2"></i>Printed Staff
                </a>
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
                    <span class="header-icon"><i class="bi bi-inboxes"></i></span>
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1"><?= htmlspecialchars($config['app']['university_name']) ?></p>
                        <h2 class="h3 fw-bold mb-1"><?= htmlspecialchars($typeTitle) ?></h2>
                        <p class="text-secondary mb-0"><?= htmlspecialchars($typeDescription) ?></p>
                    </div>
                </div>

                <a class="btn btn-primary" href="index.php?action=send-mail"><i class="bi bi-plus-circle me-2"></i>Queue More Mail</a>
            </div>
        </header>

        <?php if (is_array($message)): ?>
            <section class="alert alert-<?= htmlspecialchars($message['type']) ?> rounded-4">
                <?= htmlspecialchars($message['text']) ?>
            </section>
        <?php endif; ?>

        <section class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
                <a class="mail-tab <?= $mailType === 'rejected' && $recordType === 'student' ? 'active' : '' ?>" href="<?= htmlspecialchars($typeLink('rejected', 'student')) ?>">
                    <span class="small text-uppercase fw-bold text-danger">Rejected Students</span>
                    <strong class="d-block mt-2"><?= number_format((int) ($counts['rejected']['student']['sent'] ?? 0)) ?></strong>
                    <span class="text-secondary small">successful rejected student emails</span>
                </a>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <a class="mail-tab <?= $mailType === 'rejected' && $recordType === 'staff' ? 'active' : '' ?>" href="<?= htmlspecialchars($typeLink('rejected', 'staff')) ?>">
                    <span class="small text-uppercase fw-bold text-danger">Rejected Staff</span>
                    <strong class="d-block mt-2"><?= number_format((int) ($counts['rejected']['staff']['sent'] ?? 0)) ?></strong>
                    <span class="text-secondary small">successful rejected staff emails</span>
                </a>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <a class="mail-tab <?= $mailType === 'printed' && $recordType === 'student' ? 'active' : '' ?>" href="<?= htmlspecialchars($typeLink('printed', 'student')) ?>">
                    <span class="small text-uppercase fw-bold text-success">Printed Students</span>
                    <strong class="d-block mt-2"><?= number_format((int) ($counts['printed']['student']['sent'] ?? 0)) ?></strong>
                    <span class="text-secondary small">successful student collection emails</span>
                </a>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <a class="mail-tab <?= $mailType === 'printed' && $recordType === 'staff' ? 'active' : '' ?>" href="<?= htmlspecialchars($typeLink('printed', 'staff')) ?>">
                    <span class="small text-uppercase fw-bold text-success">Printed Staff</span>
                    <strong class="d-block mt-2"><?= number_format((int) ($counts['printed']['staff']['sent'] ?? 0)) ?></strong>
                    <span class="text-secondary small">successful staff collection emails</span>
                </a>
            </div>
        </section>

        <section class="card soft-card rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">Filter Mail Records</p>
                        <h3 class="h5 mb-0"><?= number_format(count($records)) ?> records shown</h3>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <a class="btn btn-sm <?= $mailStatus === $value ? 'btn-primary' : 'btn-outline-primary' ?>" href="index.php?<?= htmlspecialchars(http_build_query(['action' => 'mail-records', 'mail_type' => $mailType, 'record_type' => $recordType, 'mail_status' => $value, 'search' => $filters['search']])) ?>">
                                <?= htmlspecialchars($label) ?>
                                <span class="badge text-bg-light text-dark ms-1"><?= number_format((int) ($currentCounts[$value] ?? 0)) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="mail-records">
                    <input type="hidden" name="mail_type" value="<?= htmlspecialchars($mailType) ?>">
                    <input type="hidden" name="record_type" value="<?= htmlspecialchars($recordType) ?>">
                    <input type="hidden" name="mail_status" value="<?= htmlspecialchars($mailStatus) ?>">
                    <label class="col-12 col-lg-10 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Search</span>
                        <input class="form-control mt-1" name="search" value="<?= htmlspecialchars((string) $filters['search']) ?>" placeholder="Search email, name, subject, or error">
                    </label>
                    <div class="col-12 col-lg-2 d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-2"></i>Search</button>
                        <a class="btn btn-outline-secondary" href="index.php?action=mail-records&mail_type=<?= htmlspecialchars($mailType) ?>&record_type=<?= htmlspecialchars($recordType) ?>&mail_status=<?= htmlspecialchars($mailStatus) ?>">Reset</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="card records-card rounded-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">Mail History</p>
                        <h3 class="h5 mb-0"><?= htmlspecialchars($typeTitle) ?></h3>
                    </div>
                </div>

                <?php if ($records === []): ?>
                    <div class="alert alert-info mb-0">No mail records matched this view.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Recipient</th>
                                <th>Record</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Attempts</th>
                                <th>Last Activity</th>
                                <th>Error</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($records as $record): ?>
                                <?php
                                    $status = (string) $record['status'];
                                    $lastActivity = $record['sent_at'] ?: ($record['log_last_attempt_at'] ?: ($record['updated_at'] ?: $record['created_at']));
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold d-block"><?= htmlspecialchars((string) ($record['recipient_name'] ?: 'N/A')) ?></span>
                                        <span class="text-secondary small"><?= htmlspecialchars((string) $record['recipient_email']) ?></span>
                                    </td>
                                    <td><span class="badge <?= $record['record_type'] === 'staff' ? 'text-bg-primary' : 'text-bg-success' ?>"><?= htmlspecialchars(ucfirst((string) $record['record_type'])) ?></span></td>
                                    <td><span class="badge <?= $record['notification_type'] === 'printed' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= htmlspecialchars(ucfirst((string) $record['notification_type'])) ?></span></td>
                                    <td><span class="badge <?= $statusBadge($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                                    <td><?= number_format((int) $record['attempts']) ?> / <?= number_format((int) (($record['retry_count'] ?? 0) + 1)) ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars((string) ($lastActivity ?: 'N/A')) ?></td>
                                    <td class="text-secondary error-cell"><?= htmlspecialchars((string) ($record['last_error'] ?: 'N/A')) ?></td>
                                    <td>
                                        <?php if ($status === 'failed'): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="mail-records">
                                                <input type="hidden" name="mail_action" value="retry_failed_mail">
                                                <input type="hidden" name="queue_id" value="<?= (int) $record['id'] ?>">
                                                <input type="hidden" name="mail_type" value="<?= htmlspecialchars($mailType) ?>">
                                                <input type="hidden" name="record_type" value="<?= htmlspecialchars($recordType) ?>">
                                                <input type="hidden" name="mail_status" value="<?= htmlspecialchars($mailStatus) ?>">
                                                <input type="hidden" name="search" value="<?= htmlspecialchars((string) $filters['search']) ?>">
                                                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Resend</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-secondary small">No action</span>
                                        <?php endif; ?>
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
</body>
</html>
