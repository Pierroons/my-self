#!/usr/bin/env bash
# Une moisson partielle se garde, elle ne se détruit pas.
#
# Le 16/09/2026, cinq tranches refusées sur quatre-vingt-douze ont fait
# restaurer un index qui venait de gagner 4 190 décisions en 2 h 34 — pour
# revenir à celui d'avant, vieux de quinze jours. Le tout-ou-rien gardait le
# plus incomplet des deux, et le cycle se rejouait à chaque passage.
#
# Deux correctifs, éprouvés ici séparément :
#
#   1. Un `400` SANS corps se réessaie. Il ne met en cause ni un paramètre
#      (corps vide, donc pas de `"param"`), ni le plafond de pagination (416 au
#      11e lot), ni le volume (`Data too large`, qui s'explique). C'était le
#      seul des trois refus à n'avoir droit à aucune seconde chance.
#
#   2. L'enveloppe distingue TROIS issues : complète (0), partielle mais saine
#      (3 → on garde), échec (autre → on restaure). Les données et la fraîcheur
#      sont deux questions distinctes ; elles étaient traitées comme une seule.
#
# ⚠️ Les trois propriétés de l'issue 3 se mesurent SÉPARÉMENT : garder les
# données sans bloquer le marqueur ferait mentir l'API sur sa fraîcheur, et
# garder les données sans sortir en échec ferait taire la sentinelle. Un banc
# qui ne vérifierait que « la base est encore là » laisserait passer les deux.

set -u

ICI="$(cd "$(dirname "$0")" && pwd)"
OUTILS="$(dirname "$ICI")/tools"
SCRIPT="$OUTILS/build_judilibre_index.py"
ENVELOPPE="$OUTILS/update_judilibre.sh"

command -v python3 >/dev/null || { echo "python3 introuvable" >&2; exit 2; }
command -v sqlite3 >/dev/null || { echo "sqlite3 introuvable" >&2; exit 2; }
[ -f "$SCRIPT" ]    || { echo "moissonneur introuvable : $SCRIPT" >&2; exit 2; }
[ -f "$ENVELOPPE" ] || { echo "enveloppe introuvable : $ENVELOPPE" >&2; exit 2; }

TMP="$(mktemp -d)"
AMONT_PID=""
trap '[ -n "$AMONT_PID" ] && kill "$AMONT_PID" 2>/dev/null; rm -rf -- "$TMP"' EXIT
ECHECS=0
verdict() { if [ "$1" = "0" ]; then echo "  ✓ $2"; else echo "  ✗ $2${3:+ — $3}"; ECHECS=$((ECHECS+1)); fi; }

echo "factice" > "$TMP/keyid"
PORT=8793

# ── 1. Le réessai du 400 sans corps ────────────────────────────────────────
#
# L'amont journalise chaque requête ENTIÈRE. Un refus réessayé et un refus
# abandonné rendent le même échec final : seule la répétition d'une requête à
# l'identique les distingue — et pas le volume d'appels, que la dichotomie
# gonfle toute seule.

cat > "$TMP/amont.py" <<'PYEOF'
import json, os
from http.server import BaseHTTPRequestHandler, HTTPServer

CORPS = os.environ["CORPS"]          # "vide" ou "param"
COMPTEUR = os.environ["COMPTEUR"]

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_GET(self):
        with open(COMPTEUR, "a") as f:
            f.write(self.path + "\n")
        if self.path.startswith("/stats"):
            c = json.dumps({"results": {"total_decisions": 500}}).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers(); self.wfile.write(c); return
        if CORPS == "param":
            c = json.dumps({"errors": [{"msg": "bad", "param": "jurisdiction"}]}).encode()
        else:
            c = b""                  # le 400 muet du 16/09/2026
        self.send_response(400)
        self.end_headers()
        if c: self.wfile.write(c)

HTTPServer(("127.0.0.1", int(os.environ["PORT"])), H).serve_forever()
PYEOF

lancer_amont() {  # $1 = forme du corps
    [ -n "$AMONT_PID" ] && { kill "$AMONT_PID" 2>/dev/null; wait "$AMONT_PID" 2>/dev/null; }
    : > "$TMP/appels.txt"
    CORPS="$1" COMPTEUR="$TMP/appels.txt" PORT="$PORT" python3 "$TMP/amont.py" &
    AMONT_PID=$!
    for _ in $(seq 1 40); do
        python3 -c "import socket,sys
s=socket.socket(); s.settimeout(0.3)
try: s.connect(('127.0.0.1', $PORT)); sys.exit(0)
except Exception: sys.exit(1)" 2>/dev/null && return 0
        sleep 0.25
    done
    echo "l'amont factice n'a pas démarré" >&2; exit 2
}

