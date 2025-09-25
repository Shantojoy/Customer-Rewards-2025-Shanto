<?php
require_once __DIR__ . '/config.php';

$errors = [];
$success = '';
$customer = null;
$isNewCustomer = false;
$pointsBalance = 0;
$progressPercent = 0;
$redeemedRewards = 0;
$pointsToward = 0;
$rewardThreshold = 200;
$rewardValue = 5;
$phoneInput = '';

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? 'lookup';
    $phoneInput = $_POST['phone'] ?? '';
    $phone = preg_replace('/[^0-9]/', '', $phoneInput);

    if (!$phone) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if (!$errors) {
        if ($action === 'lookup') {
            $stmt = db()->prepare('SELECT * FROM customers WHERE phone = ?');
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $result = $stmt->get_result();
            $customer = $result->fetch_assoc();
            $stmt->close();

            if ($customer) {
                $pointsBalance = total_points((int)$customer['id']);
                $redeemedRewards = total_redeemed_points((int)$customer['id']);
                $pointsToward = $pointsBalance % $rewardThreshold;
                if ($pointsToward < 0) {
                    $pointsToward = 0;
                }
                $progressPercent = min(100, ($pointsToward / $rewardThreshold) * 100);
                $stmt = db()->prepare('UPDATE customers SET last_visit = NOW() WHERE id = ?');
                $stmt->bind_param('i', $customer['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $isNewCustomer = true;
            }
        } elseif ($action === 'register') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if (!$name) {
                $errors[] = 'Name is required for new customers.';
            }
            if (!$errors) {
                $stmt = db()->prepare('INSERT INTO customers (phone, name, email, join_date, last_visit) VALUES (?, ?, ?, CURDATE(), NOW())');
                $stmt->bind_param('sss', $phone, $name, $email);
                if ($stmt->execute()) {
                    $customerId = $stmt->insert_id;
                    $customer = [
                        'id' => $customerId,
                        'phone' => $phone,
                        'name' => $name,
                        'email' => $email,
                    ];
                    $success = 'Welcome aboard! Points will be added during checkout.';
                    $isNewCustomer = false;
                    record_activity(null, 'customer_created', "Customer {$name} registered via kiosk");
                } else {
                    if ($stmt->errno === 1062) {
                        $errors[] = 'A customer with that phone number already exists. Please try again.';
                    } else {
                        $errors[] = 'Unable to create customer. Please try again later.';
                    }
                }
                $stmt->close();
            }
        }
    }
}

$settings = global_settings();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards Kiosk</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .kiosk-input { letter-spacing: 0.15em; }
    </style>
