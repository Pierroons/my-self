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
# Depuis, `verifier` sonde l'amont dès que rien ne correspond localement à une
# date annoncée : une moisson n'est pas exhaustive, et l'amont sert des
# juridictions que l'index ne moissonne pas. Ce sont les issues de cette sonde
# qui se contrôlent ici — dont l'échec réseau, qui doit laisser la réserve
# prudente plutôt que de rendre une page blanche.
#
# Elle ne part pas là où elle ne tranche rien : Judilibre ne porte pas l'ordre
# administratif. Et un fonds clos ne borne pas l'index.
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
API_PID=""; AMONT_PID=""; BRIS_PID=""
trap '[ -n "$API_PID" ] && kill "$API_PID" 2>/dev/null
      [ -n "$AMONT_PID" ] && kill "$AMONT_PID" 2>/dev/null
      [ -n "$BRIS_PID" ] && kill "$BRIS_PID" 2>/dev/null
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
  ('aaaa000000000002','21-11.222','2026-07-31','cc','soc',NULL,'f','b','rejet','ECLI:B','ar','2026-08-01',0),
  -- Une date à venir, non marquée suspecte : une erreur de l'amont, qui ne doit
  -- pas faire la fin de la couverture.
  ('aaaa0000000000ff','99-99.999','2099-01-01','cc','soc',NULL,'f','b','rejet','ECLI:F','ar','2099-01-02',0),
  -- Deux HOMONYMES de la décision que le faux amont porte au 2026-08-12 : même
  -- numéro normalisé, d'autres cours, d'autres dates. Un rôle général n'est
  -- unique qu'au sein d'une cour, et c'est le cas de terrain — mesuré sur la
  -- production le 22/08/2026, où « 23/03077 » en comptait huit.
  ('aaaa000000000003','23/03077','2025-04-10','ca','soc','ca_montpellier','f','','other','ECLI:D','ar','2025-04-11',0),
  ('aaaa000000000004','23/03077','2025-02-06','ca','soc','ca_toulouse','f','','other','ECLI:E','ar','2025-02-07',0);
INSERT INTO numeros VALUES ('2200111','aaaa000000000001'), ('2111222','aaaa000000000002'),
  ('2303077','aaaa000000000003'), ('2303077','aaaa000000000004');
-- Une décision du Conseil d'État, telle que le collecteur JADE l'inscrit.
INSERT INTO decisions VALUES
  ('CETATEXT000000519395','519395','2026-06-09','ce','2','Paris','f','','rejet','ECLI:G','ar','2026-06-10',0);
INSERT INTO numeros VALUES ('519395','CETATEXT000000519395');
-- Un arrêt de cassation et une décision du Conseil d'État plus anciens : sans
-- eux, ces deux fonds s'ouvrent le jour où ils se ferment, et sans juridiction
-- aucune date n'est « couverte ». Ils ne servent qu'à la couverture.
INSERT INTO decisions VALUES
  ('aaaa000000000005','23-10.555','2024-01-17','cc','civ1',NULL,'f','b','rejet','ECLI:H','ar','2024-01-18',0),
  ('CETATEXT000000400001','400001','2024-02-01','ce','5','Paris','f','','rejet','ECLI:I','ar','2024-02-02',0),
  -- Un fonds vivant qui commence AVANT la Cour de cassation : « opposable » se
  -- juge sur le début de la seule Cour de cassation, pas sur celui du premier fonds.
  ('CETATEXT000000300001','300001','2023-06-01','ce','5','Paris','f','','rejet','ECLI:N','ar','2023-06-02',0);
-- Deux fonds CLOS, comme JADE les porte : la Cour de discipline budgétaire et
-- financière s'arrête en 2000, les tribunaux administratifs en 2009.
INSERT INTO decisions VALUES
  ('CETATEXT000000100001','123-456','1998-05-04','cdbf','','Paris','f','','rejet','ECLI:J','ar','1998-05-05',0),
  ('CETATEXT000000100002','124-457','2000-11-20','cdbf','','Paris','f','','rejet','ECLI:K','ar','2000-11-21',0),
  ('CETATEXT000000200001','0501234','2005-03-01','ta','','Lyon','f','','rejet','ECLI:L','ar','2005-03-02',0),
  ('CETATEXT000000200002','0904321','2009-06-30','ta','','Lyon','f','','rejet','ECLI:M','ar','2009-07-01',0);
SQL

