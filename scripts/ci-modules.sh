#!/usr/bin/env bash
#
# Quels groupes de `structure.yml` un envoi doit-il jouer ?
#
# La table des chemins et des dépendances entre modules vit ici, et nulle part
# ailleurs : le job `modules` du workflow l'appelle, le job final lit ce qu'elle a
# rendu, et `--verifier` contrôle que les jobs du workflow suivent ses groupes.
#
# Un groupe tourne si l'envoi touche l'un des modules dont il dépend. Le lab
# charge SelfDataGuard, SelfRecover et SelfModerate (son `composer.json`) et porte
# les bancs de SelfModerate ; deux bancs de SelfRecover chargent SelfDataGuard.
# Le job `transverse` tourne à chaque envoi et n'est pas dans la table.
#
# Un chemin que la table ne range nulle part joue TOUT : un oubli de la table
# coûte un run complet, jamais un module non éprouvé.
#
# Usage :
#   scripts/ci-modules.sh --diff <base> <tete>   les fichiers de `git diff` entre les deux
#   scripts/ci-modules.sh --fichiers             la liste des fichiers lue sur l'entrée
#   scripts/ci-modules.sh --modules <liste>      des groupes nommés (virgules), ou « tout »
#   scripts/ci-modules.sh --verifier [workflow]  les jobs du workflow suivent-ils la table ?
# Sortie : des lignes `groupe=true|false` puis `raison=…`, à verser dans $GITHUB_OUTPUT ;
# le détail lisible va sur la sortie d'erreur. Code 1 sur une entrée invalide.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

mode="${1:-}"
case "$mode" in
  --diff)
    base="${2:-}"; tete="${3:-}"
    [ -n "$tete" ] || { echo "✗ --diff demande <base> <tete>" >&2; exit 1; }
    # Un `before` nul (première poussée d'une branche) ou absent de l'historique
    # ne dit pas ce qui a changé : on joue tout.
    if [ -z "$base" ] || [[ "$base" =~ ^0+$ ]] || ! git cat-file -e "$base^{commit}" 2>/dev/null; then
      export CI_TOUT="base introuvable (${base:-vide})"
      fichiers=""
    else
      fichiers=$(git diff --name-only "$base" "$tete") || { echo "✗ git diff $base $tete a échoué" >&2; exit 1; }
    fi
    ;;
  --fichiers) fichiers=$(cat) ;;
  --modules)  fichiers="" ;;
  --verifier) ;;
  *) sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//' >&2; exit 1 ;;
esac

export CI_MODE="$mode" CI_ARG="${2:-}" CI_WORKFLOW="${2:-.github/workflows/structure.yml}"
# Le programme arrive par le descripteur 3 : l'entrée standard porte la liste des fichiers.
printf '%s\n' "${fichiers:-}" | python3 /dev/fd/3 3<<'PY'
import os
import re
import sys

# ── La table ────────────────────────────────────────────────────────────────
# Chemin → module. Le premier préfixe qui correspond l'emporte.
MODULES = [
    ('self-security/selfdataguard/', 'SDG'),
    ('demo/selfdataguard/', 'SDG'),
    ('bi-self/selfrecover/', 'SR'),
    ('bi-self/selfmoderate/', 'SM'),
    ('demo/lab/', 'LAB'),
    ('self-right/', 'RIGHT'),
]
# Groupe → modules dont il dépend.
GROUPES = {
    'selfdataguard': {'SDG'},
    'selfrecover': {'SR'},
    'couplage': {'SR', 'SDG'},
    'lab': {'LAB', 'SR', 'SDG', 'SM'},
    'selfright': {'RIGHT'},
}
# Ce qui change la CI elle-même : tout se rejoue.
TOUT = ['.github/workflows/structure.yml', '.github/actions/', 'scripts/ci-modules.sh']
# Ce que seul le job `transverse` éprouve : il tourne à chaque envoi.
TRANSVERSE = ['scripts/', 'tools/', 'deploy/', 'web/', 'demo/bi-self-duo/', 'self-security/selfrecover-luks/',
              'docs/', 'assets/', '.claude/', '.github/', 'modules.json', 'AUTHORS', 'COPYRIGHT', 'DCO', 'LICENSE',
              '.gitignore', '.gitleaks.toml', '.gitleaksignore', '.htmlvalidate.json', '.mdl_style.rb', '.mdlrc',
              '.pre-commit-config.yaml', 'ruff.toml']


def rendre(choix, raison):
    for g in GROUPES:
        print(f'{g}={"true" if g in choix else "false"}')
    print(f'raison={raison}')
    print(f'▸ groupes joués : {", ".join(g for g in GROUPES if g in choix) or "aucun (transverse seul)"} — {raison}',
          file=sys.stderr)
    sys.exit(0)


def ranger(chemin):
    """Le module d'un chemin, '' pour le transverse seul, None s'il est inconnu."""
    if any(chemin == p or chemin.startswith(p) for p in TOUT):
        return 'TOUT'
    if chemin.endswith('.md'):
        return ''
    for p, m in MODULES:
        if chemin.startswith(p):
            return m
    if any(chemin == p or chemin.startswith(p) for p in TRANSVERSE):
        return ''
    return None


