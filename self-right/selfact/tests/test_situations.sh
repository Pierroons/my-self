#!/bin/bash
# Garde-fou — situations.json, le fichier que rien ne regardait.
#
# 🔑 Le pont gabarit → catalogue est tenu par test_gabarits_officiels.sh.
# situations.json, lui, n'a que ce banc : les validations de `structure.yml`
# (`php -l`, `python3 -m ast`, `bash -n`) ne chargent aucun JSON. Sans lui, une
# virgule en trop passe l'intégration continue et casse `find.php` en 500
# `data_malformed` sur la machine qui sert.
#
# ⚠️ Deux défauts de plus sont silencieux par construction :
#   · un `status` mal orthographié n'échoue pas, il retombe sur 99 dans la table
#     de tri de find.php et l'acte part en dernier sans que personne le voie ;
#   · `art_applicable` accepte deux formes — une chaîne libre héritée, et la
#     liste d'objets que le module MCP sait lire (`a.get('reference')`). Seule
#     la liste est lue par lui, et une liste dont les entrées n'ont pas
#     `reference` s'affiche vide, sans erreur.
#
# 🔑 La table des statuts n'est PAS recopiée ici : elle est extraite de
# find.php, qui est la seule source. Une table écrite dans le banc aurait
# divergé du code le jour où l'un des deux bouge — c'est exactement le défaut
# que ce dépôt a déjà payé sur les intitulés de gabarits, écrits à deux endroits.
#
# Usage : bash tests/test_situations.sh
# Sortie : 0 si les propriétés tiennent.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
ACT="$(cd "$ICI/../api" && pwd)"
SITUATIONS="$ACT/data/situations.json"
FIND="$ACT/find.php"

command -v python3 >/dev/null || { echo "python3 introuvable" >&2; exit 1; }
[ -r "$SITUATIONS" ] || { echo "ÉCHEC : $SITUATIONS illisible" >&2; exit 1; }
[ -r "$FIND" ]       || { echo "ÉCHEC : $FIND illisible" >&2; exit 1; }

reussites=0
echecs=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# Le contrôle, isolé dans une fonction : le banc l'applique au fichier réel,
# puis à des copies volontairement fautives pour vérifier qu'il rougit.
controler() {
    python3 - "$1" "$FIND" <<'PYEOF'
import json, re, sys

fichier, find_php = sys.argv[1], sys.argv[2]

try:
    with open(fichier, encoding="utf-8") as f:
        data = json.load(f)
except (json.JSONDecodeError, UnicodeDecodeError) as e:
    print(f"JSON_INVALIDE|{e}")
    raise SystemExit(0)

# Les statuts connus viennent de la table de tri de find.php, source unique.
# ⚠️ Ancrée sur le bloc `$priority`, pas sur la forme « 'x' => n, » : celle-ci
# ramassait AUSSI la table de tri par nature de suggestFromCatalog
# (modele_lettre, formulaire, teleservice), et le banc acceptait alors
# « formulaire » comme statut d'acte.
source = open(find_php, encoding="utf-8").read()
bloc = re.search(r"\$priority\s*=\s*\[(.*?)\];", source, re.S)
statuts = set(re.findall(r"'([a-z_]+)'", bloc.group(1))) if bloc else set()
if not statuts:
    print("TABLE_INTROUVABLE|le bloc $priority de find.php n'a pas été reconnu")
    raise SystemExit(0)

problemes = []
situations = data.get("situations")
if not isinstance(situations, dict) or not situations:
    print("RACINE_ABSENTE|la clé 'situations' manque ou est vide")
    raise SystemExit(0)

for slug, s in situations.items():
    if not s.get("label"):
        problemes.append(f"{slug} : pas de label")
    actes = s.get("acts")
    if not actes:
        problemes.append(f"{slug} : aucun acte")
        continue
    for i, a in enumerate(actes):
        st = a.get("status")
        if st not in statuts:
            problemes.append(
                f"{slug}[{i}] : status « {st} » inconnu de find.php "
                f"— l'acte serait trié en dernier sans rien dire"
            )
        if not a.get("url"):
            problemes.append(f"{slug}[{i}] : pas d'url")

    arts = s.get("art_applicable")
    if isinstance(arts, list):
        for i, a in enumerate(arts):
            if not isinstance(a, dict) or not a.get("reference"):
                problemes.append(
                    f"{slug} : art_applicable[{i}] sans « reference » "
                    f"— le module MCP l'afficherait vide"
                )

meta = data.get("_meta", {})
if meta.get("last_update") != meta.get("last_review"):
    problemes.append(
        f"_meta : last_update ({meta.get('last_update')}) et last_review "
        f"({meta.get('last_review')}) divergent — une curation a deux dates"
    )

print("OK" if not problemes else "PROBLEMES|" + " ; ".join(problemes))
PYEOF
}

