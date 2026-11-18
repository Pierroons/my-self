#!/usr/bin/env bash
# Pose sur le lab CTF le vhost que le dépôt porte, à la révision que la machine sert déjà.
#
# À lancer depuis un VRAI terminal : `sudo` du serveur a besoin d'un TTY, et le
# canal de Claude Code n'en fournit pas (« a terminal is required to read the
# password »). Rien ne bascule sans la confirmation demandée plus bas, et un
# `nginx -t` qui échoue REMET le gabarit précédent avant de rendre la main.
#
#   CTF_SSH_LECTURE=<alias> CTF_SSH_ADMIN=<alias> bash deploy/ctf-s2/deploy-vhost-ctf.sh
#
# La détection de clé qu'il emploie s'éprouve seule, sans machine ni nginx :
#
#   bash deploy/ctf-s2/tests/test_pose_vhost.sh
#
# shellcheck disable=SC2029  # SHA et VHOST sont développés sur le poste, exprès
set -euo pipefail

VHOST=/etc/nginx/sites-available/lab
GABARIT=deploy/ctf-s2/nginx-ctf.conf
DEPOT=https://raw.githubusercontent.com/Pierroons/my-self

# shellcheck source=deploy/ctf-s2/lib-zones.sh
. "$(cd "$(dirname "$0")" && pwd)/lib-zones.sh"

LECTURE=${CTF_SSH_LECTURE:?alias SSH du compte de lecture, sans privilège}
ADMIN=${CTF_SSH_ADMIN:?alias SSH du compte qui peut élever}
SUFFIXE="avant-$(date +%Y%m%d-%H%M)"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

echo "▸ 1. Révision que la machine sert (elle décide du gabarit, pas moi)"
SHA=$(ssh "$LECTURE" 'cat /var/www/ctf-s2/REVISION')
[[ $SHA =~ ^[0-9a-f]{40}$ ]] || { echo "❌ REVISION illisible : « $SHA »"; exit 1; }
echo "   $SHA"

echo "▸ 2. Empreinte du vhost en service, avant tout geste"
ssh "$LECTURE" "cat $VHOST" > "$T/service"
echo "   $(sha256sum "$T/service" | cut -d' ' -f1)"

echo "▸ 3. Récupération du gabarit à ce SHA, par la machine, depuis le dépôt public"
ssh "$ADMIN" "set -e
  curl -fsSL -o /tmp/lab-vhost-candidat.conf '$DEPOT/$SHA/$GABARIT'
  echo -n '   candidat  '; sha256sum /tmp/lab-vhost-candidat.conf | cut -d' ' -f1"

echo "▸ 4. Ce qui changerait — lignes fonctionnelles seules (les commentaires suivent)"
ssh "$ADMIN" "diff '$VHOST' /tmp/lab-vhost-candidat.conf | grep -E '^[<>]' \
  | grep -vE '^[<>][[:space:]]*#|^[<>][[:space:]]*\$' || true"

echo "▸ 5. Une zone de frein change-t-elle de clé ? (c'est ce qu'un rechargement ne peut pas appliquer)"
curl -fsSL -o "$T/candidat" "$DEPOT/$SHA/$GABARIT"
CHANGEES=$(zones_a_cle_changee "$T/service" "$T/candidat")
if [ -n "$CHANGEES" ]; then
  GESTE=restart
  echo "   ⚠️ clé(s) changée(s) — un rechargement serait refusé SANS ERREUR VISIBLE, donc REDÉMARRAGE :"
  # shellcheck disable=SC2001  # indentation ligne par ligne : une expansion ne la ferait pas
  echo "$CHANGEES" | sed 's/^/     /'
  echo "     (coupure d'environ une seconde, et les compteurs de freins en cours repartent de zéro)"
else
  GESTE=reload
  echo "   ✓ aucune clé de zone ne change — un rechargement suffit"
fi

echo
read -r -p "Poser ce vhost par un $GESTE, sauvegarde en $VHOST.$SUFFIXE ? [oui/N] " REPONSE
[ "$REPONSE" = "oui" ] || { echo "⏹ rien n'est fait."; exit 0; }

