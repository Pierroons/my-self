# Architecture

## Registration flow

```
┌─────────┐                    ┌─────────┐                   ┌─────────┐
│  User   │                    │ Browser │                   │ Server  │
└────┬────┘                    └────┬────┘                   └────┬────┘
     │                              │                             │
     │  Type recovery word          │                             │
     │─────────────────────────────>│                             │
     │                              │  generate user_salt         │
     │                              │  (client-side, random)      │
     │                              │                             │
     │                              │  HMAC-SHA256:               │
     │                              │    key = word               │
     │                              │    msg = <material>|v2<salt>│
     │                              │             ─> derived_key  │
     │                              │                             │
     │                              │  POST /register             │
     │                              │  { derived_key, user_salt,  │
     │                              │    username, passphrase? }  │
     │                              │────────────────────────────>│
     │                              │                             │
     │                              │                generate password
     │                              │                Argon2id(password)
     │                              │                Argon2id(derived_key)
     │                              │                passphrase brought? validate it
     │                              │                (6 listed words, lowercased),
     │                              │                else generate one (diceware)
     │                              │                Argon2id(passphrase)
     │                              │                INSERT account
     │                              │                emit 10 recovery codes
     │                              │                             │
     │                              │<────────────────────────────│
     │                              │  { password, passphrase,    │
     │                              │    recovery_codes }         │
     │  Display them once           │                             │
     │<─────────────────────────────│                             │
     │                              │                             │
```

## Recovery L2 flow (passphrase lost)

```
User enters a recovery code + the memorized word
              │
              ▼
Browser: POST /user-salt { recovery_code }   (the code locates the account; decoy
              │     salt if unknown — no enumeration; POST, so the code stays out of URLs and logs)
              ▼
Browser computes HMAC-SHA256(key = word, message = material + "|v2" + user_salt)
              (material comes from the mandatory derivation mode — see below)
              │
              ▼
POST /recover-l2 { recovery_code, recovery_key (derived), new_passphrase? }
              │
              ▼
Server: code_lookup = HMAC-SHA256(deployment salt, recovery_code) → locate account
              │
              ▼
Server: Argon2id-verify(recovery_code) AND Argon2id-verify(recovery_key)
              │   (generic error — never reveals which factor failed)
              ├── OK ──> Generate a new password; new passphrase drawn, or the one
              │          brought (validated, and not the one it replaces); consume
              │          the code, revoke sessions; return both to the browser, once
              │
              └── FAIL ─> Increment L2 attempts counter
                         The person chooses to open a dispute
```

## Recovery L3 flow (everything lost)

No secret is requested here — by definition the user has none left. What is
collected is a bundle of raw facts for a human to read, never a score.

```
User types their account name only
              │
              ▼
Browser generates a tracking code, sends SHA-256(code) as the claim
              │            └── the code itself stays with the user: an L3
              │                applicant has no session, so this claim is
              ▼                what protects the case thread
POST /recover-l3-init { username, claim_hash }
              │
              ├── unknown name, case already open, or procedure frozen
              │     ──> one and the same refusal, same delay: nothing tells
              │         the caller which (an open case is a third party's
              │         recovery in progress); the number is never disclosed
              │         → a concurrent request is recorded for the arbitrator
              ▼
Server opens case LIT-XXXX (24h TTL), returns 3 contextual questions
   creation year · last-login month · usage frequency
              │
              ▼
POST /recover-l3 { dispute_number, claim, answers }
              │
              ▼
Server assembles a BUNDLE OF RAW FACTS — never a numeric score:
   CONTEXT      account created, last login, login count, L2 codes left,
                recent refusals, the deployment's own facts (uninterpreted)
   DECLARATIVE  stated vs actual: match · differs · unavailable  (guessable)
   WARNING      the answers guide the conversation, they prove nothing
              │
              ▼
status = awaiting_admin        attempt logged as a FAILURE
              │                (an L3 never succeeds on its own)
              ▼
A human administrator reads the facts and confirms identity in the case chat
              │
              ▼
POST /l3-reset  → the OWNER sets a new password and memorized word;
                  the server issues a fresh passphrase (or validates the one the
                  owner brings) and a fresh batch of recovery codes, and revokes
                  sessions and enrolled devices.
                  The tracking code is consumed (one-shot).

   ⚠ At this level the server never generates the password: the owner sets it.
     Wiring an automatic reset to the administrator's "accept" button would
     rebuild the very automatic path this level exists to avoid.
```

