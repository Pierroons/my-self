#!/bin/sh
# tor-nas.sh — gestionnaire Tor hidden service pour NAS (ADM)
#
# Sous-commandes :
#   install   — bootstrap initial (structure dirs, clé SSH backup, torrc, cron @reboot)
#               À exécuter UNE FOIS via SSH root sur le NAS, après quoi SSH peut être désactivé
#   start     — démarre le démon Tor en mode daemon
#   stop      — arrête proprement Tor (init.d du gestionnaire ; sinon SIGTERM puis SIGKILL à 2 s)
#   restart   — stop puis start
#   status    — affiche l'état + l'adresse .onion v3 du hidden service
#   backup    — zippe les artefacts critiques (clés + hostname + manifest)
#               + push SCP sur la clé USB du serveur de sauvegarde
#   update    — met à jour le binaire Tor (opkg si dispo, sinon binaire pinned)
#
# Conventions — tout est sous les chemins persistants d'opkg (cf. bloc CONFIG) :
#   /opt/bin/tor                       — binaire Tor
#   /opt/sbin/tor-nas.sh               — ce script, recopié par `install`
#   /opt/etc/tor/torrc                 — config Tor ; `install` y ajoute le bloc HS
#   /opt/etc/tor/hidden_service_adm/   — clés Ed25519 + hostname du HS
#   /opt/etc/tor/.ssh/nas-to-backup    — clé SSH dédiée au push des backups
#   /opt/etc/tor/backups/              — archives locales (rotation 3 dernières)
#
# Backup destination, sur le serveur de sauvegarde :
#   /mnt/usb-backup/nas-tor/tor-backups/
#
# Auto-start au boot : assuré par le service init.d du gestionnaire /opt/etc/init.d/S35tor.
#
# Licence : AGPL-3.0-or-later — partie du repo MySelf
set -eu

# Force le PATH à inclure opkg (/opt/bin, /opt/sbin) pour que `command -v opkg/tor`
# fonctionne même quand sudo a un PATH minimal restrictif.
export PATH="/opt/bin:/opt/sbin:/usr/builtin/bin:/usr/builtin/sbin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"

# ===================== CONFIG =====================
# IMPORTANT — sur NAS avec opkg, /opt/ est reconstruit à chaque boot
# uniquement avec les symlinks du gestionnaire. Donc on utilise les paths sous
# /opt/etc/, /opt/bin/, /opt/var/ qui sont persistants (sous le répertoire AppCentral du gestionnaire de paquets).
TOR_BIN="/opt/bin/tor"
TOR_INITD="/opt/etc/init.d/S35tor"
TOR_CONF_DIR="/opt/etc/tor"
TOR_CONF="${TOR_CONF_DIR}/torrc"
HS_DIR="${TOR_CONF_DIR}/hidden_service_adm"
BACKUP_DIR="${TOR_CONF_DIR}/backups"

# Serveur de sauvegarde — destination du push nocturne (ajuste si besoin)
BACKUP_HOST="deploy@192.0.2.60"
BACKUP_PATH="/mnt/usb-backup/nas-tor/tor-backups"
SSH_KEY="${TOR_CONF_DIR}/.ssh/nas-to-backup"

# ===================== HELPERS =====================
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

require_dir() {
    if [ ! -d "$1" ]; then
        log "ERROR: missing directory $1 — run '$0 install' first"
        exit 2
    fi
}

require_bin() {
    if [ ! -x "$1" ]; then
        log "ERROR: missing binary $1 — run '$0 install' first"
        exit 2
    fi
}

# ===================== COMMANDES =====================
cmd_start() {
    if [ -x "$TOR_INITD" ]; then
        log "Starting Tor via $TOR_INITD..."
        "$TOR_INITD" start || { log "ERROR: tor start failed"; exit 1; }
    else
        require_bin "$TOR_BIN"
        log "Starting Tor (manual mode)..."
        "$TOR_BIN" -f "$TOR_CONF" --runasdaemon 1 || {
            log "ERROR: tor failed to start"; exit 1;
        }
    fi
    sleep 3
    cmd_status
}

cmd_stop() {
    if [ -x "$TOR_INITD" ]; then
        "$TOR_INITD" stop
        log "Tor stopped via init.d"
    else
        # Fallback : tuer par pkill
        pkill -TERM -x tor 2>/dev/null
        sleep 2
        pkill -KILL -x tor 2>/dev/null
        log "Tor stopped (manual)"
    fi
}

cmd_restart() {
    if [ -x "$TOR_INITD" ]; then
        "$TOR_INITD" restart
    else
        cmd_stop
        sleep 1
        cmd_start
    fi
}