echo "▸ 6. Sauvegarde, pose, test, $GESTE — un test en échec REMET le précédent"
POSE=$(date +%s)
# 🔑 La pose précède forcément le test : `nginx -t` lit la configuration SUR LE
# DISQUE, il ne sait pas juger un fragment qui n'est pas en place. Ce qui doit
# donc être garanti n'est pas l'ordre des gestes, c'est l'état LAISSÉ : ou le
# candidat est valide et chargé, ou le précédent est rétabli.
#
# Avant le 18/11/2026, un `nginx -t` en échec laissait le fragment fautif posé
# et `set -e` rendait la main : le service continuait sur sa configuration en
# MÉMOIRE, donc rien ne paraissait cassé. C'est le rechargement suivant —
# un autre déploiement, une rotation de certificat, un redémarrage, des jours
# plus tard — qui mettait le lab à terre, sans que personne relie les deux.
# Signalé par la session hors canal du 18/11, sur le lab en saison.
ssh -t "$ADMIN" "set -e
  sudo cp -a '$VHOST' '$VHOST.$SUFFIXE'
  sudo install -m 644 -o root -g root /tmp/lab-vhost-candidat.conf '$VHOST'
  if ! sudo /usr/sbin/nginx -t; then
    echo \"   ❌ nginx refuse ce gabarit — je remets le précédent\"
    sudo cp -a '$VHOST.$SUFFIXE' '$VHOST'
    if sudo /usr/sbin/nginx -t; then
      echo \"   ✓ gabarit précédent rétabli et valide : l'instance est dans l'état d'avant\"
    else
      echo \"   🔴 le gabarit précédent NE PASSE PAS NON PLUS : la cause est ailleurs dans /etc/nginx,\"
      echo \"      et le prochain rechargement de nginx échouera. À traiter avant toute autre pose.\"
    fi
    rm -f /tmp/lab-vhost-candidat.conf
    exit 1
  fi
  sudo systemctl $GESTE nginx
  rm -f /tmp/lab-vhost-candidat.conf"

echo "▸ 7. La configuration posée est-elle celle qui SERT ? (l'âge des workers le dit)"
# Un worker plus vieux que le geste n'a pas été remplacé : le master a refusé la nouvelle
# configuration et garde l'ancienne. C'est le seul endroit du script qui distingue
# « le fichier est posé » de « la configuration est active ».
sleep 2
LIMITE=$(( $(date +%s) - POSE + 10 ))
AGES=$(ssh "$LECTURE" "ps -eo etimes,args | grep '[n]ginx: worker' | awk '{ print \$1 }'")
[ -n "$AGES" ] || { echo "❌ aucun worker nginx ne tourne — le service est à terre."; exit 1; }
TOTAL=$(echo "$AGES" | wc -l)
VIEUX=$(echo "$AGES" | awk -v l="$LIMITE" '$1 > l { n++ } END { print n+0 }')
if [ "$VIEUX" != 0 ]; then
  echo "❌ $VIEUX worker(s) sur $TOTAL sont plus vieux que le geste ($LIMITE s) :"
  echo "   LE FICHIER EST POSÉ MAIS LA CONFIGURATION N'EST PAS APPLIQUÉE."
  echo "   nginx a refusé de la charger et continue de servir l'ancienne. Son verdict :"
  ssh "$LECTURE" "grep -i emerg /var/log/nginx/error.log | tail -3 || tail -3 /var/log/nginx/error.log" | sed 's/^/     /'
  echo "   Reprendre par un redémarrage :"
  echo "     ssh -t $ADMIN 'sudo /usr/sbin/nginx -t && sudo systemctl restart nginx'"
  echo "   Ou revenir en arrière :"
  echo "     ssh -t $ADMIN 'sudo cp -a $VHOST.$SUFFIXE $VHOST && sudo systemctl restart nginx'"
  exit 1
fi
echo "   ✓ les $TOTAL worker(s) ont tous moins de $LIMITE s : la configuration posée est celle qui sert"

echo "▸ 8. Mesures à l'arrivée"
ssh "$LECTURE" "set -e
  echo -n '   vhost servi  '; sha256sum '$VHOST' | cut -d' ' -f1
  echo -n '   sauvegarde : '; ls -1 '$VHOST'.avant-* | tail -1"

echo "▸ 9. La page répond toujours"
curl -s -o /dev/null -m 20 -w '   HTTP %{http_code}\n' https://ctf.my-self.fr/security.php
echo "✓ terminé."
