#!/bin/bash
# Garde-fou — le texte servi porte sa mise en forme, pas celle de sa source.
#
# 🔑 Au 22/08/2026, un tiers des articles en base portaient la mise en forme de
# leur source : l'article 22 du RGPD comptait 49 % de blancs, l'article P1-1 de
# la CEDH se terminait par deux numéros de page du PDF d'origine.
#
# ⚠️ Ce banc éprouve autant ce que le nettoyage NE doit PAS faire : les sauts de
# ligne qui séparent les alinéas restent, et les chiffres à l'intérieur du texte
# ne bougent pas — un montant, un délai ou un renvoi d'article leur ressemblent.
#
# Le jeu vient de la base de production : un texte fabriqué prouverait que le
# code marche sur ce qu'on a imaginé, pas sur ce qu'EUR-Lex publie.
#
# Usage : bash tests/test_texte_propre.sh

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/.." && pwd)"
JEU="$ICI/eu-jeu-artefacts.json"
command -v php >/dev/null || { echo "php introuvable" >&2; exit 1; }
[ -f "$JEU" ] || { echo "jeu introuvable : $JEU" >&2; exit 1; }

TMP="$(mktemp -d)"; PID=""
trap '[ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "$TMP"' EXIT

DB="$TMP/eu.sqlite"
sqlite3 "$DB" "CREATE TABLE articles (id TEXT PRIMARY KEY, source TEXT, num TEXT,
  titre TEXT, texte TEXT, etat TEXT, date_debut TEXT, date_fin TEXT, url_source TEXT);"
python3 - "$JEU" "$DB" <<'PY'
import json, pathlib, sqlite3, sys
lignes = json.loads(pathlib.Path(sys.argv[1]).read_text())
c = sqlite3.connect(sys.argv[2])
c.executemany("INSERT INTO articles VALUES (?,?,?,?,?,?,?,?,?)",
              [tuple(l[k] for k in ("id","source","num","titre","texte","etat",
                                    "date_debut","date_fin","url_source")) for l in lignes])
c.commit()
PY

for _ in 1 2 3; do
    PORT=$(( 8700 + RANDOM % 900 ))
    SELFJUSTICE_EU_DB="$DB" php -S "127.0.0.1:$PORT" -t "$RACINE/api" \
        "$RACINE/api/api.php" >/dev/null 2>&1 &
    PID=$!
    for _ in $(seq 40); do
        curl -sf -o /dev/null "http://127.0.0.1:$PORT/api/eu/article/CEDH/P1-1" && break 2
        sleep 0.1
    done
    kill "$PID" 2>/dev/null; PID=""
done
[ -n "$PID" ] || { echo "l'api de test n'a pas démarré" >&2; exit 1; }
BASE="http://127.0.0.1:$PORT/api/eu/article"
export BASE

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

texte() { curl -s "$BASE/$1" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("texte") or "")'; }

