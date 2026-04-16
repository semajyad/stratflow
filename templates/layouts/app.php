<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>StratFlow</title>

    <link rel="stylesheet" href="/assets/css/app.css?v=<?= ASSET_VERSION ?>">
    <script src="/assets/js/app-preload.js?v=<?= ASSET_VERSION ?>"></script>
</head>
<?php
$_uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$_bodyClass = 'app-layout';
if (str_starts_with($_uri, '/superadmin/') || $_uri === '/superadmin') { $_bodyClass .= ' superadmin-theme'; }
elseif (str_starts_with($_uri, '/app/admin/') || $_uri === '/app/admin') { $_bodyClass .= ' admin-theme'; }
?>
<body class="<?= htmlspecialchars($_bodyClass, ENT_QUOTES, 'UTF-8') ?>">
    <div class="app-wrapper">
        <?php require __DIR__ . '/../partials/sidebar.php'; ?>
        <div class="app-main">
            <header class="app-topbar">
                <button class="sidebar-toggle" id="sidebar-toggle" title="Toggle sidebar">&#9776;</button>
<?php require __DIR__ . '/../partials/workflow-stepper.php'; ?>
                <div class="topbar-right">
                    <img src="/assets/images/threepoints-logo.png" alt="ThreePoints" class="topbar-threepoints-logo">
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
    <script src="/assets/js/app.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