cmd_status() {
    # Cherche tor process via pgrep (PID file pas toujours fiable avec init du gestionnaire)
    pid=$(pgrep -x tor 2>/dev/null | head -1)
    if [ -n "$pid" ]; then
        log "Tor running (PID $pid)"
        if [ -f "$HS_DIR/hostname" ]; then
            log "Hidden service: $(cat "$HS_DIR/hostname")"
        else
            log "WARN: no hostname file yet — wait for Tor to publish HS"
        fi
        log "Tor version: $("$TOR_BIN" --version 2>&1 | head -1)"
    else
        log "Tor NOT running"
        return 1
    fi
}

cmd_backup() {
    require_dir "$HS_DIR"
    mkdir -p "$BACKUP_DIR"

    timestamp=$(date '+%Y-%m-%dT%H-%M-%S')
    archive_name="tor-backup-${timestamp}.tar.gz"
    archive_path="${BACKUP_DIR}/${archive_name}"
    work_dir="${BACKUP_DIR}/.tmp-${timestamp}"

    mkdir -p "$work_dir"

    # Copie des fichiers cruciaux (les 3 du hidden service + torrc)
    cp "$HS_DIR/hostname" "$work_dir/" 2>/dev/null || log "WARN: no hostname file"
    cp "$HS_DIR/hs_ed25519_secret_key" "$work_dir/" 2>/dev/null || log "WARN: no secret_key"
    cp "$HS_DIR/hs_ed25519_public_key" "$work_dir/" 2>/dev/null || log "WARN: no public_key"
    cp "$TOR_CONF" "$work_dir/torrc" 2>/dev/null || true

    # Manifest
    onion="$(cat "$HS_DIR/hostname" 2>/dev/null || echo none)"
    sk_hash="$(sha256sum "$HS_DIR/hs_ed25519_secret_key" 2>/dev/null | awk '{print $1}')"
    pk_hash="$(sha256sum "$HS_DIR/hs_ed25519_public_key" 2>/dev/null | awk '{print $1}')"
    tor_version="$("$TOR_BIN" --version 2>&1 | head -1 || echo unknown)"
    {
        echo "# tor-nas backup manifest"
        echo "timestamp: $timestamp"
        echo "host: $(hostname)"
        echo "tor_version: $tor_version"
        echo "onion_address: $onion"
        echo "secret_key_sha256: $sk_hash"
        echo "public_key_sha256: $pk_hash"
        echo ""
        echo "# RESTORATION INSTRUCTIONS"
        echo "# 1. Stop Tor: ./tor-nas.sh stop"
        echo "# 2. Extract: tar -xzf ${archive_name} -C ${HS_DIR}"
        echo "# 3. chmod 600 hs_ed25519_secret_key"
        echo "# 4. chown -R \$(id -un):\$(id -gn) ${HS_DIR}"
        echo "# 5. Start Tor: ./tor-nas.sh start"
        echo "# 6. Verify: ./tor-nas.sh status — must show same onion as above"
    } > "$work_dir/manifest.txt"

    # Permissions strictes sur la clé privée dans le tmp
    chmod 600 "$work_dir/hs_ed25519_secret_key" 2>/dev/null || true

    # Création de l'archive
    (cd "$work_dir" && tar -czf "$archive_path" .)
    chmod 600 "$archive_path"
    rm -rf "$work_dir"

    log "Local backup: $archive_path ($(du -h "$archive_path" | awk '{print $1}'))"

    # Push vers le serveur de sauvegarde via scp (clé SSH dédiée)
    if [ -f "$SSH_KEY" ]; then
        log "Pushing to backup server via scp..."
        # Crée le dossier distant si absent
        ssh -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new \
            "$BACKUP_HOST" "mkdir -p $BACKUP_PATH && chmod 700 $BACKUP_PATH" \
            || log "WARN: could not ensure remote dir"

        if scp -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new \
               "$archive_path" \
               "${BACKUP_HOST}:${BACKUP_PATH}/${archive_name}"; then
            log "Backup uploaded: ${BACKUP_HOST}:${BACKUP_PATH}/${archive_name}"
        else
            log "ERROR: scp failed — backup NOT uploaded (kept locally)"
        fi
    else
        log "WARN: SSH key $SSH_KEY missing — backup NOT uploaded"
        log "      Run '$0 install' to generate it, then add the public key"
        log "      to ~deploy/.ssh/authorized_keys on the backup server."
    fi

    # Rotation locale : garder les 3 derniers backups seulement.
    # Le serveur de sauvegarde, lui, garde tout l'historique — la rétention
    # longue est de son côté, pas de celui du NAS.
    if command -v ls >/dev/null 2>&1; then
        ls -t "$BACKUP_DIR"/tor-backup-*.tar.gz 2>/dev/null | tail -n +4 | while read -r f; do
            log "Rotating out: $f"
            rm -f "$f"
        done
    fi
}

