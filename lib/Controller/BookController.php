<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;

class BookController extends Controller
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
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
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
                // Fallback: return books owned by user (in case membership insert failed earlier)
                $qb2 = $this->db->getQueryBuilder();
                $qb2->select('id', 'name', 'owner_uid')
                    ->from('fc_books')
                    ->where($qb2->expr()->eq('owner_uid', $qb2->createNamedParameter($uid)));
                $owned = $qb2->execute()->fetchAll();
                $owned = array_map(static function(array $r) { $r['role'] = 'owner'; return $r; }, $owned);
                return new JSONResponse(['books' => $owned]);
            }
            return new JSONResponse(['books' => $rows]);
        } catch (\Throwable $e) {
            // On error, log and fallback to owned books
            $logger = \OC::$server->get(\OCP\ILogger::class);
            $logger->error('FamilyBudget books query failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('id', 'name', 'owner_uid')
                ->from('fc_books')
                ->where($qb2->expr()->eq('owner_uid', $qb2->createNamedParameter($uid)));
            $owned = $qb2->execute()->fetchAll();
            $owned = array_map(static function(array $r) { $r['role'] = 'owner'; return $r; }, $owned);
            return new JSONResponse(['books' => $owned]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        $name = isset($input['name']) ? trim((string)$input['name']) : '';
        if ($name === '') {
            return new JSONResponse(['message' => 'Name required'], 400);
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $this->db->beginTransaction();

            // create book
            $qb = $this->db->getQueryBuilder();
            $qb->insert('fc_books')
                ->values([
                    'owner_uid' => $qb->createNamedParameter($uid),
                    'name' => $qb->createNamedParameter($name),
                    'created_at' => $qb->createNamedParameter($now),
                ])->executeStatement();

            // fetch created id (avoid driver-specific lastInsertId)
            $qbId = $this->db->getQueryBuilder();
            $qbId->select('id')
                ->from('fc_books')
                ->where($qbId->expr()->andX(
                    $qbId->expr()->eq('owner_uid', $qbId->createNamedParameter($uid)),
                    $qbId->expr()->eq('name', $qbId->createNamedParameter($name)),
                    $qbId->expr()->eq('created_at', $qbId->createNamedParameter($now))
                ))
                ->orderBy('id', 'DESC')
                ->setMaxResults(1);
            $bookId = (int)($qbId->execute()->fetchColumn() ?: 0);

            // add owner as member (role owner)
            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('fc_book_members')
                ->values([
                    'book_id' => $qb2->createNamedParameter($bookId),
                    'user_uid' => $qb2->createNamedParameter($uid),
                    'role' => $qb2->createNamedParameter('owner'),
                    'created_at' => $qb2->createNamedParameter($now),
                ])->executeStatement();

            $this->db->commit();
            return new JSONResponse(['id' => $bookId, 'name' => $name, 'owner_uid' => $uid, 'role' => 'owner'], 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new JSONResponse(['message' => 'Create failed'], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @param int $id
     */
    public function rename(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();

        // Check that caller is owner of the book
        $qbCheck = $this->db->getQueryBuilder();
        $qbCheck->select('role')->from('fc_book_members')
            ->where($qbCheck->expr()->andX(
                $qbCheck->expr()->eq('book_id', $qbCheck->createNamedParameter($id)),
                $qbCheck->expr()->eq('user_uid', $qbCheck->createNamedParameter($uid))
            ))->setMaxResults(1);
        $role = $qbCheck->executeQuery()->fetchOne();
        if ($role !== 'owner') {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }

        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        $name = isset($input['name']) ? trim((string)$input['name']) : '';
        if ($name === '') {
            return new JSONResponse(['message' => 'Name required'], 400);
        }

        // Update name; also ensure the owner matches for extra safety
        $qb = $this->db->getQueryBuilder();
        $qb->update('fc_books')
            ->set('name', $qb->createNamedParameter($name))
            ->where($qb->expr()->andX(
                $qb->expr()->eq('id', $qb->createNamedParameter($id)),
                $qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid))
            ));
        $affected = $qb->executeStatement();
        if ($affected < 1) {
            return new JSONResponse(['message' => 'Not found'], 404);
        }
        return new JSONResponse(['ok' => true, 'id' => $id, 'name' => $name]);
    }

    /**
     * @NoAdminRequired
     * @param int $id
     */
    public function invite(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        $input = $this->request->getParams();
        if (empty($input)) {
            $raw = @file_get_contents('php://input') ?: '';
            $json = json_decode($raw, true);
            if (is_array($json)) { $input = $json; }
        }
        $inviteUid = isset($input['user']) ? trim((string)$input['user']) : '';
        if ($inviteUid === '') {
            return new JSONResponse(['message' => 'user required'], 400);
        }
        // Check that caller is member (owner or member). Optionally restrict to owner.
        $qb = $this->db->getQueryBuilder();
        $qb->select('role')->from('fc_book_members')
            ->where($qb->expr()->andX(
                $qb->expr()->eq('book_id', $qb->createNamedParameter($id)),
                $qb->expr()->eq('user_uid', $qb->createNamedParameter($uid))
            ));
        $role = $qb->executeQuery()->fetchOne();
        if ($role === false) {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        // Insert ignore duplicate
        try {
            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('fc_book_members')
                ->values([
                    'book_id' => $qb2->createNamedParameter($id),
                    'user_uid' => $qb2->createNamedParameter($inviteUid),
                    'role' => $qb2->createNamedParameter('member'),
                    'created_at' => $qb2->createNamedParameter($now),
                ])->executeStatement();
        } catch (\Throwable $e) {
            // likely duplicate
        }
        return new JSONResponse(['ok' => true]);
    }

    /**
     * @NoAdminRequired
     * @param int $id
     */
    public function members(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        // Must be member to view
        $qbCheck = $this->db->getQueryBuilder();
        $qbCheck->select('id')->from('fc_book_members')
            ->where($qbCheck->expr()->andX(
                $qbCheck->expr()->eq('book_id', $qbCheck->createNamedParameter($id)),
                $qbCheck->expr()->eq('user_uid', $qbCheck->createNamedParameter($uid))
            ))->setMaxResults(1);
        $isMember = $qbCheck->executeQuery()->fetchOne();
        if ($isMember === false) {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid', 'role', 'created_at')
            ->from('fc_book_members')
            ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($id)))
            ->orderBy('created_at', 'ASC');
        $rows = $qb->execute()->fetchAll();
        if (!is_array($rows) || count($rows) === 0) {
            // Fallback for legacy books without membership rows: include owner as member
            $qbOwner = $this->db->getQueryBuilder();
            $qbOwner->select('owner_uid')->from('fc_books')->where($qbOwner->expr()->eq('id', $qbOwner->createNamedParameter($id)))->setMaxResults(1);
            $owner = $qbOwner->executeQuery()->fetchOne();
            if (is_string($owner) && $owner !== '') {
                $rows = [[ 'user_uid' => $owner, 'role' => 'owner', 'created_at' => null ]];
            }
        }

        // Enrich with display names when possible
        try {
            $um = \OC::$server->get(\OCP\IUserManager::class);
            foreach ($rows as &$r) {
                $uid2 = $r['user_uid'] ?? '';
                $user2 = $um->get($uid2);
                $r['display_name'] = $user2 ? $user2->getDisplayName() : $uid2;
            }
        } catch (\Throwable $e) {
            // ignore, fall back to uid only
        }

        return new JSONResponse(['members' => $rows]);
    }

    /**
     * @NoAdminRequired
     */
    public function delete(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $uid = $user->getUID();
        // must be owner
        $qbC = $this->db->getQueryBuilder();
        $qbC->select('role')->from('fc_book_members')
            ->where($qbC->expr()->andX(
                $qbC->expr()->eq('book_id', $qbC->createNamedParameter($id)),
                $qbC->expr()->eq('user_uid', $qbC->createNamedParameter($uid))
            ))->setMaxResults(1);
        $role = $qbC->executeQuery()->fetchOne();
        if ($role !== 'owner') {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }
        try {
            $this->db->beginTransaction();
            // delete expenses, members, book
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
            return new JSONResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new JSONResponse(['message' => 'Delete failed'], 500);
        }
    }

    /**
     * Remove a member from a book (owner only). Owner cannot be removed.
     * @NoAdminRequired
     */
    public function removeMember(int $id, string $uid): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], 401);
        }
        $caller = $user->getUID();
        // caller must be owner
        $qbC = $this->db->getQueryBuilder();
        $qbC->select('role')->from('fc_book_members')
            ->where($qbC->expr()->andX(
                $qbC->expr()->eq('book_id', $qbC->createNamedParameter($id)),
                $qbC->expr()->eq('user_uid', $qbC->createNamedParameter($caller))
            ))->setMaxResults(1);
        $role = $qbC->executeQuery()->fetchOne();
        if ($role !== 'owner') {
            return new JSONResponse(['message' => 'Forbidden'], 403);
        }
        // do not remove owner
        $qbOwner = $this->db->getQueryBuilder();
        $qbOwner->select('owner_uid')->from('fc_books')
            ->where($qbOwner->expr()->eq('id', $qbOwner->createNamedParameter($id)))
            ->setMaxResults(1);
        $owner = $qbOwner->executeQuery()->fetchOne();
        if ($owner === $uid) {
            return new JSONResponse(['message' => 'Cannot remove owner'], 400);
        }
        // delete membership row (if exists)
        $qb = $this->db->getQueryBuilder();
        $qb->delete('fc_book_members')
            ->where($qb->expr()->andX(
                $qb->expr()->eq('book_id', $qb->createNamedParameter($id)),
                $qb->expr()->eq('user_uid', $qb->createNamedParameter($uid))
            ))->executeStatement();
        return new JSONResponse(['ok' => true]);
    }
}
