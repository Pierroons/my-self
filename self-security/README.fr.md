# Self-Security

> 🇬🇧 **[Read in English →](./README.md)**

**Protection numérique et physique.**

> *Force-moi et tu n'auras rien.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfGuard: alpha 0.0.1](https://img.shields.io/badge/SelfGuard-alpha%200.0.1-lightgrey.svg)](./selfguard/)
[![SelfKeyGuard: alpha 0.0.1](https://img.shields.io/badge/SelfKeyGuard-alpha%200.0.1-lightgrey.svg)](./selfkeyguard/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

---

## La tension qu'il adresse

Les produits de sécurité actuels défendent contre un seul modèle d'attaquant : **l'attaquant distant qui ne vous a pas en face**. Ils supposent que vous êtes prêt à donner la clé, juste lentement. Ce modèle est cassé dans deux directions :

1. **La coercition numérique est réelle.** Un fonctionnaire, un cambrioleur, un conjoint violent peut vous forcer à déverrouiller votre téléphone, votre ordinateur, votre cloud. « Saisis ton code PIN ou je te casse les doigts » n'a aucune réponse technique si votre donnée existe sous forme directement accessible. La biométrie aggrave le problème — elle élimine tout déni plausible.
2. **Les objets physiques utilisent encore une sécurité des années 1970.** Votre voiture démarre avec un bout de métal emboutit ou une clé NFC clonée. Votre moto disparaît en 90 secondes. La corrélation entre « j'ai la clé » et « je suis le propriétaire » ne tient plus.

Self-Security traite les deux dimensions avec le même principe : **l'état par défaut est verrouillé, la présence est requise pour déverrouiller, la coercition ne donne rien**.

---

## Pourquoi les deux modules se renforcent mutuellement

**SelfGuard seul** est un coffre-fort de données avec protection contre la contrainte. Bien, mais votre téléphone est encore un objet numérique — et le monde physique ? Votre voiture, votre trottinette, votre maison ?

**SelfKeyGuard seul** est du 2FA matériel pour objets. Bien, mais les clés qui authentifient ces objets vivent toujours quelque part — sur votre téléphone, dans votre tiroir — vulnérables aux mêmes attaques de coercition.

**Ensemble**, le périmètre de sécurité se ferme :

- SelfGuard stocke les clés (voitures, maisons, objets) dans un stockage qui s'auto-détruit sous contrainte (passphrase de contrainte, bouton panique).
- SelfKeyGuard utilise ces clés pour authentifier les objets physiques, **avec rien de persistant sur l'objet lui-même** — l'objet vérifie une preuve de présence que seul SelfGuard peut produire.
- Vous forcer à déverrouiller SelfGuard détruit les clés. L'objet ne peut plus être authentifié. L'attaquant obtient une brique.

Un module protège les données. L'autre protège les objets. La résistance à la coercition est la même : **sous pression, le système s'auto-détruit plutôt que de trahir son propriétaire**.

---

## Workflows croisés

- **Contrôle routier, téléphone saisi** → SelfGuard demande la passphrase. Le propriétaire saisit la passphrase de contrainte. Données visibles = profil leurre (quelques photos, apps mainstream). Vraies données + clé voiture = effacées. Le fonctionnaire obtient un téléphone d'apparence normale sans rien dessus.
- **Cambrioleur chez vous avec le téléphone** → Même mécanisme. Codes d'ouverture du coffre, clés crypto, tokens SelfKeyGuard = détruits. Le coffre reste fermé, la voiture ne démarre pas.
- **Téléphone perdu, pas volé** → Déverrouillage normal = tout intact. Celui qui le trouve n'obtient aucune donnée car le téléphone est juste verrouillé normalement. Aucune différence en UX visible, différence massive en résistance à la coercition.
- **Tentative de vol de voiture** → Keyless entry défait via attaque par relais ? Peu importe, SelfKeyGuard exige une preuve de présence live depuis SelfGuard. Pas de SelfGuard disponible = la voiture ne démarre pas même si la portière s'ouvre.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfGuard](./selfguard/) | Coffre-fort de données avec destruction garantie sous contrainte | alpha 0.0.1 — phase concept |
| [SelfKeyGuard](./selfkeyguard/) | 2FA matériel pour objets physiques (voiture, moto, maison) | alpha 0.0.1 — phase concept |

---

## Statut

Les deux modules sont en **phase concept** (alpha 0.0.1). Les whitepapers définissent les modèles de menace, la conception cryptographique, et les exigences matérielles. SelfKeyGuard est particulièrement concret : un prototype ESP32 à ~14 € peut sécuriser un allumage de moto avec 2FA matériel, testé et documenté.

Le déploiement en production est prévu après un audit de sécurité indépendant et une période d'essai physique sur les véhicules de l'auteur. C'est du code et du matériel critiques pour la sécurité ; la vitesse n'est pas une vertu ici.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Security — Le seul mot de passe qui vaille est celui qui se détruit tout seul.*
