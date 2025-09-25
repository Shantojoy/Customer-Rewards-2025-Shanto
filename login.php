<?php
require_once __DIR__ . '/config.php';

if (current_admin()) {
    redirect('admin.php');
}

$errors = [];
$username = '';

if (is_post()) {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $errors[] = 'Username and password are required.';
    } else {
        $stmt = db()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($admin && hash_equals($admin['password'], sha2_hash($password))) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['last_activity'] = time();
            record_activity($admin['id'], 'login', 'Admin logged in');
            redirect('admin.php');
        } else {
            $errors[] = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login • Rewards System</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="h-full bg-slate-900 text-white flex items-center justify-center">
    <div class="max-w-md w-full bg-slate-800/70 rounded-3xl shadow-2xl border border-slate-700 p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Rewards Admin</h1>
            <button id="toggle-theme" class="px-3 py-1 text-sm rounded-full bg-slate-700">Toggle</button>
        </div>
        <p class="text-slate-300 text-sm mb-6">Sign in to manage customers, points, and rewards.</p>

        <?php if ($errors): ?>
            <div class="mb-4 bg-red-500/10 border border-red-500 text-red-200 px-4 py-3 rounded-xl">
                <ul class="space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div>
                <label class="block text-sm font-medium mb-2">Username</label>
                <input type="text" name="username" value="<?= h($username) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3" required autofocus>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">Password</label>
                <input type="password" name="password" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl">Sign In</button>
        </form>
        <div class="mt-4 flex items-center justify-between text-sm text-slate-400">
            <a href="reset_password.php" class="text-blue-400 hover:text-blue-300">Forgot Password?</a>
            <a href="index.php" class="hover:text-white">Back to kiosk</a>
        </div>
    </div>
<script>
const toggle = document.getElementById('toggle-theme');
const root = document.documentElement;
if (localStorage.getItem('theme') === 'light') {
    root.classList.remove('dark');
    document.body.classList.remove('bg-slate-900','text-white');
    document.body.classList.add('bg-slate-100','text-slate-900');
}
toggle.addEventListener('click', () => {
    if (document.body.classList.contains('bg-slate-900')) {
        document.body.classList.remove('bg-slate-900','text-white');
        document.body.classList.add('bg-slate-100','text-slate-900');
        localStorage.setItem('theme', 'light');
    } else {
        document.body.classList.add('bg-slate-900','text-white');
        document.body.classList.remove('bg-slate-100','text-slate-900');
        localStorage.setItem('theme', 'dark');
    }
});
</script>
</body>
</html>
