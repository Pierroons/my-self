#!/bin/bash
# Garde-fou — le renvoi d'un numéro d'article qui a changé de contenu.
#
# 🔑 Sur un article abrogé le module crie ; sur un numéro recyclé il se taisait.
# Mesuré le 21/08/2026 par un contrôle extérieur sur l'article 1382 du code
# civil : depuis 2016 ce numéro porte les présomptions judiciaires, et la
# responsabilité délictuelle qu'on y cherche est passée au 1240. La réponse
# était en vigueur, exacte, datée — et hors sujet, sans un signal. C'est le cas
# où toutes les marques de fiabilité sont réunies et où l'on se trompe.
#
# Le renvoi n'est pas une table écrite à la main : il se déduit de la base, en
# demandant si le texte qu'un numéro portait autrefois vit aujourd'hui sous un
# autre numéro du même code. Ce qui se contrôle ici, c'est donc aussi la
# LIMITE de cette déduction — L122-14 est devenu L1232-2 avec une rédaction
# retouchée, l'égalité de texte échoue, et le module doit se taire plutôt que
# deviner. Un garde-fou qui n'éprouverait que les cas réussis laisserait croire
# la couverture complète.
#
# Le jeu de données est extrait de la base de production (13 lignes réelles,
# textes intégraux) : un cas fabriqué à la main prouverait que le code marche
# sur ce qu'on a imaginé, pas sur ce que LEGI contient.
#
# Usage : bash tests/test_renvoi_article.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/.." && pwd)"
JEU="$ICI/legi-jeu-renvoi.json"
command -v php     >/dev/null || { echo "php introuvable" >&2; exit 1; }
command -v sqlite3 >/dev/null || { echo "sqlite3 introuvable" >&2; exit 1; }
[ -f "$JEU" ] || { echo "jeu de test introuvable : $JEU" >&2; exit 1; }

TMP="$(mktemp -d)"
API_PID=""
trap '[ -n "$API_PID" ] && kill "$API_PID" 2>/dev/null; rm -rf "$TMP"' EXIT

DB="$TMP/legi.sqlite"
sqlite3 "$DB" "CREATE TABLE articles (id TEXT PRIMARY KEY, num TEXT, etat TEXT,
  date_debut TEXT, date_fin TEXT, code_id TEXT, code_titre TEXT, texte TEXT);
  CREATE INDEX idx_num ON articles(num);"
python3 - "$JEU" "$DB" <<'PY'
import json, pathlib, sqlite3, sys
lignes = json.loads(pathlib.Path(sys.argv[1]).read_text())
c = sqlite3.connect(sys.argv[2])
c.executemany("INSERT INTO articles VALUES (?,?,?,?,?,?,?,?)",
              [tuple(l[k] for k in ("id", "num", "etat", "date_debut",
                                    "date_fin", "code_id", "code_titre", "texte"))
               for l in lignes])
c.commit()
PY

for _ in 1 2 3; do
    PORT=$(( 8500 + RANDOM % 900 ))
    SELFJUSTICE_LEGI_DB="$DB" php -S "127.0.0.1:$PORT" -t "$RACINE/api" \
        "$RACINE/api/api.php" >/dev/null 2>&1 &
    API_PID=$!
    for _ in $(seq 40); do
        curl -sf -o /dev/null "http://127.0.0.1:$PORT/api/legi/article/1103?code=civil" && break 2
        sleep 0.1
    done
    kill "$API_PID" 2>/dev/null; API_PID=""
done
[ -n "$API_PID" ] || { echo "l'api de test n'a pas démarré" >&2; exit 1; }
BASE="http://127.0.0.1:$PORT/api/legi/article"

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

renvoi() { # renvoi <requête> <clé|"-" pour le bloc entier>
    curl -s "$BASE/$1" | python3 -c '
import json, sys
r = json.load(sys.stdin).get("renvoi")
if r is None:
    print("AUCUN"); raise SystemExit
print(json.dumps(r, ensure_ascii=False) if sys.argv[1] == "-" else str(r.get(sys.argv[1], "")))' "$2"
}

attendu() { # attendu <libellé> <requête> <clé> <valeur>
    local obtenu; obtenu=$(renvoi "$2" "$3")
    [ "$obtenu" = "$4" ] && ok "$1" || nok "$1 — $3 = « $obtenu », attendu « $4 »"
}

echo "▸ Un numéro recyclé se signale"
attendu "1382 civil → renvoi de nature numero_recycle" \
    "1382?code=civil" "nature" "numero_recycle"
attendu "1382 civil → pointe vers 1240" \
    "1382?code=civil" "article" "1240"
attendu "1382 civil → date la bascule au 2016-10-01" \
    "1382?code=civil" "depuis" "2016-10-01"
if renvoi "1382?code=civil" "message" | grep -q "^⚠️"; then
    ok "1382 civil → le message porte l'alerte"
else
    nok "1382 civil → le message n'alerte pas"
fi

echo
echo "▸ Le texte servi reste celui du numéro demandé"
# 🔑 Le renvoi avertit, il ne substitue pas : servir 1240 sous le nom 1382
# remplacerait une erreur silencieuse par une autre.
texte=$(curl -s "$BASE/1382?code=civil" | python3 -c 'import json,sys; print(json.load(sys.stdin)["texte"][:20])')
[ "$texte" = "Les présomptions qui" ] \
    && ok "1382 sert bien les présomptions, pas la responsabilité" \
    || nok "1382 sert « $texte »"

echo
echo "▸ Un article ordinaire ne déclenche rien"
# Sans ce cas, un renvoi qui se poserait sur tout passerait pour un succès.
attendu "1103 civil, jamais déplacé → aucun renvoi" \
    "1103?code=civil" "-" "AUCUN"
attendu "1240 civil, destination du texte → aucun renvoi" \
    "1240?code=civil" "-" "AUCUN"

echo
echo "▸ Ce que la déduction ne sait pas faire, elle ne l'invente pas"
# 🔑 L122-14 → L1232-2 est une recodification AVEC réécriture : les textes
# diffèrent, l'égalité échoue, et c'est le comportement voulu. Ce raccord
# n'existe que dans la table de concordance DILA, absente du dump. Ce cas est
# ici pour que la limite reste visible et qu'on ne croie pas la couverture
# complète.
attendu "L122-14 travail, abrogé et réécrit ailleurs → aucun renvoi deviné" \
    "L122-14?code=travail" "-" "AUCUN"

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — tous les cas conformes."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
