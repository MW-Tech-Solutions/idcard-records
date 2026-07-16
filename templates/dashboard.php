<?php
declare(strict_types=1);

$previewUrl = 'index.php?' . report_query_string($filters, ['action' => 'pdf', 'pdf_mode' => 'detailed']);
$downloadUrl = 'index.php?' . report_query_string($filters, ['action' => 'pdf', 'pdf_mode' => 'detailed', 'download' => '1']);
$previewSummaryUrl = 'index.php?' . report_query_string($filters, ['action' => 'pdf', 'pdf_mode' => 'summary']);
$downloadSummaryUrl = 'index.php?' . report_query_string($filters, ['action' => 'pdf', 'pdf_mode' => 'summary', 'download' => '1']);
$pdfRecordCount = min(count($records), $pdfRowLimit);
$pdfLimitReached = count($records) > $pdfRowLimit;
$statusOptions = [
    'all' => 'Any Status',
    'submitted' => 'Submitted',
    'not_submitted' => 'Not Submitted',
    'submitted_not_printed' => 'Submitted but Not Printed',
    'printed' => 'Printed',
    'not_printed' => 'Not Printed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'pending' => 'Pending',
    'collected' => 'Collected (Students)',
    'not_collected' => 'Not Collected (Students)',
];
$typeOptions = [
    'all' => 'Staff and Students',
    'student' => 'Students',
    'staff' => 'Staff',
];
$perPageOptions = [25, 50, 75, 100];
$statusLink = static function (string $status) use ($filters): string {
    return 'index.php?' . report_query_string($filters, ['status' => $status]);
};
$typeLink = static function (string $type) use ($filters): string {
    return 'index.php?' . report_query_string($filters, ['type' => $type, 'status' => 'all', 'status_2' => 'all']);
};
$pageLink = static function (int $page) use ($filters, $perPage): string {
    return 'index.php?' . report_query_string($filters, ['per_page' => (string) $perPage, 'page' => (string) $page]);
};
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
$statCards = [
    ['label' => 'Filtered Records', 'value' => $summary['total'], 'icon' => 'bi-table', 'tone' => 'primary'],
    ['label' => 'Students', 'value' => $summary['students'], 'icon' => 'bi-mortarboard', 'tone' => 'success'],
    ['label' => 'Staff', 'value' => $summary['staff'], 'icon' => 'bi-person-badge', 'tone' => 'info'],
    ['label' => 'Submitted', 'value' => $summary['submitted'], 'icon' => 'bi-send-check', 'tone' => 'indigo'],
    ['label' => 'Submitted Not Printed', 'value' => $summary['submitted_not_printed'], 'icon' => 'bi-printer-fill', 'tone' => 'warning'],
    ['label' => 'Printed', 'value' => $summary['printed'], 'icon' => 'bi-printer', 'tone' => 'dark'],
    ['label' => 'Approved', 'value' => $summary['approved'], 'icon' => 'bi-check-circle', 'tone' => 'success'],
    ['label' => 'Rejected', 'value' => $summary['rejected'], 'icon' => 'bi-x-circle', 'tone' => 'danger'],
];
$mailQueueStats = $mailQueueStats ?? ['queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($config['app']['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --app-bg: #f4f7fb;
            --sidebar-bg: #101828;
            --sidebar-muted: #98a2b3;
            --brand-blue: #2563eb;
            --brand-green: #16a34a;
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

        .top-header {
            border: 0;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .08);
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

        .soft-card,
        .filter-card,
        .records-card {
            border: 1px solid rgba(228, 231, 236, .9);
            box-shadow: 0 18px 45px rgba(15, 23, 42, .07);
            background: rgba(255, 255, 255, .9);
            backdrop-filter: blur(16px);
        }

        .stat-card {
            border: 0;
            overflow: hidden;
            box-shadow: 0 14px 32px rgba(15, 23, 42, .07);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            font-size: 1.25rem;
        }

        .tone-indigo {
            color: #4f46e5;
            background: #eef2ff;
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

        .form-control,
        .form-select,
        .btn {
            border-radius: 12px;
        }

        .quick-metric {
            border: 1px solid #e4e7ec;
            border-radius: 18px;
            background: #fff;
            padding: 14px 16px;
            min-width: 148px;
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
                <?php foreach ($typeOptions as $value => $label): ?>
                    <a class="nav-link d-flex align-items-center justify-content-between <?= $filters['type'] === $value ? 'active' : '' ?>" href="<?= htmlspecialchars($typeLink($value)) ?>">
                        <span><i class="bi <?= $value === 'student' ? 'bi-mortarboard' : ($value === 'staff' ? 'bi-person-badge' : 'bi-collection') ?> me-2"></i><?= htmlspecialchars($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="mb-4">
            <p class="sidebar-label mb-2">Tools</p>
            <nav class="nav flex-column gap-1">
                <a class="nav-link d-flex align-items-center justify-content-between" href="index.php?action=send-mail">
                    <span><i class="bi bi-envelope-paper me-2"></i>Send Mail</span>
                </a>
                <a class="nav-link d-flex align-items-center justify-content-between" href="index.php?action=mail-records">
                    <span><i class="bi bi-inboxes me-2"></i>Mail Records</span>
                </a>
                <a class="nav-link d-flex align-items-center justify-content-between" href="index.php?action=mail-records&mail_type=rejected&record_type=student&mail_status=sent">
                    <span><i class="bi bi-x-circle me-2"></i>Rejected Students</span>
                </a>
                <a class="nav-link d-flex align-items-center justify-content-between" href="index.php?action=mail-records&mail_type=rejected&record_type=staff&mail_status=sent">
                    <span><i class="bi bi-x-circle me-2"></i>Rejected Staff</span>
                </a>
                <a class="nav-link d-flex align-items-center justify-content-between" href="index.php?action=mail-records&mail_type=printed&record_type=student&mail_status=sent">
                    <span><i class="bi bi-check2-circle me-2"></i>Printed Students</span>
                </a>
                <a class="nav-link d-flex align-items-center justify-content-between" href="index.php?action=mail-records&mail_type=printed&record_type=staff&mail_status=sent">
                    <span><i class="bi bi-check2-circle me-2"></i>Printed Staff</span>
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
                    <span class="header-icon"><i class="bi bi-credit-card-2-front"></i></span>
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1"><?= htmlspecialchars($config['app']['university_name']) ?></p>
                        <h2 class="h3 fw-bold mb-1">ID Card Records Dashboard</h2>
                        <p class="text-secondary mb-0"><?= htmlspecialchars(report_filter_label($filters)) ?></p>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge rounded-pill department-pill px-3 py-2">
                        <i class="bi bi-building me-1"></i><?= htmlspecialchars(($filters['department'] ?? '') !== '' ? $filters['department'] : 'All departments') ?>
                    </span>
                    <span class="badge rounded-pill text-bg-light text-dark border px-3 py-2">
                        <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars((string) ($user['name'] ?? 'Admin')) ?>
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <select class="form-select form-select-sm pdf-layout-selector" style="width: auto;">
                            <option value="detailed" selected>Detailed (Landscape)</option>
                            <option value="summary">Summary (Portrait)</option>
                        </select>
                        <a class="btn btn-outline-primary pdf-preview-btn" href="<?= htmlspecialchars($previewUrl) ?>" target="_blank">
                            <i class="bi bi-eye me-2"></i>Preview
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <section class="card soft-card rounded-4 mb-4">
            <div class="card-body p-4 d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                <div>
                    <p class="text-uppercase small fw-bold text-primary mb-1">Mail Queue</p>
                    <h3 class="h5 mb-1"><?= number_format((int) ($mailQueueStats['remaining'] ?? 0)) ?> background emails waiting or processing</h3>
                    <p class="text-secondary mb-0">The queue worker sends 4 emails every 2 minutes and retries failed mail after 5 minutes.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <div class="quick-metric">
                        <span class="small text-secondary">Queued</span>
                        <strong class="d-block"><?= number_format((int) ($mailQueueStats['queued'] ?? 0)) ?></strong>
                    </div>
                    <div class="quick-metric">
                        <span class="small text-secondary">Sent</span>
                        <strong class="d-block text-success"><?= number_format((int) ($mailQueueStats['sent'] ?? 0)) ?></strong>
                    </div>
                    <a class="btn btn-primary align-self-center" href="index.php?action=send-mail"><i class="bi bi-envelope-paper me-2"></i>Open Mail Queue</a>
                </div>
            </div>
        </section>

        <section class="row g-3 mb-4">
            <?php foreach ($statCards as $card): ?>
                <div class="col-6 col-xl-3">
                    <div class="card stat-card rounded-4 h-100">
                        <div class="card-body p-3 p-xl-4">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <span class="small text-uppercase fw-bold text-secondary"><?= htmlspecialchars($card['label']) ?></span>
                                    <strong class="display-6 d-block lh-1 mt-2"><?= number_format((int) $card['value']) ?></strong>
                                </div>
                                <span class="stat-icon <?= $card['tone'] === 'indigo' ? 'tone-indigo' : 'text-bg-' . $card['tone'] ?>">
                                    <i class="bi <?= htmlspecialchars($card['icon']) ?>"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="card soft-card rounded-4 mb-4">
            <div class="card-body p-4 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <p class="text-uppercase small fw-bold text-primary mb-1">Print Current Selection</p>
                    <h3 class="h5 mb-1"><?= htmlspecialchars(report_filter_label($filters)) ?></h3>
                    <p class="text-secondary mb-0">
                        <?= number_format($pdfRecordCount) ?> of <?= number_format(count($records)) ?> filtered rows will be used in the PDF<?= $pdfLimitReached ? ' (limit reached)' : '' ?>.
                    </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="small fw-bold text-secondary text-uppercase mb-0 d-none d-sm-inline">Layout:</span>
                        <select class="form-select pdf-layout-selector" style="width: auto;">
                            <option value="detailed" selected>Detailed (Landscape)</option>
                            <option value="summary">Summary (Portrait)</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-primary btn-lg pdf-preview-btn" href="<?= htmlspecialchars($previewUrl) ?>" target="_blank">
                            <i class="bi bi-eye me-2"></i>Preview PDF
                        </a>
                        <a class="btn btn-primary btn-lg pdf-download-btn" href="<?= htmlspecialchars($downloadUrl) ?>" target="_blank">
                            <i class="bi bi-file-earmark-pdf me-2"></i>Download
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="card filter-card rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">Filters</p>
                        <h3 class="h5 mb-0"><?= htmlspecialchars(report_filter_label($filters)) ?></h3>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge rounded-pill department-pill px-3 py-2">
                            <i class="bi bi-building me-1"></i><?= htmlspecialchars(($filters['department'] ?? '') !== '' ? $filters['department'] : 'All departments') ?>
                        </span>
                        <span class="badge rounded-pill text-bg-primary px-3 py-2"><?= number_format(count($records)) ?> rows</span>
                    </div>
                </div>

                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="page" value="1">
                    <label class="col-12 col-md-6 col-xl-2 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Record Type</span>
                        <select class="form-select mt-1" name="type">
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="col-12 col-md-6 col-xl-2 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Status 1</span>
                        <select class="form-select mt-1" name="status">
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="col-12 col-md-6 col-xl-2 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Status 2</span>
                        <select class="form-select mt-1" name="status_2">
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= ($filters['status_2'] ?? 'all') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
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
                        <input class="form-control mt-1" name="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="Matric no, PF no, name">
                    </label>

                    <label class="col-12 col-md-6 col-xl-1 form-label mb-0">
                        <span class="small fw-bold text-uppercase">Show</span>
                        <select class="form-select mt-1" name="per_page">
                            <?php foreach ($perPageOptions as $option): ?>
                                <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="col-12 col-xl-1 d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
                        <a class="btn btn-outline-secondary" href="index.php">Reset</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="card records-card rounded-4">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">Records</p>
                        <h3 class="h5 mb-0">Showing <?= number_format($totalRecords === 0 ? 0 : $pageOffset + 1) ?>-<?= number_format(min($pageOffset + $perPage, $totalRecords)) ?> of <?= number_format($totalRecords) ?> rows</h3>
                        <p class="text-secondary mb-0">
                            The PDF includes <?= $pdfLimitReached ? 'the first ' : 'all ' ?><?= number_format($pdfRecordCount) ?> filtered rows.
                        </p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <select class="form-select form-select-sm pdf-layout-selector" style="width: auto;">
                            <option value="detailed" selected>Detailed (Landscape)</option>
                            <option value="summary">Summary (Portrait)</option>
                        </select>
                        <a class="btn btn-outline-primary pdf-preview-btn" href="<?= htmlspecialchars($previewUrl) ?>" target="_blank">
                            <i class="bi bi-eye me-2"></i>Preview
                        </a>
                        <a class="btn btn-primary pdf-download-btn" href="<?= htmlspecialchars($downloadUrl) ?>" target="_blank">
                            <i class="bi bi-printer me-2"></i>Print / Download
                        </a>
                    </div>
                </div>

                <?php if ($records === []): ?>
                    <div class="alert alert-info mb-0">No records matched this filter. Try another department, status, or search term.</div>
                <?php else: ?>
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                        <span class="text-secondary small">Viewing <?= number_format($perPage) ?> records per page.</span>
                        <nav aria-label="Records pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($pageLink(max(1, $currentPage - 1))) ?>">Previous</a>
                                </li>
                                <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                ?>
                                <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                                    <li class="page-item <?= $currentPage === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars($pageLink($page)) ?>"><?= $page ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars($pageLink(min($totalPages, $currentPage + 1))) ?>">Next</a>
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
                                <th>Reference</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Meta</th>
                                <th>Request Status</th>
                                <th>Submitted</th>
                                <th>Printed</th>
                                <th>Printed Date</th>
                                <th>Collected</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($displayRecords as $record): ?>
                                <?php
                                    $status = (string) ($record['card_request_status'] ?: 'pending');
                                    $statusBadge = $status === 'approved' ? 'text-bg-success' : ($status === 'rejected' ? 'text-bg-danger' : 'text-bg-secondary');
                                ?>
                                <tr>
                                    <td><span class="badge <?= $record['record_type'] === 'staff' ? 'text-bg-primary' : 'text-bg-success' ?>"><?= htmlspecialchars(ucfirst((string) $record['record_type'])) ?></span></td>
                                    <td class="fw-semibold"><?= htmlspecialchars((string) ($record['primary_identifier'] ?: 'N/A')) ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars((string) ($record['reference_number'] ?: 'N/A')) ?></td>
                                    <td><?= htmlspecialchars((string) ($record['full_name'] ?: 'N/A')) ?></td>
                                    <td><?= htmlspecialchars((string) ($record['department'] ?: 'N/A')) ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars((string) ($record['extra_meta'] ?: 'N/A')) ?></td>
                                    <td><span class="badge <?= $statusBadge ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                                    <td><?= (int) $record['is_submitted'] === 1 ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-warning">No</span>' ?></td>
                                    <td><?= (int) $record['is_printed'] === 1 ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-warning">No</span>' ?></td>
                                    <td class="text-secondary"><?= (int) $record['is_printed'] === 1 ? htmlspecialchars($formatDateTime($record['card_printed_at'] ?? '')) : 'N/A' ?></td>
                                    <td><?= $record['is_collected'] === null ? '<span class="badge text-bg-secondary">N/A</span>' : ((int) $record['is_collected'] === 1 ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-warning">No</span>') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectors = document.querySelectorAll('.pdf-layout-selector');
    const previewBtns = document.querySelectorAll('.pdf-preview-btn');
    const downloadBtns = document.querySelectorAll('.pdf-download-btn');

    const detailedPreviewUrl = <?= json_encode($previewUrl) ?>;
    const summaryPreviewUrl = <?= json_encode($previewSummaryUrl) ?>;
    const detailedDownloadUrl = <?= json_encode($downloadUrl) ?>;
    const summaryDownloadUrl = <?= json_encode($downloadSummaryUrl) ?>;

    selectors.forEach(selector => {
        selector.addEventListener('change', function() {
            const mode = this.value;
            
            // Sync all selectors on the page to show the same selection
            selectors.forEach(s => s.value = mode);

            const newPreview = mode === 'summary' ? summaryPreviewUrl : detailedPreviewUrl;
            const newDownload = mode === 'summary' ? summaryDownloadUrl : detailedDownloadUrl;

            previewBtns.forEach(btn => btn.setAttribute('href', newPreview));
            downloadBtns.forEach(btn => btn.setAttribute('href', newDownload));
        });
    });
});
</script>
</div>
</body>
</html>
