#!/usr/bin/env python3
"""Fabrique un coffre sain et une collection de coffres défectueux, pour le banc.

Chaque défaut correspond à un contrôle que le déchiffreur prétend exercer.

Usage : python3 defauts.py <répertoire>
Écrit les coffres et un `manifeste.json` qui porte les secrets.
"""
import hashlib, json, os, sys

SD = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(SD), "outils"))
from selfvault import (ALPHABET, BITS_MIN, b64, code_recuperation, controle,  # noqa: E402
                       entete_canonique, fabriquer, signer, tirer_phrase)
from cryptography.hazmat.primitives.asymmetric import ec  # noqa: E402
from cryptography.hazmat.primitives.ciphers.aead import AESGCM  # noqa: E402
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat  # noqa: E402
import base64  # noqa: E402

CLAIR = "DIRECTIVES — coffre de banc\nDeuxième ligne, pour mesurer la longueur.\n"
MOT_L2 = tirer_phrase()   # tirée, comme l'exige le plancher d'entropie

dest = sys.argv[1]
os.makedirs(dest, exist_ok=True)
code = code_recuperation()
serrures = [("L1 — dépositaire", code), ("L2 — titulaire", MOT_L2)]


def poser(nom, coffre):
    with open(os.path.join(dest, nom + ".selfvault"), "w") as f:
        json.dump(coffre, f, ensure_ascii=False, indent=1)


def copie(coffre):
    return json.loads(json.dumps(coffre))


VERSION, DATE = 1, "2026-09-04"
# La paire du sceau est conservée : elle sert à RE-SCELLER les coffres qu'on
# falsifie ensuite. Sans ça, le sceau les intercepterait tous et l'AAD — qui les
# refuse pour une autre raison, et qui reste la défense si ECDSA cède un jour —
# ne serait plus éprouvé par aucun contrôle.
SCEAU = ec.generate_private_key(ec.SECP256R1())
fab = lambda **kw: fabriquer(CLAIR, serrures, version=VERSION, date=DATE,
                             cle_signature=SCEAU, **kw)


def resceller(coffre):
    """Rend le coffre falsifié cohérent avec son propre sceau."""
    coffre["signature"] = signer(SCEAU, coffre)
    return coffre

sain = fab()
poser("sain", sain)

# § 2.1 — l'en-tête est authentifié par l'AAD : toute retouche doit faire échouer
# l'ouverture, et non passer inaperçue.
# Les trois sont RE-SCELLÉS : ce qu'on éprouve ici est l'AAD, pas le sceau. Un
# coffre au sceau cassé serait refusé une étape plus tôt et ne dirait rien de la
# liaison entre l'en-tête et les chiffrés.
c = copie(sain); c["version"] = 99;            poser("version_falsifiee", resceller(c))
c = copie(sain); c["date"] = "2030-01-01";     poser("date_falsifiee", resceller(c))
c = copie(sain); c["serrures"][1]["nom"] += " (falsifié)"
poser("serrure_renommee", resceller(c))

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

# Les deux bornes posées le 06/09. Elles ne se fabriquent pas : `fabriquer()` les
# applique aussi. On part donc d'un coffre sain qu'on retouche — ce qui est
# exactement le geste contre lequel elles existent.
c = copie(sain)
c["serrures"] = [copie(c["serrures"][1]) for _ in range(9)]
poser("trop_de_serrures", c)
# 2⁵³+1 : Python l'écrit exactement, JavaScript l'arrondit à 2⁵³. Sans borne, le
# fichier portait un numéro et le lecteur en affichait un autre, authentifié.
c = copie(sain); c["version"] = 2 ** 53 + 1; poser("version_enorme", c)

