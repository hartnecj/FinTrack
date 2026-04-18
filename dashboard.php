<?php
require_once __DIR__ . "/auth_guard.php";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/balance_helpers.php";

$user_id   = (int)($_SESSION["user_id"] ?? 0);
$name      = $_SESSION["user_name"] ?? "User";
$group_id  = (int)($_SESSION["active_group_id"] ?? 0);

$active_group = null;
$month_total = 0.00;
$last30_total = 0.00;
$month_count = 0;
$recent_expenses = [];
$balances = [];
$suggested_settlements = [];
$payment_history = [];
$group_members = [];
$error = '';
$success = '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($group_id <= 0) {
    $stmt = $pdo->prepare("
        SELECT g.id
        FROM group_members gm
        JOIN groups g ON gm.group_id = g.id
        WHERE gm.user_id = ?
        ORDER BY g.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $_SESSION["active_group_id"] = (int)$row["id"];
        header("Location: " . BASE_PATH . "/dashboard.php");
        exit;
    }
}

if ($group_id > 0) {
    $stmt = $pdo->prepare("
        SELECT g.id, g.name, g.owner_id
        FROM group_members gm
        JOIN groups g ON gm.group_id = g.id
        WHERE gm.user_id = ? AND g.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $group_id]);
    $active_group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$active_group) {
        unset($_SESSION["active_group_id"]);
        header("Location: " . BASE_PATH . "/dashboard.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $group_id > 0 && $active_group) {
    $posted_token = $_POST['csrf_token'] ?? '';

    if (!$posted_token || !hash_equals($_SESSION['csrf_token'], $posted_token)) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'record_settlement') {
            $payer_user_id = (int)($_POST['payer_user_id'] ?? 0);
            $payee_user_id = (int)($_POST['payee_user_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $payment_date = trim($_POST['payment_date'] ?? '');
            $note = trim($_POST['note'] ?? '');
            $noteForDb = ($note === '') ? null : $note;

            if ($payer_user_id <= 0 || $payee_user_id <= 0) {
                $error = "Please select both a payer and a payee.";
            } elseif ($payer_user_id === $payee_user_id) {
                $error = "Payer and payee cannot be the same person.";
            } elseif ($amount <= 0) {
                $error = "Payment amount must be greater than zero.";
            } elseif ($payment_date === '') {
                $error = "Please select a payment date.";
            } else {
                $memberStmt = $pdo->prepare("
                    SELECT user_id
                    FROM group_members
                    WHERE group_id = ?
                ");
                $memberStmt->execute([$group_id]);
                $memberIds = array_map('intval', $memberStmt->fetchAll(PDO::FETCH_COLUMN));

                if (!in_array($payer_user_id, $memberIds, true) || !in_array($payee_user_id, $memberIds, true)) {
                    $error = "Both users must belong to the active group.";
                } else {
                    $checkStmt = $pdo->prepare("
                        SELECT id
                        FROM settlements
                        WHERE group_id = ?
                          AND payer_user_id = ?
                          AND payee_user_id = ?
                          AND amount = ?
                          AND payment_date = ?
                          AND COALESCE(note, '') = COALESCE(?, '')
                        LIMIT 1
                    ");
                    $checkStmt->execute([
                        $group_id,
                        $payer_user_id,
                        $payee_user_id,
                        $amount,
                        $payment_date,
                        $noteForDb
                    ]);

                    if (!$checkStmt->fetch()) {
                        $stmt = $pdo->prepare("
                            INSERT INTO settlements (
                                group_id,
                                payer_user_id,
                                payee_user_id,
                                amount,
                                payment_date,
                                note,
                                created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $group_id,
                            $payer_user_id,
                            $payee_user_id,
                            $amount,
                            $payment_date,
                            $noteForDb,
                            $user_id
                        ]);
                    }

                    header("Location: " . BASE_PATH . "/dashboard.php?payment=recorded");
                    exit;
                }
            }
        }
    }
}

if (isset($_GET['payment']) && $_GET['payment'] === 'recorded') {
    $success = "Payment recorded successfully.";
}

if ($group_id > 0 && $active_group) {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total,
            COUNT(*) AS cnt
        FROM expenses
        WHERE group_id = ?
          AND expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND expense_date <= CURDATE()
    ");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $month_total = (float)($row['total'] ?? 0);
    $month_count = (int)($row['cnt'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE group_id = ?
          AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND expense_date <= CURDATE()
    ");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $last30_total = (float)($row['total'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT e.amount, e.category, e.description, e.expense_date, u.name AS created_by
        FROM expenses e
        JOIN users u ON e.user_id = u.id
        WHERE e.group_id = ?
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$group_id]);
    $recent_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $group_members = get_group_members($pdo, $group_id);
    $balances = get_group_balances($pdo, $group_id);
    $suggested_settlements = get_settlements_from_balances($balances);
    $payment_history = get_group_payment_history($pdo, $group_id, 10);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=6">
    <style>
        .balance-card {
            background: rgba(8, 12, 30, 0.78);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
        }

        .balance-table th,
        .balance-table td,
        .payment-table th,
        .payment-table td {
            color: #ffffff;
            vertical-align: middle;
        }

        .balance-table thead th,
        .payment-table thead th {
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }

        .balance-table tbody tr,
        .payment-table tbody tr {
            border-color: rgba(255,255,255,0.08);
        }

        .settlement-list li {
            margin-bottom: 8px;
            color: #ffffff;
        }

        .quick-stat {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .empty-note {
            color: rgba(255,255,255,0.75);
        }

        .form-label,
        .form-text,
        .card-title,
        .card-text,
        .text-white-soft {
            color: #ffffff;
        }

        .settlement-form .form-control,
        .settlement-form .form-select {
            background: rgba(255,255,255,0.95);
        }
    </style>
</head>
<body class="ft-page">
<nav>
    <ul>
        <li id="profile-btn"><a href="<?= BASE_PATH ?>/profile.php"><button class="btn">Profile</button></a></li>
        <li><a href="<?= BASE_PATH ?>/"><button class="btn">Home</button></a></li>
        <li><a href="<?= BASE_PATH ?>/dashboard.php"><button class="btn">Dashboard</button></a></li>
        <li><a href="<?= BASE_PATH ?>/budgets.php"><button class="btn">Budgets</button></a></li>
        <li><a href="<?= BASE_PATH ?>/expenses.php"><button class="btn">Expenses</button></a></li>
        <li><a href="<?= BASE_PATH ?>/groups.php"><button class="btn">Groups</button></a></li>
        <li><a href="<?= BASE_PATH ?>/messages.php"><button class="btn">Messages</button></a></li>
        <li><a href="<?= BASE_PATH ?>/auth/logout.php"><button class="btn">Logout</button></a></li>
    </ul>
</nav>

<section class="main-container shadow-lg">
    <div style="padding: 5%;">
        <h2>Dashboard</h2>
        <p>Welcome, <?php echo htmlspecialchars($name); ?>.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert" style="margin-top: 15px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="alert" style="margin-top: 15px;">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($group_id <= 0 || !$active_group): ?>
            <div class="alert alert-info" role="alert" style="margin-top: 20px;">
                You’re not in a group yet. Join or create a group to start tracking budgets and expenses.
            </div>

            <div class="d-grid gap-2" style="max-width: 300px; margin: 20px auto;">
                <a href="<?= BASE_PATH ?>/groups.php"><button class="btn w-100">Go to Groups</button></a>
                <a href="<?= BASE_PATH ?>/expenses.php"><button class="btn w-100">Expenses</button></a>
                <a href="<?= BASE_PATH ?>/budgets.php"><button class="btn w-100">Budgets</button></a>
            </div>
        <?php else: ?>
            <p style="margin-top: 10px;">
                <small>Active Group: <strong><?php echo htmlspecialchars($active_group['name']); ?></strong></small>
            </p>

            <div class="row" style="margin-top: 20px;">
                <div class="col-lg-6 mb-3">
                    <div class="card shadow-sm balance-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="card-title mb-1">Spending Breakdown</h5>
                                    <small class="empty-note">Last 6 months</small>
                                </div>
                                <div class="text-end">
                                    <small class="empty-note">Total</small>
                                    <div class="fw-bold text-white" id="ftPieTotal">$0.00</div>
                                </div>
                            </div>

                            <div class="mt-3" style="height: 320px;">
                                <canvas id="ftSpendingPie"></canvas>
                            </div>

                            <div class="mt-3" id="ftPieEmptyState" style="display:none;">
                                <div class="alert alert-secondary mb-0">
                                    No expenses found for this period yet.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row" style="margin-top: 20px;">
                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm balance-card">
                        <div class="card-body">
                            <h5 class="card-title">Month to Date</h5>
                            <p class="quick-stat text-white">$<?php echo htmlspecialchars(number_format($month_total, 2)); ?></p>
                            <p class="card-text"><small><?php echo htmlspecialchars((string)$month_count); ?> expense(s)</small></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm balance-card">
                        <div class="card-body">
                            <h5 class="card-title">Last 30 Days</h5>
                            <p class="quick-stat text-white">$<?php echo htmlspecialchars(number_format($last30_total, 2)); ?></p>
                            <p class="card-text"><small>Rolling total</small></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm balance-card">
                        <div class="card-body">
                            <h5 class="card-title">Quick Actions</h5>
                            <div class="d-grid gap-2">
                                <a href="<?= BASE_PATH ?>/expenses.php"><button class="btn w-100">Add Expense</button></a>
                                <a href="<?= BASE_PATH ?>/budgets.php"><button class="btn w-100">Create Budget</button></a>
                                <a href="<?= BASE_PATH ?>/groups.php"><button class="btn w-100">Manage Groups</button></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row" style="margin-top: 20px;">
                <div class="col-lg-12 mb-3">
                    <div class="card shadow-sm balance-card">
                        <div class="card-body">
                            <h5 class="card-title">Balances</h5>

                            <?php if (empty($balances)): ?>
                                <p class="empty-note mb-0">No balance data yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table balance-table" style="margin-top: 10px;">
                                        <thead>
                                            <tr>
                                                <th>Member</th>
                                                <th>Paid</th>
                                                <th>Owes</th>
                                                <th>Sent</th>
                                                <th>Received</th>
                                                <th>Net</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($balances as $b): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($b['name']); ?></td>
                                                    <td>$<?php echo number_format($b['total_paid'], 2); ?></td>
                                                    <td>$<?php echo number_format($b['current_owes'], 2); ?></td>
                                                    <td>$<?php echo number_format($b['total_sent'], 2); ?></td>
                                                    <td>$<?php echo number_format($b['total_received'], 2); ?></td>
                                                    <td>
                                                        <?php if ($b['net_balance'] > 0): ?>
                                                            <span class="text-success">Gets back $<?php echo number_format($b['net_balance'], 2); ?></span>
                                                        <?php elseif ($b['net_balance'] < 0): ?>
                                                            <span class="text-danger">Owes $<?php echo number_format(abs($b['net_balance']), 2); ?></span>
                                                        <?php else: ?>
                                                            <span>$0.00</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <h6 style="margin-top: 20px;" class="text-white">Suggested Settlements</h6>

                                <?php if (empty($suggested_settlements)): ?>
                                    <p class="empty-note mb-0">Everyone is settled up.</p>
                                <?php else: ?>
                                    <ul class="settlement-list" style="margin-top: 10px;">
                                        <?php foreach ($suggested_settlements as $s): ?>
                                            <li>
                                                <?php echo htmlspecialchars($s['from_name']); ?>
                                                owes
                                                <?php echo htmlspecialchars($s['to_name']); ?>
                                                $<?php echo number_format($s['amount'], 2); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row" style="margin-top: 20px;">
                <div class="col-lg-6 mb-3">
                    <div class="card shadow-sm balance-card">
                        <div class="card-body">
                            <h5 class="card-title">Record Payment</h5>
                            <p class="empty-note">Log a real payment so balances update automatically.</p>

                            <form method="POST" class="settlement-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="record_settlement">

                                <div class="mb-3">
                                    <label for="payer_user_id" class="form-label">Who paid?</label>
                                    <select class="form-select" id="payer_user_id" name="payer_user_id" required>
                                        <option value="">Select member</option>
                                        <?php foreach ($group_members as $member): ?>
                                            <option value="<?php echo (int)$member['id']; ?>">
                                                <?php echo htmlspecialchars($member['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="payee_user_id" class="form-label">Who received it?</label>
                                    <select class="form-select" id="payee_user_id" name="payee_user_id" required>
                                        <option value="">Select member</option>
                                        <?php foreach ($group_members as $member): ?>
                                            <option value="<?php echo (int)$member['id']; ?>">
                                                <?php echo htmlspecialchars($member['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="amount" class="form-label">Amount</label>
                                    <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required>
                                </div>

                                <div class="mb-3">
                                    <label for="payment_date" class="form-label">Payment date</label>
                                    <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label for="note" class="form-label">Note (optional)</label>
                                    <input type="text" class="form-control" id="note" name="note" maxlength="255" placeholder="Example: Venmo for groceries">
                                </div>

                                <button type="submit" class="btn">Record Payment</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 mb-3">
                    <div class="card shadow-sm balance-card">
                        <div class="card-body">
                            <h5 class="card-title">Payment History</h5>

                            <?php if (empty($payment_history)): ?>
                                <p class="empty-note mb-0">No payments have been recorded yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table payment-table" style="margin-top: 10px;">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Payment</th>
                                                <th>Amount</th>
                                                <th>Note</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payment_history as $payment): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($payment['payment_date']))); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($payment['payer_name']); ?>
                                                        paid
                                                        <?php echo htmlspecialchars($payment['payee_name']); ?>
                                                    </td>
                                                    <td>$<?php echo htmlspecialchars(number_format((float)$payment['amount'], 2)); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['note'] ?? ''); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <h4 style="margin-top: 30px;">Recent Expenses</h4>

            <?php if (empty($recent_expenses)): ?>
                <p style="margin-top: 10px;">No expenses yet. Add one to get started.</p>
            <?php else: ?>
                <table class="table table-striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Added By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_expenses as $e): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime($e['expense_date']))); ?></td>
                                <td>$<?php echo htmlspecialchars(number_format((float)$e['amount'], 2)); ?></td>
                                <td><?php echo htmlspecialchars($e['category'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($e['description'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($e['created_by']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<footer>
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" id="styleSwitch">
        <label class="form-check-label" for="styleSwitch" id="styleLabel"> Light mode: On </label>
    </div>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(() => {
    const canvas = document.getElementById("ftSpendingPie");
    if (!canvas) return;

    const totalEl = document.getElementById("ftPieTotal");
    const emptyEl = document.getElementById("ftPieEmptyState");

    const range = "180d";
    fetch(`<?= BASE_PATH ?>/backend/expenses_pie_chart.php?range=${encodeURIComponent(range)}`)
        .then((res) => res.json())
        .then((data) => {
            if (data.error) {
                canvas.parentElement.style.display = "none";
                if (emptyEl) {
                    emptyEl.style.display = "block";
                    emptyEl.innerHTML = `<div class="alert alert-warning mb-0">${data.error}</div>`;
                }
                return;
            }

            const labels = data.labels || [];
            const values = data.values || [];
            const total = Number(data.total || 0);

            if (totalEl) totalEl.textContent = `$${total.toFixed(2)}`;

            if (!labels.length) {
                canvas.parentElement.style.display = "none";
                if (emptyEl) emptyEl.style.display = "block";
                return;
            }

            if (window.ftSpendingChart) {
                window.ftSpendingChart.destroy();
            }

            window.ftSpendingChart = new Chart(canvas.getContext("2d"), {
                type: "doughnut",
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: "65%",
                    plugins: {
                        legend: {
                            position: "bottom",
                            labels: {
                                color: "#ffffff",
                                font: { size: 14 }
                            }
                        },
                        tooltip: {
                            titleFont: { size: 18, weight: "bold" },
                            bodyFont: { size: 16 },
                            padding: 12,
                            callbacks: {
                                label: function (context) {
                                    const v = Number(context.raw || 0);
                                    return `${context.label}: $${v.toFixed(2)}`;
                                }
                            }
                        }
                    }
                }
            });
        })
        .catch(() => {
            canvas.parentElement.style.display = "none";
            if (emptyEl) {
                emptyEl.style.display = "block";
                emptyEl.innerHTML = `<div class="alert alert-warning mb-0">Unable to load chart data.</div>`;
            }
        });
})();
</script>

<script src="<?= BASE_PATH ?>/assets/pageCustomization.js"></script>
</body>
</html>