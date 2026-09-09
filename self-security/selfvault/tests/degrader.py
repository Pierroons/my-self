#!/usr/bin/env python3
"""Dégrade une planche comme le ferait un copieur, avant de la relire.

Le banc papier rasterise un PDF parfait : chaque module d'un QR code y tombe sur
un nombre régulier de pixels, sans flou, sans grain, sans travers. Aucun scanner
ne rend cela. Tant que la boucle papier ne mesurait que cette image-là, elle
établissait que le pli se **relit** — jamais qu'il se **numérise**.

  python3 tests/degrader.py --flou 0.8 --rotation 0.5 --bruit 0.01 SOURCE CIBLE

Les trois dégradations sont celles d'un scanner à plat : l'optique et l'étalement
de l'encre floutent, la feuille prend du travers dans le chargeur, le capteur
grène. Mesuré sur ce pli : le flou est le seul qui tue, et il tue d'un coup —
toute la planche disparaît entre 1,0 et 1,5 pixel.

Le bruit est tiré d'une graine fixe. Le banc joue déjà à la loterie sur le
contenu du coffre, tiré à neuf à chaque exécution ; une seconde source d'aléa
rendrait un rouge irreproductible.
"""
import argparse, os, random, sys

try:
    from PIL import Image, ImageFilter
except ImportError:
    sys.exit("Pillow est absent — la planche ne peut pas être dégradée.")

EXT = (".png", ".jpg", ".jpeg", ".tif", ".tiff", ".pnm")


def degrader(im, flou, rotation, bruit, graine):
    # La rotation passe en premier : après le flou, son interpolation en
    # rajouterait, et le flou mesuré ne serait plus celui qu'on demande.
    if rotation:
        im = im.rotate(rotation, resample=Image.BICUBIC, fillcolor=255)
    if flou:
        im = im.filter(ImageFilter.GaussianBlur(flou))
    if bruit:
        px = im.load()
        largeur, hauteur = im.size
        tirage = random.Random(graine)
        for _ in range(int(largeur * hauteur * bruit)):
            px[tirage.randrange(largeur), tirage.randrange(hauteur)] = tirage.randrange(256)
    return im


def main():
    a = argparse.ArgumentParser(description="Dégrade des pages comme un scanner.")
    a.add_argument("source", help="répertoire des pages rastérisées")
    a.add_argument("cible", help="où écrire les pages dégradées")
    a.add_argument("--flou", type=float, default=0.8, help="rayon du flou gaussien, en pixels")
    a.add_argument("--rotation", type=float, default=0.5, help="travers de la feuille, en degrés")
    a.add_argument("--bruit", type=float, default=0.01, help="part des pixels remplacés au hasard")
    a.add_argument("--graine", type=int, default=1, help="graine du bruit")
    opt = a.parse_args()

    pages = sorted(f for f in os.listdir(opt.source) if f.lower().endswith(EXT))
    if not pages:
        sys.exit("Aucune page à dégrader dans « %s »." % opt.source)
    os.makedirs(opt.cible, exist_ok=True)
    for nom in pages:
        im = Image.open(os.path.join(opt.source, nom)).convert("L")
        degrader(im, opt.flou, opt.rotation, opt.bruit, opt.graine) \
            .save(os.path.join(opt.cible, nom))
    print("%d page(s) dégradées : flou %.1f px, travers %.1f°, bruit %.1f %%"
          % (len(pages), opt.flou, opt.rotation, opt.bruit * 100))


if __name__ == "__main__":
    main()
