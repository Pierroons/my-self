<?php

declare(strict_types=1);

/**
 * Sanity smoke test for the Escrow compartment (recovery-escrow sub-vault).
 *
 * Run:  php tests/sanity_escrow.php
 * Exit: 0 on success, non-zero on first failure.
 *
 * Validates the two-zone design: private E2E zone untouched, plus a consented
 * admin-recoverable escrow sealed to an admin recovery key (itself passphrase-
 * sealed). Proves compartmentalisation and cold-seizure resistance.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/key_fixture.php';
require __DIR__ . '/ceremony_fixture.php';
require __DIR__ . '/blob_v2_fixture.php';
require __DIR__ . '/storage_doubles.php';

use Pierroons\SelfDataGuard\Crypto\EncryptedBlob;
use Pierroons\SelfDataGuard\Fields\FieldCrypter;
use Pierroons\SelfDataGuard\Crypto\Primitives;
use Pierroons\SelfDataGuard\Escrow\Verdict;
use Pierroons\SelfDataGuard\Escrow\EscrowRefusedException;
use Pierroons\SelfDataGuard\Escrow\AuditLog;
use Pierroons\SelfDataGuard\Escrow\AdminKey;
use Pierroons\SelfDataGuard\Escrow\EscrowRecord;
use Pierroons\SelfDataGuard\Escrow\EscrowVault;
use Pierroons\SelfDataGuard\Escrow\UnlockedEscrow;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
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

// -----------------------------------------------------------------------------

$storage  = new SqliteAdapter('sqlite::memory:');
$blindKey = random_bytes(32);
$dg       = new SelfDataGuard($storage, $blindKey);

const ADMIN_PASS = 'ceremonie-admin-recuperation-tres-longue-2026';

// The escrow plaintexts live here and nowhere else. The at-rest leak check
// below searches the ciphertext for these very values: copied by hand, they
// drift, and a witness that no longer exists in the plaintext turns that
// check green by construction rather than by encryption.
const ESCROW_PLAINTEXT = [
    'contact_secours' => 'alice-secours@example.org',
    'indice_recup'    => 'ville de naissance de mon chat',
];

// -----------------------------------------------------------------------------

section('Admin recovery key generation + passphrase seal');

$admin = SelfDataGuard::generateAdminRecoveryKey(ADMIN_PASS);
isset($admin['publicKey'], $admin['sealedSecret']) ? ok('generate returns publicKey + sealedSecret') : ko('missing keys');
strlen(base64_decode($admin['publicKey'], true) ?: '') === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
    ? ok('public key is a valid 32-byte box public key') : ko('public key wrong length');
str_starts_with($admin['sealedSecret'], sprintf('%s:%d:%d:', AdminKey::FORMAT_V2, Primitives::ARGON2_OPSLIMIT, Primitives::ARGON2_MEMLIMIT))
    ? ok('sealed secret records its Argon2id profile (v2:ops:mem:salt:blob)') : ko('sealed secret malformed', $admin['sealedSecret']);

$sk = unsealAdminKey($admin['sealedSecret'], ADMIN_PASS);
strlen($sk) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES ? ok('unseal returns 32-byte secret key') : ko('unseal wrong length');
sodium_memzero($sk);

try {
    unsealAdminKey($admin['sealedSecret'], 'wrong-passphrase');
    ko('unseal with wrong passphrase → should throw');
} catch (RuntimeException) {
    ok('unseal with wrong passphrase → throws (cold-seizure resistance)');
}

// -----------------------------------------------------------------------------

section('User enrolls escrow fields + reads them back');

$session = $dg->register('alice', 'motdepasse-fort', 'sentier-brume-rocher-menthe-fusain');
// Private zone (untouched behaviour)
$dg->setFields($session, ['notes' => 'note privée', 'mots_de_passe' => 'forum:hunter2'], []);
// Escrow zone (consented, admin-recoverable)
$dg->setEscrowFields($session, $admin['publicKey'], ESCROW_PLAINTEXT);

$dg->hasEscrow($session) ? ok('hasEscrow() true after enrollment') : ko('hasEscrow should be true');
$dg->hasEscrow($dg->register('sans-sequestre', 'motdepasse-sans-01')) ? ko('hasEscrow() should be false without escrow') : ok('hasEscrow() false on a vault without escrow');

$asUser = $dg->getEscrowFieldsAsUser($session, ['contact_secours', 'indice_recup']);
$asUser['contact_secours'] === ESCROW_PLAINTEXT['contact_secours'] ? ok('user reads own escrow (contact_secours)') : ko('user escrow read wrong');
$asUser['indice_recup'] === ESCROW_PLAINTEXT['indice_recup'] ? ok('user reads own escrow (indice_recup)') : ko('user escrow read wrong');

// -----------------------------------------------------------------------------

section('Two first deposits at once: the one that loses the creation writes under the escrow that won');

// Hides the escrow from one read, as when another request creates it between
// this one's read and its own creation.
$raced = new class ($storage) extends DelegatingStorage {
    public bool $hide = true;

    public function loadEscrow(string $u): ?EscrowRecord
    {
        if ($this->hide) {
            $this->hide = false;
            return null;
        }
        return $this->inner->loadEscrow($u);
    }
};
$twice = $dg->register('deux-depots', 'motdepasse-deux-depots');
$dg->setEscrowFields($twice, $admin['publicKey'], ['contact_a' => 'premier dépôt']);
(new SelfDataGuard($raced, $blindKey))->setEscrowFields($twice, $admin['publicKey'], ['contact_b' => 'second dépôt']);
!$raced->hide
    ? ok('precondition: the second deposit found no escrow, as the losing request does')
    : ko('precondition: the second deposit never read the escrow — the check below measures nothing');
try {
    $both = $dg->getEscrowFieldsAsUser($twice);
    ksort($both);
    $both === ['contact_a' => 'premier dépôt', 'contact_b' => 'second dépôt']
        ? ok('both deposits read back: the second was written under the escrow key of the first')
        : ko('the escrow does not hold both deposits', json_encode($both));
} catch (RuntimeException $e) {
    ko('the escrow no longer reads in full: a deposit was sealed under a key that was replaced', $e->getMessage());
}

// -----------------------------------------------------------------------------

section('2FA path: memorized unlock also opens the escrow');

$viaMemorized = $dg->loginWithMemorized('alice', 'sentier-brume-rocher-menthe-fusain');
$escViaMem = $dg->getEscrowFieldsAsUser($viaMemorized, ['contact_secours']);
$escViaMem['contact_secours'] === ESCROW_PLAINTEXT['contact_secours']
    ? ok('escrow readable via memorized-secret session (2FA)') : ko('escrow not readable via memorized');

// -----------------------------------------------------------------------------

section('Admin recovery: unseal → open escrow (same data as the user)');

$sk = unsealAdminKey($admin['sealedSecret'], ADMIN_PASS);
$ceremony = benchCeremony($storage);
$asAdmin = $ceremony->readEscrow('alice', 'case-alice', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey'], ['contact_secours']);
$asAdmin['contact_secours'] === ESCROW_PLAINTEXT['contact_secours']
    ? ok('the admin opens the escrow through the ceremony → same contact_secours') : ko('admin escrow read wrong');

// -----------------------------------------------------------------------------

section('COMPARTMENTALISATION: escrow_key cannot read the private zone');

// Obtain the escrow_key the way the admin does, then try it on a private field.
$escrowRecord = $storage->loadEscrow('alice');
$unlockedEscrow = openWrapAdmin($escrowRecord, $sk, $admin['publicKey']);
$escrowKey = heldKey($unlockedEscrow);

$privCipher = $storage->loadFields('alice', ['mots_de_passe'])['mots_de_passe'];
// The witness first: the same blob, the same AAD, the right key — it opens. So
// when the escrow key fails below, the key is the only thing that differs, and
// the check cannot go green on a wrong AAD.
FieldCrypter::decrypt($session, 'mots_de_passe', $privCipher) === 'forum:hunter2'
    ? ok('witness: the private field opens with the master key and its own AAD')
    : ko('witness failed — the compartment check below would prove nothing');
try {
    Primitives::decrypt(
        EncryptedBlob::fromBase64($privCipher),
        $escrowKey,
        FieldCrypter::aadFor('alice', 'mots_de_passe')
    );
    ko('escrow_key decrypted a private field — COMPARTMENTALISATION BROKEN (CRITICAL)');
} catch (RuntimeException) {
    ok('escrow_key cannot decrypt private field (mots_de_passe) — private zone out of admin reach');
}
$unlockedEscrow->lock();
sodium_memzero($sk);

// -----------------------------------------------------------------------------

section('The ceremony: verdict and intention first, the witness before the unsealing');

/** Keeps what it is handed, or fails, as a witness that cannot reach its server would. */
final class BenchWitness implements Pierroons\SelfDataGuard\Escrow\HeadWitness
{
    public ?array $got = null;
    public function __construct(private readonly bool $reachable = true) {}
    public function witness(int $seq, string $hmac): void
    {
        if (!$this->reachable) {
            throw new RuntimeException('witness unreachable');
        }
        $this->got = ['seq' => $seq, 'hmac' => $hmac];
    }
}

