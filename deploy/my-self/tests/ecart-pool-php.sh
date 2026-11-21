#!/usr/bin/env bash
# Le groupe d'ouvriers PHP servi dit-il la même chose que son gabarit ?
#
# 🔑 **Pourquoi ce contrôle existe.** Le `www.conf` de la machine a vécu hors du
# dépôt jusqu'au 21/11/2026 : personne n'aurait vu sa dérive, et c'est lui qui
# porte les variables d'environnement de tout ce que la machine sert en PHP.
# `deploy/my-self/deploy.sh` ne pose pas ce fichier — comme il ne pose pas les
# vhosts —, donc le gabarit et l'instance peuvent diverger sans bruit.
#
# ⚠️ **Il n'affiche JAMAIS la valeur d'un secret.** Toute ligne `env[...]` ou
# `php_admin_value[...]` est réduite à sa CLÉ des deux côtés avant comparaison :
# le contrôle dit « cette variable est là ou pas », jamais ce qu'elle vaut. La
# lecture entière de ce fichier a fait sortir une clé d'API le 19/11/2026, et
# elle a coûté une rotation sur trois porteurs.
#
# ⚠️ **À lancer depuis un VRAI terminal** : `sudo` de la machine a besoin d'un
# TTY (`/etc/php/8.4/fpm/pool.d/www.conf` n'est pas lisible sans privilège), et
# le canal d'un agent n'en fournit pas.
#
#   POOL_SSH_HOST=<alias> bash deploy/my-self/tests/ecart-pool-php.sh
#
# Sortie : 0 si les directives concordent, 1 s'il y a un écart, 2 si le contrôle
# n'a pas pu être établi — un contrôle qu'on ne peut pas lancer ne rend pas vert.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 2

GABARIT=deploy/my-self/php-fpm-www.conf
DISTANT=/etc/php/8.4/fpm/pool.d/www.conf
HOTE=${POOL_SSH_HOST:?alias SSH de la machine qui sert (son sudo demande un mot de passe)}

[ -r "$GABARIT" ] || { echo "❌ gabarit introuvable : $GABARIT"; exit 2; }

T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

# Les directives actives, normalisées, SECRETS MASQUÉS.
# Le masquage se fait ici et côté distant : la valeur ne traverse jamais le
# réseau ni ce terminal.
directives() {
    sed -E 's/;.*$//' "$1" \
    | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//; s/[[:space:]]*=[[:space:]]*/ = /' \
    | grep -vE '^$' \
    | sed -E 's/^((env|php_admin_value|php_value)\[[A-Za-z_.]+\]) = .*/\1 = <MASQUE>/' \
    | sort -u
}

echo "▸ 1. Le gabarit du dépôt"
directives "$GABARIT" > "$T/gabarit.txt"
echo "   $(wc -l < "$T/gabarit.txt") directive(s)"

echo "▸ 2. Le pool servi sur $HOTE — valeurs masquées À LA SOURCE"
# shellcheck disable=SC2029  # le chemin est développé ici, exprès
if ! ssh -t "$HOTE" "sudo sed -E 's/^([[:space:]]*(env|php_admin_value|php_value)\[[A-Za-z_.]+\])[[:space:]]*=.*/\1 = <MASQUE>/' '$DISTANT'" > "$T/brut.txt" 2>"$T/err.txt"; then
    echo "❌ lecture impossible — ni vert ni rouge, le contrôle n'est pas établi :"
    sed 's/^/   /' "$T/err.txt" | tail -3
    exit 2
fi
directives "$T/brut.txt" > "$T/servi.txt"
echo "   $(wc -l < "$T/servi.txt") directive(s)"

if [ ! -s "$T/servi.txt" ]; then
    echo "❌ aucune directive lue : le fichier distant est vide, ou le masquage a tout mangé."
    exit 2
fi

echo
ecart=0

manquantes=$(comm -23 "$T/servi.txt" "$T/gabarit.txt")
if [ -n "$manquantes" ]; then
    ecart=1
    echo "✗ SERVI mais absent du gabarit — le gabarit est en retard, et le poser RETIRERAIT ceci :"
    printf '%s\n' "$manquantes" | sed 's/^/    /'
fi

en_trop=$(comm -13 "$T/servi.txt" "$T/gabarit.txt")
if [ -n "$en_trop" ]; then
    ecart=1
    echo "✗ dans le gabarit mais PAS servi — la machine est en retard, ou le gabarit anticipe :"
    printf '%s\n' "$en_trop" | sed 's/^/    /'
fi

if [ "$ecart" -eq 0 ]; then
    echo "✓ le pool servi et son gabarit portent les mêmes directives."
    echo "  ⚠️ Les VALEURS des secrets ne sont pas comparées, par construction :"
    echo "     ce contrôle dit qu'une variable est là, jamais ce qu'elle vaut."
    exit 0
fi

echo
echo "⚠️ Un écart n'est pas forcément une faute : ce gabarit ne reprend pas les"
echo "   commentaires du paquet, et une directive au défaut n'a pas à y figurer."
echo "   Ce qui compte est la liste ci-dessus, ligne par ligne."
exit 1
