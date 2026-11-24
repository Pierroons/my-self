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

echo "\n▸ Une IPv4 présentée en IPv6 revient à son IPv4 — sinon tous les clients IPv4 n'ont qu'une clé\n";

// 🔴 Le défaut que ces vecteurs plantent, relevé par la conv Recover le 19/11/2026
// (REC-1911-0703). `inet_pton('::ffff:1.2.3.4')` rend SEIZE octets dont les douze
// premiers sont le préfixe : garder les huit premiers ne gardait que des zéros, donc
// rendait `::` pour TOUTE adresse IPv4 ainsi présentée. Un seul visiteur bruyant
// épuisait alors la file de tous les autres. Le lab ne reçoit cette forme que si nginx
// écoute en double pile, ce que personne n'a mesuré sur l'instance — le banc ne dépend
// pas de cette question, et c'est pour cela qu'il la pose ici.
v('⭐ une IPv4 présentée en IPv6 rend la clé de son /24, pas « :: »',
    cle('::ffff:203.0.113.45') === '203.0.113.0', (string) cle('::ffff:203.0.113.45'));
v('⭐ deux IPv4 mappées de /24 DIFFÉRENTS ne partagent pas la même clé',
    cle('::ffff:203.0.113.1') !== cle('::ffff:198.51.100.1'),
    cle('::ffff:203.0.113.1') . ' vs ' . cle('::ffff:198.51.100.1'));
v('la forme mappée et l\'IPv4 nue du même client rendent LA MÊME clé',
    cle('::ffff:203.0.113.45') === cle('203.0.113.45'),
    cle('::ffff:203.0.113.45') . ' vs ' . cle('203.0.113.45'));
v('deux adresses mappées d\'un même /24 rendent la même clé',
    cle('::ffff:203.0.113.1') === cle('::ffff:203.0.113.254'));
v('une adresse NAT64 revient aussi à son /24',
    cle('64:ff9b::203.0.113.45') === '203.0.113.0', (string) cle('64:ff9b::203.0.113.45'));
v('⭐ et deux NAT64 de /24 différents ne se confondent pas',
    cle('64:ff9b::203.0.113.1') !== cle('64:ff9b::198.51.100.1'));
v('une IPv6 qui COMMENCE par des zéros sans être mappée garde son /64',
    cle('0:0:0:1::5') === '0:0:0:1::', (string) cle('0:0:0:1::5'));

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
