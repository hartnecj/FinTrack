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

function get_group_members(PDO $pdo, int $group_id): array {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name
        FROM group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY u.name ASC
    ");
    $stmt->execute([$group_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
    }

    return $rows;
}

function get_group_balances(PDO $pdo, int $group_id): array {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            COALESCE(paid.total_paid, 0) AS total_paid,
            COALESCE(owed.total_owed, 0) AS total_owed,
            COALESCE(settled.total_sent, 0) AS total_sent,
            COALESCE(received.total_received, 0) AS total_received,
            (
                COALESCE(paid.total_paid, 0)
                - COALESCE(owed.total_owed, 0)
                + COALESCE(settled.total_sent, 0)
                - COALESCE(received.total_received, 0)
            ) AS net_balance
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
        LEFT JOIN (
            SELECT payer_user_id AS user_id, group_id, SUM(amount) AS total_sent
            FROM settlements
            GROUP BY payer_user_id, group_id
        ) settled
            ON settled.user_id = gm.user_id
           AND settled.group_id = gm.group_id
        LEFT JOIN (
            SELECT payee_user_id AS user_id, group_id, SUM(amount) AS total_received
            FROM settlements
            GROUP BY payee_user_id, group_id
        ) received
            ON received.user_id = gm.user_id
           AND received.group_id = gm.group_id
        WHERE gm.group_id = ?
        ORDER BY u.name ASC
    ");
    $stmt->execute([$group_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['total_paid'] = round((float)$row['total_paid'], 2);
        $row['total_owed'] = round((float)$row['total_owed'], 2);
        $row['total_sent'] = round((float)$row['total_sent'], 2);
        $row['total_received'] = round((float)$row['total_received'], 2);
        $row['net_balance'] = round((float)$row['net_balance'], 2);

        $row['current_owes'] = $row['net_balance'] < 0
            ? round(abs($row['net_balance']), 2)
            : 0.00;

        $row['current_gets_back'] = $row['net_balance'] > 0
            ? round($row['net_balance'], 2)
            : 0.00;
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

function get_group_payment_history(PDO $pdo, int $group_id, int $limit = 10): array {
    $limit = max(1, $limit);

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.amount,
            s.payment_date,
            s.note,
            payer.name AS payer_name,
            payee.name AS payee_name,
            creator.name AS created_by_name
        FROM settlements s
        JOIN users payer ON payer.id = s.payer_user_id
        JOIN users payee ON payee.id = s.payee_user_id
        JOIN users creator ON creator.id = s.created_by
        WHERE s.group_id = ?
        ORDER BY s.payment_date DESC, s.created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$group_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}