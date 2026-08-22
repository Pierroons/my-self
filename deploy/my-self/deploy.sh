#!/bin/bash
# Déploiement de l'arbre my-self vers une instance.
#
# 🔑 **Ce script existe parce qu'il n'existait pas.** Les domaines servis depuis
# la racine de l'instance étaient alimentés en tapant `rsync -a dépôt/ prod/` à
# la main. Deux défauts mesurés le 21/08/2026 viennent de là : un transfert a
# reposé une copie de catalogue vieille de douze jours par-dessus la fraîche, et
# le script de déploiement du seul module qui en avait un cherchait un gabarit
# de domaine que ses pages ne portaient plus — sans qu'aucun contrôle le voie.
#
# La racine de l'instance est un miroir du dépôt dans lequel le serveur web
# pointe à plusieurs profondeurs : la structure de l'arbre fait partie du
# contrat, on ne peut pas se contenter d'y déposer des fichiers choisis.
#
# ── Trois verbes ────────────────────────────────────────────────────────────
#
#   assembler   sur le poste : produit un arbre filtré et substitué, ou refuse
#   poser       sur le serveur, en root : installe cet arbre
#   verifier    partout : les contrôles HTTP seuls, sans root
#
# La partie qui décide — quoi inclure, sous quel nom — se fait sur le poste, où
# elle est vérifiable avant que rien ne bouge. Le serveur ne fait que poser un
# arbre déjà jugé bon. C'est aussi la seule répartition possible quand `sudo`
# demande un mot de passe à distance.
#
# Usage :
#   ./deploy.sh assembler [--dest <répertoire>]
#   sudo ./deploy.sh poser <répertoire> [--racine <cible>] [--appliquer]
#   ./deploy.sh verifier
#
# Sortie : 0 si tout s'est fait et vérifié, 1 sinon.

set -uo pipefail

ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPOT="$(cd "$ICI/../.." && pwd)"
DEST_DEFAUT="$ICI/dist"
RACINE_DEFAUT=/var/www/myself
# Surchargeables pour que le garde-fou puisse monter un serveur de contrefaçon :
# une sonde qui ne peut pas mentir au script ne peut rien éprouver de lui.
IMMUTABLE="${MYSELF_IMMUTABLE:-/usr/local/sbin/prod-immutable.sh}"
VHOSTS="${MYSELF_VHOSTS:-/etc/nginx/sites-enabled}"

# ── La table des noms de domaine ────────────────────────────────────────────
#
# 🔑 **Ce n'est pas une règle de suffixe.** `farm.example.org` devient
# `selffarm.my-self.fr`, pas `farm.my-self.fr` — ce dernier n'a pas de vhost, et
# une substitution mécanique poserait huit liens morts sur la page d'accueil.
# La table est donc écrite, et l'inventaire de ce que les sources portent
# vraiment la précède : `grep -rhoE '[a-z0-9.-]*\.example\.org'`.
#
# ⚠️ `your-instance.example` n'y figure pas et ne doit pas y figurer. Il
# apparaît dans `api/act/find.php` et `api/api.php` comme repli d'exécution
# quand `HTTP_HOST` est absent — un défaut, pas un gabarit. Y substituer le nom
# de l'instance le graverait dans le message d'erreur.
lire_table() {
    cat <<'TABLE'
justice.example.org    justice.my-self.fr
lab.example.org        lab.my-self.fr
bi-self.example.org    bi-self.my-self.fr
dataguard.example.org  dataguard.my-self.fr
farm.example.org       selffarm.my-self.fr
TABLE
}

