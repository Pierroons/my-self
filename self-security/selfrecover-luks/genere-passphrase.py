#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Genere une passphrase diceware depuis la liste EFF francaise du depot MySelf.

A LANCER DANS UN VRAI TERMINAL. La passphrase s'affiche a l'ecran : elle ne doit
jamais transiter par un journal, un historique, ni une conversation.

  python3 genere-passphrase.py          # 7 mots sur la liste EFF longue, ~90 bits
  python3 genere-passphrase.py 8        # si tu veux plus

Le nombre de mots suffisant depend de la liste : une liste diceware compte 6^4 =
1296 mots ou 6^5 = 7776. Sur la longue il en faut 6 pour atteindre le plancher,
sur la courte il en faut 8. Le plancher est donc exprime en bits, jamais en mots.
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
# Le plancher d'entropie. Un secret memorise qui sert a chiffrer se casse hors
# ligne, sans compteur d'essais : seul le cout par essai protege, et il ne
# rattrape pas un secret trop court.
BITS_MIN = 77.0

WORDS = json.load(open(CHEMIN, encoding="utf-8"))
# Aucun seuil de taille : c'est le plancher en bits qui decide, et il se mesure.
# Ce qui est verifie ici, c'est ce dont le calcul d'entropie depend — des entrees
# non vides et sans doublon. `len()` compte les ENTREES : une liste de 7776
# lignes portant toutes le meme mot ferait annoncer 90 bits pour zero.
if not isinstance(WORDS, list) or not WORDS:
    sys.exit(f"{CHEMIN} n'est pas une liste de mots.")
if not all(isinstance(m, str) and m.strip() for m in WORDS):
    sys.exit(f"{CHEMIN} porte des entrees vides ou non textuelles.")
DOUBLONS = len(WORDS) - len(set(WORDS))
if DOUBLONS:
    sys.exit(f"{CHEMIN} porte {DOUBLONS} doublon(s) : une liste qui se repete "
             f"annonce plus de mots qu'elle n'en a.")

try:
    n = int(sys.argv[1]) if len(sys.argv) > 1 else 7
except ValueError:
    sys.exit(f"nombre de mots invalide : {sys.argv[1]!r}")
# Le plancher se mesure sur CETTE liste. Un plancher en mots serait juste sur la
# liste longue et faux sur la courte.
bits = n * math.log2(len(WORDS))
if bits < BITS_MIN:
    besoin = math.ceil(BITS_MIN / math.log2(len(WORDS)))
    sys.exit(f"{n} mots sur une liste de {len(WORDS)} n'en font que {bits:.1f} bits — "
             f"plancher {BITS_MIN:.0f}. Sur cette liste il en faut {besoin}.")

# secrets.choice : tirage uniforme sans biais de modulo, source cryptographique.
mots = [secrets.choice(WORDS) for _ in range(n)]

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
