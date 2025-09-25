<?php
require_once __DIR__ . '/config.php';

$message = '';
$errors = [];
$mode = isset($_GET['token']) ? 'reset' : 'request';
$token = $_GET['token'] ?? '';

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'request') {
        $email = trim($_POST['email'] ?? '');
        if (!$email) {
            $errors[] = 'Please enter your email.';
        } else {
            $stmt = db()->prepare('SELECT id, username FROM admins WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($admin) {
                $token = bin2hex(random_bytes(32));
                $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
                $stmt = db()->prepare('INSERT INTO password_resets (admin_id, token, expires_at) VALUES (?,?,?)');
                $stmt->bind_param('iss', $admin['id'], $token, $expires);
                $stmt->execute();
                $stmt->close();
                $message = 'A reset link has been generated. For demo purposes, use the token below.';
                record_activity($admin['id'], 'password_reset_requested', 'Reset token generated');
                $mode = 'token_display';
            } else {
                $errors[] = 'We could not find that email address.';
            }
        }
    } elseif ($action === 'reset') {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!$token || !$password || !$confirm) {
            $errors[] = 'All fields are required.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $stmt = db()->prepare('SELECT pr.admin_id FROM password_resets pr WHERE pr.token = ? AND pr.expires_at >= NOW() ORDER BY pr.created_at DESC LIMIT 1');
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                $errors[] = 'This reset link is invalid or expired.';
            } else {
                $hash = sha2_hash($password);
                $stmt = db()->prepare('UPDATE admins SET password = ? WHERE id = ?');
                $stmt->bind_param('si', $hash, $row['admin_id']);
                if ($stmt->execute()) {
                    $message = 'Password updated successfully. You may now log in.';
                    $mode = 'done';
                    record_activity($row['admin_id'], 'password_reset', 'Password reset via token');
                    $del = db()->prepare('DELETE FROM password_resets WHERE token = ?');
                    $del->bind_param('s', $token);
                    $del->execute();
                    $del->close();
                } else {
                    $errors[] = 'Failed to update password.';
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password • Rewards Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-950 text-slate-100 min-h-full flex items-center justify-center py-16">
<div class="w-full max-w-lg bg-slate-900/70 border border-slate-800 rounded-3xl p-8 shadow-2xl">
    <h1 class="text-2xl font-bold mb-2">Reset Password</h1>
    <p class="text-sm text-slate-400 mb-6">Enter your email to receive a reset link or update your password with a token.</p>

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

    <?php if ($mode === 'token_display' && isset($token)): ?>
        <div class="mb-6 bg-blue-500/10 border border-blue-500 text-blue-200 px-4 py-3 rounded-xl text-sm">
            Token: <code class="font-mono text-xs"><?= h($token) ?></code><br>
            Visit <span class="text-blue-300">/reset_password.php?token=<?= h($token) ?></span> to set a new password.
        </div>
        <a href="login.php" class="text-sm text-blue-400">Back to login</a>
    <?php elseif ($mode === 'done'): ?>
        <a href="login.php" class="text-sm text-blue-400">Sign in now</a>
    <?php elseif ($mode === 'reset'): ?>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div>
                <label class="block text-sm mb-1">New Password</label>
                <input type="password" name="password" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-xl">Update Password</button>
        </form>
    <?php else: ?>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="request">
            <div>
                <label class="block text-sm mb-1">Email Address</label>
                <input type="email" name="email" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-xl">Send Reset Link</button>
        </form>
        <a href="login.php" class="block text-center text-sm text-slate-400 mt-4">Back to login</a>
    <?php endif; ?>
</div>
</body>
</html>
