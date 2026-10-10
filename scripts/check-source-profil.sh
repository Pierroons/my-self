#!/bin/bash
# Le profil Argon2id du navigateur dit-il encore d'où il vient ?
#
# 🔑 **Pourquoi ce contrôle existe.** Trois profils Argon2id coexistent dans ce
# dépôt, et deux d'entre eux partagent leur mémoire :
#
#   navigateur   t=3, m=64 Mio, p=1   source : SelfDataGuard (`Primitives`)
#   serveur      t=4, m=64 Mio, p=2   source : SelfRecover (`Hashing::ARGON2`)
#   disque LUKS  t=3, m=64 Mio, p=4   source : SelfRecover-LUKS
#
# La valeur du navigateur est DÉFINIE par SelfDataGuard ; `client/sr-kdf.js` la
# reprend sans la définir, et `sanity_couplage_dataguard.php` tient l'égalité des
# dix valeurs partagées. Mais rien ne tenait la PHRASE qui nomme le propriétaire.
#
# Le 7 octobre 2026, `demo/lab/README.md` annonçait « Argon2id (m=64 Mo, t=4, p=2)
# pour tout » : vrai des seules empreintes serveur. Le 10 octobre, la page security
# du laboratoire annonçait encore « 64 Mio, le profil de SelfRecover » pour le
# chiffrement du navigateur, et les deux whitepapers disaient ce profil « écrit
# dans la bibliothèque » sans nommer laquelle. Le chiffre juste, la source fausse,
# à trois jours d'une campagne red team. Aucun banc, aucun des quatorze contrôles,
# aucun des vingt-sept canaris n'a rougi : ils tiennent l'égalité des nombres entre
# deux langages, pas la vérité de la phrase qui les attribue.
#
# ⚠️ **Ce que ça coûte quand c'est faux.** Un chercheur qui calibre son coût
# d'attaque sur `t=4, p=2` vise une porte qui n'existe pas ; et qui veut vérifier
# le profil va le chercher dans SelfRecover, où il n'est pas défini.
#
# 🔑 **Deux volets, et pourquoi pas un seul.** Une première version exigeait de
# TOUTE phrase énonçant ce profil qu'elle nomme sa source. Mesuré sur le dépôt :
# douze signalements, dont neuf faux — la section « pourquoi HMAC et pas Argon2 »
# d'un README, une règle de lissage nginx, un commentaire sur le coût d'une attaque
# Sybil. Juger toute prose automatiquement produit du bruit, et un contrôle qui
# crie partout cesse d'être lu. D'où deux volets étroits : une erreur **nommée**
# qui ne doit pas revenir, et une liste **explicite** de porteurs qui doivent dire
# la source — le motif de `check-versions.sh`, qui tient ses porteurs par une liste
# plutôt que par une heuristique.
#
# Usage  : bash scripts/check-source-profil.sh
# Sortie : 0 si aucune attribution fautive et si chaque porteur déclaré dit sa
#          source, 1 sinon.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 1

echec=0
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
ko() { printf '  \033[31m✗\033[0m %s\n' "$*"; echec=1; }

# ---------------------------------------------------------------------------
# 1. L'attribution fautive, nommée. Ces formulations attribuent à SelfRecover
#    seul un profil qu'il ne définit pas. Elles ont toutes été écrites pour de
#    bon : les deux premières sur la page security du laboratoire, les deux
#    suivantes dans les whitepapers.
#
# ⚠️ Le motif cherche la formulation, pas le voisinage d'un chiffre : « le profil
#    de SelfRecover » est faux partout où il désigne la dérivation du navigateur,
#    et il n'a aucun autre emploi légitime dans ce dépôt — SelfRecover a un profil,
#    celui de `Hashing::ARGON2`, que ses porteurs nomment par sa constante.
printf '\n▸ 1. Attributions fautives du profil du navigateur\n\n'

# ⚠️ « écrit dans la bibliothèque » a été essayé ici, puis retiré : la phrase est
#    légitime ailleurs — un jalon de `README.fr.md` dit « Argon2id écrit dans la
#    bibliothèque » pour signifier « dans la bibliothèque, pas dans la démo », et
#    nomme `client/sr-kdf.js` juste après. Les whitepapers, où elle servait bien
#    d'attribution, sont tenus par le volet 2 : ils sont porteurs déclarés.
FAUTIFS=(
    'le profil de SelfRecover'
    'the SelfRecover profile'
    'profil SelfRecover'
)
# 🔑 Lu sur le DISQUE, pas dans l'index. `git grep` aurait été plus court, mais il
#    lit ce qui est indexé : un fichier modifié et non ajouté lui est invisible.
#    C'est la limite que `check-profil-unique.sh` porte et que son en-tête
#    signale ; ici elle rendrait le canari muet — il plante dans un fichier, pas
#    dans l'index — et ferait passer un envoi dont l'arbre de travail est fautif.
#    `git ls-files` donne le périmètre, `grep` lit le contenu réel.
trouve_fautif=0
for motif in "${FAUTIFS[@]}"; do
    lignes=$(git ls-files -z -- '*.php' '*.md' '*.js' '*.txt' \
             | xargs -0 -r grep -nF "$motif" /dev/null 2>/dev/null \
             | grep -v '^scripts/check-source-profil.sh:')
    if [ -n "$lignes" ]; then
        while IFS= read -r l; do
            [ -n "$l" ] || continue
            trouve_fautif=1
            ko "$(printf '%s' "$l" | cut -d: -f1,2) — « $motif »"
        done <<< "$lignes"
    fi
