<?php
declare(strict_types=1);

// Bootstrap Nextcloud
require_once '/var/www/html/lib/base.php';

/** @var \OCP\IUserManager $userManager */
$userManager = \OC::$server->get(\OCP\IUserManager::class);
/** @var \OCP\IDBConnection $db */
$db = \OC::$server->get(\OCP\IDBConnection::class);
/** @var \OCP\ILogger $logger */
$logger = \OC::$server->get(\OCP\ILogger::class);

function out(string $m): void { echo $m, "\n"; }

// Ensure base user exists
$admin = $userManager->get('admin');
if (!$admin) {
    out('[ERR] Admin user not found. Make sure Nextcloud is installed (admin/admin).');
    exit(1);
}

// Create demo users if missing
$users = [
    // Use strong, policy-compliant demo passwords (>=10 chars, mixed)
    ['uid' => 'alice', 'pass' => 'Demo!User1-2025', 'display' => 'Alice Example'],
    ['uid' => 'bob',   'pass' => 'Demo!User2-2025', 'display' => 'Bob Example'],
];
foreach ($users as $u) {
    if (!$userManager->userExists($u['uid'])) {
        out("[+] Creating user {$u['uid']}");
        $nu = $userManager->createUser($u['uid'], $u['pass']);
        if ($nu) { $nu->setDisplayName($u['display']); }
    } else {
        out("[=] User {$u['uid']} exists");
    }
}

$now = (new DateTimeImmutable());
// Two months: current and previous
$month1 = $now->modify('first day of this month');
$month0 = $now->modify('first day of last month');

// Create or get demo book owned by admin
$bookName = 'Familienbudget Demo';
$qb = $db->getQueryBuilder();
$qb->select('id')->from('fc_books')
   ->where($qb->expr()->andX(
       $qb->expr()->eq('owner_uid', $qb->createNamedParameter('admin')),
       $qb->expr()->eq('name', $qb->createNamedParameter($bookName))
   ))->setMaxResults(1);
$bookId = $qb->executeQuery()->fetchOne();
if ($bookId === false) {
    out('[+] Creating demo book');
    $qb = $db->getQueryBuilder();
    $qb->insert('fc_books')->values([
        'owner_uid' => $qb->createNamedParameter('admin'),
        'name' => $qb->createNamedParameter($bookName),
        'created_at' => $qb->createNamedParameter($now->format('Y-m-d H:i:s')),
    ])->executeStatement();
    // Resolve ID
    $qb = $db->getQueryBuilder();
    $qb->select('id')->from('fc_books')
       ->where($qb->expr()->andX(
           $qb->expr()->eq('owner_uid', $qb->createNamedParameter('admin')),
           $qb->expr()->eq('name', $qb->createNamedParameter($bookName))
       ))->orderBy('id', 'DESC')->setMaxResults(1);
    $bookId = (int)$qb->executeQuery()->fetchOne();
} else {
    $bookId = (int)$bookId;
    out('[=] Demo book exists (ID ' . $bookId . ')');
}

// Ensure memberships: admin(owner), alice(member), bob(member)
$members = [
    ['uid' => 'admin', 'role' => 'owner'],
    ['uid' => 'alice', 'role' => 'member'],
    ['uid' => 'bob',   'role' => 'member'],
];
foreach ($members as $m) {
    $qb = $db->getQueryBuilder();
    $qb->select('id')->from('fc_book_members')
        ->where($qb->expr()->andX(
            $qb->expr()->eq('book_id', $qb->createNamedParameter($bookId)),
            $qb->expr()->eq('user_uid', $qb->createNamedParameter($m['uid']))
        ))->setMaxResults(1);
    $exists = $qb->executeQuery()->fetchOne();
    if ($exists === false) {
        out("[+] Adding member {$m['uid']} ({$m['role']})");
        $qb = $db->getQueryBuilder();
        $qb->insert('fc_book_members')->values([
            'book_id' => $qb->createNamedParameter($bookId),
            'user_uid' => $qb->createNamedParameter($m['uid']),
            'role' => $qb->createNamedParameter($m['role']),
            'created_at' => $qb->createNamedParameter($now->format('Y-m-d H:i:s')),
        ])->executeStatement();
    }
}

// If there are already expenses, avoid duplicating
$qb = $db->getQueryBuilder();
$qb->select($qb->func()->count('*', 'cnt'))->from('fc_expenses')
   ->where($qb->expr()->eq('book_id', $qb->createNamedParameter($bookId)));
$cnt = (int)$qb->executeQuery()->fetchOne();
if ($cnt > 0) {
    out('[=] Expenses already present, skipping seeding.');
    exit(0);
}

// Helper to format a date within a month
function d(DateTimeImmutable $monthStart, int $day): string {
    $day = max(1, min(28, $day));
    return $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), $day)->format('Y-m-d');
}

$eur = 'EUR';
$data = [
    // Previous month
    ['uid' => 'admin', 'amount_cents' => 7500,  'currency' => $eur, 'desc' => 'Wocheneinkauf Supermarkt', 'date' => d($month0, 3)],
    ['uid' => 'alice', 'amount_cents' => 1299,  'currency' => $eur, 'desc' => 'Café & Kuchen',            'date' => d($month0, 5)],
    ['uid' => 'bob',   'amount_cents' => 5400,  'currency' => $eur, 'desc' => 'Tanken',                   'date' => d($month0, 8)],
    ['uid' => 'admin', 'amount_cents' => 8999,  'currency' => $eur, 'desc' => 'Drogerie & Haushalt',      'date' => d($month0, 12)],
    ['uid' => 'alice', 'amount_cents' => 2400,  'currency' => $eur, 'desc' => 'Mittagessen Arbeit',       'date' => d($month0, 15)],
    ['uid' => 'bob',   'amount_cents' => 1999,  'currency' => $eur, 'desc' => 'Baumarkt Kleinkram',       'date' => d($month0, 22)],
    // Current month
    ['uid' => 'admin', 'amount_cents' => 8200,  'currency' => $eur, 'desc' => 'Wocheneinkauf Supermarkt', 'date' => d($month1, 2)],
    ['uid' => 'alice', 'amount_cents' => 1550,  'currency' => $eur, 'desc' => 'Eis & Kaffee',             'date' => d($month1, 4)],
    ['uid' => 'bob',   'amount_cents' => 5600,  'currency' => $eur, 'desc' => 'Tanken',                   'date' => d($month1, 9)],
    ['uid' => 'admin', 'amount_cents' => 12000, 'currency' => $eur, 'desc' => 'Monatliche Vorräte',       'date' => d($month1, 10)],
    ['uid' => 'alice', 'amount_cents' => 3000,  'currency' => $eur, 'desc' => 'Geschenk',                 'date' => d($month1, 18)],
];

out('[+] Inserting demo expenses ...');
foreach ($data as $e) {
    $qb = $db->getQueryBuilder();
    $qb->insert('fc_expenses')->values([
        'book_id' => $qb->createNamedParameter($bookId),
        'user_uid' => $qb->createNamedParameter($e['uid']),
        'amount_cents' => $qb->createNamedParameter($e['amount_cents']),
        'currency' => $qb->createNamedParameter($e['currency']),
        'description' => $qb->createNamedParameter($e['desc']),
        'occurred_at' => $qb->createNamedParameter($e['date'] . ' 00:00:00'),
        'created_at' => $qb->createNamedParameter($now->format('Y-m-d H:i:s')),
    ])->executeStatement();
}

out('[OK] Demo data created. Book: "' . $bookName . '" (ID ' . $bookId . ') with ' . count($data) . ' expenses.');
