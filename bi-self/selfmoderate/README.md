# SelfModerate

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Autonomous community moderation engine through social reasoning**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.8.0](https://img.shields.io/badge/status-v0.8.0-yellow.svg)](#status)
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
- The reason must **say something**: 40 characters, 3 distinct words, no word hammered on (twice is always allowed; beyond that a short word such as "of" or "my" stays within a third of the words, a word of four letters or more within a fifth), at least 12 different characters, no run of one character. Five rules because a single one is worked around — you reach any length by holding a key down. It also stays within 1000 characters: that bound protects the targeted person's page, where reasons are displayed
- Voting is a **recommendation, not an obligation** — it helps recognize good teammates or flag problematic behavior
- Configurable reasons per platform through `setReasonCodes()`; the default set fits a forum (off-topic, aggressive, misinformation, helpful to others, useful contribution, other)
- Anonymous votes: the target sees their score and reasons, not who voted. Reasons come back **dated to the day**, in a non-chronological order — to the second, cross-referenced with who was online, they would name their author. A limit no ordering lifts: on a single downvote received, the person often guesses who sent it. And rounding does not protect against a public figure: if the host shows a post's score or an author's reputation without a session, whoever reads them often enough dates each vote to the minute, and the post it targeted

### Reputation score
- Every user starts at **20** (configurable)
- Score is capped at **30** (configurable) — no hoarding social credit
- Going up is slow, going down is fast: a downvote takes a point immediately, passive recovery gives one back per quiet interval
- **Recovery**: below 5 the state is set and the score climbs back on its own **up to 20**, its starting point — never beyond. Voting rights return at 5, the state lifts at 20
- Recovery caps its own progress, not the score: a point earned from an upvote during recovery is kept: voting rights return as soon as the score reaches 5, and the state lifts as soon as it reaches 20, without waiting for the next recovery point
- The state is **visible** on one's own profile and to the arbiter: it announces that something went wrong, and that the score climbs back with patience rather than merit. Other members only see a reputation tier, never the sanction state; below the voting threshold, that tier carries no number
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
- Losing the right to vote is **flagged to the arbiter** (`droit_de_vote_perdu`, `signalPerteDuVote`); the flag falls when the right returns, or when the fall to the floor is flagged in turn
- Score = 0 → **temporary ban, graduated**: 24 h → 7 d → 30 d in service (`Config::prod()`), 2 → 10 → 30 min in demonstration (`Config::demo()`). The last tier **repeats**: the machine never pronounces a definitive exclusion; beyond it, the arbiter decides — only they can impose a ban with no end
- **The automatic ban only falls when a journal is plugged in** (`setJournal()`) and the host has not turned it off (`banAutomatique`), and never inside a host's transaction, which could still roll it back. Without a journal, a zero reputation raises a flag, and no arbiter action goes through: neither the machine nor an arbiter sanctions without a trace. With one, every start, end and lift of a ban is written to the journal, **before** the database: a failure leaves a trace without effect, never a penalty without trace. When the journal refuses to record the start of an automatic ban, the vote that brought the account down stands: the fall is flagged with the ban due (`ban_en_attente`), and the next negative vote tries the ban again. The end of a penalty is recorded by the first write that touches the account — a vote, an arbiter action, the sweep —, never by a read, which shows it as soon as it is due. When the journal refuses to record it, the closing is left pending: the write that tried still goes through, the arbiter is told (`journal_en_panne`, and the arbiter console shows it site-wide), and the closing happens once the journal is back. Until then the page shows the penalty as over, but only the recorded state allows a write: the account cannot vote, and the refusal says why — but `canVote()` does not say it in advance: only an attempt knows whether the journal is back; no arbiter action and no new ban goes through on it
- A running penalty **is not extended** by the next opposing vote; only an arbiter extends a ban, and only an arbiter's ban
- After a served automatic ban: score back to **20**, strikes kept — the next ban will be longer
- What a ban blocks beyond voting is the platform's call (`estBanni()`). The lab also blocks posting, and leaves login and private messages open
- 3 months without incident: strikes reset — *not implemented yet*

### Arbiter actions
- **Ban**, **lift**, **pardon**, **extend**, **maintain**, **close**: each carries the arbiter's name and is written to the journal, with the flags that were open and those it closes; with no journal plugged in, none goes through. Banning requires a reason, checked like a downvote's: the banned person must be able to read what they are blamed for; it closes no flag. **Lifting** ends the penalty: the account comes out as it would at the due time, state flags included — after an automatic ban, the reputation goes back to the start —, and the strikes stay, an arbiter's included: the next ban will be longer. **Pardoning** resets the ban, the reputation, the strikes, a voting suspension left by an earlier version and still running, and the pack rank — the history of episodes stays readable. The pardon closes what it undoes: the state flags, and the pack-recidivism flag when it erases the rank; a burst, a truncation or a wide voter stays to be closed, with a written reason. **Extending** pushes back the due time of a running arbiter's ban, reason required: same episode, no extra strike. An automatic ban is not extended: the arbiter lifts it, the repeat offence still counting, then imposes their own. **Maintaining** takes note of an automatic ban: its flag leaves the queue, the penalty runs; an arbiter's ban has nothing to maintain. **Closing** closes one flag, named, and touches nothing else, reason required
- **One flag per cause**: each open cause on an account has its own row, and none replaces another. A state flag (`reputation_zero`, `ban_auto`, `journal_en_panne`, `ban_en_attente`, `droit_de_vote_perdu`, `ban_sans_fin`) falls on its own when the state ends — `levee_en_attente`, a pack lift the journal refused, falls when its penalty's lift is done, by a pardon or by a closing, and outlives the end of the penalty; a behaviour flag (`salve_rapide`, `meute_recidive`, `votants_tronques`, `votant_etendu`, `bans_arbitre`) only falls when an arbiter closes it. A repeated fact raises its count; an action receives the flag as the console displayed it, with its detections, and refuses if a new vote has detected it again since — even when the detection folded a burst from other voters into the same fact: the arbiter does not close what they have not seen. The sweep, which rereads votes already detected, makes an action refuse only on a new fact — a pack that a link formed after the votes has just revealed. The wide voter and the truncation are the exceptions: for them, only a counted fact makes the action refuse, at most once per `balayagePeriodeSecondes` since the previous one, otherwise every vote would keep the arbiter from closing for as long as it lasts. The console shows one page per cause, oldest first: a flood of bursts fills its own page and pushes nothing else out of view
- **Repeated arbiter bans**: an arbiter's ban counts as an episode, and makes the next automatic ban heavier — a repeat offence is paid for, whoever recorded it. The ban start record says how many of its episodes came from an arbiter, and at the **2nd arbiter ban** on the same account (`bansArbitreSignal`), a `bans_arbitre` flag goes to the arbiters' queue: a chain no longer passes unflagged, but nothing stops the arbiter who banned from closing that flag themselves. The pardon, which resets the strikes, closes it; the lift, which keeps them, does not. These bans are counted from 0.7.0 on: a migrated database starts at zero
- **Floor**: before one third of the **running** penalty (`Config::$plancherFraction`), a lift requires a written reason and is recorded as an **early lift**, with the time that remained. One third of the running penalty, never of the maximum tier: otherwise the floor of a one-day first ban would last ten days. The floor does not close favouritism; it makes it costly, because it leaves a signed trace. It applies to lifting and pardoning alike
- **Ban with no end**: only an arbiter imposes one (`adminBan()`), or turns a running arbiter's ban into one (`adminProlonger()`); an automatic ban never becomes endless. Its due time is `Moderate::SANS_FIN`, 31/12/9999, and never comes; lifting or pardoning it always requires a written reason. A duration that would reach that date is refused: a ban that must not end is imposed with no end. It stays among the open flags (`ban_sans_fin`), out of the queue: the arbiter who imposed it has seen it
- **Never a floor on a detected pack**: when the engine recognises that the pack **caused** the ban — cancelled votes, cast before it started, had taken a point —, it lifts it on its own and records it. The lift gives back what those votes took and removes that ban's strike, nothing more. It repairs an injustice; it grants none. Votes cast during the ban do not lift it, and an arbiter's ban is not lifted that way: it did not come from the votes

### A pack weighs no more than a single member
The private-message link is the only sign of a pack, and it cannot tell coordination
apart from two friends reacting in good faith to the same obnoxious post, or from a link
**made up by the target itself** — a throwaway account someone answered once. A penalty
built on it would hit honest members, at a third party's choice: a member
posting one odious message a day would turn the people criticising them together
into repeat offenders. So the engine neutralises what the group adds, and punishes
no one.

| Episode | What happens |
|---|---|
| each | In the linked group hitting a target, the **first voter keeps their votes**, the others' are cancelled, and the target gets back what they took |
| 1st | Nothing more — the voter is warned on their own profile |
| 2nd and beyond | **Human review** (`meute_recidive`) — no suspension, no points off, no exclusion |

- The rank belongs to the **voter**, not to the target. Counted on the target, a
  group switching prey would stay at the first rank forever. A voter only enters an
  episode if they voted against the target since their last episode on it: a linked
  third party voting again does not raise the rank of someone who did nothing new
- A voter climbs **one rank per 24 hours at most**, counted from the **start** of the
  episode. Without that bound, a group hitting three people in the same sweep would
  cross three ranks at once; counted from the last detection, a pack striking
  less than 24 hours apart would stay at the first rank forever. The extra targets are
  still recorded: the arbiter sees the scale
- The rank is visible **on one's own profile only**. A public badge would be one
  more penalty, decided by no one
- **Limit: the oracle.** Any neutralisation shows in the target's reputation. A
  target controlling an account linked to a critic learns, when a vote is
  cancelled, that this critic voted against them
- **Limit: the pack that contributed.** Linked accounts that really brought a
  target down lift the ban they caused, even when acting on the target's behalf.
  They get that lift and the exact return of their votes, nothing else
- **Limit: the supporting pack.** Detection only reads negative votes. Linked
  accounts that all vote for the same member are neither seen nor neutralised, and
  carry them up to the cap, which makes them slower to bring down; the per-pair cap
  bounds each of them, not their number. Detection in both directions is planned — *not implemented yet*
- **Limit: the platform without private messages.** The link is only read from `dm`.
  Empty, detection sees no pack: each linked voter weighs their full weight, and a pack
  as much as all its members together
- **Limit: whether an account exists.** A reason is optional on a positive vote: a
  positive vote without a reason on an identifier goes through if it exists and is
  refused otherwise, and gives the target a point. It therefore tells whether an account
  exists; the per-pair cap bounds it, not the number of identifiers probed. A negative
  vote, on the other hand, requires a valid reason before looking at the target

### Anti-manipulation
- **Anti-Sybil**: a delay on new accounts, unless they have already posted: **24 hours** in service, matching the warm-up period described by [Bi-Self](../README.md), **2 minutes** in demonstration. The refusal states the remaining wait
- **Pack**: two voters **linked to each other** hitting the same target within 30 days → the first keeps their votes, the other's are cancelled, and the target gets back what they took. Linkage propagates transitively — A–B and B–C linked form a pack of three, because a pack has a ringleader; a member whose vote was already cancelled still links the others. Beyond 200 voters on a target (`meuteVotantsMax`), the most recent are examined and the target is flagged to the arbiter
- What links two accounts depends on the platform. On a forum: a **private message in each direction**, the closest equivalent to an accepted invitation. Requiring reciprocity stops a spammer from becoming invulnerable by writing to everyone. Message contents are **never read** — only who wrote to whom
- **Fast burst**: several voters with **no link at all** within a short window (5 min in service, 1 min in demonstration). That is not a pack, it is most often the same reaction to the same post: nothing is cancelled, the target goes to human review. Cancelling here would protect a post all the better for shocking more people at once. A burst's flag is never overwritten by a ban's
- **What neither one sees**: coordination organised elsewhere, between accounts that never wrote to each other on the platform, spacing its votes beyond the burst window. Nothing recognises it. What the arbiter sees of it: the **make-up of every automatic ban** — how many voters caused it over the pack window before it, how old their accounts are, over how long
- **Farming**: beyond 3 positive votes from one voter to one member within 60 days, the next ones are neutralised; the same cap applies to negative votes, against the slow erosion of a patient voter who targets every post. And a voter targets no more than 10 distinct members over the same window (`ciblesParVotantMax`): beyond that, their negative votes towards one more member are neutralised, and they are flagged to the arbiter
- **Cross-voting**: A vs B and B vs A on same invitation → both cancelled — *not implemented yet*
- **Victim protection**: a recognised pack lifts the automatic ban it caused — *partial: a mere flag does not suspend a ban yet*

## Integrating

The engine is a static class; the host passes its `PDO` connection on every call.

| Call | Role |
|---|---|
| `setConfig(Config)` | the thresholds: `Config::demo()`, `Config::prod()` or your own. **Single source** of the thresholds: the engine reads them there, and so do your pages, through `Moderate::config()` |
| `setJournal(Journal)` | plugs in the journal, and with it the automatic ban and the arbiter actions. The [`Journal`](./src/Journal.php) interface lists the acts and their keys; writing must be durable and not rewritable. A refusal always propagates, except when a vote or an arbiter action notices an expired penalty: its closing is then left pending and the arbiter is told (`journal_en_panne`); and except when the site sweep, a vote's or the scheduler's, lifts, for a pack, an account other than the vote's target: the lift waits for the next sweep, and the arbiter is told (`levee_en_attente`); and except at the start of an automatic ban: the vote that caused it stands, and the arbiter is told (`ban_en_attente`). A refusal is raised as an exception; an `\Error` raised on the sweep's path is a defect, and propagates. A host that could not build its journal serves neither votes nor actions: without a journal, a vote closes an expired penalty without recording it |
| `balayerBansEchus(PDO)` | records in one pass what time has done — penalty ends, to the journal first, and recovery climbs —, for a scheduler or the arbiter console, which reads the database and calls it before reading. Without a scheduler, the first negative vote of each sweep slot does it afterwards, for at most **50** accounts (`balayageFiletMax`), in its own request and under the write lock: a scheduler spares the voter that cost. A vote cast inside a host's transaction takes the slot without sweeping, and a host that votes inside its transactions needs a scheduler. Otherwise, an end is recorded by the first write that touches the account. After a first refusal from the journal, the pass stops calling it: it still records recovery climbs, leaves the ends to the next pass — where accounts already refused come last, the one refused longest ago first —, and the refusal surfaces at the end of the pass. An account still waiting for the migration from before 0.7.0 is skipped, and named at the end of the pass; a journal refusal in the same pass then stays flagged on its account (`journal_en_panne`), without being raised. Outside a host's transaction only: an end recorded there would reach the journal and outlive its rollback. The journal carries the due time AND the moment it was recorded |
| `applyVote(PDO, voter, type, target, value, ?reason)` | a vote, returning `ok`, `blocked` and `message` — never the target's reputation, which the profile does not show below the voting threshold. It is written all or nothing: in a transaction the engine opens (`BEGIN IMMEDIATE`) when the host has none, behind a savepoint in the host's otherwise, which then commits or rolls back the rest. Inside the host's transaction, it does not arm the automatic ban: a zero reputation is flagged there, as without a journal. A penalty closing or a pack lift done there, then rolled back with it, is done again on the next pass: the journal then carries the act twice. The first `applyVote()` of a host's transaction takes the write lock there, refusals included — except one for invalid parameters —, and the host holds it until its end; a deferred transaction that has already read can no longer take it under another writer, and fails at once: open it with `BEGIN IMMEDIATE`, or vote before any read. The first negative vote of a slot sweeps the site: what it finds on an account other than its target, voters in its target's pack included — a forgotten migration, a pack lift the journal refuses, any refusal raised as an exception — does not bring it down; that account is skipped, and the next sweep takes it up; a voter in its target's pack whose migration was forgotten is recorded at its rank without its repeat-offence flag; on another account, the episode waits for that migration, the victim's lift included, and if the victim's penalty expires first, it keeps its strike, with no flag — the pardon gives it back. A refused lift is flagged on the victim (`levee_en_attente`): if the penalty expires before the next sweep, that sweep no longer lifts it, and the arbiter's pardon gives the strike back — by resetting the whole account |
| `getReputation(PDO, id)` · `canVote(PDO, id)` | read without writing anything, neither to the database nor to the journal, and without taking a lock: the state returned is that of the moment — recovery and penalty end included —, even when the database does not carry them yet, and `fin_en_attente` says an end is waiting to be recorded. An account without a row reads as the starting values, without a row being created. A voter missing from `accounts`, or whose `created_at` is not an integer timestamp in seconds, does not vote, refused by `canVote()` as by `applyVote()`. The `member_moderation` columns are the state the engine has recorded: a host reads them, it does not write them |
| `estBanni(PDO, id)` · `bansEnCours(PDO)` | what the host blocks, and what the arbiter must see: origin, reason, end, time left, floor, and for an automatic ban the make-up of its fall; `sans_fin` marks a ban with no due time, which `Moderate::estSansFin()` also recognises from `banned_until` |
| `adminBan(PDO, id, arbiter, reason)` · `adminLever(PDO, id, arbiter, ?reason)` · `adminPardon(PDO, id, arbiter, ?reason)` · `adminProlonger(PDO, id, arbiter, reason, seconds)` · `adminMaintenir(PDO, id, arbiter, reason, ?vu)` · `adminClasser(PDO, id, arbiter, reason, ?vu)` | the arbiter actions, returning `['ok' => bool, 'message' => string]`. `adminBan()` also takes a duration, and `sansFin`; `adminProlonger()` only extends an arbiter's ban, by a duration or with `sansFin`; a duration that would reach `Moderate::SANS_FIN` is refused. Each one rereads the state and decides under the write lock, taken up front. They refuse inside a host's transaction: the journal is not rolled back with it. `vu` is the flag as displayed, `['signal' => id, 'detections' => n]`; without it, maintaining and closing target the only flag within their reach, and refuse if there are several |
| `migrerSignaux(PDO)` | moves the single flag slot of versions before 0.7.0 into `mod_signaux`: call it once, right after creating the table; the mark that keeps it from running again is the host's. Until it has run, the engine refuses to read or flag an account whose slot is open, with a `RepriseManquante` (a `LogicException`) that names it; the arbiter's lists raise it too |
| `flaggedForReview(PDO)` · `signauxOuverts(PDO)` | the accounts to arbitrate, oldest first, one row per account with all its flags; and the flags to look at, one page per cause, oldest first, with each cause's total |
| `dureeEnClair(seconds)` | a readable duration, units passed through your translator (`setTranslator()`) |

**Expected schema** — the lab's is the reference ([`demo/lab/schema.sql`](../../demo/lab/schema.sql)): `member_moderation` (reputation, strikes including an arbiter's (`strikes_admin`), voting right, `banned_until`, `ban_debut`, `ban_origine`, `ban_motif`, flag, recovery, vote suspension), `mod_votes` (including `effet`, what the vote actually changed to the reputation; a vote older than the column leaves it `NULL`, with no known effect: it is not given back and causes no ban), `mod_pack_flags`, `mod_signaux` (one row per open flag, per account and per cause; `needs_review` and `review_reason` remain, a summary the engine keeps for whoever reads the database; it reads it back only to refuse an open slot with no flag, and the host does not write it — a database from before 0.7.0 creates the table, then calls `migrerSignaux()` once, otherwise the engine refuses every account whose slot is open), `mod_sweeps` (one row per `balayagePeriodeSecondes` slot already swept; without it every downvote fails instead of letting the safety net go out), and three host tables read without being written: `accounts(id, username, created_at)`, `posts(id, account_id)`, `dm(sender_id, recipient_id, created_at)`. The engine writes SQLite SQL. Pack detection only reads `dm`: empty, it sees no pack; missing, neither table nor view, a target's second voter raises a `LogicException` that names it. A host whose tables have other names exposes them under these through temporary views, created on the connection it passes to the engine (`CREATE TEMP VIEW accounts AS SELECT …`), without writing anything to its schema; the foreign keys of the engine's tables then point to its real tables, since a foreign key cannot point to a view.

