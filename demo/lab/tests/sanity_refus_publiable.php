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

// ── 7. 🔑 Aucun motif de la bibliothèque ne tombe dans le défaut ───────────
// `RecoverL3::http()` traduit le motif en code HTTP, avec `?? 400` au bout. Un
// défaut est muet : le jour où la bibliothèque ajoute un motif, il part en 400
// sans que personne l'apprenne — `accord_perime` l'a fait, et un accord périmé
// annoncé « requête invalide » envoie le titulaire chercher l'erreur chez lui.
// Ce cas lit les motifs dans la SOURCE de la bibliothèque plutôt que dans une
// liste recopiée ici : une liste aurait le même angle mort que la table.
//
// ⚠️ Il lit la bibliothèque RÉSOLUE (`vendor/`), pas la copie du dépôt : les
// deux coïncident en intégration, mais pas dans un arbre de travail qui pointe
// ailleurs — et lire l'une en exécutant l'autre rend un vert sur une version
// qui ne tourne pas.
//
// ⚠️ Et deux formes d'émission, pas une : un `return [... 'error' => '…']`, et
// un refus fabriqué par une fermeture (`$refuser('…', …)`). N'en chercher
// qu'une laissait trois motifs invisibles, dont `accord_perime` — celui-là même
// qui a motivé ce cas.
echo "\n7. Les motifs de la bibliothèque ont tous leur code\n";
$src = __DIR__ . '/../vendor/pierroons/selfrecover/src/Recovery/Escalade.php';
if (!is_file($src)) {
    v('la source de la bibliothèque a été trouvée', false, $src);
} else {
    $codeBiblio = (string) file_get_contents($src);
    preg_match_all('/\x27error\x27\s*=>\s*\x27([a-z_]+)\x27/', $codeBiblio, $mm);
    preg_match_all('/\$refuser\(\s*\x27([a-z_]+)\x27/', $codeBiblio, $mr);
    $mm[1] = array_merge($mm[1], $mr[1]);
    $motifs = array_values(array_unique($mm[1]));
    sort($motifs);
    v('des motifs ont été extraits', count($motifs) > 10, count($motifs) . ' motif(s)');

    // Les six premiers cas tournent sans la pile, exprès : `reponse.php` doit
    // se charger seul. Celui-ci lit une constante de classe, donc il lui faut
    // le relais, qui exige l'autochargeur — d'où ce require tardif, après que
    // la propriété a été éprouvée.
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../lib/recover_l3.php';
    $codes = (new ReflectionClass(\Pierroons\MySelfLab\RecoverL3::class))
        ->getReflectionConstant('CODES')->getValue();
    $orphelins = array_values(array_diff($motifs, array_keys($codes)));
    v('chaque motif a son code explicite', $orphelins === [],
        'tombent en 400 par défaut : ' . implode(', ', $orphelins));

    // Et l'inverse : une entrée pour un motif qui n'existe plus est du code mort
    // qui laisse croire qu'un cas est couvert. `invalid_derived_key` vient du
    // relais lui-même, pas de la bibliothèque — d'où l'exception nommée.
    $inutiles = array_values(array_diff(array_keys($codes), $motifs, ['invalid_derived_key']));
    v('aucune entrée ne vise un motif disparu', $inutiles === [], implode(', ', $inutiles));
}

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
