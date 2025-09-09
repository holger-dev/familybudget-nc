<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Controller;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;

class OcsApiController extends OCSController
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
     */
    public function books(): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('b.id', 'b.name', 'b.owner_uid', 'm.role')
                ->from('fc_books', 'b')
                ->innerJoin('b', 'fc_book_members', 'm', 'b.id = m.book_id')
                ->where($qb->expr()->eq('m.user_uid', $qb->createNamedParameter($uid)));
            $rows = $qb->execute()->fetchAll();
            if (!is_array($rows) || count($rows) === 0) {
                $qb2 = $this->db->getQueryBuilder();
                $qb2->select('id', 'name', 'owner_uid')
                    ->from('fc_books')
                    ->where($qb2->expr()->eq('owner_uid', $qb2->createNamedParameter($uid)));
                $owned = $qb2->execute()->fetchAll();
                $owned = array_map(static function(array $r) { $r['role'] = 'owner'; return $r; }, $owned);
                return new DataResponse(['books' => $owned]);
            }
            return new DataResponse(['books' => $rows]);
        } catch (\Throwable $e) {
            $logger = \OC::$server->get(\OCP\ILogger::class);
            $logger->error('FamilyBudget OCS books failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            return new DataResponse(['message' => 'Internal error'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function booksCreate(): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $uid = $user->getUID();
        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        $name = isset($input['name']) ? trim((string)$input['name']) : '';
        if ($name === '') { return new DataResponse(['message' => 'Name required'], 400); }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $this->db->beginTransaction();
            $qb = $this->db->getQueryBuilder();
            $qb->insert('fc_books')->values([
                'owner_uid' => $qb->createNamedParameter($uid),
                'name' => $qb->createNamedParameter($name),
                'created_at' => $qb->createNamedParameter($now),
            ])->executeStatement();
            $qbId = $this->db->getQueryBuilder();
            $qbId->select('id')->from('fc_books')
                ->where($qbId->expr()->andX(
                    $qbId->expr()->eq('owner_uid', $qbId->createNamedParameter($uid)),
                    $qbId->expr()->eq('name', $qbId->createNamedParameter($name)),
                    $qbId->expr()->eq('created_at', $qbId->createNamedParameter($now))
                ))->orderBy('id','DESC')->setMaxResults(1);
            $bookId = (int)($qbId->execute()->fetchColumn() ?: 0);
            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('fc_book_members')->values([
                'book_id' => $qb2->createNamedParameter($bookId),
                'user_uid' => $qb2->createNamedParameter($uid),
                'role' => $qb2->createNamedParameter('owner'),
                'created_at' => $qb2->createNamedParameter($now),
            ])->executeStatement();
            $this->db->commit();
            return new DataResponse(['id' => $bookId, 'name' => $name, 'owner_uid' => $uid, 'role' => 'owner'], 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new DataResponse(['message' => 'Create failed'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function booksRename(int $id): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $uid = $user->getUID();
        $qbCheck = $this->db->getQueryBuilder();
        $qbCheck->select('role')->from('fc_book_members')
            ->where($qbCheck->expr()->andX(
                $qbCheck->expr()->eq('book_id', $qbCheck->createNamedParameter($id)),
                $qbCheck->expr()->eq('user_uid', $qbCheck->createNamedParameter($uid))
            ))->setMaxResults(1);
        $role = $qbCheck->executeQuery()->fetchOne();
        if ($role !== 'owner') { return new DataResponse(['message' => 'Forbidden'], 403); }
        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        $name = isset($input['name']) ? trim((string)$input['name']) : '';
        if ($name === '') { return new DataResponse(['message' => 'Name required'], 400); }
        $qb = $this->db->getQueryBuilder();
        $qb->update('fc_books')->set('name', $qb->createNamedParameter($name))
            ->where($qb->expr()->andX(
                $qb->expr()->eq('id', $qb->createNamedParameter($id)),
                $qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid))
            ));
        $affected = $qb->executeStatement();
        if ($affected < 1) { return new DataResponse(['message' => 'Not found'], 404); }
        return new DataResponse(['ok' => true, 'id' => $id, 'name' => $name]);
    }

    /**
     * @NoAdminRequired
     */
    public function booksInvite(int $id): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $uid = $user->getUID();
        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        $inviteUid = isset($input['user']) ? trim((string)$input['user']) : '';
        if ($inviteUid === '') { return new DataResponse(['message' => 'user required'], 400); }
        $qb = $this->db->getQueryBuilder();
        $qb->select('role')->from('fc_book_members')
            ->where($qb->expr()->andX(
                $qb->expr()->eq('book_id', $qb->createNamedParameter($id)),
                $qb->expr()->eq('user_uid', $qb->createNamedParameter($uid))
            ));
        $role = $qb->executeQuery()->fetchOne();
        if ($role === false) { return new DataResponse(['message' => 'Forbidden'], 403); }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('fc_book_members')->values([
                'book_id' => $qb2->createNamedParameter($id),
                'user_uid' => $qb2->createNamedParameter($inviteUid),
                'role' => $qb2->createNamedParameter('member'),
                'created_at' => $qb2->createNamedParameter($now),
            ])->executeStatement();
        } catch (\Throwable $e) {
            // ignore duplicate
        }
        return new DataResponse(['ok' => true]);
    }

    /**
     * @NoAdminRequired
     */
    public function booksMembers(int $id): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $uid = $user->getUID();
        $qbCheck = $this->db->getQueryBuilder();
        $qbCheck->select('id')->from('fc_book_members')
            ->where($qbCheck->expr()->andX(
                $qbCheck->expr()->eq('book_id', $qbCheck->createNamedParameter($id)),
                $qbCheck->expr()->eq('user_uid', $qbCheck->createNamedParameter($uid))
            ))->setMaxResults(1);
        $isMember = $qbCheck->executeQuery()->fetchOne();
        if ($isMember === false) { return new DataResponse(['message' => 'Forbidden'], 403); }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid', 'role', 'created_at')->from('fc_book_members')
            ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($id)))
            ->orderBy('created_at', 'ASC');
        $rows = $qb->execute()->fetchAll();
        if (!is_array($rows) || count($rows) === 0) {
            $qbOwner = $this->db->getQueryBuilder();
            $qbOwner->select('owner_uid')->from('fc_books')
                ->where($qbOwner->expr()->eq('id', $qbOwner->createNamedParameter($id)))
                ->setMaxResults(1);
            $owner = $qbOwner->executeQuery()->fetchOne();
            if (is_string($owner) && $owner !== '') {
                $rows = [[ 'user_uid' => $owner, 'role' => 'owner', 'created_at' => null ]];
            }
        }
        try {
            $um = \OC::$server->get(\OCP\IUserManager::class);
            foreach ($rows as &$r) {
                $uid2 = $r['user_uid'] ?? '';
                $user2 = $um->get($uid2);
                $r['display_name'] = $user2 ? $user2->getDisplayName() : $uid2;
            }
        } catch (\Throwable $e) {}
        return new DataResponse(['members' => $rows]);
    }

    /**
     * @NoAdminRequired
     */
    public function booksRemoveMember(int $id, string $uid): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $caller = $user->getUID();
        $qbC = $this->db->getQueryBuilder();
        $qbC->select('role')->from('fc_book_members')
            ->where($qbC->expr()->andX(
                $qbC->expr()->eq('book_id', $qbC->createNamedParameter($id)),
                $qbC->expr()->eq('user_uid', $qbC->createNamedParameter($caller))
            ))->setMaxResults(1);
        $role = $qbC->executeQuery()->fetchOne();
        if ($role !== 'owner') { return new DataResponse(['message' => 'Forbidden'], 403); }
        $qbOwner = $this->db->getQueryBuilder();
        $qbOwner->select('owner_uid')->from('fc_books')
            ->where($qbOwner->expr()->eq('id', $qbOwner->createNamedParameter($id)))
            ->setMaxResults(1);
        $owner = $qbOwner->executeQuery()->fetchOne();
        if ($owner === $uid) { return new DataResponse(['message' => 'Cannot remove owner'], 400); }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('fc_book_members')->where($qb->expr()->andX(
            $qb->expr()->eq('book_id', $qb->createNamedParameter($id)),
            $qb->expr()->eq('user_uid', $qb->createNamedParameter($uid))
        ))->executeStatement();
        return new DataResponse(['ok' => true]);
    }

    /**
     * @NoAdminRequired
     */
    public function booksDelete(int $id): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $uid = $user->getUID();
        $qbC = $this->db->getQueryBuilder();
        $qbC->select('role')->from('fc_book_members')
            ->where($qbC->expr()->andX(
                $qbC->expr()->eq('book_id', $qbC->createNamedParameter($id)),
                $qbC->expr()->eq('user_uid', $qbC->createNamedParameter($uid))
            ))->setMaxResults(1);
        $role = $qbC->executeQuery()->fetchOne();
        if ($role !== 'owner') { return new DataResponse(['message' => 'Forbidden'], 403); }
        try {
            $this->db->beginTransaction();
            $qb = $this->db->getQueryBuilder();
            $qb->delete('fc_expenses')->where($qb->expr()->eq('book_id', $qb->createNamedParameter($id)))->executeStatement();
            $qb2 = $this->db->getQueryBuilder();
            $qb2->delete('fc_book_members')->where($qb2->expr()->eq('book_id', $qb2->createNamedParameter($id)))->executeStatement();
            $qb3 = $this->db->getQueryBuilder();
            $qb3->delete('fc_books')->where($qb3->expr()->andX(
                $qb3->expr()->eq('id', $qb3->createNamedParameter($id)),
                $qb3->expr()->eq('owner_uid', $qb3->createNamedParameter($uid))
            ))->executeStatement();
            $this->db->commit();
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new DataResponse(['message' => 'Delete failed'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function expensesIndex(int $id): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new DataResponse(['message' => 'Forbidden'], 403);
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
            return new DataResponse(['expenses' => $rows]);
        } catch (\Throwable $e) {
            $logger = \OC::$server->get(\OCP\ILogger::class);
            $logger->error('FamilyBudget OCS expenses list failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            return new DataResponse(['message' => 'Internal error'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function expensesCreate(int $id): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new DataResponse(['message' => 'Forbidden'], 403);
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
            return new DataResponse(['message' => 'amount>0 and date required'], 400);
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
            return new DataResponse(['id' => $newId], 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new DataResponse(['message' => 'Create failed'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function expensesUpdate(int $id, int $eid): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new DataResponse(['message' => 'Forbidden'], 403);
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
            if (!$has) { $this->db->rollBack(); return new DataResponse(['message' => 'Nothing to update'], 400); }
            $qb->where($qb->expr()->andX(
                $qb->expr()->eq('id', $qb->createNamedParameter($eid)),
                $qb->expr()->eq('book_id', $qb->createNamedParameter($id))
            ));
            $qb->executeStatement();
            $this->db->commit();
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new DataResponse(['message' => 'Update failed'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function expensesDelete(int $id, int $eid): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) {
            return new DataResponse(['message' => 'Forbidden'], 403);
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
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new DataResponse(['message' => 'Delete failed'], 500);
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
}
