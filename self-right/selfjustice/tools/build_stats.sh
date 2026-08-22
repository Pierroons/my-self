#!/bin/bash
# SelfJustice — Compilation des statistiques publiques (anonymes).
#
# Parse les logs nginx (sans IP, sans contenu utilisateur) et génère
# deux fichiers JSON consultables via /api/stats/by-ai et /api/stats/by-endpoint.
#
# Règle éthique : on n'extrait AUCUNE information identifiante (IP, session,
# cookie, contenu de requête). Uniquement :
#   - User-Agent catégorisé en famille d'IA (ou "humain")
#   - endpoint consulté (article cité anonymement)
#
# À lancer via cron toutes les heures :
#   0 * * * * <install-dir>/build_stats.sh

# Pas de set -e : grep -c retourne 1 quand 0 match, on gère les erreurs localement

VAR_DIR="${SELFJUSTICE_VAR_DIR:-/var/lib/selfjustice}"
STATS_DIR="${SELFJUSTICE_STATS_DIR:-$VAR_DIR/stats}"
LOG_CURRENT="${SELFJUSTICE_LOG:-/var/log/nginx/selfjustice-access.log}"
LOG_OLD="${SELFJUSTICE_LOG_OLD:-${LOG_CURRENT}.1}"

. "$(dirname "$0")/lib_journal.sh"

mkdir -p "$STATS_DIR" 2>/dev/null || true

# ============================================================
# 1. STATS PAR IA — compte les requêtes de chaque famille d'IA
# ============================================================

TMP_AI="$STATS_DIR/by-ai.json.tmp"

# 🔑 **Deux affirmations fausses tenaient ce bloc debout.** Le commentaire d'avant
# disait les journaux « world-readable (-rw-r--r--) » — ils sont en `-rw-r-----
# www-data:adm` — et invoquait un compte `deploy` qui n'existe pas sur l'instance.
# Le script ne fonctionne que parce que la tâche planifiée le lance en root.
# Lancé autrement, `cat` échouait en silence, la variable était vide, et ce
# fichier publiait des zéros partout sans qu'une erreur soit émise. `journal_contenu`
# crie désormais sur un journal présent et illisible.
LOGS_CONTENT=$(journal_contenu "$LOG_CURRENT" "$LOG_OLD") || {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERTE : journaux illisibles — statistiques publiques non regénérées." >&2
    exit 1
}

# 🔑 **Le motif se cherche dans le User-Agent, pas dans la ligne entière.** Ce
# script publie sur `/api/stats/by-ai` un JSON dont la description affirme
# distinguer les consultations par User-Agent. Il cherchait pourtant `Claude-User`
# ou `GPTBot` sur toute la ligne, URL et référent compris : `GET /?q=ChatGPT`
# valait une consultation, sur un compteur public donc forgeable. La règle
# d'extraction vit dans `lib_journal.sh`, partagée avec `update_stats.sh` — un
# correctif appliqué à un seul des deux compteurs ne vaut rien.
UA_CONTENT=$(printf '%s\n' "$LOGS_CONTENT" | journal_user_agents)

count_ua() {
    # $1 = pattern regex insensible à la casse
    # grep -c retourne exit 1 quand 0 match, donc on capture proprement
    local count
    count=$(printf '%s\n' "$UA_CONTENT" | grep -cEi "$1") || count=0
    echo "$count"
}

# ============================================================
# CONSULTATIONS — requêtes déclenchées par un utilisateur humain
# qui a demandé à son IA de consulter SelfJustice.
# Seules 3 IA exposent un User-Agent distinct pour ce cas :
#   - Claude-User (claude.ai)
#   - ChatGPT-User (chatgpt.com)
#   - Perplexity-User (perplexity.ai)
# ============================================================

CLAUDE_USER=$(count_ua 'Claude-User')
CHATGPT_USER=$(count_ua 'ChatGPT-User')
PERPLEXITY_USER=$(count_ua 'Perplexity-User')
USER_TOTAL=$((CLAUDE_USER + CHATGPT_USER + PERPLEXITY_USER))

# ============================================================
# CRAWLERS — bots d'indexation / d'entraînement qui aspirent le
# contenu sans qu'un utilisateur l'ait demandé.
# ============================================================

CRAWLER_CLAUDE=$(count_ua '(ClaudeBot|claude-web|anthropic-ai)')
CRAWLER_OPENAI=$(count_ua '(GPTBot|OAI-SearchBot)')
CRAWLER_MISTRAL=$(count_ua '(MistralAI|Mistral-Bot)')
CRAWLER_GOOGLE=$(count_ua '(GoogleBot|Google-Extended|GoogleOther|Bard|Gemini)')
CRAWLER_PERPLEXITY=$(count_ua 'PerplexityBot')
CRAWLER_GROK=$(count_ua 'Grok')
CRAWLER_OTHERS=$(count_ua '(YouBot|DuckAssistBot|Bytespider|Applebot)')
CRAWLER_TOTAL=$((CRAWLER_CLAUDE + CRAWLER_OPENAI + CRAWLER_MISTRAL + CRAWLER_GOOGLE + CRAWLER_PERPLEXITY + CRAWLER_GROK + CRAWLER_OTHERS))

