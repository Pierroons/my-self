# MySelf

> 🇫🇷 **[Lire cette page en français →](./README.fr.md)**

**Account recovery without email, without SMS, without a third party.**

Today, "forgot my password" means "we've sent a link to your email address".
Your inbox becomes the key to every account you own. And if you lose it, you
lose everything else with it.

MySelf is a set of modules exploring the other route: identity, data, law and
trade tools, with no external channel in the loop. The code is free software, it
runs on your own machine, and it is written to be read.

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

## One secret, three separated uses

This is what makes MySelf a set rather than a collection. A single memorized
passphrase serves three purposes, through a distinct **label** that changes the
effective salt: two keys derived from the same secret stay independent, and
compromising one does not open the others.

| Label | Purpose | Module |
|---|---|---|
| `auth` | regain access to an account | SelfRecover |
| `disk` | open a LUKS2 slot | SelfRecover-LUKS |
| `data-enc` | encrypt application data | SelfDataGuard |

One entry at boot opens the root volume, then the secondary volumes in cascade.

---

## The modules

Each module answers one question and deploys on its own. Those carrying security
code document their own threat model: what they protect, and what they do not.
SelfJustice and SelfAct have none — they are law databases kept up to date, not
protection mechanisms.

| Module | Question | Status |
|---|---|---|
| [SelfRecover](./bi-self/selfrecover/) | Who are you? | **v0.4.0** — usable, local demo |
| [SelfRecover-LUKS](./self-security/selfrecover-luks/) | What if the disk is stolen? | **v0.3.0** — deployed and documented |
| [SelfDataGuard](./self-security/selfdataguard/) | How do you protect data at rest? | **v0.1.0** — in service, 191 tests |
| [SelfJustice](./self-right/selfjustice/) | What does the law say? | **v0.1.0 beta** |
| [SelfAct](./self-right/selfact/) | How do you act on it? | **v0.1.2** — code, not online yet |
| [SelfModerate](./bi-self/selfmoderate/) | How do you behave? | concept |
| [SelfGuard](./self-security/selfguard/) | Destruction under coercion | concept — whitepaper, no code |
| [SelfKeyGuard](./self-security/selfkeyguard/) | Hardware 2FA | concept |

Modules marked "concept" are design documents, with no code.

No link to a hosted demo: everything self-hosts from this repository.

---

## Under the hood

What you will find opening `src/`, and which says more than any pitch:

| File | What's inside |
|---|---|
| [`Primitives.php`](./self-security/selfdataguard/src/Crypto/Primitives.php) | AAD bound to `userId`, `zeroize()` with a fallback when `sodium_memzero` is missing, `hash_equals`, final class with a private constructor |
| [`entropy.js`](./bi-self/selfrecover/tools/entropy-lab/engine/entropy.js) | Rejection sampling over `crypto.getRandomValues` |
| [`Recovery.php`](./bi-self/selfrecover/src/Recovery/Recovery.php) | Rate limit scoped to `username + IP`, dummy hash against the timing oracle |

The vendored libraries (`zxcvbn.js`, `hash-wasm-argon2.js`, EFF wordlist) are the
real ones, not demo stand-ins.

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

Written in continuous coworking with an AI assistant. Direction,
field experience and judgement calls are human; structure and review are shared.
