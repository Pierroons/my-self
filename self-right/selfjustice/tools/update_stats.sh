#!/bin/bash
# SelfJustice — Mise à jour des statistiques de la page d'accueil.
# Compte les requêtes IA dans les logs nginx et met à jour le compteur dans index.html.
#
# À lancer via cron toutes les heures :
#   0 * * * * <install-dir>/update_stats.sh

set -e

LOG_FILE="/var/log/nginx/selfjustice-access.log"
LOG_FILE_OLD="/var/log/nginx/selfjustice-access.log.1"
COUNTER_FILE="/var/lib/selfjustice/counter.txt"
# Racine du site servie par nginx — surchargeable : elle diffère selon
# l installation. Codée en dur, elle a cessé de pointer vers quoi que ce soit
# après la migration du 03/08/2026, et le compteur de la page a gelé en silence.
HTML_FILE="${SELFJUSTICE_SITE_DIR:-/var/www/selfjustice}/index.html"
ACT_HTML="${SELFJUSTICE_SITE_DIR:-/var/www/selfjustice}/act.html"
# Catalogue SelfAct : il vit à côté de l'API, pas dans le dossier du site.
ACT_CATALOG="${SELFACT_CATALOG:-$(dirname "${SELFJUSTICE_SITE_DIR:-/var/www/selfjustice}")/api/act/data/catalog.json}"
LEGI_DB="${SELFJUSTICE_DB_DIR:-/var/lib/selfjustice/db}/legi_selfjustice.sqlite"
LEGI_LAST_UPDATE_FILE="/var/lib/selfjustice/legi_last_update.txt"
EU_DB="${SELFJUSTICE_DB_DIR:-/var/lib/selfjustice/db}/conventionnalite.sqlite"
EU_LAST_UPDATE_FILE="/var/lib/selfjustice/eu_last_update.txt"

# Créer le dossier de stockage si besoin
sudo mkdir -p /var/lib/selfjustice
sudo chown deploy:deploy /var/lib/selfjustice 2>/dev/null || true

# ============================================================
# 1. Compter les requêtes IA dans les logs
# ============================================================

# User-Agents qui correspondent à des fetches d'IA (et non des visiteurs humains)
AI_PATTERNS='(Claude-User|Claude-Web|claudebot|anthropic|ChatGPT|GPTBot|OAI-SearchBot|MistralAI|Mistral-Bot|Google-Extended|GoogleOther|GeminiBot|PerplexityBot|Perplexity-User|Bytespider|YouBot|DuckAssistBot)'

# Compter les hits actuels dans les logs (incluant rotation)
HITS_NOW=0
if [ -f "$LOG_FILE" ]; then
    HITS_NOW=$(sudo grep -ciE "$AI_PATTERNS" "$LOG_FILE" 2>/dev/null || echo 0)
fi

# Si log rotation active, ajouter aussi l'ancien log (du jour)
HITS_OLD=0
if [ -f "$LOG_FILE_OLD" ]; then
    HITS_OLD=$(sudo grep -ciE "$AI_PATTERNS" "$LOG_FILE_OLD" 2>/dev/null || echo 0)
fi

CURRENT_HITS=$((HITS_NOW + HITS_OLD))

# Lire le compteur historique (cumul depuis le début)
PREVIOUS_TOTAL=0
if [ -f "$COUNTER_FILE" ]; then
    PREVIOUS_TOTAL=$(cat "$COUNTER_FILE")
fi

# Lire le dernier hit count enregistré (pour calculer le delta avant rotation)
LAST_HITS_FILE="/var/lib/selfjustice/last_hits.txt"
LAST_HITS=0
if [ -f "$LAST_HITS_FILE" ]; then
    LAST_HITS=$(cat "$LAST_HITS_FILE")
fi

# Si CURRENT_HITS < LAST_HITS = rotation des logs → on ajoute les hits oubliés
if [ "$CURRENT_HITS" -lt "$LAST_HITS" ]; then
    # On considère que LAST_HITS représentait le total du jour précédent
    PREVIOUS_TOTAL=$((PREVIOUS_TOTAL + LAST_HITS))
fi

# Total = historique + hits du jour actuel
TOTAL_HITS=$((PREVIOUS_TOTAL + CURRENT_HITS))

# Sauvegarder pour la prochaine exécution
echo "$PREVIOUS_TOTAL" > "$COUNTER_FILE"
echo "$CURRENT_HITS" > "$LAST_HITS_FILE"

# ============================================================
# 2. Récupérer la date de dernière mise à jour LEGI
# ============================================================

LEGI_UPDATE_DATE="15 avril 2026"
LEGI_ARTICLES="488 903"

if [ -f "$LEGI_LAST_UPDATE_FILE" ]; then
    LEGI_UPDATE_DATE=$(cat "$LEGI_LAST_UPDATE_FILE")
fi

if [ -f "$LEGI_DB" ]; then
    NB_ARTICLES=$(sqlite3 "$LEGI_DB" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo "488903")
    # Formater avec espaces fines comme séparateurs de milliers
    LEGI_ARTICLES=$(printf "%'d" "$NB_ARTICLES" | tr ',' ' ')
