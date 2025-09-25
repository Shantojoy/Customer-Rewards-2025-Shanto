<?php
require_once __DIR__ . '/config.php';
require_login();
require_role('superadmin');

$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pagination = paginate($page, $perPage);

$message = '';
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $role = $_POST['role'] ?? 'cashier';

        if (!$username || !$email || !$password) {
            $errors[] = 'All fields are required to create a user.';
        }

        if (!$errors) {
            $hashed = sha2_hash($password);
            $stmt = db()->prepare('INSERT INTO admins (username, email, password, role) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $username, $email, $hashed, $role);
            if ($stmt->execute()) {
                $message = 'User created successfully.';
                record_activity(current_admin()['id'], 'user_create', "Created user {$username}");
            } else {
                if ($stmt->errno === 1062) {
                    $errors[] = 'Username or email already exists.';
                } else {
                    $errors[] = 'Unable to create user.';
                }
            }
            $stmt->close();
        }
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = $_POST['role'] ?? 'cashier';
        $password = $_POST['password'] ?? '';

        if (!$id || !$username || !$email) {
            $errors[] = 'Username and email are required.';
        }

        if (!$errors) {
            if ($password) {
                $hashed = sha2_hash($password);
                $stmt = db()->prepare('UPDATE admins SET username = ?, email = ?, password = ?, role = ? WHERE id = ?');
                $stmt->bind_param('ssssi', $username, $email, $hashed, $role, $id);
            } else {
                $stmt = db()->prepare('UPDATE admins SET username = ?, email = ?, role = ? WHERE id = ?');
                $stmt->bind_param('sssi', $username, $email, $role, $id);
            }
            if ($stmt->execute()) {
                $message = 'User updated successfully.';
                record_activity(current_admin()['id'], 'user_update', "Updated user #{$id}");
            } else {
                $errors[] = 'Failed to update user.';
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === current_admin()['id']) {
            $errors[] = 'You cannot delete your own account.';
        } else {
            $stmt = db()->prepare('DELETE FROM admins WHERE id = ?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $message = 'User deleted successfully.';
                record_activity(current_admin()['id'], 'user_delete', "Deleted user #{$id}");
            } else {
                $errors[] = 'Unable to delete user.';
            }
            $stmt->close();
        }
    }
}

$totalRows = (int)db()->query('SELECT COUNT(*) AS c FROM admins')->fetch_assoc()['c'];

$stmt = db()->prepare('SELECT * FROM admins ORDER BY created_at DESC LIMIT ? OFFSET ?');
$stmt->bind_param('ii', $pagination['limit'], $pagination['offset']);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages = (int)ceil($totalRows / $perPage);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management • Rewards Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="min-h-full bg-slate-950 text-slate-100">
<div class="max-w-6xl mx-auto py-12 px-6">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold">Team Members</h1>
            <p class="text-sm text-slate-400">Create, edit, and remove admin access.</p>
        </div>
        <a href="admin.php" class="text-sm text-blue-400">← Back to Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="mb-6 bg-emerald-500/10 border border-emerald-500 text-emerald-200 px-4 py-3 rounded-xl text-sm">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="mb-6 bg-red-500/10 border border-red-500 text-red-200 px-4 py-3 rounded-xl text-sm">
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 mb-10">
        <h2 class="text-xl font-semibold mb-4">Add Team Member</h2>
        <form method="post" class="grid md:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-sm mb-1">Username</label>
                <input type="text" name="username" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm mb-1">Email</label>
                <input type="email" name="email" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm mb-1">Password</label>
                <input type="password" name="password" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm mb-1">Role</label>
                <select name="role" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2">
                    <option value="cashier">Cashier</option>
                    <option value="superadmin">Superadmin</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg">Create User</button>
            </div>
        </form>
    </div>

    <div class="bg-slate-900/60 border border-slate-800 rounded-2xl">
        <div class="p-6 border-b border-slate-800 flex items-center justify-between">
            <h2 class="text-xl font-semibold">Existing Users</h2>
            <p class="text-xs text-slate-500">Total: <?= number_format($totalRows) ?></p>
        </div>
        <div class="divide-y divide-slate-800">
            <?php foreach ($users as $user): ?>
            <div class="p-6">
                <form method="post" class="grid md:grid-cols-5 gap-4 items-end">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-400">Username</label>
                        <input type="text" name="username" value="<?= h($user['username']) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-400">Email</label>
                        <input type="email" name="email" value="<?= h($user['email']) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-400">New Password</label>
                        <input type="password" name="password" placeholder="Leave blank" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-slate-400">Role</label>
                        <select name="role" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-sm">
                            <option value="cashier" <?= $user['role'] === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                            <option value="superadmin" <?= $user['role'] === 'superadmin' ? 'selected' : '' ?>>Superadmin</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 px-4 py-2 rounded-lg text-sm">Save</button>
                        <?php if ($user['id'] !== current_admin()['id']): ?>
                        <button type="button" data-user="<?= (int)$user['id'] ?>" class="delete-btn text-red-400 text-sm">Delete</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="p-6 flex items-center justify-between text-sm text-slate-400">
            <div>Page <?= $page ?> of <?= $totalPages ?></div>
            <div class="flex gap-2">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a class="px-3 py-1 rounded-lg <?= $i === $page ? 'bg-blue-600 text-white' : 'border border-slate-700' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<form method="post" id="delete-form" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete-id" value="">
</form>

<script>
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
            document.getElementById('delete-id').value = btn.dataset.user;
            document.getElementById('delete-form').submit();
        }
    });
});
</script>
</body>
</html>
