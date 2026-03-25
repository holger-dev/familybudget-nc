<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Service;

class ExpenseMapper
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function mapExpenseRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'book_id' => (int)($row['book_id'] ?? 0),
            'user_uid' => (string)($row['user_uid'] ?? ''),
            'amount_cents' => (int)($row['amount_cents'] ?? 0),
            'currency' => (string)($row['currency'] ?? 'EUR'),
            'description' => array_key_exists('description', $row) && $row['description'] !== null
                ? (string)$row['description']
                : null,
            'occurred_at' => (string)($row['occurred_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function mapExpenseRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->mapExpenseRow($row), $rows);
    }
}
