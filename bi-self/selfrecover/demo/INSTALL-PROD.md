# SelfRecover — installation en production (durcissement)

Ce module tourne **secure-by-default** : sans les variables de démo, il refuse de démarrer
si un secret vaut encore une valeur de démo (garde-fou « fail fast »). Suivre les étapes ci-dessous.

## 1. Secrets à générer et poser

Copier `.env.example` et remplir (voir les commentaires) :

```bash
# Secret serveur (anti-énumération) et secret d'audit HMAC
openssl rand -hex 32   # → SELFRECOVER_SERVER_SECRET
openssl rand -hex 32   # → SELFRECOVER_SU_AUDIT_SECRET   (FIXE une fois pour toutes)

# Secret SuperUser : HASH Argon2 d'une passphrase diceware (jamais la passphrase en clair)
php -r 'echo password_hash("ta-passphrase-diceware", PASSWORD_ARGON2ID), "\n";'
# → SELFRECOVER_SU_SECRET   (posé seulement dans l'env du CLI selfrecover-su)
```

- La **passphrase SU** en clair : à ranger hors ligne / gestionnaire chiffré (jamais dans un `.env` commité).
- Le **secret d'audit** ne doit **plus jamais changer** : le modifier rend tout l'historique du log invérifiable (propriété voulue).

## 2. Chemins hors webroot (données writable, code immutable)

```bash
sudo mkdir -p /var/lib/selfrecover
sudo chown <user-php>:<user-php> /var/lib/selfrecover
sudo chmod 750 /var/lib/selfrecover
```

Pointer `SELFRECOVER_DB_PATH`, `SELFRECOVER_STATE_DIR`, `SELFRECOVER_SU_AUDIT_LOG` dedans.
Le code (`api/`, `*.html`) reste en lecture seule (chattr +i côté déploiement immutable).

## 3. Log SU append-only + externalisation

```bash
# Rendre le log append-only (résiste à la réécriture même par le compte web)
touch /var/lib/selfrecover/su-audit.log
sudo chattr +a /var/lib/selfrecover/su-audit.log

# ntfy : pousser les events SU hors-serveur en temps réel
# SELFRECOVER_NTFY_URL=https://ntfy.example/your-topic
```

## 4. Contrôles de mise en service

```bash
# Le garde-fou doit laisser passer (secrets réels posés) :
php -r 'require "api/db.php"; echo "boot OK\n";'

# Intégrité du log après la première action SU :
SELFRECOVER_SU_SECRET=<hash> php selfrecover-su verify-log
```

## 5. Console admin

Pas de lien public. Accès par **URL directe bookmarkée** : `/selfrecover/admin.html`.
Le thème se calque sur le site hôte quand la console est ouverte depuis lui (postMessage / localStorage même-origine).

## Rappels

- **DEBUG reste false** en prod (défaut). Le `_trace` n'est jamais exposé.
- **DEMO_PURGE reste false** (défaut) : aucune purge automatique de comptes.
- Sauvegarde du log SU : `selfrecover-su backup-log <dest>` (scellé AES-256-GCM), à copier **hors du serveur**.
- Démo locale : `./serve-demo.sh` (réglages permissifs, à ne jamais utiliser en prod).