# ── Ce qui ne part pas ──────────────────────────────────────────────────────
#
# Outillage, tests, documentation de développement, dépendances, et tout ce que
# la machine produit de son côté.
EXCLUS=(
    ".git" ".github" ".claude" ".ruff_cache" ".venv" "__pycache__" "node_modules"
    "tests" "docs" "deploy" "scripts" "tools" "mcp" "dist"
    # 🔑 Bacs à sable et copies locales de bases. Le premier assemblage réel les
    # a fait refuser par le filet de sécurité plus bas : `playground/` contient
    # du matériel cryptographique de développement — clés, scellés, base d'essai
    # — et `data/` une copie de 357 Mo d'un index. Rien de
    # tout cela n'est suivi par git — et c'est précisément le piège, car
    # `.gitignore` n'a jamais retenu un `rsync`.
    "/self-security/selfdataguard/playground/"
    "/self-right/selfjustice/data/"
    # 🔑 Rien de ce répertoire n'est suivi par git — ni `flags.txt`, ni
    # `prepare.php`, ni `gen-vault.cjs`. Ce sont les réponses du CTF et
    # l'outillage qui les pose. Déployées depuis un poste, elles changeraient les
    # drapeaux sous les joueurs d'une partie en cours.
    "/demo/lab/challenge/"
)

# ⚠️ **L'état de l'instance ne se déploie pas.** `storage/` porte ce que la
# démonstration a produit chez elle ; le poser depuis un poste écraserait des
# données vivantes par celles d'un développeur — la même erreur que le
# catalogue SelfAct, corrigée le 21/08/2026 en déplaçant le fichier. Ici le
# répertoire doit exister à la destination, mais son contenu lui appartient.
# 🔑 `demo/lab/data/` relève du même régime, et l'oubli s'est vu le 22/08/2026 :
# l'assemblage y prenait `.blindkey`, `.sitesalt` et `.serversecret`, que le code
# du lab décrit lui-même comme « propre à ce déploiement » (lib/auth.php). Les
# poser depuis un poste aurait remplacé le sel qui vérifie les mots de passe et
# la clé qui chiffre les coffres : plus personne ne se connecte, et ce qui était
# chiffré devient illisible. Le répertoire est nommé ici plutôt que dans EXCLUS
# parce que ce n'est pas de l'outillage — c'est de la donnée vivante.
#
# La leçon vaut au-delà de ces deux lignes : une exclusion nommée répertoire par
# répertoire se périme dès qu'un module naît. Les INTERDITS plus bas sont ce qui
# rattrape l'oubli suivant.
ETAT_INSTANCE=( "storage/*" "/demo/lab/data/*" )
EXCLUS_MOTIF=(
    "*.md" "*.pyc" "composer.json" "composer.lock" "package.json"
    "package-lock.json" ".gitignore" ".gitattributes" ".gitleaks.toml"
    ".editorconfig" "*.example" "catalog.json"
)

# ⚠️ Exception à l'exclusion des `*.md` : le serveur sert ce fichier par un
# `location` nommé. Une exclusion en bloc le ferait disparaître sans bruit — et
# le contrôle des références du serveur, plus bas, est ce qui l'a montré.
GARDES=(
    "self-right/selfjustice/api/act/directives.md"
    "demo/selfdataguard/storage/.gitkeep"
)

# ── Ce qui ne doit jamais franchir le filtre ────────────────────────────────
#
# La ceinture, en plus des bretelles : si un jour un secret entre au dépôt, il
# ne doit pas atteindre une racine servie au public par-dessus le marché.
# ⚠️ Les motifs sont des globs de nom de fichier : `*.key` ne capture PAS
# `.blindkey`, faute de point. Les trois secrets du lab sont donc nommés en
# entier — mesuré le 22/08/2026, où la ceinture avait un trou exactement là.
INTERDITS=( ".env" ".env.*" "*.key" "*.pem" "id_rsa*" "id_ed25519*" "*.sqlite" "*.p12"
    ".blindkey" ".sitesalt" ".serversecret" )

rouge() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
vert()  { printf '\033[32m%s\033[0m\n' "$*"; }

# ═══ assembler ══════════════════════════════════════════════════════════════

