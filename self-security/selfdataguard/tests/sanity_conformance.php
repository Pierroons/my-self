<?php

declare(strict_types=1);

/**
 * Sanity test — the storage conformance suite, played against the shipped
 * adapter and against storages that each break one clause of the contract.
 *
 * Run:  php tests/sanity_conformance.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * A suite that passes the shipped adapter proves nothing on its own. Each
 * broken storage must make it fail, on the case that names the clause it
 * breaks — otherwise that case does not measure what it says.
 */

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/storage_doubles.php';

use Pierroons\SelfDataGuard\Storage\Conformance;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

$failures = 0;
$passes = 0;

function ok(string $label): void
{
    global $passes;
    $passes++;
    echo "  ✅ {$label}\n";
}

function ko(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    echo "  ❌ {$label}";
    if ($detail !== '') {
        echo " — {$detail}";
    }
    echo "\n";
}

function section(string $title): void
{
    echo "\n→ {$title}\n";
}

$work = sys_get_temp_dir() . '/selfdataguard-conformance-' . bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(static function () use ($work): void {
    foreach (glob("{$work}/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($work);
});

/**
 * A connection file for $class wrapping a SqliteAdapter on its own database —
 * what the racing processes require —, the callable it returns, and a callable
 * that runs work inside a transaction it opens on a new connection, then rolls
 * it back.
 *
 * @return array{0: callable, 1: string, 2: callable}
 */
function connection(string $work, string $class): array
{
    $db   = "{$work}/{$class}.sqlite";
    $file = "{$work}/{$class}.php";
    $build = $class === SqliteAdapter::class ? 'new SqliteAdapter($pdo)' : "new {$class}(new SqliteAdapter(\$pdo), \$pdo)";
    file_put_contents($file, sprintf(
        "<?php\nrequire_once %s;\nrequire_once %s;\nuse Pierroons\\SelfDataGuard\\Storage\\SqliteAdapter;\n"
        . "return static function () { \$pdo = new PDO(%s); return %s; };\n",
        var_export(__DIR__ . '/../src/autoload.php', true),
        var_export(__DIR__ . '/storage_doubles.php', true),
        var_export("sqlite:{$db}", true),
        $build
    ));
    $callerFile = "{$work}/{$class}-caller.php";
    file_put_contents($callerFile, sprintf(
        "<?php\nrequire_once %s;\nrequire_once %s;\nuse Pierroons\\SelfDataGuard\\Storage\\SqliteAdapter;\n"
        . "return static function (callable \$work): void { \$pdo = new PDO(%s);"
        . " \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); \$pdo->beginTransaction();"
        . " \$work(%s); \$pdo->rollBack(); };\n",
        var_export(__DIR__ . '/../src/autoload.php', true),
        var_export(__DIR__ . '/storage_doubles.php', true),
        var_export("sqlite:{$db}", true),
        $build
    ));
    return [require $file, $file, require $callerFile, $callerFile];
}

/** @return array<string, array{outcome: string, detail: string}> */
function outcomes(array $results): array
{
    $byCase = [];
    foreach ($results as $r) {
        $byCase[$r['case']] = ['outcome' => $r['outcome'], 'detail' => $r['detail']];
    }
    return $byCase;
}

// -----------------------------------------------------------------------------

section('The shipped SqliteAdapter passes every case');

[$connect, $file, $withCaller] = connection($work, SqliteAdapter::class);
$shipped = outcomes(Conformance::run($connect, $file, $withCaller));
foreach ($shipped as $case => $r) {
    $r['outcome'] === 'pass'
        ? ok($case)
        : ko($case, "{$r['outcome']}: {$r['detail']}");
}
$pdo = new PDO("sqlite:{$work}/" . SqliteAdapter::class . '.sqlite');
$left = (int) $pdo->query(
    "SELECT (SELECT COUNT(*) FROM selfdataguard_vaults) + (SELECT COUNT(*) FROM selfdataguard_archives)
          + (SELECT COUNT(*) FROM selfdataguard_fields) + (SELECT COUNT(*) FROM selfdataguard_escrow)"
)->fetchColumn();
$left === 0
    ? ok('every userId the suite created is erased: nothing is left in the database')
    : ko('the suite left rows behind', "{$left} rows");

// -----------------------------------------------------------------------------

section('Without a connection file, the racing cases are skipped — never reported passed');

$memory = outcomes(Conformance::run(static fn () => new SqliteAdapter('sqlite::memory:')));
$racing = array_filter($memory, static fn (string $case): bool => str_starts_with($case, 'concurrent-'), ARRAY_FILTER_USE_KEY);
count($racing) === 7 && array_unique(array_column($racing, 'outcome')) === ['skip']
    ? ok('the seven concurrent-* cases: skip')
    : ko('a racing case ran, or passed, with no process to race', json_encode($racing));
($memory['joins-callers-transaction']['outcome'] ?? null) === 'skip'
    ? ok('without a caller transaction, joins-callers-transaction: skip')
    : ko('joins-callers-transaction ran, or passed, with no caller transaction', json_encode($memory['joins-callers-transaction'] ?? null));

// -----------------------------------------------------------------------------

section('Each broken storage fails the suite, on the case that names the clause it breaks');

// The racing cases catch a storage that checks, then writes in a second
// statement. A storage that ignores the generation or the revision outright is
// the sequential cases' to catch: under a race its own read happens at the same
// instant as the others', and the adapter underneath still lets one through.

$broken = [
    UpsertingStorage::class            => ['vault-insert-never-replaces'],
    CaseInsensitiveStorage::class      => ['userid-byte-for-byte'],
    GenerationBlindStorage::class      => ['writes-check-their-generation', 'replace-with-archive'],
    LastWriterWinsStorage::class       => ['update-compare-and-swap'],
    CheckThenWriteStorage::class       => ['concurrent-updates'],
    ArchiveErasingDeleteStorage::class => ['delete-vault'],
    LiveOnlyEraseStorage::class        => ['erase-account'],
    ProfileFlatteningStorage::class    => ['vault-round-trip'],
    BlindIndexNoCaseStorage::class     => ['fields-round-trip'],
    UniqueIndexDroppedStorage::class   => ['blind-index-unique'],
    SharedIndexDroppingStorage::class  => ['blind-index-unique'],
    GenerationCheckThenWriteStorage::class => ['concurrent-writes-and-re-enrolment'],
    GenerationCheckThenWriteNoPauseStorage::class => ['concurrent-writes-and-re-enrolment'],
    UpsertingEscrowStorage::class      => ['escrow-round-trip', 'concurrent-escrow-creations'],
    SettingOverwritingStorage::class   => ['setting-claimed-once'],
    CheckThenClaimStorage::class       => ['concurrent-setting-claims'],
    CheckThenInsertIndexStorage::class => ['concurrent-unique-index'],
    SealDroppingStorage::class         => ['vault-round-trip', 'update-compare-and-swap'],
    ProfileFrozenStorage::class        => ['update-compare-and-swap'],
    LiveOnlyHasVaultsStorage::class    => ['has-vaults'],
    BlindOnlyIndexedFieldsStorage::class => ['indexed-fields'],
    FreezeSkippingFieldsStorage::class => ['freeze-refuses-writes'],
    FreezeSkippingUpdateStorage::class => ['freeze-refuses-writes'],
    FreezeOverwritingStorage::class    => ['freeze-refuses-writes'],
    IndexDroppingRestoreStorage::class => ['swap-with-archive', 'restore-archive'],
    RestoreOverLiveStorage::class      => ['restore-archive'],
    CheckThenSwapStorage::class        => ['concurrent-archive-swaps'],
    CallerCommittingStorage::class     => ['joins-callers-transaction'],
];
foreach ($broken as $class => $expected) {
    [$connect, $file, $withCaller] = connection($work, $class);
    $results = outcomes(Conformance::run($connect, $file, $withCaller));
    $failed = array_keys(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'fail'));
    $missed = array_diff($expected, $failed);
    $missed === []
        ? ok("{$class}: " . implode(', ', $expected))
        : ko("{$class} passed a case it breaks", implode(', ', $missed));
}

foreach ([
    CheckThenWriteStorage::class           => 'update-compare-and-swap',
    CheckThenInsertIndexStorage::class     => 'blind-index-unique',
    GenerationCheckThenWriteStorage::class => 'writes-check-their-generation',
    CheckThenClaimStorage::class           => 'setting-claimed-once',
    CheckThenSwapStorage::class            => 'swap-with-archive',
] as $class => $sequential) {
    [$connect, $file, $withCaller] = connection($work, $class);
    $results = outcomes(Conformance::run($connect, $file, $withCaller));
    $results[$sequential]['outcome'] === 'pass'
        ? ok("{$class} passes {$sequential}: only the racing processes catch it")
        : ko("{$class} failed {$sequential} — the racing case is not what catches it", $results[$sequential]['detail']);
}

// -----------------------------------------------------------------------------

section('bin/storage-conformance.php — the exit code says it');

$bin = __DIR__ . '/../bin/storage-conformance.php';
$exit = static function (array $args): array {
    $process = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($process), $out];
};
[, $file, , $callerFile] = connection($work, SqliteAdapter::class);
[$rc, $out] = $exit([$bin, $file, $callerFile]);
$rc === 0 && str_contains($out, 'Conforme')
    ? ok('0 on the shipped adapter')
    : ko('the shipped adapter was not reported conformant', "rc={$rc} {$out}");
[$rc, $out] = $exit([$bin, $file]);
$rc === 1 && str_contains($out, '⏭️ joins-callers-transaction')
    ? ok('without the caller-transaction file, the case is skipped and the adapter is not declared conformant')
    : ko('a run without the caller-transaction file was declared conformant', "rc={$rc} {$out}");
[, $file] = connection($work, UpsertingStorage::class);
[$rc, $out] = $exit([$bin, $file]);
$rc === 1 && str_contains($out, '❌ vault-insert-never-replaces')
    ? ok('1 on a broken storage, naming the case it fails')
    : ko('a broken storage was not reported', "rc={$rc} {$out}");
[$rc] = $exit([$bin, "{$work}/missing.php"]);
$rc === 3
    ? ok('3 on a missing connection file')
    : ko('a missing connection file did not exit 3', "rc={$rc}");

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Conformance Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
