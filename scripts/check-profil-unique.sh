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
cd "$(git rev-parse --show-toplevel)" || exit 1

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

echo "▸ Appels à password_hash sans profil explicite"
# 🔑 C'est la forme qui a réellement fait défaut : `password_hash($p, PASSWORD_ARGON2ID)`
# sans options prend celles de PHP. Elles coïncident aujourd'hui avec le profil sur la
# mémoire et les itérations, et diffèrent d'un fil — assez peu pour ne rien casser,
# assez pour que le hachage concerné reste en arrière le jour où le profil monte. Le
# secret du super-utilisateur a été posé ainsi.
#
# ⚠️ **Ce contrôle ne peut pas être un `grep`.** Trois formes lui échappaient, chacune
# éprouvée avant d'être fermée : les options vides `[]`, qui valent les défauts de PHP ;
# l'appel réparti sur plusieurs lignes, qu'une recherche ligne à ligne ne voit pas ; et
# l'algorithme rangé dans une variable. `audit-password-hash.php` lit des appels, pas
# des lignes — et n'a donc pas besoin d'écarter les commentaires à la main, puisqu'un
# commentaire n'est pas du code pour un analyseur de jetons.
#
# La seule exemption est nominative. Un banc qui pose délibérément une empreinte à
# l'ancien profil, pour vérifier qu'elle se vérifie encore, ne peut pas passer le
# profil : c'est tout son propos. Exempter la famille `tests/sanity_*` couvrirait
# aussi celui qui deviendrait fautif demain.
EXEMPTS='demo/lab/tests/sanity_su.php'

mapfile -t A_LIRE < <(
    { git ls-files -- '*.php'; printf '%s\n' "${SANS_EXT[@]}"; } \
    | grep -vE "^(${EXEMPTS})$" | grep -v '^$'
)
# ⚠️ L'absence de l'analyseur doit être bruyante. Sans ce garde, `php` échoue,
# `|| true` avale son code, la sortie est vide et le contrôle rend VERT : un
# fichier effacé se lit alors comme un dépôt sain.
ANALYSEUR="scripts/audit-password-hash.php"
if [ ! -f "$ANALYSEUR" ]; then
    echo "  ✗ ${ANALYSEUR} est introuvable — ce contrôle ne peut rien affirmer"
    exit 1
fi

sans_profil=$(php "$ANALYSEUR" "${A_LIRE[@]}")
etat=$?
if [ "$etat" -gt 1 ]; then
    echo "  ✗ ${ANALYSEUR} a échoué (code ${etat}) — ce contrôle ne peut rien affirmer"
    echo "$sans_profil" | sed 's/^/     /'
    exit 1
fi
if [ -n "$sans_profil" ]; then
    echo "  ✗ hachage Argon2id sans profil explicite :"
    echo "$sans_profil" | sed 's/^/     /'
    echec=1
else
    echo "  ✓ les ${#A_LIRE[@]} fichiers analysés portent tous leur profil"
fi


# ─────────────────────────────────────────────────────────────────────────────
# 🔑 Les quatre contrôles ci-dessus ne regardent QUE du PHP, et c'est le trou par
# lequel l'écart a vécu : un profil de dérivation écrit dans un `.js` ne
# déclenchait rien. La bibliothèque annonçait Argon2id pour le facteur « cet
# appareil » pendant que la seule implémentation faisait PBKDF2, et aucune sonde
# n'avait de raison de le voir.
#
# Une sonde qui ne regarde pas un langage rend le même vert que si ce langage
# était sain.
# ─────────────────────────────────────────────────────────────────────────────

echo "▸ Dérivation de clé côté navigateur"

# Le porteur unique, et ce qui l'entoure légitimement.
KDF_JS="bi-self/selfrecover/client/sr-kdf.js"

# L'exemption est NOMINATIVE et porte sa raison. Exempter un répertoire couvrirait
# aussi le fichier qui deviendrait fautif demain.
#
# `e2e-memo.js` — coffre de démonstration, dont le PBKDF2 est assumé et INSCRIT
#   DANS LE BLOB (`kdf_iter`) : il sait sous quoi il a chiffré, donc il peut
#   migrer. C'est le seul du dépôt dans ce cas.
EXEMPTS_JS='demo/lab/public/js/e2e-memo\.js'

kdf_js=$(git grep -lE "name:\s*'PBKDF2'|\"PBKDF2\"|'PBKDF2'" -- '*.js' \
    | grep -vE "^(${KDF_JS}|bi-self/selfrecover/client/vendor/|bi-self/selfrecover/tests/|${EXEMPTS_JS})" || true)
if [ -n "$kdf_js" ]; then
    echo "  ✗ dérivation de mot de passe en JavaScript hors de ${KDF_JS} :"
    echo "$kdf_js" | sed 's/^/     /'
    echo "     Argon2id est la règle du projet ; l'exception est SelfVault, et elle"
    echo "     énonce sa condition — des secrets TIRÉS AU SORT. Un mot mémorisé est"
    echo "     choisi par un humain : la condition ne tient pas."
    echec=1
else
    echo "  ✓ aucune KDF en JavaScript hors du porteur, sauf les exemptions nommées"
fi

# Un profil Argon2id recopié dans un autre `.js` se désaligne sans que rien ne le
# dise — le même défaut que les empreintes en dur, dans l'autre langage.
profil_js=$(git grep -lE 'memorySize\s*:|memoryCost\s*:|memory_cost\s*:' -- '*.js' \
    | grep -vE "^(${KDF_JS}|bi-self/selfrecover/client/vendor/|bi-self/selfrecover/tests/)" || true)
if [ -n "$profil_js" ]; then
    echo "  ✗ profil de KDF redéfini en JavaScript :"
    echo "$profil_js" | sed 's/^/     /'
    echec=1
else
    echo "  ✓ le profil Argon2id du navigateur n'est défini que dans ${KDF_JS}"
fi

echo "▸ Aucune bibliothèque cryptographique tierce embarquée"

# 🔑 La KDF du navigateur a d'abord été un binaire WebAssembly copié dans le
# dépôt. Elle est maintenant écrite ici, et rien ne doit ramener un opaque à sa
# place sans que ce contrôle le dise : un fichier minifié ne se relit pas, et
# c'est précisément là que personne ne regarde.
opaques=$(git ls-files -- 'bi-self/selfrecover/client/*' \
    | while IFS= read -r f; do
        [ -f "$f" ] || continue
        # Une ligne de plus de 500 caractères ne s'écrit pas à la main.
        awk 'length > 500 { print FILENAME; exit }' "$f"
      done | sort -u)
if [ -n "$opaques" ]; then
    echo "  ✗ fichier illisible dans la bibliothèque cliente :"
    echo "$opaques" | sed 's/^/     /'
    echo "     Un binaire minifié ne se vérifie que par son empreinte. S'il est"
    echo "     voulu, il lui faut une provenance et un contrôle d'intégrité."
    echec=1
else
    echo "  ✓ tout ce que la bibliothèque cliente livre est lisible"
fi

exit $echec
