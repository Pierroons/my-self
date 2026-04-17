#!/bin/bash
# SelfJustice — Copie les logs nginx vers un path accessible par PHP (watch.php)
#
# nginx écrit dans /var/log/nginx/selfjustice-access.log (root:adm, 0644)
# Mais PHP-FPM a open_basedir = /var/www/:/tmp/:/var/lib/selfjustice/:...
# donc il ne peut pas lire directement les logs nginx.
#
# Ce script duplique les logs vers /var/lib/selfjustice/admin/access.log
# où PHP peut les lire. À exécuter via cron toutes les 2 minutes :
#   */2 * * * * /home/zelda/legi/admin_feed.sh

set -u

SRC_CUR="/var/log/nginx/selfjustice-access.log"
SRC_OLD="/var/log/nginx/selfjustice-access.log.1"
DST_DIR="/var/lib/selfjustice/admin"
DST_CUR="$DST_DIR/access.log"
DST_OLD="$DST_DIR/access.log.1"

# zelda est dans le groupe adm donc peut lire /var/log/nginx/
# /var/lib/selfjustice/admin/ est owned by www-data, mais zelda peut y écrire
# (sudo mkdir lors du déploiement a donné les bons droits ; sinon cron tournera
# en sudo via /etc/crontab si besoin).

mkdir -p "$DST_DIR" 2>/dev/null || true

if [ -r "$SRC_CUR" ]; then
    cp -f "$SRC_CUR" "$DST_CUR.tmp" && mv -f "$DST_CUR.tmp" "$DST_CUR"
    chmod 644 "$DST_CUR"
fi

if [ -r "$SRC_OLD" ]; then
    cp -f "$SRC_OLD" "$DST_OLD.tmp" && mv -f "$DST_OLD.tmp" "$DST_OLD"
    chmod 644 "$DST_OLD"
fi

exit 0
