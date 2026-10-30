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
# ⚠️ Les canaris MODIFIENT des fichiers du dépôt puis les restaurent eux-mêmes.
# Le rejeu ne s'y fie pas : il photographie l'arbre avant de commencer — par git,
# sans toucher l'index —, le compare après chaque canari et rétablit ce qu'un
# canari a laissé, depuis la photo : un travail non commité revient tel quel. Un
# rejeu coupé net (arrêt de la machine, `kill -9`) laisse un journal, et le
# lancement suivant refuse de rejouer : il nomme les fichiers sans rien rétablir,
# puisqu'ils ont pu être modifiés depuis.
# Limites : un fichier écrit dans un chemin ignoré échappe à la photo ; un
# processus qui se détache (`setsid`) échappe à l'arrêt du canari ; une édition
# faite PENDANT le rejeu passe pour un résidu — rétablie, mais gardée sous
# `refs/worktree/check-canaris/residu-NN`.
#
# Usage : scripts/check-canaris.sh [--statique | --abandonner] [chemin du workflow]
# Sortie : 0 si le périmètre est déclaré et si chaque canari plante et rougit
#          comme il l'annonce, 1 sinon, 130 si le rejeu est interrompu.
#          `--abandonner` lève le journal d'un rejeu coupé, une fois l'arbre vérifié.
#
# 🔑 **Deux sens, deux natures.** « Une étape qui plante dans le dépôt est
# déclarée » se lit sur le YAML, sans prérequis : c'est `--statique`, et c'est
# pour ça qu'il tient en intégration continue. « Une étape déclarée plante
# vraiment et rougit » demande de la LANCER, donc les prérequis des bancs : c'est
# le volet complet, qui s'exécute avant un envoi. Un contrôle statique qui
# promettrait le second rendrait un vert dont personne ne connaît la portée.

set -uo pipefail

STATIQUE=0 ABANDONNER=0
case "${1:-}" in
  --statique)   STATIQUE=1; shift ;;
  --abandonner) ABANDONNER=1; shift ;;
esac

WORKFLOW="${1:-.github/workflows/structure.yml}"
cd "$(dirname "$0")/.." || exit 1

[ -f "$WORKFLOW" ] || { echo "✗ workflow introuvable : $WORKFLOW"; exit 1; }

if [ "$STATIQUE" = 1 ]; then
  echo "▸ périmètre des canaris de $WORKFLOW (statique, rien n'est lancé)"
elif [ "$ABANDONNER" = 0 ]; then
  echo "▸ canaris de $WORKFLOW"
fi

# `exec` : un SIGTERM adressé au script doit atteindre le Python, qui sait rétablir
# l'arbre — pas un `bash` qui mourrait seul en laissant le rejeu tourner. `-u` : la
# progression s'écrit au fil des canaris, même quand la sortie va dans un fichier.
exec python3 -u - "$WORKFLOW" "$STATIQUE" "$ABANDONNER" <<'PY'
import fcntl
import hashlib
import json
import os
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time

MARQUEUR = '# @plante'
DELAI = int(os.environ.get('CHECK_CANARIS_DELAI', '900'))
STATIQUE, ABANDONNER = sys.argv[2] == '1', sys.argv[3] == '1'


def git(*args, env=None):
    r = subprocess.run(['git', *args], capture_output=True, text=True, env=env)
    if r.returncode != 0:
        raise SystemExit(f"✗ git {' '.join(args[:4])} : {r.stderr.strip()[:200]}")
    return r.stdout


# ── L'état d'un rejeu ─────────────────────────────────────────────────────────
# Propre à cet arbre, hors de `git status`, et il survit au redémarrage : ni
# `/tmp`, vidé au démarrage, ni un dossier temporaire, que Python efface en
# sortant sur une erreur — avec la sauvegarde du canari dedans.
ETAT = git('rev-parse', '--path-format=absolute', '--git-path', 'check-canaris').strip()
INDEX = git('rev-parse', '--path-format=absolute', '--git-path', 'index').strip()
JOURNAL = os.path.join(ETAT, 'journal.json')
ESPACE = 'refs/worktree/check-canaris/'
AVANT = ESPACE + 'avant'


