<?php
require_once __DIR__ . "/auth_guard.php";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/balance_helpers.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);
$user_name = $_SESSION["user_name"] ?? "User";
$group_id = (int)($_SESSION["active_group_id"] ?? 0);

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

if ($group_id <= 0) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Expenses</title>
        <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=5">
    </head>
    <body class="ft-page">
        <main class="container" style="padding: 30px;">
            <h1>Expenses</h1>
            <p>You need to join or create a group before you can add expenses.</p>
            <p><a href="<?= BASE_PATH ?>/groups.php">Go to Groups</a></p>
            <p><a href="<?= BASE_PATH ?>/dashboard.php">Back to dashboard</a></p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted = $_POST['csrf_token'] ?? '';
        if (!$posted || !hash_equals($_SESSION['csrf_token'], $posted)) {
            http_response_code(403);
            die("Invalid CSRF token.");
        }
    }
}

function is_group_owner(PDO $pdo, int $group_id, int $user_id): bool {
    $stmt = $pdo->prepare("SELECT owner_id FROM groups WHERE id = ? LIMIT 1");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && (int)$row['owner_id'] === $user_id;
}

$is_owner = is_group_owner($pdo, $group_id, $user_id);

$stmt = $pdo->prepare("
    SELECT id, name, start_date, end_date
    FROM budgets
    WHERE group_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$group_id]);
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT u.id, u.name
    FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ?
    ORDER BY u.name ASC
");
$stmt->execute([$group_id]);
$group_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_expense') {
        $amount_raw = trim($_POST['amount'] ?? '');
        $expense_date = $_POST['expense_date'] ?? '';
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $personal    = trim($_POST['personal'] ?? NULL);
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        $budget_id = ($budget_id > 0) ? $budget_id : null;

        $split_type = $_POST['split_type'] ?? 'equal';
        $selected_members = $_POST['selected_members'] ?? [];
        $custom_amounts = $_POST['custom_amounts'] ?? [];

        $selected_members = array_map('intval', $selected_members);
        $valid_member_ids = array_map(function ($m) {
            return (int)$m['id'];
        }, $group_members);

        $selected_members = array_values(array_filter($selected_members, function ($id) use ($valid_member_ids) {
            return in_array($id, $valid_member_ids, true);
        }));

        if ($amount_raw === '' || !is_numeric($amount_raw)) {
            $_SESSION['flash_error'] = "Please enter a valid amount.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        $amount = round((float)$amount_raw, 2);

        if ($amount <= 0) {
            $_SESSION['flash_error'] = "Amount must be greater than 0.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        if ($expense_date === '') {
            $_SESSION['flash_error'] = "Please select an expense date.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        if (strlen($category) > 50) {
            $_SESSION['flash_error'] = "Category is too long.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        if (strlen($description) > 255) {
            $_SESSION['flash_error'] = "Description is too long.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        if ($budget_id !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM budgets WHERE id = ? AND group_id = ? LIMIT 1");
            $stmt->execute([$budget_id, $group_id]);
            if (!$stmt->fetch()) {
                $_SESSION['flash_error'] = "Invalid budget selection.";
                header("Location: " . BASE_PATH . "/expenses.php");
                exit;
            }
        }

        if (empty($selected_members)) {
            $_SESSION['flash_error'] = "Please select at least one group member for the split.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO expenses (group_id, user_id, budget_id, amount, category, description, expense_date, personal)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $group_id,
                $user_id,
                $budget_id,
                $amount,
                ($category === '' ? null : $category),
                ($description === '' ? null : $description),
                $expense_date,
                $personal
            ]);

            $expense_id = (int)$pdo->lastInsertId();
            $split_rows = [];

            if ($split_type === 'equal') {
                $equal_shares = split_amount_evenly($amount, count($selected_members));

                foreach ($selected_members as $index => $member_id) {
                    $split_rows[] = [
                        'user_id' => $member_id,
                        'amount_owed' => $equal_shares[$index]
                    ];
                }
            } elseif ($split_type === 'custom') {
                $sum = 0.00;

                foreach ($selected_members as $member_id) {
                    $owed = isset($custom_amounts[$member_id]) ? round((float)$custom_amounts[$member_id], 2) : 0.00;

                    if ($owed < 0) {
                        throw new Exception("Custom split amounts cannot be negative.");
                    }

                    $split_rows[] = [
                        'user_id' => $member_id,
                        'amount_owed' => $owed
                    ];

                    $sum += $owed;
                }

                if (abs($sum - $amount) > 0.01) {
                    throw new Exception("Custom split amounts must add up to the full expense amount.");
                }
            } else {
                throw new Exception("Invalid split type.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO expense_splits (expense_id, user_id, amount_owed)
                VALUES (?, ?, ?)
            ");

            foreach ($split_rows as $row) {
                $stmt->execute([$expense_id, $row['user_id'], $row['amount_owed']]);
            }

            $pdo->commit();

            $_SESSION['flash_success'] = "Expense added and split saved.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }
    }

    if ($action === 'delete_expense') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);

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
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        $expense_owner_id = (int)$expense['user_id'];

        if ($expense_owner_id !== $user_id && !$is_owner) {
            $_SESSION['flash_error'] = "You don't have permission to delete that expense.";
            header("Location: " . BASE_PATH . "/expenses.php");
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND group_id = ?");
        $stmt->execute([$expense_id, $group_id]);

        $_SESSION['flash_success'] = "Expense deleted.";
        header("Location: " . BASE_PATH . "/expenses.php");
        exit;
    }
}
/* added and clause to make sure only appropriately flagged personal messages are shown to groups */

