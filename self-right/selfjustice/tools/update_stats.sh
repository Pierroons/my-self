#!/bin/bash
# SelfJustice — Mise à jour des statistiques de la page d'accueil.
# Compte les requêtes IA dans les logs nginx et publie les compteurs du corpus.
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
# Catalogue SelfAct : depuis le 21/08/2026 il vit dans l'état de l'instance, et
# non plus dans l'arbre du code — un transfert du dépôt y reposait une copie
# périmée par-dessus la fraîche. Le chemin du dépôt reste en dernier recours,
# pour une instance qui n'aurait pas encore migré.
# 🔑 **Une source introuvable doit crier.** Ce bloc essayait deux chemins et se
# taisait si aucun ne répondait : la statistique gardait alors sa valeur de la
# veille et la page publique annonçait un chiffre périmé, indéfiniment. Constaté
# le 22/08/2026 — les deux chemins pointaient vers des fichiers absents depuis
# que le catalogue avait quitté l'arbre du code, et le journal se contentait de
# « catalogue SelfAct inchangé » une fois par heure, ce que personne ne lit.
#
# 🔑 **Le chemin se demande, il ne se réécrit pas.** Ce bloc répétait en shell
# l'ordre de résolution de `selfact/api/chemins.php`, avec un commentaire
# promettant qu'ils restaient synchronisés. Ils ne l'étaient pas : `${VAR:-défaut}`
# substitue le défaut immédiatement, si bien que `/var/lib/selfact` passait AVANT
# un `SELFJUSTICE_VAR_DIR` explicitement posé. Et le PHP tranche sur l'existence
# du RÉPERTOIRE quand le shell exigeait le FICHIER : sur une instance fraîche,
# l'API lisait un fichier absent pendant que ce script publiait le total de la
# copie du dépôt. Deux chiffres, aucune erreur.
#
# `update_catalog.sh` demandait déjà le chemin à la fonction PHP. Ce script fait
# de même : une seule autorité, plus de miroir à tenir.
CHEMINS="$(dirname "$(dirname "${SELFJUSTICE_SITE_DIR:-/var/www/myself/self-right/selfjustice/site}")")/selfact/api/chemins.php"
if [ -r "$CHEMINS" ] && command -v php >/dev/null 2>&1; then
    candidat="$(php -r "require '$CHEMINS'; echo selfact_chemin_catalogue();" 2>/dev/null)"
    [ -n "$candidat" ] && [ -f "$candidat" ] && ACT_CATALOG="$candidat"
