<?php
require_once __DIR__ . '/config.php';
require_login();

$admin = current_admin();

$range = $_GET['range'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$now = new DateTime();
if ($range === 'today') {
    $from = $now->format('Y-m-d');
    $to = $now->format('Y-m-d');
} elseif ($range === 'week') {
    $start = (clone $now)->modify('monday this week');
    $end = (clone $start)->modify('+6 days');
    $from = $start->format('Y-m-d');
    $to = $end->format('Y-m-d');
} elseif ($range === 'month') {
    $from = $now->format('Y-m-01');
    $to = $now->format('Y-m-t');
}

if (!$from) {
    $from = $now->format('Y-m-01');
}
if (!$to) {
    $to = $now->format('Y-m-d');
}

$fromDate = DateTime::createFromFormat('Y-m-d', $from) ?: $now;
$toDate = DateTime::createFromFormat('Y-m-d', $to) ?: $now;
$toDate->setTime(23, 59, 59);

$fromSql = $fromDate->format('Y-m-d 00:00:00');
$toSql = $toDate->format('Y-m-d H:i:s');

// Metrics
$totalCustomers = (int)db()->query('SELECT COUNT(*) AS c FROM customers')->fetch_assoc()['c'];

$stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('add','edit') THEN points ELSE 0 END),0) AS given, COALESCE(SUM(CASE WHEN transaction_type = 'redeem' THEN points ELSE 0 END),0) AS redeemed, COALESCE(SUM(CASE WHEN transaction_type IN ('add','edit') THEN points WHEN transaction_type IN ('redeem','subtract') THEN -points ELSE 0 END),0) AS outstanding FROM points_transactions WHERE created_at BETWEEN ? AND ?");
$stmt->bind_param('ss', $fromSql, $toSql);
$stmt->execute();
$metrics = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalGiven = (int)$metrics['given'];
$totalRedeemed = (int)$metrics['redeemed'];
$outstanding = (int)$metrics['outstanding'];
$totalRedeemedValue = floor($totalRedeemed / 200) * 5;

