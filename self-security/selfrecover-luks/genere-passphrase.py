#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Genere une passphrase diceware depuis la liste EFF francaise du depot MySelf.

A LANCER DANS UN VRAI TERMINAL. La passphrase s'affiche a l'ecran : elle ne doit
jamais transiter par un journal, un historique, ni une conversation.

  python3 genere-passphrase.py          # 7 mots : 7 x log2(7776) ~ 90 bits
  python3 genere-passphrase.py 8        # si tu veux plus
"""
import json, math, secrets, sys, os

HERE = os.path.dirname(os.path.abspath(__file__))

# La wordlist vit chez SelfRecover : une seule copie normative dans le depot, et
# ce generateur la lit plutot que d'en embarquer une seconde qui divergerait.
# SELFRECOVER_WORDLIST permet de pointer ailleurs (copie hors ligne, autre langue).
CANDIDATS = [
    os.environ.get("SELFRECOVER_WORDLIST"),
    os.path.join(HERE, "..", "..", "bi-self", "selfrecover", "assets", "eff_fr.json"),
    os.path.join(HERE, "eff_fr.json"),
]
CHEMIN = next((c for c in CANDIDATS if c and os.path.exists(c)), None)
if CHEMIN is None:
    sys.exit("wordlist introuvable. Definis SELFRECOVER_WORDLIST vers un eff_*.json.")
WORDS = json.load(open(CHEMIN, encoding="utf-8"))
if not isinstance(WORDS, list) or len(WORDS) < 1000:
    sys.exit(f"{CHEMIN} ne ressemble pas a une wordlist diceware ({len(WORDS)} entrees).")

try:
    n = int(sys.argv[1]) if len(sys.argv) > 1 else 7
except ValueError:
    sys.exit(f"nombre de mots invalide : {sys.argv[1]!r}")
# Plancher a 6 : en dessous, l'entropie tombe sous ~77 bits et la passphrase ne
# protege plus rien qu'un KDF puisse rattraper.
if n < 6:
    sys.exit(f"{n} mots, c'est trop peu — 6 au minimum, 7 par defaut.")

# secrets.choice : tirage uniforme sans biais de modulo, source cryptographique.
mots = [secrets.choice(WORDS) for _ in range(n)]
bits = n * math.log2(len(WORDS))

avec = " ".join(mots)
sans = "".join(mots)

print()
print("  AVEC espaces  : " + avec)
print("  SANS espaces  : " + sans)
print()
print(f"  {n} mots · {len(WORDS)} du dictionnaire · {bits:.1f} bits d'entropie")
print()
print("  ---------------------------------------------------------------")
print("  CHOISIS UNE CONVENTION MAINTENANT, et note-la sur le papier.")
print("  La chaine enrolee doit etre IDENTIQUE octet pour octet a celle")
print("  que tu retaperas — sur ce disque, et pour tout label futur")
print("  (auth, data-enc) sur toute autre machine.")
print()
print(f"    longueur AVEC espaces : {len(avec)} caracteres")
print(f"    longueur SANS espaces : {len(sans)} caracteres")
print()
print("  Note ce nombre a cote de la passphrase : au moment de l'enrolement,")
print("  il permet de verifier qu'on a bien saisi ce qu'on a ecrit.")
print("  ---------------------------------------------------------------")
print()
print("  Recopie-la MAINTENANT sur papier. Ferme ce terminal ensuite.")
print("  Elle ne sera pas reaffichee et n'est ecrite nulle part.")
print()
