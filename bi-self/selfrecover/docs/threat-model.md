# Threat model

> Extracted from the v1.3 whitepaper. Read the [full version](whitepaper-en.md) for context.

## Threats SelfRecover protects against

### ✓ Passive phishing — in `'hostname'` mode only
The derivation binds the fingerprint to a per-service *material*, and which material is used is a mandatory, explicit mode (see [architecture](architecture.md#the-derivation-material--mandatory-mode-no-default)).

In `'hostname'` mode the material is `location.hostname`, **read in the browser**. A passive phishing clone that copies the page also copies the library, which then reads the clone's own hostname: the key it derives is useless against the real server, with no adaptation needed on the defender's side.

In `'label'` mode there is **no protection at all**. The label travels with the copy, so a clone served elsewhere derives exactly the fingerprint the real server stored. A deployment in `'label'` mode must not be described as phishing-resistant.

The honest limit, in both modes: an **active** phishing site that controls its own page harvests the raw word from its own form and derives whatever it needs afterwards — out of scope, as for any in-browser protocol.

### ✓ Email account takeover
The entire industry standard "reset password via email" chain is eliminated. If your Gmail gets hacked, your SelfRecover-based accounts are not automatically compromised — there's no email link to click.

### ✓ SMTP provider failures / deliverability issues
No SMTP at all. No SendGrid, no Mailgun, no Gmail deliverability rules, no spam folder. The recovery flow is entirely client ↔ site.

### ✓ Third-party dependencies
You don't need to trust Google, Microsoft, or anyone else for account recovery. You only trust the site you're registering on.

### ✓ Rate-limited brute force
Per-account rate limits on levels 1 and 2 and on device enrolment, plus per-address limits under
the `clearweb` profile, plus the suspension of code recovery past a threshold of failures. Opening a
level-3 case is braked per address and service-wide — deliberately not per account (see below).

⚠️ Enrolling a device reaches the account with the memorized word alone: measured on the wire on
13 August 2026, in three requests, with the attacker's own key. The library cannot verify a session, so
it requires the caller to assert that the holder is already authenticated, and it brakes the path per
account. What no assertion replaces, and what the integrator owes: the account name comes from the open
session, never from the request body.

Which brake applies — per account, per address, or both — is not guessed: the deployment declares a
profile, and the library refuses an address where none can mean anything, and refuses the absence of one
where the brake is supposed to bite. At L2 the per-account brake is the only one that works behind a hidden service, and it
has two steps: a short window, then suspension of the level for that account until it is rearmed.

Three gestures rearm it — a fresh batch of codes, a successful code recovery, a successful passphrase
recovery. A deployment that holds none of those dates does not suspend, rather than suspend for good.

### ~ Bot-driven account enumeration

**Partial, and the honest version is uncomfortable: opening an L3 dispute tells you whether an
account exists.** A success returns a dispute number; an unknown name cannot return one, so no
wording closes that gap. L1, L2 and device enrolment close it for an unknown account — L1 with a single
generic refusal, L2 by asking for no identifier at all, enrolment by counting failures under a label
derived from the *submitted* name: the brake bites whether that account exists or not, so its refusal
tells nothing apart. Deriving the label from the account found would have opened the gap in six requests,
which is how it was measured before being closed. L3 cannot, because there the distinction *is* the useful answer.
It says nothing more: an unknown name, a dispute already open and a frozen procedure get the same
refusal, at the same delay — naming either of the last two would tell anyone that a third party is
recovering that account, or has had requests refused.

**One L2 refusal does name a state, and it has to.** A suspended level cannot be described to its owner
without saying so. That refusal tells whoever already holds one of the account's codes that the code names
a real account, and tells it without paying the two Argon2id a wrong word costs. No enumeration follows —
a code is needed first — but a partly illegible code is completed at that price. The short-window brake
carries no such cost: it returns the per-address wording and pays the same delay.

**The L2 salt route answers every code, and it has to.** At L2 the browser derives before the account
is known — the code is what identifies it — so it asks for the account's salt by code first, and that
route is public and unauthenticated by necessity. Answering a valid code and refusing an unknown one
would let anyone test codes for the price of a request, without paying a single Argon2id: the cost the
whole level rests on would be bypassed. The route therefore always returns a salt — the account's for a
known code, otherwise a fabricated one: deterministic, of the same length, stable when the code is
retried, and keyed by a server secret, so that it cannot be recomputed from outside and told apart from a
real one. Two details keep it closed. The code is normalised exactly as the recovery normalises it,
or a code in upper case would get a fake salt here and be accepted there. And the lookup does not
filter consumed codes, or the route would say "this account has just been recovered".

The library has no routes, but since 0.9.0 it ships the guard: `Recovery::selDeDerivation()`, for a
storage that implements `SelParCodeInterface` (`StockagePdo` does); the integrator writes the route
around it. The bi-self-duo demonstration uses it, with a fake salt keyed by a value the visitor can
recompute — there the guard is shown, not effective, and the file says so. The lab still carries its
own copy (`demo/lab/lib/auth.php`, `Auth::selDeDerivation`). Measured in service on the lab
on 2026-10-03: two valid codes of one account returned its salt, two unknown codes two different fake
salts, with comparable response times. The guard is only as good as the routes around it: one that
answers "name taken" at sign-up settles the question this one refuses to.

What opposes enumeration at L3 is cost, not silence:

- two brakes in `Escalade::ouvrir()` — per address and a service-wide ceiling — both applied
  **before** the account lookup, so that being braked does not itself sort the accounts that
  exist from the ones that do not. There is deliberately no per-account brake: it would let a
  third party wall the holder out of their own dispute with nothing shown to the arbitrator,
  where the existing collision counter shows it;
- a forced delay on every refusal that hides a state, so the clock says no more than the message;
- and, for an exposed deployment, **a proof of work in front of the route**. The library cannot
  impose it — it has no routes. Deploy without one and the service-wide ceiling is the only brake
  left, which is a blunt instrument: it slows every visitor at once.

Behind a hidden service, where every request shares one address, the caller passes `null` rather
than that address: it says nothing about who is calling, and passing it would turn a per-client
brake into a global ceiling set at the per-client threshold — lower than the service ceiling, and
masking it. The service-wide ceiling then governs alone, and the proof of work stops being
optional.

None of the rows these brakes write carry the caller's address in the column other counters read.
Were they to, opening disputes would consume the login, L1, L2 and device-enrolment budget of
whoever shares that address.

---

## Threats SelfRecover does NOT protect against

### ✗ CRITICAL — Server root access (sudo)

**This is the single most important limitation.** If an attacker obtains root access to the server hosting SelfRecover, the entire protocol is bypassed. Root can:
- Read the database and all hashes
- Replace the `password_hash` column directly
- Modify the code itself
- Extract the server secret and per-user salts

**Mandatory deployment rule:**
- Remove `NOPASSWD` from sudoers on installation
- Enforce a strong diceware passphrase (6+ words minimum) for sudo
- SSH key-based auth only, no password login

A SelfRecover deployment without hardened sudo is a lock on a door with no wall.

### ✗ The recovery word is a master key

**If the recovery word is compromised** (social engineering, written down, shoulder surfing, malware), and the attacker also holds one of the paper recovery codes, or an enrolled device, they can recover the account via L2. The code finds the account: no identifier is needed.

- The per-service derivation prevents correlation of *stored hashes* across services — but a known raw word that you reuse stays reusable elsewhere (the service label is public). Derivation does not save a reused secret.
- Online, per-account rate limits (and per-address ones under the `clearweb` profile), then the L2 suspension past a threshold of failures, bound the guessing; offline (stolen database, an enrolled device's blob), only the Argon2id cost does
- **But fundamentally:** no system can protect against a stolen secret. A leaked SSH private key gives server access. A leaked seed phrase empties a wallet. A leaked recovery word opens the account. The security model is identical.

**A protected secret stays safe; a neglected one is exposed.** This is not a flaw — it is the fundamental contract of any secret-based security system.

### ✗ A stolen L1 passphrase, until it is used

The level-1 passphrase **never expires, and that is a decision rather than an omission.**

A backup passphrase is for the moment everything else is lost — sometimes years after it was
written down and put away. Expiring it would kill the recovery at the exact moment it is needed,
and its holder could not know beforehand: there is no email to warn them, which is the whole point
of the protocol. So a stolen sheet of paper stays usable until someone uses it.

What bounds the damage is **single use**, not a deadline: `parPassphrase()` consumes the passphrase
and issues a new one, so a stolen paper opens the account once, not forever.

What does *not* exist, and should not be claimed: the legitimate holder learns nothing at the
moment of the theft. **It is the thief who receives the new passphrase.** The owner finds out when
their password stops working. Without an out-of-band channel there is no notification, and that is
structural to an email-less model.

The library stores an issue date when the deployment keeps one, and `parPassphrase()` returns the
age of the passphrase that was just used. It **informs** — an account page saying "issued four
years ago", an arbitrator seeing how long the backup had been dormant. It never refuses. Hardening
an old recovery automatically would require telling a known context from an unknown one, and behind
a hidden service there is no context at all: every request shares one address. The library would
turn away a legitimate holder on a signal that does not exist.

### ✗ A passphrase the user brings is only as random as its dice

The user may bring their level-1 passphrase, rolled with dice, instead of receiving one drawn by
the server (`Recovery::validerPassphraseApportee()`). The library checks its **form**: six words or
more, each from the EFF English list or the French list, none repeated. It **cannot check the
randomness**: six words picked by hand pass, and are worth far less than six rolled words.

That weakness reaches further than level 1. SelfDataGuard seals its "passphrase" lock on the same
string, and a lock is attacked offline, with no attempt counter: a guessable passphrase becomes the
cheapest way into the vault. What the library can do, it does — refuse short or off-list phrases,
repeated words, the passphrase being replaced (at level 1 even in another word order, at levels 2
and 3 when identical), and at level 3 a passphrase equal to the chosen password. What it cannot do
is tell dice from a person, or recognise a passphrase older than the last one: it keeps no history,
so a user who alternates two sheets of paper brings an old door back.

### ✗ User negligence
- Writing the recovery word on a sticky note visible on the monitor
- Sharing it in a chat or email "for convenience"
- Using the same recovery word on a rogue site that then replays it elsewhere

The per-service derivation does **not** save you from reusing a secret on a rogue site — only unique secrets do. It does prevent cross-service correlation of stored hashes.

### ✗ Database breaches — partially protected
- Raw database leak → the account's secrets are Argon2id hashes, each guess costing one Argon2id; codes also carry an HMAC lookup keyed by the deployment salt, which must not leak with the dump
- BUT: if the attacker has root (see above), the protocol is already moot
- Recommendation: encrypt database backups at rest

### ✗ Lost everything
If a user forgets their password AND their passphrase AND their recovery word, the human-reviewed L3 is the only fallback. There is no SMTP-based "magic link" because SelfRecover rejects that model entirely. This is intentional — a system with infinite fallbacks has infinite attack surface.

---

## Summary table

| Threat | Protected ? | Mitigation |
|--------|:---:|---|
| Passive phishing | ✓ in `'hostname'` mode / ✗ in `'label'` mode | Material read in the browser; active phishing out of scope in both |
| Email account takeover | ✓ | No email used |
| SMTP failures | ✓ | No SMTP |
| Third-party trust | ✓ | Local only |
| Brute force recovery word | ✓ online | Rate limits + L2 suspension; offline, only the Argon2id cost |
| Bot enumeration | ~ | Closed at L1/L2; at L3 it is a cost, not a silence — see above |
| Stolen L1 passphrase | ✗ until used | Never expires, deliberately; single use bounds it, no notification exists |
| Server root compromise | ✗ | Mandatory sudo hardening |
| Stolen recovery word | ✗ | User responsibility |
| User negligence | ✗ | Reused secrets stay reusable; only unique secrets help |
| Database breach | ✓ (partial) | Argon2id hashes, but root trumps all |
| Lost everything | ✗ | Admin fallback only |
