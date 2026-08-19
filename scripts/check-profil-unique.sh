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

echo "▸ Définitions littérales du profil Argon2id"
# Les sondes écrivent les valeurs attendues : c'est leur travail de les comparer
# au profil, pas de le lire depuis lui — sinon elles ne vérifieraient rien.
coupables=$(git grep -lE "'memory_cost'\s*=>\s*[0-9]+" -- '*.php' \
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
dur=$(git grep -lE '\$argon2id\$v=19' -- '*.php' | grep -vE "^(${SOURCE}|bi-self/selfrecover/tests/)" || true)
if [ -n "$dur" ]; then
    echo "  ✗ empreinte en dur hors de la bibliothèque :"
    echo "$dur" | sed 's/^/     /'
    echec=1
else
    echo "  ✓ aucune empreinte recopiée"
fi

echo "▸ Appels directs à password_hash contournant la bibliothèque"
contournements=$(git grep -lE 'password_hash\([^)]*PASSWORD_ARGON2ID' -- '*.php' | grep -v "^${SOURCE}$" || true)
if [ -n "$contournements" ]; then
    echo "  ⚠ hachage direct (à vérifier, pas bloquant) :"
    echo "$contournements" | sed 's/^/     /'
else
    echo "  ✓ tout passe par Hashing::hash()"
fi

exit $echec