$stmt = $pdo->prepare("
    SELECT e.id, e.amount, e.category, e.description, e.expense_date, e.created_at,
           u.name AS created_by_name,
           e.user_id AS created_by_id,
           b.name AS budget_name
    FROM expenses e
    JOIN users u ON e.user_id = u.id
    LEFT JOIN budgets b ON e.budget_id = b.id
    WHERE e.group_id = ? AND ((e.personal = 0 OR e.personal IS NULL) OR (e.personal = 1 AND u.id = ?))
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT 50
");
$stmt->execute([$group_id, $user_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$split_map = [];

$stmt = $pdo->prepare("
    SELECT es.expense_id, u.name, es.amount_owed
    FROM expense_splits es
    JOIN users u ON u.id = es.user_id
    JOIN expenses e ON e.id = es.expense_id
    WHERE e.group_id = ?
    ORDER BY es.expense_id, u.name
");
$stmt->execute([$group_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $expense_id = (int)$row['expense_id'];
    if (!isset($split_map[$expense_id])) {
        $split_map[$expense_id] = [];
    }
    $split_map[$expense_id][] = $row['name'] . " ($" . number_format((float)$row['amount_owed'], 2) . ")";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Expenses</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-4.0.0.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=5">
    <style>
        .split-box {
            background: rgba(8, 12, 30, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .split-box .form-check-label {
            color: #ffffff;
        }

        .split-box .form-check-input {
            background-color: #101426;
            border-color: rgba(255, 255, 255, 0.35);
        }

        .split-box .form-check-input:checked {
            background-color: #4f8cff;
            border-color: #4f8cff;
        }

        .split-box .form-control {
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .split-box .form-control::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .split-box .form-control:focus {
            background: rgba(255, 255, 255, 0.10);
            color: #ffffff;
            border-color: #4f8cff;
            box-shadow: 0 0 0 0.2rem rgba(79, 140, 255, 0.2);
        }
    </style>
</head>
<body class="ft-page">
<nav>
    <ul>
        <li id="profile-btn"><a href="<?= BASE_PATH ?>/profile.php"><button class="btn" aria-label="profile">Profile</button></a></li>
        <li><a href="<?= BASE_PATH ?>/"><button class="btn" aria-label="home">Home</button></a></li>
        <li><a href="<?= BASE_PATH ?>/dashboard.php"><button class="btn" aria-label="dashboard">Dashboard</button></a></li>
        <li><a href="<?= BASE_PATH ?>/budgets.php"><button class="btn" aria-label="budgets">Budgets</button></a></li>
        <li><a href="<?= BASE_PATH ?>/expenses.php"><button class="btn" aria-label="expenses">Expenses</button></a></li>
        <li><a href="<?= BASE_PATH ?>/groups.php"><button class="btn" aria-label="groups">Groups</button></a></li>
        <li><a href="<?= BASE_PATH ?>/messages.php"><button class="btn" aria-label="messages">Messages</button></a></li>
        <li><a href="<?= BASE_PATH ?>/auth/logout.php"><button class="btn" aria-label="logout">Logout</button></a></li>
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

        <form method="post" action="<?= BASE_PATH ?>/expenses.php" style="margin-top: 10px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_expense">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="expense_amount" aria-label="expense amount" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="expense_date" aria-label="date of expense" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Budget (optional)</label>
                    <select class="form-control" name="budget_id" aria-label="budget select" >
                        <option value="0">No budget</option>
                        <?php foreach ($budgets as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>">
                                <?php echo htmlspecialchars($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-8 mb-3">
                    <label class="form-label">Category (optional)</label>
                    <input type="text" class="form-control" name="category" placeholder="Groceries" aria-label="optional category" >
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">Personal? (optional)</label><br>
                    <input type="checkbox" class="form-check-input" name="personal" value="1" aria-label="personal expense toggle" >
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Description (optional)</label><br>
                    <input type="text" class="form-control" name="description" placeholder="Target run" aria-label="expense description" >
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Split Type</label>
                    <select class="form-control" name="split_type" id="split_type" aria-label="expense-split option" >
                        <option value="equal">Equal split</option>
                        <option value="custom">Custom split</option>
                    </select>
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Split Between</label>
                    <div class="border rounded p-3 split-box">
                        <?php foreach ($group_members as $member): ?>
                            <div class="form-check mb-2">
                                <input
                                    class="form-check-input split-member-checkbox"
                                    type="checkbox"
                                    name="selected_members[]"
                                    value="<?php echo (int)$member['id']; ?>"
                                    id="member_<?php echo (int)$member['id']; ?>"
                                    checked
                                >
                                <label class="form-check-label" for="member_<?php echo (int)$member['id']; ?>">
                                    <?php echo htmlspecialchars($member['name']); ?>
                                </label>
                            </div>

                            <div class="mb-2 custom-amount-row" data-user-id="<?php echo (int)$member['id']; ?>" style="display:none;">
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="form-control"
                                    name="custom_amounts[<?php echo (int)$member['id']; ?>]"
                                    placeholder="Custom amount for <?php echo htmlspecialchars($member['name']); ?>"
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-light">Equal split will divide the amount automatically. Custom split must add up to the full amount.</small>
                </div>
            </div>

            <button type="submit" class="btn w-100">Add Expense</button>
        </form>

        <h4 style="margin-top: 25px;">Recent Expenses</h4>
        <p style="margin-top: 10px;"><a href="<?= BASE_PATH ?>/dashboard.php">Back to dashboard</a></p>

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
                        <th>Personal</th>
                        <th>Description</th>
                        <th>Split</th>
                        <th>Added By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $e): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($e['expense_date']))); ?></td>
                            <td>$<?php echo htmlspecialchars(number_format((float)$e['amount'], 2)); ?></td>
                            <td><?php echo htmlspecialchars($e['budget_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['category'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['personal'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($e['description'] ?? ''); ?></td>
                            <td>
                                <?php
                                $expense_id = (int)$e['id'];
                                if (!empty($split_map[$expense_id])) {
                                    echo htmlspecialchars(implode(', ', $split_map[$expense_id]));
                                } else {
                                    echo '';
                                }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($e['created_by_name']); ?></td>
                            <td style="text-align:right; white-space:nowrap;">
                                <?php $can_delete = ((int)$e['created_by_id'] === $user_id) || $is_owner; ?>
                                <?php if ($can_delete): ?>
                                    <form method="post" action="<?= BASE_PATH ?>/expenses.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_expense">
                                        <input type="hidden" name="expense_id" value="<?php echo (int)$e['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this expense?')" aria-label="delete button" >Delete</button>
                                    </form>
                                    
                                    <!-- button for editing expense -->
                                    <a href="<?= BASE_PATH ?>/edit_expenses.php?id=<?= (int)$e['id']; ?>">
                                        <button type="button" class="btn btn-sm btn-primary" aria-label="edit expense" >Edit</button>
                                    </a>
                                
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    const splitType = document.getElementById('split_type');
    const checkboxes = document.querySelectorAll('.split-member-checkbox');
    const customRows = document.querySelectorAll('.custom-amount-row');

    function refreshCustomRows() {
        const isCustom = splitType.value === 'custom';

        customRows.forEach(row => {
            const userId = row.getAttribute('data-user-id');
            const checkbox = document.getElementById('member_' + userId);

            row.style.display = (isCustom && checkbox && checkbox.checked) ? 'block' : 'none';
        });
    }

    if (splitType) {
        splitType.addEventListener('change', refreshCustomRows);
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', refreshCustomRows);
    });

    refreshCustomRows();
})();
</script>

</body>
</html>
