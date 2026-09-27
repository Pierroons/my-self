#!/usr/bin/env bash
# L'initramfs de /boot est-il celui que la machine a produit ?
#
# 🔑 **Pourquoi ce script existe.** /boot n'est pas chiffré — le firmware doit le
# lire — et le sel SelfRecover y voyage. Qui modifie l'image y dépose de quoi
# capturer la passphrase à la saisie suivante, et rien ne le signalait. Fermer le
# shell du dropbear d'amorçage retire un chemin vers /boot ; il en reste d'autres
# (accès physique, root sur la machine en marche). **La classe ne se ferme pas :
# ce script la rend visible.**
#
# ⚠️ **Ce qu'il ne fait pas.** Il ne protège pas la saisie qui suit immédiatement
# une altération : il s'exécute à chaud, donc après le démarrage. Il ne vaut pas
# un scellement TPM, et il ne dit rien contre quelqu'un qui a déjà root sur la
# machine ouverte — celui-là peut réécrire l'empreinte. Son modèle de menace est
# étroit et assumé : **quelqu'un qui atteint /boot sans ouvrir le volume.**
#
# Ce qui le rend possible : l'empreinte est consignée dans $SKG, sur le volume
# CHIFFRÉ, par le hook post-update, après chaque génération légitime.
set -euo pipefail
SKG="${SKG:-/etc/selfkeyguard}"
BOOT="${BOOT:-/boot}"
EMPREINTES="${EMPREINTES:-$SKG/initramfs.sha256}"

passes=0; echecs=0; indetermines=0
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; passes=$((passes+1)); }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$*"; echecs=$((echecs+1)); }
hm()   { printf '  \033[33m?\033[0m %s\n' "$*"; indetermines=$((indetermines+1)); }

printf '\n\033[1;34m=== Intégrité des images initramfs ===\033[0m\n'

if [ ! -r "$EMPREINTES" ]; then
  # Distinct d'un échec : aucune empreinte n'a JAMAIS été consignée, donc il n'y
  # a rien à comparer. Un zéro ici n'est pas « tout va bien », c'est « on ne sait
  # pas » — et les deux ne se confondent pas.
  hm "aucune empreinte consignée ($EMPREINTES absent ou illisible)"
  hm "régénère l'initramfs (update-initramfs -u) pour en produire une"
  printf '\n%d concordante(s), %d écart(s), %d indéterminé(s)\n' "$passes" "$echecs" "$indetermines"
  exit 2
fi

# La boucle part des empreintes CONSIGNÉES, pas des images présentes : une image
# supprimée de /boot doit se voir, et partir de /boot la rendrait invisible.
while IFS=$'\t' read -r ver somme quand; do
  [ -n "${ver:-}" ] || continue
  img="$BOOT/initrd.img-$ver"
  if [ ! -r "$img" ]; then
    hm "$ver : consignée le ${quand:-?}, mais $img est absent ou illisible"
    continue
  fi
  reelle=$(sha256sum < "$img" | cut -d' ' -f1)
  if [ "$reelle" = "$somme" ]; then
    ok "$ver : concorde (consignée le ${quand:-?})"
  else
    bad "$ver : ÉCART — l'image de /boot n'est pas celle qui a été produite"
    printf '      consignée %s\n      présente  %s\n' "$somme" "$reelle"
    printf '      Si aucune régénération n a eu lieu depuis %s, traite la machine\n' "${quand:-?}"
    printf '      comme compromise : la passphrase saisie depuis a pu être capturée.\n'
  fi
done < "$EMPREINTES"

printf '\n%d concordante(s), %d écart(s), %d indéterminé(s)\n' "$passes" "$echecs" "$indetermines"
# Un écart sort 1, un indéterminé sort 2 : le second n'est pas un succès, et
# l'appelant doit pouvoir les distinguer sans lire la sortie.
[ "$echecs" -eq 0 ] || exit 1
[ "$indetermines" -eq 0 ] || exit 2
exit 0
