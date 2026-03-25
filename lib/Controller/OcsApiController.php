<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Controller;

use OCA\FamilyBudget\Service\DbCompat;
use OCA\FamilyBudget\Service\ExpenseMapper;
use OCA\FamilyBudget\Service\ExpensePayloadValidator;
use OCA\FamilyBudget\Service\ExpenseService;
use OCA\FamilyBudget\Service\ExpenseValidationException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\AppFramework\Http\TextPlainResponse;
use Psr\Log\LoggerInterface;

class OcsApiController extends OCSController
{
    private IUserSession $userSession;
    private IDBConnection $db;
    private ExpenseService $expenseService;

    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
        $this->userSession = \OC::$server->get(IUserSession::class);
        $this->db = \OC::$server->get(IDBConnection::class);
        $this->expenseService = new ExpenseService(
            $this->db,
            new ExpensePayloadValidator(),
            new ExpenseMapper()
        );
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
            $rows = DbCompat::fetchAllAssociative($qb->executeQuery());
            if (!is_array($rows) || count($rows) === 0) {
                $qb2 = $this->db->getQueryBuilder();
                $qb2->select('id', 'name', 'owner_uid')
                    ->from('fc_books')
                    ->where($qb2->expr()->eq('owner_uid', $qb2->createNamedParameter($uid)));
                $owned = DbCompat::fetchAllAssociative($qb2->executeQuery());
                $owned = array_map(static function(array $r) { $r['role'] = 'owner'; return $r; }, $owned);
                return new DataResponse(['books' => $owned]);
            }
            return new DataResponse(['books' => $rows]);
        } catch (\Throwable $e) {
            $logger = \OC::$server->get(LoggerInterface::class);
            $logger->error('FamilyBudget OCS books failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            return new DataResponse(['message' => 'Internal error'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
            $affected = $qb->insert('fc_books')->values([
                'owner_uid' => $qb->createNamedParameter($uid),
                'name' => $qb->createNamedParameter($name),
                'created_at' => $qb->createNamedParameter($now),
            ])->executeStatement();
            if ($affected !== 1) {
                throw new \RuntimeException('insert_book_failed');
            }
            $bookId = (int)$qb->getLastInsertId();
            if ($bookId <= 0) {
                throw new \RuntimeException('insert_book_id_failed');
            }
            $qb2 = $this->db->getQueryBuilder();
            $affected2 = $qb2->insert('fc_book_members')->values([
                'book_id' => $qb2->createNamedParameter($bookId),
                'user_uid' => $qb2->createNamedParameter($uid),
                'role' => $qb2->createNamedParameter('owner'),
                'created_at' => $qb2->createNamedParameter($now),
            ])->executeStatement();
            if ($affected2 !== 1) {
                throw new \RuntimeException('insert_member_failed');
            }
            $this->db->commit();
            return new DataResponse(['id' => $bookId, 'name' => $name, 'owner_uid' => $uid, 'role' => 'owner'], 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $detail = $e instanceof \RuntimeException ? $e->getMessage() : 'unexpected_error';
            $logger = \OC::$server->get(LoggerInterface::class);
            $logger->error('FamilyBudget OCS book create failed: ' . $e->getMessage(), [
                'app' => 'familybudget',
                'user' => $uid,
                'detail' => $detail,
            ]);
            return new DataResponse([
                'message' => 'Create failed',
                'error' => 'book_create_failed',
                'detail' => $detail,
            ], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        $rows = DbCompat::fetchAllAssociative($qb->executeQuery());
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
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
            $rows = $this->expenseService->listExpenses($id, $this->request->getParams());
            return new DataResponse(['expenses' => $rows]);
        } catch (\Throwable $e) {
            $logger = \OC::$server->get(LoggerInterface::class);
            $logger->error('FamilyBudget OCS expenses list failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            return new DataResponse(['message' => 'Internal error'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        $input = $this->readInput();
        try {
            $newId = $this->expenseService->createExpense($id, $uid, $input);
            return new DataResponse(['id' => $newId], 201);
        } catch (ExpenseValidationException $e) {
            return new DataResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new DataResponse(['message' => 'Create failed'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        $input = $this->readInput();
        try {
            $this->expenseService->updateExpense($id, $eid, $input);
            return new DataResponse(['ok' => true]);
        } catch (ExpenseValidationException $e) {
            return new DataResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new DataResponse(['message' => 'Update failed'], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readInput(): array
    {
        $input = $this->request->getParams();
        if (!empty($input)) {
            return $input;
        }

        $raw = @file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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

    /**
     * Export all expenses of a book as CSV.
     * Columns: date,amount,currency,description,user_uid
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function booksExportCsv(int $id)
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $uid = $user->getUID();
        if (!$this->isMember($id, $uid)) { return new DataResponse(['message' => 'Forbidden'], 403); }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('occurred_at', 'amount_cents', 'currency', 'description', 'user_uid')
                ->from('fc_expenses')
                ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($id)))
                ->orderBy('occurred_at', 'ASC')
                ->addOrderBy('id', 'ASC');
            $rows = DbCompat::fetchAllAssociative($qb->executeQuery());
            $fh = fopen('php://temp', 'r+');
            // Header
            fputcsv($fh, ['date','amount','currency','description','user_uid']);
            foreach ($rows as $r) {
                $date = substr((string)($r['occurred_at'] ?? ''), 0, 10);
                $amount = number_format(((int)$r['amount_cents']) / 100, 2, '.', '');
                $currency = (string)($r['currency'] ?? 'EUR');
                $desc = $r['description'] !== null ? (string)$r['description'] : '';
                $userUid = (string)($r['user_uid'] ?? '');
                fputcsv($fh, [$date, $amount, $currency, $desc, $userUid]);
            }
            rewind($fh);
            $csv = stream_get_contents($fh) ?: '';
            fclose($fh);
            $resp = new TextPlainResponse($csv);
            $resp->addHeader('Content-Type', 'text/csv; charset=UTF-8');
            $resp->addHeader('Content-Disposition', 'attachment; filename="familybudget-book-' . $id . '.csv"');
            return $resp;
        } catch (\Throwable $e) {
            $logger = \OC::$server->get(LoggerInterface::class);
            $logger->error('FamilyBudget OCS export failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            return new DataResponse(['message' => 'Internal error'], 500);
        }
    }

    /**
     * Import CSV for a book (owner only). Replaces all expenses in the book.
     * Requires valid CSV parse first, then deletes and inserts in a transaction.
     * Accepted input: raw text/csv in request body or multipart field "file".
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function booksImportCsv(int $id): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) { return new DataResponse(['message' => 'Unauthorized'], 401); }
        $caller = $user->getUID();
        // owner check
        $qbC = $this->db->getQueryBuilder();
        $qbC->select('role')->from('fc_book_members')
            ->where($qbC->expr()->andX(
                $qbC->expr()->eq('book_id', $qbC->createNamedParameter($id)),
                $qbC->expr()->eq('user_uid', $qbC->createNamedParameter($caller))
            ))->setMaxResults(1);
        $role = $qbC->executeQuery()->fetchOne();
        if ($role !== 'owner') { return new DataResponse(['message' => 'Forbidden'], 403); }

        // Read input
        $content = '';
        try {
            // Try uploaded file first (multipart)
            $uploaded = $this->request->getUploadedFile('file');
            if (is_object($uploaded)) {
                // Nextcloud IUploadedFile
                if (method_exists($uploaded, 'getTempFile')) {
                    $tmp = $uploaded->getTempFile();
                    if ($tmp && is_readable($tmp)) { $content = (string)file_get_contents($tmp); }
                } elseif (method_exists($uploaded, 'getTemporaryFile')) {
                    $tmp = $uploaded->getTemporaryFile();
                    if ($tmp && is_readable($tmp)) { $content = (string)file_get_contents($tmp); }
                }
            } elseif (is_array($uploaded) && isset($uploaded['tmp_name']) && is_readable($uploaded['tmp_name'])) {
                $content = (string)file_get_contents($uploaded['tmp_name']);
            }
        } catch (\Throwable $e) { /* ignore, fall back to raw */ }
        if ($content === '') {
            $content = (string)(@file_get_contents('php://input') ?: '');
        }
        if (trim($content) === '') { return new DataResponse(['message' => 'Empty CSV'], 400); }

        // Parse CSV to array (validate fully before modifying DB)
        $rows = [];
        $errors = [];
        $members = $this->fetchMembersSet($id); // set of user_uids
        try {
            $delimiter = $this->detectDelimiter($content);
            $fh = fopen('php://temp', 'r+');
            fwrite($fh, $content);
            rewind($fh);
            $header = fgetcsv($fh, 0, $delimiter);
            if (!is_array($header)) { throw new \RuntimeException('Missing header'); }
            $map = $this->mapHeader($header);
            if (!isset($map['date']) || !isset($map['amount'])) {
                throw new \RuntimeException('Required columns: date, amount');
            }
            $line = 1;
            while (($cols = fgetcsv($fh, 0, $delimiter)) !== false) {
                $line++;
                if (count($cols) === 1 && trim((string)$cols[0]) === '') { continue; }
                $date = $this->col($cols, $map, 'date');
                $amountStr = $this->col($cols, $map, 'amount');
                $currency = $this->col($cols, $map, 'currency', 'EUR');
                $desc = $this->col($cols, $map, 'description', '');
                $userUid = $this->col($cols, $map, 'user_uid', $caller);

                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $date)) {
                    $errors[] = "Line $line: invalid date '$date'";
                    continue;
                }
                // Allow comma or dot decimals
                $amountNorm = str_replace(',', '.', (string)$amountStr);
                if (!is_numeric($amountNorm)) {
                    $errors[] = "Line $line: invalid amount '$amountStr'";
                    continue;
                }
                $amount = (float)$amountNorm;
                if ($amount <= 0) {
                    $errors[] = "Line $line: amount must be > 0";
                    continue;
                }
                // If user_uid missing or not a member, fallback to caller (owner)
                if ($userUid === '' || !isset($members[$userUid])) {
                    $userUid = $caller;
                }
                $rows[] = [
                    'date' => $date,
                    'amount_cents' => (int)round($amount * 100),
                    'currency' => $currency !== '' ? $currency : 'EUR',
                    'description' => $desc !== '' ? $desc : null,
                    'user_uid' => $userUid,
                ];
            }
            fclose($fh);
        } catch (\Throwable $e) {
            return new DataResponse(['message' => 'CSV parse failed', 'error' => $e->getMessage()], 400);
        }

        if (count($errors) > 0) {
            return new DataResponse(['message' => 'CSV validation failed', 'errors' => $errors], 400);
        }

        // Replace data in a single transaction
        try {
            $this->db->beginTransaction();
            $qbDel = $this->db->getQueryBuilder();
            $qbDel->delete('fc_expenses')->where($qbDel->expr()->eq('book_id', $qbDel->createNamedParameter($id)))->executeStatement();
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($rows as $r) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('fc_expenses')->values([
                    'book_id' => $qb->createNamedParameter($id),
                    'user_uid' => $qb->createNamedParameter($r['user_uid']),
                    'amount_cents' => $qb->createNamedParameter($r['amount_cents']),
                    'currency' => $qb->createNamedParameter($r['currency']),
                    'description' => $qb->createNamedParameter($r['description']),
                    'occurred_at' => $qb->createNamedParameter($r['date'] . ' 00:00:00'),
                    'created_at' => $qb->createNamedParameter($now),
                ])->executeStatement();
            }
            $this->db->commit();
            return new DataResponse(['ok' => true, 'imported' => count($rows)]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return new DataResponse(['message' => 'Import failed'], 500);
        }
    }

    private function fetchMembersSet(int $bookId): array
    {
      $set = [];
      try {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid')->from('fc_book_members')
           ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($bookId)));
        $rows = DbCompat::fetchAllAssociative($qb->executeQuery());
        foreach ($rows as $r) {
            $uid = (string)($r['user_uid'] ?? '');
            if ($uid !== '') { $set[$uid] = true; }
        }
      } catch (\Throwable $e) {}
      return $set;
    }

    private function detectDelimiter(string $csv): string
    {
        // Try to decide based on header structure
        $firstLine = strtok($csv, "\r\n");
        if ($firstLine === false) { return ','; }
        $c1 = str_getcsv($firstLine, ',');
        $c2 = str_getcsv($firstLine, ';');
        $score = function(array $cols): int {
            $cols = array_map(function($v){ return strtolower(trim((string)$v)); }, $cols);
            $ok = 0;
            foreach (['date','amount'] as $req) { if (in_array($req, $cols, true)) $ok++; }
            return $ok;
        };
        $s1 = $score($c1);
        $s2 = $score($c2);
        if ($s1 > $s2) return ',';
        if ($s2 > $s1) return ';';
        // fallback: count occurrences in whole text
        $commas = substr_count($csv, ',');
        $semis = substr_count($csv, ';');
        return ($semis > $commas) ? ';' : ',';
    }

    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $n = strtolower(trim((string)$name));
            if ($n !== '') { $map[$n] = $i; }
        }
        return $map;
    }

    private function col(array $cols, array $map, string $key, $default = ''): string
    {
        if (!isset($map[$key])) { return (string)$default; }
        $idx = (int)$map[$key];
        return isset($cols[$idx]) ? (string)$cols[$idx] : (string)$default;
    }
}
