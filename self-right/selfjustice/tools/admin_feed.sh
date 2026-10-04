#!/bin/bash
# SelfJustice — Copie les logs nginx vers un path accessible par PHP (watch.php)
#
# nginx écrit dans /var/log/nginx/selfjustice-access.log (groupe adm, 0640)
# Mais PHP-FPM a open_basedir = /var/www/:/tmp/:/var/lib/selfjustice/:...
# donc il ne peut pas lire directement les logs nginx.
#
# Ce script duplique les logs vers /var/lib/selfjustice/admin/access.log
# où PHP peut les lire. À exécuter via cron toutes les 2 minutes :
#   */2 * * * * <install-dir>/admin_feed.sh

set -u
# Les copies naissent fermées : le `chmod` plus bas ne laisse alors aucune fenêtre.
umask 027

SRC_CUR="${SELFJUSTICE_NGINX_LOG:-/var/log/nginx/selfjustice-access.log}"
SRC_OLD="$SRC_CUR.1"
DST_DIR="${SELFJUSTICE_ADMIN_DIR:-/var/lib/selfjustice/admin}"
DST_CUR="$DST_DIR/access.log"
DST_OLD="$DST_DIR/access.log.1"

# Lancé par root (cron) : il lit le journal de nginx et pose des copies que seul
# PHP-FPM (groupe www-data) peut lire.
GROUPE_LECTEUR="${SELFJUSTICE_ADMIN_GROUPE:-www-data}"

# 🔑 Une copie ne sort ni le jeton d'administration ni l'accès de quiconque. Le
# panneau s'ouvre par une URL qui porte son jeton (`/w-<jeton>`), et une copie
# lisible par tous le donnait à n'importe quel compte de la machine. Les lignes
# qui le citent — chemin demandé ou Referer — sont retirées, et la copie est en
# 640 : le journal de nginx, lui, reste réservé au groupe adm.
copier() { # copier <journal de nginx> <copie>
    [ -r "$1" ] || return 0
    grep -v -E '/w-[0-9a-f]{32}' "$1" > "$2.tmp"
    [ "$?" -le 1 ] || { rm -f "$2.tmp"; return 1; }
    chmod 640 "$2.tmp" || { rm -f "$2.tmp"; return 1; }
    # Hors root (un banc), la copie reste à son auteur seul : 640 suffit.
    if ! chown "root:$GROUPE_LECTEUR" "$2.tmp" 2>/dev/null && [ "$(id -u)" -eq 0 ]; then
        rm -f "$2.tmp"; return 1
    fi
    mv -f "$2.tmp" "$2"
}

mkdir -p "$DST_DIR" 2>/dev/null || true
code=0
copier "$SRC_CUR" "$DST_CUR" || code=1
copier "$SRC_OLD" "$DST_OLD" || code=1
exit "$code"