## Key properties

1. **The raw recovery word never leaves the browser.** Only the HMAC derivation is sent over the wire.
2. **The server never stores the raw word.** It only stores an Argon2id hash of the derived key.
3. **Service-specific.** The derivation message carries a per-service material, so the same word yields a different key per service. In `'hostname'` mode that material is read in the browser, so a passive clone that copies the page derives a different key; in `'label'` mode it is not, and the clone derives the same key. (A captured *raw* word reused elsewhere stays reusable in either mode — the material is public; the binding stops hash correlation across services, not secret reuse.)
4. **Zero SMTP.** No email addresses involved, anywhere.
5. **Zero third-party.** The user only trusts the site they're registering on.
6. **Split knowledge.** Recovery word alone = nothing. Algorithm alone = nothing. Only the combination proves identity.

## The derivation material — mandatory mode, no default

The message hashed by the derivation is `<material>|v2<salt>`, and `material` comes from a mode the integrator must pass explicitly. The shipped library (`client/sr-derive.js`) throws when the mode is missing: a default would be a choice imposed on everyone without saying so, and the trade-off depends on how the service is served.

| Mode | `material` | Anti-phishing | Cost |
|------|-----------|---------------|------|
| `'hostname'` | `location.hostname`, **read in the browser**, lowercased | ✓ real — a clone derives from its own hostname | Accounts are bound to the hostname; losing or changing the address loses L2 recovery for all of them |
| `'label'` | a stable label supplied by the integrator, verbatim | ✗ none — a copy carries the same label and derives the same key | — accounts survive a change of address |

The material must be **read** in the browser and never received from the network: material a server supplies is material any server can supply, including one imitating the real service.

Frozen test vectors for both modes live in `../tests/vecteurs-derivation.json`.

## Why HMAC and not plain hash ?

HMAC's keyed construction takes the memorized word as the **key** and the per-service material plus the per-account salt as the **message**, so the same word yields a different key per service and per account. This stops cross-service correlation of stored hashes and shared rainbow tables. Anti-phishing is a separate property, and it comes from the *mode*, not from HMAC: only `'hostname'` provides it, and only against a passive clone (an active clone that controls its page harvests the raw word).

## Rate limiting and anti-abuse

Not covered in detail in this diagram, but essential in production:

What the library enforces, with the defaults it ships:

- The deployment profile is a required constructor argument, with no default:
  `clearweb` (each caller has its own address) or `tor-onion` (no per-caller
  address the library can read — a hidden service, or a schema without the
  column). It decides whether the per-address brake exists at all, and it refuses
  the argument that contradicts it: an address under `tor-onion`, none under
  `clearweb`. Both are integration mistakes that leave a service looking healthy
- Device enrolment — 5 failures per account and 12 per address, over the same window, and a required
  assertion that the holder is already authenticated. Without it the path reaches the account with the
  memorized word alone
- L1 — 5 failures per username and 12 per address, over a 15-minute window
- L2 — no username is asked, but the code names its account: 5 failures per
  account over the same window, and the level is suspended for that account after
  20 failures since its last rearming — a fresh batch of codes, a successful code
  recovery, or a successful passphrase recovery. The per-address counter applies on
  top. A deployment that holds none of those three dates does not suspend
- L3 — 1 hour between two deposits on a dispute; 10 openings per address and 20
  per service, over an hour
- A forced delay on every refusal that hides a state. One refusal names its
  state, and must: an account whose level 2 is suspended cannot be told to renew
  otherwise. It tells whoever already holds one of that account's codes that the
  code names a real account, and tells it without paying the two Argon2id

What it does **not** enforce, and leaves to the integrator: a honeypot field, a
form-timing check, a proof of work in front of the routes, and any cross-account
correlation. These live where the routes and the pages are — the library has
neither.

Thresholds are constructor parameters, not constants: a deployment sets its own.
See the [full whitepaper](whitepaper-en.md).
