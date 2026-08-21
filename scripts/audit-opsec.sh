#!/usr/bin/env bash
# audit-opsec.sh — les quatre angles morts de gitleaks.
#
# gitleaks cherche des SECRETS (clés, tokens, mots de passe) et le fait bien.
# Il ne cherche pas les données PERSONNELLES, et surtout il ne regarde pas :
#
#   1. le contenu de l'historique complet   (il scanne, mais sans motifs perso)
#   2. les messages de commit                — aucun scanner ne les lit
#   3. les fichiers disparus du HEAD         — ils dorment dans l'historique
#   4. les métadonnées de documents          — DOCX et PDF portent un auteur
#
# Ce sont exactement les quatre endroits où dormaient les fuites de l'audit
# du 28 juillet 2026 : un username système dans six fichiers d'historique, un
# brief privé orphelin, un code postal publié par le message qui le masquait,
# et un nom de domaine dans un corps de commit.
#
# ── Quatre modes, et pourquoi le plus précoce est le plus important ─────────
#
#   --worktree  ce qui est écrit sur le disque, pas encore indexé. Aucun hook
#               ne l'appelle : c'est le mode de la relecture avant commit.
#   --staged    fichiers indexés seulement. Appelé par le pre-commit.
#   --message F motifs dans le fichier de message. Appelé par le commit-msg.
#   (aucun)     audit complet — tout l'historique. Appelé par le pre-push.
#
# --worktree existe parce qu'un fichier écrit mais pas encore ajouté n'était vu
# par AUCUN des trois autres. Un agent de relecture a dû appliquer les motifs à
# la main sur onze fichiers le 14/08/2026 : ce qui marche une fois et ne marche
# plus le jour où personne n'y pense. Un contrôle qui dépend de la vigilance de
# celui qui le lance n'est pas un contrôle.
#
# L'écart de coût entre ces modes est le vrai sujet. Une donnée arrêtée au
# worktree se corrige d'un coup d'éditeur — elle n'est même pas indexée. Une
# donnée arrêtée au pre-commit se corrige en dix secondes : le fichier n'est
# même pas commité. La même donnée arrêtée au pre-push est déjà dans l'historique
# local — il faut un rebase. Et si elle est passée, il faut git-filter-repo,
# re-signer les tags, force-pusher, purger le registre d'images et demander
# à GitHub de collecter les objets orphelins. Le rapport est de un à mille.
#
# ── Où sont les motifs ──────────────────────────────────────────────────────
#
# Volontairement PAS dans ce fichier. Un script versionné dans un dépôt public
# qui listerait les noms, communes et adresses à traquer publierait exactement
# ce qu'il protège. Ce n'est pas une hypothèse : une allowlist gitleaks de ce
# projet a fini par contenir une adresse réelle, écrite là pour l'exclure.
#
#     ~/.config/selfopsec/patterns.txt      un motif par ligne, # = commentaire
#
# Le fichier est hors dépôt et partagé par tous les projets : une seule liste
# à tenir, et elle protège chaque dépôt le jour où on l'enrichit.
#
# ── Le bruit ────────────────────────────────────────────────────────────────
#
# Un audit qui crache toujours des alertes finit lu en diagonale — et c'est
# là qu'on rate la vraie. Chaque faux positif se qualifie UNE fois, dans
# scripts/opsec-allowlist.txt, avec sa raison écrite à côté.
#
# Sortie :  0 = rien à signaler · 1 = au moins une trouvaille · 2 = mal configuré

set -uo pipefail

ROOT="$(git rev-parse --show-toplevel)"
PATTERNS="${SELFOPSEC_PATTERNS:-$HOME/.config/selfopsec/patterns.txt}"
ALLOWLIST="$ROOT/scripts/opsec-allowlist.txt"

