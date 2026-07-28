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
# ── Trois modes, et pourquoi le plus précoce est le plus important ──────────
#
#   (aucun)     audit complet — tout l'historique. Appelé par le pre-push.
#   --staged    fichiers indexés seulement. Appelé par le pre-commit.
#   --message F motifs dans le fichier de message. Appelé par le commit-msg.
#
# L'écart de coût entre les deux premiers est le vrai sujet. Une donnée
# arrêtée au pre-commit se corrige en dix secondes : le fichier n'est même
# pas commité. La même donnée arrêtée au pre-push est déjà dans l'historique
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
VERBOSE=0
case "${1:-}" in
  --staged)  MODE="staged" ;;
  --message) MODE="message"; MSGFILE="${2:-}" ;;
  --verbose) VERBOSE=1 ;;
esac

FOUND=0
red()  { printf '\033[31m%s\033[0m\n' "$1"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '  \033[31m✗\033[0m %s\n' "$1"; FOUND=1; }

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
    printf '%s' "$1" | grep -qi -e "$m" && return 0
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
    if printf '%s' "$CORPS" | grep -qi -e "$m"; then
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
  mapfile -t CIBLES < <(git -C "$ROOT" diff --cached --name-only --diff-filter=ACMR)
  [ ${#CIBLES[@]} -eq 0 ] && { ok "rien d'indexé"; exit 0; }
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
if [ "$MODE" = "staged" ]; then
  echo "2. Données personnelles — contenu indexé"
  C2=0
  for f in "${CIBLES[@]}"; do
    exclu "$f" && continue
    # Binaire : le grep n'a pas de sens, le contrôle 3 s'en charge.
    git -C "$ROOT" show ":$f" 2>/dev/null | grep -qI . || continue
    for m in "${MOTIFS[@]}"; do
      if git -C "$ROOT" show ":$f" 2>/dev/null | grep -qi -e "$m"; then
        warn "$f — contient « $m »"
        git -C "$ROOT" show ":$f" 2>/dev/null | grep -in -e "$m" | head -2 | sed 's/^/       /'
        C2=1
        break
      fi
    done
  done
  [ "$C2" = "0" ] && ok "aucun motif dans les ${#CIBLES[@]} fichier(s) indexés"
else
  # On scanne TOUS les commits, pas le HEAD : corriger un fichier ne retire
  # pas ce qu'il contenait hier. C'est ce qui distingue ce contrôle de gitleaks.
  echo "2. Données personnelles — contenu de l'historique complet"
  REVS=$(git -C "$ROOT" rev-list --all)
  C2=0
  for m in "${MOTIFS[@]}"; do
    hits=$(git -C "$ROOT" grep -Iil -e "$m" $REVS 2>/dev/null | cut -d: -f2- | sort -u)
    [ -z "$hits" ] && continue
    reste=""
    while IFS= read -r f; do
      [ -z "$f" ] && continue
      exclu "$f" || reste="${reste}${f}"$'\n'
    done <<< "$hits"
    n=$(printf '%s' "$reste" | grep -c . || true)
    [ "${n:-0}" != "0" ] && { warn "« $m » — $n fichier(s)"; C2=1; }
  done
  [ "$C2" = "0" ] && ok "aucun motif dans le contenu"
fi

# ── 3. Métadonnées des binaires ────────────────────────────────────────────
# Un PNG porte parfois un nom d'utilisateur ou des coordonnées GPS. Un DOCX
# porte un auteur et une société. Aucun scanner de secrets ne les lit.
echo
echo "3. Métadonnées des fichiers binaires"
if ! command -v exiftool >/dev/null 2>&1; then
  warn "exiftool absent — sudo apt install libimage-exiftool-perl"
else
  if [ "$MODE" = "staged" ]; then
    mapfile -t BINS < <(printf '%s\n' "${CIBLES[@]}" | grep -iE '\.(png|jpe?g|webp|tiff?|pdf|docx|xlsx|odt)$' || true)
  else
    mapfile -t BINS < <(git -C "$ROOT" ls-files -- \
      '*.png' '*.jpg' '*.jpeg' '*.webp' '*.tif' '*.tiff' \
      '*.pdf' '*.docx' '*.xlsx' '*.odt' 2>/dev/null)
  fi
  META=""
  for f in "${BINS[@]:-}"; do
    [ -n "${f:-}" ] && [ -f "$ROOT/$f" ] || continue
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
if [ "$MODE" != "staged" ]; then
  echo
  echo "4. Messages de commit (sujets et corps)"
  C4=0
  MESSAGES=$(git -C "$ROOT" log --all --format='%s%n%b')
  for m in "${MOTIFS[@]}"; do
    n=$(printf '%s' "$MESSAGES" | grep -ic -e "$m" || true)
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
  echo "5. Fichiers orphelins (dans l'historique, absents du HEAD)"
  ORPH=$(comm -23 \
    <(git -C "$ROOT" log --all --diff-filter=D --name-only --format='' | sort -u | grep . ) \
    <(git -C "$ROOT" ls-files | sort -u) || true)
  ORPH_N=$(printf '%s' "$ORPH" | grep -c . || true)
  C5=0
  if [ "${ORPH_N:-0}" != "0" ]; then
    while IFS= read -r f; do
      [ -z "$f" ] && continue
      exclu "$f" && continue
      blob=$(git -C "$ROOT" rev-list --all --objects | grep -F " $f" | head -1 | cut -d' ' -f1)
      [ -z "$blob" ] && continue
      git -C "$ROOT" cat-file -p "$blob" 2>/dev/null | grep -qI . || continue
      for m in "${MOTIFS[@]}"; do
        if git -C "$ROOT" cat-file -p "$blob" 2>/dev/null | grep -qiI -e "$m"; then
          warn "$f — contient « $m »"; C5=1; break
        fi
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
if [ "$MODE" = "staged" ]; then
  echo "  Rien n'est encore commité : corrige le fichier et refais git add."
  echo "  C'est le moment où la correction coûte le moins cher."
else
  echo "  Un motif trouvé dans le CONTENU ou les MESSAGES ne se corrige pas"
  echo "  au HEAD : il faut réécrire l'historique (git-filter-repo), re-signer"
  echo "  les tags et demander à GitHub de collecter les objets orphelins."
fi
echo "  Si c'est un faux positif, ajoute-le à scripts/opsec-allowlist.txt"
echo "  AVEC sa raison — une exclusion sans justification est une dette."
exit 1
