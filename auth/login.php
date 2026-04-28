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
    <!-- title tag -->
    <title>FinTrack- Login</title>
    <!-- link to bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
        <!-- meta viewport tag -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- link to stylesheet -->
    <link rel="stylesheet" href="/assets/style.css">
  </head><!-- end head tag -->
  <body class="ft-page">

   <nav><!-- navigation section -->
      <ul>
        <li><a href="/landing.php"><button class="btn page-btn">Home</button></a></li>
        <li><a href="#"><button class="btn page-btn">Login</button></a></li>
        <li><a href="/auth/register.php"><button class="btn page-btn">Register</button></a></li>
      </ul>
    </nav><!-- end navigation-->z
    
    <!-- main section -->
    <section class="main-container shadow-lg p-5">
      <!-- start form tag -->
      <form method="POST" action="">
        <fieldset class="form-group">
          <legend>Login</legend>
          <!-- username input -->
          <label for="email">Email</label><br>
          <div class="mb-3 input-group">
            <input type="email" id="email" name="email" class="form-control" aria-label="Email input" aria-description="text-input box for entering email" required>
          </div>
          <!-- password input -->
          <label for="password">Password</label><br>
          <div class="mb-3 input-group">
            <input type="password" id="password" name="password" class="form-control" aria-label="Password input" aria-description="password-input box for entering password"required>
          </div>
          <!-- forgot password link 
          <h6 class="text-center"><a href="#">Forgot password?</a></h6> -->
        </fieldset>
        <!-- submit button -->
        <input type="submit" class="btn" value="Login" id="login-btn">
      </form><!-- end form tag -->
    </section>
    <!-- end main section -->
    <!-- signup link -->
    <h6 id="signup" class="text-center"><a href="/auth/register.php">New? Sign up</a></h6>

      </script>
    
  </body><!-- end body tag -->
</html><!-- end HTML tag -->