MODE="full"
MSGFILE=""
RANGE=""
VERBOSE=0
case "${1:-}" in
  --staged)   MODE="staged" ;;
  --worktree) MODE="worktree" ;;
  --message)  MODE="message"; MSGFILE="${2:-}" ;;
  --verbose)  VERBOSE=1 ;;
  # Borne les contrôles d'historique (2, 4, 5) aux commits d'une plage, au lieu
  # de tout le dépôt. Sans lui, le pre-push rougissait sur un passé DÉJÀ publié,
  # qu'aucun envoi ne peut ni aggraver ni corriger : l'audit bloquait chaque
  # envoi jusqu'à réécriture de l'historique, donc on le contournait.
  --range)    RANGE="${2:-}"
              [ -n "$RANGE" ] || { printf '\033[31m✗ --range attend une plage (ex. @{u}..HEAD)\033[0m\n' >&2; exit 2; } ;;
  "")         ;;
  # Sans ce refus, une faute de frappe tombait en mode complet et rendait un
  # vert : « ✓ Rien à signaler » sur un audit qui n'était pas celui demandé.
  # Mesuré le 14/08/2026 avec --worktree avant qu'il existe.
  # printf et non red() : les fonctions d'affichage sont définies plus bas.
  *)          printf '\033[31m✗ Argument inconnu : %s\033[0m\n' "$1" >&2
              echo "  Modes : --worktree | --staged | --message <fichier> | --range <plage> | (aucun)" >&2
              exit 2 ;;
esac

# Ce qui sépare vraiment les modes n'est pas leur nom : c'est de travailler sur
# une LISTE DE FICHIERS ou sur l'HISTORIQUE. Les contrôles 4 et 5 (messages,
# orphelins) n'ont de sens que dans le second cas.
case "$MODE" in staged|worktree) PAR_CIBLES=1 ;; *) PAR_CIBLES=0 ;; esac

# D'où vient le contenu d'une cible. En mode staged c'est l'index — ce qui est
# sur le disque n'y est pas forcément. Partout ailleurs, le disque.
contenu() {
  if [ "$MODE" = "staged" ]; then
    git -C "$ROOT" show ":$1" 2>/dev/null
  else
    cat "$ROOT/$1" 2>/dev/null
  fi
}

# Une cible vit dans l'index en mode staged, sur le disque ailleurs.
lisible() {
  if [ "$MODE" = "staged" ]; then
    git -C "$ROOT" cat-file -e ":$1" 2>/dev/null
  else
    [ -r "$ROOT/$1" ]
  fi
}

FOUND=0
red()  { printf '\033[31m%s\033[0m\n' "$1"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '  \033[31m✗\033[0m %s\n' "$1"; FOUND=1; }

# grep rend 0 s'il trouve, 1 s'il ne trouve pas, et 2 ou plus si le motif est
# invalide ou la lecture impossible. Tester « rc != 0 » confond donc « rien
# trouvé » avec « pas pu chercher », et un motif refusé rend le même vert qu'un
# dépôt propre. Une raison sociale avec une parenthèse, un nom terminé par une
# barre inverse, un crochet ouvert : trois formes qui font sortir grep en 2.
cherche() {                       # cherche <motif> ; lit stdin ; 0 = trouvé
  local m="$1" rc
  grep -qi -e "$m"; rc=$?
  [ "$rc" -le 1 ] && return "$rc"
  echo >&2
  red "✗ Motif inutilisable : « $m » — grep sort en $rc." >&2
  echo "  Un motif que grep refuse ne protège rien. Corrige $PATTERNS." >&2
  exit 2
}

# ── Motifs ──────────────────────────────────────────────────────────────────
if [ ! -f "$PATTERNS" ]; then
  red "✗ Fichier de motifs introuvable : $PATTERNS"
  echo
  echo "  Crée-le avec un motif par ligne (noms, communes, IP internes,"
  echo "  usernames système, domaines privés). Il ne doit JAMAIS être versionné."
  echo
  echo "    mkdir -p \"$(dirname "$PATTERNS")\" && \$EDITOR \"$PATTERNS\""
  exit 2
