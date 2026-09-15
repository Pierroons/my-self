#!/bin/bash
# Les planchers des secrets de déploiement sont-ils encore d'accord ?
#
# 🔑 **Pourquoi ce contrôle existe.** Le 15/09/2026, un déploiement intégrateur a
# mesuré que son secret de service avait DEUX planchers dans son adaptateur — 32
# caractères exigés d'un côté, 16 de l'autre. Une valeur entre les deux passait
# ici et cassait là, et le message rendu à l'opérateur nommait la conséquence
# (« Service mal configuré ») sans nommer la cause. Ils ont perdu du temps sur des
# secrets de 28 caractères.
#
# En cherchant chez moi, j'ai trouvé la même divergence : `AuditLog` se contentait
# de 16 quand les trois consommateurs du lab exigeaient 32. Trois planchers pour une
# même sorte de valeur, et rien qui rende l'écart visible — parce qu'un plancher trop
# bas n'échoue jamais : il accepte.
#
# La règle du projet : **un plancher dans le code, pas de libre appréciation** — un
# appelant peut être plus exigeant, jamais moins.
#
# Les deux bibliothèques sont indépendantes par choix : aucune ne dépend de l'autre,
# et on n'ajoute pas une dépendance pour partager une constante. La valeur est donc
# DÉCLARÉE de chaque côté, et c'est ce script qui les tient d'accord — le motif de
# `vecteurs-derivation.json`, qui tient cinq consommateurs sans les coupler.
#
# Usage : bash scripts/check-plancher-secret.sh
# Sortie : 0 si tous les porteurs s'accordent, 1 sinon.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 1

PLANCHER=32
echec=0
vus=0

# ⚠️ Les lignes de COMMENTAIRE sont écartées des deux inventaires ci-dessous.
# Sans ce filtre, une mention de `SecretInstance::lire()` dans un docblock était
# comptée comme un appel, et le contrôle rougissait sur une phrase — il lisait du
# texte au lieu de lire du code. `git grep -n` rend « fichier:ligne:contenu ».
sans_commentaires() { grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#|/\*)'; }

ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
ko()   { printf '  \033[31m✗\033[0m %s\n' "$*"; echec=1; }

printf '\n▸ Plancher du projet pour un secret de déploiement : %s caractères\n\n' "$PLANCHER"

# ---------------------------------------------------------------------------
# 1. Les constantes déclarées. Elles DOIVENT valoir le plancher, ni plus ni moins :
#    une déclaration plus haute serait une seconde vérité, pas une prudence.
printf '▸ Constantes déclarées\n'
while IFS=: read -r fichier ligne texte; do
    [ -n "$fichier" ] || continue
    vus=$((vus + 1))
    valeur=$(printf '%s' "$texte" | grep -oE '[0-9]+' | tail -1)
    if [ "$valeur" = "$PLANCHER" ]; then
        ok "$fichier:$ligne — $valeur"
    else
        ko "$fichier:$ligne — $valeur, attendu $PLANCHER"
    fi
done < <(git grep -nE "public const (PLANCHER_SECRET|PLANCHER) = [0-9]+" -- '*.php')

[ "$vus" -gt 0 ] || ko "aucune constante de plancher trouvée — le motif a-t-il été renommé ?"

# ---------------------------------------------------------------------------
# 2. Les appels qui passent une longueur minimale à SecretInstance::lire().
#    Un appelant a le droit d'être PLUS exigeant que le plancher, jamais moins —
#    et `lire()` le refuse désormais lui-même. Ce contrôle attrape l'écart avant
#    l'exécution, y compris dans un chemin qu'aucun banc ne parcourt.
printf '\n▸ Appels à SecretInstance::lire()\n'
appels=0
while IFS=: read -r fichier ligne texte; do
    [ -n "$fichier" ] || continue
    appels=$((appels + 1))
    # lire('<nom>', <octets>, <minLongueur>, …) — le troisième argument
    min=$(printf '%s' "$texte" | sed -nE "s/.*lire\([^,]+, *[0-9]+, *([0-9]+).*/\1/p")
    if [ -z "$min" ]; then
        ko "$fichier:$ligne — longueur minimale non lisible, à vérifier à la main"
    elif [ "$min" -ge "$PLANCHER" ]; then
        ok "$fichier:$ligne — $min"
    else
        ko "$fichier:$ligne — $min, sous le plancher $PLANCHER"
    fi
done < <(git grep -nE "SecretInstance::lire\(" -- '*.php' | grep -v 'function lire' | sans_commentaires)

[ "$appels" -gt 0 ] || printf '  (aucun appel — rien à juger)\n'

# ---------------------------------------------------------------------------
# 3. Les planchers écrits à la main, hors constante.
#
# ⚠️ Ce contrôle vise la DIVERGENCE, pas le style. Un plancher en dur qui vaut la
# même chose que le plancher du projet ne casse rien — c'est celui qui en diffère
# qui coûte, parce qu'un plancher trop bas n'échoue jamais : il accepte. Exiger une
# constante partout imposerait un remaniement à deux bibliothèques indépendantes
# pour une propriété qu'on peut mesurer directement.
#
# ⚠️ Et il ne confond pas un secret avec une clé PUBLIQUE. La première version de ce
# script signalait `$publicKey` et `$adminRecoveryPubKey` : leur contrôle de longueur
# garde contre une écriture tronquée, pas contre un manque d'entropie, et les aligner
# sur le plancher d'un secret n'aurait eu aucun sens.
printf '\n▸ Planchers écrits en dur (hors clés publiques)\n'
dur=0
while IFS=: read -r fichier ligne texte; do
    [ -n "$fichier" ] || continue
    dur=$((dur + 1))
    valeur=$(printf '%s' "$texte" | sed -nE 's/.*strlen\(\$[A-Za-z_]+\) *< *([0-9]+).*/\1/p')
    if [ "$valeur" = "$PLANCHER" ]; then
        ok "$fichier:$ligne — $valeur (en dur, mais d'accord)"
    else
        ko "$fichier:$ligne — $valeur, diverge du plancher $PLANCHER"
    fi
done < <(git grep -nE "strlen\(\\\$[A-Za-z_]*([Ss]ecret|[Ss]el|[Kk]ey)[A-Za-z_]*\) *< *[0-9]+" -- '*.php' \
         | grep -viE '\$[A-Za-z_]*[Pp]ub' | sans_commentaires)
[ "$dur" -eq 0 ] && ok "aucun plancher en dur"

# ---------------------------------------------------------------------------
printf '\n'
if [ "$echec" -eq 0 ]; then
    printf '\033[32m✓ tous les porteurs du plancher s accordent sur %s.\033[0m\n\n' "$PLANCHER"
    exit 0
fi
printf '\033[31m✗ des porteurs divergent — voir ci-dessus.\033[0m\n'
printf '  Un plancher trop bas n échoue jamais : il accepte. La divergence ne se\n'
printf '  voit donc qu ici, ou le jour ou un operateur perd une heure dessus.\n\n'
exit 1