def verifier(chemin):
    """Les jobs du workflow suivent-ils la table ? Lecture ligne à ligne, comme check-canaris."""
    lignes = open(chemin, encoding='utf-8').read().split('\n')
    debut = lignes.index('jobs:')
    jobs, cour = {}, None
    for l in lignes[debut + 1:]:
        m = re.match(r'^  ([a-z0-9][a-z0-9-]*):\s*$', l)
        if m:
            cour = m.group(1)
            jobs[cour] = []
        elif cour and (l.startswith('    ') or not l.strip()):
            jobs[cour].append(l)
        elif cour and l.strip() and not l.startswith('  #'):
            cour = None

    def champ(job, cle):
        corps = jobs[job]
        for k, l in enumerate(corps):
            m = re.match(rf'^    {cle}:\s*(.*)$', l)
            if m:
                if m.group(1).strip():
                    v = m.group(1).strip()
                    return [x.strip() for x in v.strip('[]').split(',')] if cle == 'needs' else v
                liste = []
                for l2 in corps[k + 1:]:
                    m2 = re.match(r'^      - ([a-z0-9-]+)\s*$', l2)
                    if m2:
                        liste.append(m2.group(1))
                    elif l2.strip():
                        break
                return liste
        return None

    ecarts = []
    for j in ('modules', 'transverse', 'structure'):
        if j not in jobs:
            ecarts.append(f'job `{j}` absent')
    if ecarts:
        return ecarts
    autres = [j for j in jobs if j != 'structure']
    besoins = champ('structure', 'needs') or []
    if sorted(besoins) != sorted(autres):
        ecarts.append(f'le job final attend {sorted(set(besoins) ^ set(autres))} en trop ou en moins')
    if champ('structure', 'if') != 'always()':
        ecarts.append('le job final ne tourne pas sur `always()`')
    if champ('transverse', 'if') is not None:
        ecarts.append('le job `transverse` porte un `if:` : il doit tourner à chaque envoi')
    sorties = '\n'.join(jobs['modules'])
    vus = set()
    for j in jobs:
        if j in ('modules', 'transverse', 'structure'):
            continue
        g = max((g for g in GROUPES if j == g or j.startswith(g + '-')), key=len, default=None)
        if g is None:
            ecarts.append(f'`{j}` ne commence par aucun groupe de la table')
            continue
        vus.add(g)
        if 'modules' not in (champ(j, 'needs') or []):
            ecarts.append(f'`{j}` n\'attend pas le job `modules`')
        if champ(j, 'if') != f"needs.modules.outputs.{g} == 'true'":
            ecarts.append(f'`{j}` ne tourne pas sur la sortie `{g}` : {champ(j, "if")!r}')
    for g in GROUPES:
        if g not in vus:
            ecarts.append(f'le groupe `{g}` n\'a aucun job')
        if not re.search(rf'^      {g}: \$\{{\{{ steps\.choix\.outputs\.{g} \}}\}}$', sorties, re.M):
            ecarts.append(f'le job `modules` ne rend pas la sortie `{g}`')
    return ecarts


mode, arg = os.environ['CI_MODE'], os.environ['CI_ARG']
if mode == '--verifier':
    e = verifier(os.environ['CI_WORKFLOW'])
    for x in e:
        print('✗', x)
    print('✓ chaque job de structure suit la table des modules' if not e else f'{len(e)} écart(s)')
    sys.exit(1 if e else 0)

if mode == '--modules':
    demandes = [x.strip() for x in arg.split(',') if x.strip()]
    if demandes == ['tout']:
        rendre(set(GROUPES), 'tout, à la demande')
    inconnus = [x for x in demandes if x not in GROUPES]
    if inconnus or not demandes:
        print(f'✗ groupe(s) inconnu(s) : {inconnus or "aucun nommé"} — connus : {", ".join(GROUPES)}, tout',
              file=sys.stderr)
        sys.exit(1)
    rendre(set(demandes), f'à la demande : {", ".join(demandes)}')

if os.environ.get('CI_TOUT'):
    rendre(set(GROUPES), os.environ['CI_TOUT'])

touches, inconnus = set(), []
fichiers = [l for l in sys.stdin.read().split('\n') if l.strip()]
for f in fichiers:
    m = ranger(f)
    if m == 'TOUT':
        rendre(set(GROUPES), f'la CI elle-même change ({f})')
    if m is None:
        inconnus.append(f)
    elif m:
        touches.add(m)
if inconnus:
    rendre(set(GROUPES), f'chemin hors table ({inconnus[0]}{" …" if len(inconnus) > 1 else ""})')
choix = {g for g, dep in GROUPES.items() if dep & touches}
rendre(choix, f'{len(fichiers)} fichier(s), modules touchés : {", ".join(sorted(touches)) or "aucun"}')
PY
