#!/bin/bash
# Garde-fou — la synchronisation LEGI date le contenu d'après le contenu, et la
# base européenne servie ne cède sa place qu'à une base dont les témoins sont lus.
#
# Le vrai pilote tourne dans un bac : legi.py, l'extraction, build_eu_db.py et
# update_stats.sh y sont des faux qui rendent la situation voulue, ses chemins
# sont redirigés par les variables qu'il lit, et un faux ntfy consigne chaque
# alerte — la méthode de test_update_jade.sh. `chown` est remplacé dans le
# PATH : le banc ne tourne pas en root.
#
# build_eu_db.py est éprouvé en vrai, sur une base jetable : son téléchargement
# est bouchonné pour échouer, et le réseau est fermé.
#
# Usage : bash tests/test_update_legi.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
PILOTE="$ICI/../tools/update_legi.sh"
CONSTRUCTEUR_UE="$ICI/../tools/build_eu_db.py"
for f in "$PILOTE" "$CONSTRUCTEUR_UE"; do
    [ -r "$f" ] || { echo "introuvable : $f" >&2; exit 1; }
done
for outil in sqlite3 flock curl python3; do
    command -v "$outil" >/dev/null || { echo "$outil manquant : le pilote l'appelle" >&2; exit 1; }
done

BAC=$(mktemp -d)
SERVEUR=""
trap '[ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null; rm -rf "$BAC"' EXIT

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# --- L'installation simulée ---------------------------------------------------
LEGI="$BAC/opt"
ETAT="$BAC/etat"
EU_SERVIE="$BAC/db/conventionnalite.sqlite"
mkdir -p "$LEGI/legi.py/venv/bin" "$LEGI/bin" "$LEGI/tarballs" "$BAC/py/legi" \
         "$BAC/db" "$ETAT" "$BAC/stubs" "$BAC/sources"

# L'interpréteur du venv : python3, le faux paquet legi en tête du chemin.
cat > "$LEGI/legi.py/venv/bin/python" <<SH
#!/bin/sh
PYTHONPATH="$BAC/py" exec python3 -B "\$@"
SH
printf '#!/bin/sh\nexit 0\n' > "$BAC/stubs/chown"
printf '#!/bin/sh\nexit 0\n' > "$LEGI/bin/update_stats.sh"
chmod +x "$LEGI/legi.py/venv/bin/python" "$BAC/stubs/chown" "$LEGI/bin/update_stats.sh"

: > "$BAC/py/legi/__init__.py"
: > "$BAC/py/legi/download.py"
# legi.tar2sqlite : écrit FAUX_LAST_UPDATE dans db_meta, ou n'y écrit rien s'il est vide.
cat > "$BAC/py/legi/tar2sqlite.py" <<'PY'
import os, sqlite3, sys
c = sqlite3.connect(sys.argv[-2])
c.execute("CREATE TABLE IF NOT EXISTS db_meta (key TEXT PRIMARY KEY, value TEXT)")
c.execute("DELETE FROM db_meta WHERE key = 'last_update'")
if os.environ["FAUX_LAST_UPDATE"]:
    c.execute("INSERT INTO db_meta VALUES ('last_update', ?)", (os.environ["FAUX_LAST_UPDATE"],))
c.commit()
PY
# Une extraction qui franchit le seuil de l'étape 5.
cat > "$LEGI/bin/extract_selfjustice_db.py" <<'PY'
import sqlite3, sys
c = sqlite3.connect(sys.argv[sys.argv.index("--dest") + 1])
c.execute("DROP TABLE IF EXISTS articles")
c.execute("CREATE TABLE articles (id INTEGER PRIMARY KEY)")
c.execute("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 100000) "
          "INSERT INTO articles SELECT x FROM n")
c.commit()
PY
# build_eu_db.py : ajoute un article à la base qu'on lui donne, l'abîme en code 1,
# inscrit dans `provenance` les seules sources relues (FAUX_RELUES, datées de
# FAUX_RELUE_LE) comme le vrai, note si la base servie a bougé pendant qu'il
# construisait, et rend FAUX_RC.
cat > "$LEGI/bin/build_eu_db.py" <<'PY'
import hashlib, os, sqlite3, sys
c = sqlite3.connect(sys.argv[sys.argv.index("--db") + 1])
c.execute("CREATE TABLE IF NOT EXISTS articles (id TEXT PRIMARY KEY, texte TEXT)")
c.execute("CREATE TABLE IF NOT EXISTS provenance (source TEXT PRIMARY KEY, origine TEXT NOT NULL, "
          "fichier TEXT, depose_le TEXT, empreinte TEXT, construite_le TEXT NOT NULL)")
