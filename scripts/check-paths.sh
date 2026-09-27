#!/bin/bash
# Contrôle des chemins cités — un chemin écrit quelque part a-t-il encore sa cible ?
#
# 🔑 **Pourquoi ce script existe.** Une référence dont la cible a disparu ne casse
# pas : elle échoue de deux façons. Bruyamment — l'allowlist OPSEC qui cite un
# fichier renommé fait rougir l'audit, on corrige, cas confortable. Silencieusement
# — une règle `.gitignore` qui cite un chemin déplacé cesse d'ignorer, et le
# fichier qu'elle protégeait entre au prochain `git add -A`. Rien ne bloque, rien
# n'avertit.
#
# Le rangement du 17/08/2026 a produit les deux cas dans la même heure. Le second
# visait un document de mission pentest, une base de démonstration et deux
# artefacts cryptographiques, sur un dépôt public.
#
# Usage : bash scripts/check-paths.sh
# Sortie : 0 si tout résout, 1 sinon.

set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 1

echec=0

echo "▸ Chemins cités — liens Markdown"
python3 - <<'PY' || echec=1
import re, pathlib, subprocess, sys
morts = []
for f in subprocess.run(["git","ls-files","*.md"],capture_output=True,text=True).stdout.split():
    p = pathlib.Path(f)
    for m in re.finditer(r'\]\((\.{0,2}/[^)#]+|[A-Za-z0-9_][^):#]*\.(?:md|html|php|sh|json|ya?ml|docx|pdf))\)', p.read_text(errors="ignore")):
        t = m.group(1)
        if t.startswith(("http", "mailto")): continue
        if not (p.parent / t).exists(): morts.append(f"{f} → {t}")
if morts:
    print("  ✗ " + str(len(morts)) + " lien(s) mort(s)")
    for m in morts: print("     " + m)
    sys.exit(1)
print("  ✓ tous les liens résolvent")
PY

echo "▸ Chemins cités — ancres Markdown"
# 🔑 **Le contrôle au-dessus ne regardait AUCUN lien ancré.** Sa classe `[^)#]`
# exclut le `#` : `](#quickstart)` et `](guide.md#section)` sortaient de son
# périmètre depuis toujours. Quatre badges des README SelfRecover pointaient un
# fragment qu'aucun titre ne produit, et le script rendait vert. C'est la forme
# la plus discrète du faux vert : un contrôle qui passe parce qu'il ne regarde pas.
#
# ⚠️ L'ordre des opérations de `github-slugger` n'est pas intuitif et un seul
# écart fabrique des faux positifs : `trim()` s'applique AVANT la suppression de
# la ponctuation, jamais après. `## Facteur « cet appareil »` produit donc
# `facteur--cet-appareil-`, avec son tiret final — le retirer condamnait un lien
# parfaitement valide, et un garde-fou qui crie à tort finit désactivé.
python3 - <<'PY' || echec=1
import re, pathlib, subprocess, sys, collections

def slug(titre):
    t = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', titre)   # le TEXTE du lien, pas sa cible
    t = re.sub(r'[`*_~]', '', t).lower().strip()
    t = re.sub(r'[^\w\s-]', '', t, flags=re.UNICODE)
    return t.replace(' ', '-')

def fragments(p):
    vus, out = collections.Counter(), set()
    txt = sans_blocs(p.read_text(errors="ignore"))
    for ligne in txt.splitlines():
        m = re.match(r'\s{0,3}#{1,6}\s+(.*)', ligne)
        if not m: continue
        s = slug(m.group(1))
        if not s: continue
        # GitHub suffixe les doublons : -1, -2, …
        out.add(s if not vus[s] else f"{s}-{vus[s]}")
        vus[s] += 1
    for m in re.finditer(r'<a\s+(?:id|name)=["\']([^"\']+)["\']', txt):
        out.add(m.group(1).lower())
    return out

def sans_blocs(txt):
    # Un « # commentaire » dans un bloc shell n'est pas un titre Markdown.
    # Les sauts de ligne sont conservés pour ne pas décaler la lecture.
    return re.sub(r'```.*?```', lambda m: '\n' * m.group(0).count('\n'), txt, flags=re.S)

def prose(txt):
    # ⚠️ Un lien CITÉ n'est pas un lien. Le CHANGELOG de ce dépôt écrit
    # `](#section)` entre backticks pour EXPLIQUER ce contrôle — les lire comme
    # des liens le faisait rougir sur sa propre documentation.
    #
    # 🔑 Ce retrait du code inline ne vaut QUE pour chercher les liens. Des
    # titres du dépôt contiennent du code inline (`## 6. \`--keyfile-size\` est une parade…`) :
    # l'appliquer aussi à l'extraction des titres amputait leur slug et condamnait
    # des liens valides. `slug()` retire les backticks et garde leur contenu, ce
    # que fait GitHub.
    return re.sub(r'`[^`\n]*`', '', sans_blocs(txt))

