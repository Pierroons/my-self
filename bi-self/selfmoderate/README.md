# SelfModerate

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Autonomous community moderation engine through social reasoning**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.0](https://img.shields.io/badge/status-v0.1.0%20whitepaper-orange.svg)](#status)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../selfrecover/)
[![Self-hosted](https://img.shields.io/badge/self--hosted-Raspberry%20Pi-blue.svg)](#)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> *The most effective moderation isn't imposed. It emerges naturally when the system is well designed.*

Part of [Bi-Self](../README.md) — can also be used standalone.

## What is it?

SelfModerate is a moderation engine that lets online communities self-regulate without dedicated moderators. Instead of a single admin deciding who gets muted or banned, the community's natural social dynamics do the work.

**Core principle:** You play with someone, you rate them. If you're toxic, nobody wants to play with you. Social isolation is the sanction. Naturally.

## How it works

### Vote system
- Votes are tied to **accepted invitations** (real interactions, not anonymous reports)
- 👍 (+1) or 👎 (-1) with a mandatory reason
- Voting is a **recommendation, not an obligation** — it helps recognize good teammates or flag problematic behavior
- Configurable reasons per platform (toxic, no-show, cheating, good teammate, skilled...)
- Anonymous votes: the target sees their score and reasons, not who voted

### Reputation score
- Every user starts at **20** (configurable)
- Score is capped at **30** (configurable) — no hoarding social credit
- Going up is slow, going down is fast
- Passive regeneration: +1/week if score drops below 5

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
- 3 months clean: full reset (score + strikes)

### Anti-manipulation
- **Anti-Sybil**: SelfRecover integration (optional) + 7-day cooldown on new accounts
- **Pack voting**: cross-reference invitations and votes to detect coordinated downvotes
- **Upvote farming**: mutual positive votes blocked after 3 occurrences in 2 months
- **Cross-voting**: A vs B and B vs A on same invitation → both cancelled
- **Victim protection**: flagged abuse suspends the ban for admin review

## Documentation

- [Technical whitepaper (FR)](./docs/) — coming soon (DOCX available)
- Threat model — coming soon

## Status

🟡 **Concept phase** — whitepaper written, demo in development.

## License

AGPL-3.0-or-later — see the root [`LICENSE`](../../LICENSE).

## Author

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
