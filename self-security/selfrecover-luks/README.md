# SelfRecover-LUKS

> Pont entre le protocole **SelfRecover** (récupération d'accès sans email ni tiers) et le
> **chiffrement de disque LUKS2** : un mot de récupération mémorisé devient une clé qui ouvre
> un volume chiffré. Couche de **récupération souveraine du disque** du stack `lnmp_my-self`.

**Statut : validé sur banc (25/05/2026)** — PoC + test sur image jetable + wrapper d'intégration
au quorum. Intégration sur le disque réel d'une cible : à faire sur le serveur de destination.

## Le principe

Un mot racine mémorisé → dérivation **Argon2id** par **label** → clés filles cloisonnées :

| label | usage |
|-------|-------|
| `auth` | prouver / retrouver l'accès (SelfRecover web) |
| `data-enc` | chiffrer la donnée applicative (SelfDataGuard) |
| `disk` | **key-file d'un slot LUKS2** (ce module) |

Le label change le sel effectif → deux clés du même mot sont indépendantes (le serveur web
ne peut pas dériver la clé disque). C'est le *mapping Recover⇄DataGuard* étendu au FDE.

**Pourquoi Argon2id et pas le HMAC de l'auth web ?** Une clé de disque est bruteforçable
**hors-ligne** si le SSD est volé → il faut un KDF lent/memory-hard (≈63 ms + 64 MiB par essai
dans le PoC ; à monter en prod). Le HMAC rapide convient à l'auth (protégée par rate-limit en ligne),
pas au disque.

## Place dans l'architecture

Le SSD `/data` d'un serveur `lnmp_my-self` est en **LUKS2** avec plusieurs slots :

```
/data (LUKS2)
├── slot quorum      : déverrouillage AUTO au boot (parts distribuées sur des témoins)
├── slot SelfRecover : ce module — secours HUMAIN sans email/tiers
└── slot air-gapped  : passphrase de secours hors-ligne (coffre)
```
Par-dessus, **SelfDataGuard** chiffre les champs sensibles (lisibles même disque ouvert → anti-dump applicatif).

**Filet croisé** : le slot quorum n'est *jamais* retiré. Si un témoin tombe, le mot SelfRecover
ouvre le volume ; si le mot est oublié, le quorum ouvre toujours. Deux voies, un même volume.

## Composants

| Fichier | Rôle |
|---------|------|
| `selfrecover_derive.py` | dérive une clé déterministe `(mot, sel, label) → Argon2id` |
| `keyguard-luks-unlock.py` | déverrouillage **quorum réseau**, avec **fallback SelfRecover** si le quorum est KO (boot-safe : sans TTY, sort proprement) |
| `setup-add-selfrecover-slot.sh` | ajoute un slot SelfRecover à un volume, autorisé par une clé existante (master quorum ou passphrase) |
| `add-slot-via-quorum.sh` | enchaîne *quorum → master tmpfs → ajout du slot → shred*. `--dry-run` reconstitue + vérifie sans rien modifier |
| `selfrecover-unlock.sh` | déverrouillage de secours autonome (mot → `luksOpen`) |
| `test-luks-selfrecover.sh` | PoC bout-en-bout sur **image jetable** (ajout slot, ouverture, redérivation au boot, refus mauvais mot) |
| `test-phase2-image.sh` | valide sur **image jetable** que les deux voies (master quorum + mot SelfRecover) ouvrent le même volume |

```bash
# dérivation seule
python3 selfrecover_derive.py --word "<mot de récup>" --salt "<sel déploiement>" --label disk --format raw

# test complet sur image jetable (aucun vrai disque touché)
sudo apt install cryptsetup
sudo bash test-phase2-image.sh
```

## Configuration

Tout est externalisé (aucune valeur d'infra en dur). Copier **`keyguard.conf.example`** en
`keyguard.conf` (ignoré par git) et renseigner les témoins, chemins et device — ou passer les
mêmes clés en variables d'environnement (`WITNESSES`, `KEYGUARD_DIR`, `LUKS_DEVICE`, …).

## Garde-fous

- Mot de récupération **fort** (diceware) — le KDF ralentit, il ne compense pas un mot faible.
- Le slot SelfRecover est un **secours**, pas le déverrouillage quotidien.
- `/data` se déverrouille **post-boot** (pas l'OS) → pas d'initramfs requis, juste un service systemd.
- Aucune destruction automatique : l'ajout de slot est explicite, les clés transitent en tmpfs et sont `shred`.

## À faire

- Intégration au **boot orchestré** sur la cible (tente le déverrouillage auto, sinon prompt SelfRecover).
- Paramètres Argon2id de **production** (`memory_cost` ↑ pour viser ~0,5–1 s/essai).

AGPL-3.0-or-later · écosystème [MySelf](https://my-self.fr)
