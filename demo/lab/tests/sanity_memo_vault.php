#!/usr/bin/env php
<?php
/**
 * Contrôles du stockage du coffre mémo — ce que le serveur accepte et ce qu'il rend.
 *
 * Le serveur ne fait aucune crypto ; il ne doit pas non plus ranger un coffre qui ne
 * dit pas sous quels paramètres Argon2id il a été scellé, puisque le client refuserait
 * de le relire. `sanity_memo_client.js` éprouve le client ; celui-ci, la table.
 *
 * Usage : php tests/sanity_memo_vault.php
 */

declare(strict_types=1);

$dir = sys_get_temp_dir() . '/sanity_memo_vault_' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$base = "$dir/lab.db";
register_shutdown_function(static function () use ($dir): void {
    foreach (glob("$dir/*") ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dir);
});
putenv("LAB_DB_PATH=$base");

// Une base créée AVANT la colonne `kdf` : c'est la migration qui doit l'ajouter.
$ancienne = new PDO('sqlite:' . $base);
$ancienne->exec('CREATE TABLE memo_vault (account_id INTEGER PRIMARY KEY, kdf_salt TEXT NOT NULL,
    kdf_iter INTEGER NOT NULL, memo_iv TEXT NOT NULL, memo_ct TEXT NOT NULL, wrap_pw_iv TEXT NOT NULL,
    wrap_pw_ct TEXT NOT NULL, wrap_rec_iv TEXT NOT NULL, wrap_rec_ct TEXT NOT NULL, updated_at INTEGER NOT NULL)');
$ancienne = null;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/memo_vault.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\MemoVault;

$echecs = 0; $reussites = 0;
function ok(string $m): void  { global $reussites; echo "  ✓ $m\n"; $reussites++; }
function nok(string $m): void { global $echecs; fwrite(STDERR, "  ✗ $m\n"); $echecs++; }

$pdo  = Db::pdo();
$cols = $pdo->query('PRAGMA table_info(memo_vault)')->fetchAll(PDO::FETCH_COLUMN, 1);
in_array('kdf', $cols, true)
    ? ok('une table créée sans `kdf` la reçoit à l\'ouverture')
    : nok('la migration n\'a pas ajouté la colonne `kdf`');

$pdo->exec("INSERT INTO accounts (username, pw_hash, pass_hash, recovery_hash, created_at) VALUES ('banc', 'x', 'x', 'x', 1)");
$id    = (int) $pdo->lastInsertId();
$blobs = ['kdf_salt' => 'AAAAAAAAAAAAAAAAAAAAAA==', 'memo_iv' => 'AAAA', 'memo_ct' => 'AAAA',
          'wrap_pw_iv' => 'AAAA', 'wrap_pw_ct' => 'AAAA', 'wrap_rec_iv' => 'AAAA', 'wrap_rec_ct' => 'AAAA'];
$argon = static fn (array $en_plus = []): string => json_encode($en_plus + ['alg' => 'argon2id', 't' => 3, 'm' => 65536, 'p' => 1]);

foreach ([
    'un profil Argon2id complet'          => [$argon(), true],
    'un coffre sans `kdf`'                => [null, false],
    'un autre algorithme'                 => [$argon(['alg' => 'pbkdf2']), false],
    'un paramètre qui n\'est pas entier'  => [$argon(['m' => '65536']), false],
    'un paramètre démesuré'               => [$argon(['t' => 999]), false],
    'du JSON cassé'                       => ['{"alg":', false],
] as $cas => [$kdf, $attendu]) {
    $r = MemoVault::save($pdo, $id, $kdf === null ? $blobs : $blobs + ['kdf' => $kdf]);
    $r['ok'] === $attendu
        ? ok("$cas : " . ($attendu ? 'accepté' : 'refusé'))
        : nok("$cas : " . ($r['ok'] ? 'accepté' : 'refusé') . ', attendu ' . ($attendu ? 'accepté' : 'refusé'));
}

MemoVault::save($pdo, $id, $blobs + ['kdf' => $argon(['extra' => 'x'])]);
$v = MemoVault::get($pdo, $id);
($v['kdf'] ?? null) === '{"alg":"argon2id","t":3,"m":65536,"p":1}'
    ? ok('le `kdf` rendu est normalisé : un champ en trop ne passe pas')
    : nok('`kdf` rendu : ' . var_export($v['kdf'] ?? null, true));
(int) $pdo->query('SELECT kdf_iter FROM memo_vault')->fetchColumn() === 0
    ? ok('`kdf_iter` vaut 0 : plus aucun nombre de tours PBKDF2')
    : nok('`kdf_iter` porte encore une valeur');

echo "\n";
$total = $reussites + $echecs;
if ($echecs === 0) {
    echo "OK — $total/$total contrôles conformes.\n";
    exit(0);
}
fwrite(STDERR, "ÉCHEC — $echecs contrôle(s) sur $total.\n");
exit(1);
