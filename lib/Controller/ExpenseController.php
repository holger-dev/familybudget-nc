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
     * @NoCSRFRequired
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
        return new JSONResponse(['id' => $newId], 201);
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
     * @NoCSRFRequired
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
        $qb = $this->db->getQueryBuilder();
        $qb->delete('fc_expenses')->where($qb->expr()->andX(
            $qb->expr()->eq('id', $qb->createNamedParameter($eid)),
            $qb->expr()->eq('book_id', $qb->createNamedParameter($id))
        ));
        $qb->executeStatement();
        return new JSONResponse(['ok' => true]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
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
        $qb = $this->db->getQueryBuilder();
        $qb->update('fc_expenses');
        $has = false;
        if (isset($input['amount'])) { $qb->set('amount_cents', $qb->createNamedParameter((int)round(((float)$input['amount']) * 100))); $has = true; }
        if (array_key_exists('description', $input)) { $desc = $input['description'] !== null ? (string)$input['description'] : null; $qb->set('description', $qb->createNamedParameter($desc)); $has = true; }
        if (isset($input['date'])) { $occ = ((string)$input['date']) . ' 00:00:00'; $qb->set('occurred_at', $qb->createNamedParameter($occ)); $has = true; }
        if (isset($input['currency'])) { $qb->set('currency', $qb->createNamedParameter((string)$input['currency'])); $has = true; }
        if (!$has) { return new JSONResponse(['message' => 'Nothing to update'], 400); }
        $qb->where($qb->expr()->andX(
            $qb->expr()->eq('id', $qb->createNamedParameter($eid)),
            $qb->expr()->eq('book_id', $qb->createNamedParameter($id))
        ));
        $qb->executeStatement();
        return new JSONResponse(['ok' => true]);
    }
}