**What the host sets besides the thresholds**, still in `Config`: the automatic ban (`banAutomatique`, **on** by default; off, a reputation at the floor is flagged to the arbiter, with no ban); the flag on losing the right to vote (`signalPerteDuVote`, **on** by default, between the voting threshold and the floor); the published messages that waive the anti-Sybil age (`messagesPourVoterAvantAge`, **1** by default; 0 waives no one); the number of distinct voters from which `reasonsFor()` returns the reasons received (`motifsVotantsMin`, **1** by default; below it, a reason can point to its author, and nothing is returned).

Dates in its messages, and the day of the reasons `reasonsFor()` returns, follow the host's time zone (`date_default_timezone_set()`).

## Documentation

- Technical whitepaper (FR) — written, not yet published in this repository
- Threat model — to be written

## Status

🟢 **v0.8.0** — the engine is here, under `src/`, and the lab imports it the way
it imports SelfRecover and SelfDataGuard.

0.8.0 answers a second host's requests, with no schema change:
- **lifting is no longer pardoning**: `adminLever()` ends a penalty and keeps the repeat offence, `adminProlonger()`
  extends a running arbiter's ban, and an arbiter can impose a ban with no end (`Moderate::SANS_FIN`), which can be lifted only with a reason;
  the lab console separates lifting, pardoning and extending;
