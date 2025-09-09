<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;

class ExpenseController extends Controller
{
    private IUserSession $userSession;
    private IDBConnection $db;

    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
        $this->userSession = \OC::$server->get(IUserSession::class);
        $this->db = \OC::$server->get(IDBConnection::class);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int $id Book id
     */
    public function index(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'book_id', 'user_uid', 'amount_cents', 'currency', 'description', 'occurred_at', 'created_at')
                ->from('fc_expenses')
                ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($id)))
                ->orderBy('occurred_at', 'DESC');

            // Optional filters
            // 1) Month list: month=YYYY-MM (repeatable) or months=YYYY-MM,YYYY-MM
            // 2) Range: from=YYYY-MM [& to=YYYY-MM]
            $monthsFilter = [];
            $monthParam = $this->request->getParam('month');
            if (is_array($monthParam)) {
                $monthsFilter = $monthParam;
            } elseif (is_string($monthParam) && $monthParam !== '') {
                $monthsFilter = [$monthParam];
            }
            $monthsCsv = $this->request->getParam('months');
            if (is_string($monthsCsv) && $monthsCsv !== '') {
                foreach (explode(',', $monthsCsv) as $m) { $monthsFilter[] = trim($m); }
            }
            $fromParam = $this->request->getParam('from');
            $toParam = $this->request->getParam('to');

            $rangeApplied = false;
            if (is_string($fromParam) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fromParam)) {
                try {
                    $fromDt = new \DateTimeImmutable($fromParam . '-01 00:00:00');
                    $qb->andWhere($qb->expr()->gte('occurred_at', $qb->createNamedParameter($fromDt->format('Y-m-d H:i:s'))));
                    $rangeApplied = true;
                } catch (\Throwable $e) {}
            }
            if (is_string($toParam) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $toParam)) {
                try {
                    $toDt = new \DateTimeImmutable($toParam . '-01 00:00:00');
                    $toEnd = $toDt->modify('first day of next month');
                    $qb->andWhere($qb->expr()->lt('occurred_at', $qb->createNamedParameter($toEnd->format('Y-m-d H:i:s'))));
                    $rangeApplied = true;
                } catch (\Throwable $e) {}
            }

            if (!$rangeApplied) {
                $months = [];
                foreach ($monthsFilter as $m) {
                    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { $months[] = $m; }
                }
                if (count($months) > 0) {
                    $or = [];
                    foreach ($months as $m) {
                        try {
                            $dt = new \DateTimeImmutable($m . '-01 00:00:00');
                            $start = $dt->format('Y-m-d H:i:s');
                            $end = $dt->modify('first day of next month')->format('Y-m-d H:i:s');
                            $or[] = $qb->expr()->andX(
                                $qb->expr()->gte('occurred_at', $qb->createNamedParameter($start)),
                                $qb->expr()->lt('occurred_at', $qb->createNamedParameter($end))
                            );
                        } catch (\Throwable $e) { /* ignore invalid */ }
                    }
                    if (count($or) > 0) {
                        $qb->andWhere(call_user_func_array([$qb->expr(), 'orX'], $or));
                    }
                }
            }
            $rows = $qb->execute()->fetchAll();
            return new JSONResponse(['expenses' => $rows]);
        } catch (\Throwable $e) {
            $logger = \OC::$server->get(\OCP\ILogger::class);
            $logger->error('FamilyBudget expenses query failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            return new JSONResponse(['message' => 'Internal error'], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @param int $id Book id
     */
    public function create(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }
        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        $amount = isset($input['amount']) ? (float)$input['amount'] : 0.0;
        $desc = isset($input['description']) ? trim((string)$input['description']) : null;
        $date = isset($input['date']) ? (string)$input['date'] : null; // YYYY-MM-DD
        $currency = isset($input['currency']) ? (string)$input['currency'] : 'EUR';
        if ($amount <= 0 || $date === null) {
            return new JSONResponse(['message' => 'amount>0 and date required'], 400);
        }
        $occurredAt = $date . ' 00:00:00';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $amountCents = (int)round($amount * 100);

        try {
            $this->db->beginTransaction();
            $qb = $this->db->getQueryBuilder();
            $qb->insert('fc_expenses')
                ->values([
                    'book_id' => $qb->createNamedParameter($id),
                    'user_uid' => $qb->createNamedParameter($uid),
                    'amount_cents' => $qb->createNamedParameter($amountCents),
                    'currency' => $qb->createNamedParameter($currency),
                    'description' => $qb->createNamedParameter($desc),
                    'occurred_at' => $qb->createNamedParameter($occurredAt),
                    'created_at' => $qb->createNamedParameter($now),
                ])->executeStatement();
            $newId = (int)$qb->getLastInsertId();
            $this->db->commit();
            return new JSONResponse(['id' => $newId], 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new JSONResponse(['message' => 'Create failed'], 500);
        }
    }

    private function isMember(int $bookId, string $uid): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('fc_book_members')
            ->where($qb->expr()->andX(
                $qb->expr()->eq('book_id', $qb->createNamedParameter($bookId)),
                $qb->expr()->eq('user_uid', $qb->createNamedParameter($uid))
            ))->setMaxResults(1);
        $row = $qb->executeQuery()->fetchOne();
        return $row !== false;
    }

    /**
     * @NoAdminRequired
     */
    public function delete(int $id, int $eid): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }
        try {
            $this->db->beginTransaction();
            $qb = $this->db->getQueryBuilder();
            $qb->delete('fc_expenses')->where($qb->expr()->andX(
                $qb->expr()->eq('id', $qb->createNamedParameter($eid)),
                $qb->expr()->eq('book_id', $qb->createNamedParameter($id))
            ));
            $qb->executeStatement();
            $this->db->commit();
            return new JSONResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new JSONResponse(['message' => 'Delete failed'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $id, int $eid): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }
        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        try {
            $this->db->beginTransaction();
            $qb = $this->db->getQueryBuilder();
            $qb->update('fc_expenses');
            $has = false;
            if (isset($input['amount'])) { $qb->set('amount_cents', $qb->createNamedParameter((int)round(((float)$input['amount']) * 100))); $has = true; }
            if (array_key_exists('description', $input)) { $desc = $input['description'] !== null ? (string)$input['description'] : null; $qb->set('description', $qb->createNamedParameter($desc)); $has = true; }
            if (isset($input['date'])) { $occ = ((string)$input['date']) . ' 00:00:00'; $qb->set('occurred_at', $qb->createNamedParameter($occ)); $has = true; }
            if (isset($input['currency'])) { $qb->set('currency', $qb->createNamedParameter((string)$input['currency'])); $has = true; }
            if (!$has) { $this->db->rollBack(); return new JSONResponse(['message' => 'Nothing to update'], 400); }
            $qb->where($qb->expr()->andX(
                $qb->expr()->eq('id', $qb->createNamedParameter($eid)),
                $qb->expr()->eq('book_id', $qb->createNamedParameter($id))
            ));
            $qb->executeStatement();
            $this->db->commit();
            return new JSONResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new JSONResponse(['message' => 'Update failed'], 500);
        }
    }
}
