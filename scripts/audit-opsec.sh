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
# scripts/opsec-allowlist.txt, avec sa raison écrite à côté. L'objectif est
# une sortie vide : une ligne qui apparaît veut alors dire quelque chose.
#
# Usage :  ./scripts/audit-opsec.sh [--verbose]
# Sortie :  0 = rien à signaler · 1 = au moins une trouvaille

set -uo pipefail

ROOT="$(git rev-parse --show-toplevel)"
PATTERNS="${SELFOPSEC_PATTERNS:-$HOME/.config/selfopsec/patterns.txt}"
ALLOWLIST="$ROOT/scripts/opsec-allowlist.txt"
VERBOSE=0
[ "${1:-}" = "--verbose" ] && VERBOSE=1

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

# ── Allowlist : chemins exclus du grep de contenu ────────────────────────────
# Chaque entrée est un motif grep -E appliqué au chemin du fichier.
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

echo "▸ Audit OPSEC — ${#MOTIFS[@]} motifs, ${#EXCLUDES[@]} exclusions"
echo

# ── 1. Secrets, sur tout l'historique ───────────────────────────────────────
echo "1. Secrets (gitleaks)"
if command -v gitleaks >/dev/null 2>&1; then
  if gitleaks detect --source "$ROOT" -c "$ROOT/.gitleaks.toml" \
       --no-banner --redact >/dev/null 2>&1; then
    ok "aucun secret"
  else
    warn "secret détecté — détail : gitleaks detect -v"
  fi
else
  warn "gitleaks absent (le paquet Debian est figé sur une version ancienne"
  echo "      qui n'interprète pas les allowlists pareil — prends le binaire"
  echo "      officiel : https://github.com/gitleaks/gitleaks/releases)"
fi

# ── 2. Données personnelles dans le contenu de l'historique ─────────────────
# On scanne TOUS les commits, pas le HEAD : corriger un fichier ne retire pas
# ce qu'il contenait hier. C'est ce qui distingue ce contrôle de gitleaks.
echo
echo "2. Données personnelles — contenu de l'historique complet"
REVS=$(git -C "$ROOT" rev-list --all)
for m in "${MOTIFS[@]}"; do
  hits=$(git -C "$ROOT" grep -Iil -e "$m" $REVS 2>/dev/null | cut -d: -f2- | sort -u)
  [ -z "$hits" ] && continue
  reste=""
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    exclu "$f" || reste="${reste}${f}"$'\n'
  done <<< "$hits"
  reste=$(printf '%s' "$reste" | grep -c . || true)
  [ "${reste:-0}" != "0" ] && warn "« $m » — $reste fichier(s)"
done
[ "$FOUND" = "0" ] && ok "aucun motif dans le contenu"

# ── 3. Messages de commit — sujets ET corps ─────────────────────────────────
# Aucun outil ne lit les messages. Et c'est le pire endroit pour une fuite :
# « retire le nom X de la démo » publie X définitivement. Décrire ce qu'on a
# fait, jamais ce qu'on a retiré.
echo
echo "3. Messages de commit (sujets et corps)"
MSG_FOUND=0
MESSAGES=$(git -C "$ROOT" log --all --format='%s%n%b')
for m in "${MOTIFS[@]}"; do
  n=$(printf '%s' "$MESSAGES" | grep -ic -e "$m" || true)
  if [ "${n:-0}" != "0" ]; then
    warn "« $m » — $n ligne(s)"
    MSG_FOUND=1
    [ "$VERBOSE" = "1" ] && printf '%s' "$MESSAGES" | grep -i -e "$m" | head -3 | sed 's/^/       /'
  fi
done
[ "$MSG_FOUND" = "0" ] && ok "aucun motif dans les messages"

# ── 4. Fichiers présents dans l'historique, absents du HEAD ─────────────────
# Le filtre le plus utile de tous : c'est là que dorment les documents de
# travail, briefs privés, captures d'écran et scripts perso qu'on a « rangés »
# d'un coup de git rm en croyant les avoir retirés.
echo
echo "4. Fichiers orphelins (dans l'historique, absents du HEAD)"
ORPH=$(comm -23 \
  <(git -C "$ROOT" log --all --diff-filter=D --name-only --format='' | sort -u | grep . ) \
  <(git -C "$ROOT" ls-files | sort -u) || true)
