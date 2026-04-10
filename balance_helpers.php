<?php

function split_amount_evenly(float $amount, int $count): array {
    $totalCents = (int) round($amount * 100);
    $base = intdiv($totalCents, $count);
    $remainder = $totalCents % $count;

    $splits = array_fill(0, $count, $base);

    for ($i = 0; $i < $remainder; $i++) {
        $splits[$i]++;
    }

    return array_map(function ($cents) {
        return $cents / 100;
    }, $splits);
}

function get_group_balances(PDO $pdo, int $group_id): array {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            COALESCE(paid.total_paid, 0) AS total_paid,
            COALESCE(owed.total_owed, 0) AS total_owed,
            COALESCE(paid.total_paid, 0) - COALESCE(owed.total_owed, 0) AS net_balance
        FROM group_members gm
        JOIN users u
            ON u.id = gm.user_id
        LEFT JOIN (
            SELECT user_id, group_id, SUM(amount) AS total_paid
            FROM expenses
            GROUP BY user_id, group_id
        ) paid
            ON paid.user_id = gm.user_id
           AND paid.group_id = gm.group_id
        LEFT JOIN (
            SELECT es.user_id, e.group_id, SUM(es.amount_owed) AS total_owed
            FROM expense_splits es
            JOIN expenses e ON e.id = es.expense_id
            GROUP BY es.user_id, e.group_id
        ) owed
            ON owed.user_id = gm.user_id
           AND owed.group_id = gm.group_id
        WHERE gm.group_id = ?
        ORDER BY u.name ASC
    ");
    $stmt->execute([$group_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['total_paid'] = round((float)$row['total_paid'], 2);
        $row['total_owed'] = round((float)$row['total_owed'], 2);
        $row['net_balance'] = round((float)$row['net_balance'], 2);
    }

    return $rows;
}

function get_settlements_from_balances(array $balances): array {
    $creditors = [];
    $debtors = [];

    foreach ($balances as $b) {
        $net = round((float)$b['net_balance'], 2);

        if ($net > 0.009) {
            $creditors[] = [
                'user_id' => (int)$b['id'],
                'name' => $b['name'],
                'amount' => $net
            ];
        } elseif ($net < -0.009) {
            $debtors[] = [
                'user_id' => (int)$b['id'],
                'name' => $b['name'],
                'amount' => abs($net)
            ];
        }
    }

    $settlements = [];
    $i = 0;
    $j = 0;

    while ($i < count($debtors) && $j < count($creditors)) {
        $payAmount = min($debtors[$i]['amount'], $creditors[$j]['amount']);
        $payAmount = round($payAmount, 2);

        if ($payAmount > 0) {
            $settlements[] = [
                'from_user_id' => $debtors[$i]['user_id'],
                'from_name' => $debtors[$i]['name'],
                'to_user_id' => $creditors[$j]['user_id'],
                'to_name' => $creditors[$j]['name'],
                'amount' => $payAmount
            ];
        }

        $debtors[$i]['amount'] = round($debtors[$i]['amount'] - $payAmount, 2);
        $creditors[$j]['amount'] = round($creditors[$j]['amount'] - $payAmount, 2);

        if ($debtors[$i]['amount'] <= 0.009) {
            $i++;
        }

        if ($creditors[$j]['amount'] <= 0.009) {
            $j++;
        }
    }

    return $settlements;
}