def verrouiller():
    os.makedirs(ETAT, exist_ok=True)
    fd = os.open(os.path.join(ETAT, 'verrou'), os.O_CREAT | os.O_RDWR, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print("✗ un rejeu tourne déjà dans cet arbre — rien n'est lancé")
        sys.exit(1)
    # Gardé ouvert, et non hérité par les canaris : le verrou meurt avec ce
    # processus, même tué net.
    return fd


def ecrire_journal(etat):
    provisoire = JOURNAL + '.provisoire'
    with open(provisoire, 'w', encoding='utf-8') as f:
        json.dump(etat, f, ensure_ascii=False)
        f.flush()
        os.fsync(f.fileno())
    os.replace(provisoire, JOURNAL)
    d = os.open(ETAT, os.O_RDONLY)
    os.fsync(d)
    os.close(d)


def photo():
    """L'arbre tel qu'il est sur le disque, en un objet `tree` : fichiers suivis
    avec leurs modifications, non suivis non ignorés, suppressions. L'index réel
    n'est jamais écrit — la photo passe par une copie."""
    copie = os.path.join(ETAT, 'index.photo')
    shutil.copyfile(INDEX, copie)
    env = dict(os.environ, GIT_INDEX_FILE=copie)
    git('-c', 'core.fsync=committed', 'add', '-A', '--', ':/', env=env)
    return git('-c', 'core.fsync=committed', 'write-tree', env=env).strip()


def ecarts(avant, apres):
    if avant == apres:
        return []
    champs = git('diff-tree', '-r', '-z', '--no-renames', '--name-status', avant, apres).split('\0')
    return list(zip(champs[0::2], champs[1::2]))


def empreinte_index():
    return hashlib.sha256(git('ls-files', '-s', '-z').encode()).hexdigest()


def references():
    return git('for-each-ref', '--format=%(refname)', ESPACE).split()


if ABANDONNER:
    verrou = verrouiller()
    if not os.path.exists(JOURNAL) and not references():
        print('• aucun rejeu à lever dans cet arbre')
        sys.exit(0)
    for ref in references():
        git('update-ref', '-d', ref)
        print(f'  • {ref} effacée')
    if os.path.exists(JOURNAL):
        os.remove(JOURNAL)
        print('  • journal levé')
    print("✓ état levé — le prochain rejeu photographie l'arbre tel qu'il est")
    sys.exit(0)

if STATIQUE and os.path.exists(JOURNAL):
    print("⚠️ un rejeu complet n'a pas fini dans cet arbre — lance le volet complet : il dira ce qu'il a laissé")

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

if STATIQUE:
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

# ── Interruption ──────────────────────────────────────────────────────────────
# Un signal ne lève rien au milieu d'une réparation : il tue le canari en cours,
# et la boucle s'arrête une fois l'arbre rétabli.
ARRET, EN_COURS = [], []


def tuer(p):
    try:
        os.killpg(p.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass


def arreter(signum, _):
    ARRET.append(signum)
    for p in EN_COURS:
        tuer(p)


for s in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
    signal.signal(s, arreter)


def lancer(script, prive):
    # Son propre groupe : à l'arrêt, le banc PHP qu'il a lancé meurt avec lui.
    p = subprocess.Popen(['bash', '-e', script], stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                         text=True, env=dict(os.environ, TMPDIR=prive), start_new_session=True)
    EN_COURS.append(p)
    if ARRET:
        tuer(p)
    depasse = False
    try:
        sortie, _ = p.communicate(timeout=DELAI)
    except subprocess.TimeoutExpired:
        depasse = True
        tuer(p)
        try:
            sortie, _ = p.communicate(timeout=10)
        except subprocess.TimeoutExpired:
            sortie = ''
            p.wait()
    finally:
        EN_COURS.clear()
    return p.returncode, sortie.split('\n'), depasse


def retablir(n, avant):
    """Rétablit ce que le canari n a laissé, depuis la photo d'avant le rejeu —
    jamais depuis HEAD. Rend la liste des écarts trouvés."""
    apres = photo()
    laisses = ecarts(avant, apres)
    if not laisses:
        return []
    git('update-ref', f'{ESPACE}residu-{n:02d}', apres)
    for statut, chemin in laisses:
        present = os.path.lexists(chemin)
        if statut == 'D':
            if not present:
                git('restore', f'--source={avant}', '--worktree', '--', chemin)
            continue
        # N'écraser que ce que le canari a laissé : un contenu qui a bougé depuis
        # la photo prise juste après lui (`apres`) n'est plus le sien.
        if not present or (git('hash-object', '--', chemin).strip()
                           != git('rev-parse', f'{apres}:{chemin}').strip()):
            continue
        if statut == 'A':
            os.remove(chemin)
        else:
            git('restore', f'--source={avant}', '--worktree', '--', chemin)
    reste = ecarts(avant, photo())
    if reste:
        print(f'  ✗ {n:2d}. le rejeu ne sait pas rétablir ce que ce canari a laissé :')
        for statut, chemin in reste:
            print(f'        {statut} {chemin}')
        print(f'  Le journal reste : relance le script pour la marche à suivre.')
        sys.exit(1)
    return laisses


# ── Le rejeu précédent a-t-il fini ? ──────────────────────────────────────────
verrou = verrouiller()

if os.path.exists(JOURNAL):
    with open(JOURNAL, encoding='utf-8') as f:
        ancien = json.load(f)
    try:
        laisses = ecarts(ancien['photo'], photo())
    except SystemExit:
        laisses = None
    if laisses == []:
        print(f"• le rejeu du {ancien['debut']} s'est arrêté sans rien laisser : son journal est levé")
        git('update-ref', '-d', AVANT)
        os.remove(JOURNAL)
    else:
        print(f"✗ le rejeu du {ancien['debut']} n'a pas fini — arrêté pendant le canari "
              f"{ancien['canari']} « {ancien['nom'][:60]} »")
        print(f"  HEAD d'alors {ancien['head'][:7]} · d'aujourd'hui {git('rev-parse', 'HEAD').strip()[:7]}")
        if laisses is None:
            print("  ✗ sa photo est illisible : compare l'arbre à la main (`git status`, `git diff`)")
        else:
            print(f"  {len(laisses)} chemin(s) diffèrent de l'arbre d'avant ce rejeu :")
            for statut, chemin in laisses:
                print(f'    {statut}  {chemin}')
            print(f'  Voir :      git diff {AVANT} -- <chemin>')
            print(f'  Rétablir :  git restore --source={AVANT} --worktree -- <chemin>')
            print('              (statut A : le fichier est apparu, rm -- <chemin>)')
        print("  Rien n'est rejoué ni rétabli : ces fichiers ont pu changer depuis.")
        print('  Une fois vérifié : scripts/check-canaris.sh --abandonner')
        sys.exit(1)

# ── Photo, puis rejeu ─────────────────────────────────────────────────────────
avant = photo()
git('update-ref', AVANT, avant)          # protège les objets de la photo du `gc`
index_avant = empreinte_index()
rejeu = {'debut': time.strftime('%Y-%m-%d %H:%M:%S'), 'head': git('rev-parse', 'HEAD').strip(),
         'photo': avant, 'canari': 0, 'nom': ''}
ecrire_journal(rejeu)

print(f'▸ {len(canaris)} canari(s) rejoué(s), chacun comme la CI le lance')
print()

verts, rouges, absents, residus = 0, [], [], 0
with tempfile.TemporaryDirectory(prefix='check-canaris-') as tmp:
    for n, (nom, corps) in enumerate(canaris, 1):
        if ARRET:
            break
        rejeu.update(canari=n, nom=nom)
        ecrire_journal(rejeu)
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

        rc, sortie, depasse = lancer(script, prive)
        laisses = retablir(n, avant)
        index_bouge = empreinte_index() != index_avant
        if index_bouge:
            index_avant = empreinte_index()
        if ARRET:
            break

        # Un canari vert qui laisse un fichier changé est rouge : en CI, les
        # étapes suivantes tourneraient sur son défaut.
        notes = []
        if laisses:
            residus += 1
            notes.append('a laissé ' + ', '.join(f'{s} {c}' for s, c in laisses) + ' — rétabli')
        if index_bouge:
            notes.append("a modifié l'index — non rétabli")

        if depasse:
            cause = f"dépasse {DELAI} s — arrêté avec tout ce qu'il avait lancé"
        elif rc == 0:
            if not notes:
                verts += 1
                print(f'  ✓ {n:2d}. {nom[:68]}')
                continue
            cause = None
        else:
            # Un outil manquant n'est pas un canari périmé : le dire, ne pas le compter rouge.
            manque = [l for l in sortie if 'command not found' in l or 'commande introuvable' in l]
            erreurs = [l for l in sortie if '::error::' in l]
            if manque and not erreurs and not notes:
                absents.append((nom, manque[0].strip()))
                print(f'  • {n:2d}. {nom[:68]}')
                print(f'        non rejoué — {manque[0].strip()[:72]}')
                continue
            cause = (erreurs[-1].replace('::error::', '').strip() if erreurs
                     else f'rc={rc} · ' + ' ⏎ '.join(x for x in sortie[-3:] if x.strip()))

        cause = ' · '.join(x for x in [cause, *notes] if x)
        rouges.append((nom, cause))
        print(f'  ✗ {n:2d}. {nom[:68]}')
        print(f'        {cause[:100]}')

# La photo de fin doit rendre celle d'avant : c'est elle qui autorise à lever l'état.
if ecarts(avant, photo()):
    print("✗ l'arbre ne rend pas la photo d'avant le rejeu — le journal reste : relance pour la marche à suivre")
    sys.exit(1)
git('update-ref', '-d', AVANT)
os.remove(JOURNAL)
os.remove(os.path.join(ETAT, 'index.photo'))

print()
if residus:
    print(f"• {residus} canari(s) ont laissé un fichier changé, rétabli. Ce qu'ils ont laissé est gardé sous"
          f' {ESPACE}residu-NN (`git show <réf>:<chemin>`) ; `--abandonner` l\'efface.')
if ARRET:
    print(f"✗ rejeu interrompu au canari {rejeu['canari']} — l'arbre est rendu tel qu'avant le rejeu")
    sys.exit(130)
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
