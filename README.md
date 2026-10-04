# MySelf

> 🇫🇷 **[Lire cette page en français →](./README.fr.md)**

**Tools that need no one but you.**

Getting an account back goes through an email address. Knowing your rights goes
through someone who knows them. Protecting your data goes through a service that
holds it. Every time, a third party sits in the loop — and that third party can
shut down, get it wrong, be breached, or simply stop answering.

MySelf is a set of modules exploring the other way, across four grounds:
identity, data, law, and collective life. The code is free, it runs on your own
machine, and it is written to be read.

---

## The modules

Each module answers one question and deploys on its own. Its README says the rest:
what it does, how to install it, and what it does not protect.

| Module | Question | Status |
|---|---|---|
| [SelfRecover](./bi-self/selfrecover/) | Who are you? | **v0.9.0** — library + deployed implementation |
| [SelfRecover-LUKS](./self-security/selfrecover-luks/) | What if the disk is stolen? | **v0.6.2** — documented, hex key |
| [SelfDataGuard](./self-security/selfdataguard/) | How do you protect data at rest? | **v0.6.0** — available, three locks, archiving at level 3, an escrow bound to its account |
| [SelfJustice](./self-right/selfjustice/) | What does the law say? | **v0.4.2 beta** — French law in force (LEGI), EU/ECHR texts, administrative case law |
| [SelfAct](./self-right/selfact/) | How do you act on it? | **v0.1.3 beta** — over 1,800 official resources |
| [SelfModerate](./bi-self/selfmoderate/) | How do you behave? | **v0.4.0** — linked voters, recovery, vote reason, graduated ban traced to a journal; 2 mechanisms not yet coded |

Those carrying security code document their own threat model; SelfModerate's is
still to be written. SelfJustice and SelfAct hold no user secret and have no
threat model yet.

Every line links to code you can read and run. No link to a hosted demo:
everything self-hosts from this repository.

<!-- ecosysteme:selffarm-lite:debut — produit par scripts/check-ecosysteme.sh --ecrire -->
**SelfFarm-Lite**, the farm application layer of the ecosystem, lives in its own repository: [Pierroons/selffarm-lite](https://github.com/Pierroons/selffarm-lite) — **v0.4.10**, released.
<!-- ecosysteme:selffarm-lite:fin -->

---

## What makes it a set

Not one master secret that opens everything — that would be the opposite of the
point. What the modules share is the discipline: distinct inputs for each use,
and two primitives, each kept to a role it is never asked to leave.

**HMAC-SHA256 binds and masks.** The memorized word proves you know it without
ever being sent: what travels is
`HMAC(word, material | version + account salt)`, where the integrator picks the
material. With the hostname, read in the page and never received from the
network, the same word yields a different fingerprint on every service, and a
page copied as-is and served elsewhere produces nothing usable. With a fixed
label, chosen so that accounts survive a change of address, that protection is
gone. Neither choice stops a page modified to read the word: it reads it. The
fingerprint is 64 characters whether the word has four letters or forty, and two
services comparing their databases would have to guess the word to recognize it.

**Argon2id at 64 MiB slows things down.** That is the one thing HMAC does not
do: it costs nothing to compute. Wherever an attacker works offline — a stolen
disk, an encrypted vault, a dumped database — no attempt counter can stop them,
and the price of one guess is all that is left.

| Module | Secret | Scope | What separates it |
|---|---|---|---|
| SelfRecover | diceware passphrase (L1) | per account | Argon2id internal salt |
| SelfRecover | memorized word (L2) | per account | hostname or label + account salt |
| SelfRecover-LUKS | diceware passphrase | per machine | label `disk` |
| SelfDataGuard | password + memorized word + passphrase | per user | contexts `/dataguard` and `/dataguard/passphrase` |

Compromising one does not open the others, with two exceptions worth stating:
SelfRecover and SelfDataGuard may share the memorized word (L2) and the passphrase
(L1): the derivation keeps their hashes apart, not the secrets themselves. Neither
library imports the other: pairing them is the integrator's job, described in the
SelfDataGuard README, and no demo in this repository wires it yet. A
hash stolen from one side's database does not open the other. The word itself, if stolen, opens
the SelfDataGuard vault on its own — on the SelfRecover side it still needs the
*recovery code*. A stolen passphrase opens both, until its first use — yours or
the thief's —, which replaces it.
A vault sealed by the password alone does not survive a SelfRecover recovery:
see [the warning in the SelfDataGuard README](./self-security/selfdataguard/README.md#coupling-with-selfrecover).
The detail of each derivation is in the README of the module concerned.

### A salt is not a secret

It sits in the clear next to the fingerprint, and anyone reading the database
sees it. That is not an oversight: a salt is not there to hide, it is there to
**separate**.

Without it, two people choosing the same word produce the same fingerprint.
Three consequences, all bad: the database reveals who shares a secret with whom;
a table computed once works against every account on the service; and cracking
one fingerprint cracks them all at once.

With a salt drawn at random per account — 16 bytes, generated by your browser —
each fingerprint becomes a separate problem again. The work no longer pools: it
has to be redone person by person.

**A salt per service would not be enough.** It would move the constant rather
than salt anything: every account on the service would still share it. That is
why the code requires one per account and refuses to derive without it, rather
than quietly accepting an empty one.

And what it does not do: it makes no single attempt more expensive. Attacking one
specific account stays possible if its secret is weak — Argon2id is what makes
each guess costly, and the salt is what stops anyone from making one guess for
everybody.

---

## Try it

Everything runs locally, with no account to create. You need PHP 8.1 or later,
with `sodium`, `pdo_sqlite`, `mbstring` and `openssl`, and Composer for the forum.

**Watch the encrypted database live** — split screen: the application on one
side, the raw database content on the other.

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/demo/selfdataguard
./run.sh
```

**Watch the modules work together** — a forum where sign-up goes through
SelfRecover and private messages are encrypted at rest with SelfDataGuard's
primitives, under a server key. In a second terminal, from the directory you
cloned into:

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

Before 19 April 2026, MySelf was licensed under MIT: releases published before
that date remain available under their original terms. Details in
[COPYRIGHT](./COPYRIGHT).

---

## Author

Written in continuous coworking with an AI assistant. Direction, hands-on
experience and judgement calls are human; structure and review are shared.
