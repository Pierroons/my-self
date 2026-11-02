<?php

declare(strict_types=1);

namespace Pierroons\SelfDataGuard\Escrow;

use Pierroons\SelfDataGuard\Storage\StorageInterface;

/**
 * An escrow's envelope and its fields, read under one revision. A deposit
 * that lands between the two reads re-encrypts the fields under a new key,
 * which the envelope read before does not open: decrypting them would fail as
 * if they were corrupted. The envelope is read again after the fields, and the
 * pair again when it moved.
 */
final class EscrowSnapshot
{
    private const ATTEMPTS = 3;

    /**
     * @param list<string> $fieldNames empty = all escrow fields
     * @return array{0: EscrowRecord, 1: array<string, string>}|null the envelope and the
     *         ciphertexts it opens; null when the account holds no escrow
     * @throws StaleEscrowException when deposits kept landing between the reads
     */
    public static function read(StorageInterface $storage, string $userId, array $fieldNames = []): ?array
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $record = $storage->loadEscrow($userId);
            if ($record === null) {
                return null;
            }
            $fields = $storage->loadEscrowFields($userId, $fieldNames);
            $again  = $storage->loadEscrow($userId);
            if ($again !== null && $again->revision === $record->revision && hash_equals($again->wrapUser->toBase64(), $record->wrapUser->toBase64())) {
                return [$record, $fields];
            }
        }
        throw new StaleEscrowException(
            "The escrow of '{$userId}' was replaced between the reads of its envelope and of its fields, "
            . self::ATTEMPTS . ' times: deposits keep landing on it'
        );
    }
}
