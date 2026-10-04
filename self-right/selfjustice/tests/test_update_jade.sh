#!/bin/bash
# Garde-fou — le pilote JADE sonne quand le fonds décroche, et pas avant.
#
# 🔑 Deux défauts opposés guettent un pilote quotidien. Muet, il laisse le fonds
# se figer : c'est arrivé, trois semaines, sans pilote du tout. Bavard, il sonne
# à chaque panne d'un soir de la DILA, et l'alerte cesse d'être lue — le jour où
# elle compte, son rouge ressemble à celui de la veille.
#
# Troisième défaut : une panne de la source qui dure laisse l'unité `failed`
# chaque soir, et `systemctl --failed` cesse d'être lu. Le pilote sort alors en un
# code que l'unité accepte — le banc vérifie qu'elle le déclare bien.
#
# Le collecteur est remplacé par un faux qui rend le code voulu : ses propres
# comportements sont éprouvés par sanity_jade_collecteur.py. Ici, seule la
# décision du pilote est sous examen.
#
# Usage : bash tests/test_update_jade.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
PILOTE="$ICI/../tools/update_jade.sh"
UNITE="$ICI/../../../deploy/selfjustice/jade-update.service"
[ -r "$PILOTE" ] || { echo "pilote introuvable : $PILOTE" >&2; exit 1; }

BAC=$(mktemp -d)
SERVEUR=""
trap '[ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null; rm -rf "$BAC"' EXIT

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# Le pilote et un faux collecteur côte à côte : le pilote cherche le sien à côté
# de lui-même.
mkdir -p "$BAC/bin" "$BAC/cache"
cp "$PILOTE" "$BAC/bin/update_jade.sh"
cat > "$BAC/bin/build_jade_db.py" <<'PY'
import os, sys
print("faux collecteur, code", os.environ["FAUX_RC"])
sys.exit(int(os.environ["FAUX_RC"]))
PY

# Un faux ntfy qui consigne chaque titre reçu.
cat > "$BAC/ntfy.py" <<'PY'
import os, pathlib
from http.server import BaseHTTPRequestHandler, HTTPServer
class H(BaseHTTPRequestHandler):
    def do_POST(self):
        self.rfile.read(int(self.headers.get("Content-Length") or 0))
        titre = (self.headers.get("Title") or "").encode("latin-1").decode("utf-8", "replace")
        with open(pathlib.Path(os.environ["BAC_DIR"]) / "ntfy.log", "a", encoding="utf-8") as f:
            f.write(titre + "\n")
        self.send_response(200); self.send_header("Content-Length", "0"); self.end_headers()
    def log_message(self, *a): pass
HTTPServer(("127.0.0.1", int(os.environ["PORT"])), H).serve_forever()
PY
for _ in 1 2 3; do
    PORT=$(( 8900 + RANDOM % 600 ))
    BAC_DIR="$BAC" PORT="$PORT" python3 "$BAC/ntfy.py" >/dev/null 2>&1 &
    candidat=$!
    for _ in $(seq 40); do
        curl -s -o /dev/null -X POST "http://127.0.0.1:$PORT/" && break
        sleep 0.1
    done
    if curl -s -o /dev/null -X POST "http://127.0.0.1:$PORT/"; then SERVEUR=$candidat; break; fi
    kill "$candidat" 2>/dev/null
done
[ -n "$SERVEUR" ] || { echo "le faux ntfy n'a pas démarré" >&2; exit 1; }
echo "banc" > "$BAC/token"

# jouer <code du collecteur> <âge en jours du dernier incrément> → code du pilote
jouer() {
    python3 - "$BAC/index.sqlite" "$2" <<'PY'
import datetime, sqlite3, sys
c = sqlite3.connect(sys.argv[1])
c.execute("CREATE TABLE IF NOT EXISTS jade_etat (cle TEXT PRIMARY KEY, valeur TEXT)")
jour = datetime.date.today() - datetime.timedelta(days=int(sys.argv[2]))
c.execute("INSERT OR REPLACE INTO jade_etat VALUES ('dernier_diff', ?)",
          ("JADE_%s-213000.tar.gz" % jour.strftime("%Y%m%d"),))
c.commit()
PY
    : > "$BAC/ntfy.log"
    FAUX_RC="$1" JUDILIBRE_DB="$BAC/index.sqlite" SELFJUSTICE_JADE_CACHE="$BAC/cache" \
    SELFJUSTICE_JADE_LOG="$BAC/jade.log" SELFJUSTICE_NTFY_URL="http://127.0.0.1:$PORT/" \
    SELFJUSTICE_NTFY_TOKEN_FILE="$BAC/token" \
        bash "$BAC/bin/update_jade.sh"
}

