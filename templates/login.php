<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($config['app']['name']) ?> Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --page-bg: #f4f7fb;
            --ink: #101828;
            --muted: #667085;
            --line: #d9e2ef;
            --blue: #2563eb;
            --green: #16a34a;
            --navy: #101828;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 12% 16%, rgba(37, 99, 235, .16), transparent 28%),
                radial-gradient(circle at 88% 10%, rgba(22, 163, 74, .12), transparent 24%),
                linear-gradient(180deg, #fbfdff 0%, var(--page-bg) 100%);
            color: var(--ink);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .login-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, .96fr) minmax(460px, 1.04fr);
        }

        .login-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(24px, 5vw, 64px);
        }

        .login-card {
            width: 100%;
            max-width: 460px;
            border: 1px solid rgba(217, 226, 239, .95);
            border-radius: 24px;
            background: rgba(255, 255, 255, .92);
            box-shadow: 0 24px 70px rgba(15, 23, 42, .11);
            backdrop-filter: blur(18px);
        }

        .brand-mark {
            width: 52px;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            color: #fff;
            background: linear-gradient(135deg, var(--blue), var(--green));
            font-weight: 800;
            letter-spacing: .03em;
        }

        .input-shell {
            position: relative;
        }

        .input-shell .bi {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #98a2b3;
            pointer-events: none;
        }

        .input-shell .form-control {
            min-height: 52px;
            border-radius: 14px;
            border-color: var(--line);
            padding-left: 46px;
            font-weight: 600;
        }

        .input-shell .form-control:focus {
            border-color: rgba(37, 99, 235, .55);
            box-shadow: 0 0 0 .25rem rgba(37, 99, 235, .12);
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            border: 0;
            border-radius: 12px;
            background: #eef4ff;
            color: var(--blue);
        }

        .password-toggle .bi {
            position: static;
            transform: none;
            color: inherit;
            pointer-events: auto;
        }

        .password-input {
            padding-right: 56px;
        }

        .btn-primary {
            --bs-btn-bg: var(--blue);
            --bs-btn-border-color: var(--blue);
            --bs-btn-hover-bg: #1d4ed8;
            --bs-btn-hover-border-color: #1d4ed8;
            border-radius: 14px;
            min-height: 52px;
            font-weight: 800;
        }

        .showcase {
            position: relative;
            display: flex;
            align-items: center;
            overflow: hidden;
            padding: clamp(24px, 5vw, 64px);
            background:
                linear-gradient(135deg, rgba(16, 24, 40, .98), rgba(17, 52, 77, .94)),
                var(--navy);
            color: #fff;
        }

        .showcase::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .055) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent);
        }

        .showcase-inner {
            position: relative;
            width: min(100%, 780px);
            margin: 0 auto;
        }

        .status-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 28px;
        }

        .status-box,
        .preview-board {
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .09);
            box-shadow: 0 22px 55px rgba(0, 0, 0, .22);
            backdrop-filter: blur(18px);
        }

        .status-box {
            border-radius: 8px;
            padding: 16px;
        }

        .status-box strong {
            display: block;
            font-size: clamp(1.35rem, 3vw, 2rem);
            line-height: 1;
        }

        .preview-board {
            border-radius: 18px;
            margin-top: 28px;
            overflow: hidden;
        }

        .board-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, .12);
        }

        .board-dots {
            display: inline-flex;
            gap: 6px;
        }

        .board-dots span {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .36);
        }

        .record-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
            padding: 14px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, .09);
        }

        .record-row:last-child {
            border-bottom: 0;
        }

        .record-title {
            width: min(100%, 270px);
            height: 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .72);
        }

        .record-meta {
            width: min(80%, 190px);
            height: 8px;
            margin-top: 9px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .24);
        }

        .record-badge {
            min-width: 88px;
            border-radius: 999px;
            padding: 7px 10px;
            text-align: center;
            font-size: .78rem;
            font-weight: 800;
        }

        .badge-approved {
            color: #dcfce7;
            background: rgba(22, 163, 74, .22);
        }

        .badge-rejected {
            color: #fee2e2;
            background: rgba(220, 38, 38, .2);
        }

        .badge-printed {
            color: #dbeafe;
            background: rgba(37, 99, 235, .24);
        }

        .reset-link {
            color: var(--blue);
        }

        @media (max-width: 991.98px) {
            .login-shell {
                display: flex;
                flex-direction: column;
            }

            .showcase {
                min-height: 430px;
                order: -1;
            }

            .login-panel {
                padding: 20px;
            }

            .status-strip {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575.98px) {
            .showcase {
                min-height: 360px;
            }

            .preview-board {
                display: none;
            }
        }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="login-panel">
        <div class="login-card">
            <div class="p-4 p-sm-5">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <span class="brand-mark">JS</span>
                    <div>
                        <p class="text-uppercase small fw-bold text-primary mb-1">JoSTUM</p>
                        <h1 class="h4 fw-bold mb-0">Report Centre</h1>
                    </div>
                </div>

                <div class="mb-4">
                    <p class="text-uppercase small fw-bold text-secondary mb-2">Secure Access</p>
                    <h2 class="h2 fw-bold mb-2">Welcome back</h2>
                    <p class="text-secondary mb-0">Sign in with your administrator account.</p>
                </div>

                <?php if ($loginError !== ''): ?>
                    <div class="alert alert-danger rounded-4 d-flex gap-2 align-items-start">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span><?= htmlspecialchars($loginError) ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" class="d-grid gap-3">
                    <input type="hidden" name="action" value="login">

                    <label class="form-label mb-0">
                        <span class="small fw-bold text-uppercase text-secondary">Email Address</span>
                        <span class="input-shell d-block mt-1">
                            <i class="bi bi-envelope"></i>
                            <input class="form-control form-control-lg" type="email" name="email" placeholder="admin@uam.edu.ng" autocomplete="username" required>
                        </span>
                    </label>

                    <label class="form-label mb-0">
                        <span class="small fw-bold text-uppercase text-secondary">Password</span>
                        <span class="input-shell d-block mt-1">
                            <i class="bi bi-lock"></i>
                            <input class="form-control form-control-lg password-input" id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                            <button class="password-toggle" type="button" data-password-toggle aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </span>
                    </label>

                    <button class="btn btn-primary btn-lg mt-2" type="submit">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                    </button>
                </form>

                <div class="border-top mt-4 pt-3 small d-flex flex-column flex-sm-row gap-1">
                    <span class="text-secondary">Forgot your password?</span>
                    <a class="fw-semibold text-decoration-none reset-link" href="<?= htmlspecialchars($resetUrl) ?>" target="_blank" rel="noopener">Reset it from the main portal</a>
                </div>
            </div>
        </div>
    </section>

    <section class="showcase">
        <div class="showcase-inner">
            <p class="text-uppercase small fw-bold text-white-50 mb-2"><?= htmlspecialchars($config['app']['university_name']) ?></p>
            <h2 class="display-5 fw-bold lh-1 mb-3">ID Card Records</h2>
            <p class="lead text-white-50 mb-0" style="max-width: 640px;">
                Monitor staff and student card requests from a focused administrative workspace.
            </p>

            <div class="status-strip">
                <div class="status-box">
                    <span class="small text-white-50 text-uppercase fw-bold">Staff</span>
                    <strong class="mt-2">Active</strong>
                </div>
                <div class="status-box">
                    <span class="small text-white-50 text-uppercase fw-bold">Students</span>
                    <strong class="mt-2">Ready</strong>
                </div>
                <div class="status-box">
                    <span class="small text-white-50 text-uppercase fw-bold">Reports</span>
                    <strong class="mt-2">PDF</strong>
                </div>
            </div>

            <div class="preview-board" aria-hidden="true">
                <div class="board-header">
                    <div class="board-dots"><span></span><span></span><span></span></div>
                    <span class="small text-white-50 fw-bold">Live workspace</span>
                </div>
                <div class="record-row">
                    <div>
                        <div class="record-title"></div>
                        <div class="record-meta"></div>
                    </div>
                    <span class="record-badge badge-approved">Approved</span>
                </div>
                <div class="record-row">
                    <div>
                        <div class="record-title" style="width: 74%;"></div>
                        <div class="record-meta" style="width: 58%;"></div>
                    </div>
                    <span class="record-badge badge-printed">Printed</span>
                </div>
                <div class="record-row">
                    <div>
                        <div class="record-title" style="width: 86%;"></div>
                        <div class="record-meta" style="width: 64%;"></div>
                    </div>
                    <span class="record-badge badge-rejected">Rejected</span>
                </div>
            </div>
        </div>
    </section>
</main>
<script>
(() => {
    const button = document.querySelector('[data-password-toggle]');
    const password = document.querySelector('#password');

    if (!button || !password) {
        return;
    }

    button.addEventListener('click', () => {
        const showing = password.type === 'text';
        password.type = showing ? 'password' : 'text';
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        button.innerHTML = `<i class="bi ${showing ? 'bi-eye' : 'bi-eye-slash'}"></i>`;
    });
})();
</script>
</body>
</html>
