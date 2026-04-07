<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StratFlow - ThreePoints Solutions</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="public-header">
        <div class="container">
            <a href="/" class="logo">StratFlow</a>
            <nav>
                <a href="/pricing">Pricing</a>
                <a href="/login">Login</a>
            </nav>
        </div>
    </header>
    <main class="container">
        <?php if ($flash = ($flash_message ?? null)): ?>
            <div class="flash-message"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </main>
    <footer class="public-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> ThreePoints Solutions</p>
        </div>
    </footer>
</body>
</html>
