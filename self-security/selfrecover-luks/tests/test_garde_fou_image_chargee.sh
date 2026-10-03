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
  *sans-secours*)   # dropbear embarqué, mais pas le script qu'il lance au boot
    echo "usr/sbin/dropbear"
    echo "etc/selfkeyguard/selfrecover_derive_c"
    echo "etc/selfkeyguard/selfrecover_salt"
    echo "etc/selfkeyguard/selfrecover-keyscript"
    echo "usr/lib/x86_64-linux-gnu/libargon2.so.1"
    echo "usr/lib/x86_64-linux-gnu/libgcc_s.so.1"
    echo "usr/sbin/cryptsetup"
    ;;
  *serveur*)        # serveur : dropbear et le script qu'il lance
    echo "usr/sbin/dropbear"
    echo "etc/selfkeyguard/selfrecover-secours.sh"
    echo "etc/selfkeyguard/selfrecover_derive_c"
    echo "etc/selfkeyguard/selfrecover_salt"
    echo "etc/selfkeyguard/selfrecover-keyscript"
    echo "usr/lib/x86_64-linux-gnu/libargon2.so.1"
    echo "usr/lib/x86_64-linux-gnu/libgcc_s.so.1"
    echo "usr/sbin/cryptsetup"
    ;;
  *poste*)          # poste au clavier : ni dropbear ni script de secours
    echo "etc/selfkeyguard/selfrecover_derive_c"
    echo "etc/selfkeyguard/selfrecover_salt"
    echo "etc/selfkeyguard/selfrecover-keyscript"
    echo "usr/lib/x86_64-linux-gnu/libargon2.so.1"
    echo "usr/lib/x86_64-linux-gnu/libgcc_s.so.1"
    echo "usr/sbin/cryptsetup"
    ;;
  *complete*)
    echo "etc/selfkeyguard/selfrecover_derive_c"
    echo "etc/selfkeyguard/selfrecover_salt"
    echo "etc/selfkeyguard/selfrecover-keyscript"
    echo "etc/selfkeyguard/selfrecover-secours.sh"
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

# 4 bis. Sans le script de secours, une clé d'amorçage portant son `command=`
#        ne rend plus rien : la machine devient injoignable au boot.
SANS="$BANC/boot/initrd.img-$VER.sans-secours"
: > "$SANS"
cp "$SANS" "$BANC/firmware/initrd.img-$VER.sans-secours"
printf 'initramfs initrd.img-%s.sans-secours\n' "$VER" > "$BANC/firmware/config.txt"
verdict "dropbear embarqué sans le script de secours" ROUGE "script de secours" \
  lancer "$SANS"

# 4 ter. Sans dropbear, le script de secours n'a rien à faire dans l'image : le hook
#        ne l'embarque que s'il est posé, le garde-fou ne l'exige pas.
POSTE="$BANC/boot/initrd.img-$VER.poste"
: > "$POSTE"
cp "$POSTE" "$BANC/firmware/initrd.img-$VER.poste"
printf 'initramfs initrd.img-%s.poste\n' "$VER" > "$BANC/firmware/config.txt"
verdict "poste sans dropbear ni script de secours" VERT "--" \
  lancer "$POSTE"

# 4 quater. Un serveur complet : dropbear ET le script de secours.
SERVEUR="$BANC/boot/initrd.img-$VER.serveur"
: > "$SERVEUR"
cp "$SERVEUR" "$BANC/firmware/initrd.img-$VER.serveur"
printf 'initramfs initrd.img-%s.serveur\n' "$VER" > "$BANC/firmware/config.txt"
verdict "serveur avec dropbear et script de secours" VERT "--" \
  lancer "$SERVEUR"

# 4 quinquies. Image générée de serveur, image CHARGÉE sans dropbear ni secours :
#              l'exigence vient de la générée, la chargée doit la tenir.
cp "$POSTE" "$BANC/firmware/initrd.img-$VER.poste"
printf 'initramfs initrd.img-%s.poste\n' "$VER" > "$BANC/firmware/config.txt"
verdict "générée serveur, CHARGÉE sans script de secours" ROUGE "absentes de l'image CHARGEE" \
  lancer "$SERVEUR"

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

# ---------------------------------------------------------------------------
echo
echo "▸ Le sel, selon la forme de l'image — le trou du 15/09/2026"
#
# `unmkinitramfs` extrait à la racine une image d'un seul tenant, mais éclate en
# `early/` + `main/` celle qui porte un segment de microcode — c'est-à-dire
# l'image de toute machine Intel ou AMD. Le contrôle cherchait le sel à la
# racine : sur ces machines il ne le trouvait jamais, annonçait « sel absent de
# l'image extraite » et sortait en 0. Un sel périmé y passait en silence.
printf 'cryptroot UUID=banc none luks,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh\n' \
  > "$BANC/crypttab"
