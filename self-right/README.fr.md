# Self-Right

> 🇬🇧 **[Read in English →](./README.md)**

**Accès au droit + capacité d'agir.**

> *Connais tes droits, fais-les valoir.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfJustice : v0.1.0 bêta](https://img.shields.io/badge/SelfJustice-v0.1.0%20b%C3%AAta-green.svg)](./selfjustice/)
[![SelfAct : v0.1.2](https://img.shields.io/badge/SelfAct-v0.1.2-brightgreen.svg)](./selfact/)
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

**SelfAct seul** est une bibliothèque de modèles — utile, mais dangereux sans contexte. Une mise en demeure avec la mauvaise base légale est pire qu'une absence de courrier.

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
| [SelfJustice](./selfjustice/) | Directives juridiques lisibles par machine + API ouverte du droit | **v0.1.0 bêta** — en ligne sur [justice.my-self.fr](https://justice.my-self.fr) |
| [SelfAct](./selfact/) | Courriers, formulaires et délais bâtis sur cette analyse | **v0.1.2** — API, catalogue et pages en service |

---

## Statut

SelfJustice est **déployé en production** et sert n'importe quel agent IA (Claude, ChatGPT, Mistral, Gemini, Perplexity) avec tout le corpus juridique français indexé et les textes UE/CEDH, via une API HTTP ouverte. N'importe qui peut l'interroger, n'importe qui peut l'auto-héberger. Compteurs en direct : [`/api/status`](https://justice.my-self.fr/api/status).

SelfAct **tourne aussi**, et son dossier le montre : [`selfact/`](./selfact/) porte son code, ses données, ses garde-fous, son déploiement, son whitepaper et sa licence.

| Brique | Où | Ce qu'elle fait |
|---|---|---|
| Catalogue | [`selfact/api/`](./selfact/api/) | plus de 1 800 ressources officielles moissonnées sur service-public.gouv.fr, rangées en 16 catégories — le compte exact et la ventilation par type sont servis en direct par `/act/api/catalog.php?stats=1`. Rafraîchi les 1er et 15. |
| Aiguillage | [`selfact/api/find.php`](./selfact/api/find.php) | 20 situations curées à la main : « je me fais licencier » → l'acte, l'article, le formulaire. |
| Calcul de délai | [`selfact/api/deadline.php`](./selfact/api/deadline.php) | Le seul endroit qui calcule au lieu de restituer, avec export agenda. |
| Gabarit de courrier | [`selfact/api/draft.php`](./selfact/api/draft.php) | Mise en demeure, saisine (conciliateur, Défenseur des droits), contestation, résiliation, recours — chacun avec un filigrane « NON OFFICIEL — IRRECEVABLE » non supprimable à l'impression, et les ressources officielles correspondantes. |

Quatre des douze outils MCP SelfRight sont ceux de SelfAct. Les deux modules sont servis par le même domaine — `justice.my-self.fr/act` — et n'échangent aucun appel : SelfJustice dit le droit, SelfAct fait la démarche.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Right — Le droit ne devrait pas être un mur. Il devrait être un outil.*
