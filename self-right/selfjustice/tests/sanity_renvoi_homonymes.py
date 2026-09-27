#!/usr/bin/env python3
"""Garde-fou — le renvoi « Voir « homonymes » » trouve sa cible chez le client.

🔑 La réserve que rend `/jurisprudence/verifier` renvoie le lecteur à un CHAMP de
la réponse : « Voir « homonymes » ». Pour un client HTTP, le renvoi est exact —
`api.php` peuple bien `homonymes`. Le serveur MCP, lui, relayait la réserve mot
pour mot et jetait le champ : l'invitation arrivait au modèle sans rien derrière
elle. Mesuré le 27/09/2026 sur « 23/00039 » daté du 05/01/2023, dont la réponse
finissait sur ce renvoi, suivi de rien.

Le défaut n'a rien cassé, et c'est pourquoi il a duré : deux fichiers de deux
langages portent les deux moitiés d'une même phrase, et rien ne les tenait
ensemble.

Ce banc impose le sens qui compte : tant que l'API renvoie à ce champ, chaque
réponse du client qui relaie une réserve doit aussi rendre le champ. L'inverse
est libre — rendre les homonymes reste utile si l'API cesse un jour d'y renvoyer.

    python3 tests/sanity_renvoi_homonymes.py
"""

import ast
import pathlib
import sys

RACINE = pathlib.Path(__file__).resolve().parent.parent.parent   # self-right/
API = RACINE / "selfjustice" / "api" / "api.php"
SERVEUR = RACINE / "selfjustice" / "mcp" / "selfright_mcp" / "server.py"

RENVOI = "« homonymes »"
OUTIL = "verifier_jurisprudence"
BLOC = "_bloc_homonymes"

echecs: list[str] = []


def nok(message: str) -> None:
    echecs.append(message)
    print(f"  ✗ {message}")


def ok(message: str) -> None:
    print(f"  ✓ {message}")


api = API.read_text(encoding="utf-8")
renvois = api.count(RENVOI)
print(f"▸ {API.name} renvoie {renvois} fois à « homonymes »")

if renvois == 0:
    print("  ↷ plus de renvoi dans l'API — rien à exiger du client")
    sys.exit(0)

source = SERVEUR.read_text(encoding="utf-8")
arbre = ast.parse(source)

outil = next(
    (n for n in ast.walk(arbre)
     if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef)) and n.name == OUTIL),
    None,
)
if outil is None:
    nok(f"{OUTIL} introuvable dans {SERVEUR.name} — le banc ne mesure plus rien")
    sys.exit(1)

# Le champ doit être LU quelque part, sinon le renvoi est orphelin quoi qu'il
# arrive ensuite.
if 'data.get("homonymes")' in source or "data.get('homonymes')" in source:
    ok("le client lit le champ « homonymes » de la réponse")
else:
    nok("aucune lecture de `data.get(\"homonymes\")` — le champ est jeté")

# 🔑 Une lecture quelque part ne suffit pas : c'est CHAQUE réponse qui relaie une
# réserve qui doit rendre le champ. Le défaut du 27/09 tenait à une branche —
# `absente` — que la correction d'une seule aurait laissée muette.
print(f"▸ les réponses de {OUTIL} qui relaient une réserve")
relayantes = 0
for noeud in ast.walk(outil):
    if not isinstance(noeud, ast.Return) or noeud.value is None:
        continue
    rendu = ast.get_source_segment(source, noeud.value) or ""
    if "reserve" not in rendu:
        continue
    relayantes += 1
    ligne = noeud.lineno
    if BLOC in rendu:
        ok(f"ligne {ligne} : réserve relayée, {BLOC}() appelé")
    else:
        nok(f"ligne {ligne} : réserve relayée sans {BLOC}() — "
            "le renvoi de l'API y arrive sans sa cible")

if relayantes == 0:
    nok(f"aucune réponse de {OUTIL} ne relaie de réserve — "
        "le banc ne mesure plus ce qu'il croit mesurer")

print()
if echecs:
    print(f"ÉCHEC — {len(echecs)} défaut(s)")
    sys.exit(1)
print(f"OK — {relayantes} réponse(s) relayant une réserve rendent aussi les homonymes")
