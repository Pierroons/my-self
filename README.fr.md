# MySelf

> 🇬🇧 **[Read this page in English →](./README.md)**

**Be yourself, for yourself.**

> L'humain apporte l'entropie. La machine apporte l'impartialité.
> Aucun des deux ne suffit seul. Ensemble, ils sont souverains.

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Auto-hébergé](https://img.shields.io/badge/auto--hébergé-Raspberry%20Pi-blue.svg)](#prérequis)
[![Zero cloud](https://img.shields.io/badge/cloud-zéro-brightgreen.svg)](#philosophie)
[![Zero tracking](https://img.shields.io/badge/tracking-zéro-brightgreen.svg)](#philosophie)

---

## Le pacte Self

MySelf est un écosystème open source de modules fondé sur un principe unique :
**la complicité entre l'humain et la machine**. Chaque module résout un
problème concret du quotidien **sans dépendre d'aucun tiers** — pas de
GAFAM, pas de service cloud, pas d'autorité centrale.

Les humains apportent l'entropie : leur vécu, leurs choix, leurs secrets.
Les machines apportent l'impartialité : analyse structurée, garanties
cryptographiques, processus déterministes. Aucun des deux ne suffit seul.
Ensemble, ils rendent l'individu souverain sur sa propre identité, ses
droits, ses données et ses biens.

---

## Modules

| Module | Question à laquelle il répond | Statut |
|--------|------------------------------|--------|
| [SelfRecover](./bi-self/selfrecover/) | Qui es-tu ? | v0.1.0 ✅ |
| [SelfModerate](./bi-self/selfmoderate/) | Comment tu te comportes ? | v0.1.0 (concept) |
| [SelfJustice](https://justice.my-self.fr) | Quels sont tes droits ? | bêta v0.1.0 ✅ |
| [SelfAct](https://justice.my-self.fr/act) | Comment tu les fais valoir ? | bêta v0.1.2 ✅ |
| [SelfGuard](./self-security/selfguard/) | Comment protéger tes données ? | concept |
| [SelfKeyGuard](./self-security/selfkeyguard/) | Comment protéger tes objets ? | concept |
| [SelfInvoice](./selfinvoice/) | Comment facturer tes clients ? | bêta (Factur-X natif) |
| **[SelfFarm-Lite](https://selffarm.my-self.fr)** | **Comment piloter ton exploitation ?** | **v0.2 live ✅** |

---

## Ensembles nommés (les trois piliers)

Certains modules forment des **binômes qui se renforcent mutuellement** —
plus que la somme de leurs parties. MySelf s'organise autour de trois
binômes, chacun couvrant une dimension de la souveraineté personnelle.

### Bi-Self — Identité souveraine et autonomie communautaire
**SelfRecover + SelfModerate**

Une identité fiable rend la modération par vote résistante aux attaques
Sybil. La modération collective protège contre les comportements toxiques.
Ensemble, ils permettent l'auto-gouvernance d'une communauté en ligne sans
dépendre d'une autorité centrale ou d'une plateforme corporate.

> *Si une communauté peut se construire elle-même, elle peut se gouverner elle-même.*

### Self-Right — Accès au droit et capacité d'agir
**SelfJustice + SelfAct**

Connaître ses droits ne suffit pas si on ne sait pas comment les faire
valoir. Ce binôme couvre l'arc complet de l'auto-émancipation juridique :
du diagnostic (SelfJustice — que dit le droit dans ta situation ?) à
l'action (SelfAct — comment rédiger le courrier recommandé, remplir
le formulaire CERFA, calculer le délai ?).

> *Connais tes droits, fais-les valoir.*

### Self-Security — Protection numérique et physique
**SelfGuard + SelfKeyGuard**

Le numérique et le physique ne sont plus des domaines séparés. SelfGuard
protège tes données par destruction garantie (force-moi et tu perds tout,
même moi). SelfKeyGuard protège tes objets physiques par 2FA matérielle
(la voiture ne démarre que si ton téléphone est présent). Ensemble, ils
forment un périmètre de sécurité où le **mode par défaut est verrouillé**
et où la présence active est requise pour déverrouiller.

> *Force-moi et tu n'auras rien.*

---

## Module autonome

### SelfInvoice — facturation conforme, local-first

Génère des factures conformes avec **Factur-X natif** (PDF/A-3 + XML CII,
profil EN16931 — obligatoire en France à partir de septembre 2026 en
réception, 2027-2028 en émission). Multi-régime : franchise TVA
(art. 293 B CGI), micro-BA, réel simplifié/normal. Pas de cloud, pas
d'abonnement, pas de détention de fonds. Le client règle par virement
SEPA classique vers l'IBAN affiché sur la facture.

> *Ta facture. Ton template. Tes données. Terminé.*

---

## Étage applicatif — SelfFarm-Lite

Quand les trois piliers tiennent, **on peut construire au-dessus**.
SelfFarm-Lite est la première application concrète : un écosystème complet
de gestion d'exploitation pour jeunes agriculteurs (JA), nouveaux installés
(NA), exploitations en croisière (AGRI) et PME agricoles.

> *Quand l'identité, le droit et la sécurité sont en place, l'individu peut construire.*

SelfFarm-Lite contient 7 modules qui alimentent tous **un hub comptable
central unique** (`self_agri_book`) :

| Module | Rôle |
|--------|------|
| `self_agri_book` | **Hub compta central** — journal, grand livre, balance, compte de résultat, bilan, export FEC DGFIP (conforme art. L47 A-I LPF) |
| `self_invoice` | Facturation Factur-X native (BASIC / EN16931 / EXTENDED) — auto-écriture 411/701 vers le hub |
| `self_dnja` | Moteur prévisionnel jeune agriculteur — 4 ans + dossier PDF officiel CDOA |
| `self_aid` | Catalogue d'aides publiques sourcées aux autorités primaires (Légifrance, BOFiP, FranceAgriMer, MSA, portails régionaux) |
| `self_banking` | Parsers de relevés bancaires fake-first (Société Générale fait, CA/CM/Boursorama à venir). Les imports alimentent le hub avec lettrage auto 512/411, prélèvements récurrents, frais bancaires |
| `self_parcelles` | Visualisation cartographique des parcelles via IGN Géoportail (overlay cadastre + recherche WFS) |
| `self_achats` | Achats fournisseurs (semences, carburant, assurance) — 6xxx/401 vers le hub |

**Architecture hub-centrée** : chaque module alimente la même table SQLite
`ecritures_comptables`. Pas de double saisie. Dédup garantie par
l'unicité `(source_module, source_id)`. Source unique de vérité pour
l'expert-comptable, l'administration fiscale et l'exploitant lui-même.

**Démo live** : https://selffarm.my-self.fr

**Alignement philosophique** : SelfFarm-Lite utilise les trois piliers MySelf
comme fondations :
- **Bi-Self** : signer les documents légaux avec son identité SelfRecover,
  contribuer au catalogue partagé d'aides via SelfModerate
- **Self-Right** : SelfJustice pour les litiges agricoles (métayage,
  bailleur/preneur, contentieux réglementaire), SelfAct pour les formulaires
  CERFA (déclarations PAC, etc.)
- **Self-Security** : SelfGuard pour les identifiants bancaires sensibles,
  SelfKeyGuard pour la 2FA matérielle sur tracteurs / serres / hangars

Le même pattern peut être appliqué à **n'importe quelle autre profession** :
`SelfClinic-Lite` pour les praticiens de santé indépendants, `SelfCraft-Lite`
pour les artisans, `SelfStore-Lite` pour le commerce, etc. SelfFarm-Lite est
la première preuve que les trois piliers sont porteurs.

---

## La vision d'ensemble

MySelf adresse la **personne complète** à travers trois piliers et un étage
applicatif :

| Étage | Dimension |
|-------|-----------|
| **Bi-Self** | Sociale — qui tu es et comment tu interagis |
| **Self-Right** | Juridique — ce que tu peux défendre par le droit |
| **Self-Security** | Matérielle — ce que tu protèges concrètement |
| **SelfFarm-Lite** (étage applicatif) | Professionnelle — ce que tu construis et exploites |

Trois piliers, deux modules par pilier, plus le module autonome SelfInvoice,
plus SelfFarm-Lite comme premier étage applicatif au-dessus.

Aucun module n'est obligatoire.
Tu choisis ce qui correspond à tes besoins et tu auto-héberges ce que
tu veux contrôler.

---

## Philosophie

- **Open source** (AGPL v3) — code ouvert, audit communautaire, pas de boîte noire, et tout ce qui se construit sur MySelf doit aussi rester libre
- **Auto-hébergé** — tourne sur un Raspberry Pi ou ton propre serveur
- **Zéro cloud, zéro tracking, zéro base de données centralisée**
- **Souveraineté par conception** — l'utilisateur garde le contrôle total
  de son identité, ses données, ses clés
- **Mode par défaut = verrouillé** — les modules nécessitent la présence
  active pour se déverrouiller
- **Résistance à la contrainte** — codes sous contrainte, destruction
  garantie, racines de confiance matérielles
- **Émancipation, pas dépendance** — chaque module renforce la dignité
  et l'autonomie de la personne face aux systèmes

---

## Prérequis

- Un Raspberry Pi 4 (ou n'importe quel serveur basé sur Debian)
- PHP 8.0+ pour SelfRecover/SelfModerate (les autres varient selon le module)
- Un serveur web statique (nginx) pour SelfJustice
- Composants matériels pour les prototypes SelfKeyGuard (~14 € pour la version voiture)
- Aucune dépendance externe, aucun service cloud, aucun abonnement API requis

---

## Statut

MySelf est un écosystème vivant. Certains modules sont déployés et testés
en production (SelfRecover sur ARC PVE Hub, SelfJustice sur
[justice.my-self.fr](https://justice.my-self.fr)). D'autres sont au stade
conceptuel ou en prototypage.
La feuille de route évolue avec les retours d'usage réels plutôt qu'avec
une planification descendante.

---

## Contribuer

Chaque module a son propre `CONTRIBUTING.md`. L'esprit :

- Les revues de code sont les bienvenues
- Les traductions sont les bienvenues (FR/EN déjà disponibles, les autres aussi)
- Le prototypage matériel est bienvenu (notamment pour SelfKeyGuard)
- Les audits (sécurité, juridiques, accessibilité) sont très bienvenus
- **Les forks sont encouragés** — si tu construis un SelfHealth, SelfMoney,
  SelfSchool, ouvre une discussion et on l'ajoutera à la famille

---

## Licence

[AGPL-3.0-or-later](LICENSE) — copyleft fort. Tu peux l'utiliser, le
modifier, l'auto-héberger. Si tu bâtis un service au-dessus de MySelf
et que tu le proposes à d'autres, tu dois aussi publier tes modifications.
Les versions historiques sous MIT (avant le 19/04/2026) restent sous
leurs termes d'origine.

---

## Soutenir le projet

MySelf est auto-hébergé sur un Raspberry Pi 4, sans publicité, sans tracker,
sans sponsor commercial. Si le projet te sert ou te parle, un geste direct
aide à le maintenir en vie.

**[🙏 Soutenir via Viva Wallet](https://pay.vivawallet.com/pierroons)** — CB,
Apple Pay, Google Pay, PayPal. Commission minimale, compte pro indépendant.

---

## Auteur & coworking

**Pierroons** — un agriculteur qui code sur son temps libre.

**Ce projet n'a pas été écrit seul.** Chaque module, chaque ligne, chaque
whitepaper est le fruit d'un coworking continu avec **Claude Opus 4.7**
(Anthropic) : le produit est une vraie collaboration humain–IA. L'humain
apporte l'entropie (le vécu, la direction, le bon sens agricole). La
machine apporte la rigueur (la structure, la relecture, la cohérence
technique). Le "Self pact" dont parle ce README n'est pas théorique —
c'est la méthode d'écriture de tout MySelf.

*MySelf — Be yourself, for yourself.*