rm -f "$BANC/firmware/config.txt"          # on ne juge plus que le sel

cat > "$BANC/bin/unmkinitramfs" <<'STUB'
#!/bin/sh
# Reproduit les deux dispositions réelles de l'outil, choisies par le nom de
# l'image : « microcode » donne early/ + main/, sinon tout est à la racine.
# L'image porte aussi sa crypttab et le keyscript qu'elle désigne : hex sous
# keyfile-size=64 par défaut ; « raw » donne un keyscript raw sous 32, « borne32 »
# une borne 32, « sansborne » aucune borne, « deuxlignes » une seconde ligne sous 32,
# « sansligne » aucune ligne à keyscript, « sanscrypttab » pas de crypttab du tout.
case "$1" in
  *microcode*) racine="$2/main"; mkdir -p "$2/early" ;;
  *)           racine="$2" ;;
esac
mkdir -p "$racine/etc/selfkeyguard" "$racine/cryptroot"
case "$1" in
  *selperime*) printf 'sel-etranger\n' ;;
  *)           printf 'sel-de-banc\n' ;;
esac > "$racine/etc/selfkeyguard/selfrecover_salt"
case "$1" in
  *raw*) source_ks="$BANC_KS_RAW" ;;
  *)     source_ks="$BANC_KS_HEX" ;;
esac
cp "$source_ks" "$racine/etc/selfkeyguard/selfrecover-keyscript.sh"
case "$1" in
  *borne32*)   borne=",keyfile-size=32" ;;
  *sansborne*) borne="" ;;
  *raw*)       borne=",keyfile-size=32" ;;
  *)           borne=",keyfile-size=64" ;;
esac
[ -z "${1##*sanscrypttab*}" ] && exit 0
case "$1" in
  *sansligne*)
    printf 'cryptroot UUID=banc none luks\n' ;;
  *deuxlignes*)
    printf 'cryptroot UUID=banc none luks,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh%s\n' "$borne"
    printf 'reprise UUID=banc2 none luks,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh,keyfile-size=32\n' ;;
  *)
    printf 'cryptroot UUID=banc none luks,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh%s\n' "$borne" ;;
esac > "$racine/cryptroot/crypttab"
STUB
chmod 0755 "$BANC/bin/unmkinitramfs"

# Le garde-fou lit format-slot.sh dans $SKG, où install.sh le pose.
cp "$MODULE/format-slot.sh" "$BANC/skg/format-slot.sh"
export BANC_KS_HEX="$MODULE/selfrecover-keyscript.sh"
export BANC_KS_RAW="$BANC/keyscript-raw.sh"
sed '$ s/--format hex/--format raw/' "$BANC_KS_HEX" > "$BANC_KS_RAW"

# 9. Image d'un seul tenant, sel concordant — le cas qui marchait déjà.
GEN_PLAT="$BANC/boot/initrd.img-$VER.complete.plat"
: > "$GEN_PLAT"
verdict "image plate, sel concordant" VERT "complet" \
  lancer "$GEN_PLAT"

# 10. Image à microcode, sel concordant. Avant le correctif : « SEL NON VERIFIE ».
GEN_UCODE="$BANC/boot/initrd.img-$VER.complete.microcode"
: > "$GEN_UCODE"
verdict "image à microcode, sel concordant" VERT "complet" \
  lancer "$GEN_UCODE"

# 11. LE DÉFAUT QUI TUE : image à microcode, sel périmé. Avant le correctif, le
#     contrôle sortait en 0 — et le volume ne se serait pas ouvert au redémarrage.
GEN_UCODE_KO="$BANC/boot/initrd.img-$VER.complete.microcode.selperime"
: > "$GEN_UCODE_KO"
verdict "image à microcode, SEL PÉRIMÉ" ROUGE "sel embarque DIFFERE" \
  lancer "$GEN_UCODE_KO"

# 12. Le même sel périmé dans une image plate : déjà attrapé, ne doit pas régresser.
GEN_PLAT_KO="$BANC/boot/initrd.img-$VER.complete.plat.selperime"
: > "$GEN_PLAT_KO"
verdict "image plate, SEL PÉRIMÉ (non-régression)" ROUGE "sel embarque DIFFERE" \
  lancer "$GEN_PLAT_KO"

