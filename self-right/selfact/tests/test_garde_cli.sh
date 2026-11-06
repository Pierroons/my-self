#!/bin/bash
# Garde-fou — les outils de ligne de commande d'api/ ne font rien s'ils sont servis.
#
# 🔑 `scraper.php` et `reclassify.php` vivent dans le répertoire servi. Le vhost
# ne les ouvre pas, mais c'est une protection d'instance : un vhost réécrit, ou
# une autre instance, et ils deviennent des routes. Servis, ils doivent rendre
# 404 sans rien faire — ni collecte, ni réécriture du catalogue.
#
# Le serveur intégré de PHP tourne sous un autre SAPI que `cli`, comme PHP-FPM :
# c'est le cas que la garde doit couvrir. Le catalogue pointe vers un fichier
# jetable, que seule une garde défaillante toucherait.
#
# Usage : bash tests/test_garde_cli.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
API="$(cd "$ICI/../api" && pwd)"
command -v php >/dev/null || { echo "php introuvable" >&2; exit 1; }

BAC=$(mktemp -d)
SERVEUR=""
trap '[ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null; rm -rf "$BAC"' EXIT

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

cp "$ICI/catalog-fixture.json" "$BAC/catalog.json"
empreinte=$(md5sum < "$BAC/catalog.json")

for _ in 1 2 3; do
    # Un port que le système donne libre. Tiré au hasard, il pouvait être déjà pris
    # (Tor écoute sur 9050, ou un autre serveur du même banc), et la sonde de
    # démarrage acceptait alors celui qui répondait à sa place.
    PORT=$(python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')
    # Déjà servi par un autre : on en prend un autre, avant de lancer le nôtre.
    if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then continue; fi
    SELFACT_CATALOG="$BAC/catalog.json" php -S "127.0.0.1:$PORT" -t "$API" >/dev/null 2>&1 &
    candidat=$!
    for _ in $(seq 40); do
        curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break
        sleep 0.1
    done
    if kill -0 "$candidat" 2>/dev/null && curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then SERVEUR=$candidat; break; fi
    kill "$candidat" 2>/dev/null
done
[ -n "$SERVEUR" ] || { echo "le serveur PHP n'a pas démarré" >&2; exit 1; }

echo "▸ Servis, les outils de ligne de commande ne font rien"
for outil in scraper.php reclassify.php; do
    r=$(curl -s --max-time 20 -o "$BAC/corps" -w '%{http_code}' "http://127.0.0.1:$PORT/$outil")
    if [ "$r" = "404" ] && [ ! -s "$BAC/corps" ]; then
        ok "$outil → 404, corps vide"
    else
        nok "$outil → HTTP $r, $(wc -c < "$BAC/corps") octet(s) rendus"
    fi
done
if [ "$(md5sum < "$BAC/catalog.json")" = "$empreinte" ]; then
    ok "le catalogue n'a pas été touché"
else
    nok "le catalogue a été réécrit par un outil servi"
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — servis, les outils ne font rien."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
