<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Service;

final class DbCompat
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchAllAssociative(object $result): array
    {
        if (method_exists($result, 'fetchAllAssociative')) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $result->fetchAllAssociative();
            return $rows;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->fetchAll();
        return $rows;
    }
}