c.execute("INSERT OR REPLACE INTO articles VALUES ('NOUVEAU', 'construit ce jour')")
for source in filter(None, os.environ["FAUX_RELUES"].split(",")):
    c.execute("INSERT OR REPLACE INTO provenance VALUES (?, 'reseau', NULL, NULL, NULL, ?)",
              (source, os.environ["FAUX_RELUE_LE"]))
# Une source servie depuis sa copie locale est datée du jour sans avoir rien relu.
for source in filter(None, os.environ["FAUX_COPIES"].split(",")):
    c.execute("INSERT OR REPLACE INTO provenance VALUES (?, 'copie_locale', ?, '2026-08-20', "
              "'0123456789abcdef', ?)", (source, source + ".pdf", os.environ["FAUX_RELUE_LE"]))
if os.environ["FAUX_RC"] == "1":
    c.execute("UPDATE articles SET texte = 'un texte pour un autre'")
c.commit()
c.close()
servie = os.environ["FAUX_EU_SERVIE"]
empreinte = hashlib.sha256(open(servie, "rb").read()).hexdigest() if os.path.exists(servie) else ""
with open(os.environ["FAUX_EU_TRACE"], "w") as f:
    f.write("intacte" if empreinte == os.environ["FAUX_EU_EMPREINTE"] else "modifiée")
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

# --- Jouer un passage ---------------------------------------------------------
MOIS=(janvier février mars avril mai juin juillet août septembre octobre novembre décembre)
AUJ_ISO=$(date +%F)
AUJ_FR="$(date +%-d) ${MOIS[$(( $(date +%-m) - 1 ))]} $(date +%Y)"
HIER_ISO=$(date -d yesterday +%F)
HIER_FR="$(date -d yesterday +%-d) ${MOIS[$(( $(date -d yesterday +%-m) - 1 ))]} $(date -d yesterday +%Y)"
# La forme qu'écrit legi.tar2sqlite : la date de la dernière archive appliquée,
# telle que la porte son nom (LEGI_AAAAMMJJ-HHMMSS.tar.gz).
LAST_UPDATE_LEGI="20260814-213449"

empreinte() { [ -f "$1" ] && sha256sum "$1" | cut -d' ' -f1; }
lire()      { cat "$1" 2>/dev/null; }
alertes()   { paste -sd'|' "$BAC/ntfy.log"; }
a_sonne()   { grep -qF "$1" "$BAC/ntfy.log"; }
fini()      { grep -qF "=== Fin mise à jour LEGI ===" "$LEGI/update_legi.log"; }
ids_ue() {
    python3 -c 'import sqlite3, sys
c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
print(",".join(r[0] for r in c.execute("SELECT id FROM articles ORDER BY id")))' "$EU_SERVIE" 2>/dev/null
}
etat_ue() {
    echo "servie [$(ids_ue)] $(lire "$BAC/eu.trace") pendant la construction," \
         "copie $([ -e "$EU_SERVIE.nouveau" ] && echo restée || echo absente)," \
         "eu_last_update « $(lire "$ETAT/eu_last_update.txt") »," \
         "eu_last_sync « $(lire "$ETAT/eu_last_sync.txt") », alertes : $(alertes)"
}

