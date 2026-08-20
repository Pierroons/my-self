#!/bin/bash
# SelfJustice — déploiement d'une instance.
#
# 🔑 **Pourquoi ce script existe.** Le dépôt est anonyme par règle : aucun
# domaine réel n'y figure, seulement le placeholder `your-instance.example`.
# Les fichiers PHP s'en accommodent seuls — ils lisent `SELFJUSTICE_BASE_URL`
# ou, à défaut, l'en-tête `Host`. Le HTML statique, lui, ne le peut pas : le
# placeholder y est écrit en dur et serait servi tel quel.
#
# Sans substitution au déploiement, une instance affiche donc à ses visiteurs
# « analyse your-instance.example » — une consigne inutilisable. C'est arrivé le
# 02/08/2026 en déployant le dépôt directement, et rien ne l'avait signalé
# puisque le site répondait 200.
#
# Usage :
#   ./deploy.sh <domaine> <racine-de-destination>
#   ./deploy.sh justice.example.org /var/www/selfjustice
#
# Le script ne touche qu'aux fichiers servis. Bases de données, configuration
# nginx et tâches planifiées restent à la charge de l'opérateur.

set -euo pipefail

DOMAINE="${1:-}"
DEST="${2:-}"

if [ -z "$DOMAINE" ] || [ -z "$DEST" ]; then
    echo "usage: $0 <domaine> <racine-de-destination>" >&2
    echo "exemple: $0 justice.example.org /var/www/selfjustice" >&2
    exit 1
fi

if [ ! -d "$DEST" ]; then
    echo "erreur : $DEST n'existe pas" >&2
    exit 1
fi

# 🔑 La racine du module se reconnaît à ce qu'elle contient, jamais à sa distance
# du script. Un `dirname "$0"/..` code en dur la profondeur : déplacer ce fichier
# vers un dossier `deploy/` commun fait pointer le `..` sur la racine du dépôt, et
# tout ce qui suit travaille alors sur une source qui n'existe pas.
#
# On essaie donc les emplacements possibles et on retient le premier qui porte le
# marqueur. Remonter de parent en parent ne suffirait pas : depuis
# `deploy/selfjustice/`, le module est dans une branche voisine, pas au-dessus.
ICI="$(cd "$(dirname "$0")" && pwd)"
SRC=""
for candidat in "$ICI/.." "$ICI/../../self-right/selfjustice"; do
    if [ -f "$candidat/site/index.php" ]; then
        SRC="$(cd "$candidat" && pwd)"
        break
    fi
done

# ⚠️ Et si elle reste introuvable, on s'arrête ici. Sans cette sortie, le script
# continue avec une racine absente : la boucle plus bas ne trouve aucun fichier,
# n'écrit rien, et le contrôle final ne voit aucun placeholder dans une
# destination déjà substituée — donc il affiche « OK » et sort en 0. Un
# déploiement qui n'a rien déployé et qui l'annonce comme un succès est pire
# qu'un déploiement qui échoue.
if [ -z "$SRC" ]; then
    echo "erreur : racine du module SelfJustice introuvable depuis $ICI." >&2
    echo "         Aucun des emplacements essayés ne contient site/index.php :" >&2
    echo "           $ICI/.." >&2
    echo "           $ICI/../../self-right/selfjustice" >&2
    exit 1
fi

PLACEHOLDER="your-instance.example"

# ⚠️ Garde-fou indispensable : `sed source > destination` tronque la destination
# AVANT que sed n'ouvre la source. Si les deux sont le même fichier, le contenu
# est détruit — et le serveur continue de répondre 200 avec des pages vides,
# donc rien ne le signale. C'est arrivé le 02/08/2026 en lançant ce script
# depuis la copie déployée : les deux pages d'accueil sont tombées à 0 octet.
#
# La comparaison ci-dessous n'a de sens que parce que `$SRC/site` est garanti
# exister : tant que son absence était avalée par un `2>/dev/null`, la
# substitution rendait une chaîne vide, l'égalité était fausse quoi qu'il arrive,
# et ce garde-fou ne pouvait plus se déclencher.
if [ "$(cd "$SRC/site" && pwd)" = "$(cd "$DEST" && pwd)" ]; then
    echo "erreur : la source et la destination sont le même répertoire." >&2
    echo "         Lancez ce script depuis le dépôt, pas depuis l'instance déployée." >&2
    exit 1
fi

echo "SelfJustice — déploiement vers $DEST (domaine : $DOMAINE)"

# Les fichiers servis directement au navigateur. `act.php` et `act-docs.html`
# peuvent légitimement manquer selon les modules activés, d'où le `continue` ;
# `index.php` non, mais on compte plutôt que de le traiter à part.
#
# ⚠️ Deux extensions, et ce n'est pas un oubli : les compteurs du corpus sont
# rendus côté serveur depuis le 19/08/2026, ce qui a fait passer index et act
# en .php ; act-docs reste une page statique. Ce script cherchait encore les
# trois en .html, donc ne trouvait plus la racine du module et ne déployait
# rien — en le disant, au moins. La production n'en dépendait pas, elle est
# servie par un autre chemin ; toute autre instance de SelfJustice, si.
traites=0
for f in site/index.php site/act.php site/act-docs.html; do
    [ -f "$SRC/$f" ] || continue
    cible="$DEST/$(basename "$f")"
    sed "s|$PLACEHOLDER|$DOMAINE|g" "$SRC/$f" > "$cible"
    n=$(grep -c "$DOMAINE" "$cible" || true)
    echo "  $(basename "$f") : $n occurrence(s) substituée(s)"
    traites=$((traites + 1))
done

# ⚠️ Zéro fichier traité n'est pas un cas nominal : c'est le symptôme d'une
# source vide ou mal située. Sans cette sortie, le contrôle final rendrait « OK »
# sur une destination que ce passage n'a pas touchée.
if [ "$traites" -eq 0 ]; then
    echo "erreur : aucun fichier servi trouvé sous $SRC/site — rien n'a été déployé." >&2
    exit 1
fi

# Contrôle : aucun placeholder ne doit survivre dans ce qui est servi.
if grep -rq "$PLACEHOLDER" "$DEST" 2>/dev/null; then
    echo "ERREUR : le placeholder subsiste dans $DEST" >&2
    grep -rln "$PLACEHOLDER" "$DEST" >&2
    exit 1
fi

echo "OK — aucun placeholder résiduel."
