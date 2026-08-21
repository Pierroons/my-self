# SelfModerate

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Autonomous community moderation engine through social reasoning**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.2.0](https://img.shields.io/badge/status-v0.2.0-yellow.svg)](#status)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../selfrecover/)
[![Self-hosted](https://img.shields.io/badge/self--hosted-yes-blue.svg)](#)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> *The most effective moderation isn't imposed. It emerges naturally when the system is well designed.*

Part of [Bi-Self](../README.md) — can also be used standalone.

## What is it?

SelfModerate is a moderation engine that lets online communities self-regulate without dedicated moderators. Instead of a single admin deciding who gets muted or banned, the community's natural social dynamics do the work.

**Core principle:** You play with someone, you rate them. If you're toxic, nobody wants to play with you. Social isolation is the sanction. Naturally.

## How it works

> **This page describes the target design.** The engine lives in
> [`src/Moderate.php`](./src/Moderate.php) and covers part of what follows;
> [`demo/lab/`](../../demo/lab/) imports and uses it. What is missing is marked
> *not implemented yet*; what is half-kept, *partial*.


### Vote system
- Votes are tied to **accepted invitations** (real interactions, not anonymous reports) — *partial: the lab is a forum and has no invitations*
- 👍 (+1) or 👎 (-1) with a mandatory reason — *partial: the duo demo never validates the reason, and the lab has no such field*
- Voting is a **recommendation, not an obligation** — it helps recognize good teammates or flag problematic behavior
- Configurable reasons per platform (toxic, no-show, cheating, good teammate, skilled...) — *not implemented yet*
- Anonymous votes: the target sees their score and reasons, not who voted — *partial: anonymity holds, but no screen returns their reasons to the target*

### Reputation score
- Every user starts at **20** (configurable)
- Score is capped at **30** (configurable) — no hoarding social credit
- Going up is slow, going down is fast — *not implemented yet: a vote is worth ±1 either way*
- Passive regeneration: +1/week if score drops below 5 — *not implemented yet*

### Self-regulating loop
```
Toxic player → receives downvotes → score drops
→ nobody wants to play with them → no accepted invitations
→ can't vote (no invitation = no vote right) → socially isolated
→ only option: lay low and rebuild
```

The punishment isn't technical — it's social.

### Sanction escalation
- Score < 5 → **loss of voting rights**
- Score = 0 → **temporary ban** (24h → 7d → 30d, progressive)
- 3 temporary bans executed → **permanent ban**
- After a served ban: score resets to 20 (second chance), strike count preserved
- 3 months clean: full reset (score + strikes) — *partial: the duo demo clears everyone's strikes at once, the lab does not do it at all*

### Anti-manipulation
- **Anti-Sybil**: SelfRecover integration (optional) + 7-day cooldown on new accounts — *partial: anti-Sybil is there, the cooldown is not*
- **Pack voting**: cross-reference invitations and votes to detect coordinated downvotes — *not implemented yet: detection rests on a time threshold, without checking that the voters know each other*
- **Upvote farming**: mutual positive votes blocked after 3 occurrences in 2 months
- **Cross-voting**: A vs B and B vs A on same invitation → both cancelled — *not implemented yet*
- **Victim protection**: flagged abuse suspends the ban for admin review — *not implemented yet*

## Documentation

- Technical whitepaper (FR) — written, not yet published in this repository
- Threat model — to be written

## Status

🟢 **v0.2.0** — the engine is here, under `src/`, and the lab imports it the way
it imports SelfRecover and SelfDataGuard. Six protocol mechanisms remain to be
written; they are marked in the lists above.

Checks: [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
— eight, each seen failing first. They still live on the lab side because they
need a database schema; they will move to `tests/` once the module carries one.

## License

AGPL-3.0-or-later — see the root [`LICENSE`](../../LICENSE).

## Author

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
