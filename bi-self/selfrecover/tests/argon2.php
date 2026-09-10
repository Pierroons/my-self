<?php

declare(strict_types=1);

/**
 * Éprouve les vecteurs de la KDF contre `sodium_crypto_pwhash`.
 *
 * Usage : php tests/argon2.php
 * Sort 0 si tout passe, 1 sinon. Exige l'extension sodium.
 *
 * ── Pourquoi un oracle PHP alors que la KDF vit dans le navigateur ──────────
 *
 * `client/sr-kdf.js` dérive une clé qui ne quitte jamais le poste. Aucun serveur
 * n'en a besoin, et ce fichier n'est donc PAS un composant de production — pas
 * plus que `derivation.php` ne l'est.
 *
 * Il existe pour une seule raison, et elle a pris du poids : `client/argon2id.js`
 * est écrit dans ce dépôt. Une implémentation qu'aucune autre ne contredit peut se
 * tromper sans qu'on le sache — et une qu'on a écrite soi-même n'échappe pas à la
 * règle, elle y est le plus exposée.
 *
 * libsodium est donc le TÉMOIN INDÉPENDANT : du C, écrit par d'autres, audité, qui
 * ne partage pas une ligne avec notre JavaScript. C'est lui qui rend le vert de
 * `argon2.js` probant. Le remplacer par une seconde implémentation maison ferait
 * comparer notre code à notre code, et les vecteurs cesseraient de prouver quoi
 * que ce soit.
 *
 * ⚠️ La différence avec `derivation.php` mérite d'être dite, parce qu'elle est
 * inverse : là-bas, l'oracle PHP est DANGEREUX à déplacer dans `src/` — il
 * calculerait côté serveur une empreinte qui ne doit jamais y arriver. Ici,
 * rien de tel : la clé n'est pas une preuve, elle ne transite pas, et un serveur
 * qui la calculerait ne trahirait aucune propriété — il n'aurait simplement
 * aucune raison de le faire.
 *
 * ── L'unité de la mémoire ───────────────────────────────────────────────────
 *
 * Les vecteurs portent `m` en KIBIOCTETS — l'unité de la RFC 9106, de PHP et de
 * LUKS. `sodium_crypto_pwhash` prend des OCTETS. La conversion est le défaut le
 * plus fréquent de ce coin-là, et il est silencieux : un facteur 1024 ne change
 * pas la forme du résultat, seulement son coût.
 */

if (!function_exists('sodium_crypto_pwhash')) {
    fwrite(STDERR, "L'extension sodium est absente — cet oracle ne peut rien vérifier.\n");
    exit(1);
}

$echecs = 0;
function verdict(string $quoi, bool $ok, string $detail = ''): void
{
    global $echecs;
    echo ($ok ? '  ok     ' : '  RATE   ') . $quoi . ($detail !== '' ? " — $detail" : '') . "\n";
    if (!$ok) {
        $echecs++;
    }
}

$brut = file_get_contents(__DIR__ . '/vecteurs-argon2.json');
if ($brut === false) {
    fwrite(STDERR, "vecteurs-argon2.json introuvable\n");
    exit(1);
}
$doc = json_decode($brut, true, 512, JSON_THROW_ON_ERROR);

// 🔑 Une boucle sur un tableau vide ne rougit pas : elle ne fait rien, et la
// sonde annonce « tout passe ». Un fichier de vecteurs vidé — par un conflit de
// fusion mal résolu, par une génération ratée — rendrait donc le même vert qu'un
// dépôt sain. Le plancher est le seul contrôle de ce fichier qui ne dépende pas
// de son contenu.
echo "── Le jeu de vecteurs est-il seulement là ? ───────────\n";
verdict('au moins 7 vecteurs', count($doc['vecteurs'] ?? []) >= 7,
    count($doc['vecteurs'] ?? []) . ' présent(s)');
verdict('au moins 8 cas de refus', count($doc['refus'] ?? []) >= 8,
    count($doc['refus'] ?? []) . ' présent(s)');

