<?php
require_once __DIR__ . '/config.php';
require_login();

$admin = current_admin();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$fromDate = DateTime::createFromFormat('Y-m-d', $from) ?: new DateTime('first day of this month');
$toDate = DateTime::createFromFormat('Y-m-d', $to) ?: new DateTime();
$toDate->setTime(23, 59, 59);

$fromSql = $fromDate->format('Y-m-d 00:00:00');
$toSql = $toDate->format('Y-m-d H:i:s');

// Points added by date
$stmt = db()->prepare("SELECT DATE(created_at) AS d, SUM(points) AS total FROM points_transactions WHERE transaction_type IN ('add','edit') AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d");
$stmt->bind_param('ss', $fromSql, $toSql);
$stmt->execute();
$pointsAdded = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Points redeemed by date
$stmt = db()->prepare("SELECT DATE(created_at) AS d, SUM(points) AS total FROM points_transactions WHERE transaction_type = 'redeem' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d");
$stmt->bind_param('ss', $fromSql, $toSql);
$stmt->execute();
$pointsRedeemed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Points by admin
$stmt = db()->prepare("SELECT a.username, SUM(CASE WHEN pt.transaction_type IN ('add','edit') THEN pt.points ELSE 0 END) AS added, SUM(CASE WHEN pt.transaction_type = 'redeem' THEN pt.points ELSE 0 END) AS redeemed FROM points_transactions pt LEFT JOIN admins a ON pt.admin_id = a.id WHERE pt.created_at BETWEEN ? AND ? GROUP BY a.username ORDER BY a.username");
$stmt->bind_param('ss', $fromSql, $toSql);
$stmt->execute();
$pointsByAdmin = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// New customers by date
$stmt = db()->prepare("SELECT join_date AS d, COUNT(*) AS total FROM customers WHERE join_date BETWEEN ? AND ? GROUP BY join_date ORDER BY join_date");
$stmt->bind_param('ss', $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
$stmt->execute();
$newCustomers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Active customers in range
$stmt = db()->prepare('SELECT COUNT(*) AS total FROM customers WHERE last_visit BETWEEN ? AND ?');
$stmt->bind_param('ss', $fromSql, $toSql);
$stmt->execute();
$activeCustomers = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$totalPointsAdded = array_sum(array_column($pointsAdded, 'total'));
$totalPointsRedeemed = array_sum(array_column($pointsRedeemed, 'total'));
$totalNewCustomers = array_sum(array_column($newCustomers, 'total'));

$export = isset($_GET['export']) ? $_GET['export'] : '';
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reports.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Total Points Added', $totalPointsAdded]);
    fputcsv($out, ['Total Points Redeemed', $totalPointsRedeemed]);
    fputcsv($out, ['New Customers', $totalNewCustomers]);
    fputcsv($out, ['Active Customers', $activeCustomers]);
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports • Rewards Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-950 text-slate-100 min-h-full">
<div class="max-w-6xl mx-auto py-12 px-6">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold">Reports & Analytics</h1>
            <p class="text-sm text-slate-400">Visualize points, redemptions, and customer trends.</p>
        </div>
        <div class="flex gap-3">
            <a href="admin.php" class="text-sm text-blue-400">← Dashboard</a>
            <a href="?export=csv&from=<?= h($fromDate->format('Y-m-d')) ?>&to=<?= h($toDate->format('Y-m-d')) ?>" class="text-sm bg-blue-600 px-4 py-2 rounded-lg">Export CSV</a>
        </div>
    </div>

    <form method="get" class="grid md:grid-cols-4 gap-4 mb-10 text-sm">
        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-400">From</label>
            <input type="date" name="from" value="<?= h($fromDate->format('Y-m-d')) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-slate-400">To</label>
            <input type="date" name="to" value="<?= h($toDate->format('Y-m-d')) ?>" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2">
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Apply</button>
            <a href="reports.php" class="px-4 py-2 rounded-lg border border-slate-700">Reset</a>
        </div>
    </form>

    <div class="grid gap-6 md:grid-cols-2">
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
            <h2 class="text-lg font-semibold mb-4">Points Added</h2>
            <canvas id="chartAdded" height="220"></canvas>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
            <h2 class="text-lg font-semibold mb-4">Points Redeemed</h2>
            <canvas id="chartRedeemed" height="220"></canvas>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
            <h2 class="text-lg font-semibold mb-4">New Customers</h2>
            <canvas id="chartNewCustomers" height="220"></canvas>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
            <h2 class="text-lg font-semibold mb-4">Points by Admin</h2>
            <canvas id="chartAdmins" height="220"></canvas>
        </div>
    </div>

    <div class="mt-10 bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
        <h2 class="text-lg font-semibold mb-4">Totals</h2>
        <div class="grid md:grid-cols-4 gap-4 text-sm">
            <div class="bg-slate-800/60 rounded-xl px-4 py-3">
                <p class="text-slate-400">Points Added</p>
                <p class="text-2xl font-semibold text-emerald-300"><?= number_format($totalPointsAdded) ?></p>
            </div>
            <div class="bg-slate-800/60 rounded-xl px-4 py-3">
                <p class="text-slate-400">Points Redeemed</p>
                <p class="text-2xl font-semibold text-red-300"><?= number_format($totalPointsRedeemed) ?></p>
            </div>
            <div class="bg-slate-800/60 rounded-xl px-4 py-3">
                <p class="text-slate-400">New Customers</p>
                <p class="text-2xl font-semibold text-blue-300"><?= number_format($totalNewCustomers) ?></p>
            </div>
            <div class="bg-slate-800/60 rounded-xl px-4 py-3">
                <p class="text-slate-400">Active Customers</p>
                <p class="text-2xl font-semibold text-amber-300"><?= number_format($activeCustomers) ?></p>
            </div>
        </div>
    </div>
</div>

<script>
const pointsAdded = <?= json_encode($pointsAdded, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
const pointsRedeemed = <?= json_encode($pointsRedeemed, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
const pointsByAdmin = <?= json_encode($pointsByAdmin, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
const newCustomers = <?= json_encode($newCustomers, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

function buildDataset(records, label) {
    return {
        labels: records.map(r => r.d),
        datasets: [{
            label: label,
            data: records.map(r => Number(r.total)),
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.2)',
            tension: 0.4,
            fill: true,
            pointRadius: 3
        }]
    };
}

const addedData = buildDataset(pointsAdded, 'Points Added');
new Chart(document.getElementById('chartAdded'), {
    type: 'line',
    data: addedData,
    options: { responsive: true, maintainAspectRatio: false }
});

const redeemedData = buildDataset(pointsRedeemed, 'Points Redeemed');
redeemedData.datasets[0].borderColor = '#ef4444';
redeemedData.datasets[0].backgroundColor = 'rgba(239,68,68,0.2)';
new Chart(document.getElementById('chartRedeemed'), {
    type: 'line',
    data: redeemedData,
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('chartNewCustomers'), {
    type: 'bar',
    data: {
        labels: newCustomers.map(r => r.d),
        datasets: [{
            label: 'New Customers',
            data: newCustomers.map(r => Number(r.total)),
            backgroundColor: '#10b981'
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('chartAdmins'), {
    type: 'bar',
    data: {
        labels: pointsByAdmin.map(r => r.username || 'System'),
        datasets: [
            {
                label: 'Added',
                data: pointsByAdmin.map(r => Number(r.added)),
                backgroundColor: '#2563eb'
            },
            {
                label: 'Redeemed',
                data: pointsByAdmin.map(r => Number(r.redeemed)),
                backgroundColor: '#ef4444'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { x: { stacked: true }, y: { stacked: true } }
    }
});
</script>
</body>
</html>
