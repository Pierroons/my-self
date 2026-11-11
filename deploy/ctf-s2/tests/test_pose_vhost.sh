#!/usr/bin/env bash
# Le poseur de vhost sait-il reconnaître ce qu'un rechargement ne peut PAS appliquer ?
#
# Il n'a besoin ni de machine, ni de nginx, ni de réseau : il joue la détection sur des
# configurations écrites ici. Ce qu'il garde est la seule propriété qui a manqué le 11/11/2026 —
# distinguer « le fichier est posé » de « la configuration est active ».
#
#   bash deploy/ctf-s2/tests/test_pose_vhost.sh
#
# ZONES_LIB pointe une autre bibliothèque, pour les canaris de la CI : la plantation vit dans
# /tmp et le dépôt n'est jamais modifié.
set -euo pipefail

ICI=$(cd "$(dirname "$0")" && pwd)
LIB=${ZONES_LIB:-$ICI/../lib-zones.sh}
[ -r "$LIB" ] || { echo "❌ bibliothèque des zones illisible : $LIB"; exit 1; }
# shellcheck source=/dev/null
. "$LIB"

T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

cat > "$T/service" <<'FIN'
limit_req_zone $binary_remote_addr zone=lab_auth:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=lab_l3:10m rate=2r/s;
FIN
cat > "$T/debit-change" <<'FIN'
limit_req_zone $binary_remote_addr zone=lab_auth:10m rate=20r/s;
limit_req_zone $binary_remote_addr zone=lab_l3:10m rate=2r/s;
FIN
cat > "$T/cle-changee" <<'FIN'
limit_req_zone $cle_frein zone=lab_auth:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=lab_l3:10m rate=2r/s;
FIN
cat > "$T/zone-neuve" <<'FIN'
limit_req_zone $binary_remote_addr zone=lab_auth:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=lab_l3:10m rate=2r/s;
limit_req_zone $cle_frein zone=lab_derive:10m rate=2r/s;
FIN

ok=0
total=0

cas() {
  local titre=$1 attendu=$2 avant=$3 apres=$4 vu
  total=$((total + 1))
  vu=$(zones_a_cle_changee "$avant" "$apres")
  if [ "$vu" = "$attendu" ]; then
    ok=$((ok + 1))
    echo "  ✓ $titre"
  else
    echo "  ❌ $titre"
    echo "     attendu : « $attendu »"
    echo "     obtenu  : « $vu »"
  fi
}

echo "▸ détection des zones dont la clé change (la seule chose qu'un rechargement ne peut pas appliquer)"
cas "un débit changé à clé constante n'exige pas de redémarrage" \
  "" "$T/service" "$T/debit-change"
cas "un changement de clé doit être détecté" \
  "lab_auth : binary_remote_addr -> cle_frein" "$T/service" "$T/cle-changee"
# Une zone que le master ne porte pas encore ne peut pas entrer en conflit avec elle-même : la
# déclarer avec une clé neuve n'empêche pas le rechargement, et l'exiger ferait redémarrer nginx
# pour rien.
cas "une zone NEUVE à clé neuve n'exige pas de redémarrage" \
  "" "$T/service" "$T/zone-neuve"
# Le vhost réel du lab : il doit être détecté contre la forme d'avant le 11/11, sinon ce banc
# passerait à côté du cas qui l'a fait écrire.
if [ -r "$ICI/../nginx-ctf.conf" ]; then
  cas "le vhost du dépôt, contre la forme d'avant le 11/11, exige un redémarrage" \
    "lab_auth : binary_remote_addr -> cle_frein
lab_l3 : binary_remote_addr -> cle_frein" "$T/service" "$ICI/../nginx-ctf.conf"
fi

echo
if [ "$ok" = "$total" ]; then
  echo "✅ $ok/$total — le poseur reconnaît ce qu'un rechargement ne peut pas appliquer"
  exit 0
fi
echo "❌ $ok/$total — un cas au moins est passé à travers"
exit 1