# ── Un numéro porté par PLUS de décisions que la limite de la route ─────────
# Des homonymes récents, plus nombreux que la limite, et la décision cherchée,
# plus ancienne que tous. Mesuré sur la production le 27/09/2026 : « 23/00039 »,
# 61 décisions. La limite est lue dans api.php : écrite ici, elle pourrait
# grandir là-bas et ce cas passerait sans plus rien éprouver.
LIMITE=$(sed -nE 's/^const LIMITE_DECISIONS = ([0-9]+);.*/\1/p' "$RACINE/api/api.php")
[ -n "$LIMITE" ] || { echo "LIMITE_DECISIONS introuvable dans api.php" >&2; exit 1; }
NB_HOMONYMES=$((LIMITE + 9))
CIBLE=$(date -d "2026-01-01 - $((NB_HOMONYMES + 30)) days" +%F)
SANS_DECISION=$(date -d "$CIBLE + 1 day" +%F)
sqlite3 "$DB" "
WITH RECURSIVE s(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM s WHERE i < $NB_HOMONYMES)
INSERT INTO decisions SELECT printf('dddd%012d', i), '24/00042',
  date('2026-01-01', printf('-%d days', i)), 'ca', 'soc', printf('ca_cour%04d', i),
  'f', '', 'other', '', 'ar', '2026-01-02', 0 FROM s;
INSERT INTO decisions VALUES
  ('dddd999999999999','24/00042','$CIBLE','ca','soc','ca_cible','f','','rejet','ECLI:F','ar','$CIBLE',0);
INSERT INTO numeros SELECT '2400042', id FROM decisions WHERE number = '24/00042';"


# ── Le faux amont ───────────────────────────────────────────────────────────
# Il connaît deux décisions : un arrêt d'appel du 2026-08-12, hors de portée de
# l'index, et un jugement de tribunal judiciaire, fonds que l'index ne moissonne
# à aucune date. Le reste rend une liste vide : c'est ce qui distingue « l'amont ne l'a pas » de
# « l'amont n'a pas répondu », et ces deux-là n'autorisent pas la même phrase.
# 🔑 Chaque appel reçu s'inscrit au journal : « aucune sonde n'est partie » se
# lit là, pas dans la réponse de la route.
mkdir -p "$TMP/amont"
JOURNAL="$TMP/amont/journal"
au_journal() { awk -F'\t' -v c="$1" -v v="$2" '$c == v { n++ } END { print n + 0 }' "$JOURNAL"; }
cat > "$TMP/amont/router.php" <<'PHP'
<?php
header('Content-Type: application/json');
file_put_contents(__DIR__ . '/journal', ($_GET['query'] ?? '') . "\t"
    . ($_GET['jurisdiction'] ?? '') . "\t" . ($_GET['date_start'] ?? '') . "\n", FILE_APPEND);
