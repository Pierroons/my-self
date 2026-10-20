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

$dir = sys_get_temp_dir() . '/sanity_dataguard_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});
putenv('LAB_DB_PATH=' . $dir . '/lab.db');
putenv('LAB_STATE_DIR=' . $dir);
putenv('LAB_BLINDKEY_PATH=' . $dir . '/.blindkey');   // sans quoi le banc lit le secret réel de l'instance locale

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/dataguard.php';
require_once __DIR__ . '/../lib/dm.php';
require_once __DIR__ . '/../lib/moderate.php';   // `Profile::save` refuse un compte banni
require_once __DIR__ . '/../lib/profile.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DataGuard;
use Pierroons\MySelfLab\Dm;
use Pierroons\MySelfLab\Profile;
use Pierroons\MySelfLab\SecretInstance;
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
SecretInstance::lire('.blindkey', 48, SecretInstance::PLANCHER, 'LAB_BLINDKEY_PATH');
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

echo "\n# Un blob déplacé n'est plus lisible — c'est l'objet de ce banc\n";

$blobDm = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 1')->fetchColumn();

// 1. le wrapper lui-même refuse un blob présenté sous un autre contexte.
//    ⚠️ Ne pas mesurer ce cas par `Profile::get()` : il passe le blob d'un message
//    privé à `json_decode`, qui rejette un non-JSON et rend un profil vide. La page
//    paraît donc sûre même quand le déchiffrement a réussi — un vert qui ne mesure
//    rien. C'est au wrapper qu'il faut poser la question.
$deplace = clairOuRefus(static fn() => DataGuard::decrypt($blobDm, DataGuard::contexteProfil(2)));
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
// dans le corps, donc il resterait vert si le `if ($contexte === '')` disparaissait.
// C'est un contexte vide qui éprouve la garde elle-même.
$contexteVide = clairOuRefus(static fn() => DataGuard::encrypt('texte', ''));
ok(
    '⭐ et un contexte VIDE est refusé — la garde, pas la signature',
    $contexteVide === null,
    'a rendu un blob : ' . substr((string) $contexteVide, 0, 24)
);

echo "\n# Les blobs écrits avant l'AAD restent lisibles — sinon la migration perd des données\n";

// Un blob d'avant la liaison au contexte, fabriqué comme le code d'alors l'écrivait :
// par réflexion sur la clé, pour que la production n'ait pas à exposer de méthode d'héritage.
$cle = (new ReflectionMethod(DataGuard::class, 'cle'));
$cle->setAccessible(true);
$ancien = Primitives::encrypt('message d\'avant la migration', $cle->invoke(null, '/my-self-lab/dm'))->toBase64();
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (20, 1, 2, ?, ?)')
    ->execute([$ancien, $t]);
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
ok('la migration passe et se dit', $rc === 0 && str_contains($sortie, '1 blob'), trim($sortie));
ok('le blob migré a changé', sha256_blob($pdo, 20) !== $empreinteAvant);

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
    clairOuRefus(static fn() => DataGuard::decrypt($blobMigre, DataGuard::contexteDm(1, 3))) === null
);

[$sortie, $rc] = $migrer();
ok(
    'relancée, la migration ne fait rien et le dit',
    $rc === 0 && str_contains($sortie, '0 blob') && str_contains($sortie, '0 sans contexte'),
    trim($sortie)
);

echo "\n# La branche des profils de la migration, que rien n'éprouvait\n";

