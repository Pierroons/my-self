#!/usr/bin/env python3
"""Mesure ce que deux implémentations d'un même format ont en commun, au caractère près.

`outils/test_webcrypto.mjs` se déclare réimplémentation indépendante du noyau de
`pli/selfvault.html`. Cette propriété est ce qui lui permet de révéler un écart
d'encodage : une copie hérite des défauts qu'elle devrait montrer et ne prouve que
sa propre cohérence.

Une propriété déclarée dans un en-tête ne se surveille pas toute seule. Celle-ci se
mesure ici, et le banc refuse au-delà d'un seuil.

    python3 recouvrement.py <a> <b> <nombre de lignes toléré>

Le seuil est un NOMBRE de lignes, pas une part. Une fraction se dilue : le jour où
le second lecteur double de taille sans rapport avec le premier, le même
pourcentage autorise deux fois plus de logique recopiée.

Sortie : 0 si le recouvrement est sous le seuil, 1 sinon.
"""
import re
import sys


def lignes(chemin):
    """Les lignes de CODE, normalisées. Commentaires et lignes courtes écartés.

    Les lignes courtes — une accolade, une parenthèse fermante — coïncident entre
    deux fichiers quelconques et gonfleraient la mesure sans rien dire. Les
    commentaires sont écartés parce qu'un texte partagé est une intention, pas
    une duplication de logique.
    """
    texte = open(chemin, encoding="utf-8").read()
    # Le noyau se lit entre ses marqueurs ; un fichier ordinaire se lit en entier.
    d = texte.find("/* === NOYAU")
    if d >= 0:
        texte = texte[d:texte.find("— fin", d)]
    out = []
    for ligne in texte.split("\n"):
        nue = ligne.strip()
        if not nue or nue.startswith(("//", "*", "/*", "#")):
            continue
        compacte = re.sub(r"\s+", "", nue)
        if len(compacte) >= 20:
            out.append(compacte)
    return out


def main(a, b, seuil):
    ga, gb = lignes(a), lignes(b)
    if not gb:
        raise SystemExit("%s ne porte aucune ligne de code mesurable" % b)
    communes = [l for l in gb if l in set(ga)]
    print("%s : %d lignes · %s : %d lignes" % (a, len(ga), b, len(gb)))
    print("communes : %d (toléré : %d)" % (len(communes), seuil))
    if len(communes) > seuil:
        for l in communes[:10]:
            print("   " + l[:100])
        raise SystemExit("recouvrement trop élevé : ce n'est pas une réimplémentation, "
                         "c'est une copie — elle hérite des défauts qu'elle devrait révéler")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], int(sys.argv[3]))