# ⚠️ Fenêtre de deux jours, délibérément. Le réessai coûte 5 + 10 + 15 s par
# requête refusée, et la dichotomie recoupe chaque tranche qui échoue : sur une
# fenêtre de quinze jours dont TOUTES les tranches sont refusées — ce qui
# n'arrive qu'ici — le banc dépassait 180 s et rendait 124 au lieu du code
# attendu. En production, cinq tranches refusées sur quatre-vingt-douze ont
# coûté 2 min 30 de plus, ce qui est le prix cherché.
moissonner() {
    rm -f "$TMP/index.sqlite"
    SELFJUSTICE_JUDILIBRE_BASE="http://127.0.0.1:$PORT" \
    SELFJUSTICE_REESSAI_PAUSE=0.05 \
    JUDILIBRE_KEY_FILE="$TMP/keyid" \
    JUDILIBRE_DB="$TMP/index.sqlite" \
    JUDILIBRE_MARQUEUR="$TMP/marqueur.txt" \
        timeout 300 python3 "$SCRIPT" --depuis "$(date -d '2 days ago' +%F)" \
            > "$TMP/sortie.txt" 2>&1
}

echo "▸ Un 400 SANS corps se réessaie"
lancer_amont vide
CODE=0; moissonner || CODE=$?
# 🔑 C'est ici, et nulle part ailleurs, que le code de sortie du VRAI
# moissonneur est mesuré : la section 2 lui substitue un faux script pour
# juger l'enveloppe. Sans ce contrôle, remplacer `sys.exit(3)` par
# `sys.exit(1)` passait inaperçu — et l'enveloppe se serait remise à détruire
# les moissons partielles sans qu'un seul banc ne rougisse.
[ "$CODE" = "3" ] && verdict 0 "le moissonneur sort en 3 : incomplet, mais les données valent" \
                  || verdict 1 "le moissonneur sort en 3 sur une moisson partielle" "code $CODE"
# 🔑 Compter les appels à /export ne distingue RIEN : la dichotomie en produit
# des dizaines en coupant les tranches, et le seuil est franchi sans un seul
# réessai — vérifié au canari, qui n'a pas rougi. Ce qui signe un réessai, et
# rien d'autre, c'est la MÊME requête rejouée à l'identique.
REJOUES=$(grep "^/export" "$TMP/appels.txt" 2>/dev/null | sort | uniq -c | sort -rn | head -1 | awk '{print $1}')
REJOUES=${REJOUES:-0}
[ "$REJOUES" -ge 4 ] && verdict 0 "une requête refusée est rejouée à l'identique ($REJOUES fois)" \
                     || verdict 1 "une requête refusée est rejouée à l'identique" "au mieux $REJOUES fois, 4 attendues"
grep -q "400 sans corps — nouvelle tentative" "$TMP/sortie.txt" \
    && verdict 0 "le journal nomme le réessai" \
    || verdict 1 "le journal nomme le réessai"

echo
echo "▸ Un 400 qui met en cause un PARAMÈTRE ne se réessaie toujours pas"
lancer_amont param
moissonner || true
REJOUES=$(grep "^/export" "$TMP/appels.txt" 2>/dev/null | sort | uniq -c | sort -rn | head -1 | awk '{print $1}')
REJOUES=${REJOUES:-0}
[ "$REJOUES" = "1" ] && verdict 0 "aucune requête n'est rejouée — le refus est définitif" \
                     || verdict 1 "aucune requête n'est rejouée" "l'une l'a été $REJOUES fois"
grep -q "Refus définitif" "$TMP/sortie.txt" \
    && verdict 0 "et il est nommé comme définitif" \
    || verdict 1 "et il est nommé comme définitif"

kill "$AMONT_PID" 2>/dev/null; AMONT_PID=""

# ── 2. Les trois issues de l'enveloppe ─────────────────────────────────────
#
# Le moissonneur est remplacé par un script qui rend le code voulu et écrit
# une décision : c'est le comportement de l'ENVELOPPE qu'on éprouve ici, pas
# celui du collecteur. Elle cherche son moissonneur à côté d'elle, d'où la copie.

BAC="$TMP/bac"; mkdir -p "$BAC"
cp "$ENVELOPPE" "$BAC/update_judilibre.sh"