// Comme l'amont réel, mesuré le 10/09/2026 : hors de l'ordre judiciaire, un 400.
if (isset($_GET['jurisdiction'])
    && !in_array($_GET['jurisdiction'], ['cc', 'ca', 'tj', 'tcom'], true)) {
    http_response_code(400);
    echo json_encode(['message' => 'Value of the jurisdiction parameter must be in [cc,ca,tj,tcom]']);
    exit;
}
$connues = [
    '2303077|2026-08-12' => [
        'id' => 'bbbb000000000003', 'number' => '23/03077',
        'numbers' => ['23/03077'], 'decision_date' => '2026-08-12',
        'jurisdiction' => 'ca', 'chamber' => 'soc', 'location' => 'ca_versailles',
        'publication' => ['non'], 'solution' => 'infirmation', 'ecli' => 'ECLI:C',
        'type' => 'arret',
    ],
    '2104567|2026-03-05' => [
        'id' => 'bbbb000000000005', 'number' => '21/04567',
        'numbers' => ['21/04567'], 'decision_date' => '2026-03-05',
        'jurisdiction' => 'tj', 'chamber' => 'civ', 'location' => 'tj_lyon',
        'publication' => ['non'], 'solution' => 'condamnation', 'ecli' => 'ECLI:N',
        'type' => 'jugement',
    ],
    // Un arrêt d'appel à une date que l'index couvre, et que sa moisson a manqué.
    '2205555|2026-03-10' => [
        'id' => 'bbbb000000000006', 'number' => '22/05555',
        'numbers' => ['22/05555'], 'decision_date' => '2026-03-10',
        'jurisdiction' => 'ca', 'chamber' => 'soc', 'location' => 'ca_lyon',
        'publication' => ['non'], 'solution' => 'confirmation', 'ecli' => 'ECLI:O',
        'type' => 'arret',
    ],
];
$norm = preg_replace('/[^A-Za-z0-9]/', '', $_GET['query'] ?? '');
// Un numéro qui le fait tomber : la panne à une date que l'index couvre.
if ($norm === '5555555') {
    http_response_code(500);
    exit;
}
// Un numéro noyé : l'amont en annonce plus qu'une page n'en rend, et la page
// ne le porte pas.
if ($norm === '4444444') {
    echo json_encode(['total' => 75, 'results' => [[
        'id' => 'bbbb000000000007', 'number' => '19/00001', 'numbers' => ['19/00001'],
        'decision_date' => $_GET['date_start'] ?? '', 'jurisdiction' => 'ca',
        'location' => 'ca_paris', 'solution' => 'rejet', 'type' => 'arret',
    ]]]);
    exit;
}
$d = $connues[$norm . '|' . ($_GET['date_start'] ?? '')] ?? null;
if ($d && ($_GET['jurisdiction'] ?? 'cc') === $d['jurisdiction']) {
    echo json_encode(['total' => 1, 'results' => [$d]]);
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
        # Un port que le système donne libre. Tiré au hasard, il pouvait être déjà pris
        # (Tor écoute sur 9050, ou un autre serveur du même banc), et la sonde de
        # démarrage acceptait alors celui qui répondait à sa place.
        PORT=$(python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')
        # Déjà servi par un autre : on en prend un autre, avant de lancer le nôtre.
        if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then continue; fi
        "$1" "$PORT" && return 0
    done
    return 1
}

lancer_amont() {
    php -S "127.0.0.1:$1" "$TMP/amont/router.php" >/dev/null 2>&1 &
    AMONT_PID=$!
    for _ in $(seq 40); do
        kill -0 "$AMONT_PID" 2>/dev/null && curl -sf -o /dev/null "http://127.0.0.1:$1/?query=x" && return 0
        sleep 0.1
    done
    kill "$AMONT_PID" 2>/dev/null; AMONT_PID=""; return 1
}
essayer_trois_ports lancer_amont || { echo "faux amont non démarré" >&2; exit 1; }
PORT_AMONT="$PORT"

# Le jour est figé : « opposable » dépend de l'âge de la décision au jour de la
# requête, et un banc qui lirait l'horloge changerait de verdict avec elle.
JOUR_FIGE=2026-11-04
lancer_api() {
    SELFJUSTICE_JOUR="$JOUR_FIGE" \
    SELFJUSTICE_JURIS_DB="$DB" \
    SELFJUSTICE_JUDILIBRE_BASE="$AMONT_URL" \
    SELFJUSTICE_JUDILIBRE_KEY="factice" \
        php -S "127.0.0.1:$1" -t "$RACINE/api" "$RACINE/api/api.php" >/dev/null 2>&1 &
    API_PID=$!
    for _ in $(seq 40); do
        kill -0 "$API_PID" 2>/dev/null && curl -sf -o /dev/null "http://127.0.0.1:$1/api/jurisprudence/verifier/22%2F00111" && return 0
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
# `~motif` : contient ; `!motif` : ne contient pas ; la clé `http` lit le code.
controle() {
    local libelle="$1" requete="$2"; shift 2
    local code; code=$(curl -s -o "$TMP/corps" -w '%{http_code}' "$BASE/$requete")
    local corps; corps=$(<"$TMP/corps")
    local souci=""
    for paire in "$@"; do
        local cle="${paire%%:*}" attendu="${paire#*:}"
        local obtenu
        if [ "$cle" = "http" ]; then
            obtenu="$code"
        else
            obtenu=$(printf '%s' "$corps" | python3 -c '
import json, sys
d = json.load(sys.stdin)
v = d.get(sys.argv[1])
print("" if v is None else str(v))' "$cle" 2>/dev/null)
        fi
        case "$attendu" in
            "~"*) printf '%s' "$obtenu" | grep -qF "${attendu#\~}" \
                    || souci="$souci [$cle ne contient pas « ${attendu#\~} »]" ;;
            "!"*) printf '%s' "$obtenu" | grep -qF "${attendu#!}" \
                    && souci="$souci [$cle contient « ${attendu#!} »]" ;;
            *)    [ "$obtenu" = "$attendu" ] \
                    || souci="$souci [$cle = « $obtenu », attendu « $attendu »]" ;;
        esac
    done
    [ -z "$souci" ] && ok "$libelle" || nok "$libelle —$souci"
}

echo "▸ Ce que l'index local porte, il le dit sans sortir"
controle "présente localement → trouvée, sans sonde" \
    "22%2F00111?jurisdiction=ca&date=2026-06-10" \
    "etat:trouvee" "source:index local"
controle "absente, sans date → prudence, sans sonde" \
    "99%2F99999?jurisdiction=ca" \
    "etat:absente" "reserve:~ne peut pas être exclue" "reserve:!ne sont pas dans l'index"