assembler() {
    local dest="$DEST_DEFAUT"
    while [ $# -gt 0 ]; do
        case "$1" in
            --dest) dest="$2"; shift 2 ;;
            *) rouge "argument inconnu : $1"; return 2 ;;
        esac
    done

    [ -d "$DEPOT/web" ] || { rouge "erreur : $DEPOT ne ressemble pas au dépôt my-self."; return 1; }

    echo "▸ Assemblage de $DEPOT vers $dest"
    rm -rf "$dest"
    mkdir -p "$dest"

    local exclusions=()
    for e in "${EXCLUS[@]}";        do exclusions+=(--exclude "$e"); done
    for m in "${EXCLUS_MOTIF[@]}";  do exclusions+=(--exclude "$m"); done
    for e in "${ETAT_INSTANCE[@]}"; do exclusions+=(--exclude "$e"); done
    # Les gardes passent AVANT les exclusions : rsync retient la première règle
    # qui correspond, donc un `--include` placé après ne rattraperait rien.
    local gardes=()
    for g in "${GARDES[@]}"; do gardes+=(--include "$g"); done

    rsync -a "${gardes[@]}" "${exclusions[@]}" "$DEPOT/" "$dest/" || {
        rouge "erreur : la copie a échoué."; return 1; }

    # Les répertoires vidés par le filtre ne servent à rien à la destination.
    find "$dest" -type d -empty -delete 2>/dev/null

    echo "  · $(find "$dest" -type f | wc -l) fichier(s) retenus"
    echo

    # ── Aucun fichier interdit ───────────────────────────────────────────────
    echo "▸ Aucun secret dans l'arbre assemblé"
    local trouves=0 f
    for motif in "${INTERDITS[@]}"; do
        while IFS= read -r f; do
            rouge "  ✗ $motif : ${f#"$dest"/}"
            trouves=$((trouves + 1))
        done < <(find "$dest" -name "$motif" -type f 2>/dev/null)
    done
    if [ "$trouves" -gt 0 ]; then
        rouge "  Arrêt : $trouves fichier(s) interdit(s). L'arbre n'est pas publiable."
        rm -rf "$dest"
        return 1
    fi
    echo "  ✓ aucun des ${#INTERDITS[@]} motifs interdits"
    echo

    # ── Les gardes ont bien survécu ──────────────────────────────────────────
    echo "▸ Les fichiers gardés malgré leur extension"
    for g in "${GARDES[@]}"; do
        if [ -f "$dest/$g" ]; then
            echo "  ✓ $g"
        else
            rouge "  ✗ $g a été filtré alors qu'il est servi — arrêt"
            rm -rf "$dest"
            return 1
        fi
    done
    echo

    # ── La substitution des noms de domaine ──────────────────────────────────
    #
    # 🔑 Un gabarit sans règle ARRÊTE l'assemblage. C'est la seule forme qui
    # ferme la classe entière : un module neuf qui introduit un sous-domaine ne
    # peut pas expédier un lien mort en silence — il faut l'inscrire à la table,
    # donc le décider.
    echo "▸ Noms de domaine"
    local connus=() domaines=() gabarit domaine
    while read -r gabarit domaine; do
        [ -z "$gabarit" ] && continue
        connus+=("$gabarit"); domaines+=("$domaine")
    done < <(lire_table)

    local inconnus
    inconnus=$(grep -rhoE "[a-z0-9-]+\.example\.(org|com|net)" "$dest" 2>/dev/null \
               | sort -u | grep -vxF "$(printf '%s\n' "${connus[@]}")" || true)
    if [ -n "$inconnus" ]; then
        rouge "  ✗ gabarit(s) sans règle dans la table :"
        printf '      %s\n' $inconnus >&2
        rouge "  Arrêt : inscrire chaque gabarit à la table, avec le domaine qu'il désigne."
        rm -rf "$dest"
        return 1
    fi

    local total=0 i n
    for i in "${!connus[@]}"; do
        gabarit="${connus[$i]}"; domaine="${domaines[$i]}"
        n=$(grep -rlF "$gabarit" "$dest" 2>/dev/null | wc -l)
        [ "$n" -eq 0 ] && continue
        grep -rlF "$gabarit" "$dest" 2>/dev/null \
            | while IFS= read -r f; do sed -i "s|$gabarit|$domaine|g" "$f"; done
        printf "  ✓ %-22s → %-22s %s fichier(s)\n" "$gabarit" "$domaine" "$n"
        total=$((total + n))
    done

    # Zéro substitution ne peut pas être un cas nominal : les pages nomment leur
    # instance. C'est exactement le défaut du 21/08 sur le module SelfJustice.
    if [ "$total" -eq 0 ]; then
        rouge "  ✗ aucun gabarit substitué — les pages ne nomment aucune instance."
        rouge "    La table a-t-elle divergé de ce que les sources portent ?"
        rm -rf "$dest"
        return 1
    fi

    local restants
    restants=$(grep -rhoE "[a-z0-9-]+\.example\.(org|com|net)" "$dest" 2>/dev/null | sort -u || true)
    if [ -n "$restants" ]; then
        rouge "  ✗ gabarit(s) survivant(s) : $restants — arrêt"
        rm -rf "$dest"
        return 1
    fi
    echo "  ✓ aucun gabarit ne survit"
    echo

    # `your-instance.example` doit traverser intact : c'est un repli
    # d'exécution, pas un nom d'instance.
    local repli
    repli=$(grep -rlF "your-instance.example" "$dest" 2>/dev/null | wc -l)
    echo "  · repli d'exécution « your-instance.example » conservé dans $repli fichier(s)"
    echo

    vert "Arbre assemblé : $dest"
    echo "  Le poser : sudo bash $ICI/deploy.sh poser $dest --appliquer"
    return 0
}

