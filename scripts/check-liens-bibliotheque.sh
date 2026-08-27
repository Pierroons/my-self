#!/bin/bash
# Les démos pointent-elles encore vers la bibliothèque, au lieu de la copier ?
#
# 🔑 **Pourquoi ce contrôle existe.** Le dériveur du mot mémorisé a été livré le
# 27/08/2026 parce que chaque intégrateur l'écrivait lui-même : cinq formules
# pour une seule propriété, dont deux qui l'affichaient sans l'avoir. Les démos
# consomment désormais un lien symbolique vers la bibliothèque.
#
# Un lien remplacé par une copie ne casse rien le jour où on le pose : le fichier
# est correct, les sondes passent. Il se met à diverger silencieusement ensuite —
# et c'est exactement la duplication que le lien existe pour supprimer.
#
# Un `git clone` conserve les liens ; une copie à la main, une extraction d'archive
# ou un `rsync` sans `-l` les remplacent par leur contenu. C'est le cas courant.
#
# Usage : bash scripts/check-liens-bibliotheque.sh
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

SOURCE="bi-self/selfrecover/client/sr-derive.js"
echec=0

echo "▸ La bibliothèque existe"
if [ -f "$SOURCE" ]; then
    echo "  ✓ ${SOURCE}"
else
    echo "  ✗ ${SOURCE} est introuvable — les liens ci-dessous ne peuvent pas résoudre."
    exit 1
fi

echo "▸ Liens des démos vers la bibliothèque"
# Le mode git fait foi, pas le système de fichiers : c'est ce qui est versionné
# qui sera cloné ailleurs. Un lien correct en local mais commité comme fichier
# régulier reviendrait en copie chez le prochain qui clone.
liens=$(git ls-files -s -- 'demo/*/sr-derive.js' 'demo/*/*/sr-derive.js' 'demo/*/*/*/sr-derive.js' || true)

# 🔑 D'abord ce que git NE voit pas. Un lien présent sur le disque mais absent de
# l'index rendait cet étage vert sans rien mesurer : la démo marchait en local,
# et le prochain clone n'emportait rien. C'est arrivé le 27/08/2026, ici même,
# pendant qu'on éprouvait ce script.
sur_disque=$(find demo -name sr-derive.js 2>/dev/null | sort || true)
for f in $sur_disque; do
    if ! git ls-files --error-unmatch "$f" >/dev/null 2>&1; then
        echo "  ✗ ${f} existe sur le disque mais n'est pas suivi par git — un clone ne l'aura pas"
        echec=1
    fi
done

# Puis ce qui charge la bibliothèque sans lien du tout à côté.
chargeurs=$(git grep -l 'src="/js/sr-derive.js"' -- 'demo/**' || true)
for c in $chargeurs; do
    racine=$(printf '%s' "$c" | cut -d/ -f1-2)
    if ! printf '%s' "$liens" | grep -q "^.*${racine}/"; then
        echo "  ✗ ${c} charge /js/sr-derive.js, mais ${racine} n'a aucun lien indexé vers la bibliothèque"
        echec=1
    fi
done

if [ -z "$liens" ]; then
    echo "  ⚠ aucune démo ne référence la bibliothèque — attendu tant qu'aucune n'est raccordée"
else
    while read -r mode _ _ chemin; do
        [ -z "$chemin" ] && continue
        if [ "$mode" != "120000" ]; then
            echo "  ✗ ${chemin} est une COPIE (mode ${mode}), pas un lien vers la bibliothèque"
            echec=1
        elif [ ! -e "$chemin" ]; then
            echo "  ✗ ${chemin} est un lien mort → $(readlink "$chemin")"
            echec=1
        elif [ "$(realpath "$chemin")" != "$(realpath "$SOURCE")" ]; then
            echo "  ✗ ${chemin} pointe ailleurs → $(readlink "$chemin")"
            echec=1
        else
            echo "  ✓ ${chemin} → ${SOURCE}"
        fi
    done <<< "$liens"
fi

echo "▸ Réimplémentations du dériveur hors de la bibliothèque"
# Le motif vise le HMAC nommément, et non `subtle.importKey` : ce dernier attrape
# tout le PBKDF2, le HKDF et l'ECDSA des autres facteurs, qui sont légitimes et
# n'ont rien à voir avec la dérivation du mot mémorisé. Un contrôle qui rougit sur
# eux serait débranché à la première lecture, et il ne garderait plus rien.
copies=$(git grep -lE "name: *'HMAC'|name: *\"HMAC\"" -- \
        'demo/**/*.js' 'demo/**/*.html' 'demo/**/*.php' 'bi-self/**/*.js' \
    | grep -v "^${SOURCE}$" || true)
if [ -n "$copies" ]; then
    echo "  ✗ le HMAC est recalculé hors de la bibliothèque :"
    echo "$copies" | sed 's/^/     /'
    echo "     → ces fichiers doivent charger ${SOURCE} au lieu de le réécrire."
    echec=1
else
    echo "  ✓ aucune réimplémentation"
fi

echo "▸ Formule de dérivation citée dans les textes"
# Les extraits d'un document sont le premier endroit qu'un intégrateur recopie, et
# une formule périmée y survit plus longtemps que dans du code, faute de quoi que
# ce soit qui l'exécute.
#
# ⚠️ La première version de ce contrôle ne cherchait que `service_label` dans les
# `*.md`. Elle rendait vert le jour où elle a été écrite : le dépôt n'emploie pas
# cette graphie, et cinq de ses porteurs vivaient en `.php` ou `.html`. Un motif
# qui ne peut rien attraper ne se distingue pas d'une ligne absente — c'est le
# défaut que ce script existe pour fermer, et il l'avait chez lui.
#
# Ce qui est cherché : le mot mémorisé en MESSAGE plutôt qu'en clé, un matériel
# annoncé comme un label ou un domaine alors que la formule livrée lit le nom
# d'hôte, et le sel de site — qui ne participe plus à la dérivation.
MOTIF_FORMULE="HMAC[^)]{0,40}(label.?de.?service|label_de_service|service_label|label_service|domaine ?‖|domain ?\|\||sel_du_site|site_salt)"
# Et la forme inversée, qui met le SEL en clé et le mot en message : elle ne
# contient aucun des mots ci-dessus, et c'est pourtant la plus fausse des deux.
MOTIF_INVERSE="HMAC(-SHA256)?\((salt|sel|user_salt|recovery_salt)[,, ]"
docs=$(git grep -lniE "$MOTIF_FORMULE|$MOTIF_INVERSE" -- '*.md' '*.php' '*.html' '*.js' \
    | grep -vE "vecteurs-derivation|^scripts/check-liens-bibliotheque\.sh$" || true)
if [ -n "$docs" ]; then
    echo "  ✗ formule périmée : le mot en message, ou un label/sel de site là où la"
    echo "    dérivation lit le nom d'hôte —"
    echo "$docs" | sed 's/^/     /'
    echec=1
else
    echo "  ✓ les textes décrivent la formule livrée"
fi

exit $echec
