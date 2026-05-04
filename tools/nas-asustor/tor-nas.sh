#!/bin/sh
# tor-nas.sh — gestionnaire Tor hidden service pour NAS NAS <modele> (ADM)
#
# Sous-commandes :
#   install   — bootstrap initial (structure dirs, clé SSH backup, torrc, cron @reboot)
#               À exécuter UNE FOIS via SSH root sur le NAS, après quoi SSH peut être désactivé
#   start     — démarre le démon Tor en mode daemon
#   stop      — arrête proprement Tor (SIGTERM puis SIGKILL après 5 sec)
#   restart   — stop puis start
#   status    — affiche l'état + l'adresse .onion v3 du hidden service
#   backup    — zippe les artefacts critiques (clés + hostname + manifest)
#               + push SCP sur la clé USB du DEVSERVER
#   update    — met à jour le binaire Tor (opkg si dispo, sinon binaire pinned)
#
# Conventions :
#   /opt/tor/                  — racine de l'install Tor (survit aux MAJ ADM)
#   /opt/tor/bin/tor           — binaire Tor
#   /opt/tor/torrc             — config Tor (générée par install.sh)
#   /opt/tor/data/             — données Tor (DNS cache, descriptors)
#   /opt/tor/hidden_service_adm/  — clés Ed25519 + hostname du HS
#   /opt/tor/.ssh/nas-to-devserver  — clé SSH dédiée pour push backups → DEVSERVER
#   /opt/tor/backups/          — archives backup locales (rotation 3 dernières)
#
# Backup destination (sur le DEVSERVER) :
#   /mnt/usb-backup/nas-asustor/tor-backups/
#
# Auto-start au boot : `crontab -e` puis `@reboot /opt/tor/bin/tor-nas.sh start`
# (ADM n'a pas systemd standard, cron est la voie portable).
#
# Licence : AGPL-3.0-or-later — partie du repo MySelf
set -eu

# ===================== CONFIG =====================
TOR_DIR="/opt/tor"
TOR_BIN="${TOR_DIR}/bin/tor"
TOR_CONF="${TOR_DIR}/torrc"
TOR_DATA="${TOR_DIR}/data"
HS_DIR="${TOR_DIR}/hidden_service_adm"
LOG_FILE="${TOR_DIR}/tor.log"
PID_FILE="${TOR_DIR}/tor.pid"
BACKUP_DIR="${TOR_DIR}/backups"

# Cible DEVSERVER (ajuste si besoin)
DEVSERVER_HOST="user@192.0.2.10"
DEVSERVER_USB_PATH="/mnt/usb-backup/nas-asustor/tor-backups"
SSH_KEY="${TOR_DIR}/.ssh/nas-to-devserver"

# ===================== HELPERS =====================
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

require_dir() {
    if [ ! -d "$1" ]; then
        log "ERROR: missing directory $1 — run install.sh first"
        exit 2
    fi
}

require_bin() {
    if [ ! -x "$1" ]; then
        log "ERROR: missing binary $1 — run install.sh first"
        exit 2
    fi
}

# ===================== COMMANDES =====================
cmd_start() {
    require_bin "$TOR_BIN"
    require_dir "$TOR_DATA"

    if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        log "Tor already running (PID $(cat "$PID_FILE"))"
        return 0
    fi

    log "Starting Tor..."
    "$TOR_BIN" -f "$TOR_CONF" --runasdaemon 1 \
        --pidfile "$PID_FILE" --log "notice file ${LOG_FILE}" || {
        log "ERROR: tor failed to start (exit $?). See $LOG_FILE"
        exit 1
    }

    # Attente que la HS soit publiée (max 30 sec)
    i=0
    while [ $i -lt 30 ]; do
        if [ -f "$HS_DIR/hostname" ]; then break; fi
        sleep 1
        i=$((i + 1))
    done

    cmd_status
}

cmd_stop() {
    if [ ! -f "$PID_FILE" ]; then
        log "Tor not running (no PID file)"
        return 0
    fi
    pid=$(cat "$PID_FILE")
    if ! kill -0 "$pid" 2>/dev/null; then
        log "Stale PID file — cleaning"
        rm -f "$PID_FILE"
        return 0
    fi

    log "Stopping Tor (PID $pid)..."
    kill -TERM "$pid"
    i=0
    while [ $i -lt 5 ]; do
        if ! kill -0 "$pid" 2>/dev/null; then break; fi
        sleep 1
        i=$((i + 1))
    done

    if kill -0 "$pid" 2>/dev/null; then
        log "Tor still alive after 5s — SIGKILL"
        kill -KILL "$pid"
    fi

    rm -f "$PID_FILE"
    log "Tor stopped"
}

