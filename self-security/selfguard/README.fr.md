# SelfGuard

> 🇬🇧 **[Read in English →](./README.md)**

**Coffre-fort de données avec destruction garantie sous contrainte.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#statut)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.fr.md)
[![Companion of: SelfKeyGuard](https://img.shields.io/badge/companion-SelfKeyGuard-green.svg)](../selfkeyguard/)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Force-moi et tu n'auras rien. Pas moi — rien.**

---

## Le problème

Tous les produits de stockage chiffré actuels (Signal, Bitwarden, KeePass, VeraCrypt, protection de données iOS) répondent au même modèle de menace : **l'attaquant est à distance, l'appareil est dans votre poche, le secret est uniquement dans votre tête**. Biométrie + code PIN = sécurité.

Ce modèle s'effondre dès que vous êtes **face à l'attaquant**. Un fonctionnaire qui demande votre téléphone. Un cambrioleur avec un couteau. Un conjoint violent. Un douanier corrompu. Trois scénarios réels où **déverrouiller est la mauvaise réponse** mais où il n'existe aucune option technique pour « détruire tout maintenant ».

Les outils actuels soit se conforment (donnent tout à l'attaquant) soit refusent (vous vous faites tabasser). SelfGuard prend une troisième voie : **le coffre s'auto-détruit avant de remettre quoi que ce soit**, et le fait d'une manière que l'attaquant ne distingue pas d'un déverrouillage normal.

---

## Principe cœur : deux passphrases, une interface

SelfGuard contient deux univers de données parallèles :

- **Passphrase normale** → déverrouille les vraies données (photos, messages, clés crypto, tokens d'auth SelfKeyGuard, tout).
- **Passphrase de contrainte** → déverrouille un **profil leurre** (quelques photos innocentes, quelques apps, un historique de messages factice). Simultanément : **toutes les vraies données et leurs clés sont effacées, irréversiblement**.

Du point de vue de l'attaquant, saisir la passphrase de contrainte révèle un téléphone avec du contenu. Pas d'alerte rouge, pas de panique, pas de différence d'UI. Du point de vue de l'utilisateur, ses vrais secrets viennent juste de disparaître à jamais.

L'UX est identique à un déverrouillage normal. C'est tout l'intérêt : **conformité plausible, destruction garantie**.

---

## Contraintes de conception

- **Destruction atomique** : l'effacement est effectué avant que le profil leurre n'apparaisse. Pas de fenêtre de récupération, pas d'état partiel.
- **Pas de biométrie** pour le déverrouillage : la biométrie élimine le déni plausible (un tribunal peut contraindre une empreinte, pas une passphrase dans votre tête). La biométrie peut gérer le profil leurre, pas le vrai.
- **Couche de stockage indépendante** : SelfGuard est un daemon au-dessus d'un stockage normal (SQLite, filesystem). Il gère l'enveloppe crypto et la logique de contrainte. L'intégration OS hôte est optionnelle — Linux, Android LineageOS, et PostmarketOS sont les plateformes cibles.
- **Audit ouvert** : chaque octet du chemin d'effacement est auditable. Pas de blob, pas d'effet de bord caché.

---

## Squelette cryptographique

```
master_key = argon2id(passphrase, salt, t=3, m=64MB, p=4)
master_key_duress = argon2id(duress_passphrase, salt, ...)

real_data = AEAD(AES-256-GCM, master_key, payload)
decoy_data = AEAD(AES-256-GCM, master_key_duress, decoy_payload)

on unlock(input):
    if argon2id(input, salt) == stored_key_hash: unlock_real()
    elif argon2id(input, salt) == stored_key_hash_duress:
        wipe_real_data_securely()  # atomique, pas de journal recovery
        unlock_decoy()
    else: deny()
```

Détails sur l'effacement sécurisé (variante DoD 5220.22-M pour flash, TRIM-aware, avec destruction de clé matérielle sur plateformes compatibles) dans le whitepaper.

---

## Rôle dans Self-Security

SelfGuard est la **couche de stockage résistante à la coercition**. Au-dessus, [SelfKeyGuard](../selfkeyguard/) construit des tokens d'authentification matériels pour objets physiques. Les deux modules supposent le modèle de menace « l'attaquant est face à moi » — coordonnés, ils forment un périmètre où **forcer l'utilisateur ne donne rien d'autre qu'un leurre**.

Sans SelfGuard, les clés d'auth de SelfKeyGuard vivent dans un coffre téléphone normal — compromettre par contrainte rend la clé. Avec SelfGuard, la contrainte détruit les clés avec le reste.

---

## Statut

**alpha 0.0.1 — phase concept.**

- [x] Brouillon de modèle de menace
- [x] Squelette cryptographique
- [ ] Implémentation de référence (daemon Linux, backend SQLite)
- [ ] Intégration Android (module LineageOS)
- [ ] Audit de sécurité (indépendant, requis avant v0.1.0)
- [ ] Étude UX pour la formation à la passphrase de contrainte

Voir **[whitepaper](docs/whitepaper.docx)** pour le modèle de menace complet, l'analyse du chemin d'effacement, et les spécificités de destruction de clé matérielle.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfGuard — Le seul coffre qui t'obéit, pas celui qui tient le couteau.*
