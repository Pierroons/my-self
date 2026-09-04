# SelfVault

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**A post-mortem directives vault with two independent locks, printable as barcodes and lodgeable with a notary.**

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
| **L2** | randomly drawn passphrase | the vault holder | ≥ 77 bits |

This is SelfDataGuard's scheme (`data_master_key_pwd_wrap` / `_recov_wrap`); only the recipient of the second envelope differs. Adding or removing a lock touches neither the content nor the other locks.

🔑 **Both secrets are drawn, never chosen.** Since each lock opens on its own, the security of the whole is that of the cheapest lock to open. A vault handed to a third party is attacked offline, with no attempt limit: only the cost of each attempt protects it, and that does not rescue a short secret. `fabriquer()` refuses any secret whose draw cannot be established — membership of its words in a wordlist is not proof of a draw.

## The accepted limit

**Whoever holds the complete envelope can open the data.** The two-lock design removes protection against premature opening. That is a deliberate trade: the threat model here is not a dishonest depositary, but forgetting, loss, and the disappearance of the software publisher. It is stated on page 1 of the envelope.

## What the module holds

| path | what |
|---|---|
| `pli/selfvault.html` | the standalone decipherer — no dependency, works offline |
| `pli/gabarit-pli.html` | the envelope template, token-based |
| `outils/selfvault.py` | the format: construction, canonical serialisation, entropy floor |
| `outils/faire_coffre.py` · `outils/faire_pli.py` | the chain: vault, barcodes, rendered envelope |
| `outils/test_webcrypto.mjs` | an independent reimplementation, written from the printed notice |
| `tests/banc.sh` · `tests/defauts.py` · `tests/pilote_app.mjs` | the test bench, its defective vaults, and the application driver |
| `docs/conception-fr.md` | the reasoning: what was chosen, rejected, measured (French) |

`sortie/` holds what the chain produces and is **not versioned**: a real run writes a real recovery code there.

```
python3 outils/faire_coffre.py [version]   # vault + secrets into outils/secrets/
python3 outils/faire_pli.py                # barcodes + rendered envelope
bash tests/banc.sh                         # the bench, against both readers
```

## The `SELFVAULT2` format

JSON, binary fields in Base64. **The notice printed inside the envelope is authoritative**: it allows a decipherer to be rewritten without this repository, which is why the format uses only primitives native to browsers.

The canonical header is passed as additional authenticated data to every AES-GCM operation, the master key is committed through an HMAC, the iteration count is bounded on read as well as on write, and every field entering the AAD is constrained to a shape that excludes its two structural characters.

## Status

Format settled on 4 September 2026. **The envelope has not yet been presented to a notary**, and the module is deployed nowhere. `tests/banc.sh` exercises every control against the defect it claims to catch, each with its counter-witness, on both readers; it runs in continuous integration and prints its own count.

Still open: the envelope reader — the program that ingests the scanned PDF and reconstitutes the files, without which page 1's verification instructions cannot be carried out — and the paper loop on the bench: rasterise, re-read the barcodes, measure the resolution floor.
