# MySelf

> 🇫🇷 **[Lire cette page en français →](./README.fr.md)**

**Account recovery without email, without SMS, without a third party.**

Today, "forgot my password" means "we've sent a link to your email address".
Your inbox becomes the key to every account you own. And if you lose it, you
lose everything else with it.

MySelf is a set of modules exploring the other route: tools for identity, data,
law and community life, with no external channel in the loop. The code is free
software, it runs on your own machine, and it is written to be read.

---

## Where the randomness comes from

Five dice, a list of 7776 words — exactly 6⁵.

| Passphrase | Entropy |
|---|---|
| 1 word (5 dice) | 12.9 bits |
| 4 words | 51.7 bits |
| 6 words | 77.5 bits |

Measurable, and not reproducible. A software generator produces a sequence
computable from its internal state; dice have no state.

The English list is the EFF one. The French list is a community translation:
there is no official list in French, this one settled in through use. Both hold
7776 entries, so the figures above hold in either language.
[The paper method is documented step by step](./bi-self/selfrecover/tools/entropy-lab/docs/diceware-method-en.pdf).

---

## One discipline, separate secrets

This is what makes MySelf a set rather than a collection. Not one master secret
that opens everything — that would be the opposite of the point. What the modules
share is the discipline: a domain separator in the salt, and Argon2id at 64 MiB
wherever a secret has to withstand an offline attack.

| Module | Secret | Scope | What separates it |
|---|---|---|---|
| SelfRecover | diceware passphrase (L1) | per account | Argon2id internal salt |
| SelfRecover | memorized word (L2) | per account | hostname + account salt |
| SelfRecover-LUKS | diceware passphrase | per machine | label `disk` |
| SelfDataGuard | password + memorized word | per user | context `/dataguard` |

Compromising one does not open the others — not because a label compartmentalizes
them, but because they are distinct secrets, derived separately.

### Why you can keep the same memorized word everywhere

The memorized word is the only secret you actually remember. It is meant to be
reused.

One server hosts three services, you have an account on all three, and you use the
same memorized word — `tree shoes` — on each. What the three of them store has
nothing in common:

    fingerprint = HMAC-SHA256(memorized word, hostname | version + account salt)

The **hostname** is read in the browser, never received from the server. Each
service therefore stores a different fingerprint of the same word — and a cloned
page served elsewhere derives from its own address: what it produces is worthless
against the real service.

The **salt** is drawn by your browser at sign-up, one per account. It separates
people: two users who pick the same word on the same service do not store the same
fingerprint.

The word itself never leaves your browser. None of the three servers receives it,
and none can replay another's fingerprint.

### The encrypted volume is a keyring

On a machine, a single entry opens the root volume at boot — and what that volume
holds opens the rest: the keys of the secondary volumes and, on a host that signs
its own kernels, the Secure Boot signing key. The disk does not only protect files,
it protects the keys that open others.

---

## The modules

Each module answers one question and deploys on its own. Those carrying security
code document their own threat model: what they protect, and what they do not.
SelfJustice and SelfAct have none — they are law databases kept up to date, not
protection mechanisms.

| Module | Question | Status |
|---|---|---|
| [SelfRecover](./bi-self/selfrecover/) | Who are you? | **v0.5.1** — library + deployed implementation |
| [SelfRecover-LUKS](./self-security/selfrecover-luks/) | What if the disk is stolen? | **v0.4.0** — deployed and documented, hex key |
| [SelfDataGuard](./self-security/selfdataguard/) | How do you protect data at rest? | **v0.3.0** — in service, 198 checks |
| [SelfJustice](./self-right/selfjustice/) | What does the law say? | **v0.3.0 beta** — housing, family and administrative law covered |
| [SelfAct](./self-right/selfact/) | How do you act on it? | **v0.1.2** — live, over 1,800 official procedures |
| [SelfModerate](./bi-self/selfmoderate/) | How do you behave? | **v0.3.0** — linked-voter cross-referencing, recovery, vote reason; 24 checks; 2 mechanisms missing |

Every line above links to code you can read and run. No link to a hosted demo:
everything self-hosts from this repository.

---

## Under the hood

What you will find opening `src/`, and which says more than any pitch:

| File | What's inside |
|---|---|
| [`Primitives.php`](./self-security/selfdataguard/src/Crypto/Primitives.php) | AAD bound to `userId`, `zeroize()` with a fallback when `sodium_memzero` is missing, `hash_equals`, final class with a private constructor |
| [`entropy.js`](./bi-self/selfrecover/tools/entropy-lab/engine/entropy.js) | Rejection sampling over `crypto.getRandomValues` |
| [`Recovery.php`](./bi-self/selfrecover/src/Recovery/Recovery.php) | Rate limit scoped to `username + IP`, dummy hash against the timing oracle |

The vendored libraries (`zxcvbn.js`, EFF wordlist) are the real ones, not demo
stand-ins. Browser-side Argon2id is not vendored: it is written here, in
`bi-self/selfrecover/client/argon2id.js`, and checked against libsodium's vectors.

---

## Try it

Everything runs locally, with no account to create. You need PHP 8.1 or later,
with `sodium`, `pdo_sqlite` and `mbstring`.

**Watch the encrypted database live** — split screen: the application on one
side, the raw database content on the other.

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/demo/selfdataguard
./run.sh
```

**Watch the modules work together** — a forum where sign-up goes through
SelfRecover and private messages are encrypted by SelfDataGuard.

```bash
cd my-self/demo/lab
composer install
php seed.php
php -S 127.0.0.1:8090 -t public
```

---

## Contributing

Code review is welcome. Audits — security, legal, accessibility — very welcome:
tell us what's wrong, including on this page.

The repository's [CONTRIBUTING.md](./CONTRIBUTING.md) covers every module;
SelfRecover has its own on top. Translations welcome, forks encouraged.

---

## License

[AGPL-3.0-or-later](./LICENSE) — strong copyleft. You can use it, modify it,
self-host it. If you build a service on top of it and offer it to others, you
publish your modifications too.

Before 19 April 2026, MySelf was licensed under MIT: releases published up to
that date remain available under their original terms. Details in
[COPYRIGHT](./COPYRIGHT).

---

## Author

Written in continuous coworking with an AI assistant. Direction, hands-on
experience and judgement calls are human; structure and review are shared.
