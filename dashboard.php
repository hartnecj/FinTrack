<?php
require_once __DIR__ . "/auth_guard.php";
$name = $_SESSION["user_name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>FinTrack - Dashboard</title>

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
        <h2>Dashboard</h2>
        <p>Welcome, <?php echo htmlspecialchars($name); ?>.</p>

        <div class="d-grid gap-2" style="max-width: 300px; margin: 0 auto;">
          <a href="/budgets.php"><button class="btn w-100">Budgets</button></a>
          <a href="/expenses.php"><button class="btn w-100">Expenses</button></a>
          <a href="/groups.php"><button class="btn w-100">Groups</button></a>
        </div>
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