echo
echo "▸ Hors plage, la sonde tranche à la place de la prudence"
# 🔑 Le cas mesuré le 21/08 : servie par un outil, niée par l'autre.
controle "hors plage, l'amont l'a → trouvée, et le retard est nommé" \
    "23%2F03077?jurisdiction=ca&date=2026-08-12" \
    "etat:trouvee" "source:amont Judilibre" \
    "reserve:~c'est l'index qui est en retard"
# L'amont ne diffuse qu'une partie des arrêts d'appel : son silence les borne.
controle "« ca » hors plage, l'amont ne l'a pas → bornée à ce qu'il diffuse" \
    "23%2F04444?jurisdiction=ca&date=2026-08-12" \
    "etat:absente" "reserve:~ne vaut que dans les limites" "reserve:!opposable"

echo
echo "▸ Un numéro porté par plusieurs cours"
# 🔑 Le piège mesuré sur la production le 22/08/2026, et que la première version
# de la sonde laissait passer : l'index porte bien « 23/03077 », mais à
# Montpellier en 2025 et à Toulouse en 2024 — jamais à Versailles le 12 août
# 2026. La sonde ne partait que si l'index ne rendait RIEN ; ici il rendait huit
# décisions, aucune n'étant la bonne, et la route concluait « trouvée ».
# Répondre « ce numéro existe » à qui demande « cette décision existe-t-elle »
# est la forme la plus crédible du faux positif.
controle "homonymes locaux + amont l'a → c'est la datée qui est rendue" \
    "23%2F03077?jurisdiction=ca&date=2026-08-12" \
    "etat:trouvee" "source:amont Judilibre" "count:1"
corps=$(curl -s "$BASE/23%2F03077?jurisdiction=ca&date=2026-08-12")
cour=$(printf '%s' "$corps" | python3 -c 'import json,sys; print(json.load(sys.stdin)["decisions"][0]["cour"])')
[ "$cour" = "ca_versailles" ] \
    && ok "la décision rendue est celle de Versailles, pas un homonyme" \
    || nok "la décision rendue vient de « $cour »"
nb=$(printf '%s' "$corps" | python3 -c 'import json,sys; print(len(json.load(sys.stdin).get("homonymes") or []))')
[ "$nb" = "2" ] \
    && ok "les 2 homonymes sont rendus à part, pas mélangés" \
    || nok "$nb homonyme(s) séparé(s), attendu 2"

# 🔑 Et sans amont pour trancher : le numéro existe, la décision non. La réponse
# doit être « absente », pas « trouvée — 2 décisions ». Date DANS la plage : la
# sonde part, et l'amont ne l'a pas non plus.
controle "homonymes locaux, date couverte, aucune correspondance → absente" \
    "23%2F03077?jurisdiction=ca&date=2025-06-01" \
    "etat:absente" "reserve:~aucune n'est datée du 2025-06-01"

echo
echo "▸ La justice administrative que l'index porte"
# 🔑 L'index sert le Conseil d'État par JADE depuis le 10/09/2026 ; la route
# disait encore qu'il « n'y figurera jamais », et la recherche filtrée sur « ce »
# rendait le 400 de l'amont comme une panne.
controle "une décision du Conseil d'État en base est trouvée" \
    "519395?jurisdiction=ce&date=2026-06-09" \
    "etat:trouvee" "source:index local" "count:1"
controle "absente, le périmètre vient de l'index et renvoie à sa couverture" \
    "999999?jurisdiction=ce&date=2026-06-09" \
    "etat:absente" "reserve:~juridiction par juridiction, sont dans « couverture »"
corps=$(curl -s "$BASE/999999?jurisdiction=ce&date=2026-06-09")
printf '%s' "$corps" | grep -q 'ArianeWeb' \
    && nok "la réserve renvoie encore vers ArianeWeb alors que l'index sert le Conseil d'État" \
    || ok "la réserve ne renvoie plus vers ArianeWeb"
RECHERCHE="http://127.0.0.1:$PORT_API/api/jurisprudence/search"
code=$(curl -s -o "$TMP/recherche.json" -w '%{http_code}' "$RECHERCHE?q=exces+de+pouvoir&jurisdiction=ce")
[ "$code" = "400" ] && grep -q 'JADE' "$TMP/recherche.json" && grep -q 'verifier' "$TMP/recherche.json" \
    && ok "la recherche filtrée sur « ce » refuse, et dit où chercher" \
    || nok "la recherche filtrée sur « ce » rend $code : $(head -c 160 "$TMP/recherche.json")"

