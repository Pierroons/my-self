#!/usr/bin/env php
<?php
/**
 * Un blob chiffré n'est lisible que là où il a été écrit.
 *
 * Aucun banc ne gardait `DataGuard` — relevé par la conv Recover le 19/10/2026.
 *
 * Ce que ces cas éprouvent n'est pas la clé, qui ne quitte pas le serveur : c'est
 * la donnée associée. Un chiffré sans contexte est valide partout où la même clé
 * est employée, et le wrapper n'en emploie qu'une pour les messages privés ET les
 * profils — d'où des cas qui déplacent des blobs d'une table à l'autre plutôt que
 * d'attaquer le chiffrement.
 *
 * ⚠️ **Ce que ces contrôles gardent n'est pas la confidentialité de la base** — le
 * README du lab ne promet que la résistance à une exfiltration, et une
 * exfiltration ne donne que des blobs. Ils gardent ce qui doit tenir **quand
 * l'écriture a cédé** : une injection SQL rend alors l'écriture, et rien de plus.
 *
 * Usage : php tests/sanity_dataguard.php
 */

declare(strict_types=1);

// La base et les quatre secrets de ce banc, à lui seul — jamais ceux de `data/`.
require __DIR__ . '/instance_isolee.inc';

$dir = sys_get_temp_dir() . '/sanity_dataguard_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
register_shutdown_function(static fn () => effacer_arbre($dir));
putenv('LAB_DB_PATH=' . $dir . '/lab.db');
putenv('LAB_STATE_DIR=' . $dir);
putenv('LAB_BLINDKEY_PATH=' . $dir . '/.blindkey');   // sans quoi le banc lit le secret réel de l'instance locale

require __DIR__ . '/../vendor/autoload.php';
// Un blob d'avant la 0.7.0, tel que SelfDataGuard l'écrivait : la bibliothèque n'en
// écrit plus, et sa définition unique vit dans ses propres tests.
require_once __DIR__ . '/../../../self-security/selfdataguard/tests/blob_v2_fixture.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/dataguard.php';
require_once __DIR__ . '/../lib/dm.php';
require_once __DIR__ . '/../lib/moderate.php';   // `Profile::save` refuse un compte banni
require_once __DIR__ . '/../lib/profile.php';
require_once __DIR__ . '/../lib/admin.php';
require_once __DIR__ . '/../lib/redteam.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DataGuard;
use Pierroons\MySelfLab\Dm;
use Pierroons\MySelfLab\Profile;
use Pierroons\MySelfLab\Admin;
use Pierroons\MySelfLab\Redteam;
use Pierroons\MySelfLab\SecretInstance;
use Pierroons\SelfDataGuard\Crypto\Aad;
use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Crypto\Primitives;

$echecs = 0;
$passes = 0;

function ok(string $quoi, bool $vrai, string $detail = ''): void
{
    global $echecs, $passes;
    if ($vrai) {
        $passes++;
        echo "  ✓ $quoi\n";
    } else {
        $echecs++;
        echo "  ✗ $quoi" . ($detail !== '' ? " : $detail" : '') . "\n";
    }
}

/** L'empreinte du blob d'une ligne de `dm`, pour voir s'il a changé. */
function sha256_blob(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT ciphertext FROM dm WHERE id = ?');
    $stmt->execute([$id]);

    return hash('sha256', (string) $stmt->fetchColumn());
}

/** Le déchiffrement a-t-il été refusé ? Rend le clair obtenu, ou null si refus. */
function clairOuRefus(callable $lecture): ?string
{
    try {
        return (string) $lecture();
    } catch (\Throwable) {
        return null;
    }
}

$pdo = Db::pdo();
$t = time();

