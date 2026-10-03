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
# Un seul fichier connaît le format du marqueur, et la longueur de clé qui en découle :
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
#   taille <keyscript>
#       la longueur de la clé que ce keyscript présente à cryptsetup : 64 en hex, 32 en raw.
#
#   borne <nom-crypttab> <keyscript> [crypttab] [--ecrire]
#       l'option keyfile-size= de la ligne <nom> est-elle cette longueur ? cryptsetup lit
#       EXACTEMENT la borne : plus courte, il tronque la clé ; plus longue, il échoue faute
#       d'octets. Sans borne, il lit tout le flux, et la clé ouvre. Avec --ecrire, pose ou
#       corrige la valeur sur cette ligne seule, après une copie datée de la crypttab.
#       L'image d'amorçage embarque sa propre copie de la crypttab : la borne ne change à
#       l'amorçage qu'à la régénération suivante, avec le keyscript du même moment.
#
# Sortie : 0 si accepté, inscrit ou conforme ; 1 si refusé ou borne différente (avec la
# raison) ; 2 si mal appelé ; 3 si la borne est absente (lecture seule) ; 4 si la borne
# ne peut pas être jugée — ligne introuvable ou sans options, keyscript muet sur son format.
# Un garde-fou distingue ainsi une borne fausse d'une borne qu'il n'a pas pu lire.
set -uo pipefail

# cryptsetup vit dans /sbin, absent du PATH d'un shell non interactif.
export PATH="/usr/sbin:/sbin:$PATH"

refus() { printf '❌ %s\n' "$*" >&2; exit 1; }
injugeable() { printf '❔ %s\n' "$*" >&2; exit 4; }
usage() {
  echo "usage : $0 inscrire <volume> <hex|raw> [répertoire-selfkeyguard]" >&2
  echo "        $0 verifier <volume-racine> <keyscript-à-poser> [répertoire-selfkeyguard]" >&2
  echo "        $0 taille <keyscript>" >&2
  echo "        $0 borne <nom-crypttab> <keyscript> [crypttab] [--ecrire]" >&2
  exit 2
}

uuid_de() {
  local u
  u="$(cryptsetup luksUUID "$1" 2>/dev/null)"
  [ -n "$u" ] || refus "$1 n'est pas un volume LUKS lisible."
  printf '%s' "$u"
}

# Le dernier `--format` du fichier est celui de la ligne exécutée : les
# commentaires le citent avant elle.
format_de() {
  grep -oE -- '--format[= ](hex|raw)' "$1" | tail -n1 | sed -E 's/^--format[= ]//'
}

# 32 octets : la longueur par défaut de selfrecover_derive.c, que le keyscript ne
# change pas ; l'hexadécimal en écrit deux caractères par octet.
taille_de() {
  case "$1" in
    hex) echo 64 ;;
    raw) echo 32 ;;
    *)   return 1 ;;
  esac
}

