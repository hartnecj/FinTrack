<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>FinTrack - Home</title>
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
      <img src="https://static0.thegamerimages.com/wordpress/wp-content/uploads/2022/10/zenyatta-hero-select.jpg?w=1600&h=1200&fit=crop" class="img-fluid">
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
