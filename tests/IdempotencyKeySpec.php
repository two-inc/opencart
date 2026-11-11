<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;

/**
 * The Idempotency-Key shapes, one per write operation, and the guard on
 * what a key may hold (lowercase ASCII, at most 100 characters).
 */
final class IdempotencyKeySpec
{
    private const ID = '5F1C2A7E-0000-4000-8000-000000000001';

    public static function runAll(): void
    {
        $hash = hash('sha256', 'token');
        $shapes = [
            [IdempotencyKey::orderCreate(42, $hash), 'create_42_' . substr($hash, 0, 16), 'order create: shop order id and the attempt\'s token hash'],
            [IdempotencyKey::forTwoOrder(IdempotencyKey::CONFIRM, self::ID), 'confirm_5f1c2a7e-0000-4000-8000-000000000001', 'confirm: the Two order id, lowercased'],
            [IdempotencyKey::forTwoOrder(IdempotencyKey::FULFIL, self::ID), 'fulfil_5f1c2a7e-0000-4000-8000-000000000001', 'whole-order fulfil'],
            [IdempotencyKey::forTwoOrder(IdempotencyKey::CANCEL, self::ID), 'cancel_5f1c2a7e-0000-4000-8000-000000000001', 'cancel'],
            [IdempotencyKey::refund(self::ID, 12), 'refund_5f1c2a7e-0000-4000-8000-000000000001_12', 'partial refund: the plugin refund row id'],
            [preg_match('~^refund_remainder_5f1c2a7e-0000-4000-8000-000000000001_[0-9a-f]{16}\z~', IdempotencyKey::refundRemainder(self::ID, ['r2', 'r1'], '50.00')), 1, 'rest-of-order refund: Two order id plus a short hash'],
            [IdempotencyKey::refundRemainder(self::ID, ['r2', 'r1'], '50.00'), IdempotencyKey::refundRemainder(self::ID, ['r1', 'r2'], '50.00'), 'rest-of-order refund: the same refunds in any order give the same key'],
            [IdempotencyKey::refundRemainder(self::ID, ['r1'], '50.00') === IdempotencyKey::refundRemainder(self::ID, ['r1', 'r2'], '50.00'), false, 'rest-of-order refund: another refund made since gives a new key'],
            [IdempotencyKey::refundRemainder(self::ID, [], '50.00') === IdempotencyKey::refundRemainder(self::ID, [], '49.99'), false, 'rest-of-order refund: another amount gives a new key'],
            [preg_match('~^edit_5f1c2a7e-0000-4000-8000-000000000001_[0-9a-f]{16}\z~', IdempotencyKey::edit(self::ID, 'create_42_ab', 'h1')), 1, 'edit: Two order id plus a short hash'],
            [IdempotencyKey::edit(self::ID, 'create_42_ab', 'h1'), IdempotencyKey::edit(self::ID, 'create_42_ab', 'h1'), 'edit: a retry of the same edit repeats its key'],
            [IdempotencyKey::edit(self::ID, IdempotencyKey::edit(self::ID, 'create_42_ab', 'hA'), 'hB') === IdempotencyKey::edit(self::ID, 'create_42_ab', 'hB'), false, 'edit: chained on the previous key, so A to B differs from a first edit to B'],
            [IdempotencyKey::header('fulfil_x'), ['Idempotency-Key' => 'fulfil_x'], 'the header name, never X-Idempotency-Key'],
            [IdempotencyKey::in(['idempotency-key' => 'a']), 'a', 'found whatever the header name\'s case'],
            [IdempotencyKey::in(['Accept' => 'application/pdf']), null, 'none among other headers'],
        ];
        foreach ($shapes as [$actual, $expected, $description]) {
            TinyAssert::same($expected, $actual, 'idempotency key: ' . $description);
        }

        $refused = [
            [static fn () => IdempotencyKey::header(''), 'an empty key'],
            [static fn () => IdempotencyKey::header('Fulfil_x'), 'upper case'],
            [static fn () => IdempotencyKey::header('fulfil x'), 'a space'],
            [static fn () => IdempotencyKey::header(str_repeat('a', 101)), 'over 100 characters'],
            [static fn () => IdempotencyKey::forTwoOrder('refund', self::ID), 'an operation that is not whole-order'],
            [static fn () => IdempotencyKey::refund(self::ID, 0), 'a refund with no stored row'],
            [static fn () => IdempotencyKey::edit(self::ID, '', 'h1'), 'an edit with no previous key'],
            [static fn () => IdempotencyKey::forTwoOrder(IdempotencyKey::CANCEL, 'a/b'), 'a Two order id that is not an id'],
        ];
        foreach ($refused as [$call, $description]) {
            TinyAssert::throws($call, InvalidArgumentException::class, 'idempotency key refuses ' . $description);
        }
        TinyAssert::same(100, strlen(IdempotencyKey::header(str_repeat('a', 100))['Idempotency-Key']), 'idempotency key: exactly 100 characters is allowed');
    }
}
