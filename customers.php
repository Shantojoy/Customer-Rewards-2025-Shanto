<?php
require_once __DIR__ . '/config.php';
require_login();

$admin = current_admin();
$perPage = 12;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pagination = paginate($page, $perPage);

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';
$message = '';

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($status === 'new') {
    $where[] = 'join_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
} elseif ($status === 'active') {
    $where[] = 'last_visit >= DATE_SUB(NOW(), INTERVAL 45 DAY)';
} elseif ($status === 'inactive') {
    $where[] = '(last_visit IS NULL OR last_visit < DATE_SUB(NOW(), INTERVAL 90 DAY))';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = db()->prepare("SELECT name, phone, email, join_date, last_visit FROM customers {$whereSql} ORDER BY name");
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="customers.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Phone', 'Email', 'Join Date', 'Last Visit']);
    while ($row = $rows->fetch_assoc()) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone']);
        $notes = trim($_POST['notes']);
        if ($id && $name && $phone) {
            $stmt = db()->prepare('UPDATE customers SET name = ?, email = ?, phone = ?, notes = ? WHERE id = ?');
            $stmt->bind_param('ssssi', $name, $email, $phone, $notes, $id);
            if ($stmt->execute()) {
                record_activity($admin['id'], 'customer_update', "Updated customer #{$id}");
                $message = 'Customer updated successfully.';
            } else {
                $message = 'Failed to update customer.';
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare('DELETE FROM customers WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            record_activity($admin['id'], 'customer_delete', "Deleted customer #{$id}");
            $message = 'Customer deleted successfully.';
        } else {
            $message = 'Unable to delete customer.';
        }
        $stmt->close();
    }
}

$countSql = "SELECT COUNT(*) AS c FROM customers {$whereSql}";
if ($where) {
    $stmt = db()->prepare($countSql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $totalRows = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
} else {
    $totalRows = (int)db()->query($countSql)->fetch_assoc()['c'];
}

if ($where) {
    $stmt = db()->prepare("SELECT * FROM customers {$whereSql} ORDER BY last_visit IS NULL, last_visit DESC, name ASC LIMIT ? OFFSET ?");
    if ($types) {
        $typesWithLimit = $types . 'ii';
        $paramsWithLimit = array_merge($params, [$pagination['limit'], $pagination['offset']]);
        $stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
    } else {
        $stmt->bind_param('ii', $pagination['limit'], $pagination['offset']);
    }
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $stmt = db()->prepare('SELECT * FROM customers ORDER BY last_visit IS NULL, last_visit DESC, name ASC LIMIT ? OFFSET ?');
    $stmt->bind_param('ii', $pagination['limit'], $pagination['offset']);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$selectedCustomer = null;
$transactions = [];
if (!empty($_GET['customer_id'])) {
    $cid = (int)$_GET['customer_id'];
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $selectedCustomer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($selectedCustomer) {
        $selectedCustomer['points_balance'] = total_points($cid);
        $stmt = db()->prepare('SELECT pt.*, a.username FROM points_transactions pt LEFT JOIN admins a ON pt.admin_id = a.id WHERE pt.customer_id = ? ORDER BY pt.created_at DESC LIMIT 20');
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers • Rewards Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-950 text-slate-100 min-h-full">
<div class="max-w-6xl mx-auto py-12 px-6">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold">Customers</h1>
            <p class="text-sm text-slate-400">Manage customer profiles, notes, and history.</p>
        </div>
        <div class="flex gap-3">
            <a href="admin.php" class="text-sm text-blue-400">← Dashboard</a>
            <a href="?export=csv" class="text-sm bg-blue-600 px-4 py-2 rounded-lg">Export CSV</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="mb-6 bg-emerald-500/10 border border-emerald-500 text-emerald-200 px-4 py-3 rounded-xl text-sm">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <form method="get" class="grid md:grid-cols-4 gap-4 mb-8 text-sm">
        <div class="md:col-span-2">
            <label class="block text-xs uppercase tracking-wide text-slate-400">Search</label>
            <input type="search" name="search" value="<?= h($search) ?>" placeholder="Search by name, phone, or email" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-400">Segment</label>
            <select name="status" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Customers</option>
                <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>New (30 days)</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active (visited 45 days)</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive (90+ days)</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Filter</button>
            <a href="customers.php" class="px-4 py-2 rounded-lg border border-slate-700">Reset</a>
        </div>
    </form>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($customers as $cust): ?>
            <?php
            $statusLabel = 'Inactive';
            $statusColor = 'bg-red-500/20 text-red-300 border border-red-500/50';
            if (!$cust['last_visit']) {
                $statusLabel = 'New';
                $statusColor = 'bg-blue-500/20 text-blue-200 border border-blue-500/40';
            } else {
                $lastVisit = new DateTime($cust['last_visit']);
                $diff = $lastVisit->diff(new DateTime());
                if ($diff->days <= 30) {
                    $statusLabel = 'Active';
                    $statusColor = 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40';
                } elseif ($diff->days <= 90) {
                    $statusLabel = 'Engaged';
                    $statusColor = 'bg-blue-500/20 text-blue-200 border border-blue-500/40';
                }
            }
            ?>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 flex flex-col gap-3">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-white"><?= h($cust['name']) ?></h2>
                        <p class="text-sm text-slate-400">Phone: <?= h($cust['phone']) ?></p>
                        <p class="text-xs text-slate-500">Email: <?= h($cust['email'] ?? '—') ?></p>
                    </div>
                    <span class="text-xs px-3 py-1 rounded-full <?= $statusColor ?>"><?= $statusLabel ?></span>
                </div>
                <p class="text-xs text-slate-500">Joined <?= format_date($cust['join_date'], 'M d, Y') ?> • Last visit <?= format_date($cust['last_visit']) ?></p>
                <div class="flex items-center gap-3">
                    <a href="customers.php?customer_id=<?= (int)$cust['id'] ?>" class="text-sm text-blue-400">Details</a>
                    <button type="button" data-customer='<?= json_encode($cust, JSON_HEX_APOS | JSON_HEX_TAG) ?>' class="text-sm text-emerald-300 edit-btn">Edit</button>
                    <button type="button" data-id="<?= (int)$cust['id'] ?>" class="text-sm text-red-300 delete-btn">Delete</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="mt-8 flex items-center justify-between text-sm text-slate-400">
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <div class="flex gap-2">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a class="px-3 py-1 rounded-lg <?= $i === $page ? 'bg-blue-600 text-white' : 'border border-slate-700' ?>" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($selectedCustomer): ?>
        <div class="mt-12 bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-white"><?= h($selectedCustomer['name']) ?></h2>
                    <p class="text-sm text-slate-400">Phone: <?= h($selectedCustomer['phone']) ?> • Email: <?= h($selectedCustomer['email'] ?? '—') ?></p>
                    <p class="text-xs text-slate-500 mt-2">Points Balance: <?= number_format($selectedCustomer['points_balance']) ?> • Joined <?= format_date($selectedCustomer['join_date'], 'M d, Y') ?></p>
                    <p class="text-xs text-slate-500 mt-1">Notes: <?= nl2br(h($selectedCustomer['notes'] ?? 'No notes yet')) ?></p>
                </div>
                <a href="customers.php" class="text-sm text-slate-400">Close</a>
            </div>
            <h3 class="text-lg font-semibold mt-6 mb-3">Recent Transactions</h3>
            <div class="space-y-2 text-sm">
                <?php if (!$transactions): ?>
                    <p class="text-slate-400">No transactions yet.</p>
                <?php else: ?>
                    <?php foreach ($transactions as $txn): ?>
                        <div class="flex items-center justify-between bg-slate-800/60 px-4 py-3 rounded-xl">
                            <div>
                                <p class="font-semibold text-white capitalize"><?= h($txn['transaction_type']) ?> <?= number_format($txn['points']) ?> pts</p>
                                <p class="text-xs text-slate-400">By <?= h($txn['username'] ?? 'System') ?> • <?= format_date($txn['created_at']) ?></p>
                            </div>
                            <span class="text-xs text-slate-500">#<?= (int)$txn['id'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<form method="post" id="edit-form" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="edit-id">
    <input type="hidden" name="name" id="edit-name">
    <input type="hidden" name="email" id="edit-email">
    <input type="hidden" name="phone" id="edit-phone">
    <input type="hidden" name="notes" id="edit-notes">
</form>
<form method="post" id="delete-form" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete-id">
</form>

<script>
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (confirm('Delete this customer and all associated transactions?')) {
            document.getElementById('delete-id').value = btn.dataset.id;
            document.getElementById('delete-form').submit();
        }
    });
});

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const data = JSON.parse(btn.dataset.customer);
        const name = prompt('Customer name', data.name);
        if (!name) return;
        const email = prompt('Customer email', data.email || '');
        const phone = prompt('Phone (digits only)', data.phone);
        const notes = prompt('Notes', data.notes || '');
        document.getElementById('edit-id').value = data.id;
        document.getElementById('edit-name').value = name;
        document.getElementById('edit-email').value = email;
        document.getElementById('edit-phone').value = phone;
        document.getElementById('edit-notes').value = notes;
        document.getElementById('edit-form').submit();
    });
});
</script>
</body>
</html>
