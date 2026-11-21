<?php

declare(strict_types=1);

/**
 * Sanity test — the pepper.
 *
 * Run:  php tests/sanity_pepper.php
 * Exit: 0 on success, non-zero if any check failed.
 *
 * The form of the pepper is checked against the vectors a second
 * implementation wrote (`vecteurs_poivre.py`); the pepper file is refused
 * with its reason; `bin/pepper.php new` draws one and never writes over one.
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Pepper\LocalPepperFile;
use Pierroons\SelfDataGuard\Pepper\Pepper;
use Pierroons\SelfDataGuard\Pepper\PepperFileException;
use Pierroons\SelfDataGuard\Pepper\PepperSource;
use Pierroons\SelfDataGuard\Pepper\PepperUnavailableException;
use Pierroons\SelfDataGuard\Vault\UserVault;

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

$work = sys_get_temp_dir() . '/sdg-pepper-' . bin2hex(random_bytes(4));
mkdir($work, 0o700);
register_shutdown_function(static function () use ($work): void {
    foreach (glob("{$work}/{,.}*", GLOB_BRACE) ?: [] as $f) {
        if (is_dir($f) && !is_link($f) && !in_array(basename($f), ['.', '..'], true)) {
            array_map('unlink', glob("{$f}/*") ?: []);
            @rmdir($f);
        } elseif (!is_dir($f) || is_link($f)) {
            @chmod($f, 0o600);
            @unlink($f);
        }
    }
    @rmdir($work);
});

/** A pepper file with these contents and this mode, in the work directory. */
$fileOf = static function (string $name, string $contents, int $mode = 0o600) use ($work): string {
    $path = "{$work}/{$name}";
    file_put_contents($path, $contents);
    chmod($path, $mode);
    return $path;
};

/** The reason a pepper file is refused for, or null when it is taken. */
$refusal = static function (callable $make): ?string {
    try {
        $make();
        return null;
    } catch (PepperFileException $e) {
        return $e->reason;
    }
};

// -----------------------------------------------------------------------------

section('The form — the library and a second implementation give the same values');

$vectors = json_decode((string) file_get_contents(__DIR__ . '/vecteurs-poivre.json'), true);
$vectors['profil'] === ['opslimit' => Primitives::ARGON2_OPSLIMIT, 'memlimit' => Primitives::ARGON2_MEMLIMIT]
    ? ok('the vectors were written under the profile of the library')
    : ko('the vectors were written under another profile', json_encode($vectors['profil']));

foreach ($vectors['cas'] as $i => $case) {
    $source = new LocalPepperFile($fileOf("vector-{$i}", $case['fichier']), '');
    $salt   = (string) hex2bin($case['sel_compte_hex']);
    $inPwd   = Pepper::lockInput($source, Pepper::USAGE_WRAP_PWD, $salt, $case['mot_de_passe']);
    $inRecov = Pepper::lockInput($source, Pepper::USAGE_WRAP_RECOV, $salt, $case['mot_memorise']);
    $got = [
        'check'                   => Pepper::check($source),
        'pre of the memorized'    => bin2hex(Pepper::pre($salt, $case['mot_memorise'])),
        'input of wrap_pwd'       => $inPwd,
        'input of wrap_recov'     => $inRecov,
        'key of wrap_pwd'         => bin2hex(Primitives::deriveFromPassword($inPwd, $salt)),
        'key of wrap_recov'       => bin2hex(Primitives::deriveFromMemorized($inRecov, $salt . UserVault::HMAC_CONTEXT_SUFFIX)),
        'layer key of wrap_pwd'   => bin2hex(Pepper::layerKey($source, Pepper::USAGE_WRAP_PWD_LAYER, $salt)),
        'layer key of wrap_recov' => bin2hex(Pepper::layerKey($source, Pepper::USAGE_WRAP_RECOV_LAYER, $salt)),
    ];
    $expected = [
        'check'                   => $case['attendu_check'],
        'pre of the memorized'    => $case['attendu_pre_mot_memorise'],
        'input of wrap_pwd'       => $case['attendu_entree_wrap_pwd'],
        'input of wrap_recov'     => $case['attendu_entree_wrap_recov'],
        'key of wrap_pwd'         => $case['attendu_cle_wrap_pwd'],
        'key of wrap_recov'       => $case['attendu_cle_wrap_recov'],
        'layer key of wrap_pwd'   => $case['attendu_cle_couche_wrap_pwd'],
        'layer key of wrap_recov' => $case['attendu_cle_couche_wrap_recov'],
    ];
    foreach ($expected as $what => $value) {
        hash_equals($value, $got[$what])
            ? ok("{$case['nom']}: {$what}")
            : ko("{$case['nom']}: {$what} differs from the second implementation", $got[$what]);
    }
}

// Computed during the design review by an implementation written apart from
// both: the first case's inputs, and the values it gave.
$first = $vectors['cas'][0];
[$first['attendu_check'], $first['attendu_entree_wrap_recov'], $first['attendu_cle_couche_wrap_recov']] === [
    '18f5ec55bbf7a4380f317868a4599b20',
    'ba5aa0fea73210aa66a5d071fd43523d8df8b4329b4f76d90eec5a50846c34e8',
    'a649bb95e6cd73237156f0ad5096246f97600fd26af4815cdf670b6d1d850bf8',
] ? ok('the first case gives the values a third implementation gave')
  : ko('the vectors no longer give the values a third implementation gave');

