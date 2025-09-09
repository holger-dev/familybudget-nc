#!/usr/bin/env php
<?php
// Simple OCS API smoke test for FamilyBudget
// Usage:
//   php scripts/ocs_smoke.php <base> <username> <app-password>
// Example base:
//   https://cloud.example.com/ocs/v2.php/apps/familybudget

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/ocs_smoke.php <base> <username> <app-password>\n");
    exit(1);
}

[$script, $base, $user, $pass] = $argv;

function req(string $base, string $user, string $pass, string $path, string $method = 'GET', $body = null): array {
    $url = rtrim($base, '/') . $path;
    $ch = curl_init($url);
    $headers = [
        'OCS-APIRequest: true',
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $payload = is_string($body) ? $body : json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException("cURL error: $err");
    }
    curl_close($ch);
    $data = json_decode($resp, true);
    return [$status, $data ?? $resp];
}

function printStep(string $title) {
    fwrite(STDOUT, "\n== $title ==\n");
}

try {
    // 1) List books
    printStep('GET /books');
    [$st, $data] = req($base, $user, $pass, '/books');
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 200) { throw new \RuntimeException('Books list failed'); }

    // 2) Create a temp book
    $name = 'API Smoke ' . date('Y-m-d H:i:s');
    printStep('POST /books');
    [$st, $data] = req($base, $user, $pass, '/books', 'POST', ['name' => $name]);
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 201 || !isset($data['id'])) { throw new \RuntimeException('Book create failed'); }
    $bookId = (int)$data['id'];

    // 3) Rename book (PATCH /books/{id})
    printStep('PATCH /books/{id} (rename)');
    [$st, $data] = req($base, $user, $pass, "/books/$bookId", 'PATCH', ['name' => $name . ' (renamed)']);
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 200) { throw new \RuntimeException('Book rename failed'); }

    // 4) Create expense
    printStep('POST /books/{id}/expenses');
    $createExpense = [
        'amount' => 12.34,
        'date' => date('Y-m-d'),
        'description' => 'Smoke Test Expense',
        'currency' => 'EUR',
    ];
    [$st, $data] = req($base, $user, $pass, "/books/$bookId/expenses", 'POST', $createExpense);
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 201 || !isset($data['id'])) { throw new \RuntimeException('Expense create failed'); }
    $expenseId = (int)$data['id'];

    // 5) List expenses
    printStep('GET /books/{id}/expenses');
    [$st, $data] = req($base, $user, $pass, "/books/$bookId/expenses");
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 200) { throw new \RuntimeException('Expenses list failed'); }

    // 6) Update expense
    printStep('PATCH /books/{id}/expenses/{eid}');
    [$st, $data] = req($base, $user, $pass, "/books/$bookId/expenses/$expenseId", 'PATCH', [
        'amount' => 20.00,
        'description' => 'Updated by smoke test',
    ]);
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 200) { throw new \RuntimeException('Expense update failed'); }

    // 7) Delete expense
    printStep('DELETE /books/{id}/expenses/{eid}');
    [$st, $data] = req($base, $user, $pass, "/books/$bookId/expenses/$expenseId", 'DELETE');
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 200) { throw new \RuntimeException('Expense delete failed'); }

    // 8) Delete book
    printStep('DELETE /books/{id}');
    [$st, $data] = req($base, $user, $pass, "/books/$bookId", 'DELETE');
    echo "Status: $st\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    if ($st !== 200) { throw new \RuntimeException('Book delete failed'); }

    fwrite(STDOUT, "\nSmoke test completed successfully.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(2);
}