fi
if [ -z "${ACT_CATALOG:-}" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERTE : catalogue SelfAct introuvable (chemins.php : ${CHEMINS}) — la statistique publique va rester figée sur sa valeur précédente." >&2
fi
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
# 🔑 **`grep -c` affiche `0` ET sort en 1 quand il ne trouve rien.** Le
# `|| echo 0` ajoutait donc un SECOND zéro : la substitution rendait « 0\n0 »,
# et l'arithmétique refusait — « erreur de syntaxe dans l'expression ». Chaque
# heure, dans un journal que personne ne lit. Le compte des consultations
# repartait alors de zéro sans que rien ne le dise.
#
# `entier` ramène toute sortie à un nombre : vide ou non numérique valent 0.
#
# ⚠️ `head -1` AVANT le filtre, et ce n'est pas décoratif : sans lui, « 12\n34 »
# devient « 1234 », un nombre que rien n'a jamais compté. Une valeur inventée est
# pire qu'une valeur absente — elle ne se distingue pas d'une vraie.
entier() { printf '%s' "${1:-}" | head -1 | tr -dc '0-9' | head -c 18 | grep . || printf 0; }

HITS_NOW=0
if [ -f "$LOG_FILE" ]; then
    HITS_NOW=$(entier "$(sudo grep -ciE "$AI_PATTERNS" "$LOG_FILE" 2>/dev/null | head -1)")
fi

# Si log rotation active, ajouter aussi l'ancien log (du jour)
HITS_OLD=0
if [ -f "$LOG_FILE_OLD" ]; then
    HITS_OLD=$(entier "$(sudo grep -ciE "$AI_PATTERNS" "$LOG_FILE_OLD" 2>/dev/null | head -1)")
fi

CURRENT_HITS=$((HITS_NOW + HITS_OLD))

# Lire le compteur historique (cumul depuis le début)
# ⚠️ Ces fichiers étaient VIDES en production le 22/08/2026 — une écriture
# interrompue, ou un `> fichier` qui n'a jamais été suivi de son contenu. Un
# `cat` rend alors la chaîne vide, que la comparaison plus bas refuse. Passer
# par `entier` rend l'absence et le vide indiscernables d'un zéro, ce qu'ils
# sont pour un compteur.
PREVIOUS_TOTAL=0
if [ -f "$COUNTER_FILE" ]; then
    PREVIOUS_TOTAL=$(entier "$(cat "$COUNTER_FILE")")
fi

# Lire le dernier hit count enregistré (pour calculer le delta avant rotation)
LAST_HITS_FILE="/var/lib/selfjustice/last_hits.txt"
LAST_HITS=0
if [ -f "$LAST_HITS_FILE" ]; then
    LAST_HITS=$(entier "$(cat "$LAST_HITS_FILE")")
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

# 🔑 **Un échec ne rend pas un résultat.** Ces deux valeurs étaient écrites en
# dur — « 15 avril 2026 » et « 488 903 » — et servaient de repli à un `sqlite3`
# absent ou à une base verrouillée. La page publique annonçait alors un chiffre
# d'avril comme s'il venait d'être compté, dans le fichier même dont un
# commentaire plus bas dénonce « une valeur recopiée à la main qui ne suit
# jamais la donnée qu'elle prétend décrire ».
#
# Rien ne remplace une mesure qui n'a pas eu lieu : la variable reste vide, et
# la page saura qu'elle ne sait pas.
LEGI_UPDATE_DATE=""
LEGI_ARTICLES=""

if [ -f "$LEGI_LAST_UPDATE_FILE" ]; then
    LEGI_UPDATE_DATE=$(cat "$LEGI_LAST_UPDATE_FILE")
fi

if [ -f "$LEGI_DB" ]; then
    NB_ARTICLES=$(entier "$(sqlite3 "$LEGI_DB" "SELECT COUNT(*) FROM articles" 2>/dev/null)")
    # Formater avec espaces fines comme séparateurs de milliers
    [ "$NB_ARTICLES" -gt 0 ] && LEGI_ARTICLES=$(printf "%'d" "$NB_ARTICLES" | tr ',' ' ')
fi
if [ -z "$LEGI_ARTICLES" ] || [ -z "$LEGI_UPDATE_DATE" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERTE : volumétrie ou date LEGI non mesurée (base : $LEGI_DB) — la page n'affichera pas de chiffre plutôt qu'un chiffre faux." >&2
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
if [ -n "${ACT_CATALOG:-}" ] && [ -f "$ACT_CATALOG" ]; then
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
EU_ARTICLES=""
if [ -f "$EU_LAST_UPDATE_FILE" ]; then
    EU_UPDATE_DATE=$(cat "$EU_LAST_UPDATE_FILE")
fi
if [ -f "$EU_DB" ]; then
    NB_EU=$(entier "$(sqlite3 "$EU_DB" "SELECT COUNT(*) FROM articles" 2>/dev/null)")
    EU_ARTICLES=$(printf "%'d" "$NB_EU" | tr ',' ' ')
fi

# ============================================================
# 3. Mettre à jour le HTML
# ============================================================

# 🔑 **Pourquoi ces valeurs sortent dans un fichier et non dans le HTML.**
# SelfJustice est lu par des IA, et une IA n'exécute pas le JavaScript : les
# chiffres doivent être dans le HTML que le serveur rend, pas ajoutés après
# coup par le navigateur. Ils y sont — mais insérés par la page elle-même au
# moment où elle est servie, pas écrits dans un fichier versionné.
#
# La version précédente réécrivait index.html sur place. Le dépôt portait donc
# des chiffres, et chaque déploiement les faisait régresser jusqu'au passage
# suivant : mesuré le 19/08/2026, la page annonçait 488 903 articles et une
# synchronisation d'avril pendant neuf minutes, avant que ce script ne la
# répare. La page était juste par accident, entre deux accidents.
#
# Conséquence directe : plus aucun `chattr -i` ici. Ce script n'écrit plus dans
# le code de production, seulement dans ses données.
# Même emplacement que les autres sorties de statistiques, produites par
# build_stats.sh — un seul dossier à servir, un seul à sauvegarder.
STATS_DIR="${SELFJUSTICE_STATS_DIR:-/var/lib/selfjustice/stats}"
CORPUS="$STATS_DIR/corpus.json"
mkdir -p "$STATS_DIR" 2>/dev/null || true

# ⚠️ Une source momentanément illisible ne doit pas effacer sa valeur — mieux
# vaut un compteur qui date qu'un compteur disparu. Les valeurs vides sont donc
# reprises de l'écriture précédente, comme le faisait le sed d'avant.
php -r '
    $sortie  = $argv[1];
    $ancien  = is_readable($sortie) ? (json_decode(file_get_contents($sortie), true) ?: []) : [];
    $cles    = ["requetes_ia", "legi_articles", "legi_maj", "eu_articles", "eu_maj", "act_catalogue"];
    $valeurs = array_slice($argv, 2);
    $sortant = [];
    foreach ($cles as $i => $cle) {
        $v = $valeurs[$i] ?? "";
        $sortant[$cle] = ($v !== "") ? $v : ($ancien[$cle] ?? null);
    }
    $sortant["genere_le"] = date("c");
    $tmp = $sortie . ".tmp";
    file_put_contents($tmp, json_encode($sortant, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    rename($tmp, $sortie);
' "$CORPUS" \
    "$TOTAL_HITS" "$LEGI_ARTICLES" "$LEGI_UPDATE_DATE" \
    "$EU_ARTICLES" "$EU_UPDATE_DATE" "$ACT_TOTAL"


echo "[$(date '+%Y-%m-%d %H:%M:%S')] Stats mises à jour : ${TOTAL_HITS} requêtes IA, ${LEGI_ARTICLES} articles LEGI (MAJ: ${LEGI_UPDATE_DATE}), catalogue SelfAct ${ACT_TOTAL:-inchangé}"
