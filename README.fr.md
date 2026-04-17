# MySelf

> 🇬🇧 **[Read this page in English →](./README.md)**

**Be yourself, for yourself.**

> L'humain apporte l'entropie. La machine apporte l'impartialité.
> Aucun des deux ne suffit seul. Ensemble, ils sont souverains.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
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
| [SelfJustice](./self-right/selfjustice/) | Quels sont tes droits ? | v0.1.0 ✅ |
| [SelfAct](./self-right/selfact/) | Comment tu les fais valoir ? | idée |
| [SelfGuard](./self-security/selfguard/) | Comment protéger tes données ? | concept |
| [SelfKeyGuard](./self-security/selfkeyguard/) | Comment protéger tes objets ? | concept |
| [SelfInvoice](./self-bill/selfinvoice/) | Comment facturer tes clients ? | idée |
| [SelfCashpay](./self-bill/selfcashpay/) | Comment encaisser ? | idée |

---

## Ensembles nommés (les quatre piliers)

Certains modules forment des **binômes qui se renforcent mutuellement** —
plus que la somme de leurs parties. MySelf s'organise autour de quatre
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

### Self-Bill — Facturer et encaisser, sans intermédiaire
**SelfInvoice + SelfCashpay**

SelfInvoice génère des factures conformes (mentions légales art. L441-9
C. com., franchise TVA art. 293 B CGI, bordereaux) — juste un PDF, aucune
garde de fonds. SelfCashpay affiche un QR code SEPA (standard européen
EPC069-12) : le client scanne avec son appli bancaire, le virement se
pré-remplit, l'argent arrive direct sur ton IBAN. **Zéro commission,
zéro intermédiaire, aucun agrément bancaire nécessaire** parce que les
outils ne détiennent jamais de fonds. Parfait pour freelances, créateurs,
petites associations, tips.

> *Facture-le. Encaisse-le. Garde tout.*

---

## La vision d'ensemble

MySelf adresse la **personne complète** à travers quatre piliers :

| Pilier | Dimension |
|--------|-----------|
| **Bi-Self** | Sociale — qui tu es et comment tu interagis |
| **Self-Right** | Juridique — ce que tu peux défendre par le droit |
| **Self-Security** | Matérielle — ce que tu protèges concrètement |
| **Self-Bill** | Économique — comment tu gagnes ta vie sans intermédiaire |

Quatre piliers, deux modules par pilier. Aucun module n'est obligatoire.
Tu choisis ce qui correspond à tes besoins et tu auto-héberges ce que
tu veux contrôler.

---

## Philosophie

- **Open source** (MIT) — code ouvert, audit communautaire, pas de boîte noire
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

[MIT](LICENSE) — fais-en ce que tu veux, mais ne me blâme pas si ton chat
déverrouille ta voiture.

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