echo
echo "▸ Plus d'homonymes que la limite de la route"
# 🔑 La limite s'appliquait avant le filtre par date : derrière plus
# d'homonymes récents qu'elle n'en retient, une décision présente sortait
# « absente », et le MCP disait au modèle de ne pas la présenter comme existante.
controle "la datée est retrouvée derrière $NB_HOMONYMES homonymes plus récents (limite $LIMITE)" \
    "24%2F00042?jurisdiction=ca&date=$CIBLE" \
    "etat:trouvee" "source:index local" "count:1"
cour=$(curl -s "$BASE/24%2F00042?jurisdiction=ca&date=$CIBLE" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin)["decisions"]; print(d[0]["cour"] if d else "")')
[ "$cour" = "ca_cible" ] \
    && ok "la décision rendue est la datée, pas un homonyme" \
    || nok "la décision rendue vient de « $cour »"
# Le nombre d'homonymes que lit le modèle vient du total, pas de la liste bornée.
controle "trouvée → les $NB_HOMONYMES autres sont comptées, pas les seules affichées" \
    "24%2F00042?jurisdiction=ca&date=$CIBLE" \
    "reserve:~Les $NB_HOMONYMES autre(s) décision(s)"
controle "absente → le numéro est porté par $((NB_HOMONYMES + 1)) décisions, pas $LIMITE" \
    "24%2F00042?jurisdiction=ca&date=$SANS_DECISION" \
    "etat:absente" "reserve:~$((NB_HOMONYMES + 1)) décision(s) le portent"

echo
echo "▸ Sans date, la liste dit jusqu'où elle a cherché"
# 🔑 Mesuré en production le 22/08/2026 : « 24/00627 » sans date rendait 26
# homonymes, le plus récent de mai, sans un mot sur le fait que l'index s'arrête
# en août et qu'une décision postérieure ne pouvait pas y figurer. Trouver
# n'exonère pas de dire jusqu'où on a cherché.
controle "trouvée sans date → la borne de l'index est nommée" \
    "23%2F03077?jurisdiction=ca" \
    "etat:trouvee" "source:index local" "reserve:~arrêté au"
controle "trouvée sans date → l'outil dit comment aller plus loin" \
    "23%2F03077?jurisdiction=ca" \
    "reserve:~Rappelle l'outil avec la date"
# Avec une date, cette réserve-là n'a plus lieu d'être : la sonde a fait son
# travail et la réponse porte sa propre explication.
controle "trouvée AVEC date → pas de réserve de borne, celle de l'amont suffit" \
    "23%2F03077?jurisdiction=ca&date=2026-08-12" \
    "etat:trouvee" "reserve:~c'est l'index qui est en retard"

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
echo "▸ Une juridiction inconnue est une faute de saisie, pas une panne"
# 🔑 Rendue en 503 « indéterminée », une faute de frappe se lisait comme une
# vérification impossible. Seul un code que la table des libellés connaît
# relève de « hors de l'index ».
controle "« zz » → 400, et les valeurs admises sont nommées" \
    "22%2F00111?jurisdiction=zz" \
    "http:400" "error:~« zz » inconnue. Valeurs acceptées" "etat:"
controle "« cass » pour « cc » → le même refus" \
    "21-11.222?jurisdiction=cass" \
    "http:400" "error:~« cass » inconnue"
controle "« caa », connue mais absente de l'index → reste indéterminée" \
    "22%2F00111?jurisdiction=caa" \
    "http:503" "etat:indeterminee" "raison:~hors de l'index"

echo
echo "▸ Une absence dans un fonds administratif ne prouve rien"
# 🔑 JADE ne porte qu'une sélection : « si elle existait, elle y figurerait »
# y est faux, même à une date couverte.
controle "« ce », date couverte → la sélection JADE est dite" \
    "999999?jurisdiction=ce&date=2026-06-09" \
    "etat:absente" "reserve:~Le fonds JADE ne porte qu'une sélection" "reserve:!elle y figurerait"
controle "« ta », date couverte → la même réserve" \
    "0612345?jurisdiction=ta&date=2007-05-15" \
    "etat:absente" "reserve:~Le fonds JADE ne porte qu'une sélection" "reserve:!elle y figurerait"
# Sans juridiction, la sonde ne voit que la Cour de cassation : la réserve ne
# conclut que pour elle, et dit le reste.
for cas in "77%2F77777?date=2026-03-02|date couverte" "88%2F88888?date=2026-07-15|au-delà de la borne"; do
    controle "sans juridiction, ${cas#*|}, l'amont ne l'a pas → opposable pour la seule Cour de cassation" \
        "${cas%%|*}" \
        "etat:absente" "reserve:~pour la Cour de cassation : pour elle, l'absence est opposable." \
        "reserve:~Cours d'appel : l'index local ne porte que ce que Judilibre en diffuse" \
        "reserve:~ne les interroge que si la juridiction est précisée" \
        "reserve:~le fonds JADE ne porte qu'une sélection" "reserve:!elle y figurerait"
