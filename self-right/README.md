# Self-Right

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Access to law + capacity to act.**

> *Know your rights, make them right.*

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../LICENSE)
[![SelfJustice: v0.1.0](https://img.shields.io/badge/SelfJustice-v0.1.0-green.svg)](./selfjustice/)
[![SelfAct: alpha 0.0.1](https://img.shields.io/badge/SelfAct-alpha%200.0.1-lightgrey.svg)](./selfact/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.md)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

---

## The tension it addresses

Access to law in France is formally equal. In practice, it requires:
- Reading legal text (coded, archaic, cross-referenced)
- Identifying which law applies to your situation
- Quantifying your chances
- Knowing the right procedure (mediation, letter, court, which court)
- Filling the right form within the right deadline
- Affording a lawyer, or representing yourself

Each of these steps is a filter. Most people give up at the first two. Knowing your rights is useless if you don't know how to enforce them. **The law is accessible only to those who already have legal literacy** — a self-perpetuating inequality.

Self-Right tackles the full arc in two complementary modules: **understand the law (SelfJustice), then act on it (SelfAct)**.

---

## Why the two modules reinforce each other

**SelfJustice alone** produces an impartial legal analysis with citations — but leaves you with a document. You know what the law says. Now what? Nothing, unless you know how to draft a formal notice, identify the competent court, fill a CERFA form, respect a procedural deadline. For 90 % of citizens, this gap is the wall.

**SelfAct alone** would be a template library — useful, but dangerous without context. A formal notice with the wrong legal basis is worse than no letter at all.

**Together**, the chain is complete:

1. You describe your situation in plain language.
2. SelfJustice fetches the actual law articles, does an impartial analysis, identifies what's defensible.
3. SelfAct takes that analysis as input and generates ready-to-send documents: mise en demeure letter, saisine of the competent court, CERFA form pre-filled, calendar of deadlines.

From the fog of "I think I'm in my rights" to "this letter is in the post on Monday" — in a single continuous workflow, at zero cost.

---

## Cross-module workflows

- **Neighborhood noise complaint** → SelfJustice qualifies the conflict (nuisance sonore, art. R1336-5 CSP), extracts the applicable articles and deadlines → SelfAct generates the mise en demeure with the right legal basis, identifies the conciliateur de justice as first step, calculates the 15-day response window.
- **Refused insurance claim** → SelfJustice identifies the applicable clause (exclusion formelle et limitée, L113-1 CCA), evaluates it against jurisprudence → SelfAct drafts the contestation letter + the saisine of the Médiateur de l'Assurance with the CERFA.
- **Work harassment** → SelfJustice cites L1152-1 Code du travail, identifies evidence requirements → SelfAct produces the letter to the employer, the CSE/CSSCT notice, the prud'hommes form (CERFA 15586*03) with the relevant sections pre-filled.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfJustice](./selfjustice/) | Impartial legal pre-analysis powered by IA | v0.1.0 ✅ — live at [justice.my-self.fr](https://justice.my-self.fr) |
| [SelfAct](./selfact/) | Operational extension: letters, forms, procedural deadlines | alpha 0.0.1 — design phase |

---

## Status

SelfJustice is **deployed in production** and serves any IA agent (Claude, ChatGPT, Mistral, Gemini, Perplexity) with 488,903 official French legal articles + 705 EU/CEDH treaty articles through an open HTTP API. Anyone can query it, anyone can self-host it.

SelfAct is in **design phase** (alpha 0.0.1). The whitepaper defines the scope (mise en demeure, saisine, CERFA, deadlines), the architecture (prompt templates + CERFA XML forms), and the integration with SelfJustice output. Prototype implementation is planned for v0.1.0 alongside a live deployment on the same domain (`justice.my-self.fr/act`).

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Right — The law shouldn't be a wall. It should be a tool.*
