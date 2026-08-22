#!/bin/bash
# Garde-fou — le déploiement d'une instance SelfAct.
#
# 🔑 Les trois défauts que ce banc cherche ont tous été mesurés ailleurs, sur le
# script jumeau de SelfJustice : un gabarit qui survit à la substitution, une
# copie qui n'en fait aucune, et un catalogue de poste reposé sur celui que la
# machine vient de moissonner. Aucun ne casse quoi que ce soit — dans les trois
# cas le site répond 200, et c'est bien le problème.
#
# Usage : bash deploy/selfact/tests/test_deploy.sh
# Sortie : 0 si toutes les propriétés tiennent.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$ICI/../deploy.sh"
[ -f "$SCRIPT" ] || { echo "deploy.sh introuvable" >&2; exit 1; }

BAC="$(mktemp -d)"
trap 'rm -rf "$BAC"' EXIT

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# Un faux module, reconstruit avant chaque cas : un banc qui garde l'état du cas
# précédent finit par mesurer autre chose que ce qu'il croit.
monter() {
    rm -rf "$BAC/module" "$BAC/site" "$BAC/api"
    mkdir -p "$BAC/module/deploy/selfact" "$BAC/module/self-right/selfact/site" \
             "$BAC/module/self-right/selfact/api/data" "$BAC/site" "$BAC/api"
    cp "$SCRIPT" "$BAC/module/deploy/selfact/deploy.sh"

    cat > "$BAC/module/self-right/selfact/site/act.php" <<'PHP'
<?php // page
<a href="https://justice.example.org/act/api/catalog">catalogue</a>
<a href="https://justice.example.org/act/docs">docs</a>
PHP
    echo '<a href="https://justice.example.org/act">SelfAct</a>' \
        > "$BAC/module/self-right/selfact/site/act-docs.html"
    # 🔑 Le repli d'exécution, qui doit traverser intact.
    echo "<?php \$h = \$_SERVER['HTTP_HOST'] ?? 'your-instance.example';" \
        > "$BAC/module/self-right/selfact/api/find.php"
    echo "<?php // service" > "$BAC/module/self-right/selfact/api/catalog.php"
    echo "# directives" > "$BAC/module/self-right/selfact/api/directives.md"
    echo '{"situations":[]}' > "$BAC/module/self-right/selfact/api/data/situations.json"
    echo '{"gabarits":{}}'   > "$BAC/module/self-right/selfact/api/data/gabarits.json"
}

lancer() { bash "$BAC/module/deploy/selfact/deploy.sh" "$@" 2>&1; }

echo "▸ Le gabarit devient le domaine de l'instance"
monter
sortie=$(lancer act.test.invalid "$BAC/site" "$BAC/api"); code=$?
if [ "$code" -eq 0 ] && grep -q "act.test.invalid" "$BAC/site/act.php" \
   && ! grep -q "justice.example.org" "$BAC/site/act.php"; then
    ok "les pages nomment l'instance, plus le gabarit"
else
    nok "substitution incomplète (code $code) : $(grep -c 'justice.example.org' "$BAC/site/act.php" 2>/dev/null) gabarit(s) survivant(s)"
fi

echo
echo "▸ Le repli d'exécution n'est pas un gabarit"
# 🔑 « your-instance.example » est ce que find.php affiche quand HTTP_HOST manque.
# Le substituer graverait le nom de l'instance dans un défaut d'exécution.
if grep -q "your-instance.example" "$BAC/api/find.php"; then
    ok "« your-instance.example » traverse intact"
else
    nok "le repli d'exécution a été substitué"
fi

echo
echo "▸ Le catalogue moissonné ne se déploie pas"
# 🔑 Douze jours de catalogue perdus le 20/08/2026 parce qu'un transfert de poste
# a reposé la copie versionnée par-dessus celle que le cron venait d'écrire.
monter
echo '{"_meta":{"total":1},"models":[]}' \
    > "$BAC/module/self-right/selfact/api/data/catalog.json"
lancer act.test.invalid "$BAC/site" "$BAC/api" >/dev/null 2>&1
if [ ! -f "$BAC/api/data/catalog.json" ] \
   && [ -f "$BAC/api/data/situations.json" ] && [ -f "$BAC/api/data/gabarits.json" ]; then
    ok "catalog.json reste chez lui, les données curées partent"
else
    nok "catalog.json a été déployé — la copie du poste écrase celle du cron"
fi

echo
echo "▸ Une source sans gabarit arrête le déploiement"
# Un module dont les pages ne nomment plus l'instance signale que le gabarit
# cherché a changé. Rien d'autre ne le dirait : tout répondrait 200.
monter
sed -i 's|justice.example.org|deja.substitue.test|g' \
    "$BAC/module/self-right/selfact/site/act.php" \
    "$BAC/module/self-right/selfact/site/act-docs.html"
sortie=$(lancer act.test.invalid "$BAC/site" "$BAC/api"); code=$?
if [ "$code" -ne 0 ] && printf '%s' "$sortie" | grep -q "aucune occurrence"; then
    ok "zéro substitution → refus (code $code)"
else
    nok "zéro substitution acceptée (code $code)"
fi

echo
echo "▸ Une destination absente est nommée, pas devinée"
monter
sortie=$(lancer act.test.invalid "$BAC/site" "$BAC/nexiste-pas"); code=$?
if [ "$code" -ne 0 ] && printf '%s' "$sortie" | grep -q "destination absente"; then
    ok "destination introuvable → refus"
else
    nok "destination introuvable acceptée (code $code)"
fi

echo
echo "▸ Copier une source sur elle-même est refusé"
# Sans cette garde, la redirection vide le fichier avant que sed ne le lise.
monter
sortie=$(lancer act.test.invalid "$BAC/module/self-right/selfact/site" "$BAC/api"); code=$?
if [ "$code" -ne 0 ] && printf '%s' "$sortie" | grep -q "même répertoire"; then
    ok "source = destination → refus, avant toute écriture"
else
    nok "source = destination acceptée (code $code)"
fi

echo
total=$((reussites + echecs))
if [ "$echecs" -eq 0 ]; then
    echo "OK — $reussites/$total propriétés tiennent."
    exit 0
fi
echo "ÉCHEC — $echecs propriété(s) sur $total." >&2
exit 1