ORPH_N=$(printf '%s' "$ORPH" | grep -c . || true)
ORPH_FOUND=0
if [ "${ORPH_N:-0}" != "0" ]; then
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    exclu "$f" && continue
    blob=$(git -C "$ROOT" rev-list --all --objects | grep -F " $f" | head -1 | cut -d' ' -f1)
    [ -z "$blob" ] && continue
    # Binaire : le grep n'y a pas de sens, exiftool s'en charge en 5.
    git -C "$ROOT" cat-file -p "$blob" 2>/dev/null | grep -qI . || continue
    for m in "${MOTIFS[@]}"; do
      if git -C "$ROOT" cat-file -p "$blob" 2>/dev/null | grep -qiI -e "$m"; then
        warn "$f — contient « $m »"
        ORPH_FOUND=1
        break
      fi
    done
  done <<< "$ORPH"
fi
[ "$ORPH_FOUND" = "0" ] && ok "$ORPH_N orphelin(s), aucun ne porte de motif"

# ── 5. Métadonnées — images ET documents ───────────────────────────────────
# Un PNG porte parfois un nom d'utilisateur, un chemin de fichier ou des
# coordonnées GPS. Un DOCX porte un auteur et une société. Aucun scanner de
# secrets ne les lit, et ils survivent au copier-coller.
echo
echo "5. Métadonnées des fichiers binaires"
if command -v exiftool >/dev/null 2>&1; then
  META=$(git -C "$ROOT" ls-files -- \
           '*.png' '*.jpg' '*.jpeg' '*.webp' '*.tif' '*.tiff' \
           '*.pdf' '*.docx' '*.xlsx' '*.odt' 2>/dev/null | \
         while IFS= read -r f; do
           [ -f "$ROOT/$f" ] || continue
           # Ne signaler que ce qui POSE problème, pas la simple présence
           # d'une métadonnée. « Pierroons — MySelf ecosystem » ou le nom du
           # script générateur sont des valeurs voulues ; crier dessus à
           # chaque passage est le meilleur moyen de faire ignorer le jour
           # où une position GPS ou un chemin /home/<login> apparaît.
           brut=$(exiftool -s -S -Artist -Creator -Author -LastModifiedBy \
                    -Company -Software -Comment -UserComment -XPAuthor \
                    -Copyright -GPSPosition -HostComputer -OwnerName \
                    -SerialNumber "$ROOT/$f" 2>/dev/null | grep -v '^$' || true)
           v=""
           while IFS= read -r ligne; do
             [ -z "$ligne" ] && continue
             suspect=0
             # Un chemin absolu porte un nom de compte ; des coordonnées
             # portent un lieu. Les deux sont des fuites quoi qu'il arrive.
             printf '%s' "$ligne" | grep -qE '(/home/|/Users/|[A-Z]:\\|[0-9]+ deg [0-9]+)' && suspect=1
             for m in "${MOTIFS[@]}"; do
               printf '%s' "$ligne" | grep -qi -e "$m" && { suspect=1; break; }
             done
             [ "$suspect" = "1" ] && v="${v}${ligne}"$'\n'
           done <<< "$brut"
           [ -n "$v" ] && printf '%s\n%s\n' "  $f" "$v"
         done)
  if [ -n "$META" ]; then
    warn "métadonnées présentes :"
    printf '%s\n' "$META" | sed 's/^/       /'
    echo "       Purge : exiftool -all= <fichier>"
  else
    ok "aucune métadonnée identifiante"
  fi
else
  warn "exiftool absent — sudo apt install libimage-exiftool-perl"
fi

# ── Verdict ─────────────────────────────────────────────────────────────────
echo
if [ "$FOUND" = "0" ]; then
  printf '\033[32m✓ Rien à signaler.\033[0m\n'
  exit 0
fi
red "✗ Audit en échec — voir ci-dessus."
echo
echo "  Un motif trouvé dans le CONTENU ou les MESSAGES ne se corrige pas"
echo "  au HEAD : il faut réécrire l'historique (git-filter-repo), re-signer"
echo "  les tags et demander à GitHub de collecter les objets orphelins."
echo "  Si c'est un faux positif, ajoute-le à scripts/opsec-allowlist.txt"
echo "  AVEC sa raison — une exclusion sans justification est une dette."
exit 1
