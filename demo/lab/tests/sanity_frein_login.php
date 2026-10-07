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

/** Crée un compte et rend son mot de passe — le serveur le tire, on ne le choisit pas. */
function compte(PDO $pdo, string $nom): string
{
    $r = Auth::register($pdo, $nom, str_repeat('a', 64), str_repeat('b', 32));
    if (($r['ok'] ?? false) !== true) {
        fwrite(STDERR, "  impossible de créer « $nom » : " . (string) ($r['error'] ?? '?') . "\n");
        exit(1);
    }

    return (string) $r['credentials']['password'];
}

/** Vide le compteur entre deux cas : chacun doit partir d'une ardoise propre. */
function ardoise(PDO $pdo): void
{
    $pdo->exec('DELETE FROM login_attempts');
}

echo 'Seuils lus dans le code : ' . Auth::LOGIN_MAX_FAILS . " échecs par compte, "
    . Auth::LOGIN_MAX_FAILS_PER_IP . " par origine, fenêtre de " . Auth::LOGIN_WINDOW . " s.\n\n";

$mdp = compte($pdo, 'titulaire');
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
// Publié dans les limites de `/redteam.php`, FR et EN. Le compteur est alimenté
// par le nom SOUMIS : personne n'a besoin de posséder ce nom pour l'écrire.
// Si ce cas se met à échouer un jour, ce n'est pas une régression : c'est que
// quelqu'un a corrigé le défaut, et la limite publiée doit être retirée en même
// temps. Le banc le dit plutôt que de laisser deviner.
echo "\n4. Le défaut publié : un tiers ferme la porte d'un compte\n";
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

echo "\n" . ($echecs === 0
    ? "OK — $reussites/$reussites contrôles conformes.\n"
    : "ÉCHEC — $echecs sur " . ($echecs + $reussites) . ".\n");
exit($echecs === 0 ? 0 : 1);
