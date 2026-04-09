<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>StratFlow</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/assets/css/app.css') ?: '1' ?>">
    <script>
    // Restore sidebar collapsed state before paint to avoid FOUC
    (function() {
        try {
            if (localStorage.getItem('stratflow.sidebarCollapsed') === '1') {
                document.documentElement.classList.add('sidebar-collapsed-preload');
            }
        } catch (e) {}
    })();
    </script>
    <style>
    /* Applied early (before JS runs) if collapsed state was saved */
    html.sidebar-collapsed-preload .sidebar { width: var(--sidebar-width-collapsed); }
    html.sidebar-collapsed-preload .app-main { margin-left: var(--sidebar-width-collapsed); }
    </style>
</head>
<body class="app-layout">
    <div class="app-wrapper">
        <?php require __DIR__ . '/../partials/sidebar.php'; ?>
        <div class="app-main">
            <header class="app-topbar">
                <button class="sidebar-toggle" id="sidebar-toggle" title="Toggle sidebar">&#9776;</button>
                <?php require __DIR__ . '/../partials/workflow-stepper.php'; ?>
                <div class="topbar-right">
                    <span class="user-name"><?= htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User') ?></span>
                    <form method="POST" action="/logout" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">Logout</button>
                    </form>
                </div>
            </header>
            <main class="app-content">
                <?php if (!empty($flash_message)): ?>
                    <div class="flash-message flash-success"><?= htmlspecialchars($flash_message) ?></div>
                <?php endif; ?>
                <?php if (!empty($flash_error)): ?>
                    <div class="flash-message flash-error"><?= htmlspecialchars($flash_error) ?></div>
                <?php endif; ?>
                <?= $content ?>
            </main>
        </div>
    </div>
    <?php include __DIR__ . '/../partials/sounding-board-modal.php'; ?>
    <?php include __DIR__ . '/../partials/onboarding-wizard.php'; ?>
    <script src="/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../../public/assets/js/app.js') ?: '1' ?>"></script>
</body>
</html>
