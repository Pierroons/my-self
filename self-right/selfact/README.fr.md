# SelfAct

**Transforme une analyse juridique en action prête à envoyer.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#statut)
[![Part of: Self-Right](https://img.shields.io/badge/part%20of-Self--Right-blue.svg)](../README.fr.md)
[![Companion of: SelfJustice](https://img.shields.io/badge/companion-SelfJustice-green.svg)](../selfjustice/)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Connais tes droits. Maintenant rends-les réels.**

---

## Le gap que SelfAct comble

[SelfJustice](../selfjustice/) produit une pré-analyse juridique impartiale avec citations d'articles, analyse forces/faiblesses, et indications procédurales. Excellente sortie. Mais l'utilisateur se retrouve avec un PDF qui dit :

> *« Vous pourriez envoyer une mise en demeure fondée sur l'art. L113-1 du Code des assurances, puis saisir le Médiateur de l'Assurance, avec un délai de 2 mois... »*

...et ne sait toujours pas comment rédiger cette mise en demeure, quelle juridiction est compétente, quel CERFA remplir, ni comment calculer le délai. **La falaise entre « je comprends mes droits » et « j'agis sur mes droits » est là où 90 % des dossiers meurent.**

SelfAct est le pont. Il prend la sortie structurée de SelfJustice et produit **le document réel que vous envoyez**.

---

## Vision

Un générateur open-source auto-hébergeable de documents procéduraux conformes en matière civile française :

- **Mises en demeure** (avec la bonne base légale, format RAR, clause standard 15 jours)
- **Saisines** de la juridiction / tribunal / commission / conciliateur compétent
- **Formulaires CERFA** pré-remplis depuis l'analyse SelfJustice
- **Calendriers procéduraux** avec délais auto-calculés (art. 640 CPC, etc.)
- **Dossiers de preuves** avec contenu et structure suggérés

Toute sortie reste locale. Pas de cloud. Aucun tiers ne touche les documents.

---

## Principe cœur

SelfAct lit une **analyse SelfJustice JSON** en entrée. L'analyse contient :

```json
{
  "qualification": "refus d'indemnisation assurance",
  "legal_basis": ["L113-1 CCA", "L113-5 CCA"],
  "jurisdiction": "médiateur puis tribunal judiciaire",
  "deadline": { "type": "prescription", "days": 730 },
  "next_steps": ["mise en demeure", "saisine médiateur", "saisine TJ"]
}
```

À partir de ça, SelfAct :
1. Sélectionne les **templates de document** correspondants (mise en demeure + saisine médiateur).
2. Les remplit avec les parties, les faits, la base légale.
3. Calcule le **calendrier procédural** depuis le délai extrait.
4. Génère des PDF signés (via `weasyprint` ou `pdfkit`) avec XML CERFA le cas échéant.
5. Sort un **pack dossier** : `dossier-2026-0042.zip` contenant tous les documents, un readme pour l'utilisateur, et un plan d'action.

---

## Flux d'intégration avec SelfJustice

```
Saisie user
    │
    ▼
Analyse SelfJustice (JSON structuré)
    │
    ▼
SelfAct — lit le JSON, sélectionne les templates, remplit les données
    │
    ├─ Génère mise-en-demeure.pdf
    ├─ Génère saisine-mediateur.pdf  (+ CERFA XML)
    ├─ Génère calendrier-procedural.ics (iCal)
    ├─ Génère dossier-index.pdf
    │
    ▼
Archive ZIP "dossier-{ref}.zip"
```

L'utilisateur télécharge le ZIP, imprime ce qui doit l'être, envoie ce qui doit l'être. Chaque étape traçable, chaque template auditable, chaque délai dans son agenda.

---

## Rôle dans Self-Right

| SelfJustice (diagnostic) | SelfAct (action) |
|-------------------------|-------------------|
| Qualifie le conflit | Génère les documents correspondants |
| Cite les articles applicables | Les met dans le bon document formel |
| Identifie la juridiction | Rédige la saisine avec les bonnes pièces |
| Extrait les délais | Pré-remplit le calendrier (ics) |
| Dit ce qui est possible | Dit ce qu'il faut signer |

Sans SelfAct, SelfJustice est une consultation qui finit sur le bureau de l'utilisateur. Avec SelfAct, c'est un workflow qui finit avec un reçu RAR signé à La Poste.

---

## Statut

**alpha 0.0.1 — phase de conception.**

- [x] Concept paper
- [ ] Bibliothèque de templates (mise en demeure × 8 scénarios, saisines × 12 juridictions)
- [ ] Matching CERFA et pré-remplissage XML
- [ ] Moteur de calendrier (art. 640 CPC, règles dies a quo / dies ad quem)
- [ ] Rendu PDF (pipeline weasyprint)
- [ ] Adaptateur d'intégration pour la sortie API SelfJustice
- [ ] Prototype v0.1.0 sur `justice.my-self.fr/act`

Voir **[whitepaper](docs/whitepaper.docx)** pour la spécification complète du protocole, le plan de bibliothèque de templates, et la roadmap de déploiement.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfAct — La lettre est déjà écrite. Il suffit de la signer.*