fi

# ============================================================
# 2 bis. Volumétrie du catalogue SelfAct
# ============================================================
#
# 🔑 **Le chiffre était écrit en dur dans la page.** Elle a annoncé « 334
# modèles » pendant que le catalogue passait à 1 895 entrées — sous-vendant le
# service d'un facteur six, sans que rien ne signale l'écart. Même motif que la
# date de synchronisation figée treize mois : une valeur recopiée à la main ne
# suit jamais la donnée qu'elle prétend décrire.
ACT_TOTAL=""
if [ -f "$ACT_CATALOG" ]; then
    ACT_TOTAL=$(python3 -c "
import json, sys
try:
    d = json.load(open('$ACT_CATALOG'))
    n = d.get('_meta', {}).get('total') or len(d.get('models', []))
    print(f'{n:,}'.replace(',', ' ') if n else '')
except Exception:
    pass
" 2>/dev/null)
fi

# Base UE/CEDH
EU_UPDATE_DATE="16 avril 2026"
EU_ARTICLES="1 200"
if [ -f "$EU_LAST_UPDATE_FILE" ]; then
    EU_UPDATE_DATE=$(cat "$EU_LAST_UPDATE_FILE")
fi
if [ -f "$EU_DB" ]; then
    NB_EU=$(sqlite3 "$EU_DB" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo "1200")
    EU_ARTICLES=$(printf "%'d" "$NB_EU" | tr ',' ' ')
fi

# ============================================================
# 3. Mettre à jour le HTML
# ============================================================

if [ ! -f "$HTML_FILE" ]; then
    echo "ERREUR : index.html introuvable : $HTML_FILE" >&2
    exit 1
fi

# 🔑 **Pourquoi ces valeurs sont écrites dans le HTML et pas seulement servies
# par l'API.** SelfJustice est conçu pour être lu par une IA qui récupère la
# page — et une IA n'exécute pas le JavaScript. Le script de la page met bien
# ces compteurs à jour côté navigateur, mais ce que l'IA voit, c'est le HTML
# brut. D'où cette réécriture.
#
# ⚠️ Sur une instance protégée par un verrou d'immutabilité (`chattr +i`),
# l'écriture échoue. Le script lève le verrou pour ce seul fichier et le
# remet — y compris s'il est interrompu, d'où le `trap`. Sans cette
# restauration, une interruption laisserait un fichier de production
# modifiable sans que rien ne le signale.
RELOCK=""
trap 'for f in $RELOCK; do chattr +i "$f" 2>/dev/null || true; done' EXIT INT TERM

# Écrit des couples id/valeur dans les <span> d'une page, en levant puis en
# remettant le verrou d'immutabilité si la page en porte un.
#
# ⚠️ Le fichier à reverrouiller est enregistré dans RELOCK *avant* que le
# verrou ne soit levé : si le script meurt entre les deux, le trap le remet.
# L'ordre inverse laisserait une page de production modifiable en silence.
patcher_page() {
    local fichier="$1"; shift
    [ -f "$fichier" ] || { echo "  (absent, ignoré : $fichier)"; return 0; }

    if lsattr "$fichier" 2>/dev/null | cut -c1-20 | grep -q i; then
        RELOCK="$RELOCK $fichier"
        chattr -i "$fichier" 2>/dev/null || true
    fi

    local tmp
    tmp=$(mktemp /tmp/selfjustice-stats-XXXXXX.html)
    sudo cp "$fichier" "$tmp"
    while [ "$#" -ge 2 ]; do
        # Une valeur vide n'écrase rien : mieux vaut un compteur qui date qu'un
        # compteur effacé parce qu'une source était momentanément illisible.
        if [ -n "$2" ]; then
            sudo sed -i "s|<span id=\"$1\">[^<]*</span>|<span id=\"$1\">$2</span>|g" "$tmp"
        fi
        shift 2
    done
    sudo cp "$tmp" "$fichier"
    sudo rm -f "$tmp"
}

patcher_page "$HTML_FILE" \
    "header-counter" "$TOTAL_HITS" \
    "legi-update"    "$LEGI_UPDATE_DATE" \
    "legi-articles"  "$LEGI_ARTICLES" \
    "eu-update"      "$EU_UPDATE_DATE" \
    "eu-articles"    "$EU_ARTICLES"

patcher_page "$ACT_HTML" \
    "act-catalog-total" "$ACT_TOTAL"

# Reverrouiller maintenant plutôt que d'attendre la sortie du script.
for f in $RELOCK; do chattr +i "$f" 2>/dev/null || true; done
RELOCK=""
trap - EXIT INT TERM

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Stats mises à jour : ${TOTAL_HITS} requêtes IA, ${LEGI_ARTICLES} articles LEGI (MAJ: ${LEGI_UPDATE_DATE}), catalogue SelfAct ${ACT_TOTAL:-inchangé}"
