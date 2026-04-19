# SelfKeyGuard

> 🇬🇧 **[Read in English →](./README.md)**

**2FA matériel pour objets physiques — voitures, motos, maisons, tout ce qui se verrouille.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#statut)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.fr.md)
[![Companion of: SelfGuard](https://img.shields.io/badge/companion-SelfGuard-green.svg)](../selfguard/)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **La clé seule ne suffit pas. La présence, oui.**

---

## Le problème

Les objets physiques sont toujours protégés par une authentification **basée sur la possession** : celui qui détient la clé, la carte, le fob, est le propriétaire. Ce modèle est cassé depuis des décennies :

- **Les voitures** avec keyless entry sont volées en 90 secondes par attaque par relais (amplificateur dans la poche, complice à la portière).
- **Les motos** s'ouvrent avec un tournevis sur un design de barillet vieux de 15 ans.
- **Les maisons** ont une poignée de patterns de cylindre et des claviers électroniques à clés partagées.
- **Les badges NFC** (vélos en libre-service, salles de sport, voitures) sont clonés à distance avec un lecteur à 30 €.

La cause racine : le secret pour déverrouiller = une information unique qui **voyage physiquement** avec l'utilisateur. Voler le porteur vole le droit.

SelfKeyGuard introduit un **2FA cryptographique** pour les objets physiques, avec un prototype matériel assez bon marché (~14 €) pour équiper n'importe quelle serrure existante.

---

## Principe cœur

Au lieu de « cette clé ouvre cette serrure », SelfKeyGuard exige **deux facteurs** pour déverrouiller :

1. **Possession** : un signal de présence physique (badge NFC, appareil Bluetooth, ou radio courte portée avec code roulant).
2. **Preuve live de SelfGuard** : le téléphone appairé faisant tourner SelfGuard doit émettre une **réponse cryptographique à un défi, non rejouable**.

Aucun facteur ne marche seul. Un badge volé est inutile sans le téléphone. Un téléphone cloné est inutile sans le badge. Un échange enregistré est inutile car le défi roule.

```
L'objet s'éveille (l'utilisateur approche)
    ↓
L'objet envoie un défi aléatoire C au téléphone appairé
    ↓
SelfGuard (sur le téléphone) calcule R = HMAC-SHA256(shared_key_stockée, C || timestamp)
    ↓
L'objet vérifie R et contrôle la dérive temporelle (< 30 s)
    ↓
Si les deux OK + badge physique présent : déverrouillage
```

Sous contrainte, l'utilisateur active la passphrase de contrainte de SelfGuard. La shared_key est effacée. L'objet ne peut plus recevoir de R valide. Il reste verrouillé. L'attaquant a le téléphone et le badge — et une brique.

---

## Matériel de référence

Le prototype est un module **ESP32** (~14 €) + un micro-relais pour le circuit immobiliseur d'origine de la voiture :

| Composant | Rôle | Coût |
|-----------|------|------|
| ESP32-S3 | Radio + moteur crypto | ~8 € |
| MAX485 | Pont RS485 vers CAN (intégration voiture) | ~2 € |
| Micro-relais (5V, 10A) | Coupe/active l'allumage ou la ligne de déverrouillage | ~3 € |
| Module NFC (optionnel) | Vérification du badge physique | ~4 € |
| Boîtier + câblage | — | ~3 € |

Total : **~14-20 €** selon options. Firmware ouvert, matériel auditable, réutilisable pour motos, serrures de maison, coffres, boîtes de stockage.

---

## Installation type (moto)

1. Installer l'ESP32 + relais sur le circuit d'allumage (2 fils coupés, 2 fils vers le relais).
2. Appairer le téléphone avec SelfKeyGuard via QR code + secret partagé dans SelfGuard.
3. Configurer : badge NFC (optionnel) + présence téléphone requise.
4. Terminé. La moto ne démarre que quand le badge + le téléphone (avec réponse live SelfGuard) sont à quelques mètres.

Moto volée sur un camion à 100 km ? L'ESP32 ne validera pas — pas de téléphone en portée, pas de réponse. L'allumage reste mort. Les voleurs amateurs abandonnent. Les voleurs pros avec du temps peuvent physiquement by-passer l'allumage, mais au coût de détruire le système électrique de la bécane — ce qui augmente significativement le coût du vol.

---

## Rôle dans Self-Security

SelfKeyGuard est le **bras matériel** de Self-Security : il étend la résistance à la coercition de SelfGuard dans le monde physique. Sans SelfGuard, les shared keys de SelfKeyGuard seraient stockées dans un coffre téléphone normal — compromises par contrainte, perdues. Avec SelfGuard, la contrainte détruit les shared keys, et la voiture / moto / maison devient inutile à l'attaquant.

---

## Statut

**alpha 0.0.1 — phase prototype.**

- [x] Conception du protocole
- [x] Nomenclature matérielle
- [x] Firmware ESP32 de référence (cas d'usage moto, testé sur la bécane de l'auteur)
- [ ] App compagnon téléphone (iOS + Android)
- [ ] API d'intégration SelfGuard
- [ ] Audit de sécurité formel
- [ ] Guides d'installation pour cibles courantes (Peugeot, Yamaha, serrures Bosch)
- [ ] Certification CE pour vente grand public (long terme)

Voir **[whitepaper](docs/whitepaper.docx)** pour la spec matérielle complète, le protocole cryptographique, le guide d'installation, et l'analyse des attaques.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfKeyGuard — Ta voiture ne démarre que parce que ton téléphone le dit.*
