# Self-Right

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Access to law + capacity to act.**

> *Know your rights, make them right.*

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../LICENSE)
[![SelfJustice: v0.4.0 beta](https://img.shields.io/badge/SelfJustice-v0.4.0%20beta-green.svg)](./selfjustice/)
[![SelfAct: v0.1.2](https://img.shields.io/badge/SelfAct-v0.1.2-brightgreen.svg)](./selfact/)
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

Self-Right covers that arc in two modules: **the law (SelfJustice), then the step (SelfAct)**.

---

## Why the two modules go together

**SelfJustice alone** serves the law — articles in force, European texts, case law — for your AI to explain what it says about your situation. You then know what the law says, but not yet how to act: which form, which online service, which deadline, how to write the letter.

**SelfAct alone** is a catalogue and a set of letter templates. Without the law, you could pick the wrong step.

**Together:**

1. You describe your situation to your own AI.
2. Your AI reads the law through SelfJustice and explains what it says, with verifiable references.
3. SelfAct points to the official resource for the step, computes the deadline, and gives you a letter template with gaps. You write the facts, review, sign and send.

Neither module analyses your case in your place: legal advice is reserved to legal professionals in France (loi n° 71-1130). The judgement, and the decision to act, stay yours.

---

## Cross-module examples

- **Neighbour dispute (noise)** → SelfJustice serves the applicable articles → SelfAct points to the official nuisance report and to the saisine of the conciliateur de justice, mandatory before court.
- **Dispute with an insurer** → SelfJustice serves the articles of the Code des assurances and the case law → SelfAct points to the saisine of the insurance mediator.
- **Contested dismissal** → SelfJustice serves the Code du travail → SelfAct points to the application to the conseil de prud'hommes and computes the deadline.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfJustice](./selfjustice/) | Machine-readable legal directives + an open law API | **v0.4.0 beta** — live at [justice.my-self.fr](https://justice.my-self.fr) |
| [SelfAct](./selfact/) | Letters, forms and procedural deadlines built on that analysis | **v0.1.2** — API, catalogue and pages running |

---

## Status

SelfJustice is **deployed in production** and serves any AI agent (Claude, ChatGPT, Mistral, Gemini, Perplexity) with the full indexed French legal corpus plus the EU/ECHR texts, through an open HTTP API. Anyone can query it, anyone can self-host it. Live counts: [`/api/status`](https://justice.my-self.fr/api/status).

SelfAct **runs as well**, and its folder shows it: [`selfact/`](./selfact/) holds its code, its data, its guards, its deployment, its whitepaper and its licence.

| Piece | Where | What it does |
|---|---|---|
| Catalogue | [`selfact/api/`](./selfact/api/) | over 1,800 official resources harvested from service-public.gouv.fr, in 16 categories — the exact count and per-type breakdown are served live by `/act/api/catalog.php?stats=1`. Refreshed on the 1st and 15th. |
| Situation matching | [`selfact/api/find.php`](./selfact/api/find.php) | Around twenty hand-curated situations: "I am being laid off" → the step, the article, the form. |
| Deadline computation | [`selfact/api/deadline.php`](./selfact/api/deadline.php) | The one piece that computes rather than retrieves, with calendar export. |
| Letter drafting | [`selfact/api/draft.php`](./selfact/api/draft.php) | Formal notice, saisine (conciliateur, Défenseur des droits), contestation, termination, recours — each carrying a "NON OFFICIEL" notice in the body when it imitates the form of a legal act, and a footer reminder in every case, plus the matching official resources. |

Four of the twelve SelfRight MCP tools are SelfAct's. Both modules are served from the same domain — `justice.my-self.fr/act` — and exchange no calls: SelfJustice states the law, SelfAct performs the step.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Right — The law shouldn't be a wall. It should be a tool.*