foreach ($vectors['refus'] as $what => $refused) {
    $reason = $refusal(static fn () => LocalPepperFile::parse((string) hex2bin($refused['contenu_hex'])));
    $reason === $refused['raison']
        ? ok("a pepper file with {$what} is refused: {$reason}")
        : ko("a pepper file with {$what} was not refused as the second implementation refuses it", (string) $reason);
}

// -----------------------------------------------------------------------------

section('The pepper file — refused with its reason');

$good = Pepper::TAG . ':' . bin2hex(random_bytes(32)) . "\n";
$refusal(static fn () => new LocalPepperFile($fileOf('good', $good), '')) === null
    ? ok('a file drawn at random, in mode 0600, is taken')
    : ko('a good pepper file was refused');
$refusal(static fn () => new LocalPepperFile($fileOf('group-reads', $good, 0o640), '')) === null
    ? ok('mode 0640 is taken: the group may read it, as a service group does')
    : ko('a file its group may read was refused');
foreach ([
    'absent'              => static fn () => new LocalPepperFile("{$work}/nowhere", ''),
    'not-regular'         => static fn () => new LocalPepperFile($work, ''),
    'too-open'            => static fn () => new LocalPepperFile($fileOf('others-read', $good, 0o644), ''),
    'too-open, group'     => static fn () => new LocalPepperFile($fileOf('group-writes', $good, 0o660), ''),
    'malformed'           => static fn () => new LocalPepperFile($fileOf('crlf', rtrim($good) . "\r\n"), ''),
    'uniform'             => static fn () => new LocalPepperFile($fileOf('uniform', Pepper::TAG . ':' . str_repeat('ab', 32) . "\n"), ''),
] as $expected => $make) {
    $reason = $refusal($make);
    $reason === explode(',', $expected)[0]
        ? ok("refused, {$expected}")
        : ko("not refused as {$expected}", (string) $reason);
}
symlink($fileOf('target', $good), "{$work}/link");
$refusal(static fn () => new LocalPepperFile("{$work}/link", '')) === 'not-regular'
    ? ok('a symbolic link is refused, even to a good file: not-regular')
    : ko('a symbolic link was taken for the pepper file');
mkdir("{$work}/www", 0o700);
$served = $fileOf('www/pepper', $good);
$refusal(static fn () => new LocalPepperFile($served, "{$work}/www")) === 'under-document-root'
    ? ok('a file under the document root is refused: under-document-root')
    : ko('a file the web server could serve was taken');
$refusal(static fn () => new LocalPepperFile($served, "{$work}/elsewhere")) === null
    ? ok('the same file, outside the root given, is taken')
    : ko('a file outside the document root was refused');
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    ok('skipped under root, which reads any file: a file this process cannot read');
} else {
    $refusal(static fn () => new LocalPepperFile($fileOf('unreadable', $good, 0o200), '')) === 'unreadable'
        ? ok('a file this process cannot read is refused: unreadable')
        : ko('an unreadable file was not refused as such');
}
$debug = print_r(new LocalPepperFile($fileOf('dumped', $good), ''), true);
!str_contains($debug, substr($good, 17, 64)) && str_contains($debug, '[redacted]')
    ? ok('the pepper stays out of a debug dump')
    : ko('the pepper is readable in a debug dump');

// -----------------------------------------------------------------------------

section('Usages and sources');

foreach (['' => 'an empty usage', str_repeat('u', 256) => 'a usage of 256 bytes'] as $usage => $what) {
    try {
        Pepper::message($usage, '');
        ko("{$what} was taken: its length no longer fits the byte that prefixes it");
    } catch (InvalidArgumentException) {
        ok("{$what} is refused");
    }
}
$short = new class implements PepperSource {
    public function mac(string $usage, string $data): string
    {
        return str_repeat("\0", 31);
    }
};
try {
    Pepper::check($short);
    ko('a source that returns 31 bytes was taken for a pepper computation');
} catch (PepperUnavailableException) {
    ok('a source that returns anything but 32 bytes is refused: PepperUnavailableException');
}

// -----------------------------------------------------------------------------

section('bin/pepper.php new — draws a pepper, and never writes over one');

$run = static function (array $args): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/pepper.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($process), $out];
};
$drawn = "{$work}/drawn";
[$rc, $out] = $run(['new', $drawn]);
$check = null;
try {
    $check = Pepper::check(new LocalPepperFile($drawn, ''));
} catch (PepperFileException $e) {
    ko('the file bin/pepper.php wrote is refused', $e->reason);
}
$rc === 0 && $check !== null && (fileperms($drawn) & 0o777) === 0o600 && str_contains($out, $check)
    ? ok('0: a pepper file in mode 0600, taken by LocalPepperFile, and its check printed')
    : ko('bin/pepper.php new did not write a pepper file as expected', "rc={$rc} {$out}");
$before = (string) file_get_contents($drawn);
[$rc, $out] = $run(['new', $drawn]);
$rc === 2 && file_get_contents($drawn) === $before
    ? ok('2 on a file that exists, which is left as it was')
    : ko('bin/pepper.php new wrote over a pepper', "rc={$rc} {$out}");
[$rc] = $run(['new', "{$work}/no-such-dir/pepper"]);
$rc === 3 ? ok('3 when the file cannot be created') : ko('a pepper in a missing directory did not exit 3', "rc={$rc}");
[$rc] = $run(['old', $drawn]);
$rc === 3 ? ok('3 on a command it does not know') : ko('an unknown command did not exit 3', "rc={$rc}");

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Pepper Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
