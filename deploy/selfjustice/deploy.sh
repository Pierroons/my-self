#!/bin/bash
# SelfJustice — déploiement d'une instance.
#
# 🔑 **Pourquoi ce script existe.** SelfJustice se déploie sur le domaine de
# celui qui l'installe, pas sur le nôtre : le HTML servi porte donc le
# placeholder `your-instance.example`, à substituer ici. Les fichiers PHP s'en
# passent — ils lisent `SELFJUSTICE_BASE_URL` ou, à défaut, l'en-tête `Host`.
# Le HTML statique, lui, ne le peut pas : le placeholder y est écrit en dur et
# serait servi tel quel.
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

# 🔑 Ce nom doit être celui qu'on lit RÉELLEMENT dans site/, jamais celui qu'on
# croit y avoir mis. Il valait « your-instance.example » alors que les trois
# pages en portent 22 occurrences de « justice.example.org » : le sed ne
# trouvait rien, recopiait les fichiers tels quels, et un déploiement aurait
# publié un domaine mort — y compris dans la ligne qui dit à l'utilisateur quoi
# taper à son assistant. Le compteur affichait « 0 occurrence(s) substituée(s) »
# et le script continuait.
PLACEHOLDER="justice.example.org"

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

# Les fichiers servis directement au navigateur.
#
# 🔑 `act.php` et `act-docs.html` ne sont pas ici : SelfAct est un module à part,
# déployé par `deploy/selfact/deploy.sh`. Une instance qui veut les deux lance
# les deux scripts.
#
# ⚠️ `index` est en `.php`, pas en `.html` : les compteurs du corpus sont rendus
# côté serveur. Chercher la mauvaise extension fait manquer la racine du module.
#
# La liste fait autorité pour les deux boucles qui suivent : celle qui copie, et
# celle qui signale ce qui traîne à la destination sans plus venir d'ici. Écrite
# deux fois, un renommage n'en corrigerait qu'une.
SERVIS="index.php"

traites=0
substituees=0
for nom in $SERVIS; do
    f="site/$nom"
    [ -f "$SRC/$f" ] || continue
    cible="$DEST/$(basename "$f")"
    attendues=$(grep -c "$PLACEHOLDER" "$SRC/$f" || true)

    # ⚠️ On écrit d'abord à côté. Un contrôle qui se fait après avoir écrasé la
    # page servie constate le dégât au lieu de l'empêcher.
    provisoire="$cible.en-cours-$$"
    sed "s|$PLACEHOLDER|$DOMAINE|g" "$SRC/$f" > "$provisoire"
    restantes=$(grep -c "$PLACEHOLDER" "$provisoire" || true)

    # Le cas où l'instance s'appelle vraiment comme le gabarit : la substitution
    # est l'identité, et le placeholder subsiste légitimement.
    if [ "$DOMAINE" != "$PLACEHOLDER" ] && [ "$restantes" -ne 0 ]; then
        rm -f "$provisoire"
        echo "erreur : $(basename "$f") garde $restantes occurrence(s) de $PLACEHOLDER après substitution." >&2
        echo "         Page servie inchangée. Rien d'autre ne sera déployé." >&2
        exit 1
    fi

    mv "$provisoire" "$cible"
    echo "  $(basename "$f") : $attendues occurrence(s) substituée(s)"
    substituees=$((substituees + attendues))
    traites=$((traites + 1))
done

# ⚠️ Aucune substitution sur l'ensemble des pages ne peut pas être un cas
# nominal : les pages nomment leur instance. Zéro signifie que le gabarit
# cherché n'est plus celui qu'elles portent — le défaut ne se voit nulle part
# ailleurs, puisque des fichiers ont bien été copiés et que tout répond 200.
if [ "$traites" -gt 0 ] && [ "$substituees" -eq 0 ] && [ "$DOMAINE" != "$PLACEHOLDER" ]; then
    echo "erreur : $traites fichier(s) copié(s), aucune occurrence de $PLACEHOLDER substituée." >&2
    echo "         Les pages déployées nomment une instance qui n'est pas $DOMAINE." >&2
    exit 1
fi

# 🔑 Un transfert fichier par fichier n'efface rien : un nom retiré de la source
# survit indéfiniment à la destination. Le renommage du 19/08/2026 l'a montré —
# `act.html` répondait encore en 200 des jours plus tard, figé à sa dernière
# version, à côté de l'`act.php` qui le remplace. Les deux coïncidaient ce
# jour-là ; ils divergent au premier changement de contenu, et rien ne le dit.
#
# Signalé, jamais supprimé : effacer un fichier servi au public est une décision
# humaine, et ce script ne sait pas si la page orpheline est un résidu ou un
# chemin que quelqu'un a délibérément posé là.
#
# ⚠️ **Un module voisin n'est pas un résidu.** Depuis que SelfAct a son propre
# script, `act.php` et `act-docs.html` ne sont plus produits ici — mais sur une
# instance qui sert les deux modules depuis la même racine, ils sont légitimes.
# Les traiter comme des orphelins ferait supprimer un module en suivant le
# conseil de ce script. Ils sont donc nommés à part, avec le geste qui convient.
VOISINS="act.php act-docs.html"
for ancien in "$DEST"/*.html "$DEST"/*.php; do
    [ -e "$ancien" ] || continue
    nom=$(basename "$ancien")
    orphelin=1
    for servi in $SERVIS; do
        [ "$nom" = "$servi" ] && orphelin=0
    done
    [ "$orphelin" -eq 1 ] || continue
    voisin=0
    for v in $VOISINS; do
        [ "$nom" = "$v" ] && voisin=1
    done
    if [ "$voisin" -eq 1 ]; then
        echo "  ·  $nom appartient à SelfAct — déployé par deploy/selfact/deploy.sh, à ne pas retirer" >&2
    else
        echo "  ⚠️  $nom est servi mais n'est plus produit par la source — résidu probable, à retirer à la main" >&2
    fi
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