# L'instance d'hier : marqueurs au 1er septembre, base UE servie d'un article,
# ses deux sources relues pour la dernière fois ce jour-là.
preparer() {
    echo "1 septembre 2026" > "$ETAT/legi_last_update.txt"
    echo "1 septembre 2026" > "$ETAT/eu_last_update.txt"
    echo "2026-09-01" > "$ETAT/legi_last_sync.txt"
    echo "2026-09-01" > "$ETAT/eu_last_sync.txt"
    rm -f "$EU_SERVIE" "$EU_SERVIE.nouveau" "$LEGI/update_legi.log" "$BAC/eu.trace"
    python3 - "$EU_SERVIE" <<'PY'
import sqlite3, sys
c = sqlite3.connect(sys.argv[1])
c.execute("CREATE TABLE articles (id TEXT PRIMARY KEY, texte TEXT)")
c.execute("INSERT INTO articles VALUES ('ANCIEN', 'servi hier')")
c.execute("CREATE TABLE provenance (source TEXT PRIMARY KEY, origine TEXT NOT NULL, fichier TEXT, "
          "depose_le TEXT, empreinte TEXT, construite_le TEXT NOT NULL)")
c.executemany("INSERT INTO provenance VALUES (?, ?, ?, ?, ?, '2026-09-01')",
              [("TUE", "reseau", None, None, None),
               ("CEDH", "copie_locale", "CEDH.pdf", "2026-08-20", "0123456789abcdef")])
c.commit()
PY
}

# jouer <db_meta.last_update> <code de build_eu_db.py> [sources relues] [relues le]
#   → code du pilote. Par défaut, les deux sources sont relues ce jour.
jouer() {
    : > "$BAC/ntfy.log"
    FAUX_LAST_UPDATE="$1" FAUX_RC="$2" FAUX_EU_TRACE="$BAC/eu.trace" \
    FAUX_RELUES="${3-TUE,CEDH}" FAUX_RELUE_LE="${4:-$AUJ_ISO}" FAUX_COPIES="${FAUX_COPIES:-}" \
    FAUX_EU_SERVIE="$EU_SERVIE" FAUX_EU_EMPREINTE="$(empreinte "$EU_SERVIE")" \
    SELFJUSTICE_DIR="$LEGI" SELFJUSTICE_DB_DIR="$BAC/db" SELFJUSTICE_VAR_DIR="$ETAT" \
    SELFJUSTICE_NTFY_URL="http://127.0.0.1:$PORT/" SELFJUSTICE_NTFY_TOKEN_FILE="$BAC/token" \
    PATH="$BAC/stubs:$PATH" \
        bash "$PILOTE"
}

# --- Les cas ------------------------------------------------------------------
echo "▸ Étape 7 — une date de contenu illisible ne devient pas la date du jour"
date_illisible() {
    preparer
    jouer "$1" 0; code=$?
    marqueur=$(lire "$ETAT/legi_last_update.txt")
    if [ "$code" -eq 0 ] && [ "$marqueur" = "1 septembre 2026" ] && fini \
       && a_sonne "date du contenu LEGI illisible" && ! a_sonne "base LEGI perimee"; then
        ok "last_update $2 → marqueur inchangé, alerte, passage mené à son terme"
    else
        nok "last_update $2 : code $code, marqueur « $marqueur » (attendu « 1 septembre 2026 »), alertes : $(alertes)"
    fi
}
date_illisible "" "absent de db_meta"
date_illisible "inconnu" "« inconnu »"

echo
echo "▸ Étape 9 — la base européenne se construit sur une copie"
preparer; avant=$(empreinte "$EU_SERVIE")
jouer "$LAST_UPDATE_LEGI" 1; code=$?
if [ "$code" -eq 0 ] && [ "$(empreinte "$EU_SERVIE")" = "$avant" ] && [ ! -e "$EU_SERVIE.nouveau" ] \
   && [ "$(lire "$ETAT/eu_last_update.txt")" = "1 septembre 2026" ] \
   && [ "$(lire "$ETAT/eu_last_sync.txt")" = "2026-09-01" ] && a_sonne "echec de la base UE"; then
    ok "code 1 → base servie intacte, copie supprimée, marqueurs UE inchangés, alerte"
else
    nok "code 1 : code $code, $(etat_ue)"
fi

preparer
jouer "$LAST_UPDATE_LEGI" 2 "TUE"; code=$?
if [ "$code" -eq 0 ] && [ "$(lire "$BAC/eu.trace")" = "intacte" ] && [ "$(ids_ue)" = "ANCIEN,NOUVEAU" ] \
   && [ ! -e "$EU_SERVIE.nouveau" ] && [ "$(lire "$ETAT/eu_last_update.txt")" = "$AUJ_FR" ] \
   && [ "$(lire "$ETAT/eu_last_sync.txt")" = "$AUJ_ISO" ] && a_sonne "sources UE non rafraichies"; then
    ok "code 2, une source relue ce jour → base servie intacte pendant la construction, copie substituée, marqueurs UE à ce jour, alerte"
