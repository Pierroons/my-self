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
# Sortie : 0 si les quatre cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$ICI/.." && pwd)"
RACINE="$(cd "$MODULE/../.." && pwd)"
# deploy.sh vit sous deploy/selfjustice/ depuis le rangement ; le module ne garde
# que son site et ses tests.
DEPLOY="$RACINE/deploy/selfjustice/deploy.sh"
DOMAINE="justice.example.org"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

[ -f "$DEPLOY" ] || { echo "deploy.sh introuvable : $DEPLOY" >&2; exit 1; }

# ── A — emplacement actuel : doit déployer ──────────────────────────────────
mkdir -p "$TMP/a"
if bash "$DEPLOY" "$DOMAINE" "$TMP/a" >/dev/null 2>&1 && [ "$(find "$TMP/a" -name '*.html' | wc -l)" -gt 0 ]; then
    ok "A — emplacement actuel : déploie"
else
    nok "A — emplacement actuel : aurait dû déployer"
fi

# ── B — déplacé dans deploy/selfjustice/ : doit déployer aussi ──────────────
mkdir -p "$TMP/b/depot/deploy/selfjustice" "$TMP/b/depot/self-right/selfjustice/site" "$TMP/b/dest"
cp "$MODULE"/site/*.html "$MODULE"/site/*.php "$TMP/b/depot/self-right/selfjustice/site/" 2>/dev/null
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

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — 4/4 cas conformes."
    exit 0
fi
echo "ÉCHEC — $echecs cas sur 4." >&2
exit 1
