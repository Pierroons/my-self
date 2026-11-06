#!/usr/bin/env bash
# Pose sur le lab CTF le vhost que le dépôt porte, à la révision que la machine sert déjà.
#
# À lancer depuis un VRAI terminal : `sudo` du serveur a besoin d'un TTY, et le
# canal de Claude Code n'en fournit pas (« a terminal is required to read the
# password »). Rien ne bascule sans la confirmation demandée plus bas, et
# `nginx -t` garde le rechargement.
#
#   CTF_SSH_LECTURE=<alias> CTF_SSH_ADMIN=<alias> bash deploy/ctf-s2/deploy-vhost-ctf.sh
# shellcheck disable=SC2029  # SHA et VHOST sont développés sur le poste, exprès
set -euo pipefail

LECTURE=${CTF_SSH_LECTURE:?alias SSH du compte de lecture, sans privilège}
ADMIN=${CTF_SSH_ADMIN:?alias SSH du compte qui peut élever}
VHOST=/etc/nginx/sites-available/lab
GABARIT=deploy/ctf-s2/nginx-ctf.conf
SUFFIXE="avant-$(date +%Y%m%d-%H%M)"

echo "▸ 1. Révision que la machine sert (elle décide du gabarit, pas moi)"
SHA=$(ssh "$LECTURE" 'cat /var/www/ctf-s2/REVISION')
[[ $SHA =~ ^[0-9a-f]{40}$ ]] || { echo "❌ REVISION illisible : « $SHA »"; exit 1; }
echo "   $SHA"

echo "▸ 2. Empreinte du vhost en service, avant tout geste"
AVANT=$(ssh "$LECTURE" "sha256sum $VHOST" | cut -d' ' -f1)
echo "   $AVANT"

echo "▸ 3. Récupération du gabarit à ce SHA, par la machine, depuis le dépôt public"
ssh "$ADMIN" "set -e
  curl -fsSL -o /tmp/lab-vhost-candidat.conf \
    'https://raw.githubusercontent.com/Pierroons/my-self/$SHA/$GABARIT'
  echo -n '   candidat  '; sha256sum /tmp/lab-vhost-candidat.conf | cut -d' ' -f1"

echo "▸ 4. Ce qui changerait — lignes fonctionnelles seules (les commentaires suivent)"
ssh "$ADMIN" "diff '$VHOST' /tmp/lab-vhost-candidat.conf | grep -E '^[<>]' \
  | grep -vE '^[<>][[:space:]]*#|^[<>][[:space:]]*\$' || true"

echo
read -r -p "Poser ce vhost, sauvegarde en $VHOST.$SUFFIXE ? [oui/N] " REPONSE
[ "$REPONSE" = "oui" ] || { echo "⏹ rien n'est fait."; exit 0; }

echo "▸ 5. Sauvegarde, pose, test, rechargement — le test garde le rechargement"
ssh -t "$ADMIN" "set -e
  sudo cp -a '$VHOST' '$VHOST.$SUFFIXE'
  sudo install -m 644 -o root -g root /tmp/lab-vhost-candidat.conf '$VHOST'
  sudo /usr/sbin/nginx -t
  sudo systemctl reload nginx
  rm -f /tmp/lab-vhost-candidat.conf"

echo "▸ 6. Mesures à l'arrivée"
ssh "$LECTURE" "set -e
  echo -n '   vhost servi  '; sha256sum '$VHOST' | cut -d' ' -f1
  echo '   workers (ils doivent porter l heure du rechargement) :'
  ps -eo lstart,args | grep '[n]ginx: worker' | sed 's/^/     /'
  echo -n '   sauvegarde : '; ls -1 '$VHOST'.avant-* | tail -1"

echo "▸ 7. La page répond toujours"
curl -s -o /dev/null -m 20 -w '   HTTP %{http_code}\n' https://ctf.my-self.fr/security.php
echo "✓ terminé."
