# SelfVault

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**A post-mortem directives vault with two independent locks, printable as QR codes and lodgeable with a notary.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: format settled, not yet lodged](https://img.shields.io/badge/status-format%20settled%2C%20not%20lodged-orange.svg)](#status)
[![Pillar: Self-Security](https://img.shields.io/badge/pillar-Self--Security-blue.svg)](../README.md)
[![Sibling: SelfDataGuard](https://img.shields.io/badge/sibling-SelfDataGuard-green.svg)](../selfdataguard/README.md)

---

## The problem

Two requirements that contradict each other. **While the holder is alive**, nobody else may open it. **After their death**, a designated relative must be able to open it.

Nothing in MySelf covers that transition. SelfRecover restores *access*, never *data*. SelfDataGuard encrypts, but says nothing about who opens the vault once the holder is gone. And the rule "lost passphrase means lost data" — sound while they are alive — describes exactly the scenario we want to cover once they are not.

Dead-man-switch products require a server still running ten years from now. Threshold sharing among relatives assumes the relatives keep their shares and do not convene too early. A sealed envelope held by a notary is understood by everyone without explanation.

## How it works

A randomly drawn master key encrypts the content. It is then sealed **twice**, in two independent envelopes:

| lock | secret | holder | entropy |
|---|---|---|---|
| **L1** | printed recovery code | the depositary | 98 bits |
| **L2** | randomly drawn passphrase | the vault holder | ≥ 96 bits |

This is SelfDataGuard's scheme (`data_master_key_pwd_wrap` / `_recov_wrap`); only the recipient of the second envelope differs. Adding or removing a lock touches neither the content nor the other locks.

🔑 **Both secrets are drawn, never chosen.** Since each lock opens on its own, the security of the whole is that of the cheapest lock to open. A vault handed to a third party is attacked offline, with no attempt limit: only the cost of each attempt protects it, and that does not rescue a short secret. `fabriquer()` refuses any secret whose draw cannot be established — membership of its words in a wordlist is not proof of a draw.

## The accepted limit

**Whoever holds the complete envelope can READ the data.** The two-lock design removes protection against premature opening. That is a deliberate trade: the threat model here is not a dishonest depositary, but forgetting, loss, and the disappearance of the software publisher. It is stated on page 1 of the envelope.

**They cannot modify this vault.** The content is authenticated by the master key alone, and every lock yields the master key: with nothing further, whoever holds one lock can rewrite what the other will read, leaving no trace. The factory therefore seals the vault — ECDSA P-256, public key in the header, **private key destroyed** — and the decipherer verifies that seal before trying any lock at all. Amending means building a new vault.

**The seal proves integrity, not origin.** The public key is born inside the vault and points to nothing outside it: whoever knows an opening code can build a fresh vault, sealed and coherent, that opens with the printed code. The anchor is the **seal fingerprint** — `SHA-256` of the public key, printed next to the opening code. The screen shows the one from the loaded file and compares it when the reader copies in the printed one. It covers the key rather than the file, so it survives any rewriting of the JSON; and it stays **optional**, because a legitimate holder who has lost the envelope must still be able to open.

## What the module holds

| path | what |
|---|---|
| `pli/selfvault.html` | the standalone decipherer — no dependency, works offline |
| `pli/gabarit-pli.html` | the envelope template, token-based |
| `outils/selfvault.py` | the format: construction, canonical serialisation, entropy floor |
| `outils/faire_coffre.py` · `outils/faire_pli.py` | the chain: vault, QR codes, rendered envelope |
| `outils/lire_pli.py` | the reader: scanned envelope → reconstituted files |
| `outils/test_webcrypto.mjs` | an independent reimplementation, written from the printed notice |
| `tests/banc.sh` · `tests/banc_papier.sh` | the format bench, and the paper-loop bench |
| `tests/defauts.py` · `tests/pilote_app.mjs` | the defective vaults, and the application driver |
| `docs/conception-fr.md` | the reasoning: what was chosen, rejected, measured (French) |

`sortie/` holds what the chain produces and is **not versioned**: a real run writes a real recovery code there.

```
python3 outils/faire_coffre.py [version]   # vault + secrets into outils/secrets/
python3 outils/faire_pli.py                # QR codes + rendered envelope
python3 outils/lire_pli.py scanned.pdf     # reconstitute from the scanned envelope
bash tests/banc.sh                         # the bench, against both readers
```

## The `SELFVAULT3` format

JSON, binary fields in Base64. **The notice printed inside the envelope is authoritative**: it allows a decipherer to be rewritten without this repository, which is why the format uses only primitives native to browsers.

The canonical header is passed as additional authenticated data to every AES-GCM operation, the master key is committed through an HMAC, the iteration count, the number of locks and the version number are bounded on read as well as on write, and every field entering the AAD is constrained to a shape that excludes its two structural characters.

The AAD is fixed before encryption, since it is an input to it, and therefore cannot cover the ciphertexts. An ECDSA P-256 signature covers what the AAD cannot reach — the nonces, the envelopes and the encrypted content.

## Status

Format settled on 4 September 2026, sealed on 6 September 2026 (`SELFVAULT3`). **The envelope has not yet been presented to a notary**, and the module is deployed nowhere. `tests/banc.sh` exercises every control against the defect it claims to catch, each with its counter-witness, on both readers; it runs in continuous integration and prints its own count.

**The envelope depends on none of these programs.** It carries a "reading the QR codes" page with two paths. **On Windows, installing nothing**: the Snipping Tool decodes a QR code and returns its text; the vault is only three codes, and the decipherer reassembles them itself — there is a box for that. **On Linux or macOS**: four shell commands using only `zbar-tools` and ordinary Unix tools, which the bench **extracts from the rendered envelope and runs verbatim** — a procedure printed on a legally lodged document and never executed is a claim, not a measurement.

The envelope formally forbids online QR readers: these codes **are** the vault, and uploading them hands a copy to a stranger.

Every QR code is printed **twice**, on two separate pages. Losing, tearing or staining one page costs nothing; the bench verifies that either copy suffices on its own. The reader refuses when two readings of the same rank diverge, rather than letting the last one read win silently.

The paper loop is measured end to end: the envelope rendered, rasterised, re-read, reconstituted **byte for byte**, and the vault reopened with the code printed on its page 2. `outils/lire_pli.py` names every missing QR code, writes no partial file, and refuses to conclude when it has no reference fingerprint to compare against. **Resolution floor re-measured on 2026-09-06 on the sealed envelope: 200 dots per inch pass, 150 fail** — both figures are exercised by the paper bench, one because it must pass, the other because it must refuse — hence the 300 minimum printed on the envelope.

Still open: the conditions of release, to be written into the deed of deposit — page 1 currently refers to an agreement the successor notary will not know about.
