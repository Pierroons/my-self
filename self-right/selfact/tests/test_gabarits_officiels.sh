#!/bin/bash
# Garde-fou — le pont entre un gabarit maison et les ressources officielles.
#
# 🔑 Le pied de chaque gabarit disait « pour un acte officiel, utilise le modèle
# service-public.fr correspondant » sans jamais dire lequel, alors que le module
# en indexe plus de 1 800. Le rapprochement est désormais curé dans data/gabarits.json.
#
# ⚠️ Curé, donc faillible autrement : un identifiant que le catalogue ne connaît
# plus donne un renvoi vers rien. C'est le défaut que ce banc cherche en
# premier, parce qu'il est silencieux — une ressource morte ne se distingue pas
# d'une ressource absente si personne ne compte.
#
# Le second point est la double table : les intitulés étaient écrits dans
# draft.php ET dans le module MCP, et divergeaient déjà d'une entrée.
#
# Usage : bash tests/test_gabarits_officiels.sh
# Sortie : 0 si les propriétés tiennent.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
ACT="$(cd "$ICI/../api" && pwd)"
command -v php >/dev/null || { echo "php introuvable" >&2; exit 1; }

# 🔑 **Le banc lit un extrait versionné, pas le catalogue de la machine.**
# Le catalogue complet vit dans l'état de l'instance depuis le 21/08/2026 et
# `.gitignore` l'écarte du dépôt. Ce banc passait donc au vert sur les postes où
# une copie non versionnée traînait, et rouge en intégration continue : le
# 22/08 à 19:00, `catalogue non lu — les renvois ne prouvent rien`. Un banc dont
# le résultat dépend de ce qui n'est PAS dans le dépôt ne mesure pas le dépôt.
#
# L'extrait est filtré depuis le catalogue réel par les identifiants que les
# gabarits citent (`gen_catalog_fixture.py`) : un identifiant inventé n'y entre
# pas, faute d'exister en amont. Ce que l'extrait ne voit plus — une ressource
# qui meurt côté service-public — revient à `sanity_fraicheur_catalogue.py`, qui
# interroge le vrai catalogue.
FIXTURE="$ICI/catalog-fixture.json"
if [ ! -r "$FIXTURE" ]; then
    echo "ÉCHEC : $FIXTURE absent — régénérer avec tests/gen_catalog_fixture.py" >&2
    exit 1
fi
cites=$(grep -oE '\bR[0-9]{3,6}\b' "$ACT/data/gabarits.json" | sort -u | wc -l)
portes=$(python3 -c 'import json,sys; print(len(json.load(open(sys.argv[1]))["models"]))' "$FIXTURE")
if [ "$portes" -lt "$cites" ]; then
    echo "ÉCHEC : l'extrait porte $portes ressources pour $cites citées — régénérer." >&2
    exit 1
fi
export SELFACT_CATALOG="$FIXTURE"

SERVEUR=""; BASE=""
trap '[ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null' EXIT
for _ in 1 2 3; do
    port=$(( 8300 + RANDOM % 900 ))
    php -S "127.0.0.1:$port" -t "$ACT" >/dev/null 2>&1 &
    candidat=$!
    for _ in $(seq 40); do
        curl -sf -o /dev/null "http://127.0.0.1:$port/gabarits.php" && break
        sleep 0.1
    done
    if curl -sf -o /dev/null "http://127.0.0.1:$port/gabarits.php"; then
        SERVEUR=$candidat; BASE="http://127.0.0.1:$port"; break
    fi
    kill "$candidat" 2>/dev/null
done
[ -n "$BASE" ] || { echo "le serveur de test n'a pas démarré" >&2; exit 1; }

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

