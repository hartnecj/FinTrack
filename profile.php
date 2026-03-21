<?php
/*
|--------------------------------------------------------------------------
| profile.php
|--------------------------------------------------------------------------
| 
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/auth_guard.php";
require_once __DIR__ . "/config/db.php";

$user_id      = (int)($_SESSION["user_id"] ?? 0);
if($user_id > 0){
    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
$name         = $row["name"] ?? "User";
$email        = $row["email"] ?? "User Email";
$password     = $row["password_hash"];
$date_created = $row["created_at"] ?? "Creation date: unknown";

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
        header("Location: " . BASE_PATH . "/dashboard.php");
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
        header("Location: " . BASE_PATH . "/dashboard.php");
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
/*
|------------------------------------------------------------------------------
| group removal function call
|------------------------------------------------------------------------------
*/
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_group'])){
    removeGroup();
    unset($_SESSION['active_group_id']);
    header("Location: " . BASE_PATH . "/profile.php");
    exit;
}
/*
|------------------------------------------------------------------------------
| group removal function
|------------------------------------------------------------------------------
*/
function removeGroup(){
    //if there is an active group and the group id is valid:
    $total_group_members = 0;
    global $group_id, $active_group, $pdo, $user_id;
    if ($group_id > 0 && $active_group) {
        //fetch group id
        
        //remove group membership
        $stmt = $pdo->prepare("
        DELETE FROM group_members
        WHERE user_id = ? AND group_id = ?
        LIMIT 1
        ");
        $stmt->execute([$user_id, $group_id]);
        $active_group = $stmt->fetch(PDO::FETCH_ASSOC);
        //check if there are any members of the group
        
        
        $stmt = $pdo->prepare("SELECT * FROM group_members WHERE group_id = ?");
        $stmt->execute([$group_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_group_members = $row['cnt'];
        
        
        
        //if no members of the group left, remove group entirely.
    if((int)$total_group_members <= 0){
        $stmt = $pdo ->prepare("
        DELETE
        FROM groups
        WHERE id = ?
        ");
        $stmt->execute([$group_id]);
        $stmt = $pdo ->prepare("SELECT * FROM groups WHERE group_id = ?");
        $stmt->execute([$group_id]);
        $success = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$success){
            return true;
        }
    } else {
        return true;
    }
    //if the group id is invalid or there is no active group:
    } else {
        return true;
    }//end else
}//end removeGroup()

//sets the editing to false, making the profile editing box hide inputs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editing = $_POST['editing'] ?? false;
}
//use this to debug the status of the editing box (toggled on/off)
//echo $editing;

/*
|------------------------------------------------------------------------------
| profile editing function call
|------------------------------------------------------------------------------
*/
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_profile'])){
    $unsuccess = edit_profile();
    //if edit was not successful, stay on page and show error, and keep the editing tab open
    if ($unsuccess){
        $editing = true;
    //if editing was successful, redirect
    } else {
        header("Location: " . BASE_PATH . "/profile.php");
        exit;
    }
}
/*
|------------------------------------------------------------------------------
| profile editing function
|------------------------------------------------------------------------------
*/
function edit_profile(){
    //global declarations
    global $password, $email, $name, $pdo, $user_id, $error;
    //new form values
    $new_email = trim($_POST['email'] ?? '');
    $registered_email = $email;
    $new_password = $_POST['password'] ?? '';
    $new_passwordConfirm = $_POST['passwordConfirm'] ?? '';
    $new_first = trim($_POST['first-name'] ?? '');
    $new_last = trim($_POST['last-name'] ?? '');
    //concatenate new name from first and last name
    $new_name = trim($new_first . ' ' . $new_last);
    
    //if name is new and not null
    if($new_name != $name && $new_name != ''){
        $name = $new_name;
    }
    //if passwords do not match
    if ($new_password !== $new_passwordConfirm) {
        $error = 'Passwords do not match.';
        //return unsuccessful
        return true;
    }
    //if new password exists
    if ($new_password !== ''){
        $password = password_hash($new_password, PASSWORD_DEFAULT);
    }
    //if the new email exists and is not the old email
    if($new_email != '' && $new_email != $email){
        $email = $new_email;
    }
    //if nothing has changed
    if($new_email == '' && $new_password == '' && $new_first == '' && $new_last == ''){
        //do nothing
        $error = 'Nothing changed';
        //return unsuccessful
        return true;
    } else {
        //prepare sql statement
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        //if the email is already registered and it is not your email, return unsuccessful
        if ($stmt->fetch() && $email != $registered_email) {
            $error = 'That email is already registered. Try logging in.';
            return true;
        } else {
            //if the email is valid, set all values with new values (old values are overwritten with original variables)
            $stmt = $pdo->prepare('UPDATE users SET name=?, email=?, password_hash=? WHERE id = ?');
            $stmt->execute([$name, $email, $password, $user_id]);
            //return successful
            return false;
        }
    }
}//end editProfile()


?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>FinTrack - Profile</title>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script src="https://code.jquery.com/jquery-4.0.0.js" integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U=" crossorigin="anonymous"></script>
        <!-- NOTE: updated CSS 2/16/26 -->
        <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=5">
    </head>
    <body class="ft-page">
        <!-- navigation section -->
    <nav>
        <ul>
            <li><a href="<?= BASE_PATH ?>/"><button class="btn">Home</button></a></li>
            <li><a href="<?= BASE_PATH ?>/dashboard.php"><button class="btn">Dashboard</button></a></li>
            <li><a href="<?= BASE_PATH ?>/budgets.php"><button class="btn">Budgets</button></a></li>
            <li><a href="<?= BASE_PATH ?>/expenses.php"><button class="btn">Expenses</button></a></li>
            <li><a href="<?= BASE_PATH ?>/groups.php"><button class="btn">Groups</button></a></li>
            <li><a href="<?= BASE_PATH ?>/messages.php"><button class="btn">Messages</button></a></li>
            <li><a href="<?= BASE_PATH ?>/auth/logout.php"><button class="btn">Logout</button></a></li>
        </ul>
    </nav>

    <!-- main container section -->
    <section class="main-container shadow-lg">
        <div style="padding: 5%;">
            <h2>Profile</h2>
            <p>Welcome, <?php echo htmlspecialchars($name); ?>.</p>

        
            <!-- Dashboard tiles, shows general profile information -->
            <div class="row" style="margin-top: 20px;">
                <div class="col-md-6 mb-3">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Profile Information:</h5>
                            <p class="card-text" style="font-size: 1.4rem;">
                                <h5 class="card-title"> name: </h5>
                                <p class="card-text"><?php echo htmlspecialchars((string)$name); ?>
                                <h5 class="card-title"> email: </h5>
                                <p class="card-text"><?php echo htmlspecialchars((string)$email); ?>
                                <h5 class="card-title"> date joined: </h5>
                                <p class="card-text"><?php echo htmlspecialchars((string)$date_created); ?>
                            </p>
                            

                            
                        </div>
                    </div>
                </div>


                <!-- this is the section that allows for profile information editing, toggleable -->
                <div class="col-md-6 mb-3" id="edit-area">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Edit profile</h5>
                            <div class="d-grid gap-2">
                                <!-- this section will show only if the action of editing is toggled as true -->
                                <?php if ($editing): ?>
                                <form  method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" >
                                <fieldset class="form-group">
                                    <legend>Edit profile</legend>
                                    <!-- shows if there is an error -->
                                    <?php if ($error !== ''): ?>
                                        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
                                    <?php endif; ?>
          
                                    <!-- email input -->
                                    <label for="email">E-Mail</label><br>
                                    <div class="mb-3 input-group">
                                        <input type="email" id="email" name="email" class="form-control" aria-label="Email input" aria-description="email-input box for entering email">
                                    </div>
          
                                    <!-- password input -->
                                    <label for="password">Password</label><br>
                                    <div class="mb-3 input-group">
                                        <input type="password" id="password" name="password" class="form-control" aria-label="Password input" aria-description="password-input box for entering password">
                                    </div>
          
                                    <!-- password confirm input -->
                                    <label for="passwordConfirm">Password Confirm</label><br>
                                    <div class="mb-3 input-group">
                                        <input type="password" id="passwordConfirm" name="passwordConfirm" class="form-control" aria-label="Password input confirmation" aria-description="password-input box for entering password to check if they match">
                                    </div>
                                </fieldset>
        
                                <fieldset class="form-group">
          
                                    <!-- name input -->
                                    <label for="first-name" class="input-label">First Name</label><label for="last-name" class="input-label left-input-label">Last Name</label>
                                    <div class="mb-3 input-group">
                                        <!-- first name input -->
                                        <input type="text" id="first-name" name="first-name" class="form-control" aria-label="First-Name input" aria-description="first-name input box">
                                        <!-- last name input -->
                                        <input type="text" id="last-name" name="last-name" class="form-control" aria-label="Last-Name input" aria-description="last-name input box" >
                                    </div>
          
                                </fieldset>
                                <!-- submit button/cancel button/hidden input -->
                                <input type="hidden" name="editing" value="0">
                                <button type="submit" class="btn w-100" name="edit_profile" id="submit-profile-btn" value="1">Submit</button>
                                <a href="<?= BASE_PATH ?>/profile.php"><button type="submit" class="btn w-100" formnovalidate>cancel</button></a>
                            </form>
                            
                            <!-- this is the section that shows if editing is not toggled to true -->
                            <?php else: ?>
                            <form method="POST" action="">
                                <a href="<?= BASE_PATH ?>/profile.php"><button type="submit" class="btn w-100" id="edit-profile">edit profile</button></a>
                                    
                                <input type="hidden" name="editing" value="1">
                            </form>
                            <!-- end if-editing -->
                            <?php endif; ?>
         
         
                          
                        </div>
                    </div>
                </div>
            </div>
        </div>
            
       


        <!-- show this section if the user has no active groups -->  
        <?php if (!$active_group): ?>
            <!-- No valid group context yet -->
            <div class="alert alert-info" role="alert" style="margin-top: 20px;">
                You’re not in a group yet. Join or create a group to start tracking budgets and expenses.
            </div>
            <!-- Active group context -->
            <p style="margin-top: 10px;">
                <small>Active Group: <strong><?php echo htmlspecialchars($active_group['name']); ?></strong></small>
            </p>

        <!-- show this section if the user does have active groups -->
        <?php else: ?>
            <!-- we see the user's active group memberships -->
            <p><strong>Your groups</strong></p>
            <div class="d-grid gap-2" style="max-width: 500px;">
                <?php foreach ($user_groups as $g): ?>
                    <form method="post" action="/groups.php" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="set_active">
                        <input type="hidden" name="group_id" value="<?php echo (int)$g['id']; ?>">
                        <button type="submit" class="btn w-100" <?php echo ((int)$g['id'] === $active_group_id) ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($g['name']); ?>
                            <?php echo ((int)$g['id'] === $active_group_id) ? ' (Active)' : ''; ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>

          
            <!--second active group section -->
            <?php if ($active_group): ?>
                <hr style="margin: 30px 0;">

                <h3><?php echo htmlspecialchars($active_group['name']); ?></h3>
                <?php if ($is_owner): ?>
                    <p><small>(You are the group owner)</small></p>
                <?php endif; ?>

                <p><small>Created: <?php echo date('F j, Y', strtotime($active_group['created_at'])); ?></small></p>

            <?php endif; ?>
            
            
            <!-- ability to leave group shown if there is active group membership -->
            <?php if($active_group): ?>
                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                    <button class="btn w-100" type="submit" name="leave_group" value="1">Leave group</button>
                </form>
            <?php endif; ?>



            <?php endif; ?><!-- end of active group section -->
            </div>
        </section>

        <footer>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="styleSwitch">
                <label class="form-check-label" for="styleSwitch" id="styleLabel"> Light mode: On </label>
            </div>
        </footer>



        <script src="<?= BASE_PATH ?>/assets/pageCustomization.js"></script>
    </body>
</html>
