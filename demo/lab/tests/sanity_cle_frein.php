#!/usr/bin/env php
<?php
/**
 * L'origine que les freins comptent est un PRÉFIXE, et elle l'est aussi sur les
 * formes compressées.
 *
 * 🔑 Pourquoi ce banc existe. Le 11/11/2026, la conv Formation a mesuré que les
 * trois plafonds par origine du lab — comptes créés, échecs cumulés, échecs de
 * récupération — comparaient `ip = ?` sur l'adresse entière, à quatre endroits.
 * Le vhost agrégeait déjà ses files par préfixe depuis le matin ; l'application,
 * non. Les deux remparts partageaient donc la même faiblesse : un /64 routé donne
 * 2^64 clés, et aucun compteur ne mord.
 *
 * ⚠️ Le cas qui a motivé le masque binaire plutôt qu'un motif de texte : une
 * adresse à zéros compressés (`2001:db8::1`) n'a que trois champs avant `::`. Un
 * motif qui en attend quatre la laisse passer entière — c'est l'angle mort que le
 * `map $cle_frein` du vhost porte encore (inscrit à `defauts.md`), et que cette
 * fonction n'a pas. Les deux derniers cas en dessous sont là pour qu'une
 * réécriture « plus simple » par expression régulière se fasse voir.
 *
 * Usage : php tests/sanity_cle_frein.php
 */

declare(strict_types=1);

$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
require __DIR__ . '/../lib/bootstrap.php';

$echecs = 0;
$reussites = 0;

/** @param bool $ok */
function v(string $quoi, bool $ok, string $detail = ''): void
{
    global $echecs, $reussites;
    if ($ok) {
        $reussites++;
        echo "  ✓ $quoi\n";

        return;
    }
    $echecs++;
    echo "  ✗ $quoi" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/** La clé que les freins emploieraient pour cette adresse. */
function cle(?string $adresse): ?string
{
    if ($adresse === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $adresse;
    }

    return client_ip();
}

echo "▸ L'origine comptée est un préfixe, pas une adresse\n";

v('une IPv4 est réduite à son /24', cle('203.0.113.45') === '203.0.113.0', (string) cle('203.0.113.45'));
v('une IPv6 pleine est réduite à son /64',
    cle('2001:db8:1:2:3:4:5:6') === '2001:db8:1:2::', (string) cle('2001:db8:1:2:3:4:5:6'));
v('une IPv6 d\'opérateur est réduite à son /64',
    cle('2a01:e0a:1f2:3c40::9') === '2a01:e0a:1f2:3c40::', (string) cle('2a01:e0a:1f2:3c40::9'));

echo "\n▸ Deux adresses du même client partagent une clé, deux clients n'en partagent pas\n";

v('⭐ deux adresses d\'un même /64 rendent LA MÊME clé',
    cle('2a01:e0a:1f2:3c40::1') === cle('2a01:e0a:1f2:3c40:ffff:ffff:ffff:ffff'),
    cle('2a01:e0a:1f2:3c40::1') . ' vs ' . cle('2a01:e0a:1f2:3c40:ffff:ffff:ffff:ffff'));
v('⭐ deux /64 voisins rendent des clés DIFFÉRENTES',
    cle('2a01:e0a:1f2:3c40::1') !== cle('2a01:e0a:1f2:3c41::1'));
v('deux adresses d\'un même /24 rendent la même clé',
    cle('203.0.113.1') === cle('203.0.113.254'));
v('deux /24 voisins rendent des clés différentes',
    cle('203.0.113.1') !== cle('203.0.114.1'));

echo "\n▸ Les formes compressées, que le motif du vhost laisse passer entières\n";

v('⭐ une IPv6 à zéros compressés est RÉDUITE (trois champs avant « :: »)',
    cle('2001:db8::1') === '2001:db8::', (string) cle('2001:db8::1'));
v('⭐ et deux adresses de ce même /64 rendent la même clé',
    cle('2001:db8::1') === cle('2001:db8:0:0:ffff::2'),
    cle('2001:db8::1') . ' vs ' . cle('2001:db8:0:0:ffff::2'));
v('le lien-local est réduit lui aussi', cle('fe80::1') === 'fe80::', (string) cle('fe80::1'));

echo "\n▸ Ce qui n'est pas une adresse ne devient pas une origine commune\n";

v('une valeur non reconnue compte pour elle seule, pas pour une constante',
    cle('pas-une-ip') === 'pas-une-ip', var_export(cle('pas-une-ip'), true));
v('⭐ deux valeurs non reconnues ne se confondent PAS',
    cle('zzz') !== cle('yyy'));
v('une origine absente reste nulle, et n\'est pas rattrapée', cle(null) === null, var_export(cle(null), true));
v('une origine vide reste nulle', cle('') === null, var_export(cle(''), true));

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
