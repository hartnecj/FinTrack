<?php
/**
 * expenses_pie_chart.php
 * JSON endpoint for returning spending totals grouped by category.
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../auth_guard.php";
require_once __DIR__ . "/../config/db.php";

// Read request
// - 180 days (default)
// - month (from first day of current month)
// - all  (no start date)
$range = $_GET["range"] ?? "180d";

// Determine active group (adjust keys if your session uses different names)
$groupID = $_SESSION["active_group_id"] ?? null;

if (!$groupID) {
  http_response_code(400);
  echo json_encode(["error" => "No active group found"]);
  exit;
}

// Range dates
$endDate = (new DateTimeImmutable("now"))->format("Y-m-d");
$startDate = null;

if ($range === "180d") {
  $startDate = (new DateTimeImmutable("now"))->modify("-180 days")->format("Y-m-d");
} elseif ($range === "month") {
  $startDate = (new DateTimeImmutable("first day of this month"))->format("Y-m-d");
} elseif ($range === "all") {
  $startDate = null; // fetch all data
} else {
  // fallback
  $range = "180d";
  $startDate = (new DateTimeImmutable("now"))->modify("-180 days")->format("Y-m-d");
}

// Query database
// table: expenses
// columns (YOU MUST MATCH YOUR DB): amount, category, expense_date, group_id
try {
  $where = "WHERE group_id = :group_id";
  $params = [":group_id" => $groupID];

  if ($startDate !== null) {
    $where .= " AND expense_date >= :start_date";
    $params[":start_date"] = $startDate;
  }

  // Optional category filter support
  $sql = "
    SELECT
      COALESCE(category, 'Uncategorized') AS category,
      SUM(amount) AS total
    FROM expenses
    $where
    GROUP BY COALESCE(category, 'Uncategorized')
    HAVING SUM(amount) > 0
    ORDER BY total DESC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Shape data for Chart.js
  $labels = [];
  $values = [];
  $sum = 0.0;

  foreach ($rows as $row) {
    $cat = (string)($row["category"] ?? "Uncategorized");
    $val = (float)($row["total"] ?? 0);

    if ($val <= 0) continue;

    $labels[] = $cat;
    $values[] = round($val, 2);
    $sum += $val;
  }

  // Output once (NOT inside the loop)
  echo json_encode([
  "total" => round($sum, 2),
  "labels" => $labels,
  "values" => $values
]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "error" => "Server error while building pie chart data."
  ]);
}