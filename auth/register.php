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
  <!-- head tag -->
  <head>
    <!-- title -->
    <title>FinTrack- SignUp</title>
    <!-- link to bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
    <!-- link to JQuery -->
    <script src="https://code.jquery.com/jquery-4.0.0.js" integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U=" crossorigin="anonymous"></script>
    <!-- meta viewport tag -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- link to stylesheet -->
    <link rel="stylesheet" href="../assets/landingStyles.css">
  </head><!-- end head tag -->
  <!-- start body tag -->
  <body>
      
    <nav><!-- navigation section -->
      <ul>
        <li><a href="../landing.php"><button class="btn page-btn">Home</button></a></li>
        <li><a href="/auth/login.php"><button class="btn page-btn">Login</button></a></li>
        <li><a href="#"><button class="btn page-btn">Register</button></a></li>
      </ul>
    </nav><!-- end navigation-->

    <!-- main section -->
    <section class="main-container shadow-lg">
      <!-- start form section -->
      <form method="POST" action="">
        <fieldset class="form-group">
          <legend>Sign Up</legend>
          
          <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>
          
          <!-- email input -->
          <label for="email">E-Mail</label><br>
          <div class="mb-3 input-group">
            <input type="email" id="email" name="email" class="form-control" aria-label="Email input" aria-description="email-input box for entering email" required>
          </div>
          
          <!-- password input -->
          <label for="password">Password</label><br>
          <div class="mb-3 input-group">
            <input type="password" id="password" name="password" class="form-control" aria-label="Password input" aria-description="password-input box for entering password"required>
          </div>
          
          <!-- password confirm input -->
          <label for="passwordConfirm">Password Confirm</label><br>
          <div class="mb-3 input-group">
            <input type="password" id="passwordConfirm" name="passwordConfirm" class="form-control" aria-label="Password input confirmation" aria-description="password-input box for entering password to check if they match" required>
          </div>
        </fieldset>
        
        <fieldset class="form-group">
          
          <!-- name input -->
          <label for="first-name" class="input-label">First Name</label><label for="last-name" class="input-label left-input-label">Last Name</label>
          <div class="mb-3 input-group">
            <!-- first name input -->
            <input type="text" id="first-name" name="first-name" class="form-control" aria-label="First-Name input" aria-description="first-name input box" required>
            <!-- last name input -->
            <input type="text" id="last-name" name="last-name" class="form-control" aria-label="Last-Name input" aria-description="last-name input box" required>
          </div>
          
        </fieldset>
        <!-- submit button -->
        <input class="btn" type="submit" value="Sign up" id="signup-btn">
      </form>
    </section><!-- end main section -->
    
    <!-- footer section -->
    <footer>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" id="styleSwitch">
        <label class="form-check-label" for="styleSwitch" id="styleLabel"> Light mode: On </label>
      </div>
    </footer><!-- end footer -->

    <!-- link to JS -->
    <script src="../assets/pageCustomization.js">     
    </script>
  </body><!-- end body tag -->
</html><!-- end html tag -->
