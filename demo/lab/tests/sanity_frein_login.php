#!/usr/bin/env php
<?php
/**
 * Le frein de la page de connexion mord, et il mord sur les deux axes.
 *
 * Aucun banc ne le gardait — relevé par la conv Recover le 07/10/2026 en
 * auditant le lab pour SelfRecover 0.11.0. Ce frein ne change pas avec la
 * 0.11.0 : elle sépare le compteur du niveau 1 du nôtre, et `Auth::login()`
 * garde le sien sous le nom en clair. Ce banc existe donc pour qu'une
 * régression se voie — un frein qui cesse de mordre ne fait aucun bruit.
 *
 * ⚠️ Un cas documente ici un défaut CONNU et PUBLIÉ (limites de `/redteam.php`) :
 * le compteur est alimenté par le nom SOUMIS, donc un tiers ferme la porte d'un
 * compte en cinq requêtes. Ce n'est pas une régression à corriger côté lab, et
 * le banc l'affirme pour que personne ne « répare » le contraire par mégarde.
 *
 * Usage : php tests/sanity_frein_login.php
 */

declare(strict_types=1);

$dir = sys_get_temp_dir() . '/sanity_frein_login_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});
putenv('LAB_DB_PATH=' . $dir . '/lab.db');
putenv('LAB_STATE_DIR=' . $dir);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Db;

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

$pdo = Db::pdo();

/**
 * Crée un compte et rend ses accès — le serveur les tire, on ne les choisit pas.
 *
 * @return array{password: string, passphrase: string}
 */
function compte(PDO $pdo, string $nom): array
{
    $r = Auth::register($pdo, $nom, str_repeat('a', 64), str_repeat('b', 32));
    if (($r['ok'] ?? false) !== true) {
        fwrite(STDERR, "  impossible de créer « $nom » : " . (string) ($r['error'] ?? '?') . "\n");
        exit(1);
    }

    return ['password'   => (string) $r['credentials']['password'],
            'passphrase' => (string) $r['credentials']['passphrase']];
}

/** Vide le compteur entre deux cas : chacun doit partir d'une ardoise propre. */
function ardoise(PDO $pdo): void
{
    $pdo->exec('DELETE FROM login_attempts');
}

echo 'Seuils lus dans le code : ' . Auth::LOGIN_MAX_FAILS . " échecs par compte, "
    . Auth::LOGIN_MAX_FAILS_PER_IP . " par origine, fenêtre de " . Auth::LOGIN_WINDOW . " s.\n\n";

$acces = compte($pdo, 'titulaire');
$mdp   = $acces['password'];
compte($pdo, 'voisin');

// ── 1. Le seuil par compte ──────────────────────────────────────────────────
echo "1. Le frein par compte\n";
ardoise($pdo);
for ($i = 1; $i < Auth::LOGIN_MAX_FAILS; $i++) {
    Auth::login($pdo, 'titulaire', 'faux', '198.51.100.1');
}
$r = Auth::login($pdo, 'titulaire', $mdp, '198.51.100.1');
v('sous le seuil, le bon mot de passe ouvre', ($r['ok'] ?? false) === true,
    'statut : ' . (string) ($r['status'] ?? ($r['error'] ?? '?')));

ardoise($pdo);
for ($i = 0; $i < Auth::LOGIN_MAX_FAILS; $i++) {
    Auth::login($pdo, 'titulaire', 'faux', '198.51.100.1');
}
$r = Auth::login($pdo, 'titulaire', $mdp, '198.51.100.1');
v('au seuil, même le bon mot de passe est refusé', ($r['ok'] ?? false) === false);
v('et le refus se nomme « locked »', ($r['status'] ?? '') === 'locked',
    'obtenu : ' . (string) ($r['status'] ?? '—'));

// ── 2. Le seuil par origine ─────────────────────────────────────────────────
// Il existe pour le spraying : un même mot de passe essayé sur beaucoup de
// comptes ne franchit jamais le seuil par compte, mais sature celui de l'origine.
echo "\n2. Le frein par origine\n";
ardoise($pdo);
for ($i = 0; $i < Auth::LOGIN_MAX_FAILS_PER_IP; $i++) {
    Auth::login($pdo, 'compte_' . $i, 'faux', '203.0.113.9');
}
$r = Auth::login($pdo, 'titulaire', $mdp, '203.0.113.9');
v('au seuil d\'origine, un compte jamais visé est fermé aussi', ($r['ok'] ?? false) === false);
$r = Auth::login($pdo, 'titulaire', $mdp, '203.0.113.10');
v('mais depuis une autre origine, il ouvre', ($r['ok'] ?? false) === true,
    'statut : ' . (string) ($r['status'] ?? ($r['error'] ?? '?')));