- **a journal refusal at the start of an automatic ban no longer undoes the vote**: the ban due is flagged
  (`ban_en_attente`), and the next negative vote tries it again;
- **a voter missing from `accounts`, or whose creation date is not a timestamp in seconds, no longer votes**;
- **a missing `dm` table raises an error that names it**;
- **four settings**: the automatic ban can be turned off (`banAutomatique`), losing the right to vote is flagged
  (`signalPerteDuVote`), the anti-Sybil waiver can be set (`messagesPourVoterAvantAge`), and `reasonsFor()` stays silent
  below a number of distinct voters (`motifsVotantsMin`).

**Upgrading from 0.7.1**: nothing in the database. A `Journal` that filters acts accepts `ban_prolonge`, the `levee`
cause of `ban_leve` and the `sans_fin` key; `duree` and `reste` are `null` there for a ban with no end. An arbiter
console names the causes `ban_en_attente`, `droit_de_vote_perdu` and `ban_sans_fin`, and a page that dates a due time
checks `Moderate::estSansFin()` first. The flag on losing the right to vote is on by default, and adds flags to the
arbiter's queue: `signalPerteDuVote: false` keeps the 0.7.1 queue.
`Config` now refuses a `dureesBan` tier, or a `dureeBanAdminSecondes`, that would reach `Moderate::SANS_FIN`: a
configuration that wrote `PHP_INT_MAX` to mean "infinite" throws when it is built.

