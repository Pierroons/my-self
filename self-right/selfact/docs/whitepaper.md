---
title: "SelfAct — Whitepaper"
subtitle: "Operational extension of SelfJustice: compliant legal documents, one analysis away"
author: "Pierroons — MySelf ecosystem"
date: "April 2026"
version: "alpha 0.0.1"
---

# Executive summary

SelfAct is the companion of [SelfJustice](https://justice.my-self.fr) that closes the gap between *"you understand your rights"* and *"your formal notice is signed and sent"*. It takes the structured JSON output of a SelfJustice analysis and produces a packaged dossier: mises en demeure, saisines, pre-filled CERFA forms, and a procedural calendar (iCal).

No cloud. No third party. No legal fee. The tool runs locally, the documents are yours, and the deadlines land in your calendar.

# 1. Problem statement

The French civil legal system is formally accessible to every citizen but functionally gated behind **procedural literacy**: knowing which form to fill, which court to seize, which letter to send, and within which deadline. Statistics from the 2023 *Baromètre de l'accès au droit* (CNB) show that fewer than 18 % of citizens facing a clear legal dispute take any procedural action, and of those, a third use the wrong form, the wrong jurisdiction, or miss the deadline.

This is not a legal problem. It is a **documentation-production problem**. Every formal notice in France follows a handful of templates. Every saisine has a CERFA. Every procedural deadline is derivable from a fixed set of rules (art. 640 CPC and following).

# 2. Solution overview

SelfAct is a **template-driven document generator** that takes the SelfJustice JSON analysis as input and produces:

1. A **mise en demeure** with the correct legal basis, factual summary, standard 15-day response clause.
2. A **saisine** of the competent jurisdiction (tribunal judiciaire, tribunal de proximité, conciliateur de justice, médiateur, conseil des prud'hommes, etc.) with the correct attachments.
3. The relevant **CERFA form** pre-filled (XML format for administrations, PDF for court forms).
4. A **procedural calendar** (.ics) with all derived deadlines, ready to import into any calendar app.
5. An **index PDF** summarising the dossier, the action plan, the deadlines, and the recipient addresses.

All packaged into a single ZIP archive named `dossier-{ref}-{date}.zip`.

# 3. Architecture

## 3.1 Input contract (SelfJustice JSON)

```json
{
  "qualification": "refus d'indemnisation assurance dégât des eaux",
  "parties": {
    "claimant": { "name": "...", "address": "..." },
    "respondent": { "name": "...", "address": "..." }
  },
  "facts": "...",
  "legal_basis": ["L113-1 CCA", "L113-5 CCA", "1240 CC"],
  "jurisdictions": ["médiateur de l'assurance", "tribunal judiciaire"],
  "deadlines": [
    { "type": "prescription", "article": "L114-1 CCA", "days": 730 },
    { "type": "contestation", "article": "L113-5 CCA", "days": 60 }
  ],
  "next_steps": ["mise en demeure", "saisine médiateur", "saisine TJ"],
  "evidence": ["contrat assurance", "courrier refus", "photos"]
}
```

## 3.2 Template library

| Scenario category | Mise en demeure templates | Saisines | CERFA |
|-------------------|---------------------------|----------|-------|
| Assurance | 4 | Médiateur, TJ | 15766*02 |
| Travail (salarié / employeur) | 6 | CPH, CSE | 15586*03 |
| Voisinage | 5 | Conciliateur, TJ | — |
| Consommation | 4 | Médiateur conso, TJ | — |
| Copropriété | 3 | Tribunal judiciaire | — |
| Civil général (dette, RC) | 5 | TJ, proximité | 13505*10 |
| Pénal (plainte simple) | 3 | Procureur, commissariat | — |

Each template is a Jinja2 file stored in `selfact/templates/`. The template engine fills in parties, facts, legal basis, amounts, reference numbers.

## 3.3 Procedural calendar engine

Article 640 CPC defines the French dies a quo / dies ad quem rules. SelfAct implements these as a small Python library:

```python
from selfact.calendar import compute_deadline

# Mise en demeure sent on 17 April 2026, standard 15-day clause
deadline = compute_deadline(
    start="2026-04-17",
    duration_days=15,
    rule="art_640_cpc",   # includes weekend/holiday roll-over
    jurisdiction="FR"
)
# → date(2026, 5, 2)  (Friday, with no overflow)
```

The output is injected into the generated calendar (.ics).

## 3.4 PDF rendering

WeasyPrint is used to render Jinja-filled HTML templates into pixel-perfect PDFs. CERFA XML generation uses the official DILA XML schemas (opendata.gouv.fr). All renders run locally.

# 4. Security & privacy

- All input is processed in-memory and written only to the local filesystem.
- No telemetry, no cloud, no third-party API calls.
- Templates are versioned alongside the law (each template has a `law_version` stamp).
- The ZIP archive is signed with a detached PGP signature (optional), for cases where the user wants to prove the dossier content at a given date.

# 5. Integration with SelfJustice

SelfJustice exposes a `/api/analyze` endpoint that returns the structured JSON above. SelfAct reads this JSON and produces the dossier. The two can be chained from the SelfJustice web interface (`Analyse → Act on it`) or used independently — you can feed any manually written JSON to SelfAct.

Target deployment: `justice.my-self.fr/act` as a sub-path of the existing SelfJustice site.

# 6. Roadmap

**v0.1.0 (first milestone)** — templates for Assurance + Voisinage + Consommation + Civil général (covering 60 % of common citizen disputes), CERFA integration for 3 most-used forms, calendar engine, PDF renderer, ZIP export.

**v0.2.0** — templates for Travail + Copropriété + Pénal (plainte simple), more CERFA, calendar import plugins (CalDAV, Google Calendar, ProtonCalendar).

**v0.3.0** — "review mode" where the generated documents are pre-flight-checked against the law (e.g. "is this mise en demeure still valid given the last amendment of L113-1?").

# 7. License & contribution

AGPL-3.0-or-later (since 2026-04-19; earlier releases were MIT and remain so under their original terms). Contributions welcome for: additional templates, additional CERFA, additional jurisdictions (Belgium, Luxembourg, Québec). Legal reviewers welcome — every template should be validated by a lawyer familiar with the domain.

# 8. References

- Art. 640 CPC (computation of delays)
- Art. 242 nonies A Annexe II CGI (mandatory mentions)
- SelfJustice API: https://justice.my-self.fr/api
- Template library repository: https://github.com/Pierroons/my-self/tree/main/self-right/selfact
