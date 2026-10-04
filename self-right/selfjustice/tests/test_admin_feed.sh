#!/bin/bash
# Garde-fou — les copies du journal pour le panneau de veille ne sortent pas son jeton.
#
# 🔑 Le panneau s'ouvre par une URL qui porte son jeton. Une copie du journal
# lisible par tous le donnait à n'importe quel compte de la machine. Le cas
# vérifie les deux protections, parce qu'elles ne se valent pas : retirer les
# lignes empêche la fuite, fermer la copie la borne si une ligne passait. Un
# troisième vérifie que le script refuse root, qui écrirait à travers un lien
# symbolique posé par PHP dans le répertoire des copies.
#
# Usage : bash tests/test_admin_feed.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$ICI/../tools/admin_feed.sh"
[ -r "$SCRIPT" ] || { echo "admin_feed.sh introuvable : $SCRIPT" >&2; exit 1; }

BAC=$(mktemp -d)
trap 'rm -rf "$BAC"' EXIT

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

JETON=0123456789abcdef0123456789abcdef
mkdir -p "$BAC/nginx" "$BAC/admin"
cat > "$BAC/nginx/access.log" <<EOF
192.0.2.1 - - [04/Oct/2026:00:15:43 +0200] "GET / HTTP/1.1" 200 44551 "-" "lecteur"
192.0.2.2 - - [04/Oct/2026:00:16:01 +0200] "GET /w-$JETON/ HTTP/1.1" 200 9000 "-" "navigateur"
192.0.2.2 - - [04/Oct/2026:00:16:02 +0200] "GET /style.css HTTP/1.1" 200 120 "https://justice.example.org/w-$JETON/" "navigateur"
EOF
cp "$BAC/nginx/access.log" "$BAC/nginx/access.log.1"

lancer() { SELFJUSTICE_NGINX_LOG="$BAC/nginx/access.log" SELFJUSTICE_ADMIN_DIR="$BAC/admin" \
           bash "$SCRIPT"; }

echo "▸ Ce que la copie garde, ce qu'elle retire"
lancer; code=$?
for f in access.log access.log.1; do
    c="$BAC/admin/$f"
    if [ "$code" -eq 0 ] && [ -f "$c" ] && ! grep -q "$JETON" "$c" && grep -q '"GET / HTTP' "$c"; then
        ok "$f : jeton absent (chemin et Referer), ligne ordinaire gardée"
    else
        nok "$f : code $code — $(grep -c "$JETON" "$c" 2>/dev/null) ligne(s) portent encore le jeton"
    fi
    mode=$(stat -c '%a' "$c" 2>/dev/null)
    if [ "$mode" = "640" ]; then
        ok "$f : 640"
    else
        nok "$f : mode $mode — une copie lisible par tous"
    fi
done

echo
echo "▸ Un journal absent n'est pas une erreur"
rm -f "$BAC/nginx/access.log.1" "$BAC/admin/access.log.1"
lancer; code=$?
if [ "$code" -eq 0 ] && [ ! -e "$BAC/admin/access.log.1" ]; then
    ok "pas de journal tourné → code 0, rien d'inventé"
else
    nok "journal tourné absent → code $code"
fi

echo
echo "▸ Sous root, il refuse au lieu d'écrire"
# Un faux `id` en tête du PATH : le banc ne tourne pas en root.
mkdir -p "$BAC/faux"
printf '#!/bin/sh\necho 0\n' > "$BAC/faux/id"
chmod +x "$BAC/faux/id"
rm -f "$BAC/admin/access.log"
PATH="$BAC/faux:$PATH" lancer 2>/dev/null; code=$?
if [ "$code" -ne 0 ] && [ ! -e "$BAC/admin/access.log" ]; then
    ok "uid 0 → code $code, aucune copie écrite"
else
    nok "uid 0 → code $code, copie $( [ -e "$BAC/admin/access.log" ] && echo écrite || echo absente)"
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — les copies ne sortent pas le jeton."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