# ---------------------------------------------------------------------------
echo
echo "▸ La borne keyfile-size de l'image — celle que l'amorçage lit"
#
# Une machine passée de raw à hex garde sa borne raw si personne ne la corrige :
# l'image porte alors un keyscript hex sous keyfile-size=32, cryptsetup lit 32 des
# 64 caractères, et le volume ne s'ouvre plus au démarrage.

# 13. Keyscript raw sous sa borne 32 : l'état d'une machine équipée en raw.
GEN_RAW="$BANC/boot/initrd.img-$VER.complete.raw"
: > "$GEN_RAW"
verdict "keyscript raw sous keyfile-size=32" VERT "complet" \
  lancer "$GEN_RAW"

# 14. Pas de borne : cryptsetup lit tout le flux, la clé ouvre.
GEN_SANS="$BANC/boot/initrd.img-$VER.complete.sansborne"
: > "$GEN_SANS"
verdict "keyscript hex sans borne" VERT "complet" \
  lancer "$GEN_SANS"

# 15. LE CAS DE LA MIGRATION : keyscript hex sous la borne raw.
GEN_B32="$BANC/boot/initrd.img-$VER.complete.borne32"
: > "$GEN_B32"
verdict "keyscript hex sous keyfile-size=32" ROUGE "BORNE keyfile-size" \
  lancer "$GEN_B32"

# 16. Le même dans une image à microcode : la crypttab vit sous main/.
GEN_B32_UCODE="$BANC/boot/initrd.img-$VER.complete.microcode.borne32"
: > "$GEN_B32_UCODE"
verdict "image à microcode, keyscript hex sous 32" ROUGE "BORNE keyfile-size" \
  lancer "$GEN_B32_UCODE"

# 17. Sans format-slot.sh, la borne n'est pas jugée — et le contrôle le dit au
#     lieu d'annoncer « complet ».
mv "$BANC/skg/format-slot.sh" "$BANC/skg/format-slot.sh.retire"
verdict "format-slot.sh absent : borne NON vérifiée, dit" VERT "BORNE NON VERIFIEE" \
  lancer "$GEN_B32"
total=$((total + 1))
if printf '%s' "$SORTIE" | grep -q 'complet'; then
  printf '  ❌ %-52s %s\n' "… sans annoncer « complet »" "il l'annonce"; echec=1
else
  printf '  ✅ %-52s %s\n' "… sans annoncer « complet »" "VERT"
fi
mv "$BANC/skg/format-slot.sh.retire" "$BANC/skg/format-slot.sh"

# 18. Une seconde ligne à keyscript dans l'image, sous la borne raw : elle aussi
#     tournera, elle aussi est jugée.
GEN_DEUX="$BANC/boot/initrd.img-$VER.complete.deuxlignes"
: > "$GEN_DEUX"
verdict "seconde ligne de l'image sous keyfile-size=32" ROUGE "reprise" \
  lancer "$GEN_DEUX"

# 19. L'image ne porte plus la ligne : cryptsetup-initramfs l'a écartée (option
#     invalide). Le volume ne s'ouvrira pas, et ce n'est pas un « non vérifié ».
GEN_SANS_LIGNE="$BANC/boot/initrd.img-$VER.complete.sansligne"
: > "$GEN_SANS_LIGNE"
verdict "aucune ligne à keyscript dans l'image" ROUGE "AUCUNE ligne" \
  lancer "$GEN_SANS_LIGNE"

# 20. Le sel est extrait mais l'image n'a pas de crypttab : même verdict, ce n'est pas
#     une extraction ratée.
GEN_SANS_CT="$BANC/boot/initrd.img-$VER.complete.sanscrypttab"
: > "$GEN_SANS_CT"
verdict "sel extrait, image sans crypttab" ROUGE "AUCUNE ligne" \
  lancer "$GEN_SANS_CT"

# 21. Une ligne à keyscript COMMENTÉE dans /etc/crypttab n'active pas le module : le
#     contrôle de la crypttab embarquée ne doit pas exiger de ligne.
cp "$BANC/crypttab" "$BANC/crypttab.actif"
printf '# cryptroot UUID=banc none luks,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh\n' \
  > "$BANC/crypttab"
verdict "ligne à keyscript commentée : module inactif" VERT "--" \
  lancer "$GEN_SANS_LIGNE"
mv "$BANC/crypttab.actif" "$BANC/crypttab"

echo
if [ "$echec" -eq 0 ]; then
  printf '✅ %d/%d — le garde-fou vise l image chargée, et il rougit sur chaque défaut replanté.\n' \
    "$total" "$total"
  exit 0
fi
printf '❌ des cas ont rendu le mauvais verdict (%d cas joués).\n' "$total"
exit 1
