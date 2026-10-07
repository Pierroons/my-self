#!/usr/bin/env php
<?php
/**
 * Un refus ne publie que ce qu'on a décidé — et un succès n'est pas filtré.
 *
 * Les enveloppes du lab rendaient la réponse de la bibliothèque telle quelle sur
 * échec, et les endpoints la passaient à `json_out()`. Toute clé ajoutée en
 * amont sortait donc sur une route publique sans décision. Le cas s'est
 * présenté : un premier jet de SelfRecover 0.11.0 joignait au refus du niveau 1
 * un compteur d'essais plausibles, qui disait à qui connaît un nom public qu'un
 * tiers était en train de perdre ce compte.
 *
 * Les deux moitiés comptent. Un filtre qui laisse tout passer est inutile ; un
 * filtre qui s'applique aussi aux succès perdrait les secrets rendus une seule
 * fois, ce qui est le défaut que `0dd7ae4` vient de corriger sur la reprise de
 * compte au niveau 3.
 *
 * Usage : php tests/sanity_refus_publiable.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/reponse.php';

$echecs = 0;
$reussites = 0;
function v(string $quoi, bool $vrai, string $detail = ''): void
{
    global $echecs, $reussites;
    if ($vrai) {
        echo "  ✓ $quoi\n";
        $reussites++;
    } else {
        fwrite(STDERR, "  ✗ $quoi" . ($detail !== '' ? " — $detail" : '') . "\n");
        $echecs++;
    }
}

// ── 1. Le cas réel qui a motivé cette garde ─────────────────────────────────
echo "1. La clé qui a failli sortir\n";
$r = refus_publiable([
    'ok'          => false,
    'error'       => 'identifiant_ou_passphrase_incorrect',
    'message'     => 'Identifiant ou passphrase incorrect.',
    'signalement' => 'essais_plausibles',
]);
v('« signalement » ne sort pas', !array_key_exists('signalement', $r), json_encode($r));
v('le motif de refus sort',       ($r['error'] ?? '') === 'identifiant_ou_passphrase_incorrect');
v('le message sort',              ($r['message'] ?? '') !== '');
v('« ok » reste à false',         ($r['ok'] ?? null) === false);

// ── 2. Toute clé inconnue, pas seulement celle-là ───────────────────────────
echo "\n2. Une clé inconnue quelconque\n";
$r = refus_publiable([
    'ok' => false, 'error' => 'x', 'message' => 'y',
    'essais' => 4, 'compte_id' => 7, 'ip_hash' => 'deadbeef', 'signalement' => 's',
]);
v('aucune des quatre clés inconnues ne sort',
    array_keys($r) === ['ok', 'error', 'message'],
    'obtenu : ' . implode(', ', array_keys($r)));

// `motif` est au contrat : il dit OÙ une passphrase apportée est fautive, jamais
// quel mot. Le lab ne propose pas encore ce chemin, et c'est exprès qu'il y est
// déjà — un filtre dont personne ne se souviendrait rendrait un jour un refus
// muet sur ce qui cloche.
$r = refus_publiable(['ok' => false, 'error' => 'passphrase_invalide', 'motif' => 'mot_repete', 'message' => 'm']);
v('« motif » sort, il est au contrat', ($r['motif'] ?? null) === 'mot_repete');

// ── 3. Le code HTTP que les enveloppes ajoutent ─────────────────────────────
echo "\n3. Le code HTTP du lab\n";
$r = refus_publiable(['ok' => false, 'error' => 'clos', 'message' => 'm', 'code' => 409, 'interne' => 'z']);
v('« code » sort',          ($r['code'] ?? null) === 409);
v('« interne » ne sort pas', !array_key_exists('interne', $r));

// ── 4. Une clé explicitement décidée ────────────────────────────────────────
echo "\n4. Ce que l'appelant autorise nommément\n";
$r = refus_publiable(['ok' => false, 'error' => 'e', 'message' => 'm', 'retard' => 30, 'secret' => 's'], ['retard']);
v('la clé autorisée sort',      ($r['retard'] ?? null) === 30);
v('les autres restent dehors',  !array_key_exists('secret', $r));

// ── 5. Un succès n'est JAMAIS filtré ────────────────────────────────────────
echo "\n5. Un succès passe intact\n";
$succes = [
    'ok' => true,
    'message' => 'Compte repris.',
    'credentials' => ['passphrase' => 'alpha bravo charlie', 'recovery_codes' => ['aaaaa-bbbbb']],
    'note' => 'Copie-les maintenant.',
    'appareils_retires' => 2,
];
$r = refus_publiable($succes);
v('la réponse est rendue telle quelle', $r === $succes);
v('les secrets à usage unique survivent',
    isset($r['credentials']['recovery_codes'][0]),
    'un filtre appliqué aux succès perdrait la feuille de codes');

// ── 6. Les cas limites, pour que la garde ne lève pas elle-même ─────────────
echo "\n6. Cas limites\n";
v('un tableau vide ne lève pas',              refus_publiable([]) === []);
v('« ok » absent est traité comme un refus',  refus_publiable(['error' => 'e', 'z' => 1]) === ['error' => 'e']);
v('« ok » à 1 (et non true) est un refus',    !array_key_exists('z', refus_publiable(['ok' => 1, 'z' => 1])));

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
