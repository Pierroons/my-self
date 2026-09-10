#!/usr/bin/env python3
"""Dégrade une planche comme le ferait un copieur, avant de la relire.

Le banc papier rasterise un PDF parfait : chaque module d'un QR code y tombe sur
un nombre régulier de pixels, sans flou et sans travers. Aucun scanner ne rend
cela — une rasterisation établit que le pli se **relit**, pas qu'il se
**numérise**.

  python3 tests/degrader.py --flou 0.8 --rotation 0.5 SOURCE CIBLE

Deux dégradations, et deux seulement, parce que ce sont les deux qu'un scanner à
plat fait à coup sûr : l'optique et l'étalement de l'encre floutent, la feuille
prend du travers dans le chargeur. Pas de bruit aléatoire : ce n'est pas ce que
fait un scanner, et le banc tire déjà le contenu du coffre à neuf à chaque
exécution — une seconde source d'aléa rendrait un rouge irreproductible.

Le flou est celui qui tue, et il tue d'un coup. Re-mesuré le 10/09/2026 sur six
tirages du pli **tel qu'il s'imprime**, à 7,83 cm, tous rendant le même seuil :
les 24 codes se relisent jusqu'à 1,5 pixel de rayon, il en manque un ou deux à
2,0, il n'en reste que deux à 2,5 et plus aucun à 3,0. Le pli à 4,2 cm mourait
entre 1,0 et 1,5 — l'agrandissement a déplacé le seuil, il ne l'a pas supprimé.

**Ce que ce modèle n'établit pas.** Il ne dit rien du grain du capteur, du seuil
de binarisation d'un copieur, d'une tache d'encre, d'un pli de papier ni d'une
photographie au téléphone. Un vert ici veut dire « le pli survit à un flou et à
un travers de cette amplitude », pas « le pli survit à un scanner ».
"""
import argparse, os, sys

try:
    from PIL import Image, ImageFilter
except ImportError:
    sys.exit("Pillow est absent — la planche ne peut pas être dégradée.")

EXT = (".png", ".jpg", ".jpeg", ".tif", ".tiff", ".pnm")


def degrader(im, flou, rotation):
    # La rotation passe en premier : après le flou, son interpolation en
    # rajouterait, et le flou mesuré ne serait plus celui qu'on demande.
    if rotation:
        im = im.rotate(rotation, resample=Image.BICUBIC, fillcolor=255)
    if flou:
        im = im.filter(ImageFilter.GaussianBlur(flou))
    return im


def main():
    a = argparse.ArgumentParser(description="Dégrade des pages comme un scanner.")
    a.add_argument("source", help="répertoire des pages rastérisées")
    a.add_argument("cible", help="où écrire les pages dégradées")
    a.add_argument("--flou", type=float, default=0.8, help="rayon du flou gaussien, en pixels")
    a.add_argument("--rotation", type=float, default=0.5, help="travers de la feuille, en degrés")
    opt = a.parse_args()

    pages = sorted(f for f in os.listdir(opt.source) if f.lower().endswith(EXT))
    if not pages:
        sys.exit("Aucune page à dégrader dans « %s »." % opt.source)
    os.makedirs(opt.cible, exist_ok=True)
    for nom in pages:
        im = Image.open(os.path.join(opt.source, nom)).convert("L")
        degrader(im, opt.flou, opt.rotation).save(os.path.join(opt.cible, nom))
    print("%d page(s) dégradées : flou %.1f px, travers %.1f°"
          % (len(pages), opt.flou, opt.rotation))


if __name__ == "__main__":
    main()