# ═══ poser ══════════════════════════════════════════════════════════════════

# 🔑 Le contrôle qui n'existait nulle part : le serveur nomme les chemins dont
# il a besoin, et le déploiement prouve qu'il les apporte. Un fichier renommé,
# déplacé ou filtré par erreur se voit ici, avant la pose — au lieu de se voir
# en 404 sur la page d'un visiteur.
references_serveur() { # references_serveur <racine>
    local racine="$1"
    [ -d "$VHOSTS" ] || return 0
    # Les accolades ne sont pas décoratives : sans elles, `$racine[` se lit comme
    # une indexation de tableau et l'expression ne cherche plus rien.
    grep -rhoE "${racine}[A-Za-z0-9._/\$-]*" "$VHOSTS"/*.conf 2>/dev/null | sort -u
}

poser() {
    local source="${1:-}"; shift || true
    local racine="$RACINE_DEFAUT" appliquer=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --racine) racine="$2"; shift 2 ;;
            --appliquer) appliquer=1; shift ;;
            *) rouge "argument inconnu : $1"; return 2 ;;
        esac
    done

    [ -n "$source" ] && [ -d "$source" ] || { rouge "usage : poser <répertoire assemblé>"; return 2; }
    [ -d "$racine" ] || { rouge "erreur : racine introuvable — $racine"; return 1; }
    [ -n "$(find "$source" -type f -print -quit)" ] || {
        rouge "erreur : $source est vide. Assembler d'abord."; return 1; }

    # ── Ce que le serveur exige ──────────────────────────────────────────────
    echo "▸ Ce que le serveur nomme, l'arbre l'apporte-t-il ?"
    local manquants=0 chemin relatif cible
    while IFS= read -r chemin; do
        [ -z "$chemin" ] && continue
        relatif="${chemin#"$racine"/}"
        [ "$relatif" = "$chemin" ] && relatif=""
        # Un chemin qui porte une variable du serveur ne se résout qu'à
        # l'exécution : on contrôle son répertoire.
        case "$relatif" in *'$'*) relatif="$(dirname "$relatif")" ;; esac
        [ -z "$relatif" ] && continue
        cible="$source/$relatif"
        if [ -e "$cible" ]; then
            printf "  ✓ %s\n" "$relatif"
        else
            rouge "  ✗ $relatif — nommé par un vhost, absent de l'arbre"
            manquants=$((manquants + 1))
        fi
    done < <(references_serveur "$racine")
    if [ "$manquants" -gt 0 ]; then
        rouge "  Arrêt : $manquants référence(s) orpheline(s). Rien n'a été posé."
        return 1
    fi
    echo

    # ── Ce qui survit à la destination ───────────────────────────────────────
    #
    # Signalé, jamais supprimé : effacer un fichier servi au public est une
    # décision humaine, et ce script ne sait pas si l'orphelin est un résidu ou
    # un chemin que quelqu'un a délibérément posé là.
    # 🔑 Groupés par répertoire, et les joignables d'abord. La première version
    # en listait cent trente-neuf, un par ligne : un avertissement qui défile
    # n'est pas lu, et les deux seuls qui comptaient — des README servis en 200
    # sur le site public, dont un nommant un login et un chemin disque — étaient
    # noyés au milieu de la documentation et des tests.
    echo "▸ Ce qui est à la destination sans venir de la source"
    local orphelins=0 joignables=()
    # ⚠️ Le fichier de travail va dans /tmp, JAMAIS dans la destination : le
    # mode plan ne doit rien y écrire, et une première version l'y déposait —
    # le cas « le plan n'écrit pas » l'a vu tout de suite.
    local liste; liste=$(mktemp)

    # Les racines servies, en chemins relatifs : un orphelin qui tombe dessous
    # est atteignable depuis l'extérieur, les autres ne sont qu'encombrement.
    local racines_servies=() r rel
    while IFS= read -r r; do
        case "$r" in *'$'*) continue ;; esac      # variable du serveur, pas un chemin
        rel="${r#"$racine"/}"
        [ "$rel" = "$r" ] && continue
        [ -d "$racine/$rel" ] && racines_servies+=("$rel")
    done < <(references_serveur "$racine")

    while IFS= read -r relatif; do
        [ -e "$source/$relatif" ] && continue
        case "$relatif" in
            */data/*|*/storage/*|*.avant-*|*.bak) continue ;;
        esac
        echo "$relatif" >> "$liste"
        orphelins=$((orphelins + 1))
        for rel in "${racines_servies[@]}"; do
            case "$relatif" in "$rel"/*) joignables+=("$relatif"); break ;; esac
        done
    done < <(cd "$racine" && find . -type f | sed 's|^\./||' | sort)

    if [ "$orphelins" -eq 0 ]; then
        echo "  ✓ aucun"
    else
        awk -F/ '{ d = (NF > 1) ? substr($0, 1, length($0) - length($NF) - 1) : "(racine)"; n[d]++ }
                 END { for (d in n) printf "  ⚠️  %-52s %s fichier(s)\n", d, n[d] }' "$liste" \
            | sort >&2
        echo "  · $orphelins conservé(s), jamais supprimés — liste : $liste" >&2
        if [ ${#joignables[@]} -gt 0 ]; then
            echo "  ⚠️  dont ${#joignables[@]} SOUS UNE RACINE SERVIE, donc atteignable de l'extérieur :" >&2
            printf '        %s\n' "${joignables[@]}" >&2
        fi
    fi
    echo

    local n; n=$(find "$source" -type f | wc -l)
    if [ "$appliquer" -eq 0 ]; then
        vert "Plan seulement : $n fichier(s) seraient posés dans $racine."
        echo "  Pour l'appliquer, relancer avec --appliquer."
        return 0
    fi

    [ "$(id -u)" -eq 0 ] || { rouge "erreur : --appliquer demande root."; return 1; }

    echo "▸ Pose de $n fichier(s)"
    local deverrouille=0
    if [ -x "$IMMUTABLE" ] && "$IMMUTABLE" unlock >/dev/null 2>&1; then
        deverrouille=1
        trap '"$IMMUTABLE" lock >/dev/null 2>&1 || rouge "⚠️  ÉCHEC DU REVERROUILLAGE — lancer : sudo prod-immutable.sh lock"' EXIT
    fi

    local code=0
    rsync -a --no-perms --no-owner --no-group "$source/" "$racine/" || code=1
    chown -R www-data:www-data "$racine" 2>/dev/null || true

    if [ "$deverrouille" -eq 1 ]; then
        trap - EXIT
        "$IMMUTABLE" lock >/dev/null 2>&1 \
            && echo "  ✓ immutabilité reposée" \
            || rouge "  ⚠️  ÉCHEC DU REVERROUILLAGE — lancer : sudo prod-immutable.sh lock"
    fi
    [ "$code" -eq 0 ] || { rouge "  ✗ la pose a échoué."; return 1; }
    vert "  ✓ posé"
    return 0
}

# ═══ verifier ═══════════════════════════════════════════════════════════════

# 🔑 Les adresses contrôlées sont DÉDUITES de la table, pas recopiées à côté
# d'elle : un module qu'on inscrit pour la substitution entre du même geste dans
# le contrôle. Une seconde liste écrite à la main finirait par ne plus décrire
# la première — c'est le défaut que ce script tout entier existe pour empêcher.
# ── Ce que chaque domaine doit répondre ─────────────────────────────────────
#
# 🔑 **Un domaine protégé n'est pas un domaine en panne.** `lab` est derrière une
# authentification Basic : il répond 401, et c'est son bon fonctionnement. Tant
# que la sonde exigeait 200 partout, elle sortait en échec à chaque pose — donc
# pour toujours, sur un défaut qui n'existait pas. Une alarme qui hurle sans
# discontinuer cesse d'être lue, et le jour où un vrai service tombe, son rouge
# ressemble au rouge de la veille.
#
# Attendre le 401 fait mieux que taire le bruit : la sonde éprouve désormais la
# protection elle-même. Si `lab` répondait 200, c'est CELA qu'il faudrait
# signaler — la Basic Auth aurait sauté.
lire_codes() {
    cat <<'CODES'
lab.my-self.fr    401
CODES
}

code_attendu() { # code_attendu <hôte> → le code HTTP attendu, 200 par défaut
    local hote code
    while read -r hote code; do
        [ "$hote" = "$1" ] && { echo "$code"; return; }
    done < <(lire_codes)
    echo 200
}

# Surchargeable : un garde-fou ne peut éprouver cette fonction qu'en lui mentant
# sur ce que le réseau répond.
sonde_http() { # sonde_http <url> → écrit le code HTTP obtenu
    # Un frontal peut se tenir devant ces domaines : sans anti-cache, une
    # vérification lancée juste après une pose lit l'état d'avant.
    curl -s -o /dev/null -w "%{http_code}" -H "Cache-Control: no-cache" \
         --max-time 15 "$1?verif=$RANDOM"
}

verifier() {
    local souci=0 code attendu u domaine hote adresses=("https://my-self.fr/")
    local sonde="${MYSELF_SONDE:-sonde_http}"
    while read -r _ domaine; do
        [ -n "$domaine" ] && adresses+=("https://$domaine/")
    done < <(lire_table)
    # Le fichier que le serveur sert par un `location` nommé, et que son
    # extension aurait fait filtrer.
    adresses+=("https://justice.my-self.fr/act/api/directives.md")

    echo "▸ Ce que chaque domaine doit répondre"
    for u in "${adresses[@]}"; do
        hote="${u#https://}"; hote="${hote%%/*}"
        attendu=$(code_attendu "$hote")
        code=$("$sonde" "$u")
        if [ "$code" != "$attendu" ]; then
            printf "  ✗  %-48s %s (attendu %s)\n" "$u" "$code" "$attendu"; souci=1
        elif [ "$attendu" = 200 ]; then
            printf "  ✓  %-48s %s\n" "$u" "$code"
        else
            printf "  ✓  %-48s %s — protégé, c'est l'attendu\n" "$u" "$code"
        fi
    done
    return $souci
}

# ═══ ═══════════════════════════════════════════════════════════════════════

case "${1:-}" in
    assembler) shift; assembler "$@" ;;
    poser)     shift; poser "$@" ;;
    verifier)  shift; verifier "$@" ;;
    *)
        sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
        exit 2 ;;
esac
