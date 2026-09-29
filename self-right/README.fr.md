# Self-Right

> 🇬🇧 **[Read in English →](./README.md)**

**Accès au droit + capacité d'agir.**

> *Connais tes droits, fais-les valoir.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfJustice : v0.4.2 bêta](https://img.shields.io/badge/SelfJustice-v0.4.2%20b%C3%AAta-green.svg)](./selfjustice/)
[![SelfAct : v0.1.3](https://img.shields.io/badge/SelfAct-v0.1.3-brightgreen.svg)](./selfact/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

---

## La tension qu'il adresse

L'accès au droit en France est formellement égal. En pratique, il demande :
- De lire du texte juridique (codé, archaïque, plein de renvois)
- D'identifier quelle loi s'applique à ta situation
- De quantifier tes chances
- De connaître la bonne procédure (médiation, courrier, tribunal, quel tribunal)
- De remplir le bon formulaire dans le bon délai
- De payer un avocat, ou de te représenter toi-même

Chacune de ces étapes est un filtre. La plupart des gens abandonnent aux deux premières. Connaître ses droits ne sert à rien si on ne sait pas les faire valoir. **Le droit n'est accessible qu'à ceux qui ont déjà une littératie juridique** — une inégalité auto-entretenue.

Self-Right s'attaque à deux de ces filtres : **lire le texte (SelfJustice), puis trouver la démarche et son délai (SelfAct)**.

---

## Pourquoi les deux modules vont ensemble

**SelfJustice seul** sert le droit — articles en vigueur, textes européens, jurisprudence — pour que ton IA s'appuie sur les textes en vigueur plutôt que sur sa mémoire. Tu sais alors ce que dit la loi, pas encore comment agir : quel formulaire, quel service en ligne, quel délai, comment écrire le courrier.

**SelfAct seul** est un catalogue et des modèles de lettres. Sans le droit, tu pourrais te tromper de démarche.

**Ensemble :**

1. Tu décris ta situation à ta propre IA.
2. Ton IA lit le droit par SelfJustice et t'explique ce qu'il dit, avec des références vérifiables.
3. SelfAct pointe la ressource officielle de la démarche, calcule le délai, et te donne un modèle de lettre à trous. Tu écris les faits, tu relis, tu signes, tu envoies.

Aucun des deux modules n'analyse ton cas à ta place : la consultation juridique est réservée aux professionnels du droit (loi n° 71-1130). Pour un avis sur ta situation, c'est à eux qu'il faut t'adresser.

---

## Exemples croisés

- **Conflit de voisinage (bruit)** → SelfJustice sert les textes sur le bruit et le voisinage → SelfAct pointe le signalement officiel des nuisances et la saisine du conciliateur de justice : pour un trouble anormal de voisinage, une tentative amiable — conciliation, médiation ou procédure participative — doit précéder le tribunal (art. 750-1 du code de procédure civile).
- **Litige avec un assureur** → SelfJustice sert les articles du code des assurances et la jurisprudence → SelfAct pointe la saisine du médiateur en assurances.
- **Licenciement contesté** → SelfJustice sert le code du travail → SelfAct pointe la requête de saisine du conseil de prud'hommes et calcule le délai.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfJustice](./selfjustice/) | Directives juridiques lisibles par machine + API ouverte du droit | **v0.4.2 bêta** — en ligne sur [justice.my-self.fr](https://justice.my-self.fr) |
| [SelfAct](./selfact/) | Ressources officielles, délais et modèles de lettres pour la démarche | **v0.1.3** — API, catalogue et pages en service |

---

## Statut

SelfJustice est **déployé en production** et sert n'importe quel agent IA (Claude, ChatGPT, Mistral, Gemini, Perplexity) avec tout le corpus juridique français indexé et les textes UE/CEDH, via une API HTTP ouverte. N'importe qui peut l'interroger, n'importe qui peut l'auto-héberger. Compteurs en direct : [`/api/status`](https://justice.my-self.fr/api/status).

SelfAct **tourne aussi**, et son dossier le montre : [`selfact/`](./selfact/) porte son code, ses données, ses garde-fous, son déploiement, son whitepaper et sa licence.

| Brique | Où | Ce qu'elle fait |
|---|---|---|
| Catalogue | [`selfact/api/`](./selfact/api/) | plus de 1 800 ressources officielles moissonnées sur service-public.gouv.fr, rangées en 16 catégories — le compte exact et la ventilation par type sont servis en direct par `/act/api/catalog.php?stats=1`. Rafraîchi les 1er et 15. |
| Aiguillage | [`selfact/api/find.php`](./selfact/api/find.php) | une vingtaine de situations curées à la main : « je me fais licencier » → l'acte, l'article, le formulaire. |
| Calcul de délai | [`selfact/api/deadline.php`](./selfact/api/deadline.php) | Le seul endroit qui calcule au lieu de restituer, avec export agenda. |
| Gabarit de courrier | [`selfact/api/draft.php`](./selfact/api/draft.php) | Mise en demeure, saisine (conciliateur, Défenseur des droits), recours gracieux, résiliation, plainte — chacun portant la mention « NON OFFICIEL » dans le corps quand il imite la forme d'un acte, et le rappel en pied dans tous les cas, et les ressources officielles correspondantes. |

Quatre des douze outils MCP SelfRight sont ceux de SelfAct. Les deux modules sont servis par le même domaine — `justice.my-self.fr/act` — et n'échangent aucun appel : SelfJustice dit le droit, SelfAct pointe la démarche.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Right — Le droit ne devrait pas être un mur. Il devrait être un outil.*
