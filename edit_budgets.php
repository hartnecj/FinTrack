<?php
/*
|--------------------------------------------------------------------------
| edit_budgets.php
|--------------------------------------------------------------------------
| Bug Fix:
| - Budgets can duplicate on refresh if we render the page after POST.
|
| Solution:
| - Implement Post/Redirect/Get (PRG)
| - After a successful POST (add/delete), redirect to /budgets.php
| - Use session "flash" messages so feedback survives redirect
|
| Notes:
| - Uses $_SESSION['active_group_id'] for group context
| - Uses CSRF token generated in auth_guard.php
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

/*
|--------------------------------------------------------------------------
| If user has no active group, budgets should not be accessible yet
|--------------------------------------------------------------------------
*/
if ($group_id <= 0) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Budgets</title>
        <link rel="stylesheet" href="/assets/style.css?v=5">    
    </head>
    <body class="ft-page">
        <main class="container" style="padding: 30px;">
            <h1>Budgets</h1>
            <p>You need to join or create a group before you can create budgets.</p>
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
| Handle POST actions (PRG enabled)
|--------------------------------------------------------------------------
| IMPORTANT:
| - After successful insert/delete, redirect to GET /budgets.php
| - Prevents duplicate inserts on refresh
 modified to allow for editing
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'edit_budget') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $amount_limit = $_POST['amount'] ?? 0;
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        
        if ($budget_id <= 0){
            $_SESSION['flash_error'] = "Invalid budget.";
            header("Location: /budgets.php");
            exit;
        }

        // Validation
        if ($name === '') {
            $_SESSION['flash_error'] = "Budget name is required.";
            header("Location: /edit_budgets.php");
            exit;
        }

        if (strlen($name) > 100) {
            $_SESSION['flash_error'] = "Budget name is too long (max 100 characters).";
            header("Location: /edit_budgets.php");
            exit;
        }

        // If both dates provided, make sure they are in the correct order
        if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) {
            $_SESSION['flash_error'] = "Start date cannot be after end date.";
            header("Location: /edit_budgets.php");
            exit;
        }
        
        //added if there is no budget amount_limit
        if($amount_limit == NULL || $amount_limit == 0 || $amount_limit == ''){
            $_SESSION['flash_error'] = "Invalid budget amount";
            header("Location: " . BASE_PATH . "/edit_budgets.php");
            exit;
        }
        
        // Insert
        $stmt = $pdo->prepare("
            UPDATE budgets SET name=?, category=?, amount_limit=?, start_date=?, end_date=?
            WHERE id=? AND group_id=?
        ");
        $stmt->execute([
            $name,
            $category,
            $amount_limit,
            ($start_date === '' ? null : $start_date),
            ($end_date === '' ? null : $end_date),
            $budget_id,
            $group_id,
        ]);

        $_SESSION['flash_success'] = "Budget updated.";
        header("Location: /budgets.php");
        exit;
    }

    if ($action === 'delete_budget') {
        $budget_id = (int)($_POST['budget_id'] ?? 0);

        // Verify budget belongs to this group
        $stmt = $pdo->prepare("
            SELECT id, created_by
            FROM budgets
            WHERE id = ? AND group_id = ?
            LIMIT 1
        ");
        $stmt->execute([$budget_id, $group_id]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$budget) {
            $_SESSION['flash_error'] = "Budget not found.";
            header("Location: /edit_budgets.php");
            exit;
        }

        $created_by = (int)$budget['created_by'];

        // Only allow delete if creator OR group owner
        if ($created_by !== $user_id && !$is_owner) {
            $_SESSION['flash_error'] = "You don't have permission to delete that budget.";
            header("Location: /budgets.php");
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM budgets WHERE id = ? AND group_id = ?");
        $stmt->execute([$budget_id, $group_id]);

        $_SESSION['flash_success'] = "Budget deleted.";
        header("Location: /budgets.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Fetch budget for edit (GET request)
|--------------------------------------------------------------------------
*/
$budget_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT * FROM budgets
    WHERE id=? AND group_id=?
    LIMIT 1

");
$stmt->execute([$budget_id, $group_id]);
$budget = $stmt->fetch(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Budgets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-4.0.0.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- NOTE: Correct path (your project uses /assets/style.css, not /assets/css/style.css) -->
    <link rel="stylesheet" href="/assets/style.css?v=5">
</head>
<body class="ft-page">
<nav>
    <ul>
        <li id="profile-btn"><a href="<?= BASE_PATH ?>/profile.php"><button class="btn">Profile</button></a></li>
        <li><a href="/"><button class="btn">Home</button></a></li>
        <li><a href="/dashboard.php"><button class="btn">Dashboard</button></a></li>
        <li><a href="/budgets.php"><button class="btn">Budgets</button></a></li>
        <li><a href="/expenses.php"><button class="btn">Expenses</button></a></li>
        <li><a href="/groups.php"><button class="btn">Groups</button></a></li>
        <li><a href="<?= BASE_PATH ?>/messages.php"><button class="btn">Messages</button></a></li>
        <li><a href="/auth/logout.php"><button class="btn">Logout</button></a></li>
    </ul>
</nav>

<section class="main-container shadow-lg">
    <div style="padding: 5%;">
        <h2>Budgets</h2>
        <p><small>Logged in as <?php echo htmlspecialchars($user_name); ?></small></p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <h4 style="margin-top: 25px;">Edit Budget</h4>
        <form method="post" action="<?= BASE_PATH ?>/edit_budgets.php" style="margin-top: 10px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="edit_budget">

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Budget Name</label>
                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($budget['name'] ?? ''); ?>" required>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">Budget Category</label>
                    <input type="text" class="form-control" name="category" placeholder="recycling" value="<?= htmlspecialchars($budget['category'] ?? ''); ?>" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Amount Limit</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="budget_amount" aria-label="amount limit" value="<?= htmlspecialchars($budget['amount_limit'] ?? ''); ?>" required>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Start Date (optional)</label>
                    <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($budget['start_date'] ?? ''); ?>">
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">End Date (optional)</label>
                    <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($budget['end_date'] ?? ''); ?>">
                </div>

                <input type="hidden" name="budget_id" value="<?=(int)$budget['id']; ?>">

                <div class="col-md-2 mb-3 d-flex align-items-end">
                    <button type="submit" class="btn w-100">Save Changes</button>
                </div>
                
                <div class="col-md-2 mb-3 d-flex align-items-end">
                    <a href="/budgets.php"><button class="btn w-100" formnovalidate>Cancel</button></a>
                </div>

            </div>
        </form>


        <p style="margin-top: 30px;"><a href="/dashboard.php">Back to dashboard</a></p>
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