done

echo
echo "▸ Un fonds clos ne borne pas l'index"
# 🔑 Sans juridiction, la borne était la plus ancienne des fins — la CDBF, en
# 2000 —, et la réserve annonçait un index arrêté cette année-là. Pour « ta »,
# Judilibre ne tranche rien : aucune sonde ne part.
controle "sans juridiction → la borne nommée est celle des fonds vivants" \
    "77%2F77777?date=2026-03-02" \
    "etat:absente" "reserve:~arrêté au 2026-06-09" "reserve:!2000-11-20"
controle "« ta », date au-delà de son fonds clos → rien ne tranche, et c'est dit" \
    "0612345?jurisdiction=ta&date=2025-03-04" \
    "etat:absente" "reserve:~aucune base amont ne la tranche ici" "reserve:!ne l'a pas pu"
# Le témoin : les sondes des sections précédentes. Sans elles au journal, son
# silence ne prouverait rien.
temoin=$(au_journal 1 "23/03077")
vers_ta=$(au_journal 2 "ta")
[ "$temoin" -gt 0 ] && [ "$vers_ta" -eq 0 ] \
    && ok "aucune sonde amont pour « ta » ($temoin sonde(s) témoin au journal)" \
    || nok "sondes amont pour « ta » : $vers_ta (témoin : $temoin)"

echo
echo "▸ Les tribunaux judiciaires se vérifient par leur nom, auprès de l'amont"
# 🔑 L'index ne moissonne ni les tribunaux judiciaires ni ceux de commerce, que
# Judilibre sert. Nommés, ils étaient refusés en 503 « hors de l'index » ; sans
# juridiction, la sonde ne voit que la Cour de cassation.
controle "jugement de TJ, « tj », date couverte → retrouvé par l'amont" \
    "21%2F04567?jurisdiction=tj&date=2026-03-05" \
    "etat:trouvee" "source:amont Judilibre" "count:1"
# L'index n'est pas en retard, il ne moissonne pas ce fonds.
controle "jugement de TJ retrouvé → le périmètre de l'index est nommé, pas un retard" \
    "21%2F04567?jurisdiction=tj&date=2026-03-05" \
    "reserve:~ne moissonne que" "reserve:!en retard"
controle "même jugement, sans juridiction → non retrouvé, et la réserve dit pourquoi" \
    "21%2F04567?date=2026-03-05" \
    "etat:absente" "reserve:~ne les interroge que si la juridiction est précisée" \
    "reserve:!Tribunaux judiciaires, tribunaux de commerce : l'absence"
# Sans date, aucune source n'a été consultée : « absente » serait une absence
# que personne n'a vérifiée.
controle "« tj » sans date → indéterminé, et la raison le dit" \
    "21%2F04567?jurisdiction=tj" \
    "http:503" "etat:indeterminee" "raison:~n'est interrogée qu'avec la date de la décision"
juri=$(curl -s "$BASE/21%2F04567?jurisdiction=tj&date=2026-03-05" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin)["decisions"]; print(d[0]["juridiction"] if d else "")')
[ "$juri" = "tj" ] \
    && ok "la décision rendue est le jugement du TJ" \
    || nok "la décision rendue vient de « $juri »"
vers_tj=$(au_journal 1 "21/04567")
couverte=$(au_journal 1 "77/77777")
[ "$vers_tj" -gt 0 ] && [ "$couverte" -gt 0 ] \
    && ok "les deux sondes sont au journal de l'amont" \
    || nok "sondes au journal : 21/04567 → $vers_tj, 77/77777 → $couverte"
# 🔑 La sonde part aussi là où sa réponse ne prouve rien : avant le début de
# l'index, une absence reste muette quoi que l'amont rende. Et sa panne, à une
# date couverte, ne se dit pas « postérieure à l'arrêt ».
controle "sans juridiction, date avant le premier fonds de l'index, amont vide → ne prouve rien" \
    "66%2F66666?date=1980-06-01" \
    "etat:absente" "reserve:~précède le début de l'index" "reserve:~ne prouve rien" \
    "reserve:!opposable"
controle "sans juridiction, date couverte, amont en panne → la couverture est dite, la panne aussi" \
    "55%2F55555?date=2026-03-02" \
    "etat:absente" "reserve:~qui couvre cette date" "reserve:~probable sans être établie" \
    "reserve:~HTTP 500" "reserve:!postérieure à l'arrêt"

