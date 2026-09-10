#!/bin/bash
# Garde-fou — un paramètre refusé ne se découpe pas, il s'abandonne.
#
# 🔑 Le moissonneur confondait deux échecs que l'amont rend tous les deux en
# 400 : « cette tranche pèse trop lourd » (`circuit_breaking_exception: Data
# too large`), qui se corrige en coupant la fenêtre en deux, et « ce paramètre
# n'existe pas », qui ne se corrige jamais — la moitié d'un intervalle est tout
# aussi invalide que l'intervalle entier.
#
# Mesuré le 10/09/2026. `jurisdiction=ce` a été ajouté à la liste moissonnée
# alors que l'amont ne sert que [cc,ca,tj,tcom]. Le script a disséqué l'an 298
# jour par jour pendant SEPT HEURES, sans une seule écriture, et n'aurait
# jamais fini : l'intervalle par défaut court de 0100 à 2027. Rien dans la base
# ne l'aurait signalé — ni erreur, ni volume, ni code de sortie : le processus
# était vivant et paraissait travailler.
#
# Deux propriétés se mesurent ici, et la seconde compte autant que la première :
#
#   1. Sur un refus de paramètre, le script s'arrête — code non nul, un message
#      qui nomme la cause, et AUCUNE dichotomie.
#   2. Sur un refus de volume, il coupe toujours. Une correction qui ferait
#      abandonner les deux casserait le mécanisme qui permet de moissonner
#      1,19 million de décisions à travers un guichet plafonné à 10 000.
#
# Usage : bash tests/sanity_refus_amont.sh
set -uo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/.." && pwd)"
SCRIPT="$RACINE/tools/build_judilibre_index.py"
command -v python3 >/dev/null || { echo "python3 introuvable" >&2; exit 1; }
[ -f "$SCRIPT" ] || { echo "moissonneur introuvable : $SCRIPT" >&2; exit 1; }

TMP="$(mktemp -d)"
AMONT_PID=""
trap '[ -n "$AMONT_PID" ] && kill "$AMONT_PID" 2>/dev/null; rm -rf -- "$TMP"' EXIT
ECHECS=0
verdict() { if [ "$1" = "0" ]; then echo "  ✓ $2"; else echo "  ✗ $2"; ECHECS=$((ECHECS+1)); fi; }

echo "factice" > "$TMP/keyid"
PORT=8791

# Un amont qui refuse, et qui compte ce qu'on lui demande. Le décompte est la
# mesure décisive : la dichotomie se voit au NOMBRE d'appels, pas au verdict.
cat > "$TMP/amont.py" <<'PYEOF'
import json, os, sys
from http.server import BaseHTTPRequestHandler, HTTPServer

MOTIF = os.environ["MOTIF"]          # "param" ou "volume"
COMPTEUR = os.environ["COMPTEUR"]

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_GET(self):
        with open(COMPTEUR, "a") as f:
            f.write(self.path + "\n")
        if self.path.startswith("/stats") and MOTIF == "volume":
            corps = json.dumps({"results": {"total_decisions": 50000}}).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers(); self.wfile.write(corps); return
        if MOTIF == "param":
            corps = json.dumps({"route": "GET /export", "errors": [
                {"value": "ce", "msg": "Value of the jurisdiction parameter "
                 "must be in [cc,ca,tj,tcom].", "param": "jurisdiction[0]",
                 "location": "query"}]}).encode()
        else:
            corps = json.dumps({"error": "circuit_breaking_exception",
                                "reason": "Data too large"}).encode()
        self.send_response(400)
        self.send_header("Content-Type", "application/json")
        self.end_headers(); self.wfile.write(corps)

HTTPServer(("127.0.0.1", int(os.environ["PORT"])), H).serve_forever()
PYEOF

lancer_amont() {  # $1 = motif
    [ -n "$AMONT_PID" ] && { kill "$AMONT_PID" 2>/dev/null; wait "$AMONT_PID" 2>/dev/null; }
    : > "$TMP/appels.txt"
    MOTIF="$1" COMPTEUR="$TMP/appels.txt" PORT="$PORT" python3 "$TMP/amont.py" &
    AMONT_PID=$!
    for _ in $(seq 1 40); do
        python3 -c "import socket,sys; s=socket.socket(); s.settimeout(0.3)
try: s.connect(('127.0.0.1', $PORT)); sys.exit(0)
except Exception: sys.exit(1)" 2>/dev/null && return 0
        sleep 0.25
    done
    echo "l'amont factice n'a pas démarré" >&2; exit 2
}

moissonner() {  # rend le code de sortie, écrit la sortie dans $TMP/sortie.txt
    SELFJUSTICE_JUDILIBRE_BASE="http://127.0.0.1:$PORT" \
    JUDILIBRE_KEY_FILE="$TMP/keyid" \
    JUDILIBRE_DB="$TMP/index.sqlite" \
    JUDILIBRE_MARQUEUR="$TMP/marqueur.txt" \
        timeout 90 python3 "$SCRIPT" > "$TMP/sortie.txt" 2>&1
}

echo "▸ Un paramètre refusé — le script doit renoncer, pas découper"
lancer_amont param
moissonner; CODE=$?
APPELS=$(wc -l < "$TMP/appels.txt")
[ "$CODE" -ne 0 ] && [ "$CODE" -ne 124 ]; verdict $? "sortie en code non nul ($CODE) — 124 signifierait qu'il tournait encore"
grep -qi "refus définitif" "$TMP/sortie.txt"; verdict $? "le message nomme le refus définitif"
grep -qi "jurisdiction" "$TMP/sortie.txt"; verdict $? "le message cite le paramètre en cause"
[ "$APPELS" -le 5 ]; verdict $? "$APPELS appel(s) à l'amont — aucune dichotomie déclenchée"

echo
echo "▸ Un refus de volume — la dichotomie doit rester intacte"
lancer_amont volume
moissonner; CODE=$?
APPELS=$(wc -l < "$TMP/appels.txt")
grep -qiv "refus définitif" "$TMP/sortie.txt"; verdict $? "un volume trop lourd n'est PAS pris pour un refus définitif"
[ "$APPELS" -ge 6 ]; verdict $? "$APPELS appels — la fenêtre a bien été coupée en deux, puis encore"

echo
if [ "$ECHECS" -eq 0 ]; then
    echo "✓ Les deux refus sont distingués — 6 contrôles."
    exit 0
fi
echo "✗ $ECHECS contrôle(s) en échec."
exit 1
