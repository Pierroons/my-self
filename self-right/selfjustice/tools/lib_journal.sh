#!/bin/bash
# SelfJustice — lecture des journaux nginx, partagée par les scripts de statistiques.
#
# Deux scripts comptent les requêtes d'IA à partir des mêmes journaux :
# `update_stats.sh` (un total) et `build_stats.sh` (une ventilation par famille).
# Chacun cherchait son motif avec `grep` sur la LIGNE ENTIÈRE, donc sur l'URL et
# le référent autant que sur le User-Agent : `GET /?q=ChatGPT` valait une
# consultation d'IA sur deux compteurs affichés publiquement, donc forgeable par
# n'importe quel visiteur. La règle vit ici, une seule fois, pour qu'un correctif
# ne s'applique pas à un seul des deux.

# Émet un User-Agent par ligne, lu sur l'entrée standard.
#
# Le User-Agent est le dernier champ entre guillemets du format `combined` de
# nginx — `… "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"`.
# Trois paires de guillemets, donc `split` rend exactement 7 champs et le
# User-Agent est l'avant-dernier. nginx échappe en `\x22` les guillemets que
# porteraient les en-têtes : les paires sont sûres.
#
# ⚠️ Le seuil est `7`, pas `3`. Sur une ligne tronquée juste après la requête —
# nginx tué en pleine écriture, journal coupé à la rotation — `split` rend 3
# champs et l'avant-dernier est **la requête** : `"GET /?q=ChatGPT HTTP/1.1`
# repassait alors pour un User-Agent, et la contrefaçon qu'on ferme ici se
# rouvrait par la porte de derrière. Une ligne incomplète est écartée, pas devinée.
journal_user_agents() {
    awk '
        { n = split($0, champ, "\"") }
        n >= 7 { print champ[n - 1] }
    '
}

# Concatène les journaux passés en argument. Un fichier absent est normal — le
# journal pivoté n'existe pas avant la première rotation. Un fichier présent mais
# illisible est une panne, et elle crie.
#
# Les journaux de nginx sont en `-rw-r----- www-data:adm` : hors de root ou du
# groupe `adm`, `cat` échoue. Sans cette garde la sortie est vide, tous les
# compteurs valent zéro, et les pages publiques annoncent zéro sans qu'une seule
# erreur soit émise.
journal_contenu() {
    local fichier lisible=0
    for fichier in "$@"; do
        [ -e "$fichier" ] || continue
        if [ -r "$fichier" ]; then
            cat "$fichier"
            lisible=1
        else
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERTE : $fichier illisible par $(id -un) — les compteurs seraient faux." >&2
            return 1
        fi
    done
    [ "$lisible" -eq 1 ] || return 2
}
