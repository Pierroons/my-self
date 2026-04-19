# Self-Right

> 🇬🇧 **[Read in English →](./README.md)**

**Accès au droit + capacité d'agir.**

> *Connais tes droits, fais-les valoir.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfJustice: v0.1.0](https://img.shields.io/badge/SelfJustice-v0.1.0-green.svg)](./selfjustice/)
[![SelfAct: alpha 0.0.1](https://img.shields.io/badge/SelfAct-alpha%200.0.1-lightgrey.svg)](./selfact/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

---

## La tension qu'il adresse

L'accès au droit en France est formellement égal. En pratique, il demande :
- De lire du texte juridique (codé, archaïque, plein de renvois)
- D'identifier quelle loi s'applique à votre situation
- De quantifier vos chances
- De connaître la bonne procédure (médiation, courrier, tribunal, quel tribunal)
- De remplir le bon formulaire dans le bon délai
- De payer un avocat, ou de vous représenter vous-même

Chacune de ces étapes est un filtre. La plupart des gens abandonnent aux deux premières. Connaître ses droits ne sert à rien si on ne sait pas les faire valoir. **Le droit n'est accessible qu'à ceux qui ont déjà une littératie juridique** — une inégalité auto-entretenue.

Self-Right prend en charge l'arc complet en deux modules complémentaires : **comprendre le droit (SelfJustice), puis agir (SelfAct)**.

---

## Pourquoi les deux modules se renforcent mutuellement

**SelfJustice seul** produit une analyse juridique impartiale avec citations — mais vous laisse avec un document. Vous savez ce que dit la loi. Et maintenant ? Rien, sauf si vous savez rédiger une mise en demeure, identifier le tribunal compétent, remplir un CERFA, respecter un délai procédural. Pour 90 % des citoyens, c'est là qu'est le mur.

**SelfAct seul** serait une bibliothèque de modèles — utile, mais dangereux sans contexte. Une mise en demeure avec la mauvaise base légale est pire qu'une absence de courrier.

**Ensemble**, la chaîne est complète :

1. Vous décrivez votre situation en langage courant.
2. SelfJustice récupère les articles de loi réels, fait une analyse impartiale, identifie ce qui est défendable.
3. SelfAct prend cette analyse en entrée et génère des documents prêts à envoyer : mise en demeure, saisine du tribunal compétent, CERFA pré-rempli, calendrier des délais.

Du brouillard du « je pense que je suis dans mes droits » au « cette lettre part lundi matin » — en un workflow continu, à coût zéro.

---

## Workflows croisés

- **Trouble de voisinage sonore** → SelfJustice qualifie le conflit (nuisance sonore, art. R1336-5 CSP), extrait les articles et délais applicables → SelfAct génère la mise en demeure avec la bonne base légale, identifie le conciliateur de justice comme première étape, calcule la fenêtre de 15 jours pour répondre.
- **Refus d'indemnisation assurance** → SelfJustice identifie la clause applicable (exclusion formelle et limitée, L113-1 CCA), l'évalue contre la jurisprudence → SelfAct rédige la lettre de contestation + la saisine du Médiateur de l'Assurance avec le CERFA.
- **Harcèlement au travail** → SelfJustice cite L1152-1 Code du travail, identifie les exigences de preuves → SelfAct produit le courrier à l'employeur, la notification CSE/CSSCT, le formulaire prud'hommes (CERFA 15586*03) avec les sections pertinentes pré-remplies.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfJustice](./selfjustice/) | Pré-analyse juridique impartiale assistée par IA | v0.1.0 ✅ — en ligne sur [justice.my-self.fr](https://justice.my-self.fr) |
| [SelfAct](./selfact/) | Extension opérationnelle : courriers, formulaires, délais | alpha 0.0.1 — phase de conception |

---

## Statut

SelfJustice est **déployé en production** et sert n'importe quel agent IA (Claude, ChatGPT, Mistral, Gemini, Perplexity) avec 488 903 articles juridiques français officiels + 705 articles de traités UE/CEDH via une API HTTP ouverte. N'importe qui peut l'interroger, n'importe qui peut l'auto-héberger.

SelfAct est en **phase de conception** (alpha 0.0.1). Le whitepaper définit le périmètre (mise en demeure, saisine, CERFA, délais), l'architecture (templates de prompts + formulaires CERFA XML), et l'intégration avec la sortie de SelfJustice. L'implémentation prototype est prévue pour la v0.1.0 avec un déploiement live sur le même domaine (`justice.my-self.fr/act`).

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Right — Le droit ne devrait pas être un mur. Il devrait être un outil.*