fi
mapfile -t MOTIFS < <(grep -vE '^\s*(#|$)' "$PATTERNS")
[ ${#MOTIFS[@]} -eq 0 ] && { red "✗ Aucun motif dans $PATTERNS"; exit 2; }

# ── Allowlist : chemins écartés du grep de contenu ──────────────────────────
EXCLUDES=()
if [ -f "$ALLOWLIST" ]; then
  while IFS=$'\t' read -r glob _raison; do
    [ -z "${glob:-}" ] && continue
    case "$glob" in \#*) continue ;; esac
    EXCLUDES+=("$glob")
  done < <(grep -vE '^\s*(#|$)' "$ALLOWLIST")
fi
exclu() {
  local f="$1"
  for e in "${EXCLUDES[@]:-}"; do
    [ -n "$e" ] && [[ "$f" =~ $e ]] && return 0
  done
  return 1
}

# Une valeur qui porte un chemin absolu ou des coordonnées est une fuite quoi
# qu'il arrive, même si aucun motif connu n'y figure.
suspecte() {
  printf '%s' "$1" | grep -qE '(/home/|/Users/|[A-Z]:\\|[0-9]+ deg [0-9]+)' && return 0
  local m
  for m in "${MOTIFS[@]}"; do
    printf '%s' "$1" | cherche "$m" && return 0
  done
  return 1
}

# ════════════════════════════════════════════════════════════════════════════
# MODE --message : le fichier de message, avant qu'il devienne un commit
# ════════════════════════════════════════════════════════════════════════════
# C'est le contrôle le plus rentable du lot, parce que le message est le seul
# endroit qu'AUCUN outil ne scanne — et le seul où l'on écrit spontanément ce
# qu'on vient de masquer. « retire le nom X de la démo » publie X pour de bon.
if [ "$MODE" = "message" ]; then
  [ -f "$MSGFILE" ] || exit 0
  # Ignorer les lignes de commentaire que git ajoute lui-même.
  CORPS=$(grep -v '^#' "$MSGFILE" 2>/dev/null || true)
  for m in "${MOTIFS[@]}"; do
    if printf '%s' "$CORPS" | cherche "$m"; then
      echo ""
      red "❌ COMMIT BLOQUÉ — le message contient « $m »."
      printf '%s' "$CORPS" | grep -i -e "$m" | head -3 | sed 's/^/     /'
      echo ""
      echo "   Décris ce que tu as FAIT, jamais ce que tu as RETIRÉ."
      echo "   Aucun outil ne scanne les messages de commit, et ils survivent"
      echo "   à toutes les corrections de contenu."
      exit 1
    fi
  done
  exit 0
fi

# ════════════════════════════════════════════════════════════════════════════
# Périmètre des modes full et staged
# ════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "staged" ]; then
  echo "▸ Audit OPSEC (fichiers indexés) — ${#MOTIFS[@]} motifs"
  mapfile -t CIBLES < <(git -c core.quotepath=false -C "$ROOT" diff --cached --name-only --diff-filter=ACMR)
  [ ${#CIBLES[@]} -eq 0 ] && { ok "rien d'indexé"; exit 0; }
elif [ "$MODE" = "worktree" ]; then
  echo "▸ Audit OPSEC (travail en cours) — ${#MOTIFS[@]} motifs"
  # Tout ce qui n'est pas encore poussé : modifié, indexé, ou jamais tracké.
  # Un fichier peut figurer dans deux listes — un ajout partiel diffère de sa
  # version disque — d'où le sort -u.
  mapfile -t CIBLES < <(
    {
      git -c core.quotepath=false -C "$ROOT" diff --name-only --diff-filter=ACMR
      git -c core.quotepath=false -C "$ROOT" diff --cached --name-only --diff-filter=ACMR
      git -c core.quotepath=false -C "$ROOT" ls-files --others --exclude-standard
    } | sort -u | grep .
  )
  # Un working tree propre n'est pas un audit réussi : c'est un audit sans
  # objet. Le dire, plutôt que rendre un vert obtenu sur une liste vide.
  [ ${#CIBLES[@]} -eq 0 ] && { ok "aucun fichier modifié ni intracké — rien à auditer"; exit 0; }
else
  echo "▸ Audit OPSEC — ${#MOTIFS[@]} motifs, ${#EXCLUDES[@]} exclusions"
fi
echo

# ── 1. Secrets ──────────────────────────────────────────────────────────────
echo "1. Secrets (gitleaks)"
if ! command -v gitleaks >/dev/null 2>&1; then
  warn "gitleaks absent (le paquet Debian est figé sur une version ancienne"
  echo "      qui n'interprète pas les allowlists pareil — prends le binaire"
  echo "      officiel : https://github.com/gitleaks/gitleaks/releases)"
elif [ "$MODE" = "staged" ]; then
  if gitleaks protect --staged --source "$ROOT" -c "$ROOT/.gitleaks.toml" \
       --no-banner --redact >/dev/null 2>&1; then
    ok "aucun secret dans l'index"
  else
    warn "secret dans l'index — détail : gitleaks protect --staged -v"
  fi
elif [ "$MODE" = "worktree" ]; then
  # `protect --staged` ne lit que l'index : sur un fichier jamais indexé il
  # rendrait un vert alors qu'il n'a rien regardé — l'alarme morte, dans le
  # script écrit pour la traquer. `detect --no-git` est le seul mode qui
  # regarde un fichier tel qu'il est sur le disque, d'où le passage un par un.
  SEC=0
  for f in "${CIBLES[@]}"; do
    [ -f "$ROOT/$f" ] || continue
    gitleaks detect --no-git --source "$ROOT/$f" -c "$ROOT/.gitleaks.toml" \
      --no-banner --redact >/dev/null 2>&1 || { warn "$f — secret détecté"; SEC=1; }
  done
  [ "$SEC" = "0" ] && ok "aucun secret dans les ${#CIBLES[@]} fichier(s) en cours"
else
  if gitleaks detect --source "$ROOT" -c "$ROOT/.gitleaks.toml" \
       --no-banner --redact >/dev/null 2>&1; then
    ok "aucun secret"
  else
    warn "secret détecté — détail : gitleaks detect -v"
  fi
fi

# ── 2. Données personnelles dans le contenu ────────────────────────────────
echo
if [ "$PAR_CIBLES" = "1" ]; then
  if [ "$MODE" = "staged" ]; then
    echo "2. Données personnelles — contenu indexé"
  else
    echo "2. Données personnelles — contenu sur le disque"
  fi
  C2=0
  EXAMINES=0
  for f in "${CIBLES[@]}"; do
    exclu "$f" && continue
    # Un fichier qu'on ne peut pas ouvrir n'est pas un fichier propre. Sans ce
    # test, une cible illisible tombait dans le `continue` du binaire et
    # rejoignait le compte des examinés sans avoir été lue une seule fois.
    if ! lisible "$f"; then
      warn "$f — illisible, NON audité"
      C2=1
      continue
    fi
    # Binaire : le grep n'a pas de sens, le contrôle 3 s'en charge.
    contenu "$f" | grep -qI . || continue
    EXAMINES=$((EXAMINES + 1))
    for m in "${MOTIFS[@]}"; do
      if contenu "$f" | cherche "$m"; then
        warn "$f — contient « $m »"
        contenu "$f" | grep -in -e "$m" | head -2 | sed 's/^/       /'
        C2=1
        break
      fi
    done
  done
  # Le compte est celui des lectures réussies, jamais celui de la liste.
  [ "$C2" = "0" ] && ok "aucun motif dans les $EXAMINES fichier(s) réellement lus"
else
  # On scanne TOUS les commits, pas le HEAD : corriger un fichier ne retire
  # pas ce qu'il contenait hier. C'est ce qui distingue ce contrôle de gitleaks.
  if [ -n "$RANGE" ]; then
    echo "2. Données personnelles — contenu des commits à publier ($RANGE)"
    mapfile -t REVS < <(git -C "$ROOT" rev-list "$RANGE")
  else
    echo "2. Données personnelles — contenu de l'historique complet"
    mapfile -t REVS < <(git -C "$ROOT" rev-list --all)
  fi
  # Une plage vide ne se mesure pas : le dire, plutôt que rendre un vert que
  # rien ne distingue d'un vert obtenu sur des commits réellement lus.
  if [ "${#REVS[@]}" = "0" ]; then
    ok "aucun commit dans le périmètre — rien n'a été lu"
    REVS=()
  fi
  C2=0
  for m in "${MOTIFS[@]}"; do
    [ "${#REVS[@]}" = "0" ] && break
    hits=$(git -C "$ROOT" grep -Iil -e "$m" "${REVS[@]}" | cut -d: -f2- | sort -u)
    rc=${PIPESTATUS[0]}
    if [ "$rc" -gt 1 ]; then
      echo
      red "✗ Motif inutilisable : « $m » — git grep sort en $rc."
      echo "  Un motif que git grep refuse ne protège rien. Corrige $PATTERNS." >&2
      exit 2
    fi
    [ -z "$hits" ] && continue
    reste=""
    while IFS= read -r f; do
      [ -z "$f" ] && continue
      exclu "$f" || reste="${reste}${f}"$'\n'
    done <<< "$hits"
    n=$(printf '%s' "$reste" | grep -c . || true)
    [ "${n:-0}" != "0" ] && { warn "« $m » — $n fichier(s)"; C2=1; }
  done
  # Muet si la plage est vide : le « rien n'a été lu » plus haut suffit, et
  # deux verts de suite dont l'un affirme plus que l'autre finissent mal lus.
  [ "$C2" = "0" ] && [ "${#REVS[@]}" != "0" ] && ok "aucun motif dans le contenu"
fi

# ── 3. Métadonnées des binaires ────────────────────────────────────────────
# Un PNG porte parfois un nom d'utilisateur ou des coordonnées GPS. Un DOCX
# porte un auteur et une société. Aucun scanner de secrets ne les lit.
echo
echo "3. Métadonnées des fichiers binaires"
if ! command -v exiftool >/dev/null 2>&1; then
  warn "exiftool absent — sudo apt install libimage-exiftool-perl"
else
  if [ "$PAR_CIBLES" = "1" ]; then
    mapfile -t BINS < <(printf '%s\n' "${CIBLES[@]}" | grep -iE '\.(png|jpe?g|webp|tiff?|pdf|docx|xlsx|odt)$' || true)
  else
    mapfile -t BINS < <(git -C "$ROOT" ls-files -- \
      '*.png' '*.jpg' '*.jpeg' '*.webp' '*.tif' '*.tiff' \
      '*.pdf' '*.docx' '*.xlsx' '*.odt' 2>/dev/null)
  fi
  META=""
  for f in "${BINS[@]:-}"; do
    [ -n "${f:-}" ] || continue
    [ -f "$ROOT/$f" ] || continue
    brut=$(exiftool -s -S -Artist -Creator -Author -LastModifiedBy -Company \
             -Software -Comment -UserComment -XPAuthor -Copyright \
             -GPSPosition -HostComputer -OwnerName -SerialNumber \
             "$ROOT/$f" 2>/dev/null | grep -v '^$' || true)
    while IFS= read -r ligne; do
      [ -z "$ligne" ] && continue
      suspecte "$ligne" && META="${META}  $f : ${ligne}"$'\n'
    done <<< "$brut"
  done
  if [ -n "$META" ]; then
    warn "métadonnées identifiantes :"
    printf '%s' "$META" | sed 's/^/     /'
    echo "       Purge : exiftool -all= <fichier>"
  else
    ok "aucune métadonnée identifiante"
  fi
fi

# ── 4 et 5 : historique seulement ──────────────────────────────────────────
if [ "$PAR_CIBLES" = "0" ]; then
  echo
  if [ -n "$RANGE" ]; then
    echo "4. Messages de commit à publier ($RANGE)"
    MESSAGES=$(git -C "$ROOT" log "$RANGE" --format='%s%n%b')
  else
    echo "4. Messages de commit (sujets et corps)"
    MESSAGES=$(git -C "$ROOT" log --all --format='%s%n%b')
  fi
  C4=0
  for m in "${MOTIFS[@]}"; do
    n=$(printf '%s' "$MESSAGES" | grep -ic -e "$m"); rc=$?
    if [ "$rc" -gt 1 ]; then
      echo
      red "✗ Motif inutilisable : « $m » — grep sort en $rc."
      echo "  Un motif que grep refuse ne protège rien. Corrige $PATTERNS." >&2
      exit 2
    fi
    if [ "${n:-0}" != "0" ]; then
      warn "« $m » — $n ligne(s)"
      C4=1
      [ "$VERBOSE" = "1" ] && printf '%s' "$MESSAGES" | grep -i -e "$m" | head -3 | sed 's/^/       /'
    fi
  done
  [ "$C4" = "0" ] && ok "aucun motif dans les messages"

  # Le filtre le plus utile de tous : c'est là que dorment les documents de
  # travail, briefs privés et captures qu'on a « rangés » d'un git rm.
  echo
  # Le périmètre de ce contrôle suit --range comme les contrôles 2 et 4. Sans
  # lui, le pre-push rougissait sur des blobs publiés depuis des mois, qu'aucun
  # envoi ne peut ni aggraver ni corriger : il bloquait TOUT jusqu'à la
  # réécriture de l'historique, donc il se contournait — et un garde-fou qu'on
  # contourne par habitude ne garde plus rien. Le commentaire de --range
  # annonçait déjà ce bornage ; le code ne l'appliquait pas (mesuré le 21/08).
  #
  # Borné, il attrape ce qu'un envoi AJOUTE vraiment : un fichier sali puis
  # supprimé dans la même série de commits laisse son blob dans l'historique
  # poussé. L'audit complet, sans --range, continue de voir tout le passé —
  # c'est lui qui sert au chantier de réécriture, et c'est sa place.
  if [ -n "$RANGE" ]; then
    echo "5. Fichiers orphelins supprimés par les commits à publier ($RANGE)"
    PORTEE=("$RANGE")
  else
    echo "5. Fichiers orphelins (dans l'historique, absents du HEAD)"
    PORTEE=(--all)
  fi
  ORPH=$(comm -23 \
    <(git -C "$ROOT" log "${PORTEE[@]}" --diff-filter=D --name-only --format='' | sort -u | grep . ) \
    <(git -C "$ROOT" ls-files | sort -u) || true)
  ORPH_N=$(printf '%s' "$ORPH" | grep -c . || true)
  C5=0
  if [ "${ORPH_N:-0}" != "0" ]; then
    while IFS= read -r f; do
      [ -z "$f" ] && continue
      exclu "$f" && continue
      # TOUS les blobs de ce chemin. Un fichier sali, nettoyé, puis supprimé
      # garde son blob sale dans l'historique : `head -1` n'en retenait qu'un,
      # souvent le propre. Et la comparaison porte sur le chemin ENTIER — un
      # `grep -F " brief.md"` non ancré matche « mon brief.md » et fait juger
      # l'orphelin sur le contenu d'un autre fichier.
      mapfile -t BLOBS < <(git -C "$ROOT" rev-list "${PORTEE[@]}" --objects \
        | awk -v p="$f" 'index($0, " ") > 0 && substr($0, index($0, " ") + 1) == p { print $1 }' \
        | sort -u)
      [ ${#BLOBS[@]} -eq 0 ] && continue
      for blob in "${BLOBS[@]}"; do
        git -C "$ROOT" cat-file -p "$blob" 2>/dev/null | grep -qI . || continue
        for m in "${MOTIFS[@]}"; do
          if git -C "$ROOT" cat-file -p "$blob" 2>/dev/null | cherche "$m"; then
            warn "$f — contient « $m » (blob ${blob:0:8})"; C5=1; break 2
          fi
        done
      done
    done <<< "$ORPH"
  fi
  [ "$C5" = "0" ] && ok "$ORPH_N orphelin(s), aucun ne porte de motif"
fi

# ── Verdict ─────────────────────────────────────────────────────────────────
echo
if [ "$FOUND" = "0" ]; then
  printf '\033[32m✓ Rien à signaler.\033[0m\n'
  exit 0
fi
red "✗ Audit en échec — voir ci-dessus."
echo
if [ "$MODE" = "worktree" ]; then
  echo "  Rien n'est indexé ni commité : corrige le fichier, c'est tout."
  echo "  C'est le moment le moins cher de toute la chaîne."
elif [ "$MODE" = "staged" ]; then
  echo "  Rien n'est encore commité : corrige le fichier et refais git add."
  echo "  ⚠ --staged lit l'index : après correction, refais git add."
else
  echo "  Un motif trouvé dans le CONTENU ou les MESSAGES ne se corrige pas"
  echo "  au HEAD : il faut réécrire l'historique (git-filter-repo), re-signer"
  echo "  les tags et demander à GitHub de collecter les objets orphelins."
fi
echo "  Si c'est un faux positif, ajoute-le à scripts/opsec-allowlist.txt"
echo "  AVEC sa raison — une exclusion sans justification est une dette."
exit 1
