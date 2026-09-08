#!/usr/bin/env python3
"""Juge ce que le navigateur a produit, avec le format pour seule autorité.

Le navigateur ne peut rendre que du texte : `tests/pilote_navigateur.js` écrit
des lignes `clé=valeur` sur la sortie standard du processus, et le coffre y part
en morceaux — une ligne de plusieurs kilo-octets ne traverse pas toujours cette
sortie sans être coupée, et une coupure au milieu d'un Base64 ressemble à un
coffre corrompu.

Ce script recolle, écrit le coffre, puis le soumet à `outils/selfvault.py` :
mêmes bornes, même AAD, même sceau que pour un coffre né de la fabrique Python.
Un dialecte se verrait ici.

  python3 tests/juge_navigateur.py <module> <sortie.txt> <coffre.selfvault>

Sortie : des lignes `clé=valeur` que le banc relit. Code 0 si tout tient.
"""
import base64
import json
import os
import sys

MODULE, SORTIE, CIBLE = sys.argv[1], sys.argv[2], sys.argv[3]
sys.path.insert(0, os.path.join(MODULE, "outils"))
from selfvault import (  # noqa: E402
    FORMAT, empreinte_sceau, signature_tenue, verifier_champs, verifier_chiffres)

# Première valeur gagne : le pilote n'écrit chaque clé qu'une fois, et une
# seconde occurrence viendrait d'ailleurs que de lui.
dit = {}
for ligne in open(SORTIE, encoding="utf-8"):
    cle, sep, valeur = ligne.rstrip("\n").partition("=")
    if sep:
        dit.setdefault(cle, valeur)

morceaux = int(dit.get("morceaux", 0))
encode = "".join(dit.get("c%d" % i, "") for i in range(morceaux))
try:
    brut = base64.b64decode(encode.encode("ascii"), validate=True).decode("utf-8")
except Exception:
    brut = ""
open(CIBLE, "w", encoding="utf-8").write(brut)

verdicts = []


def juge(cle, condition, detail=""):
    verdicts.append("%s=%s" % (cle, "oui" if condition else "non:" + detail))
    return condition


if not juge("recolle", morceaux > 0 and len(brut) > 0,
            "aucun morceau" if not morceaux else "les %d morceaux ne se décodent pas" % morceaux):
    print("\n".join(verdicts))
    raise SystemExit(1)

try:
    coffre = json.loads(brut)
except ValueError as e:
    juge("json", False, str(e))
    print("\n".join(verdicts))
    raise SystemExit(1)
juge("json", True)

try:
    verifier_champs(coffre)
    verifier_chiffres(coffre)
    juge("forme", True)
except Exception as e:  # le format refuse, et il dit pourquoi
    juge("forme", False, str(e))

juge("format_annonce", coffre.get("format") == FORMAT, str(coffre.get("format")))
juge("serrures", len(coffre.get("serrures", [])) == 2, str(len(coffre.get("serrures", []))))
juge("sceau", signature_tenue(coffre), "signature refusée")

# 🔑 L'empreinte affichée par la page est le seul ancrage imprimé sur le pli. Si
# elle diverge de celle que le format calcule, le lecteur compare deux choses
# qui ne se répondent pas — et ne s'en apercevra que vingt ans plus tard.
calculee = empreinte_sceau(coffre)
affichee = dit.get("empreinte", "")
juge("empreinte", calculee.replace(" ", "") == affichee.replace(" ", "").lower(),
     "affichée %r ≠ calculée %r" % (affichee, calculee))

# L'identité ne va pas dans le coffre : elle vit à côté, dans la forme que
# `outils/faire_pli.py` attend pour la première page du pli.
try:
    meta = json.loads(dit.get("meta", ""))
    absents = sorted({"titulaire", "naissance", "reference"} - set(meta))
    juge("meta", not absents and bool(meta.get("titulaire", "").strip()),
         "champs absents : %s" % ", ".join(absents) if absents else "titulaire vide")
except ValueError as e:
    juge("meta", False, str(e))

print("\n".join(verdicts))
raise SystemExit(0 if all(v.endswith("=oui") for v in verdicts) else 1)
