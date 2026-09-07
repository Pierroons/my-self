#!/bin/bash
# Le profil de hachage est-il encore défini à un seul endroit ?
#
# 🔑 **Pourquoi ce contrôle existe.** Le 08/06/2026, une remédiation de pentest a
# posé le profil Argon2id dans deux implémentations sur trois. La troisième l'a
# reçu le 17/08 — soixante-dix jours pendant lesquels une instance publique
# hachait plus faiblement que ses voisines, sans qu'aucun test ne rougisse : rien,
# dans une base, ne distingue un hash à 4 itérations d'un hash à 2.
#
# La bibliothèque a supprimé la duplication. Ce script empêche qu'elle revienne.
#
# Usage : bash scripts/check-profil-unique.sh
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

SOURCE="bi-self/selfrecover/src/Crypto/Hashing.php"
echec=0

# 🔑 Le périmètre se déduit du CONTENU, pas de l'extension. `demo/lab/selfrecover-su`
# est un script PHP sans `.php` : les trois contrôles filtraient sur `*.php` et ne le
# voyaient donc pas. C'est le seul fichier du dépôt dans ce cas, et c'est aussi celui
# qui pose le secret du super-utilisateur — il a haché ce secret hors du profil sans
# qu'aucun des trois contrôles ne rougisse. Une sonde qui ne regarde pas un fichier
# rend le même vert que si ce fichier était sain.
mapfile -t SANS_EXT < <(
    git ls-files -- '*' \
    | grep -vE '\.php$' \
    | while IFS= read -r f; do
        [ -f "$f" ] && head -c 32 "$f" | grep -q '^#!.*php' && printf '%s\n' "$f"
      done
)
PHP_PATHS=('*.php' "${SANS_EXT[@]}")

echo "▸ Définitions littérales du profil Argon2id"
# Les sondes écrivent les valeurs attendues : c'est leur travail de les comparer
# au profil, pas de le lire depuis lui — sinon elles ne vérifieraient rien.
coupables=$(git grep -lE "'memory_cost'\s*=>\s*[0-9]+" -- "${PHP_PATHS[@]}" \
    | grep -vE "^(${SOURCE}|bi-self/selfrecover/tests/|.*/tests/sanity_)" || true)
if [ -n "$coupables" ]; then
    echo "  ✗ le profil est redéfini hors de la bibliothèque :"
    echo "$coupables" | sed 's/^/     /'
    echec=1
else
    echo "  ✓ défini uniquement dans ${SOURCE}"
fi

echo "▸ Empreintes Argon2id écrites en dur"
# Un hash factice recopié ailleurs se désaligne du profil sans prévenir.
dur=$(git grep -lE '\$argon2id\$v=19' -- "${PHP_PATHS[@]}" | grep -vE "^(${SOURCE}|bi-self/selfrecover/tests/)" || true)
if [ -n "$dur" ]; then
    echo "  ✗ empreinte en dur hors de la bibliothèque :"
    echo "$dur" | sed 's/^/     /'
    echec=1
else
    echo "  ✓ aucune empreinte recopiée"
fi

echo "▸ Appels directs à password_hash contournant la bibliothèque"
contournements=$(git grep -lE 'password_hash\([^)]*PASSWORD_ARGON2ID' -- "${PHP_PATHS[@]}" | grep -v "^${SOURCE}$" || true)
if [ -n "$contournements" ]; then
    echo "  ⚠ hachage direct (à vérifier, pas bloquant) :"
    echo "$contournements" | sed 's/^/     /'
else
    echo "  ✓ tout passe par Hashing::hash()"
fi

echo "▸ Appels à password_hash SANS troisième argument"
# 🔑 C'est la forme qui a réellement fait défaut, et l'avertissement ci-dessus ne
# la distinguait pas d'un appel correct : `password_hash($p, PASSWORD_ARGON2ID)`
# sans options prend celles de PHP. Elles coïncident aujourd'hui avec le profil
# sur la mémoire et les itérations, et diffèrent d'un fil — assez peu pour ne
# rien casser, assez pour que le hachage concerné reste en arrière le jour où le
# profil monte. Le secret du super-utilisateur a été posé ainsi.
#
# Les bancs sont exclus : ils écrivent délibérément une empreinte à l'ancien
# profil pour vérifier qu'elle se vérifie encore.
# Le motif est cité dans des commentaires — ici même, et dans la console SU qui
# explique le défaut. Une ligne de commentaire n'exécute rien : on l'écarte, sans
# quoi documenter le défaut le ferait rougir.
nues=$(git grep -nE 'password_hash\([^,]+,\s*PASSWORD_ARGON2ID\s*\)' -- "${PHP_PATHS[@]}" \
    | grep -vE "^(${SOURCE}|bi-self/selfrecover/tests/|.*/tests/sanity_)" \
    | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|#)' || true)
if [ -n "$nues" ]; then
    echo "  ✗ hachage Argon2id sans profil explicite :"
    echo "$nues" | sed 's/^/     /'
    echec=1
else
    echo "  ✓ tout hachage Argon2id porte son profil"
fi

exit $echec
