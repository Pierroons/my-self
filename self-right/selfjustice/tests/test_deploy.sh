#!/usr/bin/env bash
# Contrôle de deploy.sh — quatre cas, dont deux qui doivent échouer.
#
# ⚠️ Les pages d'accueil sont en .php depuis le 19/08/2026 : les compteurs du
# corpus sont rendus côté serveur. Ce test ne copiait que les .html, donc il
# éprouvait le déploiement sur un module amputé de ses deux pages principales.
#
# 🔑 Les deux cas d'échec sont le cœur du test. Un script de déploiement qui
# marche se vérifie tout seul, la première fois qu'on s'en sert ; un script qui
# échoue en silence ne se voit jamais. Les cas C et D sont donc les seuls dont
# l'absence coûterait quelque chose.
#
# Le cas B rejoue le déplacement vers un dossier `deploy/` commun. Il échouait
# avant le 17/08/2026 : la racine était calculée en `dirname "$0"/..`, et le
# script rendait alors « OK — aucun placeholder résiduel » sans avoir rien écrit.
#
# ⚠️ Le cas D protège le garde-fou anti-troncature, posé après l'incident du
# 02/08/2026 où index.html et act.html sont tombés à 0 octet. Ce garde-fou avait
# la particularité de se désarmer tout seul quand la source devenait
# introuvable : sa condition comparait une chaîne vide, donc elle était fausse
# quoi qu'il arrive. Vérifier qu'il refuse ne suffit pas — on contrôle aussi que
# le fichier n'a pas changé de taille.
#
# Usage : bash tests/test_deploy.sh
# Sortie : 0 si les huit cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$ICI/.." && pwd)"
RACINE="$(cd "$MODULE/../.." && pwd)"
# deploy.sh vit sous deploy/selfjustice/ depuis le rangement ; le module ne garde
# que son site et ses tests.
DEPLOY="$RACINE/deploy/selfjustice/deploy.sh"
# 🔑 Ce domaine doit être IMPOSSIBLE à trouver dans les pages sources. Il valait
# « justice.example.org », c'est-à-dire exactement le gabarit qu'elles portent :
# une substitution réussie et une substitution qui n'a rien fait rendaient donc
# le même fichier, et aucun cas ne pouvait les distinguer. Le gabarit cherché
# par deploy.sh a divergé de celui des sources sans qu'un seul test rougisse.
# `.invalid` est réservé par la RFC 2606 : aucune page ne le nommera par hasard.
DOMAINE="justice.instance-de-test.invalid"
GABARIT="justice.example.org"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

[ -f "$DEPLOY" ] || { echo "deploy.sh introuvable : $DEPLOY" >&2; exit 1; }

# ── A — emplacement actuel : doit déployer ──────────────────────────────────
# 🔑 Le critère porte sur `index.php`, la page du module — pas sur « au moins un
# .html ». C'était `act-docs.html` qui satisfaisait ce test, et il a suivi
# SelfAct dans son propre module le 22/08/2026 : le cas mesurait un voisin.
mkdir -p "$TMP/a"
if bash "$DEPLOY" "$DOMAINE" "$TMP/a" >/dev/null 2>&1 && [ -f "$TMP/a/index.php" ]; then
    ok "A — emplacement actuel : déploie"
else
    nok "A — emplacement actuel : aurait dû déployer"
fi