cmd_update() {
    log "Checking for Tor update..."

    # Tentative 1 : opkg si présent
    if command -v opkg >/dev/null 2>&1; then
        log "opkg detected — using opkg"
        opkg update
        if opkg upgrade tor; then
            log "Tor upgraded via opkg → restart"
            cmd_restart
            return 0
        else
            log "opkg upgrade failed — falling back to manual"
        fi
    fi

    # Tentative 2 : binaire pinned (à compléter avec une URL stable)
    # Le mainteneur (Pierroons) doit mettre à jour TOR_BIN_URL en cas
    # de nouvelle version Tor stable. Vérification SHA256 obligatoire.
    log "Manual update procedure:"
    log "  1. Récupère la dernière version Tor stable: https://www.torproject.org/download/tor/"
    log "  2. Vérifie la signature GPG du tarball (cf. README)"
    log "  3. Compile statiquement (--enable-static-tor) ou récupère un binaire static maintenu"
    log "  4. Copie le binaire dans ${TOR_BIN}.new"
    log "  5. ${0} stop"
    log "  6. mv ${TOR_BIN} ${TOR_BIN}.bak && mv ${TOR_BIN}.new ${TOR_BIN}"
    log "  7. chmod +x ${TOR_BIN}"
    log "  8. ${0} start"
    log "  9. ${0} backup"
    log ""
    log "Current installed: $("$TOR_BIN" --version 2>&1 | head -1 || echo none)"
}

