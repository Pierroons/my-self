# Threat model

> This file states what a reviewer needs before reading the code: where untrusted input enters, what
> is worth attacking, how to run the guards, and how a finding is rated. The adversary-by-adversary
> table — DB dump, stolen backup, insider DBA, RCE, coerced administrator — is in the
> [README](../README.md#threat-model-at-a-glance), and the full argument is in the whitepaper (§8).
> They are not repeated here.

## What this module does, and where untrusted input enters

SelfDataGuard encrypts application data per user: one random data key per account, sealed under each
of the user's secrets, any one of which opens it. The module holds the envelope, the field
encryption, a blind index for lookups, an administrative escrow compartment and its ceremony. It
holds no HTTP route, no session and no client-side code: the application calls it.

Untrusted input enters at six places:

1. **The user's secrets** — password, memorized word, passphrase. Arbitrary byte strings, no upper
   bound on length, supplied by the application's forms.
2. **Field names and field values.** A name reaches a database column and the blind-index
   derivation; `escrow` is reserved and refused. A value is arbitrary bytes of arbitrary length.
3. **Everything the storage returns.** Blobs, Argon2id profiles, vault generations, blind indexes,
   profile seals, escrow rows, archive packages. The library verifies what the format lets it verify —
   the AAD binds a blob to its context, the profile seal fixes the Argon2id cost, the generation names
   every write — and **an adversary who can write one row controls this input entirely**: several of
   the limits below are exactly that, a restored revision included.
4. **The audit log on disk**, and the anchor (`seq:hmac`) an operator types back in. A crash, a cut
   write, an altered line and a truncated tail are four different inputs here, and the log
   distinguishes them.
5. **The ceremony's operator files** (`DATAGUARD_ARBITER_FILE`, `DATAGUARD_WITNESS_FILE`) and the
   `Verdict` returned by an arbiter the integrator writes. No arbiter ships: one that did would say
   yes to every deployment.
6. **The `userId`.** It comes from the application, never from the module, and it is the key under
   which everything else is found.

## Components that matter most, and least

**Most** — a defect here reads or forges user data:

- `src/Crypto/Primitives.php` — XChaCha20-Poly1305 and Argon2id, the AAD that binds a blob to its
  context, and the v3 blob format.
- `src/Vault/UserVault.php` — wrapping and unwrapping the data key under each lock, and the
  generation that every write names.
- `src/Vault/ProfileSeal.php` — the seal that fixes a vault's Argon2id profile; without it, lowering
  the profile in a row makes the vault cheap to attack offline.
- `src/Fields/BlindIndex.php` — derived under a **server-side secret shared by every account**. That
  key is the one piece whose loss locks everything and whose leak makes enumerable indexed values
  findable by dictionary across all accounts.
- `src/Escrow/` — the compartment with its own permanent key, the ceremony, and the signed log.

**Secondary** — `src/SelfDataGuard.php` (orchestration), the exception hierarchy, the in-place
migrations from 0.4.x–0.6.x.

**Out of scope, deliberately** — libsodium and OpenSSL; PDO and the database engine; the integrator's
own code, including its session handling and its arbiter; `demo/selfdataguard/`, which is a reference
integration and not hardened for production; and `demo/lab/`, which uses only `Primitives` under a
server key and promises nothing per user.

## How to exercise it

Everything runs offline, on PHP 8.1 and above, with no service and no network. Each suite is
self-contained and exits non-zero on the first failure.

| what | how |
|---|---|
| the library, suite by suite | `php self-security/selfdataguard/tests/sanity_<name>.php` — the vault, the fields, the keys, the escrow, the archive, the audit log, the ceremony, the storage, the façade, the primitives, the migrations, the conformance suite and the frozen vectors. The README states the current count; `scripts/check-portes.sh` replays every suite and checks each total still matches the count the CI workflow asserts |
| frozen test vectors | `tests/vecteurs-argon2.json`, recomputed in CI by a second implementation (argon2-cffi) through `tests/vecteurs_argon2.py`. The three lock keys are pinned: a derivation that drifts locks out every existing vault, and no other check would see it |
| **your own storage** | `php bin/storage-conformance.php` against an implementation of `StorageInterface`. It is the contract made executable: generation checks inside the write's own transaction, the unique blind index, the archive swap, a frozen vault refusing writes, concurrent swaps. `tests/storage_doubles.php` carries deliberately broken storages, each of which must make it go red |
| the ceremony, end to end | `php bin/escrow-ceremony.php`, with `verify-log` and `verify-log --ancre seq:hmac`. Without an anchor, verification cannot see that the end of the log was cut off — that is the limit the anchor closes, and it is checked as such |
| the guards themselves | `scripts/check-canaris.sh` plants, in the code, the very defect each CI guard claims to catch and requires it to go red. ⚠️ It mutates files of the repository and restores them: run it on a clean tree, and on one tree at a time |

A guard that has never been seen failing does not differ from a guard that measures nothing: both
report green. Two mechanisms force the red here, and both are rerunnable: `scripts/check-canaris.sh`
plants each CI guard's own defect, and `storage_doubles.php` carries storages deliberately broken so
that `storage-conformance.php` refuses them.

## How we rate severity

The scale is written once for the whole repository, in [`GRAVITE.md`](../../../GRAVITE.md) at its
root, and it is the one used in audit reports and review findings. Four levels — **Critical**, a
secret or a user's data readable by someone holding nothing, a third party durably depriving a holder
of their account, user data destroyed with no human decision, or an administrative power exercised
without an unforgeable trace; **High**, exploitable with a realistic prerequisite — one database
write, an already-leaked secret, an integrator following the contract to the letter, a configuration
the documentation recommends — or an announced security property the code does not hold; **Medium**,
degrading without opening; **Low**, debt: the property holds, but nothing enforces it.

Three rules specific to this project can each raise a finding on their own: the **last-resort rule** (a defect on the last door open to
someone who did nothing wrong goes up one level), the **conforming-integrator rule** (what an
integrator following the documented contract can break is High at minimum), and the **docblock rule**
(a property held only by a comment is rated by how loudly its violation fails).

Applied to this module, answering outright the questions the template asks:

- **One database write is a realistic prerequisite here, not a theoretical one.** A finding that
  needs an attacker to write a row is High, not Low: the escrow seal, the restored vault row and the
  blind index all live behind that assumption.
- **An offline oracle is Medium.** Its cost per guess is the protection; that is stated, not
  promised. It becomes High when the cost can be lowered from the database — which is why the
  Argon2id profile is sealed.
- **A property documented but unenforced is Low**, however grave it would be if it broke — and it
  becomes High the day a README, a whitepaper or a docblock claims the code holds it. Version 0.7.0
  exists because a security property held by a docblock is not held.
- **Anything that makes a vault unopenable for its holder is at least High**, even with no attacker:
  losing data the user cannot recover is the failure this module exists to prevent.

## Anything to leave alone

These are declared choices. Each is documented in the README, the whitepaper or the CHANGELOG, and
re-reporting one costs a round trip — more than once it has cost a correction that broke a published
promise instead.

- **Lite is the only mode in service.** The server decrypts during a user's session, and a key read
  during a compromise stays valid afterwards. Hybrid and Full are specified in the whitepaper and
  **not written**: `wrap_admin` is `null`, and the module carries no client-side code. "The server can
  decrypt" is the mode, not a defect.
- **The escrow key is permanent, by design.** Whoever coerces the holder of its passphrase obtains,
  ceremony after ceremony, the escrow compartment of every account that has one — never the private
  vault. Against the administrator holding the sealed key file and its passphrase, the ceremony holds
  nothing: they open the escrow outside the library.
- **The admin seal does not authenticate the escrow's content.** Whoever writes to the database can,
  with the public key alone, seal and fill a new compartment. The seal names its account and is
  refused in another's row; that is all it claims.
- **No padding.** A ciphertext carries none, so a dump leaks each value's length, each field's name
  and the date it last changed. Give fields neutral names, and pad to one length the fields that take
  only a few values.
- **Response time tells whether an account exists**, and whether its vault is sealed yet. Not which
  locks it carries.
- **An old vault row put back by a database write opens with the secrets it carried**, a consumed
  passphrase included. Nothing in the vault distinguishes a restored revision: the defence is the
  integrity of the database and its backups.
- **The blind-index key is a single point of loss.** Lose it and nothing opens until it is back; keep
  it with the database's backups, and know that a backup holding both makes enumerable indexed values
  findable by dictionary across every account.
- **This module has not been audited by an external cryptographer.** Its design is verified by its
  author and by the readers of this repository, and by no one else. A community audit is planned
  before v1.0.0. Saying so is not a finding.
