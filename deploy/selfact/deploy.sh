#!/bin/bash
# SelfAct — déploiement d'une instance.
#
# Les pages portent le gabarit `justice.example.org`,
# substitué ici par le domaine de l'instance. Les fichiers PHP s'en passent : ils
# lisent `SELFJUSTICE_BASE_URL` ou, à défaut, l'en-tête `Host`.
#
# ⚠️ `your-instance.example` n'est PAS un gabarit. Il apparaît dans `find.php`
# comme repli d'exécution quand `HTTP_HOST` est absent. Y toucher graverait le
# nom de l'instance dans un défaut d'exécution — deux chaînes qui se ressemblent,
# à ne pas confondre.
#
# ⚠️ `data/catalog.json` n'est jamais déployé. Il est produit par la machine les
# 1er et 15, et vit hors de l'arbre du code — voir `api/chemins.php`. Le poser
# depuis un poste reposerait une copie plus vieille par-dessus la fraîche, ce qui
# est arrivé le 20/08/2026 et a coûté douze jours de catalogue.
#
# Usage :
#   ./deploy.sh <domaine> <racine-des-pages> <racine-de-l-api>
#   ./deploy.sh justice.example.org /var/www/selfjustice /var/www/selfact-api
#
# Le script ne touche qu'aux fichiers servis. Répertoire d'état, configuration
# nginx et tâches planifiées restent à la charge de l'opérateur.

set -euo pipefail

DOMAINE="${1:-}"
DEST_SITE="${2:-}"
DEST_API="${3:-}"

if [ -z "$DOMAINE" ] || [ -z "$DEST_SITE" ] || [ -z "$DEST_API" ]; then
    echo "usage: $0 <domaine> <racine-des-pages> <racine-de-l-api>" >&2
    echo "exemple: $0 justice.example.org /var/www/selfjustice /var/www/selfact-api" >&2
    exit 1
fi

# La source est le module, pas le dépôt : ce script vit dans deploy/selfact/ et
# le code deux niveaux plus haut. Le résoudre plutôt que de le supposer permet
# de lancer le script depuis n'importe où.
ICI="$(cd "$(dirname "$0")" && pwd)"
SRC="$(cd "$ICI/../../self-right/selfact" && pwd)"
[ -f "$SRC/site/act.php" ] || {
    echo "erreur : $SRC ne contient pas site/act.php — source introuvable." >&2; exit 1; }

for d in "$DEST_SITE" "$DEST_API"; do
    [ -d "$d" ] || { echo "erreur : destination absente : $d" >&2; exit 1; }
done

# 🔑 Copier un répertoire sur lui-même vide le fichier avant de le lire. La
# comparaison porte sur les chemins résolus : un lien symbolique ou un `..` la
# contournerait sans cela.
if [ "$(cd "$SRC/site" && pwd)" = "$(cd "$DEST_SITE" && pwd)" ] \
   || [ "$(cd "$SRC/api" && pwd)" = "$(cd "$DEST_API" && pwd)" ]; then
    echo "erreur : la source et la destination sont le même répertoire." >&2
    exit 1
fi

PLACEHOLDER="justice.example.org"
echo "SelfAct — déploiement vers $DEST_SITE et $DEST_API (domaine : $DOMAINE)"

traites=0
substituees=0

# poser <fichier-source> <fichier-destination>
# Écrit à côté puis bascule : un contrôle qui se fait après avoir écrasé la
# destination ne protège plus rien.
poser() {
    local src="$1" dest="$2" attendues obtenues tmp
    attendues=$(grep -c "$PLACEHOLDER" "$src" || true)
    tmp="$dest.nouveau.$$"
    sed "s|$PLACEHOLDER|$DOMAINE|g" "$src" > "$tmp"

    obtenues=$(grep -c "$DOMAINE" "$tmp" || true)
    if [ "$attendues" -gt 0 ] && [ "$obtenues" -lt "$attendues" ]; then
        rm -f "$tmp"
        echo "erreur : $(basename "$src") — $attendues occurrence(s) attendue(s), $obtenues écrite(s)." >&2
        exit 1
    fi
    # 🔑 Le gabarit ne doit pas survivre. Un `sed` qui échoue partiellement
    # laisse une page qui nomme une instance qui n'existe pas, et répond 200.
    if grep -q "$PLACEHOLDER" "$tmp"; then
        rm -f "$tmp"
        echo "erreur : $(basename "$src") porte encore $PLACEHOLDER après substitution." >&2
        exit 1
    fi
    # 🔑 Le mode de la source, pas celui du parapluie. `sed > fichier` crée en
    # 644 : `update_catalog.sh` arrivait non exécutable, et le cron que ce script
    # documente lui-même échouait au premier passage — dans un journal de cron,
    # c'est-à-dire nulle part.
    # ⚠️ Sur le TEMPORAIRE, avant la bascule : posé sur la destination, le `mv`
    # qui suit l'écrase aussitôt.
    chmod --reference="$src" "$tmp" 2>/dev/null || chmod 644 "$tmp"
    mv "$tmp" "$dest"
    traites=$((traites + 1))
    substituees=$((substituees + attendues))
    echo "  ✓ $(basename "$dest")$([ "$attendues" -gt 0 ] && echo " — $attendues substitution(s)")"
}

echo "▸ Pages"
for nom in act.php act-docs.html; do
    [ -f "$SRC/site/$nom" ] || { echo "  · $nom absent de la source" >&2; continue; }
    poser "$SRC/site/$nom" "$DEST_SITE/$nom"
done

echo "▸ Service"
# `data/` suit à part : ses deux fichiers curés se déploient, le catalogue non.
for f in "$SRC/api"/*.php "$SRC/api"/*.js "$SRC/api"/*.md "$SRC/api"/*.sh; do
    [ -e "$f" ] || continue
    poser "$f" "$DEST_API/$(basename "$f")"
done

echo "▸ Données curées à la main"
mkdir -p "$DEST_API/data"
for nom in situations.json gabarits.json; do
    [ -f "$SRC/api/data/$nom" ] || continue
    cp -a "$SRC/api/data/$nom" "$DEST_API/data/$nom"
    echo "  ✓ data/$nom"
done
[ -f "$DEST_API/data/catalog.json" ] && \
    echo "  ⚠️  data/catalog.json présent à la destination : il n'appartient pas à l'arbre du code depuis le 21/08/2026 et n'est plus lu — voir api/chemins.php" >&2

# ⚠️ Zéro fichier traité n'est pas un cas nominal : c'est le symptôme d'une
# source vide ou mal située.
if [ "$traites" -eq 0 ]; then
    echo "erreur : aucun fichier déployé. Source vide ou mal située : $SRC" >&2
    exit 1
fi

# 🔑 Même garde que chez SelfJustice : des fichiers copiés sans aucune
# substitution veut dire que le gabarit cherché n'est plus celui que portent les
# pages. Rien d'autre ne le signale — tout répond 200.
if [ "$substituees" -eq 0 ] && [ "$DOMAINE" != "$PLACEHOLDER" ]; then
    echo "erreur : $traites fichier(s) copié(s), aucune occurrence de $PLACEHOLDER substituée." >&2
    echo "         Les pages déployées nomment une instance qui n'est pas $DOMAINE." >&2
    exit 1
fi

echo
echo "OK — $traites fichier(s), $substituees substitution(s)."
echo "  Reste à la charge de l'opérateur : le répertoire d'état (/var/lib/selfact),"
echo "  les règles nginx, et le cron de update_catalog.sh (1er et 15)."
