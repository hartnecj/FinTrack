<?php
/*
|--------------------------------------------------------------------------
| expenses.php
|--------------------------------------------------------------------------
| Current features:
| - Add expense (PRG enabled, flash messages)
| - List recent expenses
| - Delete expense (creator or group owner)
|
| New enhancement:
| - Optional budget linking via budget_id
| - Loads budgets for active group into a dropdown
| - Saves budget_id with expense
| - Shows budget name in the expense list
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/auth_guard.php";
require_once __DIR__ . "/config/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);
$user_name = $_SESSION["user_name"] ?? "User";
$group_id = (int)($_SESSION["active_group_id"] ?? 0);

// Flash messages (survive redirects)
$error = '';
$success = '';

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// If user has no active group, they can't track expenses yet.
if ($group_id <= 0) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Expenses</title>
        <link rel="stylesheet" href="/assets/style.css">
    </head>
    <body>
        <main class="container" style="padding: 30px;">
            <h1>Expenses</h1>
            <p>You need to join or create a group before you can add expenses.</p>
            <p><a href="/groups.php">Go to Groups</a></p>
            <p><a href="/dashboard.php">Back to dashboard</a></p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF helper
|--------------------------------------------------------------------------
*/
function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted = $_POST['csrf_token'] ?? '';
        if (!$posted || !hash_equals($_SESSION['csrf_token'], $posted)) {
            http_response_code(403);
            die("Invalid CSRF token.");
        }
    }
}

/*
|--------------------------------------------------------------------------
| Helper: is user the owner of the active group?
|--------------------------------------------------------------------------
*/
function is_group_owner(PDO $pdo, int $group_id, int $user_id): bool {
    $stmt = $pdo->prepare("SELECT owner_id FROM groups WHERE id = ? LIMIT 1");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && (int)$row['owner_id'] === $user_id;
}

$is_owner = is_group_owner($pdo, $group_id, $user_id);

/*
|--------------------------------------------------------------------------
| Load budgets for dropdown
|--------------------------------------------------------------------------
| Only budgets belonging to the active group are shown.
| This prevents accidentally linking an expense to another group's budget.
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id, name, start_date, end_date
    FROM budgets
    WHERE group_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$group_id]);
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Handle POST actions (PRG enabled)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_expense') {
        $amount_raw = trim($_POST['amount'] ?? '');
        $expense_date = $_POST['expense_date'] ?? '';
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // New: optional budget_id (must belong to this group)
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        $budget_id = ($budget_id > 0) ? $budget_id : null;

        // Validation
        if ($amount_raw === '' || !is_numeric($amount_raw)) {
            $_SESSION['flash_error'] = "Please enter a valid amount.";
            header("Location: /expenses.php");
            exit;
        }

        $amount = (float)$amount_raw;
        if ($amount <= 0) {
            $_SESSION['flash_error'] = "Amount must be greater than 0.";
            header("Location: /expenses.php");
            exit;
        }

        if ($expense_date === '') {
            $_SESSION['flash_error'] = "Please select an expense date.";
            header("Location: /expenses.php");
            exit;
        }

        if (strlen($category) > 50) {
            $_SESSION['flash_error'] = "Category is too long (max 50 characters).";
            header("Location: /expenses.php");
            exit;
        }

        if (strlen($description) > 255) {
            $_SESSION['flash_error'] = "Description is too long (max 255 characters).";
            header("Location: /expenses.php");
            exit;
        }

        // If a budget_id was selected, verify it belongs to this group
        if ($budget_id !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM budgets WHERE id = ? AND group_id = ? LIMIT 1");
            $stmt->execute([$budget_id, $group_id]);
            if (!$stmt->fetch()) {
                $_SESSION['flash_error'] = "Invalid budget selection.";
                header("Location: /expenses.php");
                exit;
            }
        }

        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO expenses (group_id, user_id, budget_id, amount, category, description, expense_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $group_id,
            $user_id,
            $budget_id,
            $amount,
            ($category === '' ? null : $category),
            ($description === '' ? null : $description),
            $expense_date
        ]);

        $_SESSION['flash_success'] = "Expense added.";
        header("Location: /expenses.php");
        exit;
    }

    if ($action === 'delete_expense') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);

        // Verify expense belongs to this group
        $stmt = $pdo->prepare("
            SELECT id, user_id
            FROM expenses
            WHERE id = ? AND group_id = ?
            LIMIT 1
        ");
        $stmt->execute([$expense_id, $group_id]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$expense) {
            $_SESSION['flash_error'] = "Expense not found.";
            header("Location: /expenses.php");
            exit;
        }

        $expense_owner_id = (int)$expense['user_id'];
        if ($expense_owner_id !== $user_id && !$is_owner) {
            $_SESSION['flash_error'] = "You don't have permission to delete that expense.";
            header("Location: /expenses.php");
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND group_id = ?");
        $stmt->execute([$expense_id, $group_id]);

        $_SESSION['flash_success'] = "Expense deleted.";
        header("Location: /expenses.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Fetch recent expenses for display (GET request)
|--------------------------------------------------------------------------
| Now includes budget name via LEFT JOIN, since budget_id is optional.
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT e.id, e.amount, e.category, e.description, e.expense_date, e.created_at,
           u.name AS created_by_name,
           e.user_id AS created_by_id,
           b.name AS budget_name
    FROM expenses e
    JOIN users u ON e.user_id = u.id
    LEFT JOIN budgets b ON e.budget_id = b.id
    WHERE e.group_id = ?
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT 50
");
$stmt->execute([$group_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Expenses</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-4.0.0.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
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
        <h2>Expenses</h2>
        <p><small>Logged in as <?php echo htmlspecialchars($user_name); ?></small></p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <h4 style="margin-top: 25px;">Add Expense</h4>
        <form method="post" action="/expenses.php" style="margin-top: 10px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_expense">

            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Budget (optional)</label>
                    <select class="form-control" name="budget_id">
                        <option value="0">No budget</option>
                        <?php foreach ($budgets as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>">
                                <?php echo htmlspecialchars($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Category (optional)</label>
                    <input type="text" class="form-control" name="category" placeholder="Groceries">
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Description (optional)</label>
                    <input type="text" class="form-control" name="description" placeholder="Target run">
                </div>
            </div>

            <button type="submit" class="btn">Add Expense</button>
        </form>

        <h4 style="margin-top: 35px;">Recent Expenses</h4>

        <?php if (empty($expenses)): ?>
            <p style="margin-top: 10px;">No expenses yet.</p>
        <?php else: ?>
            <table class="table table-striped" style="margin-top: 10px;">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Budget</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Added By</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($expenses as $e): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($e['expense_date']))); ?></td>
                        <td><?php echo htmlspecialchars(number_format((float)$e['amount'], 2)); ?></td>
                        <td><?php echo htmlspecialchars($e['budget_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($e['category'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($e['description'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($e['created_by_name']); ?></td>
                        <td>
                            <?php $can_delete = ((int)$e['created_by_id'] === $user_id) || $is_owner; ?>
                            <?php if ($can_delete): ?>
                                <form method="post" action="/expenses.php" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>">
                                    <button type="submit" class="btn btn-sm" onclick="return confirm('Delete this expense?')">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top: 30px;"><a href="/dashboard.php">Back to dashboard</a></p>
    </div>
</section>

<footer>
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" id="styleSwitch">
        <label class="form-check-label" for="styleSwitch" id="styleLabel"> Light mode: On </label>
    </div>
</footer>

<script src="/assets/pageCustomization.js"></script>
</body>
</html>
