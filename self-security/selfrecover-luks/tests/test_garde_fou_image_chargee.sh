#!/usr/bin/env bash
# Le garde-fou post-update vise-t-il l'image que l'amorceur CHARGE ?
#
# 🔑 **L'invariant que ce banc fixe.** `zz-verifie-selfrecover` doit rendre son
# verdict sur l'image d'amorçage réelle, pas sur celle qu'`update-initramfs` vient
# de produire. Sur x86+GRUB les deux coïncident — c'est pourquoi le défaut a pu
# vivre sans être vu. Sur un Raspberry Pi l'amorceur lit `config.txt` dans la
# partition firmware et charge une autre copie.
#
# Le 13/09/2026 sur le RPi4 : l'image générée portait les trois pièces, l'image
# chargée n'en portait aucune, `raspi-firmware` avait promu une sauvegarde `.bak.*`
# du module en image d'amorçage — et le contrôle affichait « initramfs complet ».
# La machine n'était pas amorçable par la passphrase Recover.
#
# Un contrôle qui ne vise pas la bonne cible est pire qu'une absence de contrôle :
# il rassure. Ce banc existe pour que ce cas-là ROUGISSE.
#
# Ne demande PAS root, ne touche aucun volume, aucune image réelle : tout se passe
# dans un répertoire jetable, et `lsinitramfs` est remplacé par un bouchon dont ce
# banc contrôle la sortie — ce qui permet de fabriquer « image complète » et
# « image vide » sans construire d'initramfs.
#
# Usage  : bash tests/test_garde_fou_image_chargee.sh
# Sortie : 0 si chaque cas rend le verdict attendu, 1 sinon.

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"
# Surchargeable pour éprouver le banc lui-même : on le pointe vers une copie du
# garde-fou dont la résolution de l'image chargée a été neutralisée, et les cas
# 3 à 6 doivent alors passer au vert — c'est-à-dire que le banc doit rougir.
# Un banc qu'on n'a jamais fait échouer ne se distingue pas d'un banc qui ne
# mesure rien : les deux rendent vert.
GARDE="${GARDE:-$MODULE/initramfs-post-update-verifie-selfrecover}"

[ -x "$GARDE" ] || { echo "❌ garde-fou introuvable ou non exécutable : $GARDE"; exit 1; }

echec=0
total=0
SORTIE=""

verdict() {  # verdict <libellé> <attendu VERT|ROUGE> <motif attendu dans la sortie|-->
  local libelle="$1" attendu="$2" motif="$3"; shift 3
  local obtenu code
  total=$((total + 1))
  SORTIE="$("$@" 2>&1)"; code=$?
  if [ "$code" -eq 0 ]; then obtenu=VERT; else obtenu=ROUGE; fi
  if [ "$obtenu" != "$attendu" ]; then
    printf '  ❌ %-52s %s (attendu %s)\n' "$libelle" "$obtenu" "$attendu"
    printf '%s\n' "$SORTIE" | sed 's/^/        /'
    echec=1
    return
  fi
  if [ "$motif" != "--" ] && ! printf '%s' "$SORTIE" | grep -qi -- "$motif"; then
    printf '  ❌ %-52s %s mais le message ne dit pas « %s »\n' "$libelle" "$obtenu" "$motif"
    printf '%s\n' "$SORTIE" | sed 's/^/        /'
    echec=1
    return
  fi
  printf '  ✅ %-52s %s\n' "$libelle" "$obtenu"
}

BANC="$(mktemp -d)"
trap 'rm -rf "$BANC"' EXIT

# --- le bouchon lsinitramfs -------------------------------------------------
# Il rend la liste des pièces pour toute image dont le nom contient « complete »,
# et rien pour les autres. C'est ce qui permet de fabriquer l'écart du 13/09 :
# une image générée complète, une image chargée vide.
mkdir -p "$BANC/bin"
cat > "$BANC/bin/lsinitramfs" <<'STUB'
#!/bin/sh
case "$1" in
  *complete*)
    echo "etc/selfkeyguard/selfrecover_derive_c"
    echo "etc/selfkeyguard/selfrecover_salt"
    echo "etc/selfkeyguard/selfrecover-keyscript"
    echo "usr/lib/x86_64-linux-gnu/libargon2.so.1"
    echo "usr/lib/x86_64-linux-gnu/libgcc_s.so.1"
    echo "usr/sbin/cryptsetup"
    ;;
  *) : ;;   # image muette : aucune pièce
esac
STUB
chmod 0755 "$BANC/bin/lsinitramfs"