0.7.1 fixes what an audit of 0.7.0 found, with no schema change:
- **a forgotten migration is told to the arbiter**: `bansEnCours()`, `flaggedForReview()` and `signauxOuverts()` raise
  `RepriseManquante` instead of showing "nothing to review"; the sweep skips that account instead of stopping on it, and
  a third party's vote whose sweep crosses it no longer fails;
- **a journal refusal on an account a vote does not target no longer brings that vote down**: a refused pack lift
  waits for the next sweep, and is flagged on the victim (`levee_en_attente`) until it is done, pardoned or closed;
  `detectPackVoting()` names the skipped accounts (`comptes_sautes`). An error after which the database has rolled
  back an engine transaction, the vote's or the scheduler's, and an `\Error`, propagate;
- **a reason that is not valid UTF-8 is refused** by `validateReason()`;
- **every text value of an act reaches the journal as valid UTF-8**: a cause read from the database no longer prevents
  closing a flag, banning, or ending a penalty;
- **an account the journal refuses on every pass no longer holds back the ones behind it**;
- **`balayerBansEchus()` refuses a host's transaction**, and a vote refused inside one no longer writes its voter's row.

**Upgrading from 0.7.0**: nothing in the database. An arbiter console wraps its lists the way it wraps its sweep and
names the `levee_en_attente` cause; a scheduler reads `comptes_sautes`; `balayerBansEchus()` is called outside the host's
transaction.