# La valeur de keyfile-size= sur la ligne <nom>, vide si elle n'en porte pas ; la
# dernière occurrence, comme la lit cryptsetup. Sortie 1 si la ligne manque, 4 si elle
# n'a pas de colonne d'options. Le nom passe par l'environnement et non par -v, qui
# décoderait un nom à échappement octal (`r\040c`) que crypttab garde tel quel.
lire_borne() {
  n="$1" awk '
    $1 == ENVIRON["n"] { trouve = 1
              if (NF < 4) { sans = 1; exit }
              m = split($4, o, ",")
              for (i = 1; i <= m; i++) if (o[i] ~ /^keyfile-size=/) v = substr(o[i], 14)
              print v; exit }
    END { if (!trouve) exit 1; if (sans) exit 4 }' "$2"
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
    LIVRE="$(format_de "$KEYSCRIPT")"
    [ -n "$LIVRE" ] || refus "le keyscript $KEYSCRIPT ne dit pas quel format il produit (aucun --format hex|raw)."
    UUID="$(uuid_de "$VOLUME")" || exit 1
    MARQUEUR="$SKG/format-slot"
    ENROLE=""
    [ -f "$MARQUEUR" ] && ENROLE="$(awk -v u="$UUID" '$1 == u { f = $2 } END { print f }' "$MARQUEUR")"

    if [ -z "$ENROLE" ]; then
      if [ -e "$SKG/selfrecover-keyscript.sh" ]; then
        EN_PLACE="$(format_de "$SKG/selfrecover-keyscript.sh")"
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

  taille)
    [ $# -ge 1 ] || usage
    [ -r "$1" ] || refus "keyscript introuvable : $1"
    FORMAT="$(format_de "$1")"
    taille_de "$FORMAT" || refus "le keyscript $1 ne dit pas quel format il produit (aucun --format hex|raw)."
    ;;

  borne)
    [ $# -ge 2 ] || usage
    NOM="$1"; KEYSCRIPT="$2"; shift 2
    CRYPTTAB=/etc/crypttab; ECRIRE=non
    for a in "$@"; do
      case "$a" in --ecrire) ECRIRE=oui ;; *) CRYPTTAB="$a" ;; esac
    done
    [ -r "$CRYPTTAB" ] || injugeable "crypttab illisible : $CRYPTTAB"
    [ -r "$KEYSCRIPT" ] || injugeable "keyscript introuvable : $KEYSCRIPT"
    FORMAT="$(format_de "$KEYSCRIPT")"
    TAILLE="$(taille_de "$FORMAT")" \
      || injugeable "le keyscript $KEYSCRIPT ne dit pas quel format il produit (aucun --format hex|raw)."
    ACTUELLE="$(lire_borne "$NOM" "$CRYPTTAB")"
    case $? in
      0) ;;
      4) injugeable "la ligne $NOM de $CRYPTTAB n'a pas de colonne d'options : keyscript= n'y est pas." ;;
      *) injugeable "aucune ligne $NOM dans $CRYPTTAB." ;;
    esac

    if [ "$ACTUELLE" = "$TAILLE" ]; then
      echo "✅ $NOM : keyfile-size=$TAILLE, la longueur de la clé $FORMAT que présente le keyscript."
      exit 0
    fi
    if [ "$ECRIRE" = non ]; then
      if [ -z "$ACTUELLE" ]; then
        echo "ℹ️  $NOM : aucune borne keyfile-size ; cryptsetup lit tout le flux, la clé $FORMAT ($TAILLE) ouvre."
        exit 3
      fi
      refus "$NOM : keyfile-size=$ACTUELLE, mais le keyscript présente une clé $FORMAT de $TAILLE octets.
   cryptsetup lit exactement la borne : la clé serait tronquée ou incomplète, et le
   volume ne s'ouvrirait pas au démarrage. Correction : $0 borne $NOM $KEYSCRIPT $CRYPTTAB --ecrire"
    fi

    # Une copie datée d'abord, sous un nom à elle et propre à cet appel : install.sh
    # prend la sienne dans la même seconde, et deux appels rapprochés aussi ; un nom
    # partagé écraserait la crypttab d'avant les changements.
    SAUVEGARDE="$CRYPTTAB.avant-borne.$(date +%s).$$"
    cp -p "$CRYPTTAB" "$SAUVEGARDE" || refus "copie de $CRYPTTAB impossible."
    NOUVEAU="$CRYPTTAB.borne.nouveau"
    # Toutes les occurrences : cryptsetup lit la dernière, une seule corrigée laisserait
    # l'autre décider.
    n="$NOM" awk -v t="$TAILLE" '
      $1 == ENVIRON["n"] && !fait {
        if ($4 ~ /(^|,)keyfile-size=/) gsub(/keyfile-size=[^, \t]*/, "keyfile-size=" t)
        else sub(/[ \t]*$/, ",keyfile-size=" t)
        fait = 1 }
      { print }' "$CRYPTTAB" > "$NOUVEAU" || { rm -f "$NOUVEAU"; refus "écriture impossible à côté de $CRYPTTAB."; }
    [ "$(awk 'END { print NR }' "$NOUVEAU")" = "$(awk 'END { print NR }' "$CRYPTTAB")" ] \
      || { rm -f "$NOUVEAU"; refus "écriture incomplète de $NOUVEAU : $CRYPTTAB n'a pas été touchée."; }
    chmod --reference="$CRYPTTAB" "$NOUVEAU" 2>/dev/null || chmod 0644 "$NOUVEAU"
    mv "$NOUVEAU" "$CRYPTTAB" || refus "renommage impossible de $NOUVEAU."
    [ "$(lire_borne "$NOM" "$CRYPTTAB")" = "$TAILLE" ] \
      || refus "$NOM : la borne relue après écriture n'est pas $TAILLE — vérifie $CRYPTTAB ; la crypttab d'avant est $SAUVEGARDE."
    echo "✅ $NOM : keyfile-size ${ACTUELLE:-absente} → $TAILLE (clé $FORMAT). Prend effet à la prochaine régénération de l'initramfs."
    ;;

  *) usage ;;
esac