cmd_restart() {
    cmd_stop || true
    sleep 1
    cmd_start
}

cmd_status() {
    if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        log "Tor running (PID $(cat "$PID_FILE"))"
        if [ -f "$HS_DIR/hostname" ]; then
            log "Hidden service: $(cat "$HS_DIR/hostname")"
        else
            log "WARN: no hostname file yet — wait for Tor to publish HS"
        fi
        if command -v "$TOR_BIN" >/dev/null 2>&1; then
            log "Tor version: $("$TOR_BIN" --version 2>&1 | head -1)"
        fi
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

    # Push vers DEVSERVER via scp (clé SSH dédiée)
    if [ -f "$SSH_KEY" ]; then
        log "Pushing to DEVSERVER via scp..."
        # Crée le dossier distant si absent
        ssh -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new \
            "$DEVSERVER_HOST" "mkdir -p $DEVSERVER_USB_PATH && chmod 700 $DEVSERVER_USB_PATH" \
            || log "WARN: could not ensure remote dir"

        scp -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new \
            "$archive_path" \
            "${DEVSERVER_HOST}:${DEVSERVER_USB_PATH}/${archive_name}" \
            && log "Backup uploaded: ${DEVSERVER_HOST}:${DEVSERVER_USB_PATH}/${archive_name}" \
            || log "ERROR: scp failed — backup NOT uploaded (kept locally)"
    else
        log "WARN: SSH key $SSH_KEY missing — backup NOT uploaded"
        log "      Run install.sh to generate it, then add the public key"
        log "      to /home/user/.ssh/authorized_keys on the DEVSERVER."
    fi

    # Rotation locale : garder les 3 derniers backups seulement
    # (le DEVSERVER garde TOUS les backups historiques, lui)
    if command -v ls >/dev/null 2>&1; then
        ls -t "$BACKUP_DIR"/tor-backup-*.tar.gz 2>/dev/null | tail -n +4 | while read -r f; do
            log "Rotating out: $f"
            rm -f "$f"
        done
    fi
}