</head>
<body class="h-full bg-slate-900 text-white">
<div class="min-h-full flex flex-col items-center justify-center py-10">
    <div class="w-full max-w-4xl bg-slate-800/70 backdrop-blur rounded-3xl shadow-2xl border border-slate-700">
        <div class="px-8 py-10 flex flex-col items-center text-center">
            <img src="https://dummyimage.com/120x120/2563eb/ffffff&text=RP" alt="Rewards" class="w-24 h-24 rounded-full shadow-lg mb-6 border-4 border-blue-500">
            <h1 class="text-3xl md:text-4xl font-bold mb-2">Sparkle Cafe Rewards</h1>
            <p class="text-lg text-slate-300 mb-6">Every visit counts! Earn automatic points and unlock rewards faster.</p>

            <?php if (!empty($_SESSION['timeout'])): ?>
                <div class="mb-4 w-full bg-amber-500/10 border border-amber-400 text-amber-200 px-4 py-3 rounded-xl">
                    For your security we signed you out due to inactivity. Please continue below.
                </div>
                <?php unset($_SESSION['timeout']); ?>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="mb-6 w-full bg-red-500/10 border border-red-500 text-red-200 px-4 py-3 rounded-xl">
                    <ul class="list-disc list-inside text-left">
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-6 w-full bg-emerald-500/10 border border-emerald-500 text-emerald-200 px-4 py-3 rounded-xl">
                    <?= h($success) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="w-full" id="lookup-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="lookup" id="form-action">
                <div class="flex flex-col items-center gap-4">
                    <label class="text-xl font-semibold text-slate-200">Enter Your Phone Number to Earn Rewards</label>
                    <div class="relative">
                        <input type="text" name="phone" id="phone" value="<?= h($_POST['phone'] ?? '') ?>" maxlength="10"
                               class="kiosk-input text-3xl md:text-4xl px-6 py-4 rounded-2xl bg-slate-900/80 border border-slate-700 focus:border-blue-500 focus:ring focus:ring-blue-500/40 text-center tracking-[0.3em]" autocomplete="off" required>
                        <button type="button" id="clear" class="absolute right-3 top-3 text-slate-400 hover:text-white">Clear</button>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4 mt-8">
                    <?php foreach ([1,2,3,4,5,6,7,8,9,'←',0,'✔'] as $key): ?>
                        <button type="button" data-key="<?= h($key) ?>"
                                class="text-2xl font-semibold py-5 rounded-2xl bg-slate-700/70 hover:bg-blue-600 focus:bg-blue-700 transition-colors shadow-lg">
                            <?= h($key) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="mt-6 text-slate-300">Every 200 points = $<?= $rewardValue ?> reward • Auto add <?= (int)$settings['auto_points_per_visit'] ?> points each visit</p>
            </form>

            <?php if ($customer): ?>
                <div class="mt-10 w-full bg-slate-900/70 border border-blue-500/40 rounded-3xl p-8 text-left">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-white">Welcome back, <?= h($customer['name'] ?? 'Valued Guest') ?>!</h2>
                            <p class="text-slate-300 mt-1">Phone: <?= h($customer['phone'] ?? '') ?> • Email: <?= h($customer['email'] ?? '—') ?></p>
                        </div>
                        <div class="text-right">
                            <span class="text-5xl font-black text-emerald-400"><?= number_format($pointsBalance) ?></span>
                            <p class="text-slate-400">Current Points Balance</p>
                        </div>
                    </div>
                    <div class="mt-6">
                        <p class="uppercase text-sm text-slate-400 tracking-[0.3em] mb-2">Progress to next reward</p>
                        <div class="w-full h-4 rounded-full bg-slate-700">
                            <div class="h-4 rounded-full bg-blue-500 transition-all" style="width: <?= $progressPercent ?>%"></div>
                        </div>
                        <p class="mt-2 text-slate-300"><?= $pointsToward ?> / <?= $rewardThreshold ?> points toward your next $<?= $rewardValue ?> reward.</p>
                        <p class="mt-1 text-slate-400">Total rewards redeemed: $<?= number_format(floor($redeemedRewards / $rewardThreshold) * $rewardValue, 2) ?></p>
                    </div>
                </div>
            <?php elseif ($isNewCustomer): ?>
                <div class="mt-10 w-full bg-slate-900/70 border border-emerald-500/40 rounded-3xl p-8 text-left">
                    <h2 class="text-2xl font-semibold mb-4 text-emerald-300">Looks like you're new here!</h2>
                    <p class="text-slate-300 mb-6">Complete the quick form below to start earning rewards instantly.</p>
                    <form method="post" class="grid gap-6" id="new-customer-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="phone" value="<?= h($phoneInput) ?>">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Full Name</label>
                            <input type="text" name="name" class="w-full bg-slate-800 border border-slate-600 rounded-xl px-4 py-3 focus:ring-2 focus:ring-emerald-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Email (optional)</label>
                            <input type="email" name="email" class="w-full bg-slate-800 border border-slate-600 rounded-xl px-4 py-3 focus:ring-2 focus:ring-emerald-500" placeholder="you@email.com">
                        </div>
                        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-slate-900 font-semibold py-4 rounded-2xl text-lg shadow-lg">Create My Rewards Account</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="mt-10 text-sm text-slate-400 flex flex-col items-center gap-2">
                <p>Staff access? <a class="text-blue-400 hover:text-blue-300" href="login.php">Admin Login</a></p>
                <p class="text-xs">© <?= date('Y') ?> Sparkle Cafe. Rewards reset every 12 months of inactivity.</p>
            </div>
        </div>
    </div>
</div>
<script>
const phoneInput = document.getElementById('phone');
const buttons = document.querySelectorAll('[data-key]');
const form = document.getElementById('lookup-form');
const clearBtn = document.getElementById('clear');

buttons.forEach(btn => {
    btn.addEventListener('click', () => {
        const key = btn.dataset.key;
        if (key === '←') {
            phoneInput.value = phoneInput.value.slice(0, -1);
        } else if (key === '✔') {
            form.submit();
        } else if (phoneInput.value.length < 10) {
            phoneInput.value += key;
        }
        phoneInput.focus();
    });
});

clearBtn.addEventListener('click', () => {
    phoneInput.value = '';
    phoneInput.focus();
});
</script>
</body>
</html>