// ── 3. Une réussite ne purge pas l'ardoise ──────────────────────────────────
echo "\n3. Ce qu'une réussite ne fait pas\n";
ardoise($pdo);
Auth::login($pdo, 'titulaire', $mdp, '198.51.100.2');
$restants = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE success = 1")->fetchColumn();
v('une connexion réussie est tracée', $restants === 1, "$restants ligne(s)");
for ($i = 0; $i < Auth::LOGIN_MAX_FAILS; $i++) {
    Auth::login($pdo, 'titulaire', 'faux', '198.51.100.2');
}
$r = Auth::login($pdo, 'titulaire', $mdp, '198.51.100.2');
v('une réussite antérieure ne lève pas le frein', ($r['ok'] ?? false) === false);

// ── 4. Le défaut connu et publié — il doit RESTER vrai ──────────────────────
// Publié dans les limites de `/redteam.php`, FR et EN : « Un tiers ferme la
// connexion d'un compte en cinq requêtes ». Le compteur est alimenté par le nom
// SOUMIS, que personne n'a besoin de posséder pour l'écrire. Si ce cas se met à
// échouer un jour, ce n'est pas une régression : c'est que quelqu'un a corrigé
// le défaut, et la limite publiée doit être retirée en même temps.
echo "\n4. Le défaut publié : un tiers ferme la connexion d'un compte\n";
ardoise($pdo);
for ($i = 0; $i < Auth::LOGIN_MAX_FAILS; $i++) {
    // Une origine différente à chaque essai : seul le seuil par COMPTE joue ici.
    Auth::login($pdo, 'titulaire', 'faux', '192.0.2.' . $i);
}
$r = Auth::login($pdo, 'titulaire', $mdp, '198.51.100.3');
v('un tiers ferme le compte depuis des origines distinctes', ($r['ok'] ?? false) === false,
    'si ce cas échoue, le défaut a été corrigé : retirer la limite publiée de rt.limits.body');

// ── 5. Un nom qui n'existe pas ne trahit rien ───────────────────────────────
echo "\n5. L'anti-énumération\n";
ardoise($pdo);
$a = Auth::login($pdo, 'titulaire', 'faux', '198.51.100.4');
$b = Auth::login($pdo, 'nexistepas_x', 'faux', '198.51.100.4');
v('le refus est identique pour un compte réel et un inconnu',
    ($a['message'] ?? 'a') === ($b['message'] ?? 'b') && ($a['ok'] ?? null) === ($b['ok'] ?? null),
    json_encode([$a['message'] ?? null, $b['message'] ?? null], JSON_UNESCAPED_UNICODE));

// ── 6. 🔑 Le verrou de connexion n'atteint PAS la récupération ──────────────
// La limite publiée affirmait le contraire jusqu'au 07/10/2026, et elle avait
// raison avant que SelfRecover 0.11.0 sépare les deux compteurs. Ce qui est
// mesuré ici : après cinq verrouillages, la bonne passphrase ouvre toujours une
// récupération de niveau 1. Si ce cas rougit, les compteurs se sont recouplés —
// et un tiers referme alors la dernière porte du titulaire, pas seulement la
// première. La limite publiée devrait revenir dans le même geste.
echo "\n6. Le verrou de connexion laisse la récupération ouverte\n";
ardoise($pdo);
for ($i = 0; $i < Auth::LOGIN_MAX_FAILS; $i++) {
    Auth::login($pdo, 'titulaire', 'faux', '192.0.2.' . (100 + $i));
}
$l = Auth::login($pdo, 'titulaire', $mdp, '198.51.100.5');
v('la connexion est bien verrouillée', ($l['ok'] ?? true) === false,
    'sans ce préalable, le cas suivant ne prouverait rien');