done
[ "$trouve_fautif" -eq 0 ] && ok "aucune des ${#FAUTIFS[@]} formulations fautives connues"

# ---------------------------------------------------------------------------
# 2. Les porteurs qui DOIVENT nommer la source.
#
# Chacun décrit le chiffrement du navigateur à un lecteur — un chercheur, un
# auditeur, un intégrateur — et chacun a porté l'erreur ou son voisinage. La liste
# est explicite exprès : une heuristique sur la prose rendait neuf faux sur douze.
#
# ⚠️ Un porteur retiré de cette liste doit l'être pour une raison écrite. Un
# porteur dont le fichier disparaît fait échouer ce contrôle au lieu de le rendre
# muet : un contrôle qui perd sa cible en silence est le défaut qu'il mesure.
PORTEURS=(
    'demo/lab/lang/fr.php'
    'demo/lab/lang/en.php'
    'demo/lab/README.md'
    'demo/lab/docs/PENTEST-MISSION-ctf.md'
    'bi-self/selfrecover/docs/whitepaper-fr.md'
    'bi-self/selfrecover/docs/whitepaper-en.md'
    # Ajoutés par la conv Recover le 10/10 à 14:30, après c3455f6 : leur cellule du
    # tableau des rôles disait « Argon2id, 64 Mio » sans `t`, sans `p` et sans source,
    # donc elle attribuait par le contexte ce qu'elle n'affirmait pas. Éprouvé au canari :
    # l'énoncé d'avant replanté, ce contrôle rend 1.
    'bi-self/selfrecover/README.fr.md'
    'bi-self/selfrecover/README.md'
)
printf '\n▸ 2. Porteurs déclarés : chaque énoncé nomme-t-il SelfDataGuard ?\n\n'

for f in "${PORTEURS[@]}"; do
    if [ ! -r "$f" ]; then
        ko "$f — porteur déclaré introuvable : la liste de ce contrôle est périmée"
        continue
    fi
    resultat=$(FICHIER="$f" python3 -I - <<'PY'
import os, re

f = os.environ['FICHIER']
texte = open(f, encoding='utf-8', errors='replace').read()

# L'énoncé se juge dans sa fenêtre, pas dans son fichier. C'est la leçon du
# 10 octobre : `demo/lab/lang/fr.php` nommait la source à la ligne 62 et portait
# la même valeur sans source à la ligne 171. Une règle par fichier aurait rendu
# vert sur un fichier qui mentait en son milieu.
AMONT, AVAL = 240, 320
ARGON = re.compile(r"Argon2id", re.I)
MEM = re.compile(r"65536|67108864|64\D{0,2}(Mio|MiB|Mo|MB)", re.I)
# Le profil du navigateur, reconnu par son parallélisme : p=1. Le serveur porte
# p=2 ou `threads`, le disque p=4 — chacun a sa propre source, ailleurs.
P1 = re.compile(r"\bp\s*[=:]\s*1\b|parallelism\D{0,12}1\b", re.I)
SRC = re.compile(r"SelfDataGuard|Primitives", re.I)

for m in ARGON.finditer(texte):
    fen = re.sub(r"\s+", " ", texte[max(0, m.start() - AMONT):m.end() + AVAL])
    if not (MEM.search(fen) and P1.search(fen)):
        continue
    ligne = texte.count('\n', 0, m.start()) + 1
    print('%s %d %s' % ('SOURCE' if SRC.search(fen) else 'NUE', ligne, fen[:110]))
PY
    )
    if [ -z "$resultat" ]; then
        # Zéro énoncé dans un porteur déclaré : soit il a cessé de décrire ce
        # profil — alors il sort de la liste, par une décision écrite —, soit le
        # motif ne le voit plus. Les deux demandent un regard.
        ko "$f — aucun énoncé du profil du navigateur trouvé : porteur à retirer de la liste, ou motif à reprendre"
        continue
    fi
    while read -r etat ligne extrait; do
        [ -n "$etat" ] || continue
        case "$etat" in
            SOURCE) ok "$f:$ligne" ;;
            NUE)    ko "$f:$ligne — énoncé sans source : $extrait" ;;
        esac
    done <<< "$resultat"
done

# ---------------------------------------------------------------------------
printf '\n'
if [ "$echec" -eq 0 ]; then
    printf '\033[32m✓ aucune attribution fautive, et les %s porteurs déclarés nomment leur source.\033[0m\n\n' "${#PORTEURS[@]}"
    exit 0
fi
printf '\033[31m✗ voir ci-dessus.\033[0m\n'
printf '  Le chiffre peut être juste et la phrase fausse : cette valeur est définie\n'
printf '  par SelfDataGuard, SelfRecover la reprend. Qui veut la vérifier doit\n'
printf '  savoir où regarder.\n\n'
exit 1
