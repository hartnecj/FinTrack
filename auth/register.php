<?php
session_start();
require_once __DIR__ . '/../config/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['passwordConfirm'] ?? '';
    $first = trim($_POST['first-name'] ?? '');
    $last = trim($_POST['last-name'] ?? '');

    $name = trim($first . ' ' . $last);

    if ($email === '' || $password === '' || $passwordConfirm === '' || $name === '') {
        $error = 'Please fill out all required fields.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'That email is already registered. Try logging in.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, $hash]);

            header('Location: /auth/login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>FinTrack- SignUp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-4.0.0.js" integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U=" crossorigin="anonymous"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/style.css">
  </head>
  <body>
    <nav>
      <ul>
        <li><a href="/landing.php"><button class="btn">Home</button></a></li>
        <li><a href="/auth/login.php"><button class="btn">Login</button></a></li>
        <li><a href="/auth/register.php"><button class="btn">Sign Up</button></a></li>
      </ul>
    </nav>

    <section class="main-container shadow-lg">
      <form method="post" action="/auth/register.php" autocomplete="on">
        <fieldset class="form-group">
          <legend>Sign Up</legend>

          <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <label for="username">Desired Username</label><br>
          <div class="mb-3 input-group">
            <input type="text" id="username" name="username" class="form-control" required>
          </div>

          <label for="password">Password</label><br>
          <div class="mb-3 input-group">
            <input type="password" id="password" name="password" class="form-control" required>
          </div>

          <label for="passwordConfirm">Password Confirm</label><br>
          <div class="mb-3 input-group">
            <input type="password" id="passwordConfirm" name="passwordConfirm" class="form-control" required>
          </div>
        </fieldset>

        <fieldset class="form-group">
          <label for="email">E-Mail</label><br>
          <div class="mb-3 input-group">
            <input type="email" id="email" name="email" class="form-control" required>
          </div>

          <label for="first-name">First Name</label><label for="last-name">Last Name</label>
          <div class="mb-3 input-group">
            <input type="text" id="first-name" name="first-name" class="form-control" required>
            <input type="text" id="last-name" name="last-name" class="form-control" required>
          </div>
        </fieldset>

        <input class="btn" type="submit" value="Sign Up" id="login-btn">
      </form>
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
