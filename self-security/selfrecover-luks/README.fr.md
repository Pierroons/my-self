# SelfRecover-LUKS

> 🇬🇧 **[Read in English →](./README.md)**

> Déverrouillage de disques chiffrés **LUKS2** — volume racine **et** volumes de données —
> par une **unique passphrase de récupération**, à distance dès le démarrage, sans cloud ni
> tiers de confiance. Couche FDE auto-hébergée de l'écosystème **MySelf** (pilier Self-Security).

**Statut : validé sur serveur LNMP Debian 13 Trixie (07/06/2026) — v0.3.0.**
Déverrouillage du `/` au boot (keyscript Argon2id + SSH d'amorçage) et cascade automatique des
volumes secondaires (fichier-clé), redémarrages reproductibles. Installation documentée et
reproductible → **[INSTALL.md](./INSTALL.md)**.

## Le principe

Une passphrase de récupération mémorisée → dérivation **Argon2id** par **label** → clés filles cloisonnées :

| label | usage |
|-------|-------|
| `auth` | prouver / retrouver l'accès (SelfRecover web) |
| `data-enc` | chiffrer la donnée applicative (SelfDataGuard) |
| `disk` | **clé d'un slot LUKS2** (ce module) |

Le label change le sel effectif → deux clés du même secret sont indépendantes. Argon2id
(memory-hard) car une clé de disque est attaquable **hors-ligne** en cas de vol du support.

## Architecture

Une seule saisie de la passphrase recover ouvre **toute** la machine :

```
Passphrase recover (saisie une fois, à distance via SSH d'amorçage)
   │  dérivation Argon2id (label « disk »)
   ├──► VOLUME RACINE (/)  : keyscript dans l'initramfs → ouvre / au boot
   └──► VOLUMES SECONDAIRES : fichier-clé stocké sur / (chiffré) → ouverture
                              automatique après le pivot (cascade)
```

- **Accès distant au boot** : un serveur SSH minimal (dropbear) embarqué dans l'initramfs ;
  l'administrateur saisit sa passphrase.
- **Cascade** : les volumes non-racine sont ouverts par `systemd-cryptsetup` via un fichier-clé
  rangé dans le coffre racine chiffré (un disque volé reste illisible).
- **Filet anti-verrouillage** : chaque volume garde un slot **natif** (passphrase classique),
  jamais retiré, ouvrable manuellement si le keyscript défaille.

> Une piste **quorum** (déverrouillage auto par consensus de témoins, sans saisie) est décrite
> dans le whitepaper (travaux futurs) ; elle n'est pas activée dans cette version.

## Composants

| Fichier | Rôle |
|---------|------|
| `selfrecover_derive.c` | dérivation Argon2id (clone C autonome pour l'initramfs ; stdin → clé brute) |
| `selfrecover_derive.py` | implémentation de référence (Python, usage userspace) |
| `selfrecover-keyscript.sh` | keyscript du volume racine (dérive la passphrase recover) |
| `initramfs-hook-selfrecover` | embarque binaire + libargon2 + **libgcc** + sel + keyscript dans l'initrd |
| `setup-add-selfrecover-slot.sh` | ajoute un slot recover à un volume LUKS (autorisé par une clé existante) |
| `selfrecover-unlock.sh` | déverrouillage de secours autonome (userspace) |
| `install.sh` | installateur semi-automatique (cf. INSTALL.md) |
| [`quorum-rnd/`](./quorum-rnd/) | R&D : déverrouillage par quorum de témoins — **non activé en v0.3.0** |

## Installation

Guide complet pas-à-pas : **[INSTALL.md](./INSTALL.md)**. En résumé : compiler la dérivation,
déployer keyscript + hook, générer le sel, ajouter les slots recover, configurer le volume
racine (keyscript + dropbear + rootdelay) et les volumes secondaires (fichier-clé), régénérer
l'initramfs, **tester par redémarrage avec filet**.

Document d'architecture (le *pourquoi*) : **[SelfRecover-LUKS_Whitepaper](./docs/SelfRecover-LUKS_Whitepaper.md)** — aussi en [DOCX à télécharger](https://github.com/Pierroons/my-self/raw/main/self-security/selfrecover-luks/docs/SelfRecover-LUKS_Whitepaper.docx).

## Garde-fous

- Passphrase recover **forte** (diceware) — le KDF ralentit, il ne compense pas un secret faible.
- **Slot natif conservé** sur chaque volume + sauvegarde de l'initramfs avant régénération.
- **Récupération catastrophe** : conserver hors-site (gestionnaire de mots de passe) la passphrase,
  le **sel de déploiement** et les secrets de sauvegarde — sans le sel, pas de re-dérivation sur matériel neuf.
- Aucune destruction automatique : ajout de slot explicite, clés en tmpfs.

AGPL-3.0-or-later · écosystème [MySelf](https://my-self.fr)