echo
echo "▸ « Opposable » se juge sur la Cour de cassation seule"
# Le Conseil d'État de ce banc commence le 2023-06-01, la Cour de cassation le
# 2024-01-17 : entre les deux, la sonde ne voit que la seconde, et n'en prouve rien.
controle "sans juridiction, date avant le début de la Cour de cassation, amont vide → ne prouve rien pour elle" \
    "33%2F33333?date=2023-09-01" \
    "etat:absente" "reserve:~précède le début de l'index pour la Cour de cassation" \
    "reserve:!opposable"
# Une décision de moins de soixante jours peut ne pas être encore publiée.
controle "sans juridiction, date de moins de 60 jours, amont vide → pas encore opposable" \
    "33%2F33333?date=2026-10-01" \
    "etat:absente" "reserve:~n'est pas encore opposable" "reserve:!elle est opposable" \
    "reserve:!pour elle, l'absence est opposable"
controle "« cc », date de moins de 60 jours, amont vide → pas encore opposable" \
    "33%2F33333?jurisdiction=cc&date=2026-10-01" \
    "etat:absente" "reserve:~n'est pas encore opposable" "reserve:!elle est opposable"
# Une panne de l'amont à une date où le dernier fonds ouvert n'a pas commencé :
# l'index ne « couvre » pas cette date.
controle "sans juridiction, amont en panne, date avant le début du dernier fonds → pas « couverte »" \
    "55%2F55555?date=2024-06-01" \
    "etat:absente" "reserve:~dont tous les fonds ne commencent pas avant cette date" \
    "reserve:!qui couvre cette date" "reserve:~HTTP 500"

echo
echo "▸ À une date couverte, l'index ne conclut pas seul"
# 🔑 Une moisson n'est pas exhaustive : une décision publiée tard lui échappe.
# Rien ne correspond localement à la date annoncée → l'amont est interrogé, même
# pour « cc » ou « ca », même dans la plage.
controle "« ca », absente, date DANS la plage, l'amont ne l'a pas → bornée à ce qu'il diffuse" \
    "99%2F99999?jurisdiction=ca&date=2026-06-10" \
    "etat:absente" "reserve:~ne vaut que dans les limites" "reserve:!opposable" \
    "reserve:!elle y figurerait"
[ "$(au_journal 1 "99/99999")" -gt 0 ] \
    && ok "la sonde est au journal de l'amont" \
    || nok "aucune sonde amont pour 99/99999"
controle "« ca », date couverte, manquée par la moisson → l'index est en retard" \
    "22%2F05555?jurisdiction=ca&date=2026-03-10" \
    "etat:trouvee" "source:amont Judilibre" "reserve:~c'est l'index qui est en retard"
controle "même arrêt, sans juridiction → la sonde ne voit que la Cour de cassation, et le dit" \
    "22%2F05555?date=2026-03-10" \
    "etat:absente" "reserve:~Cours d'appel : l'index local ne porte que ce que Judilibre en diffuse" \
    "reserve:!Cours d'appel : l'absence est opposable"
controle "« ca », date couverte, amont en panne → probable, pas établie" \
    "55%2F55555?jurisdiction=ca&date=2026-03-02" \
    "etat:absente" "reserve:~l'absence y est probable sans être établie" \
    "reserve:~HTTP 500" "reserve:!elle y figurerait" \
    "reserve:!Cour de cassation, cours d'appel" "reserve:!ribunaux judiciaires"
controle "l'amont en annonce plus qu'une page n'en rend → rien ne s'établit" \
    "44%2F44444?jurisdiction=ca&date=2026-08-20" \
    "etat:absente" "reserve:~qu'une page n'en montre" "reserve:!opposable" \
    "reserve:!n'a pas répondu"
controle "sans date ni juridiction → les fonds que seul l'amont sert sont nommés" \
    "99%2F99999" \
    "etat:absente" \
    "reserve:~tribunaux de commerce ne sont pas dans l'index : avec la juridiction et la date de la décision, la base amont est interrogée"

echo
echo "▸ Une date de recherche mal formée ne part pas"
# 🔑 Transmise à l'amont, elle revenait en liste vide : un argument refusé ne
# doit jamais ressembler à une réponse.
for option in date_start date_end; do
    code=$(curl -s -o "$TMP/recherche.json" -w '%{http_code}' "$RECHERCHE?q=licenciement&$option=12%2F03%2F2024")
    [ "$code" = "400" ] && grep -q 'Format attendu' "$TMP/recherche.json" \
        && ok "$option « 12/03/2024 » → 400, et le format est nommé" \
        || nok "$option « 12/03/2024 » rend $code : $(head -c 160 "$TMP/recherche.json")"
