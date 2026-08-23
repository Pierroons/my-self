# NAS — Tor hidden service natif

Pack de scripts pour exposer le portail admin **ADM** d'un NAS uniquement via un **hidden service Tor v3**, avec backup automatique des clés cryptographiques sur la clé USB du serveur de sauvegarde.

## Composants

| Fichier | Rôle |
|---|---|
| `tor-nas.sh` | Script unique : `install` + `start` / `stop` / `restart` / `status` / `backup` / `update` |
| `torrc.template` | Config Tor de référence, commentée. `install` **n'y touche pas** : il ajoute son bloc hidden service au `torrc` en place. À copier à la main si tu veux la config complète |
| `README.md` | Ce fichier |

## Architecture

```
                ┌──────────────────────────────────────┐
                │  NAS (192.0.2.134)                   │
                │                                      │
                │   ADM portal :8000                   │
                │            ▲                         │
                │            │ 127.0.0.1:8000          │
                │            │                         │
                │   ┌────────┴────────┐                │
                │   │  Tor v3 daemon  │                │
                │   │  /opt/etc/tor/  │                │
                │   └────────┬────────┘                │
                │            │ HS v3                   │
                │            ▼                         │
                │   <56-chars>.onion                   │
                └──────────────────────────────────────┘
                             │
                             ▼ (Tor circuit)
                ┌──────────────────────────────────────┐
                │  Tor Browser (admins)                │
                │  http://<56-chars>.onion → ADM       │
                └──────────────────────────────────────┘

Backup nightly :
  /opt/etc/tor/hidden_service_adm/{hostname,hs_ed25519_*}
  → SCP via SSH key dédiée
  → serveur de sauvegarde:/mnt/usb-backup/nas-tor/tor-backups/
```

**Pourquoi `/opt/etc/tor/` et non `/opt/tor/`** : sous ce gestionnaire, `/opt/` est reconstruit à chaque boot avec ses seuls symlinks. Les chemins persistants sont `/opt/etc/`, `/opt/bin/`, `/opt/sbin/`, `/opt/var/`. Un fichier posé directement dans `/opt/tor/` ne survivrait pas au redémarrage.

## Pré-requis