preparer() {  # $1 = code que rendra le faux moissonneur
    rm -rf "$TMP/db"; mkdir -p "$TMP/db"
    sqlite3 "$TMP/db/index.sqlite" \
        "CREATE TABLE decisions (id TEXT PRIMARY KEY); INSERT INTO decisions VALUES ('ancienne');"
    echo "2026-09-01" > "$TMP/db/marqueur.txt"
    : > "$TMP/journal.log"
    cat > "$BAC/build_judilibre_index.py" <<PYEOF
import os, sqlite3, sys
c = sqlite3.connect(os.environ["JUDILIBRE_DB"])
c.execute("INSERT OR REPLACE INTO decisions VALUES ('neuve')")
c.commit(); c.close()
if $1 == 0:
    open(os.environ["JUDILIBRE_MARQUEUR"], "w").write("2026-09-16\n")
sys.exit($1)
PYEOF
}

lancer_enveloppe() {
    SELFJUSTICE_DIR="$TMP" \
    SELFJUSTICE_DB_DIR="$TMP/db" \
    JUDILIBRE_DB="$TMP/db/index.sqlite" \
    JUDILIBRE_MARQUEUR="$TMP/db/marqueur.txt" \
    JUDILIBRE_KEY_FILE="$TMP/keyid" \
    SELFJUSTICE_LOG="$TMP/journal.log" \
    SELFJUSTICE_NTFY_URL="" \
        bash "$BAC/update_judilibre.sh" > "$TMP/env-sortie.txt" 2>&1
}

compte() { sqlite3 "$TMP/db/index.sqlite" "SELECT COUNT(*) FROM decisions" 2>/dev/null || echo "ERREUR"; }

echo
echo "▸ Issue 3 — moisson partielle : trois propriétés, mesurées séparément"
preparer 3
lancer_enveloppe; CODE=$?
[ "$(compte)" = "2" ] && verdict 0 "les données gagnées sont CONSERVÉES (2 décisions)" \
                      || verdict 1 "les données gagnées sont conservées" "$(compte) décision(s)"
[ "$(cat "$TMP/db/marqueur.txt")" = "2026-09-01" ] \
    && verdict 0 "le marqueur de fraîcheur n'a PAS avancé" \
    || verdict 1 "le marqueur n'a pas avancé" "il vaut $(cat "$TMP/db/marqueur.txt")"
[ "$CODE" != "0" ] && verdict 0 "l'enveloppe sort en ÉCHEC — la sentinelle alertera (code $CODE)" \
                   || verdict 1 "l'enveloppe sort en échec" "code 0, la sentinelle se tairait"
[ ! -f "$TMP/db/index.sqlite.bak" ] && verdict 0 "la sauvegarde est nettoyée, pas laissée derrière" \
                                   || verdict 1 "la sauvegarde est nettoyée"
grep -q "index CONSERVÉ" "$TMP/journal.log" \
    && verdict 0 "le journal dit que l'index est conservé" \
    || verdict 1 "le journal dit que l'index est conservé"

echo
echo "▸ Issue 1 — échec franc : l'index précédent est restauré"
preparer 1
lancer_enveloppe; CODE=$?
[ "$(compte)" = "1" ] && verdict 0 "la base est RESTAURÉE (1 décision, la neuve est partie)" \
                      || verdict 1 "la base est restaurée" "$(compte) décision(s)"
[ "$CODE" != "0" ] && verdict 0 "l'enveloppe sort en échec (code $CODE)" \
                   || verdict 1 "l'enveloppe sort en échec"
grep -q "index précédent restauré" "$TMP/journal.log" \
    && verdict 0 "le journal dit que l'index est restauré" \
    || verdict 1 "le journal dit que l'index est restauré"

echo
echo "▸ Issue 0 — moisson entière : tout avance"
preparer 0
lancer_enveloppe; CODE=$?
[ "$CODE" = "0" ] && verdict 0 "l'enveloppe sort en succès" || verdict 1 "l'enveloppe sort en succès" "code $CODE"
[ "$(compte)" = "2" ] && verdict 0 "les données sont gardées" || verdict 1 "les données sont gardées"
[ "$(cat "$TMP/db/marqueur.txt")" = "2026-09-16" ] \
    && verdict 0 "le marqueur a avancé" \
    || verdict 1 "le marqueur a avancé" "il vaut $(cat "$TMP/db/marqueur.txt")"

echo
if [ "$ECHECS" -gt 0 ]; then
    echo -e "\033[31m✗ $ECHECS contrôle(s) en échec\033[0m"
    exit 1
fi
echo -e "\033[32m✓ un refus muet se réessaie, une moisson partielle se garde sans mentir sur sa fraîcheur\033[0m"