cmd_update() {
    log "Checking for Tor update..."

    # Tentative 1 : opkg (opkg) si présent
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
    # ===== bootstrap initial — à exécuter UNE FOIS via SSH root =====
    log "===== MySelf NAS — Tor native install ====="

    if [ "$(id -u)" != "0" ]; then
        log "ERROR: must be run as root (sudo / SSH root admin)"
        exit 1
    fi

    if [ -d "$TOR_DIR" ]; then
        log "WARN: $TOR_DIR already exists — proceeding (idempotent)"
    fi

    # 1. Structure de répertoires
    log "Creating directory structure..."
    mkdir -p "$TOR_DIR/bin" "$TOR_DATA" "$HS_DIR" "$BACKUP_DIR" "$TOR_DIR/.ssh"
    chmod 700 "$TOR_DIR/.ssh" "$HS_DIR"

    # 2. Clé SSH dédiée pour push backups vers DEVSERVER
    if [ ! -f "$SSH_KEY" ]; then
        log "Generating SSH key for backup push to DEVSERVER..."
        ssh-keygen -t ed25519 -f "$SSH_KEY" -N "" \
            -C "tor-nas-backup-$(hostname)-$(date +%Y%m%d)"
        chmod 600 "$SSH_KEY"
        chmod 644 "${SSH_KEY}.pub"
        log "SSH key generated: $SSH_KEY"
    else
        log "SSH key already exists: $SSH_KEY"
    fi

    log ""
    log "===== ACTION REQUIRED — copy this public key to DEVSERVER ====="
    cat "${SSH_KEY}.pub"
    log ""
    log "Append it to /home/user/.ssh/authorized_keys on the DEVSERVER (192.0.2.10)."
    log ""

    # 3. Tor binary
    if [ -x "$TOR_BIN" ]; then
        log "Tor binary already present: $TOR_BIN"
        log "  Version: $("$TOR_BIN" --version 2>&1 | head -1)"
    else
        if command -v opkg >/dev/null 2>&1; then
            log "opkg detected — installing tor via opkg"
            opkg update
            opkg install tor
            if [ -x /opt/bin/tor ]; then
                ln -sf /opt/bin/tor "$TOR_BIN"
            elif [ -x /opt/sbin/tor ]; then
                ln -sf /opt/sbin/tor "$TOR_BIN"
            fi
            log "Tor installed via opkg: $("$TOR_BIN" --version 2>&1 | head -1)"
        else
            log ""
            log "===== ACTION REQUIRED — install Tor binary manually ====="
            log "opkg (opkg) not detected. Three paths possible:"
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
            log "    3. ./configure --prefix=/opt/tor --disable-system-torrc"
            log "    4. make && make install"
            log "    5. SCP /opt/tor/bin/tor to NAS:$TOR_BIN"
            log ""
            log "Re-run $0 install once binary is in place."
            return 2
        fi
    fi

    # 4. torrc — généré depuis torrc.template (cherché à côté du script)
    if [ ! -f "$TOR_CONF" ]; then
        local script_dir
        script_dir="$(cd "$(dirname "$0")" && pwd)"
        if [ -f "${script_dir}/torrc.template" ]; then
            log "Generating torrc from template..."
            sed "s|@TOR_DIR@|${TOR_DIR}|g" "${script_dir}/torrc.template" > "$TOR_CONF"
            log "torrc generated: $TOR_CONF"
        else
            log "WARN: torrc.template not found — generating minimal torrc"
            cat > "$TOR_CONF" <<EOF
DataDirectory ${TOR_DATA}
RunAsDaemon 0
ClientOnly 1
SocksPort 0

HiddenServiceDir ${HS_DIR}/
HiddenServiceVersion 3
HiddenServicePort 80 127.0.0.1:8000
EOF
        fi
    fi

    # 5. Permissions strictes
    chmod 700 "$HS_DIR" "$TOR_DIR/.ssh"

    # 6. Crontab @reboot pour autostart + backup quotidien à 04:30
    local self_path
    self_path="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
    if [ "$(realpath "$self_path" 2>/dev/null)" != "$(realpath "$TOR_DIR/bin/tor-nas.sh" 2>/dev/null)" ]; then
        cp "$self_path" "$TOR_DIR/bin/tor-nas.sh"
        chmod +x "$TOR_DIR/bin/tor-nas.sh"
        log "Self-copied to $TOR_DIR/bin/tor-nas.sh"
    fi

    local cron_start="@reboot $TOR_DIR/bin/tor-nas.sh start >> $TOR_DIR/cron.log 2>&1"
    local cron_backup="30 4 * * * $TOR_DIR/bin/tor-nas.sh backup >> $TOR_DIR/cron.log 2>&1"

    if crontab -l 2>/dev/null | grep -qF "tor-nas.sh start"; then
        log "Crontab @reboot already configured"
    else
        (crontab -l 2>/dev/null; echo "$cron_start") | crontab -
        log "Crontab @reboot added"
    fi

    if crontab -l 2>/dev/null | grep -qF "tor-nas.sh backup"; then
        log "Daily backup cron already configured"
    else
        (crontab -l 2>/dev/null; echo "$cron_backup") | crontab -
        log "Daily backup cron added (04:30)"
    fi

    log ""
    log "===== INSTALL COMPLETE ====="
    log "Next steps:"
    log "  1. Copy SSH pubkey above to DEVSERVER /home/user/.ssh/authorized_keys"
    log "  2. $0 start"
    log "  3. $0 status   # wait ~30s then check .onion address"
    log "  4. $0 backup   # first backup"
    log "  5. Test from Tor Browser using the .onion shown by status"
    log "  6. Disable SSH on the NAS once everything is verified"
}

cmd_help() {
    cat <<EOF
tor-nas.sh — gestionnaire Tor hidden service NAS NAS

Usage: $0 {install|start|stop|restart|status|backup|update|help}

Commands:
  install   First-time bootstrap (mkdir, ssh-keygen, torrc, cron). Run as root.
  start     Start Tor daemon (writes PID to $PID_FILE)
  stop      Gracefully stop Tor (SIGTERM, then SIGKILL after 5s)
  restart   stop + start
  status    Show running state + .onion address + Tor version
  backup    Snapshot HS keys + manifest, upload to DEVSERVER USB drive
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
