<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date202409030001 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('fc_books')) {
            $table = $schema->createTable('fc_books');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('owner_uid', 'string', [
                'length' => 64,
                'notnull' => true,
            ]);
            $table->addColumn('name', 'string', [
                'length' => 190,
                'notnull' => true,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['owner_uid'], 'fc_books_owner');
        }

        if (!$schema->hasTable('fc_book_members')) {
            $table = $schema->createTable('fc_book_members');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('book_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('user_uid', 'string', [
                'length' => 64,
                'notnull' => true,
            ]);
            $table->addColumn('role', 'string', [
                'length' => 32,
                'notnull' => true,
                'default' => 'member',
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['book_id', 'user_uid'], 'fc_book_members_uq');
            $table->addIndex(['user_uid'], 'fc_book_members_user');
        }

        if (!$schema->hasTable('fc_expenses')) {
            $table = $schema->createTable('fc_expenses');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('book_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('user_uid', 'string', [
                'length' => 64,
                'notnull' => true,
            ]);
            $table->addColumn('amount_cents', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('currency', 'string', [
                'length' => 8,
                'notnull' => true,
                'default' => 'EUR',
            ]);
            $table->addColumn('description', 'string', [
                'length' => 500,
                'notnull' => false,
            ]);
            $table->addColumn('occurred_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['book_id'], 'fc_expenses_book');
            $table->addIndex(['user_uid'], 'fc_expenses_user');
            $table->addIndex(['occurred_at'], 'fc_expenses_date');
        }

        return $schema;
    }
}