- NAS avec ADM 4.x à jour
- SSH activé temporairement sur le NAS (le temps de l'install)
- Compte admin avec accès root via SSH
- Serveur de sauvegarde (192.0.2.60) accessible depuis le NAS, clé USB montée à `/mnt/usb-backup/`
- Sur le serveur de sauvegarde, utilisateur `deploy` avec `~/.ssh/authorized_keys` accessible

## Étapes d'installation

### 1. Préparation du NAS (une fois)

Dans l'interface ADM web :

- App Central → installer le **gestionnaire de paquets tiers qui fournit `opkg`** (recommandé, pour un `opkg install tor` propre). S'il n'est pas dans App Central officiel, suivre les chemins B ou C qu'affiche `tor-nas.sh install`.
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
1. Crée `/opt/etc/tor/{hidden_service_adm,backups,.ssh}`
2. Génère une clé SSH dédiée Ed25519 dans `/opt/etc/tor/.ssh/nas-to-backup` et **affiche la clé publique à copier sur le serveur de sauvegarde**
3. Installe Tor (via `opkg` si dispo, sinon affiche les instructions manuelles)
4. Ajoute le bloc `HiddenService` au `torrc` du gestionnaire (`/opt/etc/tor/torrc`), sans écraser le reste
5. Se recopie dans `/opt/sbin/tor-nas.sh` (chemin persistant) et crée le cron de backup quotidien à 04:30
6. Ne démarre PAS Tor (à faire à l'étape 5)

L'autostart au boot n'est **pas** posé par l'install : il est assuré par le service init.d du gestionnaire `/opt/etc/init.d/S35tor`.

### 4. Autoriser le NAS à pousser les backups sur le serveur de sauvegarde

Récupère la clé publique affichée par l'install, puis depuis ton poste :

```bash
SSH_KEY_BACKUP="$HOME/.ssh/id_ed25519_backup"
NAS_PUBKEY=$(ssh admin@192.0.2.134 cat /opt/etc/tor/.ssh/nas-to-backup.pub)
ssh -i "$SSH_KEY_BACKUP" deploy@192.0.2.60 "echo '$NAS_PUBKEY' >> ~/.ssh/authorized_keys"
```

Puis prépare le dossier cible sur le serveur de sauvegarde :

```bash
ssh -i "$SSH_KEY_BACKUP" deploy@192.0.2.60 \
    "mkdir -p /mnt/usb-backup/nas-tor/tor-backups && chmod 700 /mnt/usb-backup/nas-tor"
```

### 5. Démarrer Tor et obtenir l'adresse `.onion`

```bash
ssh admin@192.0.2.134 sudo /opt/sbin/tor-nas.sh start
sleep 30  # le HS prend ~10-30 secondes à se publier
ssh admin@192.0.2.134 sudo /opt/sbin/tor-nas.sh status
```

Le `status` affiche la **vraie adresse `.onion` v3** — 56 caractères, **suffixe `.onion` compris**. Note-la dans une note physique sécurisée.

### 6. Premier backup

```bash
ssh admin@192.0.2.134 sudo /opt/sbin/tor-nas.sh backup
```

Crée une archive `tor-backup-<timestamp>.tar.gz` contenant :
- `hostname` (l'adresse `.onion`)
- `hs_ed25519_secret_key` (clé privée — IRREMPLAÇABLE)
- `hs_ed25519_public_key`
- `torrc`
- `manifest.txt` (timestamp, hashes SHA256, version Tor, instructions de restauration)

L'archive est uploadée par SCP sur le serveur de sauvegarde. La copie locale est conservée dans `/opt/etc/tor/backups/` (rotation des 3 derniers).

### 7. Test depuis Tor Browser

Sur ton poste avec Tor Browser, colle l'adresse **telle que `status` l'affiche** — elle contient déjà le suffixe :

```
http://<adresse affichée par status>
```

→ Tu dois voir l'écran de login ADM. Login avec ton mdp diceware admin.

### 8. Désactiver SSH

Une fois tout fonctionnel, retour ADM → désactiver SSH. Tu peux toujours le réactiver ponctuellement plus tard pour des opérations admin.

## Usage courant

```bash
sudo /opt/sbin/tor-nas.sh start      # démarrer Tor
sudo /opt/sbin/tor-nas.sh stop       # arrêter
sudo /opt/sbin/tor-nas.sh restart    # redémarrer
sudo /opt/sbin/tor-nas.sh status     # voir l'état + l'adresse .onion
sudo /opt/sbin/tor-nas.sh backup     # backup manuel (le cron en fait un /jour)
sudo /opt/sbin/tor-nas.sh update     # MAJ du binaire Tor
```

## Ce que l'install pose comme tâche planifiée

| Cron | Action |
|---|---|
| `30 4 * * * tor-nas.sh backup` | Backup nightly à 04:30 |

Une seule entrée : le démarrage de Tor au boot ne passe pas par cron mais par `/opt/etc/init.d/S35tor`, posé par le gestionnaire de paquets.

Logs : `/opt/etc/tor/cron.log` + `/opt/etc/tor/tor.log`

## Restauration de l'adresse `.onion` après reformatage

Si tu reformates le NAS, l'adresse `.onion` est perdue **sauf si tu restaures la clé privée**. Procédure :

```bash
# 1. Réinstaller : sudo /tmp/tor-nas.sh install (mais NE PAS démarrer Tor)
# 2. Récupérer le dernier backup depuis le serveur de sauvegarde :
scp -i "$SSH_KEY_BACKUP" \
    "deploy@192.0.2.60:/mnt/usb-backup/nas-tor/tor-backups/tor-backup-<latest>.tar.gz" \
    /tmp/

# 3. Sur le NAS :
ssh admin@192.0.2.134
sudo tar -xzf /tmp/tor-backup-<latest>.tar.gz -C /opt/etc/tor/hidden_service_adm/
sudo chmod 600 /opt/etc/tor/hidden_service_adm/hs_ed25519_secret_key
sudo chmod 700 /opt/etc/tor/hidden_service_adm
sudo chown -R "$(id -un):$(id -gn)" /opt/etc/tor/hidden_service_adm
sudo /opt/sbin/tor-nas.sh start
sudo /opt/sbin/tor-nas.sh status   # vérifie : même .onion qu'avant
```

Le `chown` compte autant que les `chmod` : Tor refuse de servir un `HiddenServiceDir` dont il n'est pas propriétaire. Le `manifest.txt` de l'archive rappelle les cinq gestes dans l'ordre.

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

AGPL-3.0-or-later, comme le reste du dépôt.