0.7.0 closes what two audits of 0.6 found, and changes what the host sees:
- **a read writes nothing any more**: `getReputation()` and `canVote()` compute the state of the moment
  without recording it or taking a lock, and `fin_en_attente` says a penalty end awaits recording; a vote, an
  arbiter action or the sweep records it. Without a scheduler, the first negative vote of each slot then sweeps
  at most `balayageFiletMax` accounts. An engine writer reading through `getReputation()` raises
  `LectureDansUneEcriture`;
- **no sanction without a trace**: with no journal plugged in, no arbiter action goes through; inside a host's
  transaction, actions are refused and the automatic ban does not arm;
- **one flag per cause**, in `mod_signaux`: an action that closes a flag takes the token
  `$vu = {signal, detections}` and refuses if the flag was detected again since it was displayed;
  `signauxOuverts()` lists them; a record's lists (`ouverts`, `ferme`) go out as JSON text;
- **arbiter bans are counted apart** (`strikes_admin`, key `dont_arbitre` of `ban_debut`), and their repetition
  is flagged (`bans_arbitre`, `Config::bansArbitreSignal`);
- and: a drop to zero during an arbiter ban is flagged; recovery no longer pulls down a score raised by
  upvotes, and gives voting rights back from 5; an action on a non-existent account is refused; the day of the
  reasons follows the host's time zone; `Config` refuses a negative sweep cap.