echo "▸ Chaque renvoi curé pointe vers une ressource qui existe"
# 🔑 La route nomme sous `inconnus` les identifiants absents du catalogue. Zéro
# est la seule valeur acceptable : un renvoi juridique vers rien vaut moins que
# pas de renvoi du tout.
perdus=$(curl -s "$BASE/gabarits.php" | python3 -c '
import json, sys
d = json.load(sys.stdin)
manque = {c: g["inconnus"] for c, g in d["gabarits"].items() if g.get("inconnus")}
print(json.dumps(manque, ensure_ascii=False) if manque else "")')
[ -z "$perdus" ] && ok "aucun renvoi orphelin" || nok "renvois vers rien : $perdus"

# Le catalogue doit avoir été lu : sans lui, TOUS les renvois seraient inconnus
# et la ligne ci-dessus rougirait — mais si la route le lisait vide sans le
# dire, elle rendrait « aucune ressource » pour tout le monde, en silence.
lu=$(curl -s "$BASE/gabarits.php" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("catalogue_lu"))')
[ "$lu" = "True" ] && ok "le catalogue a bien été lu" || nok "catalogue non lu — les renvois ne prouvent rien"

echo
echo "▸ Les démarches qui ont un équivalent officiel le nomment"
total=$(curl -s "$BASE/gabarits.php" | python3 -c '
import json, sys
g = json.load(sys.stdin)["gabarits"]
avec = sum(1 for x in g.values() if x["officiels"])
print(f"{avec}/{len(g)}")')
avec="${total%%/*}"
# Une borne, pas un compte : les gabarits neutres — « document », et tout gabarit
# dont le sujet n'a aucune démarche officielle — n'en portent aucune.
[ "$avec" -ge 6 ] && ok "$total gabarits portent au moins une ressource officielle" \
                  || nok "$total seulement — le pont ne sert presque rien"

echo
echo "▸ Une seule table d'intitulés"
# 🔑 draft.php refuse un type inconnu en nommant ceux qu'il accepte : c'est là
# qu'on lit SA liste. Elle doit être celle de la route, sans quoi un gabarit
# proposé par le MCP serait refusé par le service qui le sert.
liste_draft=$(curl -s "$BASE/draft.php?type=zzz_inexistant" | python3 -c '
import json,sys; print(",".join(sorted(json.load(sys.stdin)["acceptes"])))')
liste_route=$(curl -s "$BASE/gabarits.php" | python3 -c '
import json,sys; print(",".join(sorted(json.load(sys.stdin)["gabarits"])))')
[ "$liste_draft" = "$liste_route" ] \
    && ok "draft.php et /gabarits servent la même liste" \
    || nok "listes divergentes — draft: $liste_draft / route: $liste_route"

echo
echo "▸ Un gabarit inconnu est refusé des deux côtés"
for cible in "gabarits.php?type=zzz_inexistant" "draft.php?type=zzz_inexistant"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/$cible")
    [ "$code" = "400" ] || [ "$code" = "404" ] \
        && ok "${cible%%\?*} → $code" \
        || nok "${cible%%\?*} → $code, la valeur a été acceptée"
done

echo
echo "▸ Les champs annoncés sont ceux que le document porte"
# 🔑 L'outil MCP annonçait les mêmes quatre champs pour tous les gabarits, dont
# « action demandée » — qui n'existe que dans la mise en demeure. Presque tous les
# documents sont un squelette identique à deux lignes près, et rien ne le disait.
# Mesuré le 22/08/2026 par un contrôle extérieur, en les ouvrant un à un.
#
# Ce cas confronte la table au document RÉEL : il relève les crochets que
# `draft.php` rend, et vérifie que la déclaration ne promet ni plus ni moins.
# Une table peut mentir ; elle ne peut pas mentir longtemps.
# ⚠️ Toute la comparaison se fait en Python : `grep -oE` sur un intervalle
# accentué (« À-ÿ ») échoue en UTF-8 — « Fin d'intervalle invalide » — et rendait
# une liste VIDE, donc un écart maximal et faux. `comm`, lui, exige deux tris de
# la même collation, que Python et le shell ne partagent pas. Deux outils, deux
# pièges, aucun bruit : le cas rougissait pour une raison qui n'était pas la
# sienne.
ecarts=$(python3 - "$BASE" <<'PYEOF'
import json, re, sys, urllib.request

base = sys.argv[1]
def lire(chemin):
    with urllib.request.urlopen(base + chemin, timeout=20) as r:
        return r.read().decode("utf-8", "replace")

types = list(json.loads(lire("/gabarits.php"))["gabarits"])
crochet = re.compile(r"\[[^\[\]]{3,40}\]")
ecarts = []
for t in types:
    reels = {c for c in crochet.findall(lire(f"/draft.php?type={t}"))}
    g = json.loads(lire(f"/gabarits.php?type={t}"))["gabarits"][t]
    declares = set(crochet.findall(" ".join(g.get("champs") or [])))
    manque, invente = sorted(reels - declares), sorted(declares - reels)
    if manque or invente:
        ecarts.append(f"    {t} — non annoncé : {manque or '—'} · "
                      f"annoncé mais absent : {invente or '—'}")
print("\n".join(ecarts))
PYEOF
)
if [ -z "$ecarts" ]; then
    ok "chaque gabarit déclare exactement ses crochets"
else
    nok "écart entre ce qui est annoncé et ce que le document porte :
$ecarts"
fi

# 🔑 Et le contrôle qui donne son sens au précédent : la mise en demeure DOIT
# être la seule à porter « Action demandée ». Sans ce cas, une table qui
# déclarerait les mêmes champs partout passerait le contrôle ci-dessus le jour
# où quelqu'un ajouterait le crochet à tous les documents.
# ⚠️ La liste vient de la route, pas d'une énumération écrite ici : un huitième
# gabarit portant « Action demandée » ne serait jamais examiné, et le contrôle
# dont ce commentaire dit qu'il « donne son sens au précédent » resterait vert.
avec=0
for type in $(curl -s "$BASE/gabarits.php" | python3 -c '
import json,sys; print(" ".join(json.load(sys.stdin)["gabarits"]))'); do
    curl -s "$BASE/draft.php?type=$type" | grep -q "Action demandée" && avec=$((avec + 1))
done
[ "$avec" -eq 1 ] \
    && ok "« Action demandée » n'est que dans un seul document" \
    || nok "« Action demandée » dans $avec document(s) — la déclaration doit suivre"

echo
echo "▸ Un renvoi mort ne se tait pas"
# 🔑 On en fabrique un : sans cette épreuve, le premier contrôle serait vert
# parce que la table est juste aujourd'hui, pas parce que le code sait détecter.
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"; [ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null' EXIT
cp "$ACT/data/gabarits.json" "$TMP/sauvegarde.json"
python3 - "$ACT/data/gabarits.json" <<'PY'
import json, sys
p = sys.argv[1]
d = json.load(open(p))
d["gabarits"]["plainte_simple"]["officiels"].append({"id": "R00000", "quand": "ressource disparue"})
json.dump(d, open(p, "w"), ensure_ascii=False, indent=2)
PY
signale=$(curl -s "$BASE/gabarits.php?type=plainte_simple" | python3 -c '
import json,sys; print(",".join(json.load(sys.stdin)["gabarits"]["plainte_simple"].get("inconnus") or []))')
cp "$TMP/sauvegarde.json" "$ACT/data/gabarits.json"
[ "$signale" = "R00000" ] \
    && ok "un identifiant absent du catalogue est nommé, pas ignoré" \
    || nok "un identifiant absent n'a rien déclenché (signalé : « $signale »)"

echo
echo "▸ L'avertissement suit la classe du gabarit, pas l'humeur du demandeur"

# 🔑 La mention « NON OFFICIEL » ne couvre que le cas C — les documents qui
# imitent la forme d'un acte juridique. Elle était posée sur tous, y compris sur
# un courrier amiable qui n'imite rien : un avertissement qui se répète là où il
# n'a pas lieu d'être finit par ne plus être lu là où il compte.
#
# ⚠️ Ce que ce banc vérifie aussi, et qui compte autant : le bloc `.disclaimer`
# du pied reste sur TOUS les documents. Le texte de la loi 71-1130 est soudé
# dans la chaîne des mentions ; les conditionner en retire deux porteurs, et
# c'est le pied qui doit rattraper. Un cas B sans mention ET sans disclaimer
# serait un document nu.
ecarts=""
for cle in $(python3 -c '
import json; print(" ".join(json.load(open("'"$ACT"'/data/gabarits.json"))["gabarits"]))'); do
    cas=$(python3 -c '
import json,sys; print(json.load(open("'"$ACT"'/data/gabarits.json"))["gabarits"][sys.argv[1]]["cas"])' "$cle")
    page=$(curl -s "$BASE/draft.php?type=$cle")
    vues=$(printf '%s' "$page" | grep -c 'class="mention"')
    pied=$(printf '%s' "$page" | grep -c 'class="disclaimer"')
    attendu=0; [ "$cas" = "C" ] && attendu=2
    [ "$vues" = "$attendu" ] || ecarts="$ecarts $cle(cas $cas: $vues mention(s), $attendu attendue(s))"
    [ "$pied" = "1" ]        || ecarts="$ecarts $cle(pied absent)"
done
[ -z "$ecarts" ] \
    && ok "les 8 gabarits marquent selon leur classe, et gardent tous leur pied" \
    || nok "l'avertissement ne suit pas la classe :$ecarts"

# Contre-témoin dans les deux sens : sans lui, un rendu qui ne marquerait
# jamais rien — ou qui marquerait tout — passerait la boucle ci-dessus dès que
# la table des classes lui donnerait raison par hasard.
cp "$ACT/data/gabarits.json" "$TMP/avant-bascule.json"
python3 - "$ACT/data/gabarits.json" <<'BASCULE'
import json, sys
p = sys.argv[1]
d = json.load(open(p))
d["gabarits"]["mise_en_demeure"]["cas"] = "B"     # un C déclassé
d["gabarits"]["recours_gracieux"]["cas"] = "C"    # un B promu
json.dump(d, open(p, "w"), ensure_ascii=False, indent=2)
BASCULE
bascule_c=$(curl -s "$BASE/draft.php?type=mise_en_demeure"  | grep -c 'class="mention"')
bascule_b=$(curl -s "$BASE/draft.php?type=recours_gracieux" | grep -c 'class="mention"')
cp "$TMP/avant-bascule.json" "$ACT/data/gabarits.json"
[ "$bascule_c" = "0" ] \
    && ok "un C déclassé en B perd sa mention — le rendu lit bien la table" \
    || nok "un C déclassé garde $bascule_c mention(s) : le marquage ne vient pas de la classe"
[ "$bascule_b" = "2" ] \
    && ok "un B promu en C la gagne" \
    || nok "un B promu n'a que $bascule_b mention(s) : le rendu ne marque jamais rien"

echo
echo "▸ Le titre de la section des faits suit le gabarit"

# 🔑 Sept gabarits sur huit mettent bien des faits dans le champ `faits` ; les
# directives post-mortem y font lister des comptes, et leur `champs` l'annonce
# — « comptes et contexte ». Le document titrait pourtant « Rappel des faits »
# pour les huit, un vocabulaire de litige sur un document qui n'en est pas un.
#
# ⚠️ `gabarits.php` expose `champs` au modèle : l'écart entre ce que le gabarit
# annonce et ce que le document imprime traversait l'API avant de se voir à
# l'impression. Un banc qui ne regarde que la page ne l'aurait pas montré.
ecarts=""
for cle in $(python3 -c '
import json; print(" ".join(json.load(open("'"$ACT"'/data/gabarits.json"))["gabarits"]))'); do
    attendu=$(python3 -c '
import json,sys
g = json.load(open("'"$ACT"'/data/gabarits.json"))["gabarits"][sys.argv[1]]
print(g.get("titre_faits", "Rappel des faits"))' "$cle")
    vu=$(curl -s "$BASE/draft.php?type=$cle" | grep -c "<h3>$attendu</h3>")
    [ "$vu" = "1" ] || ecarts="$ecarts $cle(titre « $attendu » absent)"
done
[ -z "$ecarts" ] \
    && ok "les 8 gabarits titrent la section des faits comme ils l'annoncent" \
    || nok "le titre ne suit pas le gabarit :$ecarts"

# Contre-témoin dans les deux sens : un titre resté en dur passerait la boucle
# ci-dessus aussi longtemps qu'aucun gabarit ne réclame autre chose que le
# défaut — c'est-à-dire exactement l'état d'avant le correctif.
cp "$ACT/data/gabarits.json" "$TMP/avant-titre.json"
python3 - "$ACT/data/gabarits.json" <<'TITRE'
import json, sys
d = json.load(open(sys.argv[1]))
d["gabarits"]["mise_en_demeure"]["titre_faits"] = "Temoin du banc"   # un défaut remplacé
d["gabarits"]["directives_donnees_post_mortem"].pop("titre_faits", None)   # une clé retirée
json.dump(d, open(sys.argv[1], "w"), ensure_ascii=False, indent=2)
TITRE
titre_pose=$(curl -s "$BASE/draft.php?type=mise_en_demeure" | grep -c '<h3>Temoin du banc</h3>')
titre_ote=$(curl -s "$BASE/draft.php?type=directives_donnees_post_mortem" | grep -c '<h3>Rappel des faits</h3>')
cp "$TMP/avant-titre.json" "$ACT/data/gabarits.json"
[ "$titre_pose" = "1" ] \
    && ok "un titre posé dans le gabarit sort dans le document" \
    || nok "le titre posé n'apparaît pas : il est encore en dur"
[ "$titre_ote" = "1" ] \
    && ok "une clé retirée retombe sur « Rappel des faits »" \
    || nok "sans clé, le titre par défaut ne revient pas"

echo
echo "▸ L'amorce de saisie suit le gabarit, comme le titre"

# 🔑 Le titre corrigé et l'amorce laissée en dur, le document se contredisait sur
# deux lignes qui se suivent : « Comptes et contexte » au-dessus d'un
# « [Chronologie des faits] ». Le contrôle des crochets plus haut ne pouvait pas
# le voir — les deux moitiés de `champs` divergeaient, mais son crochet à lui
# correspondait. Relevé le 03/09/2026 en ouvrant le PDF, pas en lisant le code.
ecarts=""
for cle in $(python3 -c '
import json; print(" ".join(json.load(open("'"$ACT"'/data/gabarits.json"))["gabarits"]))'); do
    attendu=$(python3 -c '
import json,sys
g = json.load(open("'"$ACT"'/data/gabarits.json"))["gabarits"][sys.argv[1]]
print(g.get("placeholder_faits", "[Chronologie des faits]"))' "$cle")
    vu=$(curl -s "$BASE/draft.php?type=$cle" | grep -cF "$attendu")
    [ "$vu" -ge 1 ] || ecarts="$ecarts $cle(amorce « $attendu » absente)"
done
[ -z "$ecarts" ] \
    && ok "les 8 gabarits amorcent la saisie des faits comme ils l'annoncent" \
    || nok "l'amorce ne suit pas le gabarit :$ecarts"

# 🔑 Et le contrôle qui l'accompagne : titre et amorce doivent parler du même
# champ. Le gabarit qui déclare l'un sans l'autre est précisément l'état d'où
# vient le défaut, et rien dans le rendu ne le trahirait.
depareilles=$(python3 -c '
import json
g = json.load(open("'"$ACT"'/data/gabarits.json"))["gabarits"]
print(" ".join(c for c, v in g.items()
               if ("titre_faits" in v) != ("placeholder_faits" in v)))')
[ -z "$depareilles" ] \
    && ok "aucun gabarit ne déclare le titre sans son amorce, ni l'inverse" \
    || nok "titre et amorce dépareillés : $depareilles"

# Contre-témoin dans les deux sens, comme pour le titre. Le retrait est
# tolérant à l'absence : un `del` sur une clé déjà partie interromprait le
# script AVANT l'écriture, et le contre-témoin rougirait en accusant le code
# d'être en dur alors qu'il n'a rien été posé du tout.
cp "$ACT/data/gabarits.json" "$TMP/avant-amorce.json"
python3 - "$ACT/data/gabarits.json" <<'AMORCE'
import json, sys
d = json.load(open(sys.argv[1]))
d["gabarits"]["mise_en_demeure"]["placeholder_faits"] = "[Temoin du banc]"   # un défaut remplacé
d["gabarits"]["directives_donnees_post_mortem"].pop("placeholder_faits", None)  # une clé retirée
json.dump(d, open(sys.argv[1], "w"), ensure_ascii=False, indent=2)
AMORCE
amorce_posee=$(curl -s "$BASE/draft.php?type=mise_en_demeure" | grep -cF '[Temoin du banc]')
amorce_otee=$(curl -s "$BASE/draft.php?type=directives_donnees_post_mortem" \
    | grep -cF '[Chronologie des faits]')
cp "$TMP/avant-amorce.json" "$ACT/data/gabarits.json"
[ "$amorce_posee" -ge 1 ] \
    && ok "une amorce posée dans le gabarit sort dans le document" \
    || nok "l'amorce posée n'apparaît pas : elle est encore en dur"
[ "$amorce_otee" -ge 1 ] \
    && ok "une clé retirée retombe sur « [Chronologie des faits] »" \
    || nok "sans clé, l'amorce par défaut ne revient pas"

echo
totalp=$((reussites + echecs))
if [ "$echecs" -eq 0 ]; then
    echo "OK — $reussites/$totalp propriétés tiennent."
    exit 0
fi
echo "ÉCHEC — $echecs propriété(s) sur $totalp." >&2
exit 1
