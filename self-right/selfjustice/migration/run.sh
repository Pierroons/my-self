#!/bin/bash
# ===========================================================================
# MIGRATION — justice.example.com → your-instance.example + landing my-self.fr
# ===========================================================================
# À lancer avec sudo : sudo bash /tmp/migration/run.sh
#
# Ce script est idempotent : tu peux le rerunner, il ne fait pas n'importe quoi.
# Il log chaque étape et s'arrête à la première erreur.
# ===========================================================================

set -e  # Arrêter à la première erreur

log()  { echo -e "\n\033[1;34m==> $*\033[0m"; }
ok()   { echo -e "\033[1;32m✓ $*\033[0m"; }
warn() { echo -e "\033[1;33m⚠ $*\033[0m"; }
die()  { echo -e "\033[1;31m✗ $*\033[0m"; exit 1; }

if [ "$EUID" -ne 0 ]; then
    die "Ce script doit être lancé avec sudo."
fi

MIGDIR="/tmp/migration"
BACKUP_DIR="/root/migration-backup-$(date +%Y%m%d-%H%M%S)"

# ---------------------------------------------------------------------------
log "0. Backup de la config actuelle dans $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
cp /etc/nginx/sites-available/selfjustice "$BACKUP_DIR/selfjustice.conf.bak"
cp -r /var/www/selfjustice "$BACKUP_DIR/www-selfjustice.bak"
ok "Backup fait."

# ---------------------------------------------------------------------------
log "1. Copie du nouveau contenu HTML + API (URLs mises à jour)"
cp "$MIGDIR/site/index.html" /var/www/selfjustice/index.html
cp "$MIGDIR/site/index.html" /var/www/selfjustice/directives.html
cp "$MIGDIR/api/api.php" /var/www/selfjustice/api/api.php
chown -R www-data:www-data /var/www/selfjustice
ok "Contenu SelfJustice mis à jour."

# ---------------------------------------------------------------------------
log "2. Création du dossier /var/www/my-self + landing"
mkdir -p /var/www/my-self
cp "$MIGDIR/myself-index.html" /var/www/my-self/index.html
chown -R www-data:www-data /var/www/my-self
ok "Landing my-self.fr en place."

# ---------------------------------------------------------------------------
log "3. Préparation vhost temporaire (HTTP only) pour certbot"
# On doit d'abord permettre à Let's Encrypt de valider via port 80
# donc on crée des vhosts HTTP provisoires avant d'activer SSL.

cat > /etc/nginx/sites-available/myself-root-temp <<'EOF'
server {
    listen 80;
    server_name my-self.fr www.my-self.fr your-instance.example;
    root /var/www/my-self;
    location /.well-known/acme-challenge/ {
        root /var/www/my-self;
        allow all;
    }
    location / {
        return 200 "migration in progress\n";
        add_header Content-Type text/plain;
    }
}
EOF
ln -sf /etc/nginx/sites-available/myself-root-temp /etc/nginx/sites-enabled/myself-root-temp

# Vérif config
nginx -t || die "Config nginx invalide. Voir erreur ci-dessus."
systemctl reload nginx
ok "Vhost temporaire pour ACME challenge actif."

# ---------------------------------------------------------------------------
log "4. Obtention des certificats Let's Encrypt (certbot)"

# Cert pour your-instance.example
if [ ! -d /etc/letsencrypt/live/your-instance.example ]; then
    certbot certonly --webroot -w /var/www/my-self \
        -d your-instance.example \
        --non-interactive --agree-tos \
        -m contact@my-self.fr \
        || die "Échec certbot pour your-instance.example"
    ok "Cert your-instance.example créé."
else
    ok "Cert your-instance.example déjà présent."
fi

# Cert pour my-self.fr (+ www)
if [ ! -d /etc/letsencrypt/live/my-self.fr ]; then
    certbot certonly --webroot -w /var/www/my-self \
        -d my-self.fr -d www.my-self.fr \
        --non-interactive --agree-tos \
        -m contact@my-self.fr \
        || die "Échec certbot pour my-self.fr"
    ok "Cert my-self.fr créé."
else
    ok "Cert my-self.fr déjà présent."
fi

# ---------------------------------------------------------------------------
log "5. Désactivation du vhost temporaire + installation des vhosts définitifs"
rm -f /etc/nginx/sites-enabled/myself-root-temp

# Nouveau vhost SelfJustice (avec your-instance.example + redirect ancien domaine)
cp "$MIGDIR/nginx-selfjustice.conf" /etc/nginx/sites-available/selfjustice

# Nouveau vhost racine my-self.fr
cp "$MIGDIR/nginx-myself-root.conf" /etc/nginx/sites-available/myself-root
ln -sf /etc/nginx/sites-available/myself-root /etc/nginx/sites-enabled/myself-root

nginx -t || die "Config nginx finale invalide."
systemctl reload nginx
ok "Vhosts définitifs installés et nginx rechargé."

# ---------------------------------------------------------------------------
log "6. Tests end-to-end"
sleep 2  # laisser nginx respirer

test_url() {
    local url="$1"
    local expected="$2"
    local actual
    actual=$(curl -s -o /dev/null -w "%{http_code}" -L --max-redirs 0 "$url" 2>/dev/null || echo "000")
    if [ "$actual" = "$expected" ]; then
        ok "$url → $actual (attendu $expected)"
    else
        warn "$url → $actual (attendu $expected)"
    fi
}

test_url "https://your-instance.example/"                 "200"
test_url "https://my-self.fr/"                         "200"
test_url "https://www.my-self.fr/"                     "200"
test_url "https://justice.example.com/"             "301"
test_url "https://your-instance.example/api/status"       "200"
test_url "https://your-instance.example/api/stats/by-ai"  "200"

# Vérif que la redirection pointe bien vers le nouveau domaine
log "7. Vérif du Location header de la redirection"
LOCATION=$(curl -sI https://justice.example.com/ | grep -i "^location:" | tr -d '\r\n')
echo "   $LOCATION"
if echo "$LOCATION" | grep -q "your-instance.example"; then
    ok "Redirection 301 vers your-instance.example confirmée."
else
    warn "Redirection 301 semble cassée, vérifier manuellement."
fi

# ---------------------------------------------------------------------------
log "MIGRATION TERMINÉE"
echo ""
echo "Nouvelles URLs en prod :"
echo "  - https://my-self.fr           (landing)"
echo "  - https://your-instance.example   (SelfJustice)"
echo "  - https://your-instance.example/api/status"
echo ""
echo "Ancienne URL (301 → nouvelle) :"
echo "  - https://justice.example.com"
echo ""
echo "Backup de l'ancienne config : $BACKUP_DIR"
echo ""
echo "Si tout est OK, pense à supprimer $MIGDIR :"
echo "  sudo rm -rf $MIGDIR"
