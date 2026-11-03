#!/usr/bin/env bash
# CTF Saison 2 — mise à jour du CODE, les données restent. À lancer sur le VPS du CTF, avec le compte d'administration :
#   bash maj-code.sh <SHA complet de main, 40 caractères>
# Le code neuf est construit à côté, puis data/ (base et secrets) y est déplacé d'un seul
# renommage. L'ancien arbre reste sur disque, sans ses données, pour le retour arrière.
set -euo pipefail

SHA="${1:?usage : $0 <SHA complet de main>}"
[[ $SHA =~ ^[0-9a-f]{40}$ ]] || { echo "❌ il faut le SHA complet (40 caractères hexadécimaux)"; exit 1; }
W="$HOME/ctf-s2-maj"
C=/var/www/ctf-s2
NEUF=$C.neuf
DATA=demo/lab/data

sudo test -d "$C/$DATA" || { echo "❌ $C/$DATA absent — rien n'est fait"; exit 1; }
[ ! -e "$W" ] || { echo "❌ $W existe déjà — rien n'est fait"; exit 1; }
sudo test ! -e "$NEUF" || { echo "❌ $NEUF existe déjà — rien n'est fait"; exit 1; }
AVANT=$(sudo cat "$C/REVISION")
[ "$AVANT" != "$SHA" ] || { echo "❌ $C est déjà à $SHA"; exit 1; }
ANCIEN=$C.ancien-${AVANT:0:7}
sudo test ! -e "$ANCIEN" || { echo "❌ $ANCIEN existe déjà — rien n'est fait"; exit 1; }
compter() { sudo -u www-data sqlite3 -readonly "$C/$DATA/lab.db" 'SELECT COUNT(*) FROM accounts; SELECT COUNT(*) FROM dm; SELECT COUNT(*) FROM memo_vault' | paste -sd' '; }
N_AVANT=$(compter)

arret_construction() {
  echo; echo "❌ Arrêt pendant la construction : le site servi n'a pas bougé. À nettoyer avant de relancer :"
  echo "  rm -rf $W; sudo rm -rf $NEUF"
}
arret_bascule() {
  echo; echo "❌ Arrêt PENDANT la bascule. Où sont les arbres et data/ :"
  sudo ls -d /var/www/ctf-s2* /var/www/ctf-s2*/$DATA 2>/dev/null
}
trap arret_construction ERR

echo "▸ 1. Code : clone public, contrôle du SHA, archive des seuls chemins utiles"
git clone -q --depth 1 --branch main https://github.com/Pierroons/my-self "$W/depot"
H=$(git -C "$W/depot" rev-parse HEAD)
[ "$H" = "$SHA" ] || { echo "❌ main = $H, attendu $SHA"; false; }
mkdir "$W/arbre"
git -C "$W/depot" -c tar.umask=0022 archive HEAD -- \
  demo/lab/composer.json demo/lab/schema.sql demo/lab/selfrecover-su \
  demo/lab/lib demo/lab/lang demo/lab/public demo/lab/seed.php \
  bi-self/selfrecover/composer.json bi-self/selfrecover/src bi-self/selfrecover/client bi-self/selfrecover/assets \
  self-security/selfdataguard/composer.json self-security/selfdataguard/src \
  bi-self/selfmoderate/composer.json bi-self/selfmoderate/src \
  LICENSE COPYRIGHT | tar -x -C "$W/arbre"
echo "$H" > "$W/arbre/REVISION"

echo "▸ 2. Composer, puis pose à côté en root:root"
(cd "$W/arbre/demo/lab" && composer install --no-dev --no-interaction --no-progress --quiet)
casses=$(find "$W/arbre/demo/lab/vendor/pierroons" "$W/arbre/demo/lab/public/js" -type l ! -exec test -e {} \; -print)
[ -z "$casses" ] || { echo "❌ liens cassés :"; echo "$casses"; false; }
nl=$(find "$W/arbre/demo/lab/public/js" -type l | wc -l)
[ "$nl" = 3 ] || { echo "❌ $nl liens JS, 3 attendus"; false; }
sudo mv "$W/arbre" "$NEUF"
sudo chown -R root:root "$NEUF"
sudo find "$NEUF" -type d -exec chmod 755 {} +
sudo find "$NEUF" -type f -exec chmod 644 {} +
sudo test ! -e "$NEUF/$DATA" || { echo "❌ l'arbre neuf porte déjà un data/"; false; }
echo "  ✓ $NEUF prêt (révision $H)"