# Les champs qui entrent dans le MESSAGE SIGNÉ sans entrer dans l'AAD. Leur forme
# base64 est ce qui rend cet encodage sans ambiguïté : un « | » ou un saut de ligne
# dans l'un d'eux fabrique deux coffres différents rendant la même chaîne signée.
# Ils sont refusés sur la FORME, avant même le sceau.
c = copie(sain); c["contenu"] = "AAAA\nBBBB";              poser("contenu_avec_saut", c)
c = copie(sain); c["nonce"] = "AAAA|BBBB";                 poser("nonce_avec_barre", c)
c = copie(sain); c["serrures"][0]["enveloppe"] = "AA|BB";  poser("enveloppe_avec_barre", c)
c = copie(sain); c["serrures"][0]["nonce"] = "AA\nBB";     poser("serr_nonce_avec_saut", c)

# JSON n'a pas d'entiers : `1.0` et `1` sont un seul nombre pour JavaScript, qui ne
# peut pas les séparer. Ce coffre doit donc s'OUVRIR — l'AAD et le message signé
# sont identiques au bit près, et exiger `int` côté Python creusait un écart que
# l'autre côté ne pouvait pas fermer.
c = copie(sain); c["version"] = 1.0; poser("version_un_flottant", c)

# Une clé que les deux chaînes canoniques ne nomment pas n'est signée par rien :
# le sceau tient, et le champ est pourtant libre. On la refuse à la porte.
c = copie(sain); c["avertissement"] = "Ce testament est révoqué."
poser("champ_inconnu", c)
c = copie(sain); c["serrures"][0]["remarque"] = "voir l'étude"
poser("champ_inconnu_serrure", c)

# § 2.7 — LE SCEAU. Ce qui rend le coffre immuable, et ce qui s'en prend à lui.
poser("sceau_absent", fab(sans_signature=True))
poser("sceau_etranger", fab(signature_de=ec.generate_private_key(ec.SECP256R1())))

# 🔑 L'attaque qui a motivé le sceau, jouée en entier. Le dépositaire ouvre SA
# serrure, récupère la clé maîtresse — toute serrure la rend — et rechiffre ce
# qu'il veut sous cette même clé avec le MÊME AAD. L'en-tête ne bouge pas d'un
# octet, l'engagement reste valide, l'enveloppe de l'autre serrure est intacte,
# et l'application affichait « en-tête authentifié » au-dessus d'un texte que la
# titulaire n'a jamais écrit. Sans ce coffre-là, le sceau serait une affirmation.
MAITRESSE = os.urandom(32)
c = fabriquer(CLAIR, serrures, version=VERSION, date=DATE, maitresse=MAITRESSE)
c["contenu"] = b64(AESGCM(MAITRESSE).encrypt(
    base64.b64decode(c["nonce"]), "TESTAMENT SUBSTITUÉ PAR LE DÉPOSITAIRE".encode(),
    entete_canonique(c)))
poser("contenu_reecrit", c)

# Et la suite logique : l'attaquant re-scelle avec SA paire de clés pour que la
# signature concorde. Elle concorde — mais `cle_publique` est dans l'AAD, donc
# plus aucune enveloppe ne se déchiffre. Le sceau et l'AAD se tiennent l'un
# l'autre ; ni l'un ni l'autre ne suffit seul.
sienne = ec.generate_private_key(ec.SECP256R1())
c = copie(sain)
c["cle_publique"] = b64(sienne.public_key().public_bytes(
    Encoding.X962, PublicFormat.UncompressedPoint))
c["signature"] = signer(sienne, c)
poser("rescelle_par_un_tiers", c)

# 🔑 LA SUBSTITUTION. Un tiers qui a lu le code L1 — il est imprimé sur le pli —
# ne modifie rien : il fabrique un coffre entièrement neuf, avec sa propre paire de
# clés, son propre contenu, la même version et la même date. Ce coffre est
# parfaitement cohérent et son sceau tient. Rien dans le fichier ne le distingue de
# l'authentique : seule l'empreinte imprimée sur le pli le fait.
# Il réemploie le code du pli : c'est tout l'intérêt de l'attaque, l'héritier tape
# le code imprimé et le coffre s'ouvre.
poser("refabrique", fabriquer("TESTAMENT SUBSTITUÉ PAR UN TIERS QUI A LU LE PLI",
                              [("L1 — dépositaire", code)], version=VERSION, date=DATE))

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
