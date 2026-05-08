<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('GET only', 405);

/**
 * Return the raw contents of the SQLite database, EXACTLY as an attacker
 * who exfiltrates the file would see it. The point of this endpoint is to
 * prove visually that field values are unreadable without unlocking.
 */
if (!is_file(DEMO_DB_PATH)) {
    ok(['vaults' => [], 'fields' => [], 'note' => 'Database empty — register a user first.']);
}

$pdo = new PDO('sqlite:' . DEMO_DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$vaults = $pdo->query('SELECT user_id, user_salt, wrap_pwd, wrap_recov, created_at FROM selfdataguard_vaults')->fetchAll();
$fields = $pdo->query('SELECT user_id, field_name, ciphertext, blind_index FROM selfdataguard_fields')->fetchAll();

// Truncate long ciphertext for display readability (still the actual stored data)
$truncate = static function (?string $s, int $max = 64): ?string {
    if ($s === null) return null;
    if (strlen($s) <= $max) return $s;
    return substr($s, 0, $max) . '…';
};

foreach ($vaults as &$v) {
    $v['user_salt']  = $truncate($v['user_salt']);
    $v['wrap_pwd']   = $truncate($v['wrap_pwd']);
    $v['wrap_recov'] = $truncate($v['wrap_recov']);
}
unset($v);

foreach ($fields as &$f) {
    $f['ciphertext']  = $truncate($f['ciphertext']);
    $f['blind_index'] = $truncate($f['blind_index']);
}
unset($f);

ok([
    'vaults' => $vaults,
    'fields' => $fields,
    'note'   => 'These are the raw values stored on disk. Wraps and ciphertexts are AES-256-GCM blobs encrypted with keys never persisted in this database.',
    'dbSize' => (int) filesize(DEMO_DB_PATH),
]);
