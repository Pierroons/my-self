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

SRC="$(cd "$(dirname "$0")/.." && pwd)"
PLACEHOLDER="your-instance.example"

# ⚠️ Garde-fou indispensable : `sed source > destination` tronque la destination
# AVANT que sed n'ouvre la source. Si les deux sont le même fichier, le contenu
# est détruit — et le serveur continue de répondre 200 avec des pages vides,
# donc rien ne le signale. C'est arrivé le 02/08/2026 en lançant ce script
# depuis la copie déployée : index.html et act.html sont tombés à 0 octet.
if [ "$(cd "$SRC/site" 2>/dev/null && pwd)" = "$(cd "$DEST" && pwd)" ]; then
    echo "erreur : la source et la destination sont le même répertoire." >&2
    echo "         Lancez ce script depuis le dépôt, pas depuis l'instance déployée." >&2
    exit 1
fi

echo "SelfJustice — déploiement vers $DEST (domaine : $DOMAINE)"

# Les fichiers servis directement au navigateur.
for f in site/index.html site/act.html site/act-docs.html; do
    [ -f "$SRC/$f" ] || continue
    cible="$DEST/$(basename "$f")"
    sed "s|$PLACEHOLDER|$DOMAINE|g" "$SRC/$f" > "$cible"
    n=$(grep -c "$DOMAINE" "$cible" || true)
    echo "  $(basename "$f") : $n occurrence(s) substituée(s)"
done

# Contrôle : aucun placeholder ne doit survivre dans ce qui est servi.
if grep -rq "$PLACEHOLDER" "$DEST" 2>/dev/null; then
    echo "ERREUR : le placeholder subsiste dans $DEST" >&2
    grep -rln "$PLACEHOLDER" "$DEST" >&2
    exit 1
fi

echo "OK — aucun placeholder résiduel."
