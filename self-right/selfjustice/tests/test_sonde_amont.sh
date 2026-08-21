#!/bin/bash
# Garde-fou — la sonde amont de /jurisprudence/verifier.
#
# 🔑 Deux corpus portent le nom du module et ne s'arrêtent pas au même jour.
# `verifier` lit l'index local ; `search` et `decision` servent l'amont
# Judilibre. Un contrôle extérieur a mesuré le 21/08/2026 trois références sur
# trois dont `decision` rendait le texte intégral et que `verifier` déclarait
# absentes — l'outil chargé d'empêcher l'invention démentait celui qui venait de
# servir la pièce.
#
# Depuis, `verifier` sonde l'amont dans le seul cas où il sait regarder trop
# court : rien localement, une date annoncée, et cette date au-delà de sa borne.
# Ce sont les quatre issues de cette sonde qui se contrôlent ici — dont l'échec
# réseau, qui doit laisser la réserve prudente plutôt que de rendre une page
# blanche.
#
# L'amont est un faux, monté sur place. C'est la raison d'être de
# SELFJUSTICE_JUDILIBRE_BASE : une sonde qu'on ne peut pas faire rougir ne
# prouve rien quand elle est verte.
#
# Usage : bash tests/test_sonde_amont.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/.." && pwd)"
command -v php     >/dev/null || { echo "php introuvable" >&2; exit 1; }
command -v sqlite3 >/dev/null || { echo "sqlite3 introuvable" >&2; exit 1; }

TMP="$(mktemp -d)"
API_PID=""; AMONT_PID=""
trap '[ -n "$API_PID" ] && kill "$API_PID" 2>/dev/null
      [ -n "$AMONT_PID" ] && kill "$AMONT_PID" 2>/dev/null
      rm -rf "$TMP"' EXIT

# ── L'index local : deux décisions, borne haute au 2026-07-31 ────────────────
DB="$TMP/index.sqlite"
sqlite3 "$DB" <<'SQL'
CREATE TABLE decisions (id TEXT PRIMARY KEY, number TEXT, decision_date TEXT,
  jurisdiction TEXT, chamber TEXT, location TEXT, formation TEXT,
  publication TEXT, solution TEXT, ecli TEXT, type TEXT, update_date TEXT,
  date_suspecte INTEGER DEFAULT 0);
CREATE TABLE numeros (number_norm TEXT NOT NULL, decision_id TEXT NOT NULL,
  PRIMARY KEY (number_norm, decision_id));
INSERT INTO decisions VALUES
  ('aaaa000000000001','22/00111','2026-06-10','ca','soc','ca_nimes','f','','casse','ECLI:A','ar','2026-06-11',0),
  ('aaaa000000000002','21-11.222','2026-07-31','cc','soc',NULL,'f','b','rejet','ECLI:B','ar','2026-08-01',0);
INSERT INTO numeros VALUES ('2200111','aaaa000000000001'), ('2111222','aaaa000000000002');
SQL

# ── Le faux amont ───────────────────────────────────────────────────────────
# Il ne connaît qu'une décision, du 2026-08-12, hors de portée de l'index. Le
# reste rend une liste vide : c'est ce qui distingue « l'amont ne l'a pas » de
# « l'amont n'a pas répondu », et ces deux-là n'autorisent pas la même phrase.
mkdir -p "$TMP/amont"
cat > "$TMP/amont/router.php" <<'PHP'
<?php
header('Content-Type: application/json');
$q = $_GET['query'] ?? '';
if (preg_replace('/[^A-Za-z0-9]/', '', $q) === '2303077'
    && ($_GET['date_start'] ?? '') === '2026-08-12') {
    echo json_encode(['total' => 1, 'results' => [[
        'id' => 'bbbb000000000003', 'number' => '23/03077',
        'numbers' => ['23/03077'], 'decision_date' => '2026-08-12',
        'jurisdiction' => 'ca', 'chamber' => 'soc', 'location' => 'ca_versailles',
        'publication' => ['non'], 'solution' => 'infirmation', 'ecli' => 'ECLI:C',
        'type' => 'arret',
    ]]]);
    exit;
}
echo json_encode(['total' => 0, 'results' => []]);
PHP

# 🔑 Pas de $( ) autour de ces lancements : une substitution de commande ouvre
# un sous-shell, et le PID qu'on y capture meurt avec lui. Écrit ainsi la
# première fois, ce fichier ne tuait pas le faux amont — le cas « amont
# injoignable » passait donc contre un amont bien vivant, et il l'a montré en
# rendant « trouvee » là où il attendait « absente ». Le port choisi est posé
# dans une variable, pas rendu sur la sortie.
essayer_trois_ports() { # essayer_trois_ports <fonction-de-lancement>
    for _ in 1 2 3; do
        PORT=$(( 8600 + RANDOM % 900 ))
        "$1" "$PORT" && return 0
    done
    return 1
}

lancer_amont() {
    php -S "127.0.0.1:$1" "$TMP/amont/router.php" >/dev/null 2>&1 &
    AMONT_PID=$!
    for _ in $(seq 40); do
        curl -sf -o /dev/null "http://127.0.0.1:$1/?query=x" && return 0
        sleep 0.1
    done
    kill "$AMONT_PID" 2>/dev/null; AMONT_PID=""; return 1
}
essayer_trois_ports lancer_amont || { echo "faux amont non démarré" >&2; exit 1; }
PORT_AMONT="$PORT"

