#!/usr/bin/env python3
"""JADE — du libellé de juridiction au couple (code, ville).

🔑 **Le fonds écrit la même juridiction de dizaines de façons.** Inventaire du
dump complet, 10/09/2026 : 113 libellés distincts pour cinq juridictions
réelles. « Conseil d'Etat » sans accent (113 069 décisions) et « Conseil d'État »
avec (57 433) ; la cour de Marseille sous trois graphies — « CAA de MARSEILLE »,
« Cour Administrative d'Appel de Marseille », « Cour administrative d'appel de
Marseille » — et celle de Lyon en capitales.

Une table écrite à la main aurait reflété l'orthographe d'aujourd'hui. Les
quatre derniers diffs quotidiens ne montraient que dix libellés, tous récents,
tous accentués ; le fonds remonte à 1873. C'est l'inventaire du fonds entier qui
a produit cette fonction, et c'est lui que `tests/sanity_jade_juridictions.sh`
rejoue contre elle.

**Ce qui n'est pas reconnu est refusé, jamais rangé sous un code par défaut.**
Un libellé inconnu classé « ce » par commodité produirait des décisions
attribuées à la mauvaise juridiction — une erreur qu'aucune mesure de volume ne
révèle, puisque les comptes tomberaient juste.

    >>> normaliser("CAA de MARSEILLE")
    ('caa', 'MARSEILLE')
    >>> normaliser("Conseil d'Etat")
    ('ce', '')
    >>> normaliser("Chambre régionale des comptes")
    None
"""

import re
import unicodedata

# Les codes servis. `juridiction_libelle()` d'api.php doit en porter un libellé
# pour chacun : `sanity_juridictions_servies.sh` le vérifie, et un code sans
# libellé s'afficherait brut dans un message d'erreur.
CODES = ("ce", "caa", "ta", "tc", "cdbf")

# Chaque préfixe est une SUITE DE MOTS repliés — minuscules, accents retirés,
# apostrophes rendues par une espace. C'est ce repli qui absorbe l'essentiel des
# 113 variantes.
#
# La reconnaissance se fait mot à mot, jamais par position dans la chaîne : le
# repli n'est pas conservateur en longueur (une espace double devient simple),
# et reporter un décalage du libellé replié sur l'original range « de LYON »
# dans la ville. Le défaut a été mesuré sur « ␣␣CAA␣␣de␣␣␣LYON » — aucun des 113
# libellés du fonds ne l'aurait déclenché, un futur diff le pourrait.
#
# Les préfixes les plus longs se cherchent d'abord : « cour administrative d
# appel » avant « cour de discipline… » n'a pas d'importance ici, mais un
# préfixe court qui préfixerait un long masquerait le second.
PREFIXES = (
    ("ce",   ("conseil", "d", "etat")),
    ("caa",  ("cour", "administrative", "d", "appel")),
    ("caa",  ("caa",)),
    ("ta",   ("tribunal", "administratif")),
    ("tc",   ("tribunal", "des", "conflits")),
    ("cdbf", ("cour", "de", "discipline", "budgetaire", "et", "financiere")),
)

# Les mots de liaison qui suivent un préfixe et n'appartiennent pas à la ville.
LIAISONS = ("de", "d", "du", "des")

# Deux libellés que le repli ne rattrape pas, et qu'il faut nommer.
#
# « Section du Contentieux » est une FORMATION du Conseil d'État, pas une
# juridiction : deux décisions du fonds la portent à la place du nom de la
# juridiction. La ranger ailleurs qu'en `ce` inventerait un ordre de juridiction.
NOMMES = {
    "section du contentieux": ("ce", ""),
}


def replier(libelle: str) -> str:
    """Minuscules, accents retirés, apostrophes et espaces uniformisés."""
    s = unicodedata.normalize("NFD", libelle)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    s = s.lower().replace("'", " ").replace("’", " ")
    return re.sub(r"\s+", " ", s).strip()


def normaliser(libelle: str):
    """Rend (code, ville) — ou None si le libellé n'est pas reconnu.

    La ville sort avec sa casse d'origine : le fonds écrit tantôt « MARSEILLE »,
    tantôt « Marseille », et cette information appartient à `location`, pas à
    la juridiction.

    ⚠️ Les mots qui suivent le préfixe sont rendus ENTIERS, sans tentative de
    nettoyage. « Saint-Denis de la Réunion » (84 décisions) est une ville en
    quatre mots : couper au premier la mutilerait. Le prix de cette fidélité est
    une décision unique, « Tribunal administratif Montpellier ordonnance du
    president », dont la ville sortira telle quelle. Une décision sur 552 576 mal
    rangée dans un champ informatif vaut mieux qu'une règle astucieuse qui casse
    une ville réelle.
    """
    if not libelle or not libelle.strip():
        return None
    mots = libelle.split()
    plies = [replier(m) for m in mots]
    # Le repli d'un mot peut le scinder — « d'appel » devient « d appel ». On
    # travaille donc sur la suite des mots repliés APRÈS re-découpage, en
    # gardant pour chaque mot replié l'indice du mot d'origine dont il vient.
    plat, origine = [], []
    for i, p in enumerate(plies):
        for morceau in p.split():
            plat.append(morceau)
            origine.append(i)

    if " ".join(plat) in NOMMES:
        return NOMMES[" ".join(plat)]

    for code, prefixe in PREFIXES:
        n = len(prefixe)
        if tuple(plat[:n]) != prefixe:
            continue
        reste = plat[n:]
        # Un mot de liaison juste après le préfixe n'est pas la ville.
        saut = 1 if reste and reste[0] in LIAISONS else 0
        if not reste[saut:]:
            return code, ""
        # Le premier mot d'origine qui porte la ville.
        debut = origine[n + saut]
        return code, " ".join(mots[debut:]).strip().lstrip(",").strip()
    return None
