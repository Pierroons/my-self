# SelfModerate

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Autonomous community moderation engine through social reasoning**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.4.0](https://img.shields.io/badge/status-v0.4.0-yellow.svg)](#status)
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
- Score = 0 → **temporary ban, graduated**: 24 h → 7 d → 30 d in service (`Config::prod()`), 2 → 10 → 30 min in demonstration (`Config::demo()`). The last tier **repeats**: the machine never pronounces a definitive exclusion; beyond it, the arbiter decides
- **The automatic ban only falls when a journal is plugged in** (`setJournal()`). Without a journal, a zero reputation raises a flag and an arbiter decides. With one, every start, end and lift of a ban is written to the journal, **before** the database: a failure leaves a trace without effect, never a penalty without trace
- A running penalty **is not extended** by the next opposing vote
- After a served automatic ban: score back to **20**, strikes kept — the next ban will be longer
- What a ban blocks beyond voting is the platform's call (`estBanni()`). The lab also blocks posting, and leaves login and private messages open
- 3 months without incident: strikes reset — *not implemented yet*

### Arbiter actions
- **Ban**, **lift**, **maintain**: each carries the arbiter's name and is written to the journal. Banning requires a reason, checked like a downvote's: the banned person must be able to read what they are blamed for
- **Floor**: before one third of the **running** penalty (`Config::$plancherFraction`), a lift requires a written reason and is recorded as an **early lift**, with the time that remained. One third of the running penalty, never of the maximum tier: otherwise the floor of a one-day first ban would last ten days. The floor does not close favouritism; it makes it costly, because it leaves a signed trace
- **Never a floor on a detected pack**: when the engine recognises that the ban came from a pack, it lifts it on its own and records it. That lift repairs an injustice; it grants none. An arbiter's ban is not lifted that way: it did not come from the votes

### Escalation for pack voters
The rank belongs to the **voter**, not to the target. Counted on the target, a
group switching prey would stay at the first tier forever, and a victim hit by
several groups would get first-time offenders punished.

| Episode | What it costs |
|---|---|
| 1st | **Nothing** — the votes are cancelled, as at every tier, and the voter is warned |
| 2nd | Voting rights **suspended for 7 days** |
| 3rd | Suspended for **30 days** and **5 reputation points** off |
| 4th and beyond | Suspension kept and **human review** — no automatic exclusion |

- The first episode costs nothing because the pack criterion is the reciprocal
  private message: **two friends reacting in good faith to the same obnoxious
  post meet it**. Cancelling their votes is reversible and protects the target;
  taking their voting rights away is not. Repetition is what earns the penalty
- The suspension lives in **its own counter**, not in the reputation-driven
  voting right: otherwise recovery would lift it after a few quiet days, and the
  penalty would not last what it announces
- A voter climbs **one rank per 24 hours at most**. Without that bound, a group
  hitting three people in the same sweep would cross three tiers at once and
  nobody would ever see the warning. The extra targets are still recorded: the
  admin needs to see the scale
- The rank is visible **on one's own profile only**. A public badge would be one
  more penalty, decided by no one

### Anti-manipulation
- **Anti-Sybil**: a delay on new accounts, unless they have already posted: **24 hours** in service, matching the warm-up period described by [Bi-Self](../README.md), **2 minutes** in demonstration. The refusal states the remaining wait
- **Pack**: two voters **linked to each other** hitting the same target within 30 days → their votes are cancelled, the reputation restored, and the voters enter the escalation described above. Linkage propagates transitively — A–B and B–C linked form a pack of three, because a pack has a ringleader
- What links two accounts depends on the platform. On a forum: a **private message in each direction**, the closest equivalent to an accepted invitation. Requiring reciprocity stops a spammer from becoming invulnerable by writing to everyone. Message contents are **never read** — only who wrote to whom
- **Fast burst**: several voters with **no link at all** within a short window (5 min in service, 1 min in demonstration). That is not a pack, it is most often the same reaction to the same post: nothing is cancelled, the target goes to human review. Cancelling here would protect a post all the better for shocking more people at once
- **What neither one sees**: coordination organised elsewhere, between accounts that never wrote to each other on the platform. It lands as a fast burst — flagged, never cancelled
- **Farming**: beyond 3 positive votes from one voter to one member within 60 days, the next ones are neutralised; the same cap applies to negative votes, against the slow erosion of a patient voter who targets every post
- **Cross-voting**: A vs B and B vs A on same invitation → both cancelled — *not implemented yet*
- **Victim protection**: a recognised pack lifts the automatic ban it caused — *partial: a mere flag does not suspend a ban yet*

## Integrating

The engine is a static class; the host passes its `PDO` connection on every call.

| Call | Role |
|---|---|
| `setConfig(Config)` | the thresholds: `Config::demo()`, `Config::prod()` or your own. **Single source** of the thresholds: the engine reads them there, and so do your pages, through `Moderate::config()` |
| `setJournal(Journal)` | plugs in the journal, and with it the automatic ban. The [`Journal`](./src/Journal.php) interface lists the acts and their keys; writing must be durable and not rewritable, and an exception is never swallowed |
| `balayerBansEchus(PDO)` | closes expired penalties in one pass, for a scheduler. Without it, an end is noticed at the next read, and the journal carries the due time AND the moment it was noticed |
| `estBanni(PDO, id)` · `bansEnCours(PDO)` | what the host blocks, and what the arbiter must see: origin, reason, end, time left, floor |
| `adminBan(PDO, id, arbiter, reason)` · `adminPardon(PDO, id, arbiter, ?reason)` · `adminMaintenir(PDO, id, arbiter, reason)` | the arbiter actions, returning `['ok' => bool, 'message' => string]` |
| `dureeEnClair(seconds)` | a readable duration, units passed through your translator (`setTranslator()`) |

**Expected schema** — the lab's is the reference ([`demo/lab/schema.sql`](../../demo/lab/schema.sql)): `member_moderation` (reputation, strikes, voting right, `banned_until`, `ban_debut`, `ban_origine`, `ban_motif`, flag, recovery, vote suspension), `mod_votes`, `mod_pack_flags`, and three host tables read without being written: `accounts(id, username, created_at)`, `posts(id, account_id)`, `dm(sender_id, recipient_id, created_at)`. The engine writes SQLite SQL.

Dates in its messages follow the host's time zone (`date_default_timezone_set()`).

## Documentation

- Technical whitepaper (FR) — written, not yet published in this repository
- Threat model — to be written

## Status

🟢 **v0.4.0** — the engine is here, under `src/`, and the lab imports it the way
it imports SelfRecover and SelfDataGuard.

0.4.0 brings the automatic ban back, held in check: graduated, finite, journaled
end to end, liftable by an arbiter under a floor, and lifted on its own when a pack
is recognised. The thresholds have a single source, `Config`. **Breaking** since
0.3.0: the engine's threshold constants are removed (read `Moderate::config()`),
and `adminBan()` / `adminPardon()` take the arbiter and the reason. **Two mechanisms
remain to be written** — cross-voting, and the strikes reset after three months
without incident — plus two half-kept, all marked in the lists above.

Checks: [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
— forty-five, run by CI, each seen failing first: the mechanism is disabled,
the measurement retaken, the code restored. One of them measured nothing at its
first mutation — it computed its expectation from the very constant it watched,
and drifted along with it; it now reads a literal value. The five reason rules are exercised **separately**,
each case breaking only one: otherwise defence in depth catches the hole, the
check stays green, and nobody knows which rule still measures anything. They
still live on the lab side because they need a database schema; they will move
to `tests/` once the module carries one.

## License

AGPL-3.0-or-later — see the root [`LICENSE`](../../LICENSE).

## Author

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