# --- le décor commun --------------------------------------------------------
VER=6.12.0-banc
mkdir -p "$BANC/skg" "$BANC/boot" "$BANC/firmware"
printf 'sel-de-banc\n' > "$BANC/skg/selfrecover_salt"
printf 'cryptroot UUID=banc none luks,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh\n' \
  > "$BANC/crypttab"

lancer() {  # lancer <image-generee> — les variables de décor viennent de l'appelant
  PATH="$BANC/bin:$PATH" \
  SKG="$BANC/skg" CRYPTTAB="$BANC/crypttab" FW="$BANC/firmware" \
    "$GARDE" "$VER" "$1"
}

echo
echo "▸ garde-fou : $GARDE"
echo "▸ banc      : $BANC"
echo

# ---------------------------------------------------------------------------
echo "▸ Cas verts — le contrôle ne doit pas crier sans raison"

# 1. Pas de config.txt : plateforme sans partition firmware (x86+GRUB).
GEN="$BANC/boot/initrd.img-$VER.complete"
: > "$GEN"
verdict "sans config.txt, image générée complète" VERT "cible d amorcage non resolue" \
  lancer "$GEN"

# 2. config.txt désigne exactement l'image qu'on vient de produire.
cp "$GEN" "$BANC/firmware/initrd.img-$VER.complete"
printf 'initramfs initrd.img-%s.complete\n' "$VER" > "$BANC/firmware/config.txt"
verdict "l'amorceur charge une image chargée complète" VERT "verifiee et concordante" \
  lancer "$GEN"

echo
echo "▸ Cas rouges — chacun est un défaut replanté, il DOIT crier"

# 3. LE CAS DU 13/09 : image générée complète, image chargée vide.
#    C'est exactement ce que l'ancien contrôle déclarait « complet ».
printf 'initramfs initrd.img-%s.vide\n' "$VER" > "$BANC/firmware/config.txt"
: > "$BANC/firmware/initrd.img-$VER.vide"
verdict "13/09 : générée complète, CHARGÉE vide" ROUGE "absentes de l'image CHARGEE" \
  lancer "$GEN"

# 4. Le filet promu en cible : config.txt désigne une sauvegarde du module.
printf 'initramfs initrd.img-%s.complete.bak.1789332878\n' "$VER" > "$BANC/firmware/config.txt"
: > "$BANC/firmware/initrd.img-$VER.complete.bak.1789332878"
verdict "l'amorceur charge un .bak.* du module" ROUGE "SAUVEGARDE" \
  lancer "$GEN"

# 5. La copie vers la partition d'amorçage n'a pas eu lieu : image chargée
#    complète mais plus ancienne que celle qui vient d'être générée.
printf 'initramfs initrd.img-%s.complete\n' "$VER" > "$BANC/firmware/config.txt"
: > "$BANC/firmware/initrd.img-$VER.complete"
touch -d '2020-01-01 00:00:00' "$BANC/firmware/initrd.img-$VER.complete"
touch "$GEN"
verdict "image chargée PLUS ANCIENNE que la générée" ROUGE "PLUS ANCIENNE" \
  lancer "$GEN"

# 6. config.txt désigne un fichier qui n'existe pas.
printf 'initramfs initrd.img-%s.fantome\n' "$VER" > "$BANC/firmware/config.txt"
verdict "config.txt désigne une image absente" ROUGE "qui n'existe pas" \
  lancer "$GEN"

# 7. Contrôle du contrôle : l'image générée elle-même incomplète reste détectée
#    — la nouvelle logique ne doit pas avoir désarmé l'ancienne.
GEN_VIDE="$BANC/boot/initrd.img-$VER.muette"
: > "$GEN_VIDE"
printf 'initramfs initrd.img-%s.complete\n' "$VER" > "$BANC/firmware/config.txt"
touch "$BANC/firmware/initrd.img-$VER.complete"
verdict "image GÉNÉRÉE incomplète (contrôle d'origine)" ROUGE "Pieces absentes" \
  lancer "$GEN_VIDE"

# 8. Le module inactif ne déclenche rien, même avec une image cassée.
printf 'cryptroot UUID=banc none luks\n' > "$BANC/crypttab"
verdict "module non actif dans crypttab : silence" VERT "--" \
  lancer "$GEN_VIDE"

echo
if [ "$echec" -eq 0 ]; then
  printf '✅ %d/%d — le garde-fou vise l image chargée, et il rougit sur les cinq défauts replantés.\n' \
    "$total" "$total"
  exit 0
fi
printf '❌ des cas ont rendu le mauvais verdict (%d cas joués).\n' "$total"
exit 1