**Upgrading from 0.6**: add the column `strikes_admin INTEGER NOT NULL DEFAULT 0` to `member_moderation` —
arbiter bans are then counted from 0.7.0 on —; create `mod_signaux` as in the lab schema; then call
`migrerSignaux()` once, otherwise the engine refuses any account whose slot is open.

0.6.1 changes neither promise nor schema. A vote is written all or nothing: `applyVote()` holds
its writes in one transaction — its own, or a savepoint inside the host's — and a cut no longer
leaves a vote written without its effect. Reading a reputation costs one query when the state calls for neither recovery
nor the closing of a penalty: a read no longer inserts "if absent", so it takes no write lock; when the state calls for a write — missing row, recovery, expired penalty — it
happens under the write lock, as a vote does, and a single reader writes it.

0.6.0 fixes the defects an audit of 0.5.0 found. A pack weighs no more than a single
member, and the engine no longer punishes on the strength of a link; it only lifts the
ban it caused; a burst that leads to a ban stays flagged, and the arbiter sees the
make-up of every fall; a voter no longer targets the whole forum; a failing journal no
longer brings down the vote or the page that notices a penalty's end. **Breaking** since
0.5.0: the `mod_votes.effet` column enters the expected schema; `Config` loses
`meuteMute2`, `meuteMute3` and `meutePenalite3`, gains `ciblesParVotantMax`, and
`meuteVotantsMax` goes to 200; `applyVote()` no longer returns `new_reputation`;
`detectPackVoting()` returns `premier` and `rangs` instead of `sanctions`. A voting
suspension already in the database is still read until it expires.

