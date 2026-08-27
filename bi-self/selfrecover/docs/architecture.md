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
     │                              │    username, identifier,    │
     │                              │    password }               │
     │                              │────────────────────────────>│
     │                              │                             │
     │                              │                Argon2id(derived_key)
     │                              │                generate diceware passphrase
     │                              │                Argon2id(passphrase)
     │                              │                INSERT users
     │                              │                             │
     │                              │<────────────────────────────│
     │                              │  { passphrase: "..." }      │
     │  Display passphrase once     │                             │
     │<─────────────────────────────│                             │
     │                              │                             │
```

## Recovery L2 flow (passphrase lost)

```
User enters a recovery code + the memorized word
              │
              ▼
Browser: GET /user-salt?code=…   (the recovery code locates the account;
              │                    decoy salt if unknown — no enumeration)
              ▼
Browser computes HMAC-SHA256(key = word, message = material + "|v2" + user_salt)
              (material comes from the mandatory derivation mode — see below)
              │
              ▼
POST /recover-l2 { recovery_code, recovery_key (derived) }
              │
              ▼
Server: code_lookup = HMAC-SHA256(SERVER_SECRET, recovery_code) → locate account
              │
              ▼
Server: Argon2id-verify(recovery_code) AND Argon2id-verify(recovery_key)
              │   (generic error — never reveals which factor failed)
              ├── OK ──> Generate new password, mark code used, update account
              │          Return new password to browser
              │
              └── FAIL ─> Increment L2 attempts counter
                         If >= 3 → redirect to Level 3
```

## Recovery L3 flow (everything lost)

No secret is requested here — by definition the user has none left. What is
collected is a bundle of raw facts for a human to read, never a score.

```
User types their public identifier only
              │
              ▼
Browser generates a tracking code, sends SHA-256(code) as the claim
              │            └── the code itself stays with the user: an L3
              │                applicant has no session, so this claim is
              ▼                what protects the case thread
POST /recover-l3-init { identifier, claim_hash }
              │
              ├── case already open ──> number NOT disclosed to the caller
              │                         (the claim must not be derivable from a
              │                          semi-public identifier)
              │                         → recorded as a multi-requester signal
              ▼
Server opens case LIT-XXXX (24h TTL), returns 3 contextual questions
   creation year · last-login month · usage frequency
              │
              ▼
POST /recover-l3 { dispute_number, claim, answers }
              │
              ▼
Server assembles a BUNDLE OF SIGNALS — never a numeric score:
   PASSIVE      has this IP connected successfully before?  (unforgeable)
   DECLARATIVE  stated vs actual                            (guessable)
              │
              ▼
status = awaiting_admin        attempt logged as a FAILURE
              │                (an L3 never succeeds on its own)
              ▼
A human administrator reads the facts and confirms identity in the case chat
              │
              ▼
POST /l3-reset  → the OWNER sets a new password and memorized word,
                  plus a fresh batch of recovery codes.
                  The tracking code is consumed (one-shot).

   ⚠ The server never generates nor transmits a password at any point.
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

- Per-username rate limits (L1: 3 attempts/15min → 1h block → 3 blocks → ejected to L2)
- Per-identifier rate limits (L2: 3 attempts total → ejected to L3)
- Cooldown between L3 attempts (1h)
- Honeypot field (hidden via CSS) to trap unsophisticated bots
- Timing check (form submitted in < 2s = bot)
- Fingerprint tracking for cross-account patterns

All these are documented in the [full whitepaper](whitepaper-en.md).
