<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Service;

use OCP\IDBConnection;

class ExpenseService
{
    private IDBConnection $db;
    private ExpensePayloadValidator $validator;
    private ExpenseMapper $mapper;

    public function __construct(IDBConnection $db, ExpensePayloadValidator $validator, ExpenseMapper $mapper)
    {
        $this->db = $db;
        $this->validator = $validator;
        $this->mapper = $mapper;
    }

    public function isMember(int $bookId, string $uid): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('fc_book_members')
            ->where($qb->expr()->andX(
                $qb->expr()->eq('book_id', $qb->createNamedParameter($bookId)),
                $qb->expr()->eq('user_uid', $qb->createNamedParameter($uid))
            ))
            ->setMaxResults(1);

        return $qb->executeQuery()->fetchOne() !== false;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return list<array<string, mixed>>
     */
    public function listExpenses(int $bookId, array $queryParams): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'book_id', 'user_uid', 'amount_cents', 'currency', 'description', 'occurred_at', 'created_at')
            ->from('fc_expenses')
            ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($bookId)))
            ->orderBy('occurred_at', 'DESC');

        $monthsFilter = [];
        $monthParam = $queryParams['month'] ?? null;
        if (is_array($monthParam)) {
            $monthsFilter = $monthParam;
        } elseif (is_string($monthParam) && $monthParam !== '') {
            $monthsFilter = [$monthParam];
        }

        $monthsCsv = $queryParams['months'] ?? null;
        if (is_string($monthsCsv) && $monthsCsv !== '') {
            foreach (explode(',', $monthsCsv) as $month) {
                $monthsFilter[] = trim($month);
            }
        }

        $rangeApplied = false;
        $fromParam = $queryParams['from'] ?? null;
        if (is_string($fromParam) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fromParam)) {
            $fromDt = new \DateTimeImmutable($fromParam . '-01 00:00:00');
            $qb->andWhere($qb->expr()->gte('occurred_at', $qb->createNamedParameter($fromDt->format('Y-m-d H:i:s'))));
            $rangeApplied = true;
        }

        $toParam = $queryParams['to'] ?? null;
        if (is_string($toParam) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $toParam)) {
            $toDt = new \DateTimeImmutable($toParam . '-01 00:00:00');
            $toEnd = $toDt->modify('first day of next month');
            $qb->andWhere($qb->expr()->lt('occurred_at', $qb->createNamedParameter($toEnd->format('Y-m-d H:i:s'))));
            $rangeApplied = true;
        }

        if (!$rangeApplied) {
            $monthClauses = [];
            foreach ($monthsFilter as $month) {
                if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                    continue;
                }

                $dt = new \DateTimeImmutable($month . '-01 00:00:00');
                $monthClauses[] = $qb->expr()->andX(
                    $qb->expr()->gte('occurred_at', $qb->createNamedParameter($dt->format('Y-m-d H:i:s'))),
                    $qb->expr()->lt('occurred_at', $qb->createNamedParameter($dt->modify('first day of next month')->format('Y-m-d H:i:s')))
                );
            }

            if ($monthClauses !== []) {
                $qb->andWhere(call_user_func_array([$qb->expr(), 'orX'], $monthClauses));
            }
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();
        return $this->mapper->mapExpenseRows($rows);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createExpense(int $bookId, string $currentUid, array $input): int
    {
        $payload = $this->validator->validateCreatePayload($input, $currentUid);
        $expenseUid = $payload['user_uid'];
        if (!$this->isMember($bookId, $expenseUid)) {
            throw new ExpenseValidationException('user_uid must be a member of the book');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('fc_expenses')
                ->values([
                    'book_id' => $qb->createNamedParameter($bookId),
                    'user_uid' => $qb->createNamedParameter($expenseUid),
                    'amount_cents' => $qb->createNamedParameter($payload['amount_cents']),
                    'currency' => $qb->createNamedParameter($payload['currency']),
                    'description' => $qb->createNamedParameter($payload['description']),
                    'occurred_at' => $qb->createNamedParameter($payload['occurred_at']),
                    'created_at' => $qb->createNamedParameter($now),
                ])
                ->executeStatement();

            $newId = (int)$qb->getLastInsertId();
            $this->db->commit();
            return $newId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateExpense(int $bookId, int $expenseId, array $input): void
    {
        $payload = $this->validator->validateUpdatePayload($input);
        if (isset($payload['user_uid']) && !$this->isMember($bookId, (string)$payload['user_uid'])) {
            throw new ExpenseValidationException('user_uid must be a member of the book');
        }

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('fc_expenses');

            foreach ($payload as $column => $value) {
                $qb->set($column, $qb->createNamedParameter($value));
            }

            $qb->where($qb->expr()->andX(
                $qb->expr()->eq('id', $qb->createNamedParameter($expenseId)),
                $qb->expr()->eq('book_id', $qb->createNamedParameter($bookId))
            ));
            $qb->executeStatement();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
