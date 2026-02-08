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

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email or password.';
        } else {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];

            header('Location: /dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>FinTrack- Login</title>
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
      <form method="post" action="/auth/login.php" autocomplete="on">
        <fieldset class="form-group">
          <legend>Login</legend>

          <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>

          <label for="email">E-Mail</label><br>
          <div class="mb-3 input-group">
            <input type="email" id="email" name="email" class="form-control" required>
          </div>

          <label for="password">Password</label><br>
          <div class="mb-3 input-group">
            <input type="password" id="password" name="password" class="form-control" required>
          </div>

          <h6><a href="#">Forgot password?</a></h6>
        </fieldset>

        <input type="submit" class="btn" value="Login" id="login-btn">
      </form>
    </section>

    <h6 id="signup"><a href="/auth/register.php">New? Sign up</a></h6>

    <footer>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" id="styleSwitch">
        <label class="form-check-label" for="styleSwitch" id="styleLabel"> Light mode: On </label>
      </div>
    </footer>

    <script src="/assets/pageCustomization.js"></script>
  </body>
</html>