else
    nok "code 2, une source relue ce jour : code $code, $(etat_ue)"
fi

# Réseau coupé : toutes les sources en échec, témoins conformes sur les articles
# d'avant. La base se substitue, mais rien n'a été relu.
preparer
jouer "$LAST_UPDATE_LEGI" 2 ""; code=$?
if [ "$code" -eq 0 ] && [ "$(ids_ue)" = "ANCIEN,NOUVEAU" ] \
   && [ "$(lire "$ETAT/eu_last_update.txt")" = "1 septembre 2026" ] \
   && [ "$(lire "$ETAT/eu_last_sync.txt")" = "$AUJ_ISO" ] && a_sonne "sources UE non rafraichies"; then
    ok "code 2, aucune source relue → eu_last_update garde la date de provenance, eu_last_sync à ce jour, alerte"
else
    nok "code 2, aucune source relue : code $code, $(etat_ue)"
fi

# Le réseau est coupé, mais CEDH sort de sa copie locale, datée du jour.
preparer
FAUX_COPIES="CEDH" jouer "$LAST_UPDATE_LEGI" 2 ""; code=$?
if [ "$code" -eq 0 ] && [ "$(ids_ue)" = "ANCIEN,NOUVEAU" ] \
   && [ "$(lire "$ETAT/eu_last_update.txt")" = "1 septembre 2026" ] \
   && [ "$(lire "$ETAT/eu_last_sync.txt")" = "$AUJ_ISO" ]; then
    ok "code 2, seule une copie locale reconstruite ce jour → eu_last_update garde la date des sources relues par le réseau"
else
    nok "code 2, copie locale du jour, réseau d'avant : code $code, $(etat_ue)"
fi

# La date relue est celle que porte `provenance`, pas l'horloge du passage : la
# construction est datée ici de la veille pour que les deux se distinguent.
preparer
jouer "$LAST_UPDATE_LEGI" 0 "TUE,CEDH" "$HIER_ISO"; code=$?
if [ "$code" -eq 0 ] && [ "$(lire "$BAC/eu.trace")" = "intacte" ] && [ "$(ids_ue)" = "ANCIEN,NOUVEAU" ] \
   && [ ! -e "$EU_SERVIE.nouveau" ] && [ "$(lire "$ETAT/eu_last_update.txt")" = "$HIER_FR" ] \
   && [ "$(lire "$ETAT/eu_last_sync.txt")" = "$AUJ_ISO" ] && ! grep -qE "base UE|sources UE" "$BAC/ntfy.log"; then
    ok "code 0 → base servie intacte pendant la construction, puis remplacée ; eu_last_update = date relue ($HIER_FR), eu_last_sync à ce jour"
else
    nok "code 0 (relue le $HIER_ISO) : code $code, $(etat_ue)"
fi

# Une base servie que sqlite3 ne sait pas copier : la reconstruction n'a pas lieu,
# et le passage va quand même jusqu'à ses marqueurs LEGI.
preparer; head -c 4096 /dev/zero | tr '\0' 'x' > "$EU_SERVIE"; avant=$(empreinte "$EU_SERVIE")
jouer "$LAST_UPDATE_LEGI" 0; code=$?
if [ "$code" -eq 0 ] && [ "$(empreinte "$EU_SERVIE")" = "$avant" ] && [ ! -e "$EU_SERVIE.nouveau" ] \
   && [ "$(lire "$ETAT/eu_last_update.txt")" = "1 septembre 2026" ] \
   && [ "$(lire "$ETAT/eu_last_sync.txt")" = "2026-09-01" ] \
   && [ "$(lire "$ETAT/legi_last_sync.txt")" = "$AUJ_ISO" ] && fini && a_sonne "base UE non copiee" \
   && ! a_sonne "sync LEGI en echec"; then
    ok "copie de la base servie impossible → base servie intacte, marqueurs UE inchangés, passage mené au bout, alerte"
else
    nok "copie impossible : code $code, legi_last_sync « $(lire "$ETAT/legi_last_sync.txt") », $(etat_ue)"
