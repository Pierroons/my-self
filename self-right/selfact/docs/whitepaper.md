---
title: "SelfAct — Whitepaper"
subtitle: "Operational extension of SelfJustice: the official resource, the deadline, a template to complete"
author: "Pierroons — MySelf ecosystem"
date: "August 2026"
version: "0.1.3"
---

# Executive summary

SelfAct is the companion of [SelfJustice](https://justice.my-self.fr) for the step that follows the law: which form, which online service, which deadline, which letter. For a situation, it points to the official resource, computes the procedural deadline, and gives letter templates with gaps that the reader completes in their browser. It does not analyse a case: legal advice is reserved to legal professionals in France (loi n° 71-1130).

Free of charge. What the reader types into a template stays in their browser — the server refuses to receive a document — and the deadlines land in their calendar.

# 1. Problem statement

The French civil legal system is formally accessible to every citizen but functionally gated behind **procedural literacy**: knowing which form to fill, which court to seize, which letter to send, and within which deadline. Knowing that one is in the right is not the same as knowing what to send, and the second knowledge is the one that is unevenly distributed.

This is not a legal problem. It is a **documentation-production problem**. Every formal notice in France follows a handful of templates. Every saisine has a CERFA. Every procedural deadline is derivable from a fixed set of rules (art. 640 CPC and following).

# 2. Solution overview

SelfAct is driven by **situations and templates**. For a situation, it provides:

1. A **mise en demeure** template with gaps: the facts, the legal basis the reader relies on and the response delay are theirs to fill.
2. A **saisine** template for the conciliateur de justice, and one for the Défenseur des droits.
3. The **procedural deadline**, computed under art. 640 CPC and returned as an `.ics` file ready to import into any calendar app.
4. A **pointer to the CERFA form** the situation calls for — its number and its official page. SelfAct does not fill it in.

Each document is a print-ready page. There is no packaged archive: the reader keeps what they need.

# 3. Architecture

## 3.1 Input contract

SelfAct is driven by a situation, not by a document:

```
GET /act/api/find?situation=impaye_commercial_loyer
```

The answer carries the acts that situation calls for, official service-public.fr
models first:

```json
{
  "slug": "impaye_commercial_loyer",
  "label": "Impayé commercial ou loyer en retard",
  "urgency": "normal",
  "acts": [
    {
      "type": "mise_en_demeure",
      "status": "official",
      "label": "Mise en demeure de paiement (LRAR)",
      "url": "https://www.service-public.gouv.fr/particuliers/vosdroits/R50660"
    }
  ],
  "thresholds": { "montant_max_conciliateur": 5000, "art_applicable": "art. 750-1 CPC" },
  "catalog_suggestions": { "confidence": "heuristique" }
}
```

Two levels of confidence travel in the same answer and are labelled as such:
`acts` is curated by hand, `catalog_suggestions` is matched by keyword against
the official catalogue and is offered as a lead, not as an answer.

`GET /act/api/find?list=1` returns the situations SelfAct knows, so a caller can
offer them rather than guess a slug.

## 3.2 Template library

Templates are generic by act, not by domain — one *mise en demeure* serves a
late payment and a defective product alike, because what changes between them is
the legal basis and the facts, which the reader supplies:

| Template | What it is |
|---|---|
| `mise_en_demeure` | Formal notice |
| `saisine_conciliateur` | Referral to a conciliateur de justice |
| `plainte_simple` | Criminal complaint |
| `saisine_defenseur` | Referral to the Défenseur des droits |
| `recours_gracieux` | Administrative appeal to the issuing authority |
| `resiliation` | Contract termination |
| `document` | Free-form letter |
| `directives_donnees_post_mortem` | Directives on personal data after death |

They live in `api/data/gabarits.json`, and `/act/api/gabarits` serves the current
count. Each carries a `NON OFFICIEL` notice in the body when it imitates the form of a
legal act, and a footer reminder in every case, which survives
printing: an official model, when one exists, always takes precedence, and
`/act/api/find` returns it first.

## 3.3 Procedural calendar engine

Article 640 CPC defines the French dies a quo / dies ad quem rules. SelfAct implements these in `api/deadline.php`, served over HTTP:

```
GET /act/api/deadline?start=2026-04-17&days=15
```

The response carries the computed date along with the roll-over applied when the
term falls on a weekend or a public holiday.

The output is injected into the generated calendar (.ics).

## 3.4 Document rendering

`api/draft.php` returns a print-ready HTML page — plain PHP, HTML, CSS and SVG, with no external dependency. The reader turns it into a PDF through the browser's own print dialog.

Filling a template happens in the browser (`api/remplir.js`): the text typed in lives in the page and leaves with it. SelfAct never receives the personal data a dispute carries, so there is nothing to protect on the server side.

SelfAct does not generate CERFA forms. The catalogue points to the official ones and carries their reference number.

# 4. Security & privacy

- What the reader types into a template is processed in their browser and never sent: the server refuses to receive a document. Catalogue searches and deadline computations are ordinary requests to the server.
- No telemetry, no cloud, no third-party API calls.
- Templates are versioned in the repository and curated by hand — they are not generated, and a change to one is a commit.
- Legal references follow SelfJustice, which is resynchronised on the 1st and the 15th of each month.

# 5. Integration with SelfJustice

The two modules share a host and a reading order: SelfJustice says what the law provides, SelfAct points to the step and its deadline. There is no automatic hand-off between them — a caller reads the SelfJustice answer, picks the situation it describes, and asks SelfAct for the acts.

SelfAct is served at `justice.my-self.fr/act`, as a sub-path of SelfJustice. Only declared routes answer: anything else returns an error rather than a file.

# 6. Roadmap

**Served today** — the curated situations, the letter templates, the deadline engine and its `.ics` output, and the official catalogue harvested from service-public.fr. The counts move with each harvest; `/act/api/catalog.php?stats=1` and `/act/api/gabarits` carry the current ones.

**Next** — wider situation coverage, and templates for the domains that have none yet.

**Later** — a review mode, checking a drafted letter against the article it invokes before it is sent.

# 7. License & contribution

AGPL-3.0-or-later (since 2026-04-19; earlier releases were MIT and remain so under their original terms). Contributions welcome for: additional templates, additional catalogue sources, additional jurisdictions (Belgium, Luxembourg, Québec). Legal reviewers welcome — every template should be validated by a lawyer familiar with the domain.

# 8. References

- Art. 640 CPC (computation of delays)
- SelfJustice API: https://justice.my-self.fr/api
- Template library repository: https://github.com/Pierroons/my-self/tree/main/self-right/selfact
