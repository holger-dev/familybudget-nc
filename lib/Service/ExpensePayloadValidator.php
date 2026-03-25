<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Service;

class ExpensePayloadValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{amount_cents:int, description:?string, occurred_at:string, currency:string, user_uid:string}
     */
    public function validateCreatePayload(array $input, string $fallbackUid): array
    {
        $amount = isset($input['amount']) ? (float)$input['amount'] : 0.0;
        $date = isset($input['date']) ? (string)$input['date'] : '';
        if ($amount <= 0 || !$this->isValidDate($date)) {
            throw new ExpenseValidationException('amount>0 and date required');
        }

        $currency = isset($input['currency']) ? trim((string)$input['currency']) : 'EUR';
        if ($currency === '') {
            $currency = 'EUR';
        }

        return [
            'amount_cents' => (int)round($amount * 100),
            'description' => $this->normalizeDescription($input),
            'occurred_at' => $date . ' 00:00:00',
            'currency' => $currency,
            'user_uid' => $this->normalizeUserUid($input, $fallbackUid),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validateUpdatePayload(array $input): array
    {
        $payload = [];

        if (array_key_exists('amount', $input)) {
            $amount = (float)$input['amount'];
            if ($amount <= 0) {
                throw new ExpenseValidationException('amount must be > 0');
            }
            $payload['amount_cents'] = (int)round($amount * 100);
        }

        if (array_key_exists('description', $input)) {
            $payload['description'] = $this->normalizeDescription($input);
        }

        if (array_key_exists('date', $input)) {
            $date = (string)$input['date'];
            if (!$this->isValidDate($date)) {
                throw new ExpenseValidationException('date must be YYYY-MM-DD');
            }
            $payload['occurred_at'] = $date . ' 00:00:00';
        }

        if (array_key_exists('currency', $input)) {
            $currency = trim((string)$input['currency']);
            if ($currency === '') {
                throw new ExpenseValidationException('currency must not be empty');
            }
            $payload['currency'] = $currency;
        }

        if (array_key_exists('user_uid', $input)) {
            $userUid = trim((string)$input['user_uid']);
            if ($userUid === '') {
                throw new ExpenseValidationException('user_uid must be a member of the book');
            }
            $payload['user_uid'] = $userUid;
        }

        if ($payload === []) {
            throw new ExpenseValidationException('Nothing to update');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function normalizeDescription(array $input): ?string
    {
        if (!array_key_exists('description', $input) || $input['description'] === null) {
            return null;
        }

        return trim((string)$input['description']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function normalizeUserUid(array $input, string $fallbackUid): string
    {
        if (!array_key_exists('user_uid', $input)) {
            return $fallbackUid;
        }

        return trim((string)$input['user_uid']);
    }

    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }
}
