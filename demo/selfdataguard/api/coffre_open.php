<?php

declare(strict_types=1);

/**
 * Ouvre le coffre d'un user : renvoie la ZONE PRIVÉE (E2E) + la ZONE ESCROW
 * (récup assistée), en une seule authentification (le master_key ne persiste
 * jamais côté serveur).
 */

require __DIR__ . '/_bootstrap.php';

use Pierroons\SelfDataGuard\Escrow\EscrowEnvelopeMismatchException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body   = json_input();
$userId = (string) ($body['userId'] ?? '');
$secret = (string) ($body['secret'] ?? '');
$mode   = (string) ($body['mode']   ?? 'password');

if ($userId === '' || $secret === '') fail('userId and secret are required');

try {
    $session = match ($mode) {
        'password'  => $dataGuard->loginWithPassword($userId, $secret),
        'memorized' => $dataGuard->loginWithMemorized($userId, $secret),
        default     => fail('mode must be "password" or "memorized"'),
    };
    // Ce que le gardien atteste des remises du séquestre. Cette démo n'a pas de
    // gardien : la réponse le dit (« non établi »), elle ne dit pas « aucune remise ».
    $escrowReads = $dataGuard->escrowReads($session);
    // Une ligne de séquestre qui n'ouvre plus ses champs ne doit pas cacher la vue :
    // son exception reste dans ce bloc, et la réponse porte les deux.
    try {
        $escrow      = $dataGuard->getEscrowFieldsAsUser($session);
        $escrowError = null;
    } catch (EscrowEnvelopeMismatchException) {
        $escrow      = null;
        $escrowError = "les champs du séquestre ne s'ouvrent pas sous la ligne en base : elle a été supprimée, remise d'une sauvegarde ou réécrite, ou un champ a été réécrit";
    }
    ok([
        'userId'      => $userId,
        'mode'        => $mode,
        'private'     => $dataGuard->getFields($session),
        'escrow'      => $escrow,
        'escrowError' => $escrowError,
        'hasEscrow'   => $dataGuard->hasEscrow($session),
        'escrowReads' => $escrowReads,
    ]);
} catch (RuntimeException $e) {
    fail_vault($e);
}
