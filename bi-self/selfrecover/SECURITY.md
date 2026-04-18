# Security Policy

## Reporting a vulnerability

If you find a security vulnerability in SelfRecover, **please do NOT open a public issue**. Contact the author privately instead:

- **Email**: pierroons@gmx.fr (with "[SelfRecover] Security" in the subject)
- **Expected response time**: within 7 days

Please include:
- A clear description of the issue
- Steps to reproduce (if applicable)
- Your assessment of the impact
- Any suggested fix

## Supported versions

Current status: **concept stage**. The `main` branch is the only supported version for security fixes.

| Version | Supported |
|---------|-----------|
| main    | ✓ |
| < main  | ✗ |

## Threat model

See the [whitepaper threat model](docs/whitepaper-en.md#10-threat-model--limitations) for the full analysis. Key points:

- **Protected against:** phishing attacks (via HMAC per domain), email account takeover (no email at all), SMTP interception, rate limiting bypass
- **NOT protected against:** compromised server root access (see the "CRITICAL — Server Root Access" section), social engineering of the recovery word, user negligence
- **By design:** recovery requires either the passphrase (L1) OR the recovery word + public identifier (L2). Lose both and fail L3 scoring, and the admin is the only fallback.

## Deployment security checklist

Before deploying SelfRecover in production, read the [deployment checklist](docs/whitepaper-en.md#11-deployment-security-checklist) in the whitepaper. It covers sudo hardening, database isolation, nginx rate limits, and other mandatory hardening steps.

**Critical rule:** sudo must require a strong diceware passphrase. A SelfRecover deployment without hardened sudo is a lock on a door with no wall.

## Responsible disclosure

If you report a security issue in good faith, we commit to:

1. Acknowledging your report within 7 days
2. Keeping you updated on the fix
3. Crediting you in the release notes (if you wish)
4. Not taking legal action against you for the research

Thanks for helping make SelfRecover safer.
