<?php
/*
|--------------------------------------------------------------------------
| dashboard.php
|--------------------------------------------------------------------------
| Original:
| - Simple welcome page
|
| Enhancements added:
| - Correct asset paths to match /assets folder structure
| - Uses $_SESSION['active_group_id'] to show group-specific data
| - AUTO-SETS active_group_id if user is in a group but session isn't set yet
|   (this fixes: dashboard says "not in a group" until you visit Groups page)
| - Pulls real expense totals for dashboard tiles
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/auth_guard.php";
require_once __DIR__ . "/config/db.php";

$user_id   = (int)($_SESSION["user_id"] ?? 0);
$name      = $_SESSION["user_name"] ?? "User";

/*
|--------------------------------------------------------------------------
| Active group context
|--------------------------------------------------------------------------
| The rest of the app assumes an "active group" lives in session.
| groups.php sets this automatically, but dashboard should also do that
| so users see correct data immediately after login.
|--------------------------------------------------------------------------
*/
$group_id  = (int)($_SESSION["active_group_id"] ?? 0);

$error = '';
$active_group = null;

// Dashboard metrics (defaults)
$month_total = 0.00;
$last30_total = 0.00;
$month_count = 0;
$recent_expenses = [];

/*
|--------------------------------------------------------------------------
| AUTO-SET active group if missing
|--------------------------------------------------------------------------
| Why:
| - User can already be in a group, but session active_group_id may be empty
| - In that case dashboard would incorrectly show "not in a group"
| - This query picks the most recently created group the user belongs to
| - Then we store it in session and redirect (PRG style)
|--------------------------------------------------------------------------
*/
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
        header("Location: /dashboard.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Load active group (membership check)
|--------------------------------------------------------------------------
| Ensures user can only view dashboard data for a group they belong to.
| If the active group is stale/invalid, we clear it and reload.
|--------------------------------------------------------------------------
*/
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
        header("Location: /dashboard.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| If we have a valid active group, load dashboard metrics
|--------------------------------------------------------------------------
*/
if ($group_id > 0 && $active_group) {

    // Month-to-date totals + count
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

    // Last 30 days total
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

    // Recent expenses (top 5)
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-4.0.0.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- NOTE: updated CSS 2/16/26 -->
    <link rel="stylesheet" href="/assets/style.css?v=5">
</head>

<body class="ft-page">
<nav>
    <ul>
        <li><a href="/"><button class="btn">Home</button></a></li>
        <li><a href="/dashboard.php"><button class="btn">Dashboard</button></a></li>
        <li><a href="/budgets.php"><button class="btn">Budgets</button></a></li>
        <li><a href="/expenses.php"><button class="btn">Expenses</button></a></li>
        <li><a href="/groups.php"><button class="btn">Groups</button></a></li>
        <li><a href="/auth/logout.php"><button class="btn">Logout</button></a></li>
    </ul>
</nav>

<section class="main-container shadow-lg">
    <div style="padding: 5%;">
        <h2>Dashboard</h2>
        <p>Welcome, <?php echo htmlspecialchars($name); ?>.</p>

        <?php if ($group_id <= 0 || !$active_group): ?>
            <!-- No valid group context yet -->
            <div class="alert alert-info" role="alert" style="margin-top: 20px;">
                You’re not in a group yet. Join or create a group to start tracking budgets and expenses.
            </div>

            <div class="d-grid gap-2" style="max-width: 300px; margin: 20px auto;">
                <a href="/groups.php"><button class="btn w-100">Go to Groups</button></a>
                <a href="/expenses.php"><button class="btn w-100">Expenses</button></a>
                <a href="/budgets.php"><button class="btn w-100">Budgets</button></a>
            </div>

        <?php else: ?>
            <!-- Active group context -->
            <p style="margin-top: 10px;">
                <small>Active Group: <strong><?php echo htmlspecialchars($active_group['name']); ?></strong></small>
            </p>

            <!-- Dashboard tiles -->
            <div class="row" style="margin-top: 20px;">
                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Month to Date</h5>
                            <p class="card-text" style="font-size: 1.4rem;">
                                $<?php echo htmlspecialchars(number_format($month_total, 2)); ?>
                            </p>
                            <p class="card-text"><small><?php echo htmlspecialchars((string)$month_count); ?> expense(s)</small></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Last 30 Days</h5>
                            <p class="card-text" style="font-size: 1.4rem;">
                                $<?php echo htmlspecialchars(number_format($last30_total, 2)); ?>
                            </p>
                            <p class="card-text"><small>Rolling total</small></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Quick Actions</h5>
                            <div class="d-grid gap-2">
                                <a href="/expenses.php"><button class="btn w-100">Add Expense</button></a>
                                <a href="/budgets.php"><button class="btn w-100">Create Budget</button></a>
                                <a href="/groups.php"><button class="btn w-100">Manage Groups</button></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent expenses -->
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
                            <td><?php echo htmlspecialchars(number_format((float)$e['amount'], 2)); ?></td>
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

<!-- NOTE: Correct path (your project uses /assets/pageCustomization.js, not /assets/js/pageCustomization.js) -->
<script src="/assets/pageCustomization.js"></script>
</body>
</html>