echo "▸ situations.json tient ses invariants"
verdict="$(controler "$SITUATIONS")"
case "$verdict" in
    OK) ok "JSON valide, statuts connus, articles nommés, dates cohérentes" ;;
    *)  nok "${verdict#*|}" ;;
esac

# 🔑 Les épreuves qui suivent donnent leur sens au contrôle ci-dessus : on lui
# soumet des copies volontairement fautives (AGENTS.md, § Contrôles outillés).
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo
echo "▸ Un JSON cassé ne passe pas"
sed 's/"situations": {/"situations": {,/' "$SITUATIONS" > "$TMP/casse.json"
[[ "$(controler "$TMP/casse.json")" == JSON_INVALIDE* ]] \
    && ok "une virgule en trop est vue" \
    || nok "un JSON invalide est passé — c'est le défaut qui casse la prod en 500"

echo
echo "▸ Un statut inventé ne passe pas"
python3 - "$SITUATIONS" "$TMP/statut.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
premier = next(iter(d["situations"].values()))
premier["acts"][0]["status"] = "offical"          # faute de frappe plausible
json.dump(d, open(sys.argv[2], "w", encoding="utf-8"), ensure_ascii=False)
PY
[[ "$(controler "$TMP/statut.json")" == PROBLEMES*offical* ]] \
    && ok "« offical » est nommé, pas trié en dernier en silence" \
    || nok "un statut inconnu n'a rien déclenché"

echo
echo "▸ Un article sans référence ne passe pas"
python3 - "$SITUATIONS" "$TMP/article.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
for s in d["situations"].values():
    if isinstance(s.get("art_applicable"), list):
        s["art_applicable"][0].pop("reference", None)
        break
else:
    raise SystemExit("aucune situation ne porte art_applicable en liste")
json.dump(d, open(sys.argv[2], "w", encoding="utf-8"), ensure_ascii=False)
PY
[[ "$(controler "$TMP/article.json")" == PROBLEMES*reference* ]] \
    && ok "un article en liste sans « reference » est vu" \
    || nok "un article muet n'a rien déclenché"

echo
echo "▸ Un type de ressource n'est pas un statut"
# 🔑 « formulaire » appartient à la table de tri par NATURE (`$rang`, find.php),
# pas à celle des statuts (`$priority`). Un banc qui l'accepte lit la mauvaise
# table.
python3 - "$SITUATIONS" "$TMP/nature.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
next(iter(d["situations"].values()))["acts"][0]["status"] = "formulaire"
json.dump(d, open(sys.argv[2], "w", encoding="utf-8"), ensure_ascii=False)
PY
[[ "$(controler "$TMP/nature.json")" == PROBLEMES*formulaire* ]] \
    && ok "« formulaire » est refusé comme statut" \
    || nok "« formulaire » accepté — le banc lit la table de tri par nature"

echo
totalp=$((reussites + echecs))
if [ "$echecs" -eq 0 ]; then
    echo "OK — $reussites/$totalp propriétés tiennent."
    exit 0
fi
echo "ÉCHEC — $echecs propriété(s) sur $totalp." >&2
exit 1
