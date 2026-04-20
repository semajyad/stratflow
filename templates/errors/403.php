<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - StratFlow</title>
    <style nonce="<?= htmlspecialchars(\StratFlow\Core\Response::getNonce(), ENT_QUOTES, 'UTF-8') ?>">
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .error-container {
            text-align: center;
            max-width: 480px;
            padding: 2rem;
        }
        .error-code {
            font-size: 5rem;
            font-weight: 700;
            color: #ffc107;
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        .error-message {
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.5;
        }
        .error-link {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #0d6efd;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
        }
        .error-link:hover { background: #0b5ed7; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">403</div>
        <h1 class="error-title">Access Denied</h1>
        <p class="error-message">
            You do not have permission to access this resource.
        </p>
        <a href="/" class="error-link">Return to Home</a>
    </div>
</body>
</html>