0.4.0 brings the automatic ban back, held in check: graduated, finite, journaled
end to end, liftable by an arbiter under a floor, and lifted on its own when a pack
is recognised. The thresholds have a single source, `Config`. **Breaking** since
0.3.0: the engine's threshold constants are removed (read `Moderate::config()`),
and `adminBan()` / `adminPardon()` take the arbiter and the reason. **Two mechanisms
remain to be written** — cross-voting, and the strikes reset after three months
without incident — plus two half-kept, all marked in the lists above.

Checks: [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
for the engine through the lab, [`tests/sanity_motif.php`](tests/sanity_motif.php) for the reason
rule alone, [`tests/sanity_promesses.php`](tests/sanity_promesses.php) for the thresholds this README
announces, compared with those `Config` applies — these two without a database —, run by CI, each seen failing first: the mechanism is disabled,
the measurement retaken, the code restored. One of them measured nothing at its
first mutation — it computed its expectation from the very constant it watched,
and drifted along with it; it now reads a literal value. The five reason rules are exercised **separately**,
each case breaking only one: otherwise defence in depth catches the hole, the
check stays green, and nobody knows which rule still measures anything. The
engine bench still lives on the lab side because it needs a database schema; it
will move to `tests/` once the module carries one.

## License

AGPL-3.0-or-later — see the root [`LICENSE`](../../LICENSE).

## Author

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
