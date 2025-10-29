<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        h1 { color: #1a202c; }
        .summary { background: #f7fafc; padding: 1.5rem; border-radius: 0.5rem; margin: 1rem 0; }
        .metric { background: #edf2f7; padding: 1rem; margin: 0.5rem 0; border-radius: 0.375rem; }
        .metric h3 { margin: 0 0 0.5rem 0; color: #2d3748; }
        .score { font-size: 2rem; font-weight: bold; color: #2b6cb0; }
        .result { border: 1px solid #e2e8f0; padding: 1rem; margin: 1rem 0; border-radius: 0.375rem; }
        .input { color: #4a5568; font-weight: 600; }
        .output { color: #2d3748; margin: 0.5rem 0; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem; margin-top: 0.5rem; }
        .metric-badge { background: #bee3f8; padding: 0.25rem 0.75rem; border-radius: 0.25rem; font-size: 0.875rem; }
    </style>
</head>
<body>
    <?= $content ?>
</body>
</html>