morts, vivantes, cache = [], 0, {}
for f in subprocess.run(["git","ls-files","*.md"],capture_output=True,text=True).stdout.split():
    p = pathlib.Path(f)
    for m in re.finditer(r'\]\(([^)\s]+)\)', prose(p.read_text(errors="ignore"))):
        cible = m.group(1)
        if cible.startswith(("http", "mailto", "#!")) or "#" not in cible: continue
        chemin, _, frag = cible.partition("#")
        if not frag: continue
        dest = p if chemin == "" else (p.parent / chemin)
        if not dest.exists():
            morts.append(f"{f} → {cible}  (fichier absent)"); continue
        if dest.suffix != ".md": continue
        if dest not in cache: cache[dest] = fragments(dest)
        if frag.lower() in cache[dest]: vivantes += 1
        else: morts.append(f"{f} → {cible}")

# Le contre-témoin fait partie du verdict : sans lui, « 0 mort » ne distingue pas
# « tout résout » de « le motif n'a rien trouvé à regarder ».
if morts:
    print(f"  ✗ {len(morts)} ancre(s) morte(s) sur {len(morts) + vivantes} vérifiée(s)")
    for x in morts: print("     " + x)
    sys.exit(1)
print(f"  ✓ les {vivantes} ancres vérifiées résolvent")
PY

echo "▸ Chemins cités — code inline"
# 🔑 **Un chemin se cite aussi hors d'un lien.** « Copier `deploy/nginx-bi-self.conf` »
# guide l'installateur autant qu'un lien, et les deux contrôles au-dessus ne lisent
# que les `](…)` : l'audit du 27/09/2026 a trouvé cinq chemins morts qu'ils rendaient
# verts. Ne comptent que les chemins du dépôt — premier segment connu du dépôt ou du
# dossier du fichier —, et trois familles sont écartées parce qu'elles ne sont pas
# des défauts : l'histoire (un CHANGELOG cite ce qui existait), ce que git ignore
# (un fichier créé à l'exécution), et les exceptions nommées ci-dessous avec leur raison.
python3 - <<'PY' || echec=1
import re, pathlib, subprocess, sys
EXCEPTIONS = {
    "scripts/local-top/": "chemin de l'initramfs du système, pas du dépôt",
    ".github/pull_request_template.md": "lu par les agents s'il existe, absent le plus souvent",
}
suivis = subprocess.run(["git", "ls-files"], capture_output=True, text=True).stdout.split()
tete = {p.split("/")[0] for p in suivis}
def ignore(chemin):
    return subprocess.run(["git", "check-ignore", "-q", "--no-index", str(chemin)]).returncode == 0
morts, lus = [], 0
for f in subprocess.run(["git", "ls-files", "*.md"], capture_output=True, text=True).stdout.split():
    if re.search(r"(^|/)CHANGELOG\.md$", f): continue
    p = pathlib.Path(f)
    texte = re.sub(r"```.*?```", lambda m: "\n" * m.group(0).count("\n"), p.read_text(errors="ignore"), flags=re.S)
    for n, ligne in enumerate(texte.splitlines(), 1):
        for m in re.finditer(r"`([A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_.-]+)+/?)`", ligne):
            t = m.group(1)
            if any(t.startswith(e) for e in EXCEPTIONS): continue
            if t.split("/")[0] not in tete and not (p.parent / t.split("/")[0]).exists(): continue
            lus += 1
            candidats = [p.parent / t, pathlib.Path(t)]
            if any(c.exists() for c in candidats) or any(ignore(c) for c in candidats): continue
            morts.append(f"{f}:{n} → {t}")
if morts:
    print(f"  ✗ {len(morts)} chemin(s) mort(s) sur {lus} cité(s) en code inline")
    for x in morts: print("     " + x)
    sys.exit(1)
print(f"  ✓ les {lus} chemins du dépôt cités en code inline résolvent")
PY