$rec = Auth::recoverByPassphrase($pdo, 'titulaire', $acces['passphrase'], '203.0.113.77');
v('la récupération de niveau 1 ouvre malgré le verrou', ($rec['ok'] ?? false) === true,
    'motif : ' . (string) ($rec['error'] ?? '—') . ' — les deux compteurs se sont recouplés, '
    . 'remettre la limite publiée');

// ── 7. L'autre moitié de la question : une ORIGINE saturée ──────────────────
// ⚠️ Ce cas ne vaut que par SES DEUX MOITIÉS. Des échecs de connexion ne doivent
// plus fermer la récupération — et des échecs de récupération doivent la fermer
// encore. La première seule passerait aussi bien si le frein par origine avait
// été supprimé au lieu d'être ciblé, ce qui rouvrirait l'énumération qu'il borne.
echo "\n7. Une origine saturée d'échecs de CONNEXION laisse la récupération ouverte\n";
// ⚠️ Un compte NEUF par assertion : une récupération réussie remplace la
// passphrase, donc réutiliser celle du cas 6 ferait passer une assertion pour
// la mauvaise raison — un secret périmé, pas l'origine.
$ciblA = compte($pdo, 'cible_origine_a');
$ciblB = compte($pdo, 'cible_origine_b');

ardoise($pdo);
for ($i = 0; $i < Auth::LOGIN_MAX_FAILS_PER_IP; $i++) {
    // Des comptes qui n'existent pas : le compte visé n'est jamais en cause.
    Auth::login($pdo, 'passant_' . $i, 'faux', '198.51.100.42');
}
$recIp = Auth::recoverByPassphrase($pdo, 'cible_origine_a', $ciblA['passphrase'], '198.51.100.42');
v('⭐ depuis l\'origine saturée de connexions, la bonne passphrase ouvre quand même',
    ($recIp['ok'] ?? false) === true,
    'motif : ' . (string) ($recIp['error'] ?? '—') . ' — les deux portes partagent de nouveau '
    . 'leur compteur par origine, et douze requêtes referment la récupération d\'un inconnu');

// Contre-témoin : c'est bien le secret et le compte qui ouvrent, pas une origine
// devenue sans effet.
$recAilleurs = Auth::recoverByPassphrase($pdo, 'cible_origine_b', $ciblB['passphrase'], '203.0.113.88');
v('et depuis une origine propre, le même secret ouvre aussi',
    ($recAilleurs['ok'] ?? false) === true,
    'motif : ' . (string) ($recAilleurs['error'] ?? '—'));

// ── 7 bis. Le frein par origine n'a pas disparu, il a été CIBLÉ ─────────────
// Les échecs sont répartis sur des comptes distincts, quatre par compte, sous le
// seuil par compte (5) : ce qui mord ici ne peut donc être que l'origine.
echo "\n7 bis. La même origine, saturée d'échecs de RÉCUPÉRATION, freine bien\n";
$porteurs = [];
foreach (['porteur_a', 'porteur_b', 'porteur_c'] as $nom) {
    $porteurs[$nom] = compte($pdo, $nom);
}
$ciblC = compte($pdo, 'cible_origine_c');

ardoise($pdo);
foreach (array_keys($porteurs) as $nom) {
    for ($i = 0; $i < 4; $i++) {
        Auth::recoverByPassphrase($pdo, $nom, 'cheval agrafe batterie faux', '198.51.100.43');
    }
}
$recSature = Auth::recoverByPassphrase($pdo, 'cible_origine_c', $ciblC['passphrase'], '198.51.100.43');
v('⭐ douze échecs de récupération ferment bien cette origine — le frein est ciblé, pas retiré',
    ($recSature['ok'] ?? false) === false,
    'elle a ouvert : `compterEchecsIp()` ne compte plus RIEN, donc l\'énumération '
    . 'que ce frein borne est rouverte — vérifier la liste de préfixes passée par la bibliothèque');

// Contre-témoin : la même passphrase, depuis une origine propre, ouvre.
$recPropre = Auth::recoverByPassphrase($pdo, 'cible_origine_c', $ciblC['passphrase'], '203.0.113.99');
v('et le même secret ouvre depuis une origine propre — c\'est l\'origine qui fermait',
    ($recPropre['ok'] ?? false) === true,
    'motif : ' . (string) ($recPropre['error'] ?? '—'));

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