echo "▸ 3. Bascule : data/ passe dans l'arbre neuf, puis les arbres échangent leur nom"
trap arret_bascule ERR
sudo mv "$C/$DATA" "$NEUF/$DATA"
sudo mv "$C" "$ANCIEN"
sudo mv "$NEUF" "$C"
# 🔑 L'arbre mis de cote garde le 755 qu'il avait en service, et `www-data` le lit
# donc encore alors que plus aucun vhost ne le sert. Par une lecture de fichier,
# un chercheur y relit l'historique complet des revisions du defi : tous les
# correctifs successifs, donc toutes les failles corrigees et la logique des
# drapeaux. Le jeu perd son brouillard sans que personne ne force quoi que ce
# soit. 750 suffit : seul root y entre, et le retour arriere ci-dessous est un
# geste root. Fermer les arbres existants a la main ne tenait pas — chaque
# deploiement en recreait un ouvert (mesure du 05/10).
sudo chmod 750 "$ANCIEN"
sudo systemctl reload php8.4-fpm
trap - ERR

echo "▸ 4. Contrôles"
retour() { echo "  Retour arrière :"
  echo "  sudo mv $C $C.rate && sudo mv $ANCIEN $C && sudo mv $C.rate/$DATA $C/$DATA && sudo systemctl reload php8.4-fpm"; }
[ "$(sudo cat "$C/REVISION")" = "$H" ] || { echo "❌ REVISION ne dit pas $H"; retour; exit 1; }
[ "$(sudo stat -c '%U:%G %a' "$C/$DATA")" = "www-data:www-data 700" ] || { echo "❌ data/ a perdu son propriétaire ou ses droits"; retour; exit 1; }
N_APRES=$(compter)
[ "$N_APRES" = "$N_AVANT" ] || { echo "❌ comptes, dm, coffres : $N_APRES, avant $N_AVANT"; retour; exit 1; }
echo "  ✓ données intactes (comptes, dm, coffres : $N_APRES)"
R=(--resolve ctf.my-self.fr:443:127.0.0.1 -s -o /dev/null -w '%{http_code}')
# Site fermé (auth_basic dans le vhost) : 401 sans identifiant, puis les contrôles avec le compte de l'authentification basique.
# Site ouvert : les mêmes contrôles, sans identifiant.
A=()
if sudo grep -q '^\s*auth_basic ' /etc/nginx/sites-available/lab; then
  c=$(curl "${R[@]}" https://ctf.my-self.fr/)
  [ "$c" = 401 ] || { echo "❌ site fermé, sans mot de passe : $c, 401 attendu"; retour; exit 1; }
  echo "  (site fermé : curl demande le mot de passe provisoire, deux fois)"
  A=(-u '<compte-site-ferme>')
fi
c=$(curl "${R[@]}" "${A[@]}" https://ctf.my-self.fr/)
[ "$c" = 200 ] || { echo "❌ / : $c, 200 attendu"; retour; exit 1; }
echo "  ✓ / : 200"
# sel.php lit la base et .sitesalt : il prouve que le code neuf trouve ses données.
s=$(curl --resolve ctf.my-self.fr:443:127.0.0.1 -s "${A[@]}" -X POST -H 'Content-Type: application/json' -d '{}' \
  https://ctf.my-self.fr/api/sel.php)
[[ $s =~ \"ok\":true,\"sel\":\"[0-9a-f]{32}\" ]] || { echo "❌ /api/sel.php : $s"; retour; exit 1; }
echo "  ✓ /api/sel.php répond, base et sel de site lus"
sudo sh -c "cd '$C' && find . -path ./$DATA -prune -o -type f -print0 | sort -z | xargs -0 sha256sum" \
  | sudo tee "/root/ctf-s2-empreintes-$H.txt" >/dev/null
rm -rf "$W"
echo "✅ Code à $H, données reprises. Ancien arbre (sans données) : $ANCIEN"
retour
