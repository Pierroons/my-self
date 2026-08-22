#!/bin/bash
# Garde-fou — le pont entre un gabarit maison et les ressources officielles.
#
# 🔑 Le pied de chaque gabarit disait « pour un acte officiel, utilise le modèle
# service-public.fr correspondant » sans jamais dire lequel, alors que le module
# en indexe 1 895. Le rapprochement est désormais curé dans data/gabarits.json.
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
# Six des sept : « document » est le gabarit neutre, il n'a pas de démarche.
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
# 🔑 L'outil MCP annonçait les mêmes quatre champs pour les sept gabarits, dont
# « action demandée » — qui n'existe que dans la mise en demeure. Six documents
# sur sept sont un squelette identique à deux lignes près, et rien ne le disait.
# Mesuré le 22/08/2026 par un contrôle extérieur, en ouvrant les sept.
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
    ok "les sept gabarits déclarent exactement leurs crochets"
else
    nok "écart entre ce qui est annoncé et ce que le document porte :
$ecarts"
fi

# 🔑 Et le contrôle qui donne son sens au précédent : la mise en demeure DOIT
# être la seule à porter « Action demandée ». Sans ce cas, une table qui
# déclarerait les mêmes champs partout passerait le contrôle ci-dessus le jour
# où quelqu'un ajouterait le crochet aux sept documents.
avec=0
for type in mise_en_demeure saisine_conciliateur plainte_simple saisine_defenseur \
            recours_gracieux resiliation document; do
    curl -s "$BASE/draft.php?type=$type" | grep -q "Action demandée" && avec=$((avec + 1))
done
[ "$avec" -eq 1 ] \
    && ok "« Action demandée » n'est dans qu'un seul document sur sept" \
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
totalp=$((reussites + echecs))
if [ "$echecs" -eq 0 ]; then
    echo "OK — $reussites/$totalp propriétés tiennent."
    exit 0
fi
echo "ÉCHEC — $echecs propriété(s) sur $totalp." >&2
exit 1
