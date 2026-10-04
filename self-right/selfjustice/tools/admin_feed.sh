#!/bin/bash
# SelfJustice — Copie les logs nginx vers un path accessible par PHP (watch.php)
#
# nginx écrit dans /var/log/nginx/selfjustice-access.log (groupe adm, 0640)
# Mais PHP-FPM a open_basedir = /var/www/:/tmp/:/var/lib/selfjustice/:...
# donc il ne peut pas lire directement les logs nginx.
#
# Ce script duplique les logs vers /var/lib/selfjustice/admin/access.log
# où PHP peut les lire. À exécuter via cron toutes les 2 minutes :
#   */2 * * * * www-data <install-dir>/admin_feed.sh   (ligne de /etc/cron.d)

set -u
# Les copies naissent fermées : le `chmod` plus bas ne laisse alors aucune fenêtre.
umask 027

SRC_CUR="${SELFJUSTICE_NGINX_LOG:-/var/log/nginx/selfjustice-access.log}"
SRC_OLD="$SRC_CUR.1"
DST_DIR="${SELFJUSTICE_ADMIN_DIR:-/var/lib/selfjustice/admin}"
DST_CUR="$DST_DIR/access.log"
DST_OLD="$DST_DIR/access.log.1"

# 🔑 Sous l'utilisateur de PHP-FPM, jamais root. La copie s'écrit dans un
# répertoire que PHP peut modifier : root y suivrait un lien symbolique posé à la
# place d'une copie, et écrirait le journal dans n'importe quel fichier de la
# machine. Le journal de nginx appartient à www-data, qui le lit sans privilège.
if [ "$(id -u)" -eq 0 ]; then
    echo "admin_feed.sh : à lancer sous l'utilisateur de PHP-FPM, pas root" >&2
    exit 1
fi

# 🔑 Une copie ne sort ni le jeton d'administration ni l'accès de quiconque. Le
# panneau s'ouvre par une URL qui porte son jeton (`/w-<jeton>`), et une copie
# lisible par tous le donnait à n'importe quel compte de la machine. Les lignes
# qui le citent — chemin demandé ou Referer — sont retirées, et la copie est en
# 640, comme le journal de nginx.
copier() { # copier <journal de nginx> <copie>
    [ -r "$1" ] || return 0
    grep -v -E '/w-[0-9a-f]{32}' "$1" > "$2.tmp"
    [ "$?" -le 1 ] || { rm -f "$2.tmp"; return 1; }
    chmod 640 "$2.tmp" || { rm -f "$2.tmp"; return 1; }
    mv -f "$2.tmp" "$2"
}

mkdir -p "$DST_DIR" 2>/dev/null || true
code=0
copier "$SRC_CUR" "$DST_CUR" || code=1
copier "$SRC_OLD" "$DST_OLD" || code=1
exit "$code"
