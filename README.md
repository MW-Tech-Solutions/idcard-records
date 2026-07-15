# JoSTUM ID Card Report System

Simple PHP procedural report dashboard for JoSTUM staff and student ID card records.

## What It Does

- Uses `public/index.php` as the only browser entry point.
- Uses Bootstrap from CDN for the login and dashboard screens.
- Uses procedural PHP helpers in `src/functions.php`.
- Generates plain black-and-white tabular PDF reports with DOMPDF.
- Supports staff, student, combined, status, department, and search filters.
- Queues rejected/printed ID card emails for background delivery.
- Sends only 4 queued emails every 2 minutes, with 3 retry attempts and a 5-minute retry delay.

## Main Files

- `public/index.php`
- `src/bootstrap.php`
- `src/functions.php`
- `templates/login.php`
- `templates/dashboard.php`
- `templates/pdf-report.php`
- `templates/send-mail.php`
- `bin/mail-worker.php`

## Setup

1. Copy `config.example.php` to `config.php`.
2. Update the MySQL credentials in `config.php`.
3. Run `composer install` if `vendor` is not already present.
4. Open the app through XAMPP at `http://localhost/jostumidcardrecords`.

## Background Email Worker

The Send Mail screen now queues recipients immediately and returns control to the browser. It does not send SMTP mail during the web request.

Run the worker from the project root:

```bash
php bin/mail-worker.php
```

Schedule that command every 2 minutes with Windows Task Scheduler or cron. Each run sends up to 4 emails, reuses one SMTP session for the batch, waits briefly between recipients, logs every queued/sent/failed/skipped email in MySQL, and retries failures up to 3 times after 5 minutes.

Queue settings live in `config.php` under `mail`:

```php
'batch_size' => 4,
'batch_interval_seconds' => 120,
'max_retries' => 3,
'retry_delay_seconds' => 300,
'send_delay_microseconds' => 350000,
'php_binary' => 'C:\\xampp\\php\\php.exe',
```

The Send Mail screen also has Students and Staff background sender controls. Use Initiate to start a continuous background sender for one record type, and Terminate to stop it after the current batch.

Local PHP server example:

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000
```