fi

echo
echo "▸ build_eu_db.py — les témoins se lisent avant de rendre le code 2"
# Une base qui porte tous les témoins, l'un faussé à la demande ; la source TUE échoue au
# téléchargement, sans copie locale de repli. Le code rendu s'imprime seul.
cat > "$BAC/eu.py" <<'PY'
import contextlib, importlib.util, io, os, socket, sys
script, base, cas, sources, sortie = sys.argv[1:6]
os.environ["SELFJUSTICE_SOURCES_DIR"] = sources
def hors_reseau(*a, **k):
    raise OSError("banc hors réseau")
socket.socket.connect = hors_reseau
socket.create_connection = hors_reseau
socket.getaddrinfo = hors_reseau
spec = importlib.util.spec_from_file_location("build_eu_db", script)
b = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b)
b.fetch_url = lambda *a, **k: b""
conn = b.create_db(base)
for (source, num), attendu in b.TEMOINS.items():
    texte = f"Article {num}. {attendu}."
    if cas == "faux" and (source, num) == ("TUE", "50"):
        texte = "Protocole sur le rôle des parlements nationaux dans l'Union européenne."
    conn.execute("INSERT INTO articles (id, source, num, texte) VALUES (?, ?, ?, ?)",
                 (f"{source}-{num}", source, num, texte))
conn.commit()
conn.close()
sys.argv = ["build_eu_db.py", "--db", base, "--only", "TUE"]
flux, code = io.StringIO(), 0
with contextlib.redirect_stdout(flux), contextlib.redirect_stderr(flux):
    try:
        b.main()
    except SystemExit as e:
        code = e.code if isinstance(e.code, int) else 1
open(sortie, "w", encoding="utf-8").write(flux.getvalue())
print(code)
PY
construire_ue() {
    python3 -B "$BAC/eu.py" "$CONSTRUCTEUR_UE" "$BAC/eu_$1.sqlite" "$1" "$BAC/sources" "$BAC/eu_$1.out"
}

code=$(construire_ue conformes)
if [ "$code" = "2" ] && grep -qF "non mise(s) à jour : TUE" "$BAC/eu_conformes.out" \
   && grep -qF "articles témoins conformes" "$BAC/eu_conformes.out"; then
    ok "TUE injoignable, témoins conformes → code 2, rendu après lecture des témoins"
else
    nok "TUE injoignable, témoins conformes : code « $code » (attendu 2), témoins lus : $(grep -c 'témoins conformes' "$BAC/eu_conformes.out")"
fi

code=$(construire_ue faux)
if [ "$code" = "1" ] && grep -qF "TUE art. 50" "$BAC/eu_faux.out"; then
    ok "TUE injoignable, témoin TUE 50 faux → code 1"
else
    nok "TUE injoignable, témoin TUE 50 faux : code « $code » (attendu 1)"
fi

echo
echo "▸ Témoins — vrais avant les correctifs comme après : un pilote qui ne date plus rien, ou ne remplace plus rien, ne passe pas"
preparer
jouer "$LAST_UPDATE_LEGI" 0; code=$?
marqueur=$(lire "$ETAT/legi_last_update.txt")
if [ "$code" -eq 0 ] && [ "$marqueur" = "14 août 2026" ] && ! a_sonne "illisible" \
   && a_sonne "base LEGI perimee"; then
    ok "last_update $LAST_UPDATE_LEGI, forme écrite par legi.tar2sqlite → marqueur « 14 août 2026 », retard signalé"
else
    nok "last_update $LAST_UPDATE_LEGI, forme écrite par legi.tar2sqlite : code $code, marqueur « $marqueur » (attendu « 14 août 2026 »), alertes : $(alertes)"
fi

preparer; rm -f "$EU_SERVIE"
jouer "$LAST_UPDATE_LEGI" 0; code=$?
if [ "$code" -eq 0 ] && [ "$(ids_ue)" = "NOUVEAU" ] && [ "$(lire "$ETAT/eu_last_sync.txt")" = "$AUJ_ISO" ]; then
    ok "première installation, sans base UE servie → base créée"
else
    nok "première installation : code $code, $(etat_ue)"
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — la date du contenu vient du contenu, et la base UE servie n'est remplacée que saine."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