done
# Le format seul ne suffit pas : une date qui n'existe pas est un argument refusé.
code=$(curl -s -o "$TMP/recherche.json" -w '%{http_code}' "$RECHERCHE?q=licenciement&date_start=2026-02-30")
[ "$code" = "400" ] \
    && ok "date_start « 2026-02-30 » → 400 : une date qui n'existe pas" \
    || nok "date_start « 2026-02-30 » rend $code"
code=$(curl -s -o "$TMP/verif.json" -w '%{http_code}' "$BASE/21%2F04567?jurisdiction=tj&date=2026-02-30")
[ "$code" = "400" ] \
    && ok "verifier, date « 2026-02-30 » → 400" \
    || nok "verifier, date « 2026-02-30 » rend $code"
fin_cc=$(curl -s "$BASE/21-11.222?jurisdiction=cc&date=2026-07-31" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)["couverture"]["cc"]["fin"])')
[ "$fin_cc" = "2026-07-31" ] \
    && ok "une décision datée de 2099 ne fait pas la fin de la couverture" \
    || nok "fin de couverture « cc » : $fin_cc"
code=$(curl -s -o "$TMP/recherche.json" -w '%{http_code}' "$RECHERCHE?q=licenciement&date_start=2024-03-12")
[ "$code" = "200" ] && [ "$(au_journal 3 "2024-03-12")" -gt 0 ] \
    && ok "date_start bien formée → transmise à l'amont" \
    || nok "date_start « 2024-03-12 » rend $code : $(head -c 160 "$TMP/recherche.json")"

echo
echo "▸ La juridiction se lit comme dans la recherche"
# 🔑 Blancs retirés, valeur vide ignorée : sans cela, « ca » précédé d'un blanc
# était refusé par un message qui nommait « ca » parmi les valeurs admises.
controle "« ca » précédé d'un blanc → lu « ca »" \
    "22%2F00111?jurisdiction=%20ca&date=2026-06-10" \
    "http:200" "etat:trouvee" "juridiction:ca"
controle "jurisdiction vide → comme absente" \
    "22%2F00111?jurisdiction=&date=2026-06-10" \
    "http:200" "etat:trouvee" "juridiction:"

echo
echo "▸ Un index illisible ne ferme pas la recherche"
# 🔑 `juridictions_servies()` garde un repli si l'index ne s'ouvre pas — à
# condition que l'échec lui revienne : `open_db()` sort du script, et la
# recherche filtrée tombait en 503. Un dossier à la place du fichier fait
# échouer l'ouverture ; un fichier qui n'est pas une base, non — SQLite ne lit
# l'en-tête qu'à la première requête, et ce cas-là passait déjà (mesuré le
# 15/10/2026).
mkdir "$TMP/index-illisible.sqlite"
# Prête dès qu'elle répond, quel que soit le code : sur cet index, `verifier`
# ne rend pas de 200.
lancer_api_bris() {
    SELFJUSTICE_JURIS_DB="$TMP/index-illisible.sqlite" \
    SELFJUSTICE_JUDILIBRE_BASE="$AMONT_URL" \
    SELFJUSTICE_JUDILIBRE_KEY="factice" \
        php -S "127.0.0.1:$1" -t "$RACINE/api" "$RACINE/api/api.php" >/dev/null 2>&1 &
    BRIS_PID=$!
    for _ in $(seq 40); do
        kill -0 "$BRIS_PID" 2>/dev/null && curl -s -o /dev/null "http://127.0.0.1:$1/api/status" && return 0
        sleep 0.1
    done
    kill "$BRIS_PID" 2>/dev/null; BRIS_PID=""; return 1
}
essayer_trois_ports lancer_api_bris || { echo "api sur index illisible non démarrée" >&2; exit 1; }
code=$(curl -s -o "$TMP/bris.json" -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/jurisprudence/search?q=licenciement&jurisdiction=ca")
[ "$code" = "200" ] && ! grep -q "Erreur d'ouverture" "$TMP/bris.json" \
    && ok "recherche filtrée sur « ca » → servie sur le repli" \
    || nok "recherche filtrée sur « ca » rend $code : $(head -c 160 "$TMP/bris.json")"
kill "$BRIS_PID" 2>/dev/null; wait "$BRIS_PID" 2>/dev/null; BRIS_PID=""

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
    "reserve:~ne l'a pas pu"
controle "« tj », amont injoignable → indéterminé : aucune autre source ne le porte" \
    "21%2F04567?jurisdiction=tj&date=2026-03-05" \
    "http:503" "etat:indeterminee" "raison:~ne l'a pas pu"
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
