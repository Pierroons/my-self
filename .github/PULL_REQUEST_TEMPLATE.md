<!--
Thanks for contributing to MySelf. This template is deliberately short: it asks
only for what a reviewer cannot find out on their own.
-->

## What this changes

<!-- One or two sentences, and the issue number if there is one. -->

## What it does NOT do

<!-- The limit you know about. A named limit is worth more than a silent one, and
     this project writes down what its code does not protect. -->

## Sign-off

- [ ] Every commit of this pull request carries a `Signed-off-by:` line — `git commit -s` adds it.
      Reference text: [`DCO`][dco], details in [CONTRIBUTING.md][contributing]. You keep full
      ownership of your lines; the sign-off attests provenance, it transfers nothing.

## If this touches a secret, a key or a derivation

[REVIEW.md][review] asks five questions of such a change. An ambiguous answer is a
finding.

- [ ] **Which component owns the secret**, and where is it born?
- [ ] **Which trust boundary does it cross?** Browser to server, module to integrator, memory to
      disk, initramfs to system.
- [ ] **What can a compromised caller obtain** from the public API you changed?
- [ ] **What happens when a dependency stops answering?** A plain refusal, never a fallback to a
      less protected path.
- [ ] **What can be observed without exposing the secret?** A log carries the operation, the caller
      and the result — never the secret, the key, or the plaintext.

Two rules from the same section are worth repeating here, because they are the ones most often
missed: **a key does not leave the component that holds it**, and **an interface is not a
boundary** — two classes in one process share its memory.

## Sensitive areas

[AGENTS.md][agents] lists four changes that need an explicit note in this description —
say what moves and why, even briefly:

- [ ] schema of a database served by an API
- [ ] cryptographic primitives or parameters (`self-security/`)
- [ ] cadence or scope of a public-data synchronisation
- [ ] any existing API route

<!-- None of the four? Delete this section. -->

## Checks

Per the validation table in [AGENTS.md][agents]:

- [ ] `php -l` on changed PHP, `python3 -m ast` on changed Python, `bash -n` **and** `shellcheck` on
      changed shell
- [ ] `bash scripts/check-versions.sh` if a module version or status changed (`modules.json` is the
      single source; the tag itself is the maintainer's move)
- [ ] The `structure` workflow is green on this branch. It is the reference, not a local
      approximation: if it is red, that comes before review.
- [ ] No real data, no personal identity, no private IP, no local path —
      [CONTRIBUTING.md][contributing] states the rule, and the `gitleaks` check enforces part of it.

If a check could not be run, write it down: the command, the reason, and the risk left standing.

<!--
Security issue? Do not open it here. See SECURITY.md for private disclosure.
-->

[dco]: https://github.com/Pierroons/my-self/blob/main/DCO
[contributing]: https://github.com/Pierroons/my-self/blob/main/CONTRIBUTING.md
[review]: https://github.com/Pierroons/my-self/blob/main/REVIEW.md
[agents]: https://github.com/Pierroons/my-self/blob/main/AGENTS.md