# Total toutes requêtes confondues
TOTAL=$(echo -n "$LOGS_CONTENT" | grep -c '^' 2>/dev/null) || TOTAL=0
OTHER=$((TOTAL - USER_TOTAL - CRAWLER_TOTAL))

# ⚠️ Un `OTHER` négatif signifie qu'un User-Agent a été compté dans deux familles.
# Il était ramené à zéro sans un mot : le seul signal que les familles se
# recouvrent disparaissait, et le JSON public restait cohérent en apparence.
if [ "$OTHER" -lt 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERTE : $((-OTHER)) requête(s) comptée(s) dans deux familles d'IA — les motifs se recouvrent." >&2
    OTHER=0
fi

cat > "$TMP_AI" <<JSON
{
  "meta": {
    "description": "Statistiques anonymes des accès à SelfJustice. Aucune IP, aucun cookie, aucun contenu utilisateur n'est stocké. On distingue les consultations à la demande d'un utilisateur (User-Agent *-User) des crawlers d'indexation automatique.",
    "updated": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
    "period": "depuis la dernière rotation des logs nginx (environ 7 jours)",
    "total_requests": $TOTAL
  },
  "user_consultations": {
    "description": "Requêtes explicitement déclenchées par un humain qui a demandé à son IA de consulter SelfJustice.",
    "claude": $CLAUDE_USER,
    "chatgpt": $CHATGPT_USER,
    "perplexity": $PERPLEXITY_USER,
    "note": "Mistral, Gemini et Grok n'exposent pas de User-Agent distinct pour le trafic utilisateur : ils sont uniquement comptés côté crawlers."
  },
  "user_total": $USER_TOTAL,
  "crawlers": {
    "description": "Bots d'indexation ou d'entraînement. Pas de requête utilisateur derrière.",
    "claude_bots": $CRAWLER_CLAUDE,
    "openai_bots": $CRAWLER_OPENAI,
    "mistral_bots": $CRAWLER_MISTRAL,
    "google_bots": $CRAWLER_GOOGLE,
    "perplexity_bots": $CRAWLER_PERPLEXITY,
    "grok_bots": $CRAWLER_GROK,
    "autres_bots": $CRAWLER_OTHERS
  },
  "crawler_total": $CRAWLER_TOTAL,
  "background_and_unclassified": $OTHER
}
JSON

mv "$TMP_AI" "$STATS_DIR/by-ai.json"

# ============================================================
# 2. STATS PAR ENDPOINT — top articles consultés via /api/legi et /api/eu
# ============================================================

TMP_EP="$STATS_DIR/by-endpoint.json.tmp"

# Extraire les requêtes /api/legi/article/{ref} et /api/eu/article/{source}/{num}
# puis compter par article
LEGI_COUNTS=$(echo "$LOGS_CONTENT" | grep -oE 'GET /api/legi/article/[A-Za-z0-9_-]+' | awk '{print $2}' | sed 's|.*article/||' | sort | uniq -c | sort -rn | head -30)

EU_COUNTS=$(echo "$LOGS_CONTENT" | grep -oE 'GET /api/eu/article/[A-Z_]+/[A-Za-z0-9-]+' | awk '{print $2}' | sed 's|.*article/||' | sort | uniq -c | sort -rn | head -30)

# Construire les arrays JSON
build_json_array() {
    local input="$1"
    local first=1
    echo "["
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        count=$(echo "$line" | awk '{print $1}')
        ref=$(echo "$line" | awk '{$1=""; print $0}' | sed 's/^ //')
        [ -z "$ref" ] && continue
        if [ $first -eq 1 ]; then
            first=0
        else
            echo ","
        fi
        printf '    {"reference": "%s", "count": %s}' "$ref" "$count"
    done <<<"$input"
    echo ""
    echo "  ]"
}

cat > "$TMP_EP" <<JSON
{
  "meta": {
    "description": "Top 30 des articles juridiques les plus consultés via l'API SelfJustice. Aucune donnée personnelle, uniquement les références d'articles.",
    "updated": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
    "disclaimer": "Ces statistiques reflètent l'intérêt collectif pour certains articles — donnée d'intérêt général, sans tracking utilisateur.",
    "github_script": "https://github.com/Pierroons/my-self/blob/main/self-right/selfjustice/tools/build_stats.sh"
  },
  "top_legi_articles": $(build_json_array "$LEGI_COUNTS"),
  "top_eu_articles": $(build_json_array "$EU_COUNTS")
}
JSON

mv "$TMP_EP" "$STATS_DIR/by-endpoint.json"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Stats mises à jour : total $TOTAL (consultations IA: $USER_TOTAL, crawlers: $CRAWLER_TOTAL, autres: $OTHER)"

exit 0
