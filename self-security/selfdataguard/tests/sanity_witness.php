<?php

declare(strict_types=1);

/**
 * Sanity test — the witness conformance suite.
 *
 * Run:  php tests/sanity_witness.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * A witness whose store takes a head from anyone who can write to it lets one
 * forged head stop every ceremony. The suite must pass the witness that keeps
 * its duties, and fail each witness that breaks one, on the case that names it.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/witness_doubles.php';

use Pierroons\SelfDataGuard\Escrow\NoHeadWitness;
use Pierroons\SelfDataGuard\Escrow\WitnessConformance;

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

$work = sys_get_temp_dir() . '/sdg-witness-conf-' . bin2hex(random_bytes(4));
mkdir($work);
register_shutdown_function(static function () use ($work): void {
    array_map('unlink', glob("{$work}/*") ?: []);
    @rmdir($work);
});

/** @return array<string, string> case => detail, for the cases that fail */
$failedCases = static fn (array $results): array => array_column(
    array_filter($results, static fn (array $r): bool => $r['outcome'] === 'fail'), 'detail', 'case'
);

section('The suite — the witness that keeps its duties passes, each broken one fails on its duty');

$results = WitnessConformance::run(reserveWitnessFactory());
$failed = $failedCases($results);
$cases = array_column($results, 'case');
$failed === [] && count($cases) === 6
    ? ok('ReserveWitness: the six cases pass')
    : ko('the witness that keeps its duties fails the suite', json_encode($results));
$reset = array_column($results, 'detail', 'case')['store-not-reset'] ?? '';
str_contains($reset, 'out of the serving machine\'s reach: reset')
    ? ok('a store the serving machine cannot reset passes, and the result says the reset was out of reach')
    : ko('the reset out of reach is not said', $reset);

foreach ([
    [PreloadedWitness::class, WitnessReserve::class, 'starts-empty'],
    [DroppingWitness::class, WitnessReserve::class, 'takes-a-head'],
    [AnySeqWitness::class, WitnessReserve::class, 'refuses-a-seq-not-above'],
    [UnsignedReserveWitness::class, WitnessReserve::class, 'forged-head-not-taken'],
    [ThrowsOnForgeryWitness::class, WitnessReserve::class, 'forged-head-not-taken'],
    [ReserveWitness::class, ResettableReserve::class, 'store-not-reset'],
    [LastEntryWitness::class, WitnessReserve::class, 'no-rewind'],
] as [$class, $reserve, $case]) {
    $failed = $failedCases(WitnessConformance::run(reserveWitnessFactory($class, $reserve)));
    isset($failed[$case])
        ? ok("{$class} on {$reserve} fails {$case}")
        : ko("{$class} on {$reserve} passed a case it breaks", $case);
}

$failed = $failedCases(WitnessConformance::run(reserveWitnessFactory(ThrowsOnForgeryWitness::class)));
str_contains($failed['forged-head-not-taken'] ?? '', 'every ceremony stops')
    ? ok('a witness that throws on a forged line fails, and the result says a throw stops every ceremony')
    : ko('a throw on a forged line is not named', json_encode($failed));

$failed = $failedCases(WitnessConformance::run(plainFileWitnessFactory($work)));
isset($failed['forged-head-not-taken'], $failed['store-not-reset'], $failed['no-rewind'], $failed['refuses-a-seq-not-above'])
    ? ok('the plain file the ceremony bench writes fails the forged head, the reset, the rewind and the seq not above')
    : ko('the plain file witness passed a case it breaks', json_encode($failed));

$results = WitnessConformance::run(static fn (): array => [new NoHeadWitness(), static fn (): bool => false]);
count($results) === 1 && $results[0]['outcome'] === 'nothing'
    ? ok('NoHeadWitness: nothing to play, said as such')
    : ko('NoHeadWitness was played as a witness', json_encode($results));

try {
    WitnessConformance::run(static fn (): ReserveWitness => new ReserveWitness(new WitnessReserve(), random_bytes(32)));
    ko('a factory that returns a witness without its forger was taken');
} catch (InvalidArgumentException) {
    ok('a factory that returns a witness without its forger is refused');
}

section('bin/witness-conformance.php — its exit codes');

$factoryFile = static function (string $name, string $returns) use ($work): string {
    $file = "{$work}/{$name}.php";
    file_put_contents($file, sprintf(
        "<?php\nrequire %s;\nrequire_once %s;\n%s\n",
        var_export(__DIR__ . '/../src/autoload.php', true),
        var_export(__DIR__ . '/witness_doubles.php', true),
        $returns
    ));
    return $file;
};
$runBin = static function (string $file): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/witness-conformance.php', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($process), $out];
};

[$rc, $out] = $runBin($factoryFile('conforme', 'return reserveWitnessFactory();'));
$rc === 0 && str_contains($out, 'Conforme.')
    ? ok('0 on the witness that keeps its duties')
    : ko('the witness that keeps its duties was not reported conformant', "rc={$rc} {$out}");
[$rc, $out] = $runBin($factoryFile('fichier', 'return plainFileWitnessFactory(' . var_export($work, true) . ');'));
$rc === 1 && str_contains($out, '❌ forged-head-not-taken')
    ? ok('1 on the plain file witness, naming the forged head')
    : ko('the plain file witness was not reported', "rc={$rc} {$out}");
[$rc, $out] = $runBin($factoryFile('aucun', 'return static fn (): array => [new Pierroons\SelfDataGuard\Escrow\NoHeadWitness(), static fn (): bool => false];'));
$rc === 4 && str_contains($out, 'Rien à éprouver')
    ? ok('4 on NoHeadWitness — nothing to play, outside 0, 1, 2, 125-127 and 255')
    : ko('NoHeadWitness did not exit 4', "rc={$rc} {$out}");
[$rc] = $runBin("{$work}/absent.php");
$rc === 3 ? ok('3 on a file that does not exist') : ko('a missing file did not exit 3', "rc={$rc}");
[$rc] = $runBin($factoryFile('instance', 'return new ReserveWitness(new WitnessReserve(), random_bytes(32));'));
$rc === 3 ? ok('3 on a file that returns a witness instead of a factory') : ko('a witness was taken for a factory', "rc={$rc}");
[$rc] = $runBin($factoryFile('sans-faussaire', 'return static fn () => [new ReserveWitness(new WitnessReserve(), random_bytes(32))];'));
$rc === 3 ? ok('3 on a factory that returns no forger') : ko('a factory without a forger did not exit 3', "rc={$rc}");

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Witness Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
