#!/usr/bin/env bash
# Le format de clé qu'un slot recover attend, écrit là où on peut le lire.
#
# 🔑 **Pourquoi ce script existe.** Un slot enrôlé en `--format raw` n'est pas
# ouvert par un keyscript qui produit de l'hexadécimal, et inversement. Poser le
# mauvais keyscript rend la machine inamorçable au redémarrage suivant, ou à la
# prochaine régénération d'initramfs d'une mise à jour du noyau. Jusqu'ici, le format
# ENRÔLÉ n'était écrit nulle part : seul `INSTALL.md` §15 mettait en garde, et rien
# ne s'opposait au geste.
#
# Le marqueur est `$SKG/format-slot`, une ligne par volume : `<UUID LUKS> <hex|raw>`.
# Il est indexé par volume et non global : un slot hex ajouté sur un volume de
# données ne dit rien du format de la racine, que le keyscript ouvre.
#
# Deux verbes, pour qu'un seul fichier connaisse le format du marqueur :
#
#   inscrire <volume> <hex|raw> [répertoire-selfkeyguard]
#       appelé par setup-add-selfrecover-slot.sh, une fois le slot PROUVÉ ouvrant ;
#       remplace la ligne de ce volume, garde les autres.
#
#   verifier <volume-racine> <keyscript-à-poser> [répertoire-selfkeyguard]
#       appelé par install.sh avant de poser le keyscript. Refuse :
#         - un keyscript dont le format enrôlé pour ce volume n'est pas celui qu'il produit ;
#         - un keyscript déjà en place sans marqueur pour ce volume — le format réel
#           est alors inconnu, et deviner est le seul geste interdit ici ;
#         - un marqueur ou un keyscript illisible.
#       Accepte une installation neuve : ni keyscript en place, ni marqueur.
#
# Sortie : 0 si accepté ou inscrit, 1 si refusé (avec la raison), 2 si mal appelé.
set -uo pipefail

# cryptsetup vit dans /sbin, absent du PATH d'un shell non interactif.
export PATH="/usr/sbin:/sbin:$PATH"

refus() { printf '❌ %s\n' "$*" >&2; exit 1; }
usage() {
  echo "usage : $0 inscrire <volume> <hex|raw> [répertoire-selfkeyguard]" >&2
  echo "        $0 verifier <volume-racine> <keyscript-à-poser> [répertoire-selfkeyguard]" >&2
  exit 2
}

uuid_de() {
  local u
  u="$(cryptsetup luksUUID "$1" 2>/dev/null)"
  [ -n "$u" ] || refus "$1 n'est pas un volume LUKS lisible."
  printf '%s' "$u"
}

VERBE="${1:-}"; shift || true
case "$VERBE" in
  inscrire)
    [ $# -ge 2 ] || usage
    VOLUME="$1"; FORMAT="$2"; SKG="${3:-/etc/selfkeyguard}"
    case "$FORMAT" in hex|raw) ;; *) refus "format inconnu : « $FORMAT » (hex ou raw)." ;; esac
    [ -d "$SKG" ] || refus "$SKG absent — marqueur non écrit."
    UUID="$(uuid_de "$VOLUME")" || exit 1
    MARQUEUR="$SKG/format-slot"
    NOUVEAU="$SKG/.format-slot.nouveau"
    # Écrit à côté puis renommé : un marqueur tronqué par une coupure laisserait un
    # format faux, et c'est lui qu'install.sh croirait.
    {
      [ -f "$MARQUEUR" ] && awk -v u="$UUID" '$1 != u' "$MARQUEUR"
      printf '%s %s\n' "$UUID" "$FORMAT"
    } > "$NOUVEAU" || { rm -f "$NOUVEAU"; refus "écriture impossible dans $SKG."; }
    chmod 0644 "$NOUVEAU"
    mv "$NOUVEAU" "$MARQUEUR" || refus "renommage impossible de $NOUVEAU."
    echo "✅ format enrôlé inscrit : $UUID $FORMAT → $MARQUEUR"
    ;;

  verifier)
    [ $# -ge 2 ] || usage
    VOLUME="$1"; KEYSCRIPT="$2"; SKG="${3:-/etc/selfkeyguard}"
    [ -r "$KEYSCRIPT" ] || refus "keyscript à poser introuvable : $KEYSCRIPT"
    # Le dernier `--format` du fichier est celui de la ligne exécutée : les
    # commentaires le citent avant elle.
    LIVRE="$(grep -oE -- '--format (hex|raw)' "$KEYSCRIPT" | tail -n1 | cut -d' ' -f2)"
    [ -n "$LIVRE" ] || refus "le keyscript $KEYSCRIPT ne dit pas quel format il produit (aucun --format hex|raw)."
    UUID="$(uuid_de "$VOLUME")" || exit 1
    MARQUEUR="$SKG/format-slot"
    ENROLE=""
    [ -f "$MARQUEUR" ] && ENROLE="$(awk -v u="$UUID" '$1 == u { f = $2 } END { print f }' "$MARQUEUR")"

    if [ -z "$ENROLE" ]; then
      if [ -e "$SKG/selfrecover-keyscript.sh" ]; then
        EN_PLACE="$(grep -oE -- '--format (hex|raw)' "$SKG/selfrecover-keyscript.sh" | tail -n1 | cut -d' ' -f2)"
        refus "un keyscript est déjà en place (il produit : ${EN_PLACE:-format inconnu}), mais le format
   enrôlé pour $VOLUME ($UUID) n'est écrit nulle part. Poser un keyscript $LIVRE
   sans le savoir peut rendre la machine inamorçable.
   Établis le format qui OUVRE le volume (INSTALL.md §6, par --test-passphrase avec
   --format hex puis --format raw), puis inscris-le :
     bash $0 inscrire $VOLUME <hex|raw> $SKG
   S'il s'agit de raw, la migration vers hex est INSTALL.md §15."
      fi
      echo "✅ installation neuve sur $VOLUME : aucun keyscript en place, aucun slot à respecter."
      exit 0
    fi

    case "$ENROLE" in hex|raw) ;; *) refus "marqueur illisible pour $UUID dans $MARQUEUR : « $ENROLE »." ;; esac
    [ "$ENROLE" = "$LIVRE" ] || refus "le slot de $VOLUME est enrôlé en $ENROLE, et le keyscript à poser produit $LIVRE.
   Il n'ouvrirait pas le volume : la machine deviendrait inamorçable.
   La migration, slot par slot et avec redémarrage éprouvé, est INSTALL.md §15."
    echo "✅ $VOLUME est enrôlé en $ENROLE, le keyscript à poser produit $LIVRE."
    ;;

  *) usage ;;
esac
