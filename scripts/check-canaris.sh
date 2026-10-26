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
# Usage : scripts/check-canaris.sh [--statique] [chemin du workflow]
# Sortie : 0 si le périmètre est déclaré et si chaque canari plante et rougit
#          comme il l'annonce, 1 sinon.
#
# 🔑 **Deux sens, deux natures.** « Une étape qui plante dans le dépôt est
# déclarée » se lit sur le YAML, sans prérequis : c'est `--statique`, et c'est
# pour ça qu'il tient en intégration continue. « Une étape déclarée plante
# vraiment et rougit » demande de la LANCER, donc les prérequis des bancs : c'est
# le volet complet, qui s'exécute avant un envoi. Un contrôle statique qui
# promettrait le second rendrait un vert dont personne ne connaît la portée.

set -uo pipefail

STATIQUE=0
if [ "${1:-}" = "--statique" ]; then STATIQUE=1; shift; fi

WORKFLOW="${1:-.github/workflows/structure.yml}"
cd "$(dirname "$0")/.." || exit 1

[ -f "$WORKFLOW" ] || { echo "✗ workflow introuvable : $WORKFLOW"; exit 1; }

if [ "$STATIQUE" = 1 ]; then
  echo "▸ périmètre des canaris de $WORKFLOW (statique, rien n'est lancé)"
else
  echo "▸ canaris de $WORKFLOW"
fi

python3 - "$WORKFLOW" "$STATIQUE" <<'PY'
import os
import re
import subprocess
import sys
import tempfile

MARQUEUR = '# @plante'

lignes = open(sys.argv[1], encoding='utf-8').read().split('\n')

# 🔑 **Le bloc `run:` se borne à l'INDENTATION**, jamais au `- name:` suivant.
# Un premier jet prenait tout jusqu'à l'étape d'après, donc ses commentaires
# YAML — moins indentés —, puis les dédentait de la marge du script : le `#`
# sautait et sept canaris « échouaient » en exécutant les mots de leurs
# commentaires. La sonde mesurait son propre défaut.
# 🔑 **Le périmètre se déclare, il ne se devine pas.** Le filtre a d'abord été
# `nom.startswith('Canari')` : il ne voyait que 29 des 39 étapes qui plantaient
# alors, et les dix qu'il ratait — nommées « Garde-fou » — portaient un défaut que
# personne ne rejouait. Quatre heuristiques de contenu essayées le 10/10/2026 rendaient 12, 28,
# 33 et 39 : aucune ne décide. Chaque canari porte donc `# @plante` en tête de son
# `run:`, et c'est cette ligne, pas son libellé, qui le range ici.
etapes, breves = [], []
for i, l in enumerate(lignes):
    m = re.match(r'(\s*)- name: (.*)', l)
    if m is None:
        continue
    nom = m.group(2).strip()
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
            etapes.append((nom, corps))
            break
        # ⚠️ Un `run:` d'une seule ligne ne peut pas porter le marqueur en tête
        # de bloc. Sans cette branche, 59 étapes sortaient du périmètre LU sans
        # que la sortie le dise — et une plantation s'y cacherait aussi bien.
        mb = re.match(r'\s*run: (?!\|)(.+)$', lignes[j])
        if mb is not None:
            breves.append((nom, mb.group(1)))
            break
        j += 1

canaris = [(nom, corps) for nom, corps in etapes
           if any(x.strip() == MARQUEUR for x in corps)]

# ── Volet statique : le périmètre déclaré couvre-t-il ce qui plante ? ─────────
# Le couple sauvegarde→restauration est la signature de ce qui est RISQUÉ : une
# étape qui écrit dans un fichier du dépôt puis le réécrit. Un canari qui plante
# dans un `mktemp -d` ne laisse rien derrière lui et n'a pas cette signature —
# l'implication ne va donc que dans un sens, et c'est voulu : « plante dans le
# dépôt ⟹ déclaré », jamais l'inverse.
SAUVE = re.compile(r'cp\s+(?:-a\s+)?\S+\s+/tmp/')
RESTAU = re.compile(r'cp\s+(?:-a\s+)?/tmp/\S+\s+\S')
# Large exprès : ce motif ne sert qu'à dire « je ne vois RIEN qui plante ici »,
# et ce doute se lève à l'œil. Un marqueur orphelin qui passerait coûterait plus
# qu'un signalement à vérifier.
# ⚠️ Pas de `sed\s+[^|]*>` ici : cinq canaris emploient `|` comme séparateur de
# `sed`, et la classe qui l'exclut les rendait tous invisibles. Une redirection
# vers `/tmp` suffit à dire « quelque chose est fabriqué pour planter ».
PLANTE = re.compile(r"sed\s+-i|>\s*/tmp/|open\([^)]*['\"]w['\"]"
                    r"|write_text|json\.dump\(|printf[^|]*>>")

