#!/usr/bin/env python3
"""Fabrique un coffre SELFVAULT2 de démonstration, à deux serrures."""
import datetime, json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from selfvault import FORMAT, bits_de, code_recuperation, fabriquer, tirer_phrase

# La version du coffre se donne, elle ne se devine pas. Le pli demande au
# dépositaire de détruire l'ancien pli d'après ce numéro : deux coffres qui
# naîtraient tous les deux « version 1 » ne seraient pas départageables, et leurs
# dates figées certifiées par l'AAD achèveraient de brouiller la piste.
VERSION = int(sys.argv[1]) if len(sys.argv) > 1 else 1
DATE = datetime.date.today().isoformat()

CONTENU = """DIRECTIVES ET ACCÈS — Sophie MARTIN
Coffre établi le 4 septembre 2026.

À la personne qui lit ces lignes : ce coffre accompagne mes directives sur le sort
de mes données personnelles après mon décès (article 85 de la loi n° 78-17).
Les directives disent CE QU'IL FAUT FAIRE ; ce coffre donne LES MOYENS DE LE FAIRE.

--- COMPTES ET SERVICES ---

1. Messagerie principale — sophie.martin.exemple@example.org
   Sort demandé : fermeture définitive après extraction des pièces administratives.
   Personne chargée : Marie DUPONT.

2. Hébergement de fichiers — même adresse
   Sort demandé : les dossiers « Famille » et « Photos » remis à Marie DUPONT ;
   tout le reste effacé sans être consulté.

3. Réseau social — compte au nom de Sophie MARTIN
   Sort demandé : mise en mémorial, aucun message publié en mon nom.

4. Banque en ligne — agence de Sainte-Foy (33220)
   Aucun accès transmis ici : la succession suit sa voie ordinaire.

5. Gestionnaire de mots de passe
   Les identifiants n'ont pas été recopiés dans ce coffre. Le gestionnaire dispose
   de son propre accès d'urgence, décrit dans les directives.

--- CE QUE JE DEMANDE EXPRESSÉMENT ---

Aucune donnée de ce coffre ne doit être publiée, transmise à un tiers commercial,
ni utilisée pour entraîner un système automatique.

Les messages privés ne doivent être lus par personne. Leur effacement prime sur
leur conservation, y compris si un proche demande le contraire.

--- OÙ SE TROUVE LE COFFRE ---

Le fichier chiffré est conservé en trois exemplaires : sur mon ordinateur personnel,
sur un disque externe rangé au domicile, et dans les codes-barres du pli déposé
à l'étude. Les trois portent le même contenu.
"""

code_notaire = code_recuperation()
# La phrase de la titulaire est TIRÉE, pas choisie. C'est ce qui autorise PBKDF2
# sur un coffre remis à un tiers : voir le plancher d'entropie dans selfvault.py.
MOT_UTILISATEUR = tirer_phrase()

coffre = fabriquer(CONTENU, [("L2 — titulaire", MOT_UTILISATEUR),
                             ("L1 — dépositaire", code_notaire)],
                   version=VERSION, date=DATE)

SD      = os.path.dirname(os.path.abspath(__file__))
RACINE  = os.path.dirname(SD)
SECRETS = os.path.join(SD, "secrets"); os.makedirs(SECRETS, exist_ok=True)
SORTIE  = os.path.join(RACINE, "sortie"); os.makedirs(SORTIE, exist_ok=True)
chemin = os.path.join(SORTIE, "coffre.selfvault")
open(chemin, "w").write(json.dumps(coffre, ensure_ascii=False, indent=1))
open(os.path.join(SECRETS, "code_L1.txt"), "w").write(code_notaire + "\n")
open(os.path.join(SECRETS, "mot_L2.txt"), "w").write(MOT_UTILISATEUR + "\n")
# Le nom de la titulaire n'entre pas dans le coffre en clair : il vit à côté,
# pour la page 1 du pli, qui l'imprime.
json.dump({"titulaire": "Sophie MARTIN"}, open(os.path.join(SORTIE, "meta.json"), "w"),
          ensure_ascii=False)
print(f"version : {VERSION} — du {DATE}")
print(f"coffre  : {os.path.getsize(chemin)} octets — {FORMAT}")
print(f"clair   : {len(CONTENU)} octets")
print(f"code L1 : {code_notaire}  ({bits_de(code_notaire):.0f} bits)")
print(f"mot L2  : {MOT_UTILISATEUR}  ({bits_de(MOT_UTILISATEUR):.0f} bits)")