# ── B — déplacé dans deploy/selfjustice/ : doit déployer aussi ──────────────
mkdir -p "$TMP/b/depot/deploy/selfjustice" "$TMP/b/depot/self-right/selfjustice/site" "$TMP/b/dest"
cp "$MODULE"/site/*.php "$TMP/b/depot/self-right/selfjustice/site/" 2>/dev/null
cp "$DEPLOY" "$TMP/b/depot/deploy/selfjustice/"
if bash "$TMP/b/depot/deploy/selfjustice/deploy.sh" "$DOMAINE" "$TMP/b/dest" >/dev/null 2>&1 \
   && [ "$(find "$TMP/b/dest" -name 'index.php' | wc -l)" -gt 0 ]; then
    ok "B — déplacé dans deploy/selfjustice/ : déploie"
else
    nok "B — déplacé dans deploy/selfjustice/ : n'a rien déployé, ou a échoué"
fi

# ── C — source introuvable : doit échouer, sans rien écrire ─────────────────
mkdir -p "$TMP/c/isole" "$TMP/c/dest"
cp "$DEPLOY" "$TMP/c/isole/"
bash "$TMP/c/isole/deploy.sh" "$DOMAINE" "$TMP/c/dest" >/dev/null 2>&1
code=$?
n=$(find "$TMP/c/dest" -type f | wc -l)
if [ "$code" -ne 0 ] && [ "$n" -eq 0 ]; then
    ok "C — source introuvable : refuse (code $code), aucun fichier écrit"
else
    nok "C — source introuvable : code $code, $n fichier(s) écrit(s) — le succès menteur est de retour"
fi

# ── D — source == destination : le garde-fou doit refuser ───────────────────
mkdir -p "$TMP/d/site" "$TMP/d/deploy"
cp "$MODULE"/site/*.html "$MODULE"/site/*.php "$TMP/d/site/" 2>/dev/null
cp "$DEPLOY" "$TMP/d/deploy/"
avant=$(stat -c%s "$TMP/d/site/index.php")
bash "$TMP/d/deploy/deploy.sh" "$DOMAINE" "$TMP/d/site" >/dev/null 2>&1
code=$?
apres=$(stat -c%s "$TMP/d/site/index.php")
if [ "$code" -ne 0 ] && [ "$avant" -eq "$apres" ]; then
    ok "D — source == destination : refuse (code $code), index.php intact ($apres o)"
else
    nok "D — source == destination : code $code, index.php $avant → $apres o"
fi

# ── E — un nom retiré de la source survit à la destination ──────────────────
# Le transfert copie fichier par fichier et n'efface rien. Le renommage du
# 19/08/2026 l'a montré : `act.html` répondait encore en 200 des jours plus
# tard, figé, à côté de l'`act.php` qui le remplace. Le script doit le dire —
# et ne pas le supprimer, parce qu'effacer une page servie au public est une
# décision humaine.
mkdir -p "$TMP/e"
echo "<p>résidu figé</p>" > "$TMP/e/act.html"
echo "<p>vieille page</p>" > "$TMP/e/ancien-module.php"
# 🔑 Et une page de SelfAct, qui n'est PAS un résidu : depuis le 22/08/2026 elle
# est déployée par `deploy/selfact/`, mais une instance peut légitimement servir
# les deux modules depuis la même racine. La signaler « à retirer à la main »
# ferait supprimer un module en suivant le conseil du script.
echo "<?php // page SelfAct" > "$TMP/e/act.php"
sortie_e=$(bash "$DEPLOY" "$DOMAINE" "$TMP/e" 2>&1)
signale=$(printf '%s' "$sortie_e" | grep -c "n'est plus produit par la source")
voisin=$(printf '%s' "$sortie_e" | grep -c "appartient à SelfAct")
survit=0
[ -f "$TMP/e/act.html" ] && [ -f "$TMP/e/ancien-module.php" ] && [ -f "$TMP/e/act.php" ] && survit=1
if [ "$signale" -eq 2 ] && [ "$voisin" -eq 1 ] && [ "$survit" -eq 1 ]; then
    ok "E — 2 résidus signalés, 1 voisin nommé à part, 3 conservés"
else
    nok "E — résidus : $signale signalé(s), voisin : $voisin, conservés=$survit — un orphelin muet, un voisin pris pour un résidu, ou une suppression automatique"
fi

# ── F — une destination sans résidu ne doit rien signaler ───────────────────
# Un avertissement qui se déclenche sur le cas nominal cesse d'être lu.
mkdir -p "$TMP/f"
bruit=$(bash "$DEPLOY" "$DOMAINE" "$TMP/f" 2>&1 | grep -c "n'est plus produit par la source")
if [ "$bruit" -eq 0 ]; then
    ok "F — destination propre : aucun avertissement"
else
    nok "F — destination propre : $bruit avertissement(s) sur ce que le script vient d'écrire"
fi

# ── G — le nom de l'instance est réellement substitué ───────────────────────
# Le seul contrôle qui distingue une page déployée d'une page recopiée.
mkdir -p "$TMP/g"
bash "$DEPLOY" "$DOMAINE" "$TMP/g" >/dev/null 2>&1
# `grep -c` imprime 0 ET sort en 1 quand il ne trouve rien : un `|| echo 0`
# concatène les deux zéros et rend « 0\n0 », que `[` refuse de comparer.
reste=$(grep -c "$GABARIT" "$TMP/g/index.php" 2>/dev/null || true); reste=${reste:-0}
pose=$(grep -c "$DOMAINE" "$TMP/g/index.php" 2>/dev/null || true); pose=${pose:-0}
if [ "$reste" -eq 0 ] && [ "$pose" -gt 0 ]; then
    ok "G — index.php : $pose occurrence(s) portent $DOMAINE, plus aucune $GABARIT"
else
    nok "G — index.php : $pose occurrence(s) du domaine, $reste du gabarit — page recopiée sans substitution"
fi

# ── H — un gabarit que la source ne porte pas : refuser, sans écrire ────────
# Le défaut réel du 21/08/2026 : deploy.sh cherchait « your-instance.example »
# quand les pages portaient « justice.example.org ». Ici on rejoue la divergence
# en changeant la source plutôt que le script.
mkdir -p "$TMP/h/depot/deploy/selfjustice" "$TMP/h/depot/self-right/selfjustice/site" "$TMP/h/dest"
cp "$MODULE"/site/*.html "$MODULE"/site/*.php "$TMP/h/depot/self-right/selfjustice/site/" 2>/dev/null
cp "$DEPLOY" "$TMP/h/depot/deploy/selfjustice/"
sed -i "s|$GABARIT|un-autre-gabarit.invalid|g" "$TMP/h/depot/self-right/selfjustice/site"/*.php \
                                                "$TMP/h/depot/self-right/selfjustice/site"/*.html 2>/dev/null
echo "<p>témoin</p>" > "$TMP/h/dest/index.php"
temoin=$(stat -c%s "$TMP/h/dest/index.php")
bash "$TMP/h/depot/deploy/selfjustice/deploy.sh" "$DOMAINE" "$TMP/h/dest" >/dev/null 2>&1
code=$?
apres=$(stat -c%s "$TMP/h/dest/index.php")
if [ "$code" -ne 0 ]; then
    ok "H — gabarit absent de la source : refuse (code $code)"
else
    nok "H — gabarit absent de la source : code $code, page servie remplacée ($temoin → $apres o) sans substitution"
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — 8/8 cas conformes."
    exit 0
fi
echo "ÉCHEC — $echecs cas sur 8." >&2
exit 1
