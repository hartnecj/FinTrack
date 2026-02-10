<?php
require_once __DIR__ . "/auth_guard.php";
require_once __DIR__ . "/config/db.php";

$user_id = $_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "User";
$error = '';
$success = '';

// Handle POST requests for group management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'leave_group') {
        // Check if user is the owner
        $stmt = $pdo->prepare('SELECT g.owner_id, gm.group_id FROM group_members gm JOIN groups g ON gm.group_id = g.id WHERE gm.user_id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($membership && $membership['owner_id'] == $user_id) {
            $error = 'Group owners cannot leave. Please delete the group or transfer ownership first.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM group_members WHERE user_id = ?');
            $stmt->execute([$user_id]);
            $success = 'You have left the group.';
        }
    } elseif ($action === 'remove_member') {
        $member_id = intval($_POST['member_id'] ?? 0);

        // Verify user is the owner
        $stmt = $pdo->prepare('SELECT g.id, g.owner_id FROM group_members gm JOIN groups g ON gm.group_id = g.id WHERE gm.user_id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group || $group['owner_id'] != $user_id) {
            $error = 'Only the group owner can remove members.';
        } elseif ($member_id == $user_id) {
            $error = 'You cannot remove yourself. Use "Leave Group" or "Delete Group" instead.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
            $stmt->execute([$group['id'], $member_id]);
            $success = 'Member removed successfully.';
        }
    } elseif ($action === 'delete_group') {
        // Verify user is the owner
        $stmt = $pdo->prepare('SELECT g.id FROM group_members gm JOIN groups g ON gm.group_id = g.id WHERE gm.user_id = ? AND g.owner_id = ? LIMIT 1');
        $stmt->execute([$user_id, $user_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            $error = 'Only the group owner can delete the group.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM groups WHERE id = ?');
            $stmt->execute([$group['id']]);
            $success = 'Group deleted successfully.';
        }
    }
}

// Check if user is in a group
// Currently only allows one group per user, but can be expanded to support multiple groups in the future
$stmt = $pdo->prepare('
    SELECT g.id, g.name, g.owner_id, g.created_at
    FROM group_members gm
    JOIN groups g ON gm.group_id = g.id
    WHERE gm.user_id = ?
    LIMIT 1
');
$stmt->execute([$user_id]);
$user_group = $stmt->fetch(PDO::FETCH_ASSOC);

// If in a group, get all members
$members = [];
if ($user_group) {
    $stmt = $pdo->prepare('
        SELECT u.id, u.name, gm.joined_at
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ?
        ORDER BY gm.joined_at ASC
    ');
    $stmt->execute([$user_group['id']]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$is_owner = $user_group && $user_group['owner_id'] == $user_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Groups</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-4.0.0.js" integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U=" crossorigin="anonymous"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/style.css">
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
            <h2>Groups</h2>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (!$user_group): ?>
                <!-- User is not in a group - show links to create/join pages -->
                <p>You are not currently in a group.</p>

                <div class="d-grid gap-2" style="max-width: 300px; margin: 30px auto;">
                    <a href="/groups/create.php"><button class="btn w-100">Create a Group</button></a>
                    <a href="/groups/join.php"><button class="btn w-100">Join a Group</button></a>
                </div>

            <?php else: ?>
                <!-- User is in a group - show group info and members -->
                <h3><?php echo htmlspecialchars($user_group['name']); ?></h3>
                <?php if ($is_owner): ?>
                    <p><small>(You are the group owner)</small></p>
                <?php endif; ?>
                <p><small>Created: <?php echo date('F j, Y', strtotime($user_group['created_at'])); ?></small></p>

                <h4 style="margin-top: 30px;">Members</h4>
                <table class="table table-striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Joined</th>
                            <th>Role</th>
                            <?php if ($is_owner): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($member['name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($member['joined_at'])); ?></td>
                                <td>
                                    <?php if ($member['id'] == $user_group['owner_id']): ?>
                                        <strong>Owner</strong>
                                    <?php else: ?>
                                        Member
                                    <?php endif; ?>
                                </td>
                                <?php if ($is_owner): ?>
                                    <td>
                                        <?php if ($member['id'] != $user_id): ?>
                                            <form method="post" action="/groups.php" style="display: inline;">
                                                <input type="hidden" name="action" value="remove_member">
                                                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                <button type="submit" class="btn btn-sm" onclick="return confirm('Remove this member?')">Remove</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 30px;">
                    <?php if (!$is_owner): ?>
                        <form method="post" action="/groups.php" style="display: inline;">
                            <input type="hidden" name="action" value="leave_group">
                            <button type="submit" class="btn" onclick="return confirm('Are you sure you want to leave this group?')">Leave Group</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/groups.php" style="display: inline;">
                            <input type="hidden" name="action" value="delete_group">
                            <button type="submit" class="btn" onclick="return confirm('Are you sure? This will delete the group for all members!')">Delete Group</button>
                        </form>
                    <?php endif; ?>
                </div>
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

    <script src="/assets/js/pageCustomization.js"></script>
</body>
</html>