echo "▸ Ce que la source impose disparaît"
# ⚠️ Le comptage se fait en Python. `grep -oP '\xc2\xa0'` cherche DEUX
# caractères — U+00C2 puis U+00A0 — et non la séquence d'octets d'un insécable :
# il rend zéro sur un texte qui en est plein, et la condition `-eq 0` ne peut
# alors jamais être fausse. Vérifié : la même sonde affichait « ✓ ni insécable »
# sur un texte à 49 % de blancs, avec 16 insécables intacts.
for a in "RGPD/22" "RGPD/9" "AI_ACT/14"; do
    mesure=$(texte "$a" | python3 -c '
import re, sys
t = sys.stdin.read()
print(t.count("\xa0"), len(re.findall(r"[ \t]{3,}", t)))')
    set -- $mesure
    if [ "${1:-1}" -eq 0 ] && [ "${2:-1}" -eq 0 ]; then
        ok "$a — ni insécable, ni colonne d'espaces"
    else
        nok "$a — ${1:-?} insécable(s), ${2:-?} colonne(s) de 3 espaces ou plus"
    fi
done

echo
echo "▸ L'aperçu de la recherche est nettoyé lui aussi"
# 🔑 Deux routes servent ces textes : l'article entier, et l'extrait de 200
# caractères que rend la recherche. Le nettoyage n'était posé que sur la
# première — un correctif appliqué à un seul exemplaire d'un mécanisme dupliqué.
# C'est la seconde que voit un agent qui cherche par mot-clé.
RECHERCHE="${BASE%/article}/search"
sale=$(curl -s "$RECHERCHE?q=automatis&limit=5" | python3 -c '
import json, re, sys
d = json.load(sys.stdin)
n = c = 0
for r in d.get("results") or []:
    a = r.get("apercu") or ""
    n += a.count("\xa0"); c += len(re.findall(r"[ \t]{3,}", a))
print(n, c)')
set -- $sale
if [ "${1:-1}" -eq 0 ] && [ "${2:-1}" -eq 0 ]; then
    ok "les aperçus ne portent ni insécable ni colonne d'espaces"
else
    nok "aperçus : ${1:-?} insécable(s), ${2:-?} colonne(s) — la recherche échappe au nettoyage"
fi

echo
echo "▸ La pagination du PDF ne se lit pas comme du droit"
t=$(texte "CEDH/P1-1")
if printf '%s' "$t" | tail -1 | grep -qE '^[0-9 ]+$'; then
    nok "CEDH P1-1 finit encore par « $(printf '%s' "$t" | tail -1) »"
else
    ok "CEDH P1-1 finit sur du texte : « …$(printf '%s' "$t" | tail -c 30 | tr '\n' ' ') »"
fi

echo
echo "▸ Ce que le nettoyage ne doit PAS emporter"
# 🔑 Sans ces cas, un nettoyage trop zélé passerait pour un succès : il suffirait
# d'écraser les sauts de ligne et les chiffres pour n'avoir plus aucun artefact.
t=$(texte "RGPD/22")
[ "$(printf '%s' "$t" | wc -l)" -gt 2 ] \
    && ok "les alinéas gardent leurs sauts de ligne" \
    || nok "le texte a été aplati sur une ligne"
printf '%s' "$t" | grep -qE '(^|[^0-9])[123]\.' \
    && ok "les numéros d'alinéa survivent" \
    || nok "les numéros d'alinéa ont disparu"
# 🔑 Le contrôle porte sur les caractères NON BLANCS, pas sur le volume. Une
# première version comparait les octets et rougissait à 46 % de perte — or le
# contrôle extérieur avait justement mesuré 49 % de blancs sur cet article :
# retirer presque la moitié du volume était le comportement attendu. Un banc qui
# mesure la taille au lieu de l'information condamne le correctif qu'il vérifie.
python3 - "$JEU" <<'PYEOF'
import json, pathlib, re, sys, urllib.request
jeu = {(r["source"], r["num"]): r["texte"] or ""
       for r in json.loads(pathlib.Path(sys.argv[1]).read_text())}
import os
base = os.environ["BASE"]
echecs = 0
for (src, num), avant in jeu.items():
    with urllib.request.urlopen(f"{base}/{src}/{num}", timeout=20) as r:
        apres = json.loads(r.read()).get("texte") or ""
    dur = lambda t: re.sub(r"\s+", "", t.replace("\xa0", " "))
    a, b = dur(avant), dur(apres)
    # La pagination retirée est le seul écart toléré : quelques chiffres.
    perdu = len(a) - len(b)
    if b and a.endswith(b) is False and not a.startswith(b[:200]):
        print(f"  ✗ {src} {num} — le texte utile a changé de contenu")
        echecs += 1
    elif perdu > 12:
        print(f"  ✗ {src} {num} — {perdu} caractère(s) utiles perdus")
        echecs += 1
    else:
        print(f"  ✓ {src} {num} — {perdu} caractère(s) non blancs retirés (pagination)")
raise SystemExit(1 if echecs else 0)
PYEOF
if [ $? -eq 0 ]; then
    reussites=$((reussites + 1))
else
    echecs=$((echecs + 1))
fi

echo
total=$((reussites + echecs))
if [ "$echecs" -eq 0 ]; then
    echo "OK — $reussites/$total propriétés tiennent."
    exit 0
fi
echo "ÉCHEC — $echecs propriété(s) sur $total." >&2
exit 1
