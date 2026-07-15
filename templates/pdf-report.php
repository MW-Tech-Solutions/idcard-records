<?php
declare(strict_types=1);

$formatValue = static function ($value): string {
    $value = trim((string) $value);
    return $value === '' ? 'N/A' : $value;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>JoSTUM ID Report</title>
    <style>
        @page { margin: 18px 18px 22px; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 9px; margin: 0; }
        h1, h2, p { margin: 0; }
        h1 { font-size: 16px; text-align: center; }
        h2 { margin-top: 3px; font-size: 12px; text-align: center; font-weight: normal; }
        .department-title { margin: 8px 0 0; font-size: 15px; text-align: center; font-weight: bold; text-transform: uppercase; }
        .meta { margin: 10px 0 8px; width: 100%; border-collapse: collapse; }
        .meta td { border: 1px solid #000; padding: 5px 6px; vertical-align: top; }
        .meta strong { display: block; font-size: 8px; text-transform: uppercase; }
        .notice { margin: 8px 0; border: 2px solid #000; padding: 7px; font-weight: bold; }
        .records { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .records th, .records td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; word-wrap: break-word; }
        .records th { font-size: 8px; text-align: left; font-weight: bold; }
        .records td { font-size: 8px; }
        .footer { margin-top: 8px; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($config['app']['university_name']) ?></h1>
    <h2><?= htmlspecialchars(report_filter_label($filters)) ?></h2>
    <div class="department-title">
        Department: <?= htmlspecialchars(($filters['department'] ?? '') !== '' ? $filters['department'] : 'All Departments') ?>
    </div>

    <table class="meta">
        <tr>
            <td><strong>Generated</strong><?= $generatedAt->format('F j, Y g:i A') ?></td>
            <td><strong>Record Type</strong><?= htmlspecialchars($filters['type'] === 'all' ? 'Staff and Students' : ucfirst($filters['type'])) ?></td>
            <td><strong>Status Filter</strong><?= htmlspecialchars(report_filter_label($filters)) ?></td>
            <td><strong>Department</strong><?= htmlspecialchars(($filters['department'] ?? '') !== '' ? $filters['department'] : 'All departments') ?></td>
        </tr>
        <tr>
            <td><strong>Matching Rows</strong><?= number_format($summary['total']) ?></td>
            <td><strong>Submitted</strong><?= number_format($summary['submitted']) ?></td>
            <td><strong>Printed / Not Printed</strong><?= number_format($summary['printed']) ?> / <?= number_format($summary['not_printed']) ?></td>
            <td><strong>Search Filter</strong><?= htmlspecialchars($filters['search'] !== '' ? $filters['search'] : 'No search term') ?></td>
        </tr>
    </table>

    <?php if (!empty($pdfNotice)): ?>
        <div class="notice"><?= htmlspecialchars($pdfNotice) ?></div>
    <?php endif; ?>

    <table class="records">
        <thead>
            <tr>
                <th>S/N</th>
                <th>Type</th>
                <th>Identifier</th>
                <th>Reference</th>
                <th>Name</th>
                <th>Department</th>
                <th>Designation / Meta</th>
                <th>Request Status</th>
                <th>Submitted</th>
                <th>Printed</th>
                <th>Printed Date</th>
                <th>Issued</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $index => $record): ?>
            <tr>
                <td><?= number_format($index + 1) ?></td>
                <td><?= htmlspecialchars($formatValue($record['record_type'])) ?></td>
                <td><?= htmlspecialchars($formatValue($record['primary_identifier'])) ?></td>
                <td><?= htmlspecialchars($formatValue($record['reference_number'])) ?></td>
                <td><?= htmlspecialchars($formatValue($record['full_name'])) ?></td>
                <td><?= htmlspecialchars($formatValue($record['department'])) ?></td>
                <td><?= htmlspecialchars($formatValue($record['extra_meta'])) ?></td>
                <td><?= htmlspecialchars($formatValue($record['card_request_status'] ?: 'pending')) ?></td>
                <td><?= (int) $record['is_submitted'] === 1 ? 'Yes' : 'No' ?></td>
                <td><?= (int) $record['is_printed'] === 1 ? 'Yes' : 'No' ?></td>
                <td><?= (int) $record['is_printed'] === 1 ? htmlspecialchars($formatDateTime($record['card_printed_at'] ?? '')) : 'N/A' ?></td>
                <td><?= htmlspecialchars($formatValue($record['card_issued_at'] ?: 'Not issued')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($records === []): ?>
            <tr>
                <td colspan="12">No records matched the selected filters.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">Prepared for JoSTUM ID card administration.</div>
</body>
</html>
