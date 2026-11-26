#!/usr/bin/env bash
# Éprouve les décisions de `deploy/my-self/deploy-vhost.sh` SANS machine, SANS nginx et SANS réseau.
#
# 🔑 Ce qu'il mesure, c'est ce que le poseur REFUSE. Un poseur qui pose est facile à écrire ; ce qui
# coûte, c'est qu'il s'arrête devant les quatre cas qui cassent la production sans le dire.
#
#   bash deploy/my-self/tests/test_pose_vhost.sh
set -euo pipefail

cd "$(dirname "$0")/../../.."
# 🔑 `POSEUR` permet de viser une COPIE : un canari plante le défaut dans la copie et laisse le
# vrai script intact. Sans cette poignée, éprouver le banc obligerait à casser le fichier en
# service, et une interruption au mauvais moment le laisserait cassé.
POSEUR=${POSEUR:-deploy/my-self/deploy-vhost.sh}
# shellcheck source=deploy/my-self/deploy-vhost.sh
. "$POSEUR"

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
OK=0; KO=0

verifie() { # verifie <intitulé> <attendu> <obtenu>
    if [ "$2" = "$3" ]; then
        OK=$((OK + 1)); echo "  ✓ $1"
    else
        KO=$((KO + 1)); echo "  ✗ $1"
        echo "      attendu : « $2 »"
        echo "      obtenu  : « $3 »"
    fi
}

# ── Les fichiers d'essai ─────────────────────────────────────────────────────────────────────────

cat > "$T/servi" <<'EOF'
server {
    listen 443 ssl;
    server_name demo.exemple-de-test.fr;
    ssl_certificate /etc/ssl/demo.exemple-de-test.fr/fullchain.pem;
    root  /var/www/essai/racine;
    location / { try_files $uri =404; }
}
EOF

cat > "$T/gabarit" <<'EOF'
# Vhost pour your-instance.example
limit_req_zone $cle_frein zone=essai:10m rate=10r/s;
server {
    listen 443 ssl;
    server_name your-instance.example;
    ssl_certificate /etc/ssl/your-instance.example/fullchain.pem;
    root  /var/www/essai/racine;
    location / { limit_req zone=essai burst=5 nodelay; try_files $uri =404; }
}
EOF

echo "▸ 1. Apparier un gabarit et le vhost qu'il remplace"
verifie "le \`root\` est la clé d'appariement, espaces normalisés" \
    "/var/www/essai/racine" "$(racine_servie "$T/servi")"
verifie "le gabarit déclare le même \`root\`" \
    "$(racine_servie "$T/servi")" "$(racine_servie "$T/gabarit")"
cat > "$T/autre-racine" <<'EOF'
server { root /var/www/autre/chose; }
EOF
verifie "une paire incohérente se voit" \
    "non" "$([ "$(racine_servie "$T/servi")" = "$(racine_servie "$T/autre-racine")" ] && echo oui || echo non)"

echo "▸ 2. Le domaine se lit sur la machine, et ZÉRO ou PLUSIEURS sont des refus"
verifie "un nom unique est rendu" \
    "demo.exemple-de-test.fr" "$(domaine_unique "$T/servi")"
printf 'server {\n  server_name a.test b.test;\n}\n' > "$T/deux-noms"
verifie "deux noms sont REFUSÉS (choisir le premier poserait un certificat au nom d'un alias)" \
    "refus" "$(domaine_unique "$T/deux-noms" >/dev/null 2>&1 && echo rendu || echo refus)"
printf 'server {\n  listen 443;\n}\n' > "$T/sans-nom"
verifie "aucun nom est REFUSÉ" \
    "refus" "$(domaine_unique "$T/sans-nom" >/dev/null 2>&1 && echo rendu || echo refus)"
printf 'server {\n  server_name _;\n}\n' > "$T/defaut"
verifie "le vhost par défaut (\`server_name _\`) s'écarte de lui-même" \
    "refus" "$(domaine_unique "$T/defaut" >/dev/null 2>&1 && echo rendu || echo refus)"

echo "▸ 3. La substitution, et le refus s'il reste un gabarit"
substituer_domaine "$T/gabarit" "demo.exemple-de-test.fr" > "$T/candidat"
verifie "plus aucun gabarit ne subsiste après substitution" \
    "" "$(gabarits_restants "$T/candidat")"
verifie "le \`server_name\` porte le domaine réel" \
    "demo.exemple-de-test.fr" "$(domaine_unique "$T/candidat")"
verifie "le chemin de certificat suit le domaine, sans règle à part" \
    "1" "$(grep -c '/etc/ssl/demo.exemple-de-test.fr/fullchain.pem' "$T/candidat")"
# 🔑 Le cas qui tient à un fil : `your-instance.example` DOIT être substitué dans un vhost, alors
# que `deploy.sh` interdit de le mettre dans sa table — dans le code PHP servi, c'est un repli
# d'exécution. Deux sens pour une chaîne, selon le fichier qui la porte.
verifie "\`your-instance.example\` est bien substitué ICI (contrairement à la table de deploy.sh)" \
    "0" "$(grep -c 'your-instance.example' "$T/candidat" || true)"
printf 'server { server_name reste.example.org; }\n' > "$T/non-substitue"
verifie "un gabarit non substitué est DÉTECTÉ (le garde-fou qui manquait le 25/11/2026)" \
    "oui" "$([ -n "$(gabarits_restants "$T/non-substitue")" ] && echo oui || echo non)"

echo "▸ 4. Une zone déjà déclarée ailleurs : refus AVANT la copie"
printf 'essai /etc/nginx/conf.d/essai-ratelimit.conf\n' > "$T/ailleurs-collision"
verifie "la collision est nommée avec sa source" \
    "essai : déjà déclarée dans /etc/nginx/conf.d/essai-ratelimit.conf" \
    "$(zones_deja_declarees "$T/candidat" "$T/ailleurs-collision")"
printf 'autre /etc/nginx/conf.d/autre.conf\n' > "$T/ailleurs-sans-collision"
verifie "une zone étrangère ne déclenche rien" \
    "" "$(zones_deja_declarees "$T/candidat" "$T/ailleurs-sans-collision")"
: > "$T/ailleurs-vide"
verifie "une machine sans aucune zone ne déclenche rien" \
    "" "$(zones_deja_declarees "$T/candidat" "$T/ailleurs-vide")"

echo "▸ 5. Le geste : rechargement ou redémarrage, par la mesure"
verifie "une zone NEUVE n'exige pas de redémarrage — le cas réel de bi-self le 25/11/2026" \
    "" "$(zones_a_cle_changee "$T/servi" "$T/candidat")"
# shellcheck disable=SC2016  # le « $ » cherché est celui de la variable nginx, pas une expansion
sed 's/\$cle_frein/$binary_remote_addr/' "$T/candidat" > "$T/ancienne-cle"
verifie "la même zone à clé différente EXIGE un redémarrage" \
    "essai : binary_remote_addr -> cle_frein" \
    "$(zones_a_cle_changee "$T/ancienne-cle" "$T/candidat")"
sed 's/rate=10r\/s/rate=3r\/s/' "$T/candidat" > "$T/autre-debit"
verifie "un débit changé à clé constante n'exige pas de redémarrage" \
    "" "$(zones_a_cle_changee "$T/autre-debit" "$T/candidat")"

echo
if [ "$KO" = 0 ]; then
    echo "✅ $OK/$OK — le poseur refuse les quatre cas qui cassent sans le dire"
else
    echo "❌ $KO échec(s) sur $((OK + KO))"
    exit 1
fi
