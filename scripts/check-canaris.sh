#!/usr/bin/env bash
#
# Les canaris de la CI plantent-ils encore le défaut qu'ils nomment ?
#
# Un canari est fait de deux littéraux : le MOTIF qu'il plante dans un fichier,
# et la LIGNE ROUGE qu'il exige du banc. Les deux vieillissent quand le code
# avance — exactement comme les totaux que garde `check-portes.sh`, et c'est la
# même classe de défaut sur la famille voisine.
#
# ⚠️ Mesuré le 10/10/2026 : sur 27 canaris de `structure.yml`, **trois** avaient
# un motif périmé par le lot SelfRecover 0.12.0. Le job est tombé sur le premier,
# et son message envoyait corriger un contrôle qui faisait exactement son
# travail. Les trois ont refusé de prouver quoi que ce soit plutôt que de rendre
# vert — la garde « la plantation n'a pas pris » a joué. C'est elle que ce
# contrôle rejoue, avant l'envoi plutôt qu'après.
#
# 🔑 **Il lance le `run:` de chaque étape, il ne le relit pas.** Un contrôle qui
# vérifierait la présence des motifs par `grep` dirait qu'ils existent, pas
# qu'ils plantent — et la plantation est ce qui donne sa valeur au rouge.
#
# ⚠️ Les canaris MODIFIENT des fichiers du dépôt puis les restaurent, chacun
# contre une empreinte prise avant plantation. À lancer sur un arbre propre : un
# canari interrompu laisse son fichier planté, et `git status` est alors le seul
# à le dire.
#
# Usage : scripts/check-canaris.sh [chemin du workflow]
# Sortie : 0 si chaque canari plante et rougit comme il l'annonce, 1 sinon.

set -uo pipefail

WORKFLOW="${1:-.github/workflows/structure.yml}"
cd "$(dirname "$0")/.." || exit 1

[ -f "$WORKFLOW" ] || { echo "✗ workflow introuvable : $WORKFLOW"; exit 1; }

echo "▸ canaris de $WORKFLOW"

python3 - "$WORKFLOW" <<'PY'
import os
import re
import subprocess
import sys
import tempfile

lignes = open(sys.argv[1], encoding='utf-8').read().split('\n')

# 🔑 **Le bloc `run:` se borne à l'INDENTATION**, jamais au `- name:` suivant.
# Un premier jet prenait tout jusqu'à l'étape d'après, donc ses commentaires
# YAML — moins indentés —, puis les dédentait de la marge du script : le `#`
# sautait et sept canaris « échouaient » en exécutant les mots de leurs
# commentaires. La sonde mesurait son propre défaut.
canaris = []
for i, l in enumerate(lignes):
    m = re.match(r'(\s*)- name: (.*)', l)
    if m is None:
        continue
    nom = m.group(2).strip()
    if not nom.startswith('Canari'):
        continue
    j = i + 1
    while j < len(lignes) and re.match(r'\s*- name:', lignes[j]) is None:
        mr = re.match(r'(\s*)run: \|\s*$', lignes[j])
        if mr is not None:
            marge = len(mr.group(1))
            corps, k = [], j + 1
            while k < len(lignes):
                if lignes[k].strip() == '':
                    corps.append('')
                    k += 1
                    continue
                if len(lignes[k]) - len(lignes[k].lstrip()) <= marge:
                    break
                corps.append(lignes[k])
                k += 1
            while corps and corps[-1] == '':
                corps.pop()
            canaris.append((nom, corps))
            break
        j += 1

if not canaris:
    # Zéro canari n'est pas un succès : c'est un extracteur qui ne reconnaît plus
    # la forme des étapes, et il rendrait vert sur un fichier entièrement cassé.
    print("  ✗ aucun canari extrait — l'extracteur ne reconnaît plus la forme du workflow")
    sys.exit(1)

print(f'▸ {len(canaris)} canari(s) rejoué(s), chacun comme la CI le lance')
print()

verts, rouges, absents = 0, [], []
with tempfile.TemporaryDirectory(prefix='check-canaris-') as tmp:
    for n, (nom, corps) in enumerate(canaris, 1):
        creux = min((len(l) - len(l.lstrip()) for l in corps if l.strip()), default=0)
        script = os.path.join(tmp, f'canari-{n:02d}.sh')
        with open(script, 'w', encoding='utf-8') as f:
            f.write('\n'.join(l[creux:] if len(l) >= creux else l for l in corps))

        r = subprocess.run(['bash', '-e', script], capture_output=True, text=True, timeout=900)
        sortie = (r.stdout + r.stderr).split('\n')

        if r.returncode == 0:
            verts += 1
            print(f'  ✓ {n:2d}. {nom[:68]}')
            continue

        # Un outil manquant n'est pas un canari périmé : le dire, ne pas le compter rouge.
        manque = [l for l in sortie if 'command not found' in l or 'commande introuvable' in l]
        erreurs = [l for l in sortie if '::error::' in l]
        if manque and not erreurs:
            absents.append((nom, manque[0].strip()))
            print(f'  • {n:2d}. {nom[:68]}')
            print(f'        non rejoué — {manque[0].strip()[:72]}')
            continue

        cause = (erreurs[-1].replace('::error::', '').strip() if erreurs
                 else f'rc={r.returncode} · ' + ' ⏎ '.join(x for x in sortie[-3:] if x.strip()))
        rouges.append((nom, cause))
        print(f'  ✗ {n:2d}. {nom[:68]}')
        print(f'        {cause[:100]}')

print()
if absents:
    print(f'• {len(absents)} canari(s) non rejoué(s) : un outil manque sur cette machine.')
if not rouges:
    print(f'✓ les {verts} canari(s) rejoué(s) plantent et rougissent comme ils l\'annoncent.')
    sys.exit(0)

print(f'✗ {len(rouges)} canari(s) sur {verts + len(rouges)} rejoué(s) ne tiennent plus leur promesse :')
for nom, cause in rouges:
    print(f'   • {nom[:60]} — {cause[:80]}')
print('  La CI tomberait sur le premier, et son message accuserait le contrôle planté.')
sys.exit(1)
PY
