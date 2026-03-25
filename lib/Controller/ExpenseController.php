<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Controller;

use OCA\FamilyBudget\Service\ExpenseMapper;
use OCA\FamilyBudget\Service\ExpensePayloadValidator;
use OCA\FamilyBudget\Service\ExpenseService;
use OCA\FamilyBudget\Service\ExpenseValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class ExpenseController extends Controller
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
     * @NoCSRFRequired
     * @param int $id Book id
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
            $rows = $this->expenseService->listExpenses($id, $this->request->getParams());
            return new JSONResponse(['expenses' => $rows]);
        } catch (\Throwable $e) {
            $logger = \OC::$server->get(LoggerInterface::class);
            $logger->error('FamilyBudget expenses query failed: ' . $e->getMessage(), ['app' => 'familybudget']);
            return new JSONResponse(['message' => 'Internal error'], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @param int $id Book id
     */
    #[NoAdminRequired]
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
        $input = $this->readInput();
        try {
            $newId = $this->expenseService->createExpense($id, $uid, $input);
            return new JSONResponse(['id' => $newId], 201);
        } catch (ExpenseValidationException $e) {
            return new JSONResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Create failed'], 500);
        }
    }

    private function isMember(int $bookId, string $uid): bool
    {
        return $this->expenseService->isMember($bookId, $uid);
    }

    /**
     * @NoAdminRequired
     */
    #[NoAdminRequired]
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
    #[NoAdminRequired]
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
        $input = $this->readInput();
        try {
            $this->expenseService->updateExpense($id, $eid, $input);
            return new JSONResponse(['ok' => true]);
        } catch (ExpenseValidationException $e) {
            return new JSONResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Update failed'], 500);
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
}