echo "▸ Chemins cités — règles .gitignore"
# Une règle peut légitimement viser ce qui n'existe pas encore (/vendor/, /tmp/).
# Le signal n'est donc pas « la cible manque » mais « la cible manque ET un
# fichier du même nom existe ailleurs » — c'est-à-dire : elle a été déplacée.
python3 - <<'PY' || echec=1
import pathlib, subprocess, sys
suspects = []
tous = {p.as_posix() for p in pathlib.Path(".").rglob("*") if ".git/" not in p.as_posix()}
for gi in subprocess.run(["git","ls-files","*.gitignore",".gitignore"],capture_output=True,text=True).stdout.split():
    base = pathlib.Path(gi).parent
    for n, line in enumerate(pathlib.Path(gi).read_text().splitlines(), 1):
        s = line.strip()
        if not s or s.startswith(("#", "!")) or "/" not in s.rstrip("/"): continue
        # Outillage régénérable : un `vendor/` existe forcément ailleurs sans que
        # la règle soit orpheline pour autant.
        if s.strip("/") in {"vendor", "node_modules", ".venv", "tmp", "cache", ".phpunit.cache"}: continue
        cible = s.lstrip("/").split("*")[0].rstrip("/")
        if not cible: continue
        if (base / cible).exists(): continue
        feuille = cible.rsplit("/", 1)[-1]
        if feuille and any(t.endswith("/" + feuille) for t in tous):
            # 🔑 Le discriminant qui manquait, et sans lui ce contrôle rougit
            # sur son fonctionnement normal. Une cible absente est le cas
            # ORDINAIRE d'une règle efficace : le fichier vit sur la machine et
            # n'est pas versionné, donc il ne se trouve pas dans un clone frais.
            # La CI n'en voit jamais aucun. Le 20/08/2026, `scripts/deploy.sh`
            # et `demo/lab/data/` — deux règles qui protègent des fichiers bien
            # présents — ont fait échouer `main` pour cette seule raison.
            # Un déplacement, lui, laisse une trace : git a connu ce chemin.
            # Jamais suivi = garde-fou préventif, pas orphelin.
            connu = subprocess.run(
                ["git", "log", "--all", "--oneline", "-1", "--", (base / cible).as_posix()],
                capture_output=True, text=True).stdout.strip()
            if not connu:
                continue
            suspects.append(f"{gi}:{n}  {s}  (a existé dans l'historique, "
                            "et une cible de ce nom existe ailleurs)")
if suspects:
    print("  ✗ " + str(len(suspects)) + " règle(s) probablement orpheline(s)")
    for x in suspects: print("     " + x)
    sys.exit(1)
print("  ✓ aucune règle orpheline détectée")
PY

echo "▸ Chemins cités — workflows GitHub"
python3 - <<'EOF' || echec=1
import pathlib, re, sys
morts = []
for wf in sorted(pathlib.Path(".github/workflows").glob("*.yml")):
    for n, line in enumerate(wf.read_text().splitlines(), 1):
        m = re.match(r"\s*(context|working-directory|dockerfile|file):\s*(\S+)", line)
        if not m: continue
        chemin = m.group(2).strip("'\"")
        if chemin.startswith("$") or chemin == ".": continue
        if not pathlib.Path(chemin).exists():
            morts.append(f"{wf}:{n}  {m.group(1)}: {chemin}")
if morts:
    print("  \u2717 " + str(len(morts)) + " chemin(s) mort(s)")
    for x in morts: print("     " + x)
    sys.exit(1)
print("  \u2713 tous les chemins de workflow résolvent")
EOF

# ── Copies qui doivent rester identiques ────────────────────────────────────
#
# 🔑 Un texte de licence se copie — c'est un document légal verbatim, et l'AGPL
# demande qu'une copie accompagne chaque programme distribué. Un paquet Python
# publié doit donc porter le sien. Mais deux copies peuvent diverger, et
# personne ne relit 34 000 octets : la duplication n'est acceptable que
# surveillée.
#
# ⚠️ Un lien symbolique aurait évité la copie. Il casse la construction : la
# sdist le stocke tel quel, et l'extraction refuse un lien pointant hors de
# l'archive. Mesuré le 21/08/2026.
echo "▸ Copies verbatim — le texte de licence"
# Chaque paquet distribuable indépendamment porte sa copie ; la liste grandit
# avec eux.
COPIES_LICENCE=(
    self-right/selfjustice/mcp/LICENSE
)
for copie in "${COPIES_LICENCE[@]}"; do
    if [ ! -f "$copie" ]; then
        echo "  ✗ $copie manque — le paquet publié n'emporterait pas sa licence" >&2
        echec=1
    elif cmp -s LICENSE "$copie"; then
        echo "  ✓ $copie identique à LICENSE"
    else
        echo "  ✗ $copie a divergé de LICENSE" >&2
        echec=1
    fi
done

exit $echec