// Le secret se pose ici, directement, sans traverser la garde de `DataGuard`. Le
// banc n'est pas une installation neuve : sans cette ligne, une garde redevenue
// inconditionnelle le ferait planter dès le premier envoi, et le cas qui nomme
// l'installation neuve ne serait jamais joué — un rouge venu d'ailleurs.
SecretInstance::lire('.blindkey', 48, SecretInstance::PLANCHER, 'LAB_BLINDKEY_PATH', static fn (): ?string => null);
$compte = static function (int $id, string $nom) use ($pdo, $t): void {
    $pdo->prepare(
        'INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$id, $nom, 'h', 'h', 'h', $t - 86400]);
};
$compte(1, 'alice');
$compte(2, 'bob');
$compte(3, 'carol');

echo "\n# Le chemin normal, d'abord — sans lui, un refus ne prouverait rien\n";

$secret = "Rendez-vous demain a 14h, n'en parle a personne.";
$r = Dm::send($pdo, 1, 'bob', $secret);
ok('alice écrit à bob', ($r['ok'] ?? false) === true, json_encode($r));

$recus = Dm::inbox($pdo, 2);
ok('bob lit le message qui lui est adressé', ($recus[0]['message'] ?? null) === $secret);

$envoyes = Dm::sent($pdo, 1);
ok('alice relit son propre envoi', ($envoyes[0]['message'] ?? null) === $secret);

Profile::save($pdo, 3, ['bio' => 'carol la prudente', 'lien' => 'https://exemple.test']);
ok('carol enregistre et relit son profil', (Profile::get($pdo, 3)['bio'] ?? '') === 'carol la prudente');

// Un message dont le clair EST le libellé d'un illisible : reconnu à son texte, il passait
// pour illisible, et deux suffisaient à écrire au journal l'alarme d'une clé remplacée
// (mesuré par la neuvième passe d'audit).
$journalDuBanc = $dir . '/erreurs.log';
$journalAvant = ini_set('error_log', $journalDuBanc);
Dm::send($pdo, 1, 'carol', '(message illisible ici)');
Dm::send($pdo, 1, 'carol', '(message illisible ici)');
$forges = Dm::inbox($pdo, 3);
ini_set('error_log', (string) $journalAvant);
ok(
    '⭐ un message au texte d\'un illisible se lit comme un message, et ne fait pas accuser la clé',
    count($forges) === 2 && array_column($forges, 'illisible') === [false, false]
        && !str_contains((string) @file_get_contents($journalDuBanc), 'illisibles'),
    json_encode($forges, JSON_UNESCAPED_UNICODE) . ' · ' . (string) @file_get_contents($journalDuBanc)
);
$pdo->exec('DELETE FROM dm WHERE recipient_id = 3');

echo "\n# Un blob déplacé n'est plus lisible — c'est l'objet de ce banc\n";

$blobDm = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 1')->fetchColumn();

// 1. le wrapper lui-même refuse un blob présenté sous un autre contexte.
//    ⚠️ Ne pas mesurer ce cas par `Profile::get()` : il passe le blob d'un message
//    privé à `json_decode`, qui rejette un non-JSON et rend un profil vide. La page
//    paraît donc sûre même quand le déchiffrement a réussi — un vert qui ne mesure
//    rien. C'est au wrapper qu'il faut poser la question.
$deplace = clairOuRefus(static fn() => DataGuard::decrypt($blobDm, DataGuard::contexteProfil($pdo, 2)));
ok(
    '⭐ un blob présenté sous le contexte d\'un profil est refusé',
    $deplace === null,
    'clair obtenu = ' . var_export($deplace, true)
);

// 2. le même blob, recopié dans une conversation dont le tiers EST destinataire
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (9, 1, 3, ?, ?)')
    ->execute([$blobDm, $t]);
$lu = clairOuRefus(static function () use ($pdo) {
    foreach (Dm::inbox($pdo, 3) as $m) {
        if ((int) $m['id'] === 9) {
            return $m['message'];
        }
    }
    return '';
});
ok(
    '⭐ le blob d\'un message privé entre deux autres comptes ne se lit pas en se mettant destinataire',
    $lu === null || !str_contains((string) $lu, 'Rendez-vous demain'),
    'clair obtenu = ' . var_export($lu, true)
);

// 3. le blob d'un profil, recopié dans le profil d'un autre compte
$blobProfil = (string) $pdo->query('SELECT ciphertext FROM profiles WHERE account_id = 3')->fetchColumn();
$pdo->prepare(
    'INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (1, ?, ?)
     ON CONFLICT(account_id) DO UPDATE SET ciphertext = excluded.ciphertext'
)->execute([$blobProfil, $t]);
ok(
    'le profil d\'un compte ne se lit pas depuis la ligne d\'un autre',
    (Profile::get($pdo, 1)['bio'] ?? '') !== 'carol la prudente',
    'bio rendue = ' . json_encode(Profile::get($pdo, 1)['bio'] ?? null)
);

echo "\n# Et le wrapper refuse de chiffrer sans contexte\n";

$sansArgument = clairOuRefus(static function () {
    // @phpstan-ignore-next-line — l'appel est volontairement invalide : c'est ce que le cas mesure
    return DataGuard::encrypt('texte');
});
ok(
    'appeler encrypt() sans second argument est une erreur, pas un chiffré valide',
    $sansArgument === null,
    'a rendu un blob : ' . substr((string) $sansArgument, 0, 24)
);

// ⚠️ Le cas ci-dessus mesure la SIGNATURE, pas la garde : PHP lève avant d'entrer
// dans le corps, donc il resterait vert si la garde disparaissait. C'est un contexte
// qui ne lie à RIEN — `Aad::none()`, que la bibliothèque accepte — qui l'éprouve.
$contexteVide = clairOuRefus(static fn() => DataGuard::encrypt('texte', Aad::none()));
ok(
    '⭐ et un contexte qui ne lie à rien est refusé — la garde, pas la signature',
    $contexteVide === null,
    'a rendu un blob : ' . substr((string) $contexteVide, 0, 24)
);

echo "\n# Les blobs écrits avant l'AAD restent lisibles — sinon la migration perd des données\n";

// Un blob d'avant la liaison au contexte, fabriqué comme le code d'alors l'écrivait —
// format v2, sans donnée associée —, la clé lue par réflexion pour que la production
// n'ait pas à exposer de méthode d'héritage.
$cle = (new ReflectionMethod(DataGuard::class, 'cle'));
$cle->setAccessible(true);
$cleDmBanc = $cle->invoke(null, '/my-self-lab/dm');
$nuDe = static fn(string $clair): string => blobV2($clair, $cleDmBanc, '')->toBase64();
$ancien = $nuDe('message d\'avant la migration');
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (20, 1, 2, ?, ?)')
    ->execute([$ancien, $t]);
// Un profil nu, de même : `Profile::save()` n'en produit plus, il se pose comme le
// code d'avant l'écrivait — sans quoi la boucle `profiles` de la passe pourrait être
// supprimée sans qu'un seul cas rougisse (mesuré : elle l'a été, 15/15 est resté vert).
$profilNu = $nuDe(json_encode(['bio' => 'dave avant la migration', 'lien' => ''], JSON_UNESCAPED_UNICODE));
$compte(4, 'dave');
$pdo->prepare('INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (4, ?, ?)')->execute([$profilNu, $t]);
$relu = clairOuRefus(static function () use ($pdo) {
    foreach (Dm::inbox($pdo, 2) as $m) {
        if ((int) $m['id'] === 20) {
            return $m['message'];
        }
    }
    return '';
});
ok(
    'un blob au format d\'avant se lit encore, pour que la migration puisse le relire',
    $relu === 'message d\'avant la migration',
    'clair obtenu = ' . var_export($relu, true)
);

echo "\n# Et la migration relie ce qui restait nu — le script lui-même, pas une copie de sa logique\n";

$migrer = static function (string ...$args): array {
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/migrer-aad.php');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    exec($cmd . ' 2>&1', $sortie, $rc);

    return [implode("\n", $sortie), $rc];
};

$empreinteAvant = sha256_blob($pdo, 20);
[$sortie, $rc] = $migrer('--a-blanc');
ok(
    '--a-blanc compte sans rien écrire',
    $rc === 0 && sha256_blob($pdo, 20) === $empreinteAvant && str_contains($sortie, '[à blanc]'),
    trim($sortie)
);

[$sortie, $rc] = $migrer();
ok('la migration passe et se dit', $rc === 0 && str_contains($sortie, '2 blob'), trim($sortie));
ok('le blob migré a changé', sha256_blob($pdo, 20) !== $empreinteAvant);
ok(
    'la passe relie aussi un profil nu',
    DataGuard::estLie((string) $pdo->query('SELECT ciphertext FROM profiles WHERE account_id = 4')->fetchColumn())
);
ok(
    '⭐ et le profil migré se lit par son titulaire, pas par un autre',
    (Profile::get($pdo, 4)['bio'] ?? '') === 'dave avant la migration'
        && clairOuRefus(static fn() => DataGuard::decrypt(
            (string) $pdo->query('SELECT ciphertext FROM profiles WHERE account_id = 4')->fetchColumn(),
            DataGuard::contexteProfil($pdo, 3)
        )) === null
);

$encoreLisible = clairOuRefus(static function () use ($pdo) {
    foreach (Dm::inbox($pdo, 2) as $m) {
        if ((int) $m['id'] === 20) {
            return $m['message'];
        }
    }
    return '';
});
ok(
    'le message migré se lit toujours par son destinataire',
    $encoreLisible === 'message d\'avant la migration',
    'clair = ' . var_export($encoreLisible, true)
);

$blobMigre = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 20')->fetchColumn();
ok(
    '⭐ et il n\'est plus déplaçable : présenté sous un autre contexte, il est refusé',
    clairOuRefus(static fn() => DataGuard::decrypt($blobMigre, DataGuard::contexteDm($pdo, 1, 3))) === null
);

[$sortie, $rc] = $migrer();
ok(
    'relancée, la migration ne fait rien et le dit',
    $rc === 0 && str_contains($sortie, '0 blob') && str_contains($sortie, '0 sans contexte'),
    trim($sortie)
);

echo "\n# Finie, la passe ferme la lecture sans contexte — sinon un vieux dump rouvre tout\n";

// `$ancien` est ce qu'un dump d'avant la passe aurait gardé de la ligne 20. Replanté
// dans une conversation où carol est destinataire, il se lisait sans contexte, puis
// la passe suivante le scellait sous `dm:1:3` comme s'il y était né. Trouvé par la
// cinquième passe d'audit.
ok("la passe finie l'annonce", str_contains($sortie, 'lecture sans contexte fermée'), trim($sortie));
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (21, 1, 3, ?, ?)')
    ->execute([$ancien, $t]);
$replante = clairOuRefus(static function () use ($pdo) {
    foreach (Dm::inbox($pdo, 3) as $m) {
        if ((int) $m['id'] === 21) {
            return $m['message'];
        }
    }
    return '';
});
ok(
    '⭐ un blob nu gardé d\'avant la passe ne se relit plus, où qu\'on le replante',
    $replante !== 'message d\'avant la migration'
        && clairOuRefus(static fn() => DataGuard::decrypt($ancien, DataGuard::contexteDm($pdo, 1, 3))) === null,
    'clair obtenu = ' . var_export($replante, true)
);
[$sortie, $rc] = $migrer();
$ligne21 = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 21')->fetchColumn();
ok(
    '⭐ et la passe suivante ne le scelle pas pour qui l\'a planté : elle le nomme',
    $ligne21 === $ancien && str_contains($sortie, 'dm #21') && $rc !== 0,
    'rc=' . $rc . ' · ' . trim($sortie)
);
$pdo->exec('DELETE FROM dm WHERE id = 21');

echo "\n# Ce que la passe ne peut PAS réparer, et qu'elle doit refuser\n";

// Un blob nu recopié avant la passe : elle le scellerait sous la ligne où il se
// trouve, donc elle le certifierait lisible par qui l'a déplacé. Elle doit s'en
// abstenir et le nommer. Trouvé par l'audit du lot, pas par ce banc.
$nu = $nuDe('le secret d\'alice pour bob');
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (30, 1, 2, ?, ?)')
    ->execute([$nu, $t]);
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (31, 1, 3, ?, ?)')
    ->execute([$nu, $t]);

[$sortie, $rc] = $migrer();
ok(
    '⭐ un blob recopié est laissé nu et nommé, au lieu d\'être scellé pour qui l\'a déplacé',
    str_contains($sortie, '2 recopié(s)') && str_contains($sortie, '2 sans contexte'),
    trim($sortie)
);
ok(
    'et la passe ne s\'annonce pas finie tant qu\'un blob reste nu',
    $rc !== 0 && !str_contains($sortie, '✅'),
    'rc=' . $rc . ' · ' . trim($sortie)
);

echo "\n# Les deux contournements de la détection, trouvés par l'audit du lot\n";

// ⚠️ Ce qui se mesure ici est que la passe **ne scelle pas** une copie, non que le
// tiers n'en lise rien : un blob resté nu se déchiffre sans contexte, c'est la
// tolérance de lecture, et seule une migration complète la referme. Le défaut que
// ces deux cas gardent est la certification — un blob scellé sous la ligne du
// tiers ne sera plus jamais revu par aucune passe.

// Sur une instance déjà scellée, la passe laisse tout blob nu en place et le nomme
// « apparu après la fermeture » : ces deux cas y restaient verts quand la détection
// cédait (mesuré par la neuvième passe d'audit). Ils se jouent donc fenêtre ouverte, là
// où une copie manquée serait SCELLÉE, et ils exigent la cause, pas le seul numéro.
$temoin = DataGuard::fichiersDeLaCle()[2];
rename($temoin, $temoin . '.de-cote');

// (a) Le base64 strict de PHP tolère les blancs : une copie suivie d'un « \n » est
//     une autre CHAÎNE qui décode aux MÊMES octets. Comparer les chaînes la laissait
//     passer, et la passe scellait alors la copie pour qui l'avait déplacée.
$nu2 = $nuDe('le second secret d\'alice');
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (40, 1, 2, ?, ?)')
    ->execute([$nu2, $t]);
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (41, 1, 3, ?, ?)')
    ->execute([$nu2 . "\n", $t]);

[$sortie, $rc] = $migrer();
$ligne41 = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 41')->fetchColumn();
ok(
    '⭐ une copie retouchée d\'un seul caractère est reconnue : la charge compte, pas la chaîne',
    !DataGuard::estLie($ligne41) && str_contains($sortie, 'dm #41 : cette charge chiffrée est présente à 2 endroits'),
    'ligne 41 = ' . substr($ligne41, 0, 12) . '… · ' . trim($sortie)
);

// (b) Donner à la ligne LÉGITIME l'en-tête d'un blob lié (`SDG3.` au lieu de `SDG2.`)
//     la sort de l'ensemble des nus : la copie s'y retrouve seule, donc unique, donc
//     scellée pour le tiers. La détection prend donc pour univers TOUTES les lignes,
//     liées comprises.
$nu3 = $nuDe('le troisième secret d\'alice');
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (50, 1, 2, ?, ?)')
    ->execute([EncryptedBlob::PREFIX_V3 . substr($nu3, strlen(EncryptedBlob::PREFIX_V2)), $t]);
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (51, 1, 3, ?, ?)')
    ->execute([$nu3, $t]);

[$sortie, $rc] = $migrer();
$ligne51 = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 51')->fetchColumn();
ok(
    '⭐ un en-tête de blob lié posé à la main sur la ligne légitime n\'isole pas la copie',
    !DataGuard::estLie($ligne51) && str_contains($sortie, 'dm #51 : cette charge chiffrée est présente à 2 endroits'),
    'ligne 51 = ' . substr($ligne51, 0, 12) . '… · ' . trim($sortie)
);
rename($temoin . '.de-cote', $temoin);

echo "\n# Une clé qui manque se dit, elle ne se déguise pas en donnée abîmée\n";

// Le secret absent était le pire des trois cas : `SecretInstance::lire()` en POSE un
// neuf, donc la lecture réussissait avec la mauvaise clé et chaque message
// s'affichait « illisible ». L'exploitant cherchait un attaquant.
$secret = $dir . '/.blindkey';
$copieSecret = file_get_contents($secret);
unlink($secret);
$leve = false;
try {
    Dm::inbox($pdo, 2);
} catch (\Throwable $e) {
    $leve = str_contains($e->getMessage(), '.blindkey');
}
ok(
    '⭐ secret absent : la boîte refuse de servir au lieu de rendre « illisible »',
    $leve && !is_file($secret),
    'levée = ' . var_export($leve, true) . ' · secret recréé = ' . var_export(is_file($secret), true)
);
file_put_contents($secret, $copieSecret);
ok('et le secret remis, la boîte se lit de nouveau', count(Dm::inbox($pdo, 2)) > 0);

echo "\n# Un profil qu'on ne sait pas lire ne s'écrase pas\n";

// Sans cette garde, la page d'édition pré-remplissait son formulaire avec des champs
// vides, le titulaire retapait, et `save()` écrasait le seul chiffré existant.
$pdo->prepare('INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (2, ?, ?)
               ON CONFLICT(account_id) DO UPDATE SET ciphertext = excluded.ciphertext')
    ->execute([EncryptedBlob::PREFIX_V3 . base64_encode(random_bytes(40)), $t]);
$avantProfil = (string) $pdo->query('SELECT ciphertext FROM profiles WHERE account_id = 2')->fetchColumn();
ok(
    'un profil illisible se dit illisible, et non vide',
    Profile::illisible($pdo, 2) === true && Profile::get($pdo, 2)['bio'] === ''
);
ok(
    'et l\'état se demande à part : il n\'habite pas la carte des champs',
    !array_key_exists('illisible', Profile::get($pdo, 2))
        && Profile::illisible($pdo, 3) === false,
    'clés rendues = ' . implode(',', array_keys(Profile::get($pdo, 2)))
);
$r = Profile::save($pdo, 2, ['bio' => 'retapé par son titulaire', 'lien' => '']);
ok(
    '⭐ et l\'enregistrement est refusé : le chiffré d\'origine survit',
    ($r['ok'] ?? true) === false
        && ($r['error'] ?? '') === 'profil_illisible'
        && (string) $pdo->query('SELECT ciphertext FROM profiles WHERE account_id = 2')->fetchColumn() === $avantProfil,
    json_encode($r, JSON_UNESCAPED_UNICODE)
);

ok(
    'la console d\'arbitrage dit le profil illisible, au lieu de l\'afficher vide',
    (Admin::profile($pdo, 2)['profil_illisible'] ?? null) === true
        && (Admin::profile($pdo, 3)['profil_illisible'] ?? null) === false
);
ok(
    'un blob mal formé ne porte pas sa liaison — il ne fait pas lever la question',
    !DataGuard::estLie('pas un blob') && !DataGuard::estLie(EncryptedBlob::PREFIX_V3 . '!!')
        && DataGuard::estLie($avantProfil) && !DataGuard::estLie($ancien)
);

ok(
    '⭐ et il existe une SORTIE : le titulaire acte la perte explicitement',
    (Profile::save($pdo, 2, ['bio' => 'je repars de zéro', 'lien' => ''], true)['ok'] ?? false) === true
        && Profile::illisible($pdo, 2) === false
        && (Profile::get($pdo, 2)['bio'] ?? '') === 'je repars de zéro'
);

echo "\n# Une requête neuve, cache vide — comme une requête HTTP\n";

// Les deux cas suivants tournent en sous-processus : la clé dérivée est mémoïsée
// dans le processus, et un cache plein masquerait exactement ce qu'on mesure.
$sonde = $dir . '/sonde.php';
file_put_contents($sonde, '<?php
require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/db.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/dataguard.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/moderate.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/dm.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/profile.php', true) . ';
use Pierroons\MySelfLab\{Db, DataGuard, DM};
$geste = $argv[1];
try {
    if ($geste === "inbox")  { DM::inbox(Db::pdo(), 1); echo "OK"; }
    if ($geste === "sent")   { DM::sent(Db::pdo(), 1);  echo "OK"; }
    if ($geste === "hmac")   { DataGuard::hmac("x", "drapeau"); echo "OK"; }
    if ($geste === "chiffrer") { DataGuard::encrypt("premier message", DataGuard::contexteDm(Db::pdo(), 1, 2)); echo "OK"; }
    if ($geste === "profil") { Pierroons\MySelfLab\Profile::get(Db::pdo(), 1); echo "OK"; }
} catch (\Throwable $e) { echo "LEVE:" . $e->getMessage(); }
');
// Une levée ne prouve la garde que si c'est ELLE qui lève : une sonde qui rendait
// « LEVE » pour n'importe quelle exception restait verte sous un `hmac()` cassé.
$surSecret = static fn(string $r): bool => str_starts_with($r, 'LEVE:')
    && str_contains($r, '.blindkey') && str_contains($r, 'absent');
$jouer = static function (string $base, string $cle, string $geste): string {
    $cmd = sprintf('LAB_DB_PATH=%s LAB_BLINDKEY_PATH=%s %s %s %s 2>/dev/null',
        escapeshellarg($base), escapeshellarg($cle), escapeshellcmd(PHP_BINARY), escapeshellarg($GLOBALS['sonde']), escapeshellarg($geste));
    return trim((string) shell_exec($cmd));
};

// (a) L'installation neuve : aucune donnée chiffrée, aucun secret. Lever ici rendait
//     /messages.php en 500 dès le premier clic d'un membre fraîchement inscrit.
$neuve = $dir . '/neuve.db';
$cleNeuve = $dir . '/.blindkey-neuve';
$pdoNeuve = new PDO('sqlite:' . $neuve);
$pdoNeuve->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$pdoNeuve->exec("INSERT INTO lab_generation (id, valeur, nee_le) VALUES (1, '" . bin2hex(random_bytes(16)) . "', $t)");
$pdoNeuve->exec("INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (1, 'alice', 'h', 'h', 'h', $t), (2, 'bob', 'h', 'h', 'h', $t)");
$pdoNeuve = null;
ok(
    'une instance neuve, sans rien de chiffré, ouvre sa messagerie sans secret',
    $jouer($neuve, $cleNeuve, 'inbox') === 'OK' && $jouer($neuve, $cleNeuve, 'sent') === 'OK'
);

// ⚠️ Le cas ci-dessus ne mesure pas la CONDITION de la garde : sur une base vide,
// `inbox()` ne l'atteint pas du tout. Il resterait vert si la garde redevenait
// inconditionnelle. C'est la première écriture qui la traverse, et c'est elle qui
// doit réussir — et poser le secret — sur une instance qui n'a encore rien chiffré.
ok(
    '⭐ et sa première écriture pose le secret : la garde laisse s\'installer ce qui n\'a rien à perdre',
    $jouer($neuve, $cleNeuve, 'chiffrer') === 'OK' && is_file($cleNeuve),
    'secret posé = ' . var_export(is_file($cleNeuve), true)
);
// Une clé qui naît ici n'a jamais chiffré sans contexte : sans ce témoin posé à sa
// naissance, une instance née sous la 0.7.0 gardait la lecture sans contexte ouverte pour
// toujours, aucune passe n'ayant de blob nu à relier (mesuré par la neuvième passe d'audit).
ok(
    '⭐ une clé qui naît ici ferme d\'emblée la lecture sans contexte',
    is_file($cleNeuve . '.scelle'),
    'témoin = ' . var_export(is_file($cleNeuve . '.scelle'), true)
);

// (b) Une base qui porte des chiffrés, un secret perdu, et le chemin d'une route
//     ANONYME — `hmac()`, appelé par le dépôt et le suivi des rapports et par la
//     soumission de drapeau. Il posait une clé neuve, et la case « repartir d'un
//     profil neuf » devenait ensuite l'outil de la destruction.
$cleAbsente = $dir . '/.blindkey-perdue';
ok(
    '⭐ une route anonyme ne pose pas de clé neuve sur une base qui porte des chiffrés',
    $surSecret($jouer(getenv('LAB_DB_PATH'), $cleAbsente, 'hmac')) && !is_file($cleAbsente),
    'secret posé = ' . var_export(is_file($cleAbsente), true)
);

echo "\n# Chaque donnée qui dépend du secret le garde, seule — une base où elle est seule\n";

// Sur la base du banc, messages et profils sont présents ENSEMBLE : retirer l'un des
// deux de ce que le secret déclare, ou l'appel à `exigerCle()` d'un lecteur, laissait
// tout vert (mesuré par la cinquième passe d'audit, six mutations). Une base par sorte
// de donnée, un secret absent, et le lecteur de CETTE donnée.
$baseAvec = static function (string $nom, string $sql) use ($dir, $t): string {
    $f = $dir . '/' . $nom . '.db';
    $p = new PDO('sqlite:' . $f);
    $p->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    $p->exec("INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at)
              VALUES (1, 'alice', 'h', 'h', 'h', $t), (2, 'bob', 'h', 'h', 'h', $t)");
    // Le numéro qu'une base reçoit à sa naissance : sans lui, un blob lié y désigne
    // une base qui a perdu le sien, et l'ouverture refuse d'en tirer un.
    $p->exec("INSERT INTO lab_generation (id, valeur, nee_le) VALUES (1, '" . bin2hex(random_bytes(16)) . "', $t)");
    $p->exec($sql);

    return $f;
};
$seulProfil  = $baseAvec('seul-profil', "INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (1, 'SDG3.xx', $t)");
$seulMessage = $baseAvec('seul-message', "INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (1, 2, 'SDG3.xx', $t)");
$seulRapport = $baseAvec('seul-rapport', "INSERT INTO redteam_reports (handle, ciphertext, created_at) VALUES ('h', '-----BEGIN PGP-----', $t)");
foreach ([
    'un profil seul : sa lecture refuse, elle ne rend pas un profil vide'       => [$seulProfil, 'profil'],
    'un message seul : la liste des envois refuse, elle ne rend pas « illisible »' => [$seulMessage, 'sent'],
    'un rapport red team seul : son jeton de suivi en dérive, la route refuse' => [$seulRapport, 'hmac'],
] as $quoi => [$base, $geste]) {
    $cleSeule = $dir . '/.blindkey-' . $geste;
    $rendu = $jouer($base, $cleSeule, $geste);
    ok('⭐ ' . $quoi, $surSecret($rendu) && !is_file($cleSeule), 'rendu = ' . $rendu . ' · secret posé = ' . var_export(is_file($cleSeule), true));
}

echo "\n# La passe aussi : un secret absent se dit, il ne se compte pas en lignes illisibles\n";

$migrerSur = static function (string $base, string $cle, string ...$args): array {
    $cmd = sprintf('LAB_DB_PATH=%s LAB_BLINDKEY_PATH=%s %s %s',
        escapeshellarg($base), escapeshellarg($cle), escapeshellcmd(PHP_BINARY), escapeshellarg(__DIR__ . '/../tools/migrer-aad.php'));
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    exec($cmd . ' 2>&1', $sortie, $rc);

    return [implode("\n", $sortie), $rc];
};
[$sortie, $rc] = $migrerSur($seulMessage, $dir . '/.blindkey-passe');
ok(
    '⭐ secret absent : la passe s\'arrête sur la cause, sans bilan ni secret posé',
    $rc !== 0 && str_contains($sortie, '.blindkey') && !str_contains($sortie, 'illisible(s)') && !is_file($dir . '/.blindkey-passe'),
    'rc=' . $rc . ' · ' . substr(trim($sortie), 0, 160)
);

echo "\n# Une cible qui n'est pas une base du lab n'en devient pas une\n";

// `Db::pdo()` pose le schéma sur toute base qui ne l'a pas : un fichier vide ou une
// base étrangère ressortaient avec 24 tables et un « ✅ ».
$vide = $dir . '/vide.db';
touch($vide);
[$sortie, $rc] = $migrerSur($vide, $dir . '/.blindkey-vide');
clearstatcache();
ok('un fichier vide est refusé, et reste vide', $rc === 2 && filesize($vide) === 0, 'rc=' . $rc . ' · ' . trim($sortie));
$etrangere = $dir . '/etrangere.db';
(new PDO('sqlite:' . $etrangere))->exec('CREATE TABLE notes (texte TEXT)');
[$sortie, $rc] = $migrerSur($etrangere, $dir . '/.blindkey-etrangere');
$tablesApres = (int) (new PDO('sqlite:' . $etrangere))->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
ok('⭐ une base étrangère est refusée, et garde ses seules tables', $rc === 2 && $tablesApres === 1, 'rc=' . $rc . ' · tables = ' . $tablesApres);
// Sous une clé qui n'est pas celle de la base, la passe posait le témoin de fermeture à
// côté de la mauvaise, et annonçait fermée une lecture restée ouverte sur le site.
// Une base qui ne porte qu'un message déjà lié, sans copie : sous la mauvaise clé, rien ne
// retenait la passe d'aller au bout et de poser son témoin.
$sousAutreCle = $dir . '/passe-autre-cle.db';
copy(getenv('LAB_DB_PATH'), $sousAutreCle);
$pAutre = new PDO('sqlite:' . $sousAutreCle);
$pAutre->exec('DELETE FROM dm; DELETE FROM profiles');
$pAutre->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (1, 2, ?, ?)')
    ->execute([DataGuard::encrypt('message lié de la base', DataGuard::contexteDm($pAutre, 1, 2)), $t]);
$pAutre = null;
$autreCle = $dir . '/.blindkey-autre';
file_put_contents($autreCle, bin2hex(random_bytes(48)));
[$sortie, $rc] = $migrerSur($sousAutreCle, $autreCle);
ok(
    '⭐ la passe sous une clé qui n\'est pas celle de la base refuse, et ne pose aucun témoin',
    $rc === 2 && str_contains($sortie, 'Passe refusée') && !is_file($autreCle . '.scelle'),
    'rc=' . $rc . ' · ' . substr(trim($sortie), 0, 200)
);

echo "\n# Une copie écrite PENDANT la passe n'est pas scellée pour qui l'a faite\n";

// (a) L'inventaire des copies et les lignes qu'il juge doivent être la même base. Sans
// verrou, une copie écrite après l'inventaire n'y figurait pas, et la passe la
// scellait pour qui l'avait faite — mesuré par la cinquième passe d'audit. La fenêtre se rend mesurable ici : une passe longue sur
// les messages, pendant laquelle un copieur range le blob d'un message dans des
// PROFILS, que la passe traite après. ⚠️ Le copieur n'écrit qu'une fois la passe
// commencée — dès qu'un message scellé est VISIBLE : une copie écrite avant
// l'inventaire y figure, et toutes les suivantes sont alors reconnues ; le banc
// restait vert sans verrou (mesuré, trois essais). Une instance à part, qui n'a
// jamais passé, pour que ce soit le verrou qui soit mesuré et non le témoin.
$course = $baseAvec('course', 'SELECT 1');
$cleCourse = $dir . '/.blindkey-course';
file_put_contents($cleCourse, bin2hex(random_bytes(48)));
$cleDm = Primitives::deriveFromMemorized(trim((string) file_get_contents($cleCourse)), '/my-self-lab/dm');
$nuCourse = blobV2('le secret que le copieur veut lire', $cleDm, '')->toBase64();
$pdoCourse = new PDO('sqlite:' . $course);
$pdoCourse->beginTransaction();
$ins = $pdoCourse->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (1, 2, ?, ?)');
$ins->execute([$nuCourse, $t]);
for ($i = 0; $i < 1500; $i++) {
    $ins->execute([blobV2('message ' . $i, $cleDm, '')->toBase64(), $t]);
}
$pdoCourse->commit();
$copieur = $dir . '/copieur.php';
file_put_contents($copieur, '<?php
$p = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 120]);
$fin = microtime(true) + (float) $argv[3];
while (microtime(true) < $fin && $p->query("SELECT 1 FROM dm WHERE ciphertext LIKE \'SDG3.%\' LIMIT 1")->fetchColumn() === false) {
    usleep(1000);
}
for ($n = 0; microtime(true) < $fin; $n++) {
    $p->prepare("INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (?, ?, 0)")->execute([1000 + $n, $argv[2]]);
    usleep(5000);
}
echo $n;
');
$tube = proc_open([PHP_BINARY, $copieur, $course, $nuCourse, '6'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $flux);
[$sortieCourse] = $migrerSur($course, $cleCourse);
$copiesEcrites = (int) stream_get_contents($flux[1]);
proc_close($tube);
$scellees = (int) $pdoCourse->query("SELECT COUNT(*) FROM profiles WHERE account_id >= 1000 AND ciphertext LIKE 'SDG3.%'")->fetchColumn();
ok(
    '⭐ aucune copie écrite pendant la passe n\'en sort scellée pour qui l\'a faite',
    $copiesEcrites > 0 && $scellees === 0,
    sprintf('%d copie(s) écrite(s), %d scellée(s) · %s', $copiesEcrites, $scellees, substr(trim($sortieCourse), -160))
);

// (b) Et c'est un verrou d'ÉCRITURE : sous un `BEGIN` différé, la passe ne certifie rien,
// elle tombe sur « database is locked » dès que le site écrit en même temps (mesuré par
// la neuvième passe d'audit) — ce que (a) ne voit pas, son copieur n'écrivant qu'après
// le `COMMIT`. Ici le copieur écrit sans relâche, dès avant la passe et jusqu'après
// elle : la passe doit aller au bout, et n'en sceller aucune.
$course = $baseAvec('course-continue', 'SELECT 1');
$cleCourse = $dir . '/.blindkey-course-continue';
file_put_contents($cleCourse, bin2hex(random_bytes(48)));
$cleDm = Primitives::deriveFromMemorized(trim((string) file_get_contents($cleCourse)), '/my-self-lab/dm');
$nuCourse = blobV2('le secret que le copieur veut lire', $cleDm, '')->toBase64();
$pdoCourse = new PDO('sqlite:' . $course);
$pdoCourse->beginTransaction();
$ins = $pdoCourse->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (1, 2, ?, ?)');
$ins->execute([$nuCourse, $t]);
for ($i = 0; $i < 1500; $i++) {
    $ins->execute([blobV2('message ' . $i, $cleDm, '')->toBase64(), $t]);
}
$pdoCourse->commit();
$copieur = $dir . '/copieur-continu.php';
$pret = $dir . '/copieur.pret';
file_put_contents($copieur, '<?php
$p = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 120]);
$fin = microtime(true) + (float) $argv[3];
for ($n = 0; microtime(true) < $fin; $n++) {
    $p->prepare("INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (?, ?, 0)")->execute([1000 + $n, $argv[2]]);
    $n === 0 && touch($argv[4]);
    usleep(2000);
}
echo $n;
');
$tube = proc_open([PHP_BINARY, $copieur, $course, $nuCourse, '4', $pret], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $flux);
for ($i = 0; $i < 500 && !is_file($pret); $i++) {
    usleep(10000);
}
[$sortieCourse, $rcCourse] = $migrerSur($course, $cleCourse);
$copiesEcrites = (int) stream_get_contents($flux[1]);
proc_close($tube);
$scellees = (int) $pdoCourse->query("SELECT COUNT(*) FROM profiles WHERE account_id >= 1000 AND ciphertext LIKE 'SDG3.%'")->fetchColumn();
ok(
    '⭐ sous un copieur qui écrit pendant toute la passe, la passe va au bout et ne scelle aucune copie',
    is_file($pret) && $copiesEcrites > 0 && $scellees === 0 && $rcCourse === 1 && str_contains($sortieCourse, 'recopié'),
    sprintf('%d copie(s) écrite(s), %d scellée(s), rc=%d · %s', $copiesEcrites, $scellees, $rcCourse, substr(trim($sortieCourse), -160))
);

echo "\n# Un blob d'une base ne s'ouvre pas dans la suivante — même clé, mêmes numéros\n";

// Les identifiants de compte ne sont uniques que dans une base : recréée, elle repart
// de 1, et la clé, qui vit à côté, reste. Le message d'Alice (1) à Bob (2), gardé dans
// un dump puis replanté dans la base suivante, s'ouvrait pour son nouveau n° 2 (mesuré
// par la septième passe d'audit, après un `reset-db`). Le numéro de la base l'empêche.
$baseA = getenv('LAB_DB_PATH');
$baseB = $dir . '/suivante.db';
$restauree = $dir . '/restauree.db';
copy($baseA, $restauree);
Db::fermer();
// 🔑 Une base ne naît plus sous une clé qui a déjà servi : effacée à la main, elle se
// recréait sans un mot sous la clé de l'ancienne (mesuré par la neuvième passe d'audit).
putenv('LAB_DB_PATH=' . $baseB);
$refusNeuve = '';
try {
    Db::pdo();
} catch (\RuntimeException $e) {
    $refusNeuve = $e->getMessage();
}
ok(
    '⭐ une base absente sous une clé qui a servi n\'est pas recréée, et le refus le dit',
    str_contains($refusNeuve, 'Base du lab absente') && !is_file($baseB),
    $refusNeuve === '' ? 'base créée' : $refusNeuve
);
// Un fichier présent mais sans table — vidé, tronqué à zéro, pré-créé par l'exploitation —
// est une base neuve : SQLite l'ouvre sans un mot, et le schéma s'y posait sous la clé.
$refusVide = static function (string $f): string {
    Db::fermer();
    putenv('LAB_DB_PATH=' . $f);
    try {
        Db::pdo();
        return '';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
};
$aZero = $dir . '/videe.db';
file_put_contents($aZero, '');
$refus = $refusVide($aZero);
clearstatcache();
ok(
    '⭐ une base vidée à zéro octet sous une clé qui a servi n\'est pas remplie, et le refus le dit',
    str_contains($refus, 'absente ou vide') && filesize($aZero) === 0,
    $refus === '' ? 'schéma posé' : $refus
);
$sansTable = $dir . '/sans-table.db';
(new PDO('sqlite:' . $sansTable))->exec('PRAGMA user_version = 7');
clearstatcache();
$tailleSansTable = filesize($sansTable);
$refus = $refusVide($sansTable);
ok(
    'un fichier SQLite sans table, non vide, est refusé de même',
    $tailleSansTable > 0 && str_contains($refus, 'absente ou vide')
        && (int) (new PDO('sqlite:' . $sansTable))->query("SELECT COUNT(*) FROM sqlite_master")->fetchColumn() === 0,
    'taille = ' . $tailleSansTable . ' · ' . ($refus === '' ? 'schéma posé' : $refus)
);
// Une autre base SQLite, non vide, restaurée au chemin de celle du lab : elle a des tables,
// mais pas la sienne.
$autreBase = $dir . '/autre-base.db';
(new PDO('sqlite:' . $autreBase))->exec("CREATE TABLE notes (texte TEXT); INSERT INTO notes VALUES ('note')");
$refus = $refusVide($autreBase);
ok(
    '⭐ une autre base SQLite, non vide, au chemin de celle du lab n\'est pas prise pour elle',
    str_contains($refus, 'absente ou vide')
        && (new PDO('sqlite:' . $autreBase))->query("SELECT group_concat(name) FROM sqlite_master WHERE type = 'table'")->fetchColumn() === 'notes',
    $refus === '' ? 'schéma posé' : $refus
);
// Toutes les tables supprimées, le fichier gardé : SQLite y laisse `sqlite_sequence`, qui
// faisait passer la base pour existante.
$sansLab = $dir . '/tables-supprimees.db';
$pS = new PDO('sqlite:' . $sansLab);
$pS->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$pS->exec("INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES ('alice', 'h', 'h', 'h', $t)");
$pS->exec('PRAGMA foreign_keys = OFF');
foreach ($pS->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\_%' ESCAPE '\'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pS->exec('DROP TABLE "' . $table . '"');
}
$restantes = $pS->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
$pS = null;
$refus = $refusVide($sansLab);
ok(
    '⭐ une base dont on a supprimé toutes les tables n\'est pas remplie sous la clé qui a servi',
    $restantes === ['sqlite_sequence'] && str_contains($refus, 'absente ou vide'),
    'restantes = ' . json_encode($restantes) . ' · ' . ($refus === '' ? 'schéma posé' : $refus)
);
// La clé retirée à la main, son témoin resté : c'est encore une clé qui a servi.
$cleDuBanc = getenv('LAB_BLINDKEY_PATH');
$temoinSeul = $dir . '/temoin-seul/.blindkey';
mkdir(dirname($temoinSeul), 0700);
touch($temoinSeul . '.existe');
putenv('LAB_BLINDKEY_PATH=' . $temoinSeul);
$refus = $refusVide($dir . '/sous-temoin.db');
putenv('LAB_BLINDKEY_PATH=' . $cleDuBanc);
ok(
    '⭐ la clé retirée, son témoin resté : la base absente n\'est pas recréée non plus',
    str_contains($refus, 'absente ou vide') && !is_file($dir . '/sous-temoin.db') && !is_file($temoinSeul),
    $refus === '' ? 'base créée' : $refus
);
Db::fermer();
putenv('LAB_DB_PATH=' . $baseB);
// La base suivante se bâtit donc à la main, comme la bâtirait qui écrit sur le disque :
// le numéro reste ce qui l'empêche de rouvrir les blobs de l'ancienne.
$pb = new PDO('sqlite:' . $baseB);
$pb->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$pb->exec("INSERT INTO lab_generation (id, valeur, nee_le) VALUES (1, '" . bin2hex(random_bytes(16)) . "', $t)");
$pb = null;
$pdoB = Db::pdo();
$pdoB->exec("INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at)
             VALUES (1, 'nouvelle1', 'h', 'h', 'h', $t), (2, 'eve', 'h', 'h', 'h', $t)");
$pdoB->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (1, 1, 2, ?, ?)')
    ->execute([$blobDm, $t]);
$luParEve = clairOuRefus(static fn() => Dm::inbox($pdoB, 2)[0]['message'] ?? '');
ok(
    '⭐ le message d\'une base effacée, replanté dans la suivante, ne s\'ouvre pas pour son nouveau n° 2',
    $luParEve !== null && !str_contains((string) $luParEve, 'Rendez-vous demain'),
    'eve lit : ' . var_export($luParEve, true)
);
$pdoB->exec("INSERT INTO accounts (id, username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (3, 'mallory', 'h', 'h', 'h', $t)");
$pdoB->prepare('INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (3, ?, ?)')->execute([$blobProfil, $t]);
$profilB = Profile::lire($pdoB, 3);
ok(
    '⭐ le profil d\'une base effacée, replanté dans la suivante, ne s\'ouvre pas pour son nouveau n° 3',
    $profilB['illisible'] === true && ($profilB['champs']['bio'] ?? '') === '',
    'mallory lit : ' . json_encode($profilB, JSON_UNESCAPED_UNICODE)
);
$rapportB = (int) $pdoB->query("INSERT INTO redteam_reports (handle, ciphertext, created_at) VALUES ('h', 'x', " . ($t + 1) . ') RETURNING id')->fetchColumn();
$jetonB = Redteam::jetonSuivi($rapportB, DataGuard::graine($pdoB, 'redteam_reports', $rapportB));
Db::fermer();
putenv('LAB_DB_PATH=' . $restauree);
$pdoR = Db::pdo();
ok(
    'contre-témoin : une sauvegarde restaurée garde son numéro, et ses messages se lisent',
    array_filter(array_column(Dm::inbox($pdoR, 2), 'message'), static fn(string $m): bool => str_contains($m, 'Rendez-vous demain')) !== []
);
Db::fermer();
putenv('LAB_DB_PATH=' . $baseA);
$pdo = Db::pdo();

// Le jeton de suivi d'un rapport dérivait du seul numéro du rapport : le même défaut.
$naissance = Db::generationNeeLe();
$ancienRapport = (int) $pdo->query("INSERT INTO redteam_reports (handle, ciphertext, created_at) VALUES ('h', 'x', " . ($naissance - 60) . ') RETURNING id')->fetchColumn();
$neufRapport   = (int) $pdo->query("INSERT INTO redteam_reports (handle, ciphertext, created_at) VALUES ('h', 'x', " . ($naissance + 60) . ') RETURNING id')->fetchColumn();
$jetonSans = static fn(int $id): string => substr(DataGuard::hmac((string) $id, 'report-track'), 0, 32);
ok(
    'un jeton remis avant le numéro de base reste valable pour SON rapport',
    (Redteam::etat($pdo, $ancienRapport, $jetonSans($ancienRapport))['ok'] ?? false) === true
);
ok(
    '⭐ et pour aucun rapport déposé après — le jeton d\'aujourd\'hui porte le numéro de la base',
    (Redteam::etat($pdo, $neufRapport, $jetonSans($neufRapport))['ok'] ?? true) === false
        && (Redteam::etat($pdo, $neufRapport, Redteam::jetonSuivi($neufRapport, DataGuard::graine($pdo, 'redteam_reports', $neufRapport)))['ok'] ?? false) === true
);
// À la seconde même du numéro, la base le portait déjà : le rapport a reçu le jeton
// d'aujourd'hui, et seul ce qui précède strictement relève de l'ancien.
$bordRapport = (int) $pdo->query("INSERT INTO redteam_reports (handle, ciphertext, created_at) VALUES ('h', 'x', " . $naissance . ') RETURNING id')->fetchColumn();
ok(
    'un rapport déposé à la seconde du numéro n\'accepte pas le jeton sans lui',
    (Redteam::etat($pdo, $bordRapport, $jetonSans($bordRapport))['ok'] ?? true) === false
);
$memeNumero = $pdo->prepare('SELECT COUNT(*) FROM redteam_reports WHERE id = ?');
$memeNumero->execute([$rapportB]);
ok(
    '⭐ le jeton d\'un rapport de la base suivante n\'ouvre pas le rapport de même numéro ici',
    (int) $memeNumero->fetchColumn() === 1 && (Redteam::etat($pdo, $rapportB, $jetonB)['ok'] ?? true) === false,
    'rapport n° ' . $rapportB
);

echo "\n# Un numéro de base perdu ne se remplace pas en silence\n";

// Retiré à neuf chaque fois que la ligne manquait, il rendait illisibles tous les
// messages et profils sans un mot, et la passe se disait finie (mesuré par la huitième
// passe d'audit). Une copie de la base, dont on efface le numéro.
ok(
    'le préfixe qu\'ouvre la base pour reconnaître un blob lié est celui de la bibliothèque',
    Db::PREFIXE_BLOB_LIE === EncryptedBlob::PREFIX_V3
);
$perdu = $dir . '/perdu.db';
$memeNumero = null;   // un curseur resté ouvert tiendrait la base du banc en lecture
Db::fermer();
$baseDuBanc = getenv('LAB_DB_PATH');
/** Une copie de la base du banc, modifiée par `$sql` ; rend son chemin. */
$variante = static function (string $nom, string $sql) use ($dir, $baseDuBanc): string {
    $f = $dir . '/' . $nom . '.db';
    copy($baseDuBanc, $f);
    (new PDO('sqlite:' . $f))->exec($sql);

    return $f;
};
/** Ouvre une base comme le site, et rend combien de numéros elle porte ensuite. */
$numerosApresOuverture = static function (string $f): int {
    Db::fermer();
    putenv('LAB_DB_PATH=' . $f);
    Db::pdo();

    return (int) (new PDO('sqlite:' . $f))->query('SELECT COUNT(*) FROM lab_generation')->fetchColumn();
};
$sansTable = 'DROP TABLE lab_generation;';
$sansTrace = "DELETE FROM lab_migrations WHERE nom = 'numero-de-base';";

// Chaque témoin, seul : sous plusieurs à la fois, un témoin rendu aveugle restait couvert
// par un autre, et le banc vert (mesuré par la neuvième passe d'audit).
foreach ([
    'la table du numéro, présente avant l\'ouverture — une base qui ne porte que des rapports'
        => 'DELETE FROM lab_generation; ' . $sansTrace . ' DELETE FROM dm; DELETE FROM profiles;',
    'la trace de son tirage'           => $sansTable . ' DELETE FROM dm; DELETE FROM profiles;',
    'les messages scellés sous lui'    => $sansTable . $sansTrace . ' DELETE FROM profiles;',
    'les profils scellés sous lui'     => $sansTable . $sansTrace . ' DELETE FROM dm;',
] as $temoin => $sql) {
    $f = $variante('perdu-' . substr(md5($temoin), 0, 6), $sql);
    ok("⭐ numéro perdu, seul témoin restant — {$temoin} : pas de numéro neuf", $numerosApresOuverture($f) === 0);
}
$sansRien = $variante('perdu-sans-temoin', $sansTable . $sansTrace . ' DELETE FROM dm; DELETE FROM profiles;');
ok(
    'contre-témoin et limite déclarée : sans table, sans trace et sans blob lié, une base qui ne porte que des rapports reçoit un numéro neuf',
    $numerosApresOuverture($sansRien) === 1
);

// Le numéro absent, tout le reste en place : ce qui le lit refuse en le nommant.
$perdu = $variante('perdu', 'DELETE FROM lab_generation;');
$numerosApresOuverture($perdu);
$pdoP = Db::pdo();
ok(
    'le préfixe qu\'ouvre la base pour reconnaître un blob lié est celui de la bibliothèque',
    Db::PREFIXE_BLOB_LIE === EncryptedBlob::PREFIX_V3
);
$cause = static function (callable $lecture): string {
    try {
        return 'rendu : ' . json_encode($lecture(), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
$recuSansNumero = $cause(static fn() => Dm::inbox($pdoP, 2));
ok(
    '⭐ la boîte de réception refuse en nommant le numéro, elle ne rend pas des messages illisibles',
    str_contains($recuSansNumero, 'Numéro de base absent'),
    $recuSansNumero
);
$profilSansNumero = $cause(static fn() => Profile::lire($pdoP, 3));
ok('le profil aussi : une panne d\'instance, pas un profil abîmé', str_contains($profilSansNumero, 'Numéro de base absent'), $profilSansNumero);
[$sortie, $rc] = $migrerSur($perdu, (string) getenv('LAB_BLINDKEY_PATH'));
ok(
    'la passe de migration s\'arrête sur le numéro, au lieu de se dire finie',
    $rc !== 0 && str_contains($sortie, 'Numéro de base absent') && !str_contains($sortie, '✅'),
    'rc=' . $rc . ' · ' . substr(trim($sortie), 0, 160)
);

// Réécrit, et non perdu : tout devenait illisible, le journal accusait la clé, et le site
// offrait de remplacer un profil intact (mesuré par la neuvième passe d'audit).
$reecrit = $variante('reecrit', "UPDATE lab_generation SET valeur = '" . bin2hex(random_bytes(16)) . "';");
$numerosApresOuverture($reecrit);
$pdoP = Db::pdo();
$recuReecrit = $cause(static fn() => Dm::inbox($pdoP, 2));
ok(
    '⭐ un numéro réécrit : la boîte refuse en le nommant, elle n\'accuse pas la clé',
    str_contains($recuReecrit, 'Numéro de base réécrit'),
    $recuReecrit
);
$enregistre = $cause(static fn() => Profile::save($pdoP, 3, ['bio' => 'un profil neuf']));
ok(
    'et le titulaire ne se voit pas offrir de remplacer un profil intact',
    str_contains($enregistre, 'Numéro de base réécrit') && !str_contains($enregistre, 'profil_illisible'),
    $enregistre
);
Db::fermer();
// Un numéro altéré, et non absent : le profil se calculait sous lui dans son `catch`.
$numerosApresOuverture($variante('abime', "UPDATE lab_generation SET valeur = 'abime';"));
$pdoP = Db::pdo();
$profilAbime = $cause(static fn() => Profile::lire($pdoP, 3));
ok(
    '⭐ un numéro altéré fait refuser le profil, il ne le déguise pas en profil illisible',
    str_contains($profilAbime, 'altéré'),
    $profilAbime
);
Db::fermer();
putenv('LAB_DB_PATH=' . $baseDuBanc);
$pdo = Db::pdo();

echo "\n# À blanc, une base d'avant le numéro ne bouge pas d'un octet\n";

// `--a-blanc` rouvert par `Db::pdo()` restait vert : sur une base déjà migrée, schéma et
// migrations ne changent rien. Sur une base d'avant le numéro, ils tirent ce numéro et
// convertissent ses sessions (mesuré par la huitième passe d'audit).
$avantNumero = $baseAvec('avant-numero', "INSERT INTO app_sessions (account_id, token, created_at) VALUES (1, '" . bin2hex(random_bytes(32)) . "', $t)");
$cleAvant = $dir . '/.blindkey-avant-numero';
file_put_contents($cleAvant, bin2hex(random_bytes(48)));
$pa = new PDO('sqlite:' . $avantNumero);
$pa->prepare('INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (1, 2, ?, ?)')->execute([
    blobV2('un message d\'avant le numéro', Primitives::deriveFromMemorized(trim((string) file_get_contents($cleAvant)), '/my-self-lab/dm'), '')->toBase64(),
    $t,
]);
$pa->exec('DROP TABLE lab_generation; DROP TABLE lab_migrations');
$pa = null;
$md5Avant = md5_file($avantNumero);
[$sortie, $rc] = $migrerSur($avantNumero, $cleAvant, '--a-blanc');
clearstatcache();
ok(
    '⭐ --a-blanc sur une base d\'avant le numéro : identique à l\'octet, ni numéro tiré ni session convertie',
    $rc === 0 && md5_file($avantNumero) === $md5Avant && str_contains($sortie, '1 blob'),
    'rc=' . $rc . ' · ' . trim($sortie)
);
[$sortie, $rc] = $migrerSur($avantNumero, $cleAvant);
$pa = new PDO('sqlite:' . $avantNumero);
ok(
    'et la passe réelle lui tire son numéro, scelle son message, convertit sa session',
    $rc === 0
        && (int) $pa->query('SELECT COUNT(*) FROM lab_generation')->fetchColumn() === 1
        && str_starts_with((string) $pa->query('SELECT ciphertext FROM dm')->fetchColumn(), EncryptedBlob::PREFIX_V3)
        && str_starts_with((string) $pa->query('SELECT token FROM app_sessions')->fetchColumn(), 'sha256:'),
    'rc=' . $rc . ' · ' . trim($sortie)
);
$pa = null;

echo "\n# Une passe qui ne finit pas ne ferme rien ; « --fermer » ferme, sur décision\n";

// Rien ne gardait la condition elle-même : une passe qui posait le témoin malgré des
// lignes restées nues gardait le banc vert (mesuré par la sixième passe d'audit). Une
// instance à part, qui n'a jamais passé.
$inachevee = $baseAvec('inachevee', 'SELECT 1');
$cleInachevee = $dir . '/.blindkey-inachevee';
file_put_contents($cleInachevee, bin2hex(random_bytes(48)));
$nuI = blobV2(
    'une charge recopiée',
    Primitives::deriveFromMemorized(trim((string) file_get_contents($cleInachevee)), '/my-self-lab/dm'),
    ''
)->toBase64();
(new PDO('sqlite:' . $inachevee))->prepare(
    'INSERT INTO dm (sender_id, recipient_id, ciphertext, created_at) VALUES (1, 2, ?, ?), (2, 1, ?, ?)'
)->execute([$nuI, $t, $nuI, $t]);
[$sortie, $rc] = $migrerSur($inachevee, $cleInachevee);
ok(
    '⭐ des lignes restées nues : la passe ne pose pas le témoin',
    $rc !== 0 && !is_file($cleInachevee . '.scelle') && str_contains($sortie, 'encore ouverte'),
    'rc=' . $rc . ' · témoin = ' . var_export(is_file($cleInachevee . '.scelle'), true)
);
[$sortie, $rc] = $migrerSur($inachevee, $cleInachevee, '--fermer');
ok(
    'et « --fermer » le pose, en nommant ce qui devient illisible',
    is_file($cleInachevee . '.scelle') && str_contains($sortie, 'deviennent illisibles'),
    'rc=' . $rc . ' · ' . substr(trim($sortie), -160)
);

echo "\n# Une sauvegarde restaurée ne rend pas les chiffrés d'un compte disparu à qui reprend son numéro\n";

// La base revient de la veille, sous la même clé et le même numéro : le prochain inscrit
// reçoit le numéro d'un compte créé depuis. Qui garde une copie d'avant la restauration et
// écrit la colonne d'un chiffré de SA ligne y remettait celui du disparu, et le lisait.
$restau = $dir . '/restauration';
mkdir($restau, 0700);
$etapeRestau = $restau . '/etape.php';
file_put_contents($etapeRestau, '<?php
require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/db.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/dm.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/profile.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/redteam.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/notify.php', true) . ';
require_once ' . var_export(__DIR__ . '/../lib/i18n.php', true) . ';
use Pierroons\MySelfLab\{Db, DM, Profile, Redteam};
$p = Db::pdo();
$inscrire = static function (string $nom) use ($p): int {
    $p->prepare("INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES (?, \'h\', \'h\', \'h\', ?)")->execute([$nom, time()]);
    return (int) $p->lastInsertId();
};
switch ($argv[1]) {
    case "graine":
        echo json_encode([$inscrire("alice"), $inscrire("bob")]);
        break;
    case "disparu":
        $c = $inscrire("carol");
        DM::send($p, $c, "alice", "SECRET DU COMPTE DISPARU");
        Profile::save($p, $c, ["bio" => "BIO DU COMPTE DISPARU", "localisation" => "", "lien" => ""]);
        $r = Redteam::submit($p, ["pgp" => "-----BEGIN PGP MESSAGE-----\nx\n-----END PGP MESSAGE-----", "severity" => "info", "target" => "autre"], "198.51.100.7");
        echo json_encode([$c, $r["id"] ?? null, $r["suivi"] ?? null]);
        break;
    case "repreneur":
        $m = $inscrire("mallory");
        DM::send($p, $m, "alice", "bonjour");
        Profile::save($p, $m, ["bio" => "bio de mallory", "localisation" => "", "lien" => ""]);
        $r = Redteam::submit($p, ["pgp" => "-----BEGIN PGP MESSAGE-----\ny\n-----END PGP MESSAGE-----", "severity" => "info", "target" => "autre"], "198.51.100.8");
        echo json_encode([$m, $r["id"] ?? null]);
        break;
    case "lire":
        $m = (int) $argv[2];
        echo json_encode([
            array_column(DM::sent($p, $m), "message"),
            Profile::lire($p, $m)["champs"]["bio"] ?? "",
            Redteam::etat($p, (int) $argv[3], (string) $argv[4])["ok"] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        break;
}
');
$envRestau = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LAB_DB_PATH' => "$restau/lab.db", 'LAB_BLINDKEY_PATH' => "$restau/.blindkey",
    'LAB_SITESALT_PATH' => "$restau/.sitesalt", 'LAB_SERVERSECRET_PATH' => "$restau/.serversecret", 'LAB_MODAUDIT_PATH' => "$restau/.modaudit",
    'LAB_MODERATION_LOG' => "$restau/moderation.log"];
// Sa sortie standard seule : le journal du site, sur la sortie d'erreur, se collerait au JSON.
$etape = static function (string ...$args) use ($etapeRestau, $envRestau): string {
    $p = proc_open(array_merge([PHP_BINARY, $etapeRestau], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux, null, $envRestau);
    $o = stream_get_contents($tuyaux[1]);
    stream_get_contents($tuyaux[2]);
    fclose($tuyaux[1]);
    fclose($tuyaux[2]);
    proc_close($p);

    return trim($o);
};
$etape('graine');
copy("$restau/lab.db", "$restau/sauvegarde.db");
$sortieDisparu = $etape('disparu');
[$disparu, $rapportDisparu, $jetonDisparu] = json_decode($sortieDisparu, true) ?? [null, null, null];
copy("$restau/lab.db", "$restau/copie-d-apres.db");
copy("$restau/sauvegarde.db", "$restau/lab.db");
$sortieRepreneur = $etape('repreneur');
[$repreneur, $rapportRepreneur] = json_decode($sortieRepreneur, true) ?? [null, null];
// L'écriture de qui reprend le numéro : la colonne du chiffré, dans SES lignes seulement.
$apres = new PDO('sqlite:' . "$restau/copie-d-apres.db");
$ici = new PDO('sqlite:' . "$restau/lab.db");
$lireApres = static function (string $sql) use ($apres, $disparu): string {
    $st = $apres->prepare($sql);
    $st->execute([(int) $disparu]);

    return (string) $st->fetchColumn();
};
$ici->prepare('UPDATE dm SET ciphertext = ? WHERE sender_id = ?')
    ->execute([$lireApres('SELECT ciphertext FROM dm WHERE sender_id = ?'), $repreneur]);
$ici->prepare('UPDATE profiles SET ciphertext = ? WHERE account_id = ?')
    ->execute([$lireApres('SELECT ciphertext FROM profiles WHERE account_id = ?'), $repreneur]);
$apres = $ici = $lireApres = null;
$sortieLecture = $etape('lire', (string) $repreneur, (string) $rapportRepreneur, (string) $jetonDisparu);
$lecture = json_decode($sortieLecture, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
[$envoyes, $bio, $suivi] = is_array($lecture) && count($lecture) === 3 ? $lecture : [[], '', null];
// Le cas ne mesure rien si la reprise n'a pas eu lieu, ou si la lecture n'a rien rendu.
$reprise = $disparu !== null && $repreneur === $disparu && $rapportRepreneur === $rapportDisparu
    && is_array($lecture) && count($lecture) === 3;
ok(
    '⭐ restaurée d\'une sauvegarde, la base rend le numéro d\'un compte disparu — et son message, replanté dans la ligne du repreneur, ne s\'ouvre pas',
    $reprise && $envoyes !== [] && !in_array('SECRET DU COMPTE DISPARU', $envoyes, true),
    'disparu #' . var_export($disparu, true) . ', repreneur #' . var_export($repreneur, true) . ' · lu : ' . json_encode($envoyes, JSON_UNESCAPED_UNICODE)
        . ($reprise ? '' : ' · ' . substr($sortieDisparu . ' | ' . $sortieRepreneur . ' | ' . $sortieLecture, 0, 400))
);
ok(
    '⭐ ni son profil',
    $reprise && $bio !== 'BIO DU COMPTE DISPARU',
    'bio lue : ' . var_export($bio, true)
);
ok(
    '⭐ ni le suivi de son rapport : le jeton du rapport perdu ne vaut pas pour celui qui reprend son numéro',
    $reprise && $suivi === false,
    'rapport #' . var_export($rapportRepreneur, true) . ' · suivi accepté = ' . var_export($suivi, true)
);

echo "\n# Ce que le visiteur reçoit — par HTTP, comme lui\n";

// Le gestionnaire d'exception et la vue publique d'un profil illisible n'étaient
// éprouvés par aucun cas. Un vrai serveur, `display_errors` ALLUMÉ : c'est sous lui
// qu'une trace et ses chemins disque partaient au client.
$baseHttp = $baseAvec('http', 'SELECT 1');
$cleHttp  = $dir . '/.blindkey-http';
file_put_contents($cleHttp, bin2hex(random_bytes(48)));
(new PDO('sqlite:' . $baseHttp))->prepare('INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (1, ?, ?)')
    ->execute([EncryptedBlob::PREFIX_V3 . base64_encode(random_bytes(40)), $t]);
$port = 9000 + (getmypid() % 900);
$serveur = proc_open(
    [PHP_BINARY, '-d', 'display_errors=1', '-S', '127.0.0.1:' . $port, '-t', __DIR__ . '/../public'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $tuyaux,
    null,
    ['LAB_DB_PATH' => $baseHttp, 'LAB_BLINDKEY_PATH' => $cleHttp] + instance_isolee()
);
$debout = false;
for ($i = 0; $i < 50 && !$debout; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) {
        fclose($c);
        $debout = true;
    } else {
        usleep(100000);
    }
}
$demander = static function (string $chemin, ?array $json = null) use ($port): array {
    $ctx = stream_context_create(['http' => [
        'method'        => $json === null ? 'GET' : 'POST',
        'header'        => "Accept-Language: fr\r\nContent-Type: application/json\r\n",
        'content'       => $json === null ? '' : json_encode($json),
        'ignore_errors' => true,
        'timeout'       => 10,
    ]]);
    $corps = (string) @file_get_contents('http://127.0.0.1:' . $port . $chemin, false, $ctx);
    $entetes = implode("\n", $http_response_header ?? []);

    return [(int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0), $entetes, $corps];
};
if (!$debout) {
    ok('le serveur du banc démarre', false, 'aucun cas HTTP n\'a été joué');
} else {
    [$code, , $corps] = $demander('/profile.php?u=alice');
    ok(
        '⭐ la vue publique d\'un profil illisible le dit, au lieu d\'un profil vide',
        $code === 200 && str_contains($corps, 'ne peut pas être affiché'),
        'code=' . $code
    );
    // Le secret retiré : chaque route qui l'atteint lève, et c'est la réponse qui compte.
    rename($cleHttp, $cleHttp . '.retire');
    $fuite = static fn(string $c): bool => str_contains($c, 'Stack trace') || str_contains($c, __DIR__ . '/..')
        || str_contains($c, '.blindkey') || preg_match('#/[a-z_]+\.php#', $c) === 1;
    [$code, $entetes, $corps] = $demander('/api/report_status.php', ['id' => 1, 'suivi' => str_repeat('a', 32)]);
    ok(
        '⭐ une route d\'API qui lève rend un 500 JSON, sans trace ni chemin',
        $code === 500 && str_contains($entetes, 'application/json')
            && (json_decode($corps, true)['error'] ?? '') === 'indisponible' && !$fuite($corps),
        'code=' . $code . ' · ' . substr($corps, 0, 160)
    );
    [$code, $entetes, $corps] = $demander('/profile.php?u=alice');
    ok(
        '⭐ une page qui lève rend un 500 HTML, sans trace ni chemin',
        $code === 500 && str_contains($entetes, 'text/html') && !$fuite($corps),
        'code=' . $code . ' · ' . substr(strip_tags($corps), 0, 160)
    );
    rename($cleHttp . '.retire', $cleHttp);
}
proc_terminate($serveur);
proc_close($serveur);

printf("\n%s %d/%d contrôles conformes\n", $echecs === 0 ? '✅' : '❌', $passes, $passes + $echecs);
exit($echecs === 0 ? 0 : 1);