$stmt = db()->prepare('SELECT COUNT(*) AS c FROM customers WHERE join_date BETWEEN ? AND ?');
$stmt->bind_param('ss', $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
$stmt->execute();
$newCustomers = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Customer search
$search = trim($_GET['search'] ?? '');
$searchResults = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = db()->prepare('SELECT id, name, phone, email FROM customers WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? ORDER BY name LIMIT 15');
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $searchResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$selectedCustomer = null;
$customerTransactions = [];

if (!empty($_GET['customer_id'])) {
    $customerId = (int)$_GET['customer_id'];
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $selectedCustomer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selectedCustomer) {
        $pointsBalance = total_points($customerId);
        $selectedCustomer['points_balance'] = $pointsBalance;
        $selectedCustomer['redeemed_points'] = total_redeemed_points($customerId);

        $stmt = db()->prepare('SELECT pt.*, a.username FROM points_transactions pt LEFT JOIN admins a ON pt.admin_id = a.id WHERE pt.customer_id = ? ORDER BY pt.created_at DESC LIMIT 5');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customerTransactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$transactionMessage = '';
if (is_post()) {
    verify_csrf();
    if (isset($_POST['transaction_action'])) {
        $customerId = (int)$_POST['customer_id'];
        $type = $_POST['transaction_action'];
        $points = (int)$_POST['points'];
        if (!$customerId || !$points) {
            $transactionMessage = 'Customer and points are required.';
        } else {
            $allowedTypes = ['add','redeem','subtract'];
            if (!in_array($type, $allowedTypes, true)) {
                $transactionMessage = 'Invalid transaction type.';
            } else {
                if ($type === 'redeem') {
                    $currentBalance = total_points($customerId);
                    if ($points > $currentBalance) {
                        $transactionMessage = 'Cannot redeem more points than the current balance.';
                    }
                }
                if (!$transactionMessage) {
                    $stmt = db()->prepare('INSERT INTO points_transactions (customer_id, admin_id, transaction_type, points) VALUES (?,?,?,?)');
                    $stmt->bind_param('iisi', $customerId, $admin['id'], $type, $points);
                    if ($stmt->execute()) {
                        $transactionMessage = 'Transaction recorded successfully.';
                        record_activity($admin['id'], 'points_' . $type, "{$points} points for customer #{$customerId}");
                        redirect('admin.php?customer_id=' . $customerId . '&success=1');
                    } else {
                        $transactionMessage = 'Failed to record transaction.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

if (isset($_GET['success'])) {
    $transactionMessage = 'Transaction recorded successfully.';
}

$settings = global_settings();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard • Rewards Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            blue: '#2563eb',
                            green: '#10b981',
                            red: '#ef4444'
                        }
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-950 text-slate-100 min-h-full">
<div class="min-h-screen flex">
    <aside class="hidden lg:flex lg:flex-col w-72 bg-slate-900 border-r border-slate-800">
        <div class="px-6 py-6 border-b border-slate-800 flex items-center justify-between">
            <span class="text-xl font-bold">Sparkle Rewards</span>
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2 text-sm">
            <a href="admin.php" class="flex items-center gap-3 px-3 py-2 rounded-xl bg-blue-600/20 text-blue-300">Dashboard</a>
            <?php if (is_superadmin()): ?>
            <a href="users.php" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-slate-800">Users</a>
            <?php endif; ?>
            <a href="customers.php" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-slate-800">Customers</a>
            <a href="reports.php" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-slate-800">Reports</a>
            <?php if (is_superadmin()): ?>
            <a href="settings.php" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-slate-800">Settings</a>
            <?php endif; ?>
            <a href="profile.php" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-slate-800">My Profile</a>
        </nav>
        <div class="px-6 py-4 border-t border-slate-800 text-xs text-slate-400">
            Logged in as <span class="text-slate-200 font-semibold"><?= h($admin['username']) ?></span><br>
            Role: <?= h($admin['role']) ?><br>
            <form action="logout.php" method="post" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <button type="submit" class="w-full bg-red-500/20 text-red-300 px-3 py-2 rounded-lg">Sign out</button>
            </form>
        </div>
    </aside>
    <main class="flex-1">
        <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 px-6 py-6 border-b border-slate-800 bg-slate-900/60 backdrop-blur">
            <div>
                <h1 class="text-2xl font-bold">Dashboard</h1>
                <p class="text-sm text-slate-400">View real-time customer activity and manage rewards.</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="get" class="flex items-center gap-2 bg-slate-900 border border-slate-800 rounded-xl px-3 py-2">
                    <input type="hidden" name="customer_id" value="<?= h($_GET['customer_id'] ?? '') ?>">
                    <input type="search" name="search" value="<?= h($search) ?>" placeholder="Search customers"
                           class="bg-transparent focus:outline-none text-sm">
                    <button class="text-blue-400" type="submit">Search</button>
                </form>
                <button id="themeToggle" class="px-3 py-2 rounded-xl border border-slate-700 text-sm">Toggle Theme</button>
            </div>
        </header>

        <section class="px-6 py-6 border-b border-slate-900/60 bg-slate-900/30">
            <form method="get" class="grid md:grid-cols-5 gap-4 text-sm items-end">
                <div>
                    <label class="block text-xs uppercase tracking-wide text-slate-400">From</label>
                    <input type="date" name="from" value="<?= h($fromDate->format('Y-m-d')) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-slate-400">To</label>
                    <input type="date" name="to" value="<?= h($toDate->format('Y-m-d')) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2">
                </div>
                <input type="hidden" name="customer_id" value="<?= h($_GET['customer_id'] ?? '') ?>">
                <div class="md:col-span-2 flex items-center gap-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Apply</button>
                    <a href="admin.php?range=today" class="px-3 py-2 rounded-lg border border-slate-700">Today</a>
                    <a href="admin.php?range=week" class="px-3 py-2 rounded-lg border border-slate-700">Week</a>
                    <a href="admin.php?range=month" class="px-3 py-2 rounded-lg border border-slate-700">Month</a>
                </div>
            </form>
        </section>

        <section class="px-6 py-8 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow">
                <p class="text-sm text-slate-400">Total Customers</p>
                <p class="text-3xl font-bold text-white mt-2"><?= number_format($totalCustomers) ?></p>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow">
                <p class="text-sm text-slate-400">Points Given</p>
                <p class="text-3xl font-bold text-emerald-400 mt-2"><?= number_format($totalGiven) ?></p>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow">
                <p class="text-sm text-slate-400">Points Redeemed</p>
                <p class="text-3xl font-bold text-red-400 mt-2"><?= number_format($totalRedeemed) ?></p>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow">
                <p class="text-sm text-slate-400">Outstanding Balance</p>
                <p class="text-3xl font-bold text-blue-400 mt-2"><?= number_format($outstanding) ?></p>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow">
                <p class="text-sm text-slate-400">Total Redemptions ($)</p>
                <p class="text-3xl font-bold text-yellow-300 mt-2">$<?= number_format($totalRedeemedValue, 2) ?></p>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow">
                <p class="text-sm text-slate-400">New Customers</p>
                <p class="text-3xl font-bold text-emerald-300 mt-2"><?= number_format($newCustomers) ?></p>
            </div>
        </section>

        <?php if ($searchResults): ?>
            <section class="px-6">
                <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
                    <h2 class="text-lg font-semibold mb-4">Search Results</h2>
                    <div class="space-y-3 text-sm">
                        <?php foreach ($searchResults as $row): ?>
                            <a href="admin.php?customer_id=<?= (int)$row['id'] ?>" class="flex justify-between items-center bg-slate-800/60 hover:bg-blue-600/20 px-4 py-3 rounded-xl">
                                <div>
                                    <p class="font-semibold text-white"><?= h($row['name']) ?></p>
                                    <p class="text-slate-400 text-xs">Phone: <?= h($row['phone']) ?> • <?= h($row['email']) ?></p>
                                </div>
                                <span class="text-blue-300">View</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($selectedCustomer): ?>
        <section class="px-6 py-8 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-semibold text-white"><?= h($selectedCustomer['name']) ?></h2>
                        <p class="text-sm text-slate-400">Phone: <?= h($selectedCustomer['phone']) ?> • Email: <?= h($selectedCustomer['email'] ?? '—') ?></p>
                        <p class="text-xs text-slate-500 mt-2">Joined <?= format_date($selectedCustomer['join_date'], 'M d, Y') ?> • Last visit <?= format_date($selectedCustomer['last_visit']) ?></p>
                        <p class="text-xs text-slate-500 mt-2">Notes: <?= h($selectedCustomer['notes'] ?? 'No notes') ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm uppercase tracking-[0.2em] text-slate-500">Points Balance</p>
                        <p class="text-4xl font-bold text-emerald-400"><?= number_format($selectedCustomer['points_balance']) ?></p>
                        <p class="text-xs text-slate-400 mt-1">Redeemed <?= number_format($selectedCustomer['redeemed_points']) ?> points total</p>
                    </div>
                </div>
                <div class="mt-6">
                    <h3 class="text-lg font-semibold mb-3">Recent Transactions</h3>
                    <div class="space-y-3">
                        <?php if (!$customerTransactions): ?>
                            <p class="text-sm text-slate-400">No transactions yet.</p>
                        <?php else: ?>
                            <?php foreach ($customerTransactions as $txn): ?>
                                <div class="flex justify-between items-center bg-slate-800/60 px-4 py-3 rounded-xl text-sm">
                                    <div>
                                        <p class="font-semibold capitalize text-white"><?= h($txn['transaction_type']) ?> <?= number_format($txn['points']) ?> pts</p>
                                        <p class="text-xs text-slate-400">By <?= h($txn['username'] ?? 'System') ?> • <?= format_date($txn['created_at']) ?></p>
                                    </div>
                                    <span class="text-slate-400">#<?= (int)$txn['id'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
                <h3 class="text-lg font-semibold mb-4">Record Transaction</h3>
                <?php if ($transactionMessage): ?>
                    <div class="mb-4 text-sm <?= strpos($transactionMessage, 'successfully') !== false ? 'bg-emerald-500/10 border border-emerald-500 text-emerald-200' : 'bg-red-500/10 border border-red-500 text-red-200' ?> px-4 py-3 rounded-xl">
                        <?= h($transactionMessage) ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="customer_id" value="<?= (int)$selectedCustomer['id'] ?>">
                    <label class="block text-sm">Transaction Type</label>
                    <div class="grid grid-cols-3 gap-2 text-sm">
                        <?php foreach (['add' => 'Add', 'redeem' => 'Redeem', 'subtract' => 'Subtract'] as $value => $label): ?>
                            <label class="flex items-center justify-center gap-2 px-3 py-2 bg-slate-800/80 rounded-xl">
                                <input type="radio" name="transaction_action" value="<?= $value ?>" <?= $value === 'add' ? 'checked' : '' ?>>
                                <?= $label ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Points</label>
                        <input type="number" name="points" min="1" value="<?= (int)$settings['auto_points_per_visit'] ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2" required>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-xl">Submit</button>
                </form>
                <p class="text-xs text-slate-500 mt-3">Quick add uses the system default of <?= (int)$settings['auto_points_per_visit'] ?> points per visit.</p>
            </div>
        </section>
        <?php elseif ($search && !$searchResults): ?>
            <section class="px-6 py-12">
                <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-8 text-center">
                    <h2 class="text-lg font-semibold">No customers found</h2>
                    <p class="text-sm text-slate-400 mt-2">Try searching with a different phone number or name.</p>
                </div>
            </section>
        <?php endif; ?>

        <section class="px-6 py-10">
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
                <h2 class="text-lg font-semibold mb-3">Team Activity</h2>
                <?php if (!is_superadmin()): ?>
                    <p class="text-sm text-slate-500">Limited to your recent actions.</p>
                <?php endif; ?>
                <div class="mt-4 space-y-3 text-sm">
                    <?php
                    if (is_superadmin()) {
                        $stmt = db()->prepare('SELECT a.username, l.action, l.details, l.created_at FROM activity_log l LEFT JOIN admins a ON l.admin_id = a.id ORDER BY l.created_at DESC LIMIT 10');
                    } else {
                        $stmt = db()->prepare('SELECT a.username, l.action, l.details, l.created_at FROM activity_log l LEFT JOIN admins a ON l.admin_id = a.id WHERE l.admin_id = ? ORDER BY l.created_at DESC LIMIT 10');
                        $stmt->bind_param('i', $admin['id']);
                    }
                    $stmt->execute();
                    $activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    ?>
                    <?php if (!$activity): ?>
                        <p class="text-slate-400">No recent activity.</p>
                    <?php else: ?>
                        <?php foreach ($activity as $item): ?>
                            <div class="flex justify-between items-start bg-slate-800/60 px-4 py-3 rounded-xl">
                                <div>
                                    <p class="font-semibold text-white"><?= h($item['username'] ?? 'System') ?> • <?= h(str_replace('_', ' ', $item['action'])) ?></p>
                                    <p class="text-xs text-slate-400 mt-1"><?= h($item['details'] ?? '') ?></p>
                                </div>
                                <span class="text-xs text-slate-500"><?= format_date($item['created_at']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>
<script>
const themeToggle = document.getElementById('themeToggle');
function applyTheme(theme) {
    if (theme === 'light') {
        document.body.classList.remove('bg-slate-950','text-slate-100');
        document.body.classList.add('bg-slate-100','text-slate-900');
    } else {
        document.body.classList.add('bg-slate-950','text-slate-100');
        document.body.classList.remove('bg-slate-100','text-slate-900');
    }
    localStorage.setItem('adminTheme', theme);
}
applyTheme(localStorage.getItem('adminTheme') || 'dark');
if (themeToggle) {
    themeToggle.addEventListener('click', () => {
        const current = localStorage.getItem('adminTheme') || 'dark';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    });
}
</script>
</body>
</html>