// Un profil nu : `Profile::save()` n'en produit plus, il faut donc le poser comme
// le code d'avant l'écrivait — sans quoi la boucle `profiles` du script pourrait
// être supprimée sans qu'un seul cas rougisse (mesuré : elle l'a été, 15/15 est resté vert).
$profilNu = Primitives::encrypt(
    json_encode(['bio' => 'bob avant la migration', 'lien' => ''], JSON_UNESCAPED_UNICODE),
    $cle->invoke(null, '/my-self-lab/dm')
)->toBase64();
$compte(4, 'dave');
$pdo->prepare('INSERT INTO profiles (account_id, ciphertext, updated_at) VALUES (4, ?, ?)
               ON CONFLICT(account_id) DO UPDATE SET ciphertext = excluded.ciphertext')
    ->execute([$profilNu, $t]);

[$sortie, $rc] = $migrer();
ok('la passe relie aussi un profil nu', $rc === 0 && str_contains($sortie, '1 blob'), trim($sortie));
ok(
    '⭐ et le profil migré se lit par son titulaire, pas par un autre',
    (Profile::get($pdo, 4)['bio'] ?? '') === 'bob avant la migration'
        && clairOuRefus(static fn() => DataGuard::decrypt(
            (string) $pdo->query('SELECT ciphertext FROM profiles WHERE account_id = 4')->fetchColumn(),
            DataGuard::contexteProfil(3)
        )) === null
);

echo "\n# Ce que la passe ne peut PAS réparer, et qu'elle doit refuser\n";

// Un blob nu recopié avant la passe : elle le scellerait sous la ligne où il se
// trouve, donc elle le certifierait lisible par qui l'a déplacé. Elle doit s'en
// abstenir et le nommer. Trouvé par l'audit du lot, pas par ce banc.
$nu = Primitives::encrypt('le secret d\'alice pour bob', $cle->invoke(null, '/my-self-lab/dm'))->toBase64();
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

// (a) Le base64 strict de PHP tolère les blancs : une copie suivie d'un « \n » est
//     une autre CHAÎNE qui décode aux MÊMES octets. Comparer les chaînes la laissait
//     passer, et la passe scellait alors la copie pour qui l'avait déplacée.
$nu2 = Primitives::encrypt('le second secret d\'alice', $cle->invoke(null, '/my-self-lab/dm'))->toBase64();
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (40, 1, 2, ?, ?)')
    ->execute([$nu2, $t]);
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (41, 1, 3, ?, ?)')
    ->execute([$nu2 . "\n", $t]);

[$sortie, $rc] = $migrer();
$ligne41 = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 41')->fetchColumn();
ok(
    '⭐ une copie retouchée d\'un seul caractère est reconnue : la charge compte, pas la chaîne',
    !str_starts_with($ligne41, DataGuard::MARQUEUR_CONTEXTE) && str_contains($sortie, 'dm #41'),
    'ligne 41 = ' . substr($ligne41, 0, 12) . '… · ' . trim($sortie)
);

// (b) Préfixer la ligne LÉGITIME de « L2. » à la main la sort de l'ensemble des nus :
//     la copie s'y retrouve seule, donc unique, donc scellée pour le tiers. La
//     détection prend donc pour univers TOUTES les lignes, scellées comprises.
$nu3 = Primitives::encrypt('le troisième secret d\'alice', $cle->invoke(null, '/my-self-lab/dm'))->toBase64();
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (50, 1, 2, ?, ?)')
    ->execute([DataGuard::MARQUEUR_CONTEXTE . $nu3, $t]);
$pdo->prepare('INSERT INTO dm (id, sender_id, recipient_id, ciphertext, created_at) VALUES (51, 1, 3, ?, ?)')
    ->execute([$nu3, $t]);

[$sortie, $rc] = $migrer();
$ligne51 = (string) $pdo->query('SELECT ciphertext FROM dm WHERE id = 51')->fetchColumn();
ok(
    '⭐ un marqueur posé à la main sur la ligne légitime n\'isole pas la copie',
    !str_starts_with($ligne51, DataGuard::MARQUEUR_CONTEXTE) && str_contains($sortie, 'dm #51'),
    'ligne 51 = ' . substr($ligne51, 0, 12) . '… · ' . trim($sortie)
);

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
    ->execute(['L2.SDG2.' . base64_encode(random_bytes(40)), $t]);
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
use Pierroons\MySelfLab\{Db, DataGuard, DM};
$geste = $argv[1];
try {
    if ($geste === "inbox")  { DM::inbox(Db::pdo(), 1); echo "OK"; }
    if ($geste === "sent")   { DM::sent(Db::pdo(), 1);  echo "OK"; }
    if ($geste === "hmac")   { DataGuard::hmac("x", "drapeau"); echo "OK"; }
    if ($geste === "chiffrer") { DataGuard::encrypt("premier message", DataGuard::contexteDm(1, 2)); echo "OK"; }
} catch (\Throwable $e) { echo "LEVE"; }
');
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

// (b) Une base qui porte des chiffrés, un secret perdu, et le chemin d'une route
//     ANONYME — `hmac()`, appelé par le dépôt et le suivi des rapports et par la
//     soumission de drapeau. Il posait une clé neuve, et la case « repartir d'un
//     profil neuf » devenait ensuite l'outil de la destruction.
$cleAbsente = $dir . '/.blindkey-perdue';
ok(
    '⭐ une route anonyme ne pose pas de clé neuve sur une base qui porte des chiffrés',
    $jouer(getenv('LAB_DB_PATH'), $cleAbsente, 'hmac') === 'LEVE' && !is_file($cleAbsente),
    'secret posé = ' . var_export(is_file($cleAbsente), true)
);

printf("\n%s %d/%d contrôles conformes\n", $echecs === 0 ? '✅' : '❌', $passes, $passes + $echecs);
exit($echecs === 0 ? 0 : 1);
