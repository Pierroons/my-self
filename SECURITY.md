# Security Policy

## Reporting a vulnerability

**Please do not open a public issue for an active vulnerability.** Use the
coordinated disclosure channel instead:

- **Policy and submission form**: <https://security.my-self.fr>
- **Email**: security@my-self.fr
- **PGP key**: <https://security.my-self.fr/.well-known/vdp-pubkey.asc>
- **`security.txt`**: <https://security.my-self.fr/.well-known/security.txt>
- **Expected first response**: within 7 days

Please include a clear description, reproduction steps where applicable, your
assessment of the impact, and a suggested fix if you have one.

## Scope

This repository holds the MySelf ecosystem: SelfRecover, SelfDataGuard,
SelfJustice, SelfAct and their siblings. Report against the module
concerned and name it — the modules share a codebase but not a threat model.

A dedicated red team environment is available at <https://ctf.my-self.fr>, with
its own rules of engagement. Testing there is encouraged and framed; testing
against production deployments is not.

## Supported versions

Only the `main` branch receives security fixes. `dev` is a working branch and
carries no such guarantee.

| Branch | Supported |
|--------|-----------|
| main   | ✓ |
| dev    | ✗ (work in progress) |

## Public tracking

Once a vulnerability is fixed and disclosed, an issue may be opened for public
tracking using the *Security disclosure* template. That template exists for
**already-resolved** matters — never for an active one.