echo "\n── L'oracle libsodium retrouve-t-il les vecteurs ? ────\n";
foreach ($doc['vecteurs'] as $v) {
    $obtenu = bin2hex(sodium_crypto_pwhash(
        $v['dkLen'],
        $v['mot'],
        hex2bin($v['sel']),
        $v['t'],
        $v['m'] * 1024,          // KiB → octets, cf. l'entête
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
    ));
    verdict($v['quoi'], $obtenu === $v['cle'],
        $obtenu === $v['cle'] ? '' : substr($obtenu, 0, 16) . '… attendu ' . substr($v['cle'], 0, 16) . '…');
}

echo "\n── Ce que les vecteurs doivent prouver entre eux ──────\n";

// 🔑 Sans ceci, un jeu de vecteurs tous identiques passerait au vert. On exige
// donc que chaque entrée du calcul se voie dans le résultat.
$par = [];
foreach ($doc['vecteurs'] as $v) {
    $par[$v['quoi']] = $v;
}
$base = $par['profil courant, sel ordinaire']['cle'] ?? '';
$paires = [
    "🔑 le MÊME mot, un autre sel — doit différer du premier"            => 'le sel entre dans le calcul',
    "🔑 un autre mot, le MÊME sel — doit différer aussi"                 => 'le mot entre dans le calcul',
    "🔑 même mot, même sel, t=4 — les PARAMÈTRES entrent dans le calcul" => "le nombre de passes n'est pas décoratif",
    "🔑 même mot, même sel, m=128 Mio — la mémoire aussi"                => 'la mémoire non plus',
];
foreach ($paires as $quoi => $pourquoi) {
    $autre = $par[$quoi]['cle'] ?? '';
    verdict($pourquoi, $base !== '' && $autre !== '' && $base !== $autre,
        $autre === '' ? 'vecteur absent' : '');
}

$toutes = array_column($doc['vecteurs'], 'cle');
verdict('toutes les clés font 64 hexadécimaux',
    count(array_filter($toutes, static fn ($c) => (bool) preg_match('/^[0-9a-f]{64}$/', $c))) === count($toutes));
verdict('aucun doublon entre les vecteurs',
    count(array_unique($toutes)) === count($toutes),
    (count($toutes) - count(array_unique($toutes))) . ' doublon(s)');

echo "\n── La condition qui permet à cet oracle d'exister ─────\n";

// 🔑 libsodium ne sait produire que p=1. Un vecteur avec p=2 serait invérifiable
// ici, et la sonde le passerait en silence si on ne le disait pas : le contrôle
// ci-dessus ne parcourt que ce que le fichier contient.
$pDivers = array_values(array_unique(array_column($doc['vecteurs'], 'p')));
verdict('tous les vecteurs sont à p=1', $pDivers === [1],
    'p=' . implode(',', $pDivers) . ' — libsodium ne sait produire que p=1, un autre serait invérifiable ici');
verdict("le profil d'écriture déclaré est à p=1", ($doc['profil_ecriture']['p'] ?? null) === 1);

echo "\n── Les cas de refus éprouvent-ils quelque chose ? ─────\n";

// L'oracle PHP ne refuse rien — il calcule. Ce qu'il peut vérifier, c'est qu'un
// cas de refus porte réellement une valeur invalide : un « refus » dont toutes
// les entrées seraient conformes ne mesurerait rien, et la sonde JS passerait au
// vert en croyant avoir constaté un refus.
foreach ($doc['refus'] as $r) {
    $selMauvais = !is_string($r['sel'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $r['sel']);
    $motMauvais = ($r['mot'] ?? null) === '';
    $algMauvais = isset($r['alg']) && $r['alg'] !== $doc['alg'];
    $sousPlancher = (isset($r['t']) && $r['t'] < $doc['plancher_lecture']['t'])
        || (isset($r['m']) && $r['m'] < $doc['plancher_lecture']['m'])
        || (isset($r['p']) && $r['p'] < $doc['plancher_lecture']['p']);

    verdict('« ' . $r['quoi'] . ' » porte bien une entrée que la forme rejette',
        $selMauvais || $motMauvais || $algMauvais || $sousPlancher);
}

echo "\n";
if ($echecs === 0) {
    echo '  ' . count($doc['vecteurs']) . " vecteurs, libsodium les retrouve tous.\n";
    exit(0);
}
echo "  $echecs contrôle(s) en échec.\n";
exit(1);