cmd_install() {
    # ===== bootstrap initial / re-install idempotent =====
    log "===== MySelf NAS — Tor native install (opkg persistent paths) ====="

    if [ "$(id -u)" != "0" ]; then
        log "ERROR: must be run as root (sudo / SSH root admin)"
        exit 1
    fi

    # 1. Structure de répertoires (sous /opt/etc/tor/ qui est persistant via opkg)
    log "Creating directory structure under $TOR_CONF_DIR..."
    mkdir -p "$HS_DIR" "$BACKUP_DIR" "${TOR_CONF_DIR}/.ssh"
    chmod 700 "${TOR_CONF_DIR}/.ssh" "$HS_DIR"

    # 2. Clé SSH dédiée pour push backups vers le serveur de sauvegarde
    if [ ! -f "$SSH_KEY" ]; then
        log "Generating SSH key for backup push..."
        ssh-keygen -t ed25519 -f "$SSH_KEY" -N "" \
            -C "tor-nas-backup-$(hostname)-$(date +%Y%m%d)"
        chmod 600 "$SSH_KEY"
        chmod 644 "${SSH_KEY}.pub"
        log "SSH key generated: $SSH_KEY"
    else
        log "SSH key already exists: $SSH_KEY"
    fi

    log ""
    log "===== ACTION REQUIRED — copy this public key to the backup server ====="
    cat "${SSH_KEY}.pub"
    log ""
    log "Append it to ~deploy/.ssh/authorized_keys on the backup server (192.0.2.60)."
    log ""

    # 3. Tor binary
    if [ -x "$TOR_BIN" ]; then
        log "Tor binary already present: $TOR_BIN"
        log "  Version: $("$TOR_BIN" --version 2>&1 | head -1)"
    else
        # Check opkg par chemin absolu (le sudo PATH n'inclut pas toujours /opt/bin)
        OPKG_BIN=""
        if [ -x /opt/bin/opkg ]; then OPKG_BIN="/opt/bin/opkg"
        elif command -v opkg >/dev/null 2>&1; then OPKG_BIN="$(command -v opkg)"
        fi

        if [ -n "$OPKG_BIN" ]; then
            log "opkg detected ($OPKG_BIN) — installing tor"
            "$OPKG_BIN" update
            "$OPKG_BIN" install tor
            if [ -x /opt/bin/tor ]; then
                ln -sf /opt/bin/tor "$TOR_BIN"
            elif [ -x /opt/sbin/tor ]; then
                ln -sf /opt/sbin/tor "$TOR_BIN"
            fi
            log "Tor installed via opkg: $("$TOR_BIN" --version 2>&1 | head -1)"
        else
            log ""
            log "===== ACTION REQUIRED — install Tor binary manually ====="
            log "opkg not detected. Three paths possible:"
            log ""
            log "  Path A (preferred) — install opkg via NAS App Central"
            log "    1. Open ADM → App Central → search 'opkg'"
            log "    2. Install, then SSH back and re-run: $0 install"
            log ""
            log "  Path B — download a static Tor binary"
            log "    1. From a trusted source (tor-static GitHub mirror)"
            log "    2. Verify SHA256 against project release notes"
            log "    3. Copy to $TOR_BIN, chmod +x"
            log "    4. Re-run: $0 install"
            log ""
            log "  Path C — compile from source (on a Debian dev box, then SCP)"
            log "    1. apt install build-essential libssl-dev libevent-dev"
            log "    2. Download Tor source from torproject.org, verify GPG"
            log "    3. ./configure --prefix=/usr/local/tor-static --disable-system-torrc"
            log "    4. make && make install"
            log "    5. SCP /usr/local/tor-static/bin/tor to NAS:$TOR_BIN"
            log ""
            log "Re-run $0 install once binary is in place."
            return 2
        fi
    fi

    # 4. torrc — vérifier que le HS est bien configuré
    if [ -f "$TOR_CONF" ] && grep -q "HiddenServiceDir ${HS_DIR}" "$TOR_CONF"; then
        log "torrc HS config already present"
    else
        log "Appending HiddenService config to $TOR_CONF..."
        cat >> "$TOR_CONF" <<EOF

# === MySelf hidden service v3 (added by tor-nas install) ===
HiddenServiceDir ${HS_DIR}/
HiddenServiceVersion 3
HiddenServicePort 80 127.0.0.1:8000
EOF
        log "HS config appended"
    fi

    # 5. Permissions strictes
    chmod 700 "$HS_DIR" "${TOR_CONF_DIR}/.ssh"

    # 6. Self-copy vers /opt/sbin/ (persistant opkg)
    target_path="/opt/sbin/tor-nas.sh"
    self_path="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
    if [ "$(realpath "$self_path" 2>/dev/null)" != "$(realpath "$target_path" 2>/dev/null)" ]; then
        cp "$self_path" "$target_path"
        chmod +x "$target_path"
        log "Self-copied to $target_path (persistent opkg path)"
    fi

    # 7. Crontab — backup quotidien uniquement (Tor auto-start déjà géré par
    # opkg via /opt/etc/init.d/S35tor)
    cron_backup="30 4 * * * $target_path backup >> ${TOR_CONF_DIR}/cron.log 2>&1"

    if crontab -l 2>/dev/null | grep -qF "tor-nas.sh backup"; then
        log "Daily backup cron already configured"
    else
        (crontab -l 2>/dev/null; echo "$cron_backup") | crontab -
        log "Daily backup cron added (04:30) — script at $target_path"
    fi
    log "Tor auto-start: handled by $TOR_INITD (opkg native, persists across reboots)"

    log ""
    log "===== INSTALL COMPLETE ====="
    log "Next steps:"
    log "  1. Copy SSH pubkey above to the backup server ~deploy/.ssh/authorized_keys"
    log "  2. $0 start"
    log "  3. $0 status   # wait ~30s then check .onion address"
    log "  4. $0 backup   # first backup"
    log "  5. Test from Tor Browser using the .onion shown by status"
    log "  6. Disable SSH on the NAS once everything is verified"
}

cmd_help() {
    cat <<EOF
tor-nas.sh — gestionnaire Tor hidden service NAS (ADM)

Usage: $0 {install|start|stop|restart|status|backup|update|help}

Commands:
  install   First-time bootstrap (mkdir, ssh-keygen, torrc, cron). Run as root.
  start     Start Tor daemon (via l'init.d du gestionnaire, else --runasdaemon)
  stop      Gracefully stop Tor (init.d; fallback SIGTERM then SIGKILL at 2s)
  restart   stop + start
  status    Show running state + .onion address + Tor version
  backup    Snapshot HS keys + manifest, upload to backup server USB drive
  update    Update Tor binary (opkg if available, else manual procedure)
  help      This message

First-time use:
  scp tor-nas.sh torrc.template admin@<NAS>:/tmp/
  ssh admin@<NAS> "sudo /tmp/tor-nas.sh install"
EOF
}

# ===================== DISPATCH =====================
case "${1:-help}" in
    install) cmd_install ;;
    start)   cmd_start ;;
    stop)    cmd_stop ;;
    restart) cmd_restart ;;
    status)  cmd_status ;;
    backup)  cmd_backup ;;
    update)  cmd_update ;;
    help|-h|--help) cmd_help ;;
    *) cmd_help; exit 1 ;;
esac
