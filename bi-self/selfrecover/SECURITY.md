# Security Policy

## Reporting a vulnerability

If you find a security vulnerability in SelfRecover, **please do NOT open a public issue**. SelfRecover shares the coordinated disclosure channel of the whole repository — policy, submission form, email and PGP key — described in the root [SECURITY.md](../../SECURITY.md). Name SelfRecover and the version or commit in your report.

## Supported versions

Current status: **reference library, deployed in real conditions, self-audited.** The current version is the latest `selfrecover-v*` tag; the root [CHANGELOG](../../CHANGELOG.md) lists them. An internal adversarial audit has been run; no external audit yet, and external red-team feedback is welcome.

Security fixes land on `main` and ship in the next release. Only the latest release line is supported: upgrade rather than wait for a backport.

| Version | Supported |
|---------|-----------|
| `main`  | ✓ |
| latest minor release line | ✓ |
| older lines | ✗ |

## Threat model

See the [whitepaper threat model](docs/whitepaper-en.md#10-threat-model--limitations) for the full analysis. Key points:

- **Protected against:** passive phishing **when the derivation runs in `'hostname'` mode** (the material is read in the browser, so a clone derives from its own hostname — in `'label'` mode there is no phishing resistance at all), email account takeover (no email at all), SMTP interception, rate limiting bypass
- **NOT protected against:** compromised server root access (see the "CRITICAL — Server Root Access" section), social engineering of the recovery word, user negligence, active phishing (a page the attacker controls)
- **Offline attack on a database dump:** the account's secrets are stored as Argon2id hashes — a dump yields none of them in the clear. Recovery codes also carry an HMAC lookup keyed by the deployment salt, which lives outside the database: as long as that salt does not leak with the dump, each guess costs one Argon2id. A file-read that yields both lets the 40-bit codes be recovered through the HMAC alone — level 2 still needs the memorized word. The recovery word never reaches the server with the shipped deriver, only its fingerprint does (a compromised server could serve a different page: see the root access section); the password is server-generated, the passphrase too unless the user brings one, and both pass through it in the clear each time they are used.
- **By design:** recovery requires either the passphrase (L1) OR a paper recovery code + the memorized word — or a "this device" proof — (L2). Lose both, and a human-reviewed L3 is the only fallback.

## Deployment security checklist

Before deploying SelfRecover in production, read the [deployment checklist](docs/whitepaper-en.md#11-deployment-security-checklist) in the whitepaper. It covers sudo hardening, database isolation, nginx rate limits, and other mandatory hardening steps.

**Critical rule:** sudo must require a strong diceware passphrase. A SelfRecover deployment without hardened sudo is a lock on a door with no wall.

Two operations destroy data by construction, and nothing in the library stops them:

- **Rotating the deployment salt** (`$selDeploiement`, the secret the integrator stores as its server secret). Paper codes are found by `HMAC(code, salt)` and stored nowhere in the clear: a new salt makes every issued code unfindable, with no possible reindexing. The only procedure is to change the salt, then have every holder reissue their sheet — level 2 by code is closed in between.
- **Pairing with SelfDataGuard without re-sealing.** Levels 1 and 2 replace the password; a vault registered with neither `$memorized` nor `$passphrase` is sealed on that password alone and becomes unreadable. A vault the integrator does not re-seal through `recover()` once the recovery is accepted stays sealed on the old password, open only through its other locks, until it is. At level 3, `reEnroll()` archives the old vault instead of deleting it. See SelfDataGuard's README, « Coupling with SelfRecover ».

## Responsible disclosure

If you report a security issue in good faith, we commit to:

1. Acknowledging your report within 7 days
2. Keeping you updated on the fix
3. Crediting you in the release notes (if you wish)
4. Not taking legal action against you for the research

Thanks for helping make SelfRecover safer.
