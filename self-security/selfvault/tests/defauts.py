#!/usr/bin/env python3
"""Fabrique un coffre sain et une collection de coffres défectueux, pour le banc.

Chaque défaut correspond à un contrôle que le déchiffreur prétend exercer.

Usage : python3 defauts.py <répertoire>
Écrit les coffres et un `manifeste.json` qui porte les secrets.
"""
import hashlib, json, os, sys

SD = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(SD), "outils"))
from selfvault import (ALPHABET, BITS_MIN, code_recuperation, controle,  # noqa: E402
                       fabriquer, tirer_phrase)

CLAIR = "DIRECTIVES — coffre de banc\nDeuxième ligne, pour mesurer la longueur.\n"
MOT_L2 = tirer_phrase()   # tirée, comme l'exige le plancher d'entropie

dest = sys.argv[1]
os.makedirs(dest, exist_ok=True)
code = code_recuperation()
serrures = [("L2 — titulaire", MOT_L2), ("L1 — dépositaire", code)]


def poser(nom, coffre):
    with open(os.path.join(dest, nom + ".selfvault"), "w") as f:
        json.dump(coffre, f, ensure_ascii=False, indent=1)


def copie(coffre):
    return json.loads(json.dumps(coffre))


VERSION, DATE = 1, "2026-09-04"
fab = lambda **kw: fabriquer(CLAIR, serrures, version=VERSION, date=DATE, **kw)

sain = fab()
poser("sain", sain)

# § 2.1 — l'en-tête est authentifié par l'AAD : toute retouche doit faire échouer
# l'ouverture, et non passer inaperçue.
c = copie(sain); c["version"] = 99;            poser("version_falsifiee", c)
c = copie(sain); c["date"] = "2030-01-01";     poser("date_falsifiee", c)
c = copie(sain); c["serrures"][1]["nom"] = "L1 — dépositaire (falsifié)"
poser("serrure_renommee", c)

# Le séparateur de l'AAD, glissé dans un nom de serrure : deux en-têtes
# différents produiraient le même AAD.
c = copie(sain); c["serrures"][0]["nom"] = "L2|faux"; poser("nom_avec_barre", c)

# § 2.3 — le nombre d'itérations est lu dans le fichier.
poser("iterations_basses", fab(iterations=1, bornes=False))
c = copie(sain); c["serrures"][0]["iterations"] = 10 ** 9
c["serrures"][1]["iterations"] = 10 ** 9
poser("iterations_enormes", c)

# § 2.2 — AES-GCM n'engage pas sa clé. Un en-tête simplement retouché échoue
# d'abord sur l'AAD : pour atteindre la vérification d'engagement il faut un
# coffre cohérent dont l'engagement porte sur une AUTRE clé.
poser("engagement_faux", fab(engagement_de=os.urandom(32)))

# § 2.1 bis — l'AAD du CONTENU. Aucun coffre ne l'éprouvait : on pouvait délier le
# contenu de l'en-tête des deux côtés sans qu'un seul contrôle rougisse.
poser("contenu_delie", fab(contenu_sans_aad=True))

# Les cinq contrôles de forme de l'en-tête, qu'aucun coffre ne visait.
for nom, retouche in (("format_inconnu",   lambda c: c.update(format="SELFVAULT9")),
                      ("version_flottante", lambda c: c.update(version=1.5)),
                      ("date_absente",      lambda c: c.pop("date")),
                      ("engagement_absent", lambda c: c.pop("engagement")),
                      ("sans_serrure",      lambda c: c.update(serrures=[]))):
    c = copie(sain); retouche(c); poser(nom, c)

# Le séparateur glissé dans les DEUX autres champs libres de l'AAD. Avec le seul
# `nom` contraint, on fabriquait deux coffres de dates différentes ayant le même
# AAD, tous deux affichant « en-tête authentifié ».
c = copie(sain); c["date"] = "2026-09-04\nengagement=X"; poser("date_avec_saut", c)
c = copie(sain); c["serrures"][0]["sel"] = "AAAA|BBBB"; poser("sel_avec_barre", c)

# § 2.6 — la somme de contrôle du code. Le corps est intact, les cinq derniers
# caractères sont faux : c'est une faute de recopie, pas un mauvais code.
plein = code.replace("-", "")
autre = next(a for a in ALPHABET if a != plein[20])
code_recopie_faux = plein[:20] + autre + plein[21:]

# Le contre-témoin historique : « deux caractères permutés » ouvrait le coffre,
# parce que les deux premiers tirés étaient identiques. La sonde était fautive,
# pas le code. On permute donc deux caractères RÉELLEMENT différents.
i = next(k for k in range(len(plein) - 1) if plein[k] != plein[k + 1])
permute = list(plein); permute[i], permute[i + 1] = permute[i + 1], permute[i]
code_permute = "".join(permute)

json.dump({
    "code_L1": code,
    "mot_L2": MOT_L2,
    "code_L1_sans_tirets": plein,
    "code_controle_faux": code_recopie_faux,
    "code_permute": code_permute,
    "controle_attendu": controle(plein[:20]),
    "empreinte_clair": hashlib.sha256(CLAIR.encode()).hexdigest(),
    "bits_min": BITS_MIN,
}, open(os.path.join(dest, "manifeste.json"), "w"), ensure_ascii=False, indent=1)
print("coffres posés dans " + dest)
