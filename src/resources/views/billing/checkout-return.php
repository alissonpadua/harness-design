<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout — <?= $ok ? 'complete' : 'not found' ?></title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #0b1120; color: #e2e8f0; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 16px; padding: 2.5rem; max-width: 32rem; text-align: center; }
        .dot { width: 56px; height: 56px; border-radius: 9999px; margin: 0 auto 1rem; display: grid; place-items: center; font-size: 28px; background: <?= $ok ? 'rgba(34,197,94,.15)' : 'rgba(239,68,68,.15)' ?>; color: <?= $ok ? '#22c55e' : '#ef4444' ?>; }
        p { color: #94a3b8; line-height: 1.6; font-size: 14px; }
        code { background: #1f2937; padding: 2px 6px; border-radius: 6px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="dot"><?= $ok ? '&#10003;' : '&#33;' ?></div>
        <h1><?= $ok ? 'Payment received' : 'Session not found' ?></h1>
        <p><?= htmlspecialchars($note, ENT_QUOTES) ?></p>
        <p>Tip: without a public webhook URL you can always run <code>php artisan billing:ingest</code>.</p>
    </div>
</body>
</html>
