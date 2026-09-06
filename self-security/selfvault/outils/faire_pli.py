#!/usr/bin/env python3
"""Compose le pli à déposer : instructions, code, notice, et les fichiers en QR codes."""
import base64, hashlib, os, json, re, sys, qrcode
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from selfvault import (ALPHABET, BITS_MIN, FORMAT, ITER_MAX, ITER_MIN,
                       SERRURES_MAX, VERSION_MAX, empreinte_sceau)
import math

# Les chemins partent de la RACINE du module, pas du répertoire du script.
# `pli/` porte les sources — le déchiffreur et le gabarit. `sortie/` porte ce que
# la chaîne produit, et n'est pas versionné (voir `.gitignore`).
SD     = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.dirname(SD)
PLI    = os.path.join(RACINE, "pli")
SORTIE = os.path.join(RACINE, "sortie")
QRD    = os.path.join(SORTIE, "qr"); os.makedirs(QRD, exist_ok=True)
# 🔑 Le répertoire est PURGÉ avant d'écrire. Sans ça, les images d'un pli plus
# gros survivent à côté de celles d'un pli plus petit, et tout consommateur qui
# lit le répertoire en entier — `tests/banc_papier.sh` le fait — mêle deux
# tirages. Il rougit alors bruyamment, mais pour la mauvaise raison : « rescanne
# à 300 points par pouce » là où rien n'est mal numérisé.
# Suppression nommée, jamais récursive : on retire ce que ce script a posé.
for _vieux in sorted(os.listdir(QRD)):
    if re.fullmatch(r"[AV]\d+\.png", _vieux):
        os.remove(os.path.join(QRD, _vieux))
CHARGE = 1600            # octets de texte par QR code, correction Q comprise

# Le préfixe versionne le DÉCOUPAGE du pli, pas le format du coffre. Les deux
# évoluent séparément : un coffre SELFVAULT3 se découpe exactement comme un
# SELFVAULT1. Les nommer pareil invitait à les faire bouger ensemble.
PREFIXE = "PLI1"

def decouper(nom_court, chemin):
    """Un fichier → une suite de QR codes numérotés, chacun autonome."""
    brut = open(chemin, "rb").read()
    txt  = base64.b64encode(brut).decode()
    parts = [txt[i:i+CHARGE] for i in range(0, len(txt), CHARGE)]
    codes = []
    for i, part in enumerate(parts, 1):
        # L'index vit DANS les données : un pli scanné en désordre se reconstitue quand même.
        codes.append(f"{PREFIXE}|{nom_court}|{i}/{len(parts)}|{part}")
    return brut, codes

def image(donnees, sortie):
    q = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_Q, border=4)
    q.add_data(donnees); q.make(fit=True)
    q.make_image(fill_color="black", back_color="white").save(sortie)
    return q.version

pieces, tous = {}, []
for court, chemin in (("A", os.path.join(PLI, "selfvault.html")),
                      ("V", os.path.join(SORTIE, "coffre.selfvault"))):
    fichier = os.path.basename(chemin)
    brut, codes = decouper(court, chemin)
    pieces[court] = {"fichier": fichier, "octets": len(brut),
                     "sha": hashlib.sha256(brut).hexdigest(), "n": len(codes)}
    for i, c in enumerate(codes, 1):
        nom = f"{court}{i:02d}.png"
        v = image(c, os.path.join(QRD, nom))
        tous.append({"nom": nom, "etiquette": f"{court}{i}/{len(codes)}", "version": v})

json.dump({"pieces": pieces, "codes": tous}, open(os.path.join(SORTIE, "pli.json"), "w"), indent=1)

# --- Rendu du pli -----------------------------------------------------------
# Substituer plutôt qu'éditer à la main : c'est ce qui empêche un chiffre de la
# page 1 de dire autre chose que le fichier qu'il décrit. Un jeton qui survit à la
# substitution fait échouer le rendu.
MOIS = ("janvier février mars avril mai juin juillet août septembre octobre "
        "novembre décembre").split()

coffre_json = json.load(open(os.path.join(SORTIE, "coffre.selfvault")))
meta = json.load(open(os.path.join(SORTIE, "meta.json")))
an, mois, jour = (int(x) for x in coffre_json["date"].split("-"))
code_l1 = open(os.path.join(SD, "secrets", "code_L1.txt")).read().strip()

def groupe4(h):
    return " ".join(h[i:i+4] for i in range(0, 32, 4))

grille = "\n".join(
    '<figure><img src="qr/%s"><figcaption>%s</figcaption></figure>' % (c["nom"], c["etiquette"])
    for c in tous)

jetons = {
    "VERSION": str(coffre_json["version"]),
    "DATE": coffre_json["date"],
    "DATE_LONGUE": "%d %s %d" % (jour, MOIS[mois - 1], an),
    "TITULAIRE": meta["titulaire"],
    "NAISSANCE": meta["naissance"],
    "REFERENCE": meta["reference"],
    "CODE_L1": code_l1,
    "OCTETS_APP": str(pieces["A"]["octets"]),
    "NQR_APP": str(pieces["A"]["n"]),
    "EMPREINTE_APP": groupe4(pieces["A"]["sha"]),
    "OCTETS_COFFRE": str(pieces["V"]["octets"]),
    "NQR_COFFRE": str(pieces["V"]["n"]),
    "EMPREINTE_COFFRE": groupe4(pieces["V"]["sha"]),
    "TOTAL_QR": str(len(tous)),
    "BITS_MIN": "%d" % BITS_MIN,
    # 🔑 Toute valeur que la notice imprimée énonce vient d'ici. Une constante
    # écrite en dur dans le gabarit échappe au garde-fou de substitution par
    # construction, et un pli imprimé ne se corrige pas : les cinq bornes ajoutées
    # le 06/09/2026 y étaient toutes figées en dur.
    "FORMAT": FORMAT,
    "VERSION_MAX": "{:,d}".format(VERSION_MAX).replace(",", "\u202f"),
    "ITER_MIN": "{:,d}".format(ITER_MIN).replace(",", "\u202f"),
    "ITER_MAX": "{:,d}".format(ITER_MAX).replace(",", "\u202f"),
    "SERRURES_MAX": str(SERRURES_MAX),
    "TAILLE_ALPHABET": str(len(ALPHABET)),
    "BITS_L1": "%d" % (20 * math.log2(len(ALPHABET))),
    "EMPREINTE_SCEAU": empreinte_sceau(coffre_json),
    "GRILLE_QR": grille,
}
rendu = open(os.path.join(PLI, "gabarit-pli.html")).read()
for cle, valeur in jetons.items():
    rendu = rendu.replace("{{%s}}" % cle, valeur)
restants = re.findall(r"\{\{[A-Z0-9_]+\}\}", rendu)
if restants:
    raise SystemExit("jeton non substitué : " + ", ".join(sorted(set(restants))))
open(os.path.join(SORTIE, "pli.html"), "w").write(rendu)
print(f"  pli rendu : sortie/pli.html — {len(rendu)} octets")
for c, p in pieces.items():
    print(f"  {p['fichier']:22s} {p['octets']:6d} o → {p['n']} QR code(s)   sha256 {p['sha'][:16]}…")
print(f"  total : {len(tous)} QR codes, version QR max {max(c['version'] for c in tous)}")
