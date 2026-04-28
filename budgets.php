<?php
/*
|--------------------------------------------------------------------------
| budgets.php
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
        <!-- NOTE: Corrected path to match actual project structure (BASE_PATH) -->
        <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=5">  
    </head>
    <body class="ft-page">
        <main class="container" style="padding: 30px;">
            <h1>Budgets</h1>
            <p>You need to join or create a group before you can create budgets.</p>
            <!-- NOTE: Corrected path to match actual project structure (BASE_PATH) -->
            <p><a href="<?= BASE_PATH ?>/groups.php">Go to Groups</a></p>
            <p><a href="<?= BASE_PATH ?>/dashboard.php">Back to dashboard</a></p>
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
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_budget') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $amount_limit = $_POST['amount'] ?? 0;
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';

        // Validation
        if ($name === '') {
            $_SESSION['flash_error'] = "Budget name is required.";
            // NOTE: Corrected path to match actual project structure (BASE_PATH) 
            header("Location: " . BASE_PATH . "/budgets.php");
            exit;
        }

        if (strlen($name) > 100) {
            $_SESSION['flash_error'] = "Budget name is too long (max 100 characters).";
            // NOTE: Corrected path to match actual project structure (BASE_PATH) 
            header("Location: " . BASE_PATH . "/budgets.php");
            exit;
        }

        // If both dates provided, make sure they are in the correct order
        if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) {
            $_SESSION['flash_error'] = "Start date cannot be after end date.";
            // NOTE: Corrected path to match actual project structure (BASE_PATH) 
            header("Location: " . BASE_PATH . "/budgets.php");
            exit;
        }
        
        //added if there is no budget amount_limit
        if($amount_limit == NULL || $amount_limit == 0 || $amount_limit == ''){
            $_SESSION['flash_error'] = "Invalid budget amount";
            header("Location: " . BASE_PATH . "/budgets.php");
            exit;
        }
        
        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO budgets (group_id, name, category, amount_limit, start_date, end_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $group_id,
            $name,
            $category,
            $amount_limit,
            ($start_date === '' ? null : $start_date),
            ($end_date === '' ? null : $end_date),
            $user_id
        ]);

        $_SESSION['flash_success'] = "Budget created.";
        // NOTE: Corrected path to match actual project structure (BASE_PATH) 
        header("Location: " . BASE_PATH . "/budgets.php");
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
            // NOTE: Corrected path to match actual project structure (BASE_PATH) 
            header("Location: " . BASE_PATH . "/budgets.php");
            exit;
        }

        $created_by = (int)$budget['created_by'];

        // Only allow delete if creator OR group owner
        if ($created_by !== $user_id && !$is_owner) {
            $_SESSION['flash_error'] = "You don't have permission to delete that budget.";
            // NOTE: Corrected path to match actual project structure (BASE_PATH) 
            header("Location: " . BASE_PATH . "/budgets.php");
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM budgets WHERE id = ? AND group_id = ?");
        $stmt->execute([$budget_id, $group_id]);

        $_SESSION['flash_success'] = "Budget deleted.";
        // NOTE: Corrected path to match actual project structure (BASE_PATH) 
        header("Location: " . BASE_PATH . "/budgets.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Fetch budgets for display (GET request)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT b.id, b.name, b.category, b.amount_limit, b.start_date, b.end_date, b.created_at,
           u.name AS created_by_name, b.created_by
    FROM budgets b
    JOIN users u ON b.created_by = u.id
    WHERE b.group_id = ?
    ORDER BY b.created_at DESC
    LIMIT 50
");
$stmt->execute([$group_id]);
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT * FROM expenses
    WHERE group_id = ? AND budget_id IS NOT NULL
");
$stmt->execute([$group_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_budgets = 0;
$all_progress_bars = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Budgets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-4.0.0.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- NOTE: Corrected path to match actual project structure (BASE_PATH) -->
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=5">
    <style>
        .progress{
            background-color: black;
            width: 100%;
            
            text-align: center;
        }
        .progress-bar{
            color: white;
        }
    </style>
</head>
<body class="ft-page">
<nav>
    <ul>
            <!-- NOTE: Corrected path to match actual project structure (BASE_PATH) -->
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
        <h2>Budgets</h2>
        <p><small>Logged in as <?php echo htmlspecialchars($user_name); ?></small></p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <h4 style="margin-top: 25px;">Create Budget</h4>
<!-- NOTE: Corrected path to match actual project structure (BASE_PATH) -->
        <form method="post" action="<?= BASE_PATH ?>/budgets.php" style="margin-top: 10px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_budget">

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Budget Name</label>
                    <input type="text" class="form-control" name="name" placeholder="budget 1" required>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">Budget Category</label>
                    <input type="text" class="form-control" name="category" placeholder="recycling" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Amount Limit</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="budget_amount" aria-label="amount limit" required>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Start Date (optional)</label>
                    <input type="date" class="form-control" name="start_date">
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">End Date (optional)</label>
                    <input type="date" class="form-control" name="end_date">
                </div>

                <div class="col-md-2 mb-3 d-flex align-items-end">
                    <button type="submit" class="btn w-100">Create</button>
                </div>
            </div>
        </form>

        <h4 style="margin-top: 35px;">Budgets</h4>

        <?php if (empty($budgets)): ?>
            <p style="margin-top: 10px;">No budgets yet.</p>
        <?php else: ?>
            <table class="table table-striped" style="margin-top: 10px;">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Amount limit</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Created By</th>
                    <th>Edit/Delete</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($budgets as $b): ?>
                    <?php $total_budgets = $total_budgets + 1; ?>
                    <tr>
                        <td><?php echo htmlspecialchars($b['name']); ?></td>
                        <td><?php echo htmlspecialchars($b['category']); ?></td>
                        <td><?php echo htmlspecialchars($b['amount_limit']); ?></td>
                        <td><?php echo htmlspecialchars($b['start_date'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($b['end_date'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($b['created_by_name']); ?></td>
                        <td>
                            <?php
                            $can_delete = ((int)$b['created_by'] === $user_id) || $is_owner;
                            ?>
                            <?php if ($can_delete): ?>
                                <!-- NOTE: Corrected path to match actual project structure (BASE_PATH) -->
                                <form method="post" action="<?= BASE_PATH ?>/budgets.php" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_budget">
                                    <input type="hidden" name="budget_id" value="<?php echo (int)$b['id']; ?>">
                                    <button type="submit" class="btn btn-sm" onclick="return confirm('Delete this budget?')">Delete</button>
                                </form>
                            <?php endif; ?>
                            
                            <!-- button for editing budget -->
                            <a href="<?= BASE_PATH ?>/edit_budgets.php?id=<?= (int)$b['id']; ?>">
                                <button type="button" class="btn btn-sm btn-primary">Edit</button>
                            </a>
                            

                        </td>
                        
                    </tr>
                    <tr>
                        <td colspan='7'>
                            <?php $total_budget_spent = 0; $total_budget_remaining=0; ?>
                            <?php foreach($expenses as $e): ?>
                            
                              
                              
                              <?php if ($e['budget_id'] == $b['id']): ?>
                                  <?php $total_budget_spent = $total_budget_spent + (float)$e['amount']; ?>
                              <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($total_budget_spent != 0): ?>
                                <p>Amount spent: $<?php echo number_format($total_budget_spent, 2); 
                                echo "&nbsp;&nbsp;&nbsp;&nbsp;Budget remaining:&nbsp;$"; 
                                echo number_format($total_budget_remaining = (float)$b['amount_limit'] - $total_budget_spent, 2); 
                                echo "&nbsp;";
                                $all_progress_bars[] = [$total_budget_spent, $total_budget_remaining];
                                ?>
                                <div class="progress col-md-4"><div class="progress-bar"><?php echo ($total_budget_spent / ($total_budget_spent + $total_budget_remaining) * 100); ?>%</div></div>
                                </p>
                                
                                <?php if($total_budget_remaining <= 0): ?>
                                    <span style="color: red">BUDGET EXCEEDED</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- NOTE: Corrected path to match actual project structure (BASE_PATH) -->
        <p style="margin-top: 30px;"><a href="<?= BASE_PATH ?>/dashboard.php">Back to dashboard</a></p>
    </div>
</section>

 <script>
     const expenses = <?php echo json_encode($expenses); ?>;
     const budgets  = <?php echo json_encode($budgets); ?>;
     const allProgressBars = <?php echo json_encode($all_progress_bars); ?>;
     const totalExpenses = expenses.length;
     let progressBars = $('.progress-bar');
     console.log(expenses);
     console.log(totalExpenses)
     console.log(progressBars.length);
     for(let i=0; i<allProgressBars.length; i++){
         let budget_amount = parseFloat(allProgressBars[i][0]) + parseFloat(allProgressBars[i][1]);
         let width = (parseFloat(allProgressBars[i][0]) / budget_amount) * 100
         console.log(width)
         let backgroundColor = "rgb(55, 101, 176)";
         if(parseFloat(allProgressBars[i][0]) > budget_amount){
             width = 100;
         } else if ( width > 50 && width < 75){
            backgroundColor = "rgb(217, 191, 63)" 
         } else if (width > 75){
             backgroundColor = 'rgb(189, 74, 57)'
         }
         let percentageObject = {
            "width": width + "%",
            "background-color": backgroundColor
         }
         $(progressBars[i]).css(percentageObject);
     }
 </script>
</body>
</html>