$cLog = sys_get_temp_dir() . '/sdg-ceremony-bench-' . bin2hex(random_bytes(4)) . '.log';
register_shutdown_function(static fn () => @unlink($cLog));
$entries = static fn (): array => (new AuditLog($cLog, str_repeat('s', 32)))->readAll();

$seen = new BenchWitness();
benchCeremony($storage, Verdict::Open, $seen, $cLog)
    ->readEscrow('alice', 'case-1', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey'], ['contact_secours']);
$log = $entries();
($log[0]['event']['action'] ?? null) === 'escrow-unlock-intent' && $seen->got === ['seq' => $log[0]['seq'], 'hmac' => $log[0]['hmac']]
    && ($log[1]['event']['action'] ?? null) === 'escrow-unlock'
    ? ok('the witness is handed the head of the intention — before the act is written')
    : ko('the witness did not get the intention\'s head', json_encode([$seen->got, $log]));

$blind = benchCeremony($storage, Verdict::Open, new BenchWitness(false), $cLog);
try {
    $blind->readEscrow('alice', 'case-2', $admin['sealedSecret'], 'not even the passphrase', $admin['publicKey']);
    ko('the ceremony went on without its witness');
} catch (RuntimeException $e) {
    $log = $entries();
    str_contains($e->getMessage(), 'nothing is unsealed') && (end($log)['event']['reason'] ?? null) === 'witness-failed'
        ? ok('a witness that fails stops the ceremony before the unsealing — the wrong passphrase was never tried — and the log says why')
        : ko('wrong failure', $e->getMessage());
}

foreach ([Verdict::Closed, Verdict::Expired, Verdict::Unknown, Verdict::Unavailable] as $verdict) {
    try {
        benchCeremony($storage, $verdict, null, $cLog)
            ->readEscrow('alice', 'case-3', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']);
        ko("a {$verdict->value} verdict unsealed the escrow");
    } catch (EscrowRefusedException $e) {
        $all  = $entries();
        $last = end($all);
        $e->verdict === $verdict && ($last['event']['verdict'] ?? null) === $verdict->value
            ? ok("verdict {$verdict->value}: refused, and logged as given")
            : ko("verdict {$verdict->value}: refused, but not logged as given", json_encode($last));
    }
}

section('Cold seizure: wrong admin key cannot open the sealed escrow');

$escrowRecord = $storage->loadEscrow('alice');
$attackerKp   = sodium_crypto_box_keypair();
$attackerSk   = sodium_crypto_box_secretkey($attackerKp);
$attackerPk   = base64_encode(sodium_crypto_box_publickey($attackerKp));
try {
    openWrapAdmin($escrowRecord, $attackerSk, $attackerPk);
    ko('sealed escrow opened with attacker key (CRITICAL)');
} catch (RuntimeException) {
    ok('sealed escrow refuses any key but the admin recovery key');
}

// -----------------------------------------------------------------------------

section('At-rest: escrow ciphertext leaks no plaintext');

$rawEscrow = $storage->loadEscrowFields('alice');

// Nothing to read is not the same as nothing to find: an empty set would walk
// the loop below without a single comparison and still report opacity.
count($rawEscrow) === count(ESCROW_PLAINTEXT)
    ? ok('at-rest inspection has every enrolled field to read (' . count($rawEscrow) . ')')
    : ko('nothing to inspect at rest', count($rawEscrow) . ' stored vs ' . count(ESCROW_PLAINTEXT) . ' enrolled');

$leaked = [];
foreach ($rawEscrow as $field => $ct) {
    foreach (ESCROW_PLAINTEXT as $plaintext) {
        if (str_contains($ct, $plaintext)) {
            $leaked[] = $field;
        }
    }
}
$leaked === []
    ? ok('escrow fields are opaque at rest (no plaintext)')
    : ko('escrow plaintext leaked at rest', implode(', ', array_unique($leaked)));

// -----------------------------------------------------------------------------

section('The admin passphrase has a floor when sealing, not when unsealing');

try {
    SelfDataGuard::generateAdminRecoveryKey(str_repeat('a', UserVault::PASSWORD_MIN_LEN - 1));
    ko('an admin key was sealed under a passphrase below the floor');
} catch (InvalidArgumentException) {
    ok('sealing under a passphrase below the floor is refused');
}
// A key sealed in the pre-0.6.0 format under a short passphrase still opens.
$court = 'court';
$selCourt = Primitives::randomBytes(Primitives::SALT_LEN);
$cleCourt = Primitives::deriveFromPassword($court, $selCourt, Primitives::LEGACY_OPSLIMIT, Primitives::LEGACY_MEMLIMIT);
$skCourt = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
$scelleCourt = base64_encode($selCourt) . ':'
    . blobV2($skCourt, $cleCourt, AdminKey::SEAL_AAD)->toBase64();
unsealAdminKey($scelleCourt, $court) === $skCourt
    ? ok('a key sealed earlier under a short passphrase still unseals')
    : ko('the floor locked out a key sealed earlier');

section('The sealed admin key carries its Argon2id profile');

// The format before 0.6.0, "salt:blob", opens under the frozen legacy profile.
$selAncien = Primitives::randomBytes(Primitives::SALT_LEN);
$skAncien  = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
$scelleAncien = base64_encode($selAncien) . ':' . blobV2(
    $skAncien,
    Primitives::deriveFromPassword(ADMIN_PASS, $selAncien, Primitives::LEGACY_OPSLIMIT, Primitives::LEGACY_MEMLIMIT),
    AdminKey::SEAL_AAD
)->toBase64();
unsealAdminKey($scelleAncien, ADMIN_PASS) === $skAncien
    ? ok('a key sealed before 0.6.0 (salt:blob) still unseals')
    : ko('the pre-0.6.0 format no longer unseals');

// A key sealed under another profile opens by the profile it records.
// Another profile than the current one, within the floor and the ceiling.
$OPS = Primitives::ARGON2_OPSLIMIT + 1;
$MEM = Primitives::ARGON2_MEMLIMIT;
($OPS !== Primitives::ARGON2_OPSLIMIT || $MEM !== Primitives::ARGON2_MEMLIMIT)
    ? ok('fixture: the other profile differs from the current constants')
    : ko('fixture: the other profile equals the current constants — the next check proves nothing');
$selAutre = Primitives::randomBytes(Primitives::SALT_LEN);
$skAutre  = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
$scelleAutre = implode(':', [AdminKey::FORMAT_V2, $OPS, $MEM, base64_encode($selAutre), blobV2(
    $skAutre, Primitives::deriveFromPassword(ADMIN_PASS, $selAutre, $OPS, $MEM), AdminKey::SEAL_AAD
)->toBase64()]);
try {
    unsealAdminKey($scelleAutre, ADMIN_PASS) === $skAutre
        ? ok('a v2 key sealed under another profile unseals by the profile it records')
        : ko('the v2 key opened to another secret key');
} catch (Throwable $e) {
    ko('a v2 key sealed under another profile no longer unseals', $e->getMessage());
}

$t0 = microtime(true);
try {
    unsealAdminKey('v2:' . Primitives::PROFILE_CEILING_OPSLIMIT . ':' . (Primitives::PROFILE_CEILING_MEMLIMIT * 2) . ':c2FsdA==:YmxvYg==', 'n\'importe quoi');
    ko('an admin key claiming a profile above the ceiling was derived under');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'no version of SelfDataGuard writes') && microtime(true) - $t0 < 0.05
        ? ok('an admin key claiming a profile above the ceiling is refused before any derivation')
        : ko('wrong refusal, or after a derivation', $e->getMessage());
}

foreach ([
    'v9:3:67108864:' . base64_encode($selAutre) . ':SDG2.x' => 'a newer format version',
    'v2:trois:67108864:' . base64_encode($selAutre) . ':SDG2.x' => 'a v2 key with a non-numeric profile',
    'v2:3:' . base64_encode($selAutre) . ':SDG2.x' => 'a v2 key missing a field',
] as $scelle => $cas) {
    try {
        unsealAdminKey($scelle, ADMIN_PASS);
        ko("{$cas} was read");
    } catch (InvalidArgumentException $e) {
        ok("{$cas} is refused before any derivation");
    }
}
try {
    unsealAdminKey('v3:' . base64_encode($selAutre), ADMIN_PASS);
    ko('a two-field v3 key was read');
} catch (InvalidArgumentException $e) {
    str_contains($e->getMessage(), 'newer')
        ? ok('a two-field newer version is named as such, not read as a salt')
        : ko('a two-field newer version was read as a salt', $e->getMessage());
}

section('wrap_admin names its account');

$skLien = unsealAdminKey($admin['sealedSecret'], ADMIN_PASS);
$ev = new EscrowVault();
EscrowVault::isAccountBound($storage->loadEscrow('alice'))
    ? ok('a new escrow seals wrap_admin with its account')
    : ko('a new escrow still seals the bare key');

$bob = $dg->register('bob', 'motdepasse-bob-01', 'bob-memorized');
$dg->setEscrowFields($bob, $admin['publicKey'], ['contact_secours' => 'bob-secours@example.org']);
$recAlice = $storage->loadEscrow('alice');
$recBob   = $storage->loadEscrow('bob');
$echange  = new EscrowRecord(
    userId: 'bob', wrapUser: $recBob->wrapUser, wrapAdmin: $recAlice->wrapAdmin,
    createdAt: $recBob->createdAt, updatedAt: $recBob->updatedAt
);
try {
    openWrapAdmin($echange, $skLien, $admin['publicKey']);
    ko("alice's wrap_admin, moved into bob's record, opened as bob's");
} catch (RuntimeException $e) {
    str_contains($e->getMessage(), 'another account')
        ? ok("alice's wrap_admin moved into bob's record is refused, named as such")
        : ko('refused for another reason', $e->getMessage());
}

// An escrow sealed before 0.6.0: wrap_admin holds the bare key.
$carol   = $dg->register('carol', 'motdepasse-carol-01', 'carol-memorized');
$neuf    = $ev->create($carol, $admin['publicKey']);
$ancien  = new EscrowRecord(
    userId: 'carol', wrapUser: $neuf['record']->wrapUser,
    wrapAdmin: sodium_crypto_box_seal(heldKey($neuf['unlocked']), base64_decode($admin['publicKey'])),
    createdAt: $neuf['record']->createdAt, updatedAt: $neuf['record']->updatedAt
);
$storage->createEscrow($carol->generation(), $ancien);
!EscrowVault::isAccountBound($storage->loadEscrow('carol'))
    ? ok('fixture: a pre-0.6.0 wrap_admin is told apart by its length')
    : ko('fixture: the pre-0.6.0 wrap_admin reads as bound');
heldKey(openWrapAdmin($storage->loadEscrow('carol'), $skLien, $admin['publicKey'])) === heldKey($neuf['unlocked'])
    ? ok('a pre-0.6.0 wrap_admin still opens')
    : ko('a pre-0.6.0 wrap_admin opened to another key');

$autreAdmin = SelfDataGuard::generateAdminRecoveryKey('une autre passphrase admin assez longue');
$dg->setEscrowFields($carol, $autreAdmin['publicKey'], ['contact_secours' => 'carol-secours@example.org']);
!EscrowVault::isAccountBound($storage->loadEscrow('carol'))
    && $ceremony->readEscrow('carol', 'case-carol', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']) === ['contact_secours' => 'carol-secours@example.org']
    ? ok('an escrow write leaves an existing wrap_admin as it is, whatever public key it is given')
    : ko('an escrow write re-sealed or lost the existing wrap_admin');
$dg->rebindEscrowAdmin($carol, $admin['publicKey'])
    && EscrowVault::isAccountBound($storage->loadEscrow('carol'))
    && $ceremony->readEscrow('carol', 'case-carol', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']) === ['contact_secours' => 'carol-secours@example.org']
    ? ok('rebindEscrowAdmin() re-seals a pre-0.6.0 wrap_admin with its account, and the admin still reads it')
    : ko('rebindEscrowAdmin() did not re-seal the pre-0.6.0 wrap_admin, or it no longer opens');
!$dg->rebindEscrowAdmin($carol, $autreAdmin['publicKey'])
    && $ceremony->readEscrow('carol', 'case-carol', $admin['sealedSecret'], ADMIN_PASS, $admin['publicKey']) === ['contact_secours' => 'carol-secours@example.org']
    ? ok('rebindEscrowAdmin() leaves a bound wrap_admin alone')
    : ko('rebindEscrowAdmin() re-sealed a wrap_admin that was already bound');
sodium_memzero($skLien);

section('Delete cascade removes escrow');

$dg->delete($dg->generationOf('alice'));
$storage->loadEscrow('alice') === null ? ok('deleteVault() cascades to escrow envelope') : ko('escrow envelope survived delete');
$storage->loadEscrowFields('alice') === [] ? ok('deleteVault() cascades to escrow fields') : ko('escrow fields survived delete');

echo "\n→ An unlocked escrow does not hand its key to a debug dump or a clone\n";

$leakEscrowKey = random_bytes(Primitives::KEY_LEN);
$openedEscrow  = new UnlockedEscrow('user-dump', $leakEscrowKey);
ob_start();
var_dump($openedEscrow);
$dumpedEscrow  = (string) ob_get_clean();
$printedEscrow = print_r($openedEscrow, true);
str_contains($dumpedEscrow, 'user-dump')
    ? ok('precondition: the dump still names the escrow it describes')
    : ko('precondition: the dump does not show the escrow — the checks below would measure nothing');
!str_contains($dumpedEscrow, $leakEscrowKey) && !str_contains($printedEscrow, $leakEscrowKey)
    ? ok('var_dump() and print_r() do not carry the escrow key bytes')
    : ko('the unwrapped escrow key is readable in a debug dump');
try {
    $copyEscrow = clone $openedEscrow;
    ko('a clone was made — it holds a copy of the key that lock() on the original never wipes');
} catch (RuntimeException) {
    ok('cloning is refused: a copy would escape lock()');
}

// -----------------------------------------------------------------------------

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Escrow Sanity — {$passes} passed, {$failures} failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

exit($failures === 0 ? 0 : 1);
