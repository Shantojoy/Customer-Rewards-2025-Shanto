<?php
require_once __DIR__ . '/config.php';
require_login();
require_role('superadmin');

$settings = global_settings();
$message = '';

if (is_post()) {
    verify_csrf();
    $autoPoints = max(0, (int)$_POST['auto_points_per_visit']);
    $stmt = db()->prepare('INSERT INTO settings (auto_points_per_visit) VALUES (?)');
    $stmt->bind_param('i', $autoPoints);
    if ($stmt->execute()) {
        $settings['auto_points_per_visit'] = $autoPoints;
        record_activity(current_admin()['id'], 'settings_update', "auto_points_per_visit={$autoPoints}");
        $message = 'Settings updated successfully.';
    } else {
        $message = 'Failed to update settings.';
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings • Rewards Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-950 text-slate-100 min-h-full">
<div class="max-w-3xl mx-auto py-12 px-6">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold">System Settings</h1>
            <p class="text-sm text-slate-400">Tune how points are awarded automatically.</p>
        </div>
        <a href="admin.php" class="text-sm text-blue-400">← Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="mb-6 bg-emerald-500/10 border border-emerald-500 text-emerald-200 px-4 py-3 rounded-xl text-sm">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
        <h2 class="text-xl font-semibold mb-4">Auto Points Per Visit</h2>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div>
                <label class="block text-sm mb-2">Points awarded for each visit</label>
                <input type="number" name="auto_points_per_visit" min="0" value="<?= (int)$settings['auto_points_per_visit'] ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2">
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Save Settings</button>
        </form>
        <p class="text-xs text-slate-500 mt-4">Cashiers can use the quick add button on the dashboard to apply this value instantly.</p>
    </div>
</div>
</body>
</html>