muets, orphelins = [], []
for nom, corps in etapes:
    txt = '\n'.join(corps)
    marque = any(x.strip() == MARQUEUR for x in corps)
    if bool(SAUVE.search(txt)) and bool(RESTAU.search(txt)) and not marque:
        muets.append(nom)
    if marque and not PLANTE.search(txt):
        orphelins.append(nom)

# Une forme que le marqueur ne peut pas atteindre ne se tait pas : elle se dit,
# et elle rougit si elle plante.
breves_qui_plantent = [(nom, r) for nom, r in breves if PLANTE.search(r)]

print(f'▸ {len(etapes)} étape(s) à `run:` en bloc, dont {len(canaris)} déclarée(s) `{MARQUEUR}`'
      f' · {len(breves)} à `run:` d\'une ligne, lue(s) pour la plantation')

if muets or orphelins or breves_qui_plantent:
    print()
    if muets:
        print(f'✗ {len(muets)} étape(s) écrivent dans un fichier du dépôt sans être déclarée(s) :')
        for nom in muets:
            print(f'   • {nom[:72]}')
        print(f'  Chacune plante un défaut que personne ne rejoue. Ajoute `{MARQUEUR}` en tête')
        print('  de son `run:`, ou dis pourquoi elle écrit sans planter.')
    if orphelins:
        if muets:
            print()
        print(f'✗ {len(orphelins)} étape(s) déclarée(s) sans plantation visible :')
        for nom in orphelins:
            print(f'   • {nom[:72]}')
        print('  Un marqueur qui survit à son étape rend vert sans rien éprouver.')
    if breves_qui_plantent:
        if muets or orphelins:
            print()
        print(f'✗ {len(breves_qui_plantent)} étape(s) plantent depuis un `run:` d\'une ligne :')
        for nom, r in breves_qui_plantent:
            print(f'   • {nom[:60]} → {r[:60]}')
        print(f'  Le marqueur se pose en tête d\'un bloc : passe son `run:` en `run: |`,')
        print(f'  puis déclare-la avec `{MARQUEUR}`.')
    print()
    print('  ⚠️ Le périmètre est partiel : le rejeu ne dirait pas ce qu\'il mesure.')
    sys.exit(1)

print('✓ périmètre déclaré complet : tout ce qui plante dans le dépôt porte son marqueur.')

if sys.argv[2] == '1':
    sys.exit(0)

if not canaris:
    # Zéro canari n'est pas un succès : c'est un extracteur qui ne reconnaît plus
    # la forme des étapes, et il rendrait vert sur un fichier entièrement cassé.
    # ⚠️ Ce garde parle APRÈS le volet statique, et non avant : sans marqueur, ce
    # qui renseigne est la liste des étapes non déclarées, pas une accusation
    # portée contre l'extracteur.
    print("  ✗ aucun canari extrait — l'extracteur ne reconnaît plus la forme du workflow")
    sys.exit(1)

print()

print(f'▸ {len(canaris)} canari(s) rejoué(s), chacun comme la CI le lance')
print()

verts, rouges, absents = 0, [], []
with tempfile.TemporaryDirectory(prefix='check-canaris-') as tmp:
    for n, (nom, corps) in enumerate(canaris, 1):
        creux = min((len(l) - len(l.lstrip()) for l in corps if l.strip()), default=0)
        # Un `/tmp` à lui : les `run:` sauvegardent dans des chemins fixes de
        # `/tmp`, que deux rejeux lancés en même temps depuis deux arbres
        # s'échangeraient — chacun restaurerait le fichier de l'autre. En CI,
        # chaque job a déjà le sien.
        prive = os.path.join(tmp, f'tmp-{n:02d}')
        os.mkdir(prive)
        script = os.path.join(tmp, f'canari-{n:02d}.sh')
        with open(script, 'w', encoding='utf-8') as f:
            f.write('\n'.join(l[creux:] if len(l) >= creux else l for l in corps).replace('/tmp/', prive + '/'))

        r = subprocess.run(['bash', '-e', script], capture_output=True, text=True, timeout=900,
                           env=dict(os.environ, TMPDIR=prive))
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
