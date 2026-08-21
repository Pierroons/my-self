# SelfModerate

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Autonomous community moderation engine through social reasoning**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.3.0](https://img.shields.io/badge/status-v0.3.0-yellow.svg)](#status)
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
- 👍 (+1) or 👎 (-1) with a reason — **mandatory on a downvote, optional on an upvote**: a reason exists so the sanctioned person knows what they are faulted for, and a thumbs-up sanctions nobody
- The reason must **say something**: 40 characters, 3 distinct words, no word repeated more than twice, at least 12 different characters, no run of one character. Five rules because a single one is worked around — you reach any length by holding a key down
- Voting is a **recommendation, not an obligation** — it helps recognize good teammates or flag problematic behavior
- Configurable reasons per platform through `setReasonCodes()`; the default set fits a forum (off-topic, aggressive, misinformation, helpful to others, useful contribution, other)
- Anonymous votes: the target sees their score and reasons, not who voted. Reasons come back **dated to the day**, in a non-chronological order — to the second, cross-referenced with who was online, they would name their author. A limit no ordering lifts: on a single downvote received, the person often guesses who sent it

### Reputation score
- Every user starts at **20** (configurable)
- Score is capped at **30** (configurable) — no hoarding social credit
- Going up is slow, going down is fast: a downvote takes a point immediately, passive recovery gives one back per quiet interval
- **Recovery**: below 5 the state is set and the score climbs back on its own **up to 20**, its starting point — never beyond. Voting rights return at 5, the state lifts at 20
- The state is **visible**, on one's own profile and on the one others see: it announces that something went wrong, and that the score moves with patience rather than merit
- It is a **state, not a threshold**. Gating recovery on "score < 5" stops it exactly at the threshold that restores voting rights, leaving the account permanently on a knife edge

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
- **Pack**: two voters **linked to each other** hitting the same target within 30 days → their votes are cancelled and the reputation restored. Linkage propagates transitively — A–B and B–C linked form a pack of three, because a pack has a ringleader
- What links two accounts depends on the platform. On a forum: a **private message in each direction**, the closest equivalent to an accepted invitation. Requiring reciprocity stops a spammer from becoming invulnerable by writing to everyone. Message contents are **never read** — only who wrote to whom
- **Fast burst**: several voters with **no link at all** within the same minute. That is not a pack, it is most often the same reaction to the same post: nothing is cancelled, the target goes to human review. Cancelling here would protect a post all the better for shocking more people at once
- **What neither one sees**: coordination organised elsewhere, between accounts that never wrote to each other on the platform. It lands as a fast burst — flagged, never cancelled
- **Upvote farming**: mutual positive votes blocked after 3 occurrences in 2 months
- **Cross-voting**: A vs B and B vs A on same invitation → both cancelled — *not implemented yet*
- **Victim protection**: flagged abuse suspends the ban for admin review — *not implemented yet*

## Documentation

- Technical whitepaper (FR) — written, not yet published in this repository
- Threat model — to be written

## Status

🟢 **v0.3.0** — the engine is here, under `src/`, and the lab imports it the way
it imports SelfRecover and SelfDataGuard.

This version ships linked-voter cross-referencing, recovery, and the vote reason.
**Two mechanisms remain to be written** — cross-voting and victim protection —
plus three half-kept, all marked in the lists above.

Checks: [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
— sixteen, each seen failing first: the mechanism is disabled, the measurement
retaken, the code restored. The four reason rules are exercised **separately**,
each case breaking only one: otherwise defence in depth catches the hole, the
check stays green, and nobody knows which rule still measures anything. They
still live on the lab side because they need a database schema; they will move
to `tests/` once the module carries one.

## License

AGPL-3.0-or-later — see the root [`LICENSE`](../../LICENSE).

## Author

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
