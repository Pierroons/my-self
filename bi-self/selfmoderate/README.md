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

SelfModerate is a moderation engine that lets online communities self-regulate. Instead of a single admin deciding who gets muted or banned, the community's natural social dynamics do the work: the members' votes make the reputation, and no dedicated moderator searches for whom to sanction. The machine computes and flags; in service, an arbiter sanctions on what it flags to them, and a sanction falls on its own only in demonstration (see [The two regimes](#the-two-regimes)).

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
- Score < 5 → **loss of voting rights**: the machine removes it in demonstration, and records it in the journal (`vote_retire`); in service, it flags the loss as due to the arbiter (`perte_du_vote_due`), and the account keeps its vote as long as no arbiter suspends it (`adminSuspendreVote()`)
- Losing the right to vote is **flagged to the arbiter** (`droit_de_vote_perdu` in demonstration, `perte_du_vote_due` in service); the flag falls when reputation climbs back over the voting threshold, or when the fall to the floor is flagged in turn
- Score = 0 → **temporary ban, graduated**: 2 → 10 → 30 min in demonstration (`Config::demo()`). The last tier **repeats**: the machine never pronounces a definitive exclusion; beyond it, the arbiter decides — only they can impose a ban with no end. In service, the machine does not ban: a reputation at the floor is flagged to the arbiter as a ban due (`reputation_zero`). The tiers of `Config::prod()`, 24 h → 7 d → 30 d, do not fall there on their own; the first gives the default length of an arbiter's ban and of a voting suspension (`dureeBanAdmin()`)
- **The automatic ban only falls in demonstration, and only when a journal is plugged in** (`setJournal()`), never inside a host's transaction, which could still roll it back. Without a journal, a zero reputation raises a flag, and no arbiter action goes through: neither the machine nor an arbiter sanctions without a trace. With one, every start, end and lift of a ban is written to the journal, **before** the database: a failure leaves a trace without effect, never a penalty without trace. When the journal refuses to record the start of an automatic ban, the vote that brought the account down stands: the fall is flagged with the ban due (`ban_en_attente`), and the next negative vote tries the ban again; that flag cannot be closed, and falls when reputation leaves the floor or an arbiter bans. Removing the vote follows the same rule: with no journal, inside a host's transaction or on a journal refusal, the vote stays, and the loss is flagged as due (`perte_du_vote_due`). The end of a penalty is recorded by the first write that touches the account — a vote, an arbiter action, the sweep —, never by a read, which shows it as soon as it is due. When the journal refuses to record it, the closing is left pending: the write that tried still goes through, the arbiter is told (`journal_en_panne`, and the arbiter console shows it site-wide), and the closing happens once the journal is back. Until then the page shows the penalty as over, but only the recorded state allows a write: the account cannot vote, and the refusal says why — but `canVote()` does not say it in advance: only an attempt knows whether the journal is back; no arbiter action and no new ban goes through on it
- **The quorum at the thresholds** (`quorumAuxSeuils`): losing the right to vote and the automatic ban require the fall to be carried by enough voters **with no link between them** — **6** for losing the right to vote, **7** for a ban, as many as voters at the cap it takes to go down from the start to the threshold (`voixPourSanction()`). In service, the quorum decides what the arbiter receives: reached, the sanction due (`perte_du_vote_due`, `reputation_zero`); missed, the fall without a quorum. Below the quorum, nothing is sanctioned: the account keeps its vote, recovery opens, and the fall is flagged to the arbiter (`chute_sans_quorum`), who decides. The flag falls when reputation climbs back over the voting threshold, or when the sanction falls; closed by the arbiter at the voting threshold, it comes back to them when the fall reaches the floor. Only a negative vote decides a sanction: a vote of support that leaves the account below the threshold triggers none. See [A link takes nothing away from anyone](#a-link-takes-nothing-away-from-anyone)
- A running penalty **is not extended** by the next opposing vote; only an arbiter extends a ban, and only an arbiter's ban
- After a served automatic ban: score back to **20**, strikes kept — the next ban will be longer
- What a ban blocks beyond voting is the platform's call (`estBanni()`). The lab also blocks posting, and leaves login and private messages open
- 3 months without incident: strikes reset — *not implemented yet*

### Arbiter actions
- **Ban**, **lift**, **pardon**, **extend**, **maintain**, **close**, **suspend the vote**, **give it back**: each is signed by the arbiter's **account**, designated by its identifier — never the one it targets: an arbiter takes no action on their own account, nor any while another arbiter has banned them — a ban of unknown origin counts as one; banned by the machine, they keep their actions, and voters do not cut moderation off: two arbiters banned by the machine can therefore lift each other, through two signed actions, recorded as early lifts before the floor — and is written to the journal, with the flags that were open and those it closes; with no journal plugged in, none goes through. Banning requires a reason, checked like a downvote's: the banned person must be able to read what they are blamed for; it closes the sanctions the machine flagged as due (`reputation_zero`, `ban_en_attente`, `perte_du_vote_due`) and no other flag, and does not touch the right to vote — a banned account does not vote. **Lifting** ends the penalty: the account comes out as it would at the due time, state flags included — after an automatic ban, the reputation goes back to the start —, and the strikes stay, an arbiter's included: the next ban will be longer. **Pardoning** resets the ban, the reputation, the strikes, a running voting suspension, and the pack rank an earlier version recorded — the history of episodes stays readable. The pardon closes what it undoes: the state flags, and the pack-recidivism flag when it erases the rank; a burst or a wide voter stays to be closed, with a written reason. **Extending** pushes back the due time of a running arbiter's ban, reason required: same episode, no extra strike. An automatic ban is not extended: the arbiter lifts it, the repeat offence still counting, then imposes their own. **Maintaining** takes note of an automatic ban: its flag leaves the queue, the penalty runs; an arbiter's ban has nothing to maintain. **Closing** closes one flag, named, and touches nothing else, reason required; a ban due waiting for the journal (`ban_en_attente`) cannot be closed. **Suspending the vote** removes it for a length of time — an arbiter's ban length by default, a century at most —, reason required and checked as for a ban: it is the action that answers, in service, a loss of the vote flagged as due, which it closes. It is written to the journal (`vote_retire`), and the vote refusal says until when. **Giving the vote back** lifts the suspension before its due time, reason optional (`vote_rendu`); the pardon lifts it too
- **One flag per cause**: each open cause on an account has its own row, and none replaces another. A state flag (`reputation_zero`, `ban_auto`, `journal_en_panne`, `ban_en_attente`, `droit_de_vote_perdu`, `perte_du_vote_due`, `ban_sans_fin`, `chute_sans_quorum`) falls on its own when the state ends — `levee_en_attente`, which a database from before 0.9.0 may carry, falls by a pardon or by a closing; a behaviour flag (`salve_rapide`, `votant_etendu`, `comptes_recents`, `bans_arbitre`, and `meute_recidive` or `votants_tronques` on a database from before 0.9.0) only falls when an arbiter closes it. A repeated fact raises its count; an action receives the flag as the console displayed it, with its detections, and refuses if a new vote has detected it again since — even when the detection folded a burst from other voters into the same fact: the arbiter does not close what they have not seen. The sweep, which rereads votes already detected, makes an action refuse only on a new fact. The wide voter and the recent accounts' share are the exceptions: for them, only a counted fact makes the action refuse, at most once per `balayagePeriodeSecondes` since the previous one, otherwise every vote would keep the arbiter from closing for as long as it lasts. The console shows one page per cause, oldest first: a flood of bursts fills its own page and pushes nothing else out of view
- **Repeated arbiter bans**: an arbiter's ban counts as an episode, and makes the next automatic ban heavier — a repeat offence is paid for, whoever recorded it. The ban start record says how many of its episodes came from an arbiter, and at the **2nd arbiter ban** on the same account (`bansArbitreSignal`), a `bans_arbitre` flag goes to the arbiters' queue: a chain no longer passes unflagged, but nothing stops the arbiter who banned from closing that flag themselves. The pardon, which resets the strikes, closes it; the lift, which keeps them, does not. These bans are counted from 0.7.0 on: a migrated database starts at zero
- **Floor**: before one third of the **running** penalty (`Config::$plancherFraction`), a lift requires a written reason and is recorded as an **early lift**, with the time that remained. One third of the running penalty, never of the maximum tier: otherwise the floor of a one-day first ban would last ten days. The floor does not close favouritism; it makes it costly, because it leaves a signed trace. It applies to lifting and pardoning alike
- **Ban with no end**: only an arbiter imposes one (`adminBan()`), or turns a running arbiter's ban into one (`adminProlonger()`); an automatic ban never becomes endless. Its due time is `Moderate::SANS_FIN`, 31/12/9999, and never comes; lifting or pardoning it always requires a written reason. A due time more than a century away (`Moderate::DUREE_MAX`) is refused: a ban that must not end is imposed with no end, rather than displayed as one without carrying its rule. It stays among the open flags (`ban_sans_fin`), out of the queue: the arbiter who imposed it has seen it
- **The penalty history**: each penalty has its row in `mod_peines`, written when it starts — who imposed it, on what reason, its initial and current due time, the last decision that carries it (`decide_le`) and its author (`decide_par`), and when it ends, the moment, the cause (due time, lift, pardon, `hors_moteur` when `migrerBans()` closes a row left open, or recognised pack on a database from before 0.9.0) and the arbiter. Its causes are **frozen** there: the votes with a negative effect standing when it started — for an automatic ban, those that caused it; for an arbiter's ban, the context. The end of an automatic ban resets those effects to zero; the copy remains, and tells after the due time who had brought the account down. Deleting an account erases its history; the journal keeps the acts

### A link takes nothing away from anyone
Two accounts are linked when each has written privately to the other over the **last 30 days** (`fenetreMeuteJours`).
The link is the only sign of coordination, and it cannot tell a pack apart from two friends reacting in good faith to
the same obnoxious post, or from a link **made up by the target itself** — a throwaway account someone answered once.
Any power given to the link over someone else's votes is therefore in the target's hands: an account linked in advance
to each of its critics would make their votes fall. The engine cancels no vote because of a link, and punishes no one
for a link. The link only counts at the thresholds.

| What happens | What the engine does |
|---|---|
| linked voters target the same member | their votes count, like anyone's |
| the target goes below a threshold | it looks for the largest number of voters none of whom is linked to another |
| that number reaches the quorum | the sanction falls in demonstration; in service, it is flagged to the arbiter as due |
| it does not | nothing is sanctioned, and the fall is flagged to the arbiter (`chute_sans_quorum`); once dismissed, it comes back to them when it worsens — on reaching the floor, or when, at the floor, it takes away the right to vote |

- **The accepted cost**: seven friends who have all written to each other weigh one voice. Their target can go down to
  zero with no ban and no loss of voting rights; it is flagged, and the arbiter sees it. A deserved sanction then comes
  from the arbiter, or from voters who do not know each other
- **The voices**: the quorum reads every voter whose balance of votes that count against the account is negative, over
  the caps window (`fenetreFarmingJours`) opened after the last settled fall — the end of an automatic ban, expired or
  lifted, or a pardon: the voters of a settled fall do not carry the next one. Leaving convalescence settles nothing:
  supporters who speed up the recovery do not erase the critics' voices. A vote followed by its opposite makes no
  voice, nor does a vote from before the `effet` column
- **No bound** on the number of voters read: a bound filled in order of arrival would fill up with the target's own
  accounts
- **A pair's links are read from `fenetreMeuteJours` before the oldest counted vote of its two members**: a clique that
  stops writing stays a clique as long as its votes count, and nobody outside the pair sets its depth — an old vote
  from one of the target's accounts only lengthens that account's own links
- **Recent accounts** make one voice between them, as they make one share: a voter whose every negative vote was cast
  while recent leaves the graph, and their class adds one voice to it
- **The search is exact** up to the quorum: the least linked voter first, a branch stopped by a clique cover. Its work
  is bounded at **200000** vertices read (`quorumTravailMax`), and the reading of links at **200000** searches
  in the pair index (`quorumRecherchesMax`): beyond that, only voters linked to nobody make voices, looked for account by
  account under the same bound. Running out before it decides, it sanctions only what the voices already found carry,
  and the make-up of the fall says so (`indecis`)
- **Nothing is judged again backwards**: a link formed after a sanction changes nothing. The quorum is looked for at
  every vote that leaves the account below the voting threshold, as long as a sanction remains to be decided: a loss of
  the vote flagged as due is a decision made
- **What the arbiter sees**: `compositionChute()` returns the voters the quorum reads, their unlinked voices, the linked
  pairs (no value when their messages exceed the reading bound), the recent accounts, whether the search decided, and
  what the links are worth (`liens`: `lus`, `non_lus` under `Messagerie::HORS_MOTEUR`, `muets` when a declared `dm` has
  no row);
  the lab's console shows it for an automatic ban as for a fall without a quorum. Only the last settled fall is kept:
  read again after the end of a ban that followed another settled fall, its make-up also counts that fall's voters
- The pack episodes an earlier version recorded stay readable (`rangDe()`, on one's own profile only); none is recorded
  any more, and the pardon still erases a rank
- **Limit: what the quorum does not see.** Coordination with no link on the platform — organised elsewhere, or a star
  whose ringleader alone wrote to each member — makes as many voices as accounts, and bans as soon as it has seven in
  demonstration; in service, it gets a ban flagged as due
- **Limit: offset critics.** Without a settled fall, a negative vote offset by votes of support stays a voice: unlinked
  critics offset by as many supporters, then a clique, reach the ban's quorum
- **Limit: what the right to vote tells, in demonstration.** Below the voting threshold, an account's right to vote
  tells whether the quorum is reached: to whoever knows who voted against it, it tells whether two of its critics wrote
  to each other privately — one bit per pair, at the price of established accounts that vote. The exact reputation
  already let it be read. In service, the account keeps its vote, which tells nothing of it
- **Limit: the graph too heavy.** Reading the links costs one search per direction between voters, and per distinct
  sender of a voter, at most two per voter: the volume of messages does not enter into it. Beyond `quorumRecherchesMax`,
  only voters linked to nobody make voices, read by increasing identifier under the same bound, and a critic who
  exchanged with another voter no longer counts. Bringing a fall there takes some 320 established accounts that vote
  against the target and have written to each other, even long ago — an old exchange costs as much as a recent one —,
  each bounded by its caps; older than the critics, they are read first, and critics linked to nobody may then make no
  voice. The fall is flagged as undecided, with no sanction, and costs the target nothing lasting: its
  accounts can give its reputation back without ceasing to be voters — two votes against, one for, their balance stays
  negative
- **Limit: the short recovery.** Leaving convalescence does not settle the fall: its critics' voices stay as long as
  their votes are within the caps window. When the recovery over time fits in that window — one point a day by default
  and in demonstration, eighteen days —, a clique alone can then complete the quorum with those voices; at the service
  pace, one point a week, the recovery outlasts the window
- **Limit: the supporting pack.** The quorum only reads falls. Linked accounts that all vote for the same member carry
  them up to the cap, which makes them slower to bring down; the per-pair cap bounds each of them, not their number,
  and only the recent accounts' share bounds those together
- **Limit: the platform without private messages.** The link is only read from `dm`. A platform without private
  messages, or whose messages go elsewhere, declares itself `Messagerie::HORS_MOTEUR`: the engine does not read `dm`,
  each voter makes one voice, a pack as many as all its members together, and the make-up of a fall says so. Declared
  `Messagerie::DM` with an empty table, it gives the same computation, which the site state flags (`liens_muets`)
- **Limit: recent accounts.** The share bounds young accounts, not aged ones: accounts
  created in advance and left dormant each weigh their full weight once established. On a
  young site, where everyone is recent, no member loses more than the share for
  `ageEtabliSecondes`: a host that is opening sets 0, or lets the moderator decide alone
  for that first month. A vote that falls into the share does not count towards its
  author's caps: it moved nothing, and a target that filled the share with its own accounts, long enough to cross them,
  would not silence the recent accounts that vote in the meantime for the whole window. And deleting an account
  erases its votes: it frees the share without giving back what they took. **What the share lets be read**: the
  neutralised vote answers like a counted one, but the score of the targeted post does not move, and the public list of
  neutralised votes carries the `comptes_recents` cause: on reload, its author reads that other recent accounts have
  already voted against that member
- **Limit: whether an account exists.** A reason is optional on a positive vote: a
  positive vote without a reason on an identifier goes through if it exists and is
  refused otherwise, and gives the target a point. It therefore tells whether an account
  exists; the per-pair cap bounds it, not the number of identifiers probed. A negative
  vote, on the other hand, requires a valid reason before looking at the target

### Anti-manipulation
- **Anti-Sybil**: a delay on new accounts, unless they have already posted: **24 hours** in service, matching the warm-up period described by [Bi-Self](../README.md), **2 minutes** in demonstration. The refusal states the remaining wait
- **Recent accounts**: an account less than **30 days** old in service, **1 hour** in demonstration (`ageEtabliSecondes`), is recent at the time of its vote. Together, recent accounts move one member's reputation no further than a single voter could: what their votes that count have moved is summed as a net balance over 60 days, and once one voter's cap is reached in a direction, the next vote from a recent account in that direction is neutralised (`comptes_recents`) and the targeted member is flagged to the moderator. The vote answers « Vote enregistré », like a counted vote: the share fills with other accounts' votes, and saying it is full would tell them those votes. The balance is net: a vote followed by its opposite, or a vote the target brings down by crossing it, does not use up the share. A vote that counts without moving anything counts for one in its own direction as long as the member is at that direction's bound — 30 for a positive vote, 0 for a negative one — if it follows the last settled fall, and for nothing otherwise: at the bound, the share still bounds the posts' scores and the reasons; votes cast during a ban, or erased by the reset, no longer fill it, even when the member is back at the floor. A vote the share neutralised enters neither its author's caps nor a burst
- **Links**: voters linked to each other keep all their votes; at the thresholds' quorum, they only count for the voices they make with no link between them. A link does not propagate: A–B and B–C linked, A and C never wrote to each other, and make two voices
- What links two accounts depends on the platform. On a forum: a **private message in each direction**, the closest equivalent to an accepted invitation. A message left unanswered tells of no agreement: writing to voters links them to nobody. Message contents are **never read** — only who wrote to whom
- **Fast burst**: several voters against the same target within a short window (5 min in service, 1 min in demonstration), linked or not; a vote the recent accounts' share neutralised does not enter it. That is not coordination, it is most often the same reaction to the same post: nothing is cancelled, the target goes to human review. Cancelling here would protect a post all the better for shocking more people at once. A burst's flag is never overwritten by a ban's
- **What neither the quorum nor the burst sees**: coordination organised elsewhere, between accounts that never wrote to each other on the platform, spacing its votes beyond the burst window. Nothing recognises it. What the arbiter sees of it: the **make-up of every automatic ban** — the voters the quorum read at its start, how old their accounts are, over how long, and what the quorum drew from them
- **Farming**: beyond 3 positive votes from one voter to one member within 60 days, the next ones are neutralised; the same cap applies to negative votes, against the slow erosion of a patient voter who targets every post. And a voter targets no more than 10 distinct members over the same window (`ciblesParVotantMax`): beyond that, their negative votes towards one more member are neutralised, and they are flagged to the arbiter
- **Crossed votes**: two members who vote against each other within **72 hours** in service, **10 minutes** in demonstration (`fenetreCroiseeSecondes`), whatever message is targeted, cross: both votes fall, in the transaction of the second — one vote against one vote, the other's most recent. Each gives back the point it had taken, and **no penalty is lifted**: a penalty caused in between stays with the arbiter. A **truce** of the same length follows the crossing: neither votes against the other any more, and those votes fall. All of them count in their author's caps — otherwise a member who strikes back at each critic would have no bound. **Below the voting threshold, no more crossing, in either direction**: an account below the threshold is crossed by nobody and crosses nobody, truce included — otherwise it would erase each of its critics before they made a voice. Its own votes therefore count in full: a member who stays below the threshold without a quorum votes without being crossable, within any voter's caps. Back at the threshold, it crosses again the votes received during its fall that are still within `fenetreCroiseeSecondes`: that is the serial retaliation below. **What this leaves readable**: as long as the public reputation is exact, each of the two reads the crossing in the reputations — the other's score does not move, theirs comes back —, and likewise in the messages' scores, on reload: the one the other targeted comes back, the one they targeted does not move. The retaliation falls under its own reason (`riposte`), the vote it crosses under `vote_croise`: only the latter, which had counted, keeps its reason and its place among its target's received votes (`reasonsFor()`, `votesRecus()`); neither the retaliation nor a truce vote shows there: at the crossing, neither party's reasons nor received-vote count change. The vote answers "Vote recorded", like a counted vote, `userVote()` gives it back to its author, the lab's vote route returns only `ok` and the message, and the lab's public page does not list it. **Limit: serial retaliation.** A member who strikes back at each of their critics makes one vote of each fall, and those they receive from them during the truce: at most **10** distinct critics over **60 days** (`ciblesParVotantMax`, `fenetreFarmingJours`), and **3** votes against each of them (`farmingDownvotesMax`)
- **Victim protection**: a fall carried by linked voters alone sanctions nothing, and the arbiter is notified of it — *partial: a victim's appeal to the arbiter is planned, not implemented yet*

## The two regimes

The host declares in `Config` two things the engine cannot see, **with no default value**: a configuration that does
not carry them cannot be built.

| Declaration | Values | What it says |
|---|---|---|
| `messagerie` | `Messagerie::DM` · `Messagerie::HORS_MOTEUR` | private messages go through the `dm` table, where the quorum reads who is linked to whom; or they go elsewhere, or do not exist |
| `usage` | `Usage::SERVICE` · `Usage::DEMONSTRATION` | a site in service, or a demonstration |

`Config::prod(Messagerie::DM)` and `Config::prod(Messagerie::HORS_MOTEUR)` give the service values; `Config::demo()`
gives the demonstration ones, with the `Messagerie::DM` messaging. A configuration of your own passes the two
declarations first, then its settings by name. The constructor's default values are the demonstration ones: in
service, a configuration of your own takes, for each setting bounded below, the value of `Config::prod()` or a harder
one, otherwise it throws when it is built.

**In demonstration** (`Usage::DEMONSTRATION`), the machine sanctions on its own. Below the voting threshold, it removes
the right to vote; at the floor, it bans, on the `dureesBan` tiers. Both are written to the journal before the database
(`vote_retire` and `ban_debut`, origin `auto`), under the same conditions: a journal plugged in, outside the host's
transaction. Without them, or on a journal refusal, nothing falls, and the sanction is flagged to the arbiter as due
(`perte_du_vote_due`, `reputation_zero`, `ban_en_attente`). A `dureesBan` tier there lasts **1 hour** at most
(`Config::PALIER_DEMONSTRATION_MAX`): a configuration that asks for more throws when it is built, and a demonstration
does not pronounce real bans. An arbiter's bans, signed by a human, have no such bound.

**In service** (`Usage::SERVICE`), the machine computes and flags, an arbiter sanctions: no ban and no loss of the vote
falls on its own. Below the voting threshold, the account keeps its vote, and the loss is flagged as due
(`perte_du_vote_due`); at the floor, the ban is flagged as due (`reputation_zero`). The quorum at the thresholds decides
what the arbiter receives: reached, the sanction due; missed, the fall without a quorum (`chute_sans_quorum`). The
arbiter answers with a signed action: `adminSuspendreVote()`, which closes `perte_du_vote_due`, `adminRendreVote()`, or
`adminBan()`, which takes `reputation_zero` and `perte_du_vote_due` out of the queue — they stay stored until
reputation climbs back — and closes `ban_en_attente`. `perte_du_vote_due` falls when reputation climbs back over the voting
threshold, or when the fall reaches the floor; `reputation_zero`, when recovery ends. Closed by the arbiter, a sanction
due comes back to them when a new fall crosses its threshold.

**Hardening means reducing what the adversary gets from the engine; turning an automatic power off hardens.** In
service, a security setting is not weakened below the value of `Config::prod()`: a weaker configuration throws when it
is built, and hardening stays allowed.

| Setting | What it bounds | In service |
|---|---|---|
| `ageMinPourVoterSecondes` | the wait before a new account's first vote | at least **24 hours** |
| `ageEtabliSecondes` | the age below which an account is recent | at least **30 days** |
| `fenetreSalveSecondes` | the window of a fast burst | at least **5 min** |
| `arrondiFinBanSecondes` | the rounding step of a ban's end | at least **1 hour** |
| `fenetreFarmingJours` | the window of a voter's caps | at least **60 days** |
| `ciblesParVotantMax` | the distinct members a voter targets | at most **10** |
| `farmingDownvotesMax` | a voter's negative votes towards one member | at most **3** |
| `farmingUpvotesMax` | a voter's positive votes towards one member | at most **3** |
| `salveVotantsMin` | the voters that make a burst | at most **3** |
| `messagesPourVoterAvantAge` | the published messages that waive the wait before the first vote | at most **1** |
| `quorumAuxSeuils` | the quorum of unlinked voters | **always on** |

Left free, because neither of their two directions hardens for certain: recovery (`intervalleConvalescenceSecondes`),
the crossed-vote window (`fenetreCroiseeSecondes`), the tiers (`dureesBan`) and the reputation thresholds
(`reputationInitiale`, `reputationMax`, `perteDroitDeVoteSous`, `banA`). A wider crossed-vote window, for instance,
makes more votes fall: the retaliation as well as the vote it crosses. The other `Config` settings do not depend on the
regime.

**`Messagerie::HORS_MOTEUR`**: the engine never reads `dm`, and requires neither the table nor the pair index. Each
established voter makes their own voice, like a voter linked to nobody, and recent accounts keep their shared voice: the
quorum sees no pack, and a pack makes as many voices as members. `compositionChute()` says so (`liens`: `non_lus`). A
host whose private messages go elsewhere declares it, instead of letting an empty `dm` keep it quiet.

**The site state**: `Moderate::etatDuSite(PDO)` returns what contradicts the host's declaration, computed on read,
writing nothing. Its state, `liens_muets`: the host declares `Messagerie::DM`, the `dm` table has no row, and the first
vote is older than one links window (`fenetreMeuteJours`) — since then, the quorum counts every voter as one voice
without the host having declared it. An empty `dm` on a new site does not enter it. The make-up of a fall says so as
soon as the table is empty (`liens`: `muets`).

## Integrating

The engine is a static class; the host passes its `PDO` connection on every call.

| Call | Role |
|---|---|
| `setHorloge(?callable)` · `maintenant()` | the time the engine reads: `time()` by default, yours if you simulate time (a demonstration, a test bench); `maintenant()` returns it, so your pages show what the engine decides. A clock that returns anything but a whole, positive Unix time before `Moderate::SANS_FIN` raises a `LogicException`; a clock that goes backwards takes the engine back with it — a fall settled "later" no longer bounds the voices, and its voters carry the next one —: keeping it monotonic is yours. The journal dates its entries its own way |
| `setConfig(Config)` | **required**: without it, the first call that reads the configuration — a vote as well as a read — raises a `LogicException` that names the step. The thresholds and the host's declaration: `Config::prod(Messagerie::DM)`, `Config::prod(Messagerie::HORS_MOTEUR)`, `Config::demo()` or your own (see [The two regimes](#the-two-regimes)). **Single source** of the thresholds: the engine reads them there, and so do your pages, through `Moderate::config()` |
| `setJournal(Journal)` | plugs in the journal, and with it, in demonstration, the automatic ban and vote removal, and in every regime the arbiter actions. The [`Journal`](./src/Journal.php) interface lists the acts and their keys; writing must be durable and not rewritable. A refusal always propagates, except when a vote or an arbiter action notices an expired penalty: its closing is then left pending and the arbiter is told (`journal_en_panne`); and except at the start of an automatic ban: the vote that caused it stands, and the arbiter is told (`ban_en_attente`); likewise for the automatic removal of the vote, which does not happen (`perte_du_vote_due`). A refusal is raised as an exception; an `\Error` raised on the sweep's path is a defect, and propagates. A host that could not build its journal serves neither votes nor actions: without a journal, a vote closes an expired penalty without recording it |
| `balayerBansEchus(PDO)` | records in one pass what time has done — penalty ends, to the journal first, and recovery climbs —, for a scheduler or the arbiter console, which reads the database and calls it before reading. Without a scheduler, the first negative vote of each sweep slot does it afterwards, for at most **50** accounts (`balayageFiletMax`), in its own request and under the write lock: a scheduler spares the voter that cost. A vote cast inside a host's transaction takes the slot without sweeping, and a host that votes inside its transactions needs a scheduler. Otherwise, an end is recorded by the first write that touches the account. After a first refusal from the journal, the pass stops calling it: it still records recovery climbs, leaves the ends to the next pass — where accounts already refused come last, the one refused longest ago first —, and the refusal surfaces at the end of the pass. An account still waiting for the migration from before 0.7.0 is skipped, and named at the end of the pass; a journal refusal in the same pass then stays flagged on its account (`journal_en_panne`), without being raised. Outside a host's transaction only: an end recorded there would reach the journal and outlive its rollback. The journal carries the due time AND the moment it was recorded |
| `applyVote(PDO, voter, type, target, value, ?reason)` | a vote, returning `ok`, `blocked` and `message` — never the target's reputation, which the profile does not show below the voting threshold. It is written all or nothing: in a transaction the engine opens (`BEGIN IMMEDIATE`) when the host has none, behind a savepoint in the host's otherwise, which then commits or rolls back the rest. Inside the host's transaction, it arms neither the automatic ban nor the automatic vote removal: a zero reputation or a loss of the vote is flagged there, as without a journal. A penalty closing done there, then rolled back with it, is done again on the next pass: the journal then carries the act twice. The first `applyVote()` of a host's transaction takes the write lock there, refusals included — except one for invalid parameters —, and the host holds it until its end; a deferred transaction that has already read can no longer take it under another writer, and fails at once: open it with `BEGIN IMMEDIATE`, or vote before any read. The first negative vote of a slot sweeps the site: what it finds on an account other than its target — a forgotten migration, any refusal raised as an exception — does not bring it down; that account is skipped, and the next sweep takes it up. A vote that counts and a crossed vote both answer "Vote recorded", and `userVote()` gives its author their vote back, whether it counts or not: their page does not say what the engine did with it. The host's route gives the voter only `ok` and `message`: `blocked`, `blocked_reason` or the updated score would tell them what the engine did with it — and, for a crossed vote, that their target had just voted against them |
| `getReputation(PDO, id)` · `canVote(PDO, id)` | read without writing anything, neither to the database nor to the journal, and without taking a lock: the state returned is that of the moment — recovery and penalty end included —, even when the database does not carry them yet, and `fin_en_attente` says an end is waiting to be recorded. An account without a row reads as the starting values, without a row being created. A voter missing from `accounts`, or whose `created_at` is not an integer timestamp in seconds, does not vote, refused by `canVote()` as by `applyVote()`. The `member_moderation` columns are the state the engine has recorded: a host reads them, it does not write them. **Limit**: for an automatic ban, `ban_debut` is the second of the vote that caused it; a page the banned account sees rounds it, as `peinesDe()` does to the day. Its end is rounded as soon as the ban is set, to the next multiple of **1 hour** in service, **1 minute** in demonstration (`arrondiFinBanSecondes`): neither the page nor the moment the account votes again gives that second back. `finAffichee(end, origin)` returns the end a page shows — rounded likewise for a ban set before this rule —, which the page calls "at the latest"; `canVote()`'s refusal goes through it. A ban set before this rule, or read after a step change, ends earlier than the page says |
| `estBanni(PDO, id)` · `bansEnCours(PDO)` | what the host blocks, and what the arbiter must see: origin, reason, end, time left, floor, and for an automatic ban the make-up of its fall; `sans_fin` marks a ban with no due time, which `Moderate::estSansFin()` also recognises from `banned_until`; `arbitre` the account that imposed it and `decide_par` that of its last decision (`{id, nom}`, null for the machine or a carried-over penalty), `peine` its history row, `echeance_initiale` and `prolongations` what it was before being extended |
| `peinesDe(PDO, id, ?limite, ?precis)` | an account's penalty history, the 50 most recent by default, most recent first: origin, episode, reason, arbiter, start, due times, last decision and its author, end and its cause, and the **number** of votes and voters among its causes. Numbers, not names; and **days**, not seconds: an automatic penalty starts at the moment of the vote that caused it, and to the second it would point to its author. Dates are brought back to midnight, in the host's time zone; `precis: true` returns them to the second, for an arbiter's console — never for a page the banned account sees. `fin` stays null while a due penalty is not recorded, when `getReputation()` already reads it as over |
| `votesRecus(PDO, id)` | the number of received votes that counted — counted, or crossed after counting: those whose reason `reasonsFor()` returns. A page that shows received votes counts these: a retaliation or a truce vote, counted, would point to their author |
| `blockedVotes(PDO, ?limit, ?saufRaisons)` | the blocked votes, most recent first, voter and target included: an arbiter view. A public page removes the names and excludes `Moderate::RAISONS_DU_CROISEMENT` in the query (`saufRaisons`), before the limit — removed afterwards, they would make the count drop, which would date each crossing |
| `adminBan(PDO, id, arbiter, reason)` · `adminLever(PDO, id, arbiter, ?reason)` · `adminPardon(PDO, id, arbiter, ?reason)` · `adminProlonger(PDO, id, arbiter, reason, seconds)` · `adminMaintenir(PDO, id, arbiter, reason, ?vu)` · `adminClasser(PDO, id, arbiter, reason, ?vu)` · `adminSuspendreVote(PDO, id, arbiter, reason, ?seconds)` · `adminRendreVote(PDO, id, arbiter, ?reason)` | the arbiter actions, returning `['ok' => bool, 'message' => string]`. `arbiter` is the **identifier** of the signing account (`accounts.id`, an integer) — never a name: a name can be taken, and a third party registered under a moderator's name would freeze their actions or sign them. The identifier is checked at run time: a string, a float or a boolean raises a `TypeError`, even from a host outside strict mode, where PHP would convert them. Refused: an identifier with no account, an action on the arbiter's own account, and any action by an arbiter whom another arbiter has banned — read under the action's lock; banned by the machine, they keep their actions. The act carries the name (`arbitre`) and the identifier (`arbitre_id`). `adminBan()` also takes a duration, and `sansFin`; `adminProlonger()` only extends an arbiter's ban, by a duration or with `sansFin`; a due time more than a century away (`Moderate::DUREE_MAX`) is refused. `adminSuspendreVote()` takes a duration, an arbiter's ban length by default (`dureeBanAdmin()`), and refuses a vote already suspended; `adminRendreVote()` refuses an account whose vote is not suspended. Each one rereads the state and decides under the write lock, taken up front. They refuse inside a host's transaction: the journal is not rolled back with it. `vu` is the flag as displayed, `['signal' => id, 'detections' => n]`; without it, maintaining and closing target the only flag within their reach, and refuse if there are several |
| `migrerBans(PDO)` | brings `mod_peines` in line with the bans: call it right after creating the table, and again after an upgrade made with the service running. A ban without a row gets one — the closed row carrying its start is reopened, otherwise a row opens, where what the database did not know stays unknown (`reprise`): the arbiter, the due time before an extension, an unrecorded start (`debut = 0`). An open row that no longer tells the running ban — no ban, or a ban with another start, lifted then set again outside the engine — is closed, cause `hors_moteur`; a due time extended outside the engine is realigned, and its row takes `reprise`, since the extension is not known. Run again on a consistent database, it does nothing. While they diverge, the engine refuses — when reading a banned account without a row, or whose open row carries another start or another due time, when banning an account that carries an open row, in the arbiter's lists — with a `RepriseManquante` that names `migrerBans()` |
| `migrerSignaux(PDO)` | moves the single flag slot of versions before 0.7.0 into `mod_signaux`: call it once, right after creating the table; the mark that keeps it from running again is the host's. Until it has run, the engine refuses to read or flag an account whose slot is open, with a `RepriseManquante` (a `LogicException`) that names it; the arbiter's lists raise it too |
| `flaggedForReview(PDO)` · `signauxOuverts(PDO)` | the accounts to arbitrate, oldest first, one row per account with all its flags; and the flags to look at, one page per cause, oldest first, with each cause's total |
| `etatDuSite(PDO)` | what contradicts the host's declaration, for the whole site: a list of `{cause, depuis}`, computed on read, writing nothing — `mod_signaux` requires an account. Its state: `liens_muets` ([The two regimes](#the-two-regimes)) |
| `dureeEnClair(seconds)` | a readable duration, units passed through your translator (`setTranslator()`) |

**Expected schema** — the lab's is the reference ([`demo/lab/schema.sql`](../../demo/lab/schema.sql)): `member_moderation` (reputation, strikes including an arbiter's (`strikes_admin`), voting right, `banned_until`, `ban_debut`, `ban_origine`, `ban_motif`, flag, recovery, vote suspension, `chute_soldee_le`, the moment of the last settled fall), `mod_votes` (including `effet`, what the vote actually changed to the reputation; a vote older than the column leaves it `NULL`, with no known effect: it is not given back and causes no ban), `mod_pack_flags` (the pack episodes from before 0.9.0, read and no longer written), `mod_peines` and `mod_peine_causes` (the penalty history and its frozen causes; a single open penalty per account, which the database guarantees through a partial unique index), `mod_signaux` (one row per open flag, per account and per cause; `needs_review` and `review_reason` remain, a summary the engine keeps for whoever reads the database; it reads it back only to refuse an open slot with no flag, and the host does not write it — a database from before 0.7.0 creates the table, then calls `migrerSignaux()` once, otherwise the engine refuses every account whose slot is open), `mod_sweeps` (one row per `balayagePeriodeSecondes` slot already swept; without it every downvote fails instead of letting the safety net go out), and host tables read without being written: `accounts(id, username, created_at)`, `posts(id, account_id)`, and `dm(sender_id, recipient_id, created_at)` when the host declares `Messagerie::DM`. The engine writes SQLite SQL. Under `Messagerie::DM`, the quorum only reads `dm`, through the **pair index**, `dm(recipient_id, sender_id, created_at)`, which is required: each search fits in that index alone, whatever the volume of messages. A `WITHOUT ROWID` table whose primary key starts with these three columns stands in for it; a view that renames the columns lets it serve, and so does a filtered view when a partial index carries the same filter. Its two identifiers are integers (`INTEGER`): the reading follows the order of numbers. Without it — a table that does not carry it, or carries it without the date, a view that computes the sender, the recipient or the date, a filtered view without a partial index, a `UNION ALL` view —, with identifiers in text or without a type, as without `dm`, neither table nor view, the engine's first write — a vote, most often — raises a `LogicException` that names it (with the quorum at the thresholds on and `Messagerie::DM` declared). Empty, no voter is linked, and the site state flags it beyond one links window (`liens_muets`). Each direction is dated by its most recent message up to now: readable if it falls between half of now and now, in Unix seconds (a timestamp written in digits in a text column is read): the moment the server received the message — a date chosen by the client would let two accounts make every vote at a threshold raise. A direction dated in clear text, in milliseconds, in tenths of a second, as a Julian day or `NULL` is unreadable: it links nobody, and the error log says so — except when no direction is readable between the voters and a pair is unreadable in both directions, where the fall raises a `LogicException` that names the date. A messaging system that changes unit converts its old messages: otherwise a direction is dated by its last readable message, however old, and the recent exchange goes silent without a word in the log. A message received under a block, if the host offers one, does not enter `dm`: it would make a link there between the writer and whoever blocked them — between a harasser and their victim; a block removes nothing already there. A host whose tables have other names exposes them under these through temporary views, created on the connection it passes to the engine (`CREATE TEMP VIEW accounts AS SELECT …`), without writing anything to its schema; the foreign keys of the engine's tables then point to its real tables, since a foreign key cannot point to a view.

Two partial indexes serve the arbiter's lists and `bansEnCours()`: `member_moderation(account_id) WHERE needs_review = 1` and `member_moderation(banned_until) WHERE banned_until > 0`; the sweep, which also reads convalescence, scans the table. The module requires the `pdo_sqlite` and `mbstring` extensions.

**What the host sets besides the thresholds**, still in `Config`: its messaging and its use, to be declared (see [The two regimes](#the-two-regimes)); the quorum at the thresholds (`quorumAuxSeuils`, **on** by default, and always in service; off, reputation alone decides), and its two work bounds (`quorumTravailMax`, `quorumRecherchesMax`); the published messages that waive the anti-Sybil age (`messagesPourVoterAvantAge`, **1** by default; 0 waives no one); the number of distinct voters from which `reasonsFor()` returns the reasons received (`motifsVotantsMin`, **1** by default; below it, a reason can point to its author, and nothing is returned). The threshold counts distinct voters whose vote counted: two accomplices whose votes counted, then were crossed, pass it, and the crossing gives the target back the points they had cost it.

Dates in its messages, and the day of the reasons `reasonsFor()` returns, follow the host's time zone (`date_default_timezone_set()`).

## Documentation

- Technical whitepaper (FR) — written, not yet published in this repository
- Threat model — to be written

## Status

🟢 **v0.8.0** — the engine is here, under `src/`, and the lab imports it the way
it imports SelfRecover and SelfDataGuard.

**0.9.0 is being written.** Still to be written before its release, among other things, the appeal to an arbiter and
the list of accounts that arbitrate; an appeals board made of members is not part of 0.9.0. Its foundation:
- **the two regimes**: the host declares its messaging and its use; in service, the machine computes and flags, an
  arbiter sanctions (`adminSuspendreVote()`, `adminRendreVote()`, `adminBan()`), and no security setting is weakened
  below `Config::prod()`; the demonstration keeps the automatic ban and vote removal, on bounded tiers
  (`Config::PALIER_DEMONSTRATION_MAX`);
- **the penalty history** (`mod_peines`, `mod_peine_causes`, `peinesDe()`); `bansEnCours()` says who imposed the ban;
- **the arbiter is an account**, designated by its identifier, never the one targeted nor an account a peer has banned; the act carries `arbitre_id`;
- **an adjustable clock** (`setHorloge()`, `maintenant()`);
- a due time more than a century away is refused: such a ban is imposed with no end;
- **a link no longer cancels any vote**: losing the right to vote and the ban wait for a quorum of voters with no link
  between them, and a fall without a quorum is flagged to the arbiter (`chute_sans_quorum`); recent accounts weigh
  together as one member.

**Upgrading from 0.8.0**: add `member_moderation.chute_soldee_le` (`INTEGER NOT NULL DEFAULT 0`), create the pair index (`CREATE INDEX IF NOT EXISTS idx_dm_paire ON dm(recipient_id, sender_id, created_at)`, on the table `dm` exposes; not needed under `Messagerie::HORS_MOTEUR`), create `mod_peines`, `mod_peine_causes` and the two partial indexes as in the lab's schema
**before** serving the 0.9.0 code — without the tables, every read fails (`no such table`); then call `migrerBans()`,
without which the engine refuses every banned account. Upgrade with the service stopped, or run `migrerBans()` again
after the switch: a penalty that 0.8.0 closes, reopens or extends during the upgrade leaves a row that no longer tells
it. Pass the arbiter actions the **identifier** of the signing account (an integer): a name, a numeric string or a
boolean raises a `TypeError`, even outside strict mode. A `Journal` that filters keys
accepts `arbitre_id`.
`Config` refuses a `dureesBan` tier, or a `dureeBanAdminSecondes`, of more than a century, and an
`arrondiFinBanSecondes` step longer than the shortest tier. In the journal, an automatic ban's `duree` is its penalty,
rounded end included: it exceeds its tier by less than one step.
`Config` loses `meuteLiensMin`, `meuteVotantsMax` and `meuteEpisodeCooldown`, which no code reads any more: a
configuration that passes them by name throws on construction — passed by position, its values would shift without an
error; pass it by name. It gains `ageEtabliSecondes` (on), `quorumAuxSeuils` (on), `quorumTravailMax` and
`quorumRecherchesMax`. Without the `chute_soldee_le` column, the first write raises a `LogicException` that names it;
without the pair index, or with `dm` identifiers that are not integers, the first write does too (with the quorum at the thresholds on and `Messagerie::DM` declared). The schema is checked once per connection: a database that loses
the column or the index during a connection is not checked again. A messaging table dated in milliseconds raises instead
of linking nobody. A vote can be neutralised by the recent accounts' share (`blocked_reason` `comptes_recents`), and
the targeted member flagged under the same cause. An arbiter ban no longer removes the right to vote.
`detectPackVoting()` keeps its keys: `pack_detected` is always false, `cancelled_votes` zero, `packs` and
`voters_truncated` empty. An arbiter console names the `chute_sans_quorum` cause, and `compositionChute()` also returns
`voix_sans_lien`, `recents`, `paires_liees`, `indecis` and `liens`. `mod_pack_flags` is no longer written.
Declare your messaging and your use ([The two regimes](#the-two-regimes)): `Moderate::setConfig(Config::prod(Messagerie::DM))`,
or `Config::prod(Messagerie::HORS_MOTEUR)` for a host whose private messages go elsewhere — `dm` is then no longer
read, and the pair index no longer required. Without `setConfig()`, the engine throws instead of serving the
demonstration values, and `Config::prod()` with no argument throws too. A configuration of your own passes `messagerie`
and `usage` first; in service, it is not weakened below `Config::prod()`. `Config` loses `banAutomatique` and
`signalPerteDuVote`: the use decides, and a configuration that passes them by name throws when it is built. In service,
nothing falls on its own: an arbiter console names the `perte_du_vote_due` cause and offers the two vote actions,
`adminSuspendreVote()` and `adminRendreVote()`; `adminBan()` closes `ban_en_attente` and takes `reputation_zero` and
`perte_du_vote_due` out of the queue, and `ban_en_attente` can no longer be closed. A `Journal` that filters acts accepts `vote_retire`
and `vote_rendu`, and the `perte_droit_de_vote` reason. `etatDuSite()` joins the arbiter's reads. The refusal of a
suspended vote changes text (« Droit de vote suspendu par un arbitre jusqu'au %s. »): a host translation
(`setTranslator()`) takes it up.

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
and `adminBan()` / `adminPardon()` take the arbiter and the reason. **One mechanism
remains to be written** — the strikes reset after three months without incident; crossed
votes are written since 0.9.0 — plus two half-kept, all marked in the lists above.

Checks: [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
for the engine through the lab, [`tests/sanity_motif.php`](tests/sanity_motif.php) for the reason
rule alone, [`tests/sanity_promesses.php`](tests/sanity_promesses.php) for the thresholds this README
announces, compared with those `Config` applies, and for each regime's bounds, which `Config` must refuse to cross —
these two without a database —, run by CI, each seen failing first: the mechanism is disabled,
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
