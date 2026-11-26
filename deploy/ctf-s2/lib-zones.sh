# shellcheck shell=bash
# Les clés des zones `limit_req` d'un vhost, et ce qui change entre deux versions.
#
# 🔑 Une zone `limit_req` vit en mémoire partagée dans le master nginx, et sa clé ne peut pas
# changer à chaud : le master refuse alors la configuration ENTIÈRE et continue de servir
# l'ancienne. Les deux contrôles habituels ne le voient pas — `nginx -t` passe, parce qu'il teste
# dans un processus neuf sans cet état, et `nginx -s reload` rend 0 parce qu'il envoie le signal
# sans attendre le verdict du master. Mesuré le 11/11/2026 sur le lab CTF : vhost posé, trois
# signaux verts, aucun frein actif pendant 14 minutes, et pour seul témoin
# « [emerg] limit_req "lab_auth" uses the "$cle_frein" key while previously it used the
# "$binary_remote_addr" key » dans l'error.log.
#
# Sourcé par `deploy-vhost-ctf.sh`, qui en tire le geste à faire, et par
# `tests/test_pose_vhost.sh`, qui l'éprouve. Une seule définition : une copie dans le banc
# divergerait du jour où le vhost change de forme.

# Une ligne « <zone> <clé> » par zone déclarée, triée sur le nom de zone.
cles_de_zone() {
  # shellcheck disable=SC2016  # le « $ » cherché est celui de la variable nginx, pas une expansion
  grep -oE 'limit_req_zone[[:space:]]+\$[A-Za-z0-9_]+[[:space:]]+zone=[A-Za-z0-9_]+' "$1" \
    | sed -E 's/.*\$([A-Za-z0-9_]+)[[:space:]]+zone=([A-Za-z0-9_]+)/\2 \1/' \
    | sort
}

# $1 = configuration en service, $2 = candidate. Une ligne par zone dont la clé change.
# Une zone qui apparaît ou disparaît ne sort pas : `join` ne retient que les communes, et ce
# sont les seules que le master porte déjà en mémoire.
zones_a_cle_changee() {
  join <(cles_de_zone "$1") <(cles_de_zone "$2") | awk '$2 != $3 { print $1 " : " $2 " -> " $3 }'
}

# 🔑 **Une zone déclarée deux fois fait refuser la configuration ENTIÈRE** —
# « limit_req_zone "x" is already bound to key … ». C'est un autre échec que celui du haut de ce
# fichier : celui-là, `nginx -t` l'attrape, donc il ne passe pas inaperçu. Mais il fait échouer la
# pose au dernier moment, après la copie, alors qu'il se voit AVANT en comparant deux listes.
#
# Le cas est réel sur l'instance de production, mesuré le 25/11/2026 :
# `conf.d/dataguard-ratelimit.conf` et
# `conf.d/selfjustice-ratelimit.conf` déclarent cinq zones que les gabarits de ces deux vhosts
# déclarent aussi — leurs commentaires le disent en prose (« retire ces lignes avant de poser »),
# et une prose ne refuse rien.
#
# $1 = candidat. $2 = fichier « <zone> <source> », une ligne par zone déjà déclarée sur le serveur,
# que le déployeur produit depuis la machine.
#
# ⚠️ **Le fichier $2 doit EXCLURE le vhost qu'on remplace.** Les zones qu'il déclare lui-même ne
# sont pas des collisions : elles disparaissent avec lui. Sans cette exclusion, toute pose d'un
# vhost qui garde ses propres zones serait refusée — le contrôle crierait sur le cas nominal.
zones_deja_declarees() {
  join -j 1 \
    <(cles_de_zone "$1" | awk '{ print $1 }' | sort -u) \
    <(awk 'NF >= 2 { print $1, $2 }' "$2" | sort -u -k1,1) \
    | awk '{ print $1 " : déjà déclarée dans " $2 }'
}
