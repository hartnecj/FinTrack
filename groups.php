<?php
/*
|--------------------------------------------------------------------------
| groups.php
|--------------------------------------------------------------------------
| Original Groups feature was implemented by teammate:
| - Create group (with hashed password)
| - Join group (password verify)
| - View group + members
| - Leave group (non-owner)
| - Remove member (owner)
| - Delete group (owner)
|
| Enhancements added here so we can "bring it home" and build budgets/expenses:
| - CSRF protection for all POST actions
| - Explicit group_id targeting (removes LIMIT 1 ambiguity)
| - Support for multiple groups per user (future-proof)
| - "Active group" stored in session (budgets/expenses will rely on this)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/auth_guard.php";
require_once __DIR__ . "/config/db.php";

$user_id = (int)$_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "User";

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| CSRF Protection
|--------------------------------------------------------------------------
| Every destructive action is a POST (good).
| This token check prevents cross-site request forgery.
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
| Fetch all groups for the current user
|--------------------------------------------------------------------------
| Original code assumed 1 group per user (LIMIT 1).
| We now pull all groups so we can support multi-group later.
|--------------------------------------------------------------------------
*/
function fetch_user_groups(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("
        SELECT g.id, g.name, g.owner_id, g.created_at
        FROM group_members gm
        JOIN groups g ON gm.group_id = g.id
        WHERE gm.user_id = ?
        ORDER BY g.created_at DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Fetch a specific group only if the user is a member
|--------------------------------------------------------------------------
| This prevents users from submitting POST actions against groups
| they are not a member of.
|--------------------------------------------------------------------------
*/
function fetch_group_if_member(PDO $pdo, int $user_id, int $group_id): ?array {
    $stmt = $pdo->prepare("
        SELECT g.id, g.name, g.owner_id, g.created_at
        FROM group_members gm
        JOIN groups g ON gm.group_id = g.id
        WHERE gm.user_id = ? AND g.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $group_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/*
|--------------------------------------------------------------------------
| Fetch members for display
|--------------------------------------------------------------------------
*/
function fetch_group_members(PDO $pdo, int $group_id): array {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, gm.joined_at
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ?
        ORDER BY gm.joined_at ASC
    ");
    $stmt->execute([$group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Handle POST actions
|--------------------------------------------------------------------------
| Major improvement:
| - Every action uses a specific group_id from the form
| - No more relying on LIMIT 1 membership queries
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';
    $group_id = (int)($_POST['group_id'] ?? 0);

    if ($action === 'set_active') {
        $group = fetch_group_if_member($pdo, $user_id, $group_id);
        if (!$group) {
            $error = "You can't set an invalid group as active.";
        } else {
            $_SESSION['active_group_id'] = $group_id;
            $success = "Active group updated.";
        }
    }

    if ($action === 'leave_group') {
        $group = fetch_group_if_member($pdo, $user_id, $group_id);

        if (!$group) {
            $error = "You are not a member of that group.";
        } elseif ((int)$group['owner_id'] === $user_id) {
            $error = "Group owners can't leave. Delete the group (or transfer ownership later).";
        } else {
            $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$group_id, $user_id]);

            // If they left the active group, unset it
            if (!empty($_SESSION['active_group_id']) && (int)$_SESSION['active_group_id'] === $group_id) {
                unset($_SESSION['active_group_id']);
            }

            $success = "You have left the group.";
        }
    }

    if ($action === 'remove_member') {
        $member_id = (int)($_POST['member_id'] ?? 0);
        $group = fetch_group_if_member($pdo, $user_id, $group_id);

        if (!$group) {
            $error = "Invalid group.";
        } elseif ((int)$group['owner_id'] !== $user_id) {
            $error = "Only the group owner can remove members.";
        } elseif ($member_id === $user_id) {
            $error = "You can't remove yourself.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$group_id, $member_id]);
            $success = "Member removed.";
        }
    }

    if ($action === 'delete_group') {
        $group = fetch_group_if_member($pdo, $user_id, $group_id);

        if (!$group) {
            $error = "Invalid group.";
        } elseif ((int)$group['owner_id'] !== $user_id) {
            $error = "Only the group owner can delete the group.";
        } else {
            // With ON DELETE CASCADE on group_members, memberships auto-delete
            $stmt = $pdo->prepare("DELETE FROM groups WHERE id = ?");
            $stmt->execute([$group_id]);

            if (!empty($_SESSION['active_group_id']) && (int)$_SESSION['active_group_id'] === $group_id) {
                unset($_SESSION['active_group_id']);
            }

            $success = "Group deleted.";
        }
    }
}

// Load groups for this user
$user_groups = fetch_user_groups($pdo, $user_id);

// If user has groups but no active selected, default to first
if (empty($_SESSION['active_group_id']) && !empty($user_groups)) {
    $_SESSION['active_group_id'] = (int)$user_groups[0]['id'];
}

$active_group_id = !empty($_SESSION['active_group_id']) ? (int)$_SESSION['active_group_id'] : 0;
$active_group = $active_group_id ? fetch_group_if_member($pdo, $user_id, $active_group_id) : null;

// If active group is invalid (edge case), unset it
if ($active_group_id && !$active_group) {
    unset($_SESSION['active_group_id']);
    $active_group_id = 0;
}

$members = [];
$is_owner = false;

if ($active_group) {
    $members = fetch_group_members($pdo, (int)$active_group['id']);
    $is_owner = ((int)$active_group['owner_id'] === $user_id);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Groups</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-4.0.0.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- NOTE: Corrected path to match actual project structure -->
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
        <h2>Groups</h2>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (empty($user_groups)): ?>
            <p>You are not currently in a group.</p>

            <div class="d-grid gap-2" style="max-width: 300px; margin: 30px auto;">
                <a href="/groups/create.php"><button class="btn w-100">Create a Group</button></a>
                <a href="/groups/join.php"><button class="btn w-100">Join a Group</button></a>
            </div>

        <?php else: ?>

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

            <div style="margin-top: 20px;">
                <a href="/groups/create.php"><button class="btn">Create another group</button></a>
                <a href="/groups/join.php"><button class="btn">Join another group</button></a>
            </div>

            <?php if ($active_group): ?>
                <hr style="margin: 30px 0;">

                <h3><?php echo htmlspecialchars($active_group['name']); ?></h3>
                <?php if ($is_owner): ?>
                    <p><small>(You are the group owner)</small></p>
                <?php endif; ?>

                <p><small>Created: <?php echo date('F j, Y', strtotime($active_group['created_at'])); ?></small></p>

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
                                <?php if ((int)$member['id'] === (int)$active_group['owner_id']): ?>
                                    <strong>Owner</strong>
                                <?php else: ?>
                                    Member
                                <?php endif; ?>
                            </td>

                            <?php if ($is_owner): ?>
                                <td>
                                    <?php if ((int)$member['id'] !== $user_id): ?>
                                        <form method="post" action="/groups.php" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="group_id" value="<?php echo (int)$active_group['id']; ?>">
                                            <input type="hidden" name="member_id" value="<?php echo (int)$member['id']; ?>">
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
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="leave_group">
                            <input type="hidden" name="group_id" value="<?php echo (int)$active_group['id']; ?>">
                            <button type="submit" class="btn" onclick="return confirm('Leave this group?')">Leave Group</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/groups.php" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_id" value="<?php echo (int)$active_group['id']; ?>">
                            <button type="submit" class="btn" onclick="return confirm('Delete the group for all members?')">Delete Group</button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

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

<!-- NOTE: Corrected path to match actual project structure -->
<script src="/assets/pageCustomization.js"></script>
</body>
</html>
