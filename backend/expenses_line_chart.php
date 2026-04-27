<?php
/**
 * expenses_line_chart.php
 * JSON endpoint for returning spending totals grouped by month.
 * Author: Jordon Jagunich
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../auth_guard.php";
require_once __DIR__ . "/../config/db.php";

$range = $_GET["range"] ?? "180d";
$groupID = $_SESSION["active_group_id"] ?? null;

if (!$groupID) { 
    http_response_code(400);
    echo json_encode(["error" => "No active group found"]);
    exit;
}

$startDate = null;
if ($range === "180d") {
    $startDate = (new DateTimeImmutable("now"))->modify("-180 days")->format("Y-m-d");
} elseif ($range === "month") {
    $startDate = (new DateTimeImmutable("first day of this month"))->format("Y-m-d");
} elseif ($range === "all") {
    $startDate = null;
} else {
    $range = "180d";
    $startDate = (new DateTimeImmutable("now"))->modify("-180 days")->format("Y-m-d");
}

try {
    $where = "WHERE group_id = :group_id";
    $params = [":group_id" => $groupID];

    if($startDate !==null) {
        $where .= " AND expense_date >= :start_date";
        $params[":start_date"] = $startDate;
    }

    $sql = "SELECT
                DATE_FORMAT(expense_date, '%Y-%m') AS month_key,
                DATE_FORMAT(expense_date, '%b %Y') AS month_label,
                SUM(amount) AS total
            FROM expenses
            $where
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m'), DATE_FORMAT(expense_date, '%b %Y')
        HAVING SUM(amount) > 0
        ORDER BY month_key ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $values = [];
    $sum = 0.0;

    foreach ($rows as $row) {
        $labels[] = (string)$row["month_label"];

        $value = round((float)$row["total"], 2);
        $values[] = $value;
        $sum += $value;
    }

   echo json_encode([
    "total" => round($sum, 2),
    "labels" => $labels,
    "values" => $values
]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Server error while building line chart data."
    ]);
}