echo "▸ Ce qui doit rester silencieux"
jouer 0 1; code=$?
if [ "$code" -eq 0 ] && [ ! -s "$BAC/ntfy.log" ]; then
    ok "collecte faite → code 0, aucune alerte"
else
    nok "collecte faite → code $code, alertes : $(paste -sd'|' "$BAC/ntfy.log")"
fi

jouer 4 2; code=$?
if [ "$code" -eq 0 ] && [ ! -s "$BAC/ntfy.log" ] && grep -q "alerte au-delà de 7" "$BAC/jade.log"; then
    ok "DILA injoignable, dernier incrément il y a 2 jours → journalisé, pas d'alerte"
else
    nok "une panne d'un soir sonne (code $code) : $(paste -sd'|' "$BAC/ntfy.log")"
fi

echo
echo "▸ Ce qui doit sonner"
jouer 4 8; code=$?
if [ "$code" -eq 75 ] && grep -q "DILA injoignable, JADE en retard" "$BAC/ntfy.log"; then
    ok "DILA injoignable, dernier incrément il y a 8 jours → alerte, code 75"
else
    nok "un fonds en retard d'une semaine : code $code (attendu 75), alertes : $(paste -sd'|' "$BAC/ntfy.log")"
fi
# Le code de la source indisponible n'a de sens que si l'unité l'accepte.
if grep -q -E '^SuccessExitStatus=([0-9 ]* )?75( |$)' "$UNITE"; then
    ok "l'unité accepte 75 (SuccessExitStatus)"
else
    nok "l'unité ne déclare pas 75 : le service resterait « failed » à chaque panne de la DILA"
fi

jouer 1 0; code=$?
if [ "$code" -eq 1 ] && grep -q "collecte JADE en echec" "$BAC/ntfy.log"; then
    ok "collecte en échec → alerte, code 1 (une vraie panne reste « failed »)"
else
    nok "un échec du collecteur : code $code (attendu 1), alertes : $(paste -sd'|' "$BAC/ntfy.log")"
fi

# Une base qui ne dit pas son dernier incrément : la panne de la source
# n'explique pas tout, ce n'est pas le cas « source indisponible ».
: > "$BAC/ntfy.log"
FAUX_RC=4 JUDILIBRE_DB="$BAC/vide.sqlite" SELFJUSTICE_JADE_CACHE="$BAC/cache" \
SELFJUSTICE_JADE_LOG="$BAC/jade.log" SELFJUSTICE_NTFY_URL="http://127.0.0.1:$PORT/" \
SELFJUSTICE_NTFY_TOKEN_FILE="$BAC/token" \
    bash "$BAC/bin/update_jade.sh"; code=$?
if [ "$code" -eq 1 ] && grep -q "fonds JADE illisible" "$BAC/ntfy.log"; then
    ok "DILA injoignable et base illisible → alerte, code 1"
else
    nok "base illisible : code $code (attendu 1), alertes : $(paste -sd'|' "$BAC/ntfy.log")"
fi

echo
echo "▸ Le cache ne garde que le mois"
touch -d "40 days ago" "$BAC/cache/JADE_20200101-213000.tar.gz"
touch -d "5 days ago" "$BAC/cache/JADE_20200201-213000.tar.gz"
jouer 0 1 >/dev/null
if [ ! -e "$BAC/cache/JADE_20200101-213000.tar.gz" ] && [ -e "$BAC/cache/JADE_20200201-213000.tar.gz" ]; then
    ok "incrément de 40 jours purgé, celui de 5 jours gardé"
else
    nok "purge du cache : $(ls "$BAC/cache" | paste -sd' ')"
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — le pilote sonne quand il faut."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