lancer_api() {
    SELFJUSTICE_JURIS_DB="$DB" \
    SELFJUSTICE_JUDILIBRE_BASE="$AMONT_URL" \
    SELFJUSTICE_JUDILIBRE_KEY="factice" \
        php -S "127.0.0.1:$1" -t "$RACINE/api" "$RACINE/api/api.php" >/dev/null 2>&1 &
    API_PID=$!
    for _ in $(seq 40); do
        curl -sf -o /dev/null "http://127.0.0.1:$1/api/jurisprudence/verifier/22%2F00111" && return 0
        sleep 0.1
    done
    kill "$API_PID" 2>/dev/null; API_PID=""; return 1
}

# `search` colle son chemin derrière la base : le faux amont ignore le chemin.
AMONT_URL="http://127.0.0.1:$PORT_AMONT"
essayer_trois_ports lancer_api || { echo "api non démarrée" >&2; exit 1; }
PORT_API="$PORT"
BASE="http://127.0.0.1:$PORT_API/api/jurisprudence/verifier"

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# controle <libellé> <requête> <clé:attendu>…
controle() {
    local libelle="$1" requete="$2"; shift 2
    local corps; corps=$(curl -s "$BASE/$requete")
    local souci=""
    for paire in "$@"; do
        local cle="${paire%%:*}" attendu="${paire#*:}"
        local obtenu
        obtenu=$(printf '%s' "$corps" | python3 -c '
import json, sys
d = json.load(sys.stdin)
v = d.get(sys.argv[1])
print("" if v is None else str(v))' "$cle" 2>/dev/null)
        case "$attendu" in
            "~"*) printf '%s' "$obtenu" | grep -qF "${attendu#\~}" \
                    || souci="$souci [$cle ne contient pas « ${attendu#\~} »]" ;;
            *)    [ "$obtenu" = "$attendu" ] \
                    || souci="$souci [$cle = « $obtenu », attendu « $attendu »]" ;;
        esac
    done
    [ -z "$souci" ] && ok "$libelle" || nok "$libelle —$souci"
}

echo "▸ Ce que l'index local sait, il le dit sans sortir"
controle "présente localement → trouvée, sans sonde" \
    "22%2F00111?jurisdiction=ca&date=2026-06-10" \
    "etat:trouvee" "source:index local"
controle "absente, date DANS la plage → refus ferme, sans sonde" \
    "99%2F99999?jurisdiction=ca&date=2026-06-10" \
    "etat:absente" "source:index local" "reserve:~elle y figurerait"
controle "absente, sans date → prudence, sans sonde" \
    "99%2F99999?jurisdiction=ca" \
    "etat:absente" "reserve:~ne peut pas être exclue"

echo
echo "▸ Hors plage, la sonde tranche à la place de la prudence"
# 🔑 Le cas mesuré le 21/08 : servie par un outil, niée par l'autre.
controle "hors plage, l'amont l'a → trouvée, et le retard est nommé" \
    "23%2F03077?jurisdiction=ca&date=2026-08-12" \
    "etat:trouvee" "source:amont Judilibre" \
    "reserve:~c'est l'index qui est en retard"
controle "hors plage, l'amont ne l'a pas → absence opposable" \
    "23%2F04444?jurisdiction=ca&date=2026-08-12" \
    "etat:absente" "reserve:~elle est opposable"

echo
echo "▸ La décision rendue par l'amont est complète"
# Sur le JSON décodé : `json_encode` échappe les slashes, et « 23/03077 » ne se
# trouve pas tel quel dans le corps brut.
champs=$(curl -s "$BASE/23%2F03077?jurisdiction=ca&date=2026-08-12" | python3 -c '
import json, sys
d = json.load(sys.stdin)["decisions"][0]
print("\n".join(str(v) for v in d.values()))')
for attendu in "bbbb000000000003" "23/03077" "2026-08-12" "ca_versailles" "infirmation"; do
    printf '%s' "$champs" | grep -qF "$attendu" \
        && ok "porte $attendu" || nok "il manque $attendu"
done

echo
echo "▸ Un amont muet ne vaut pas une absence"
# 🔑 C'est ici que la sonde doit rougir. Sans ce cas, rien ne distingue « la
# sonde a répondu vide » de « la sonde n'a pas tourné », et le module rendrait
# un refus ferme sur la foi d'un réseau coupé.
kill "$AMONT_PID" 2>/dev/null; wait "$AMONT_PID" 2>/dev/null; AMONT_PID=""
controle "amont injoignable → réserve prudente, et la panne est nommée" \
    "23%2F03077?jurisdiction=ca&date=2026-08-12" \
    "etat:absente" "reserve:~ne prouve rien"
controle "amont injoignable → la raison remonte" \
    "23%2F03077?jurisdiction=ca&date=2026-08-12" \
    "reserve:~n'a pas répondu"
controle "amont injoignable → l'index local répond quand même" \
    "22%2F00111?jurisdiction=ca&date=2026-06-10" \
    "etat:trouvee" "source:index local"

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — tous les cas conformes."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
