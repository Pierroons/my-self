# SelfAct

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Turn legal analysis into ready-to-send action.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.2 running](https://img.shields.io/badge/status-v0.1.2%20running-brightgreen.svg)](#status)
[![Part of: Self-Right](https://img.shields.io/badge/part%20of-Self--Right-blue.svg)](../README.md)
[![Companion of: SelfJustice](https://img.shields.io/badge/companion-SelfJustice-green.svg)](../selfjustice/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Know your rights. Now make them real.**

---

## The gap SelfAct fills

[SelfJustice](../selfjustice/) produces an impartial legal pre-analysis with article citations, strength/weakness analysis, and procedural pointers. Excellent output. But the user ends up with a PDF that says:

> *"You could send a formal notice based on art. L113-1 of the Code des assurances, then seize the Médiateur de l'Assurance, with a delay of 2 months..."*

...and still doesn't know how to write that formal notice, which court has jurisdiction, which CERFA form to fill, or how to calculate the deadline. **The cliff between "I understand my rights" and "I am acting on my rights" is where 90 % of cases die.**

SelfAct is the bridge. It takes the structured output of SelfJustice and produces **the actual document you send**.

---

## Vision

An open-source, self-hostable generator of legally compliant procedural documents for French civil matters:

- **Mise en demeure** letters (with correct legal basis, RAR formatting, 15-day standard clause)
- **Saisines** of the competent court / tribunal / commission / conciliateur
- **CERFA forms** pre-filled from SelfJustice analysis
- **Procedural calendars** with deadlines auto-calculated (art. 640 CPC, etc.)
- **Evidence dossiers** with suggested content and structure

All output stays local. No cloud. No third party touches the documents.

---

## Core principle

SelfAct reads a **SelfJustice JSON analysis** as input. The analysis contains:

```json
{
  "qualification": "refus d'indemnisation assurance",
  "legal_basis": ["L113-1 CCA", "L113-5 CCA"],
  "jurisdiction": "médiateur puis tribunal judiciaire",
  "deadline": { "type": "prescription", "days": 730 },
  "next_steps": ["mise en demeure", "saisine médiateur", "saisine TJ"]
}
```

From that, SelfAct:
1. Selects the matching **document templates** (mise en demeure + saisine médiateur).
2. Fills them with the parties, facts, legal basis.
3. Calculates the **procedural calendar** from the extracted deadline.
4. Generates the draft letter through `api/draft.php`, with CERFA XML where applicable.
5. Outputs a **dossier package**: `dossier-2026-0042.zip` containing all documents, a readme for the user, and an action plan.

---

## Integration flow with SelfJustice

```
User input
    │
    ▼
SelfJustice analysis (structured JSON)
    │
    ▼
SelfAct — reads JSON, selects templates, fills data
    │
    ├─ Generate mise-en-demeure.pdf
    ├─ Generate saisine-mediateur.pdf  (+ CERFA XML)
    ├─ Generate procedural-calendar.ics (iCal)
    ├─ Generate dossier-index.pdf
    │
    ▼
ZIP archive "dossier-{ref}.zip"
```

The user downloads the ZIP, prints what needs printing, sends what needs sending. Every step traceable, every template auditable, every deadline on their calendar.

---

## Role in Self-Right

| SelfJustice (diagnosis) | SelfAct (action) |
|-------------------------|-------------------|
| Qualifies the conflict | Generates matching documents |
| Cites applicable articles | Puts them in the correct formal document |
| Identifies jurisdiction | Drafts the saisine with the right attachments |
| Extracts deadlines | Pre-fills the calendar (ics) |
| Says what's possible | Says what to sign |

Without SelfAct, SelfJustice is a consultation that ends on the user's desk. With SelfAct, it's a workflow that ends with a signed RAR receipt at La Poste.

---

## Status

**v0.1.2 — running at `justice.my-self.fr/act`.**

Everything is here: [`api/`](api/) the service and its data, [`site/`](site/) the
pages, [`tests/`](tests/) the guards, [`docs/`](docs/) the whitepaper. SelfAct is
served from the same domain as SelfJustice — `justice.my-self.fr/act` — and
exchanges no calls with it: SelfJustice states the law, SelfAct performs the step.

- [x] Concept paper
- [x] Resource catalogue — over 1,800 official resources in 16 categories, harvested from service-public.gouv.fr, refreshed on the 1st and 15th (exact count: `/act/api/catalog.php?stats=1`)
- [x] Situation matching — around twenty hand-curated situations, each mapped to a step, an article and a form
- [x] Deadline engine — art. 640-643 CPC, mainland / overseas / abroad, with calendar export, covered by a CI guard
- [x] Letter templates — formal notice, saisine, contestation, termination, recours, each carrying a "NON OFFICIEL" notice in the body when it imitates the form of a legal act, and a footer reminder in every case
- [x] Printing — browser-side, so no draft ever leaves the machine
- [x] Exposed through the SelfRight MCP server (4 of its 12 tools)
- [ ] CERFA XML pre-fill
- [ ] Wider template coverage — more scenarios, more jurisdictions

See **[whitepaper](docs/whitepaper.docx)** for the full protocol specification, template library plan, and deployment roadmap.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfAct — The letter is already written. All you need is to sign.*
