#!/usr/bin/env python3
"""Garde-fou — la recherche d'un numéro recyclé lit d'abord l'article demandé.

`article_renvoi()` joint chaque ancienne version d'un article à toutes les
versions vivantes de son code, à texte égal. Dans un sens, la requête lit une
poignée de versions puis le code une fois par version ; dans l'autre, elle
reparcourt tout le code pour chaque version de chaque article du code. Le
06/11/2026, sur la base servie, qui n'avait aucune statistique d'index, SQLite
avait choisi le second : la route d'article rendait 502 après 30 s sur le code
du travail.

Ce banc construit une base vide avec le schéma et les index du script de
construction, sans statistiques, fait exécuter la vraie fonction de l'API en
enregistrant sa requête, et lit le plan que SQLite en tire.

    python3 tests/sanity_plan_renvoi.py
"""

import json
import pathlib
import sqlite3
import subprocess
import sys
import tempfile

RACINE = pathlib.Path(__file__).resolve().parent.parent
API = RACINE / "api" / "api.php"

# Ce que la fonction appelle, extrait de l'API plutôt que recopié.
FONCTIONS = ["date_iso_valide", "legi_jour", "legi_sql_applicable", "legi_sql_non_applicable",
             "article_texte_precedent", "article_renvoi"]
CONSTANTES = ["LEGI_ETATS_VIVANTS", "REFORME_MINIMUM"]

PHP = r'''<?php
$src = file_get_contents($argv[1]);
foreach (json_decode($argv[3], true) as $nom) {
    if (!preg_match("/^const " . $nom . " = .*?;$/ms", $src, $m)) { fwrite(STDERR, "constante absente : $nom\n"); exit(2); }
    eval($m[0]);
}
foreach (json_decode($argv[4], true) as $nom) {
    if (!preg_match("/^function " . $nom . "\(.*?^}$/ms", $src, $m)) { fwrite(STDERR, "fonction absente : $nom\n"); exit(2); }
    eval($m[0]);
}
class Enregistreur extends SQLite3 {
    public array $requetes = [];
    public function prepare(string $query): SQLite3Stmt|false {
        $this->requetes[] = $query;
        return parent::prepare($query);
    }
}
$db = new Enregistreur($argv[2], SQLITE3_OPEN_READONLY);
$sortie = [];
foreach ([1, 0] as $applicable) {
    $db->requetes = [];
    article_renvoi($db, ['num' => 'L1152-1', 'code_id' => 'LEGITEXT000006072050', 'texte' => 't',
                         'applicable' => $applicable, 'date_debut' => '2008-05-01', 'date_fin' => '2999-01-01']);
    $sortie[$applicable ? 'vivant' : 'mort'] = $db->requetes[0] ?? null;
}
echo json_encode($sortie);
'''

ECHECS = []


def verdict(ok: bool, libelle: str, detail: str = "") -> None:
    if not ok:
        ECHECS.append(libelle)
    print(f"  {'✓' if ok else '✗'} {libelle}" + (f" — {detail}" if detail and not ok else ""))


def main() -> int:
    sys.path.insert(0, str(RACINE / "tools"))
    import extract_selfjustice_db as construction

    with tempfile.TemporaryDirectory() as tmp:
        base = pathlib.Path(tmp) / "legi.sqlite"
        db = sqlite3.connect(base)
        db.executescript(construction.SCHEMA)
        for sql in construction.INDEXES:
            db.execute(sql)
        db.commit()
        db.close()

        harnais = pathlib.Path(tmp) / "harnais.php"
        harnais.write_text(PHP, encoding="utf-8")
        p = subprocess.run(["php", str(harnais), str(API), str(base),
                            json.dumps(CONSTANTES), json.dumps(FONCTIONS)],
                           capture_output=True, text=True, timeout=60)
        if p.returncode != 0:
            print(f"✗ le harnais PHP n'a pas tourné : {p.stderr.strip()}", file=sys.stderr)
            return 2
        requetes = json.loads(p.stdout)

        db = sqlite3.connect(base)
        print("▸ Sans statistiques d'index, comme la base servie avant le 06/11/2026")
        sql = requetes.get("vivant")
        verdict(bool(sql), "la requête de l'article vivant est enregistrée")
        if sql:
            plan = [ligne[3] for ligne in db.execute("EXPLAIN QUERY PLAN " + sql,
                                                      {"ref": "L1152-1", "code": "C", "texte": "t"})]
            lectures = [l for l in plan if l.startswith(("SEARCH", "SCAN"))]
            verdict(bool(lectures) and lectures[0].startswith("SEARCH anc "),
                    "l'article demandé est lu en premier, avant le reste du code",
                    " | ".join(plan))
            verdict(bool(lectures) and "idx_articles_num" in lectures[0],
                    "et il est lu par l'index de son numéro", " | ".join(plan))
        # Le cas « article mort » ne lit qu'une table : il ne se joint à rien.
        sql = requetes.get("mort")
        verdict(bool(sql) and " JOIN " not in sql.upper(), "la requête de l'article mort ne joint rien")
        db.close()

    print()
    if ECHECS:
        print(f"✗ {len(ECHECS)} contrôle(s) en échec")
        return 1
    print("✓ la recherche d'un numéro recyclé lit d'abord l'article demandé")
    return 0


if __name__ == "__main__":
    sys.exit(main())
