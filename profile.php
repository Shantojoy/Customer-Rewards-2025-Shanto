<?php
require_once __DIR__ . '/config.php';
require_login();

$admin = current_admin();
$message = '';
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        if (!$username || !$email) {
            $errors[] = 'Username and email are required.';
        }
        if (!$errors) {
            $stmt = db()->prepare('UPDATE admins SET username = ?, email = ? WHERE id = ?');
            $stmt->bind_param('ssi', $username, $email, $admin['id']);
            if ($stmt->execute()) {
                $message = 'Profile updated successfully.';
                record_activity($admin['id'], 'profile_update', 'Updated profile details');
                $_SESSION['admin_id'] = $admin['id'];
            } else {
                $errors[] = 'Failed to update profile.';
            }
            $stmt->close();
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            $errors[] = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $stmt = db()->prepare('SELECT password FROM admins WHERE id = ?');
            $stmt->bind_param('i', $admin['id']);
            $stmt->execute();
            $hash = $stmt->get_result()->fetch_assoc()['password'] ?? '';
            $stmt->close();
            if (!hash_equals($hash, sha2_hash($current))) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $newHash = sha2_hash($new);
                $stmt = db()->prepare('UPDATE admins SET password = ? WHERE id = ?');
                $stmt->bind_param('si', $newHash, $admin['id']);
                if ($stmt->execute()) {
                    $message = 'Password updated successfully.';
                    record_activity($admin['id'], 'password_update', 'Password changed');
                } else {
                    $errors[] = 'Failed to update password.';
                }
                $stmt->close();
            }
        }
    }
    $admin = current_admin();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile • Rewards Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-950 text-slate-100 min-h-full">
<div class="max-w-3xl mx-auto py-12 px-6">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold">My Profile</h1>
            <p class="text-sm text-slate-400">Update your contact information and password.</p>
        </div>
        <a href="admin.php" class="text-sm text-blue-400">← Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="mb-4 bg-emerald-500/10 border border-emerald-500 text-emerald-200 px-4 py-3 rounded-xl text-sm">
            <?= h($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="mb-4 bg-red-500/10 border border-red-500 text-red-200 px-4 py-3 rounded-xl text-sm">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4">Profile Information</h2>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <div>
                <label class="block text-sm mb-1">Username</label>
                <input type="text" name="username" value="<?= h($admin['username']) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm mb-1">Email</label>
                <input type="email" name="email" value="<?= h($admin['email']) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Save Profile</button>
        </form>
    </div>

    <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
        <h2 class="text-xl font-semibold mb-4">Change Password</h2>
        <form method="post" class="grid gap-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">
            <div>
                <label class="block text-sm mb-1">Current Password</label>
                <input type="password" name="current_password" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm mb-1">New Password</label>
                <input type="password" name="new_password" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm mb-1">Confirm New Password</label>
                <input type="password" name="confirm_password" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-slate-900 font-semibold px-4 py-2 rounded-lg">Update Password</button>
        </form>
    </div>
</div>
</body>
</html>
