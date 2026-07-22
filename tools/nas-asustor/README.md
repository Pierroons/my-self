# NAS NAS — Tor hidden service natif

Pack de scripts pour exposer le portail admin **ADM** d'un NAS NAS uniquement via un **hidden service Tor v3**, avec backup automatique des clés cryptographiques sur la clé USB du DEVSERVER.

## Composants

| Fichier | Rôle |
|---|---|
| `tor-nas.sh` | Script unique : `install` + `start` / `stop` / `restart` / `status` / `backup` / `update` |
| `torrc.template` | Template de config Tor (placeholder `@TOR_DIR@` remplacé à l'install) |
| `README.md` | Ce fichier |

## Architecture

```
                ┌──────────────────────────────────────┐
                │  NAS NAS (192.0.2.134)│
                │                                      │
                │   ADM portal :8000 (LHS NAS)     │
                │            ▲                         │
                │            │ 127.0.0.1:8000          │
                │            │                         │
                │   ┌────────┴────────┐                │
                │   │  Tor v3 daemon  │                │
                │   │  /opt/tor/      │                │
                │   └────────┬────────┘                │
                │            │ HS v3                   │
                │            ▼                         │
                │   <56-chars>.onion                   │
                └──────────────────────────────────────┘
                             │
                             ▼ (Tor circuit)
                ┌──────────────────────────────────────┐
                │  Tor Browser (admins)       │
                │  http://<onion>.onion → ADM admin    │
                └──────────────────────────────────────┘

Backup nightly :
  /opt/tor/hidden_service_adm/{hostname,hs_ed25519_*}
  → SCP via SSH key dédiée
  → DEVSERVER:/mnt/usb-backup/nas-nas/tor-backups/
```

## Pré-requis

- NAS NAS avec ADM 4.x à jour
- SSH activé temporairement sur le NAS (le temps de l'install)
- Compte admin avec accès root via SSH
- DEVSERVER (192.0.2.60) accessible depuis le NAS, clé USB montée à `/mnt/usb-backup/`
- Sur le DEVSERVER, utilisateur `deploy` avec `~/.ssh/authorized_keys` accessible

## Étapes d'installation

### 1. Préparation du NAS (une fois)

Dans l'interface ADM web :

- App Central → installer **opkg** (recommandé pour `opkg install tor` propre). Si opkg n'est pas dispo dans App Central officiel, suivre les paths B ou C de `tor-nas.sh install`.
- Activer SSH ponctuellement : Préférences → Service Web → SSH → Activer

### 2. Copier les scripts sur le NAS

Depuis ton poste de dev :

```bash
scp tor-nas.sh torrc.template admin@192.0.2.134:/tmp/
```

### 3. Lancer l'install

```bash
ssh admin@192.0.2.134
sudo /tmp/tor-nas.sh install
```

L'install :
1. Crée `/opt/tor/{bin,data,hidden_service_adm,backups,.ssh}`
2. Génère une clé SSH dédiée Ed25519 dans `/opt/tor/.ssh/nas-to-devserver` et **affiche la clé publique à copier sur le DEVSERVER**
3. Installe Tor (via opkg si dispo, sinon affiche les instructions manuelles)
4. Génère `/opt/tor/torrc` depuis `torrc.template`
5. Crée les entrées crontab : `@reboot` pour autostart + `30 4 * * *` pour backup quotidien à 04:30
6. Ne démarre PAS Tor (à faire manuellement à l'étape 5)

### 4. Autoriser le NAS à pousser les backups sur le DEVSERVER

Récupère la clé publique affichée par l'install, puis depuis ton poste :

```bash
SSH_KEY_DEVSERVER="$HOME/.ssh/id_rsa_serveur"
NAS_PUBKEY=$(ssh admin@192.0.2.134 cat /opt/tor/.ssh/nas-to-devserver.pub)
ssh -i "$SSH_KEY_DEVSERVER" deploy@192.0.2.60 "echo '$NAS_PUBKEY' >> ~/.ssh/authorized_keys"
```

Puis prépare le dossier cible sur le DEVSERVER :

```bash
ssh -i "$SSH_KEY_DEVSERVER" deploy@192.0.2.60 \
    "mkdir -p /mnt/usb-backup/nas-nas/tor-backups && chmod 700 /mnt/usb-backup/nas-nas"
```

### 5. Démarrer Tor et obtenir l'adresse `.onion`

```bash
ssh admin@192.0.2.134 sudo /opt/tor/bin/tor-nas.sh start
sleep 30  # le HS prend ~10-30 secondes à se publier
ssh admin@192.0.2.134 sudo /opt/tor/bin/tor-nas.sh status
```

Le `status` affiche la **vraie adresse `.onion` v3** (56 caractères + `.onion`). Note-la dans une note physique sécurisée.

### 6. Premier backup

```bash
ssh admin@192.0.2.134 sudo /opt/tor/bin/tor-nas.sh backup
```

Crée une archive `tor-backup-<timestamp>.tar.gz` contenant :
- `hostname` (l'adresse `.onion`)
- `hs_ed25519_secret_key` (clé privée — IRREMPLAÇABLE)
- `hs_ed25519_public_key`
- `torrc`
- `manifest.txt` (timestamp, hashes SHA256, version Tor, instructions de restauration)

L'archive est uploadée par SCP sur le DEVSERVER. La copie locale est conservée dans `/opt/tor/backups/` (rotation des 3 derniers).

### 7. Test depuis Tor Browser

Sur ton poste avec Tor Browser :

```
http://<l'adresse onion affichée par status>.onion
```

→ Tu dois voir l'écran de login ADM. Login avec ton mdp diceware admin.

### 8. Désactiver SSH

Une fois tout fonctionnel, retour ADM → désactiver SSH. Tu peux toujours le réactiver ponctuellement plus tard pour des opérations admin.

## Usage courant

```bash
sudo /opt/tor/bin/tor-nas.sh start      # démarrer Tor
sudo /opt/tor/bin/tor-nas.sh stop       # arrêter
sudo /opt/tor/bin/tor-nas.sh restart    # redémarrer
sudo /opt/tor/bin/tor-nas.sh status     # voir l'état + l'adresse .onion
sudo /opt/tor/bin/tor-nas.sh backup     # backup manuel (le cron en fait un /jour)
sudo /opt/tor/bin/tor-nas.sh update     # MAJ du binaire Tor
```

## Cron jobs créés par l'install

| Cron | Action |
|---|---|
| `@reboot tor-nas.sh start` | Démarre Tor automatiquement après chaque reboot du NAS |
| `30 4 * * * tor-nas.sh backup` | Backup nightly à 04:30 |

Logs : `/opt/tor/cron.log` + `/opt/tor/tor.log`

## Restauration de l'adresse `.onion` après reformatage

Si tu reformates le NAS, l'adresse `.onion` est perdue **sauf si tu restaures la clé privée**. Procédure :

```bash
# 1. Réinstaller : sudo /tmp/tor-nas.sh install (mais NE PAS démarrer Tor)
# 2. Récupérer le dernier backup depuis le DEVSERVER :
scp -i "$SSH_KEY_DEVSERVER" \
    "deploy@192.0.2.60:/mnt/usb-backup/nas-nas/tor-backups/tor-backup-<latest>.tar.gz" \
    /tmp/

# 3. Sur le NAS :
ssh admin@192.0.2.134
sudo tar -xzf /tmp/tor-backup-<latest>.tar.gz -C /opt/tor/hidden_service_adm/
sudo chmod 600 /opt/tor/hidden_service_adm/hs_ed25519_secret_key
sudo chmod 700 /opt/tor/hidden_service_adm
sudo /opt/tor/bin/tor-nas.sh start
sudo /opt/tor/bin/tor-nas.sh status   # vérifie : même .onion qu'avant
```

## Vérifier qu'aucun service ADM n'est exposé sur Internet

Depuis un poste hors-LAN (4G téléphone) :

```bash
curl -m 5 -I http://<ton_IP_publique>:8000/   # doit timeout / refused
curl -m 5 -I http://<ton_IP_publique>/        # doit timeout / refused
```

Si l'un des deux répond, ton routeur fait du port forwarding involontaire — désactiver UPnP du routeur immédiatement.

## Ce que ce setup NE protège PAS

- **Compromission du NAS lui-même** : si l'OS ADM est compromis (binaire Tor remplacé, backdoor admin), le hidden service devient un piège. Mitigation : MAJ ADM régulière + désactiver SSH par défaut + monitoring crontab.
- **Compromission du Tor Browser côté client** : un Tor Browser modifié peut rediriger ailleurs. Toujours télécharger depuis `torproject.org` avec vérification GPG.
- **Forçage de la chaîne par interception réseau côté FAI** : Tor protège la confidentialité du trafic mais pas l'existence d'un trafic Tor. Pour ça → Tor bridges + Snowflake.

## Licence

AGPL-3.0-or-later (cohérent avec l'écosystème MySelf).
