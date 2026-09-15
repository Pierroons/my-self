<?php

declare(strict_types=1);

/**
 * `StockagePdo` tient-il le contrat, et `schema.sql` le porte-t-il réellement ?
 *
 * 🔑 **Ce banc relit la BASE, pas la valeur rendue.** Une méthode d'écriture qui
 * ne fait rien et rend `void` est indiscernable d'une méthode qui écrit, tant
 * qu'on ne va pas voir la table.
 *
 * Ce qu'il établit, et rien de plus :
 *   — la classe satisfait `StorageInterface`, zéro méthode non implémentée ;
 *   — `schema.sql` porte chaque colonne que l'adaptateur nomme, et ses
 *     contraintes mordent — unicité, clés étrangères, cascade ;
 *   — les niveaux 1, 2 et 3 se jouent de bout en bout, par la bibliothèque ;
 *   — chaque méthode du contrat est observée par au moins un contrôle qui
 *     rougit quand on la casse.
 *
 * Ce qu'il n'établit PAS : rien sur des routes HTTP, rien sur un client
 * JavaScript, rien sur le temps constant.
 *
 * Usage : php bi-self/selfrecover/tests/banc_stockage_pdo.php
 */

require __DIR__ . '/../src/autoload.php';

use Pierroons\SelfRecover\Crypto\Hashing;
use Pierroons\SelfRecover\Recovery\Escalade;
use Pierroons\SelfRecover\Recovery\Recovery;
use Pierroons\SelfRecover\Storage\StorageInterface;
use Pierroons\SelfRecover\Storage\StockagePdo;

/**
 * Le journal des contrôles — la seule source du verdict.
 *
 * ⚠️ **Un compteur incrémenté ne peut pas juger.** Mesuré : remplacer
 * `$condition ? $passes++ : $echecs++` par `$passes++` faisait afficher les ❌
 * et sortir à 0. Le verdict se recalcule donc depuis ce journal, et l'étape de
 * CI relit la sortie — le caractère ❌, et le nombre de contrôles annoncé.
 *
 * 🔑 **Ce que cette redondance NE couvre pas, et il faut le dire** : le journal
 * et l'affichage sont deux lignes de la MÊME fonction. Réécrire `verifier()`
 * pour qu'elle journalise `ok` et affiche ✅ quoi qu'il arrive laisse le compte
 * intact, aucun ❌, et rend le même vert — mesuré. La garde externe vaut contre
 * tout ce qui est en AVAL de `verifier()` ; contre `verifier()` elle-même, il
 * n'y a que la relecture humaine du diff.
 *
 * @var list<array{section: string, ok: bool}>
 */
$journal = [];

/**
 * Ce que chaque section doit exécuter au minimum.
 *
 * 🔑 **Un plancher global ne protège pas.** Une section entière peut disparaître
 * sans faire tomber un total sous son seuil, et le banc rend alors le même vert
 * en ayant cessé d'éprouver ce qu'elle gardait. C'est par SECTION que le compte
 * veut dire quelque chose.
 */
const PLANCHER = [
    'contrat'       => 4,
    'schéma'        => 19,
    'niveaux 1-2'   => 10,
    'appareil'      => 11,
    'niveau 3'      => 21,
    'propriétés'    => 62,
    'atomicité'     => 18,
];

$section = '—';

// ⚠️ Un banc qui s'arrête en plein milieu sort à 0 : un `return` au niveau du
// script, une exception attrapée trop haut, un `exit` égaré. Mesuré — le banc
// tronqué rendait vert. Cette garde est levée par la dernière ligne du fichier.
$alleAuBout = false;
register_shutdown_function(static function (): void {
    global $alleAuBout;
    if (!$alleAuBout) {
        echo "\u{274C} Le banc s'est arrêté avant sa fin — aucun verdict ne peut en être tiré.\n";
        exit(1);
    }
});

function section(string $nom): void
{
    global $section;
    $section = $nom;
    echo "\n── {$nom} ──\n";
}

function verifier(string $intitule, bool $condition, string $detail = ''): void
{
    global $journal, $section;
    $journal[] = ['section' => $section, 'ok' => $condition];
    echo ($condition ? "  \u{2705} " : "  \u{274C} ") . $intitule . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * Exécute et rend ce qui s'est passé, sans laisser une exception tuer le banc.
 *
 * ⚠️ Sans cette enveloppe, une sonde qui attend un refus MEURT au lieu de
 * rougir, et le banc s'arrête en emportant les contrôles suivants.
 */
function abrite(callable $geste): array
{
    try {
        return ['ok' => true, 'valeur' => $geste(), 'message' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'valeur' => null, 'message' => $e->getMessage()];
    }
}

const HOTE = 'recover-web.example';

const CHEMIN_SCHEMA = __DIR__ . '/../schema.sql';

function baseNeuve(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = (string) file_get_contents(CHEMIN_SCHEMA);
    $pdo->exec($sql);

    return $pdo;
}

function creerCompte(
    PDO $pdo,
    string $nom,
    string $mot,
    string $phrase,
    int $quand,
    ?int $derniereConnexion = null,
    string $hote = HOTE,
): int
{
    $pdo->prepare(
        'INSERT INTO accounts (username, pw_hash, passphrase_hash, recovery_hash, recovery_salt,
                               derivation_host, pass_emise_le, created_at, last_login_at, login_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $nom,
        Hashing::hash('mot de passe initial'),
        Hashing::hash($phrase),
        Hashing::hash($mot),
        bin2hex(random_bytes(16)),
        $hote,
        $quand,
        $quand,
        $derniereConnexion,
        $derniereConnexion === null ? 0 : 12,
    ]);

    return (int) $pdo->lastInsertId();
}

$T0 = 1_700_000_000;

// ═══════════════════════════════════════════════════════════════════════════
section('contrat');

// 🔑 Le contrôle qui vaut tous les autres : PHP refuse de charger une classe qui
// déclare `implements` sans tenir la promesse. Si ce banc démarre, les 39
// signatures sont là. On le DIT quand même, parce qu'un lecteur ne devine pas
// qu'un `new` porte cette preuve.
$pdo = baseNeuve();
$r = abrite(static fn (): object => new StockagePdo($pdo, HOTE));
verifier('la classe s\'instancie — donc les 39 signatures sont tenues', $r['ok'], $r['message']);

$stockage = new StockagePdo($pdo, HOTE);
verifier('elle est bien un StorageInterface', $stockage instanceof StorageInterface);

$attendues = get_class_methods(StorageInterface::class);
$manquantes = array_filter($attendues, static fn (string $m): bool => !method_exists($stockage, $m));
verifier('aucune méthode du contrat ne manque', $manquantes === [], (string) count($attendues) . ' méthodes');

// ⚠️ Compter ne suffit pas : une méthode qui rend toujours `null` compte pareil.
// Ce décompte sert à faire ROUGIR le banc si le contrat gagne une méthode que
// l'adaptateur n'a pas suivie — c'est le seul cas où le compte dit quelque chose.
verifier('le contrat en porte 39, comme annoncé partout ailleurs', count($attendues) === 39,
    (string) count($attendues));

// ═══════════════════════════════════════════════════════════════════════════
section('schéma');

// ⚠️ Une colonne absente ne se voit qu'à l'exécution de la requête qui la cite,
// donc une méthode jamais appelée par le banc cacherait son erreur. On compare
// ici le schéma à la liste, indépendamment des appels.
$colonnes = [];
foreach (['accounts', 'sessions', 'login_attempts', 'recovery_codes',
          'device_credentials', 'device_challenges', 'disputes', 'dispute_messages', 'l3_gel'] as $table) {
    $st = $pdo->query("PRAGMA table_info({$table})");
    $colonnes[$table] = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'name');
    verifier("la table {$table} existe", $colonnes[$table] !== []);
}

foreach (['username', 'pw_hash', 'passphrase_hash', 'recovery_hash', 'recovery_salt',
          'derivation_host', 'pass_emise_le', 'created_at', 'last_login_at', 'login_count'] as $col) {
    verifier("accounts.{$col}", in_array($col, $colonnes['accounts'], true));
}

// ═══════════════════════════════════════════════════════════════════════════
section('niveaux 1-2');

$MOT    = str_repeat('a1', 32);
$PHRASE = 'cheval agrafe batterie correct';
// ⚠️ Enrôlée sous un ANCIEN hôte, exprès : sans ça, le contrôle de
// réécriture plus bas passerait au vert en lisant la valeur initiale.
$compteId = creerCompte($pdo, 'alice', $MOT, $PHRASE, $T0, $T0, 'ancien.example');

$recovery = new Recovery($stockage, 'sel-de-deploiement-du-banc', delaiRefusUs: 0);

// Quatre ans avant $T0 : aucune réécriture ne peut rendre cette valeur.
$SEME_ANCIEN = $T0 - 4 * 365 * 86400;
$pdo->prepare('UPDATE accounts SET pass_emise_le = ? WHERE id = ?')->execute([$SEME_ANCIEN, $compteId]);

$r1 = $recovery->parPassphrase('alice', $PHRASE, null, $T0);
verifier('la passphrase du niveau 1 rend l\'accès', ($r1['ok'] ?? false) === true);

$ligne = $pdo->query("SELECT pw_hash, passphrase_hash, pass_emise_le FROM accounts WHERE id = {$compteId}")
             ->fetch(PDO::FETCH_ASSOC);
verifier('pw_hash porte le mot de passe rendu', Hashing::verify($r1['mot_de_passe'], $ligne['pw_hash']));
verifier('passphrase_hash porte la passphrase NEUVE', Hashing::verify($r1['passphrase'], $ligne['passphrase_hash']));
verifier('l\'ancienne passphrase ne ressert pas',
    ($recovery->parPassphrase('alice', $PHRASE, null, $T0)['ok'] ?? true) === false);

// ⚠️ La date d'émission se refait à chaque émission. Sans ça, un papier imprimé
// à l'instant s'affiche avec l'âge de celui qu'il remplace.
//
// 🔑 On compare à la valeur SEMÉE, pas à une borne tirée de $T0 : le compte
// portait déjà $T0, donc `> $T0 - 60` était vrai avant toute écriture. La sonde
// passait sans rien mesurer — mesuré au canari, elle restait verte quand on
// retirait `pass_emise_le` de l'UPDATE.
verifier('pass_emise_le a suivi la nouvelle passphrase',
    (int) $ligne['pass_emise_le'] !== $SEME_ANCIEN && (int) $ligne['pass_emise_le'] > time() - 60,
    'semé ' . gmdate('Y-m-d', $SEME_ANCIEN) . ', relu ' . gmdate('Y-m-d', (int) $ligne['pass_emise_le']));

$codes = $recovery->emettreCodes($compteId, 5, $T0);
verifier('cinq codes sont émis', count($codes) === 5, (string) count($codes));
verifier('la base en compte cinq inutilisés', $stockage->compterCodesRestants($compteId) === 5);

$r2 = $recovery->parCode($codes[0], $MOT, null, $T0);
verifier('code + mot mémorisé rendent l\'accès, sans identifiant', ($r2['ok'] ?? false) === true);
verifier('le code consommé ne ressert pas',
    ($recovery->parCode($codes[0], $MOT, null, $T0)['ok'] ?? true) === false);
verifier('il en reste quatre en base', $stockage->compterCodesRestants($compteId) === 4);

// ═══════════════════════════════════════════════════════════════════════════
section('appareil');

$credId = 'cred-' . bin2hex(random_bytes(8));
$stockage->enregistrerAppareil($compteId, $credId, 'cle-publique-b64url-factice', $T0);

$appareil = $stockage->trouverAppareil($credId);
verifier('l\'appareil enregistré se retrouve', $appareil !== null);
verifier('il porte son compte et son nom',
    $appareil !== null && $appareil->compteId === $compteId && $appareil->nomCompte === 'alice');
verifier('un credential_id inconnu ne rend rien', $stockage->trouverAppareil('cred-jamais-vu') === null);

$defi = 'defi-' . bin2hex(random_bytes(8));
$stockage->enregistrerDefi($defi, $credId, $T0);
verifier('le défi en cours est reconnu', $stockage->defiEnCours($defi, $credId, $T0 - 60) === true);

// 🔑 Trois propriétés distinctes, et chacune se casse séparément.
verifier('un défi ne vaut pas pour un AUTRE credential_id',
    $stockage->defiEnCours($defi, 'cred-autre', $T0 - 60) === false);
verifier('un défi trop vieux est refusé',
    $stockage->defiEnCours($defi, $credId, $T0 + 60) === false);

$stockage->consommerDefi($defi);
verifier('un défi consommé ne resert pas', $stockage->defiEnCours($defi, $credId, $T0 - 60) === false);

// ⚠️ Une purge sans borne d'âge effacerait aussi les défis EN COURS, et
// déconnecterait tout le monde à chaque passage du nettoyage.
$stockage->enregistrerDefi('defi-recent', $credId, $T0);
$stockage->purgerDefisExpires($T0 - 3600);
verifier('la purge épargne les défis récents',
    $stockage->defiEnCours('defi-recent', $credId, $T0 - 60) === true);

$stockage->enregistrerDefi('defi-vieux', $credId, $T0 - 7200);
$stockage->purgerDefisExpires($T0 - 3600);
$reste = (int) $pdo->query("SELECT COUNT(*) FROM device_challenges WHERE challenge = 'defi-vieux'")->fetchColumn();
verifier('la purge efface les défis périmés, en base', $reste === 0);

// ⚠️ Ré-enrôler le même appareil REMPLACE sa clé. Deux lignes pour un même
// credential_id laisseraient l'ancienne clé ouvrir indéfiniment.
$stockage->enregistrerAppareil($compteId, $credId, 'nouvelle-cle-publique', $T0 + 10);
$lignes = (int) $pdo->query("SELECT COUNT(*) FROM device_credentials WHERE credential_id = " .
                            $pdo->quote($credId))->fetchColumn();
verifier('le ré-enrôlement remplace, il n\'accumule pas', $lignes === 1, (string) $lignes . ' ligne(s)');
verifier('et c\'est la nouvelle clé qui répond',
    $stockage->trouverAppareil($credId)?->clePubliqueB64url === 'nouvelle-cle-publique');

// ═══════════════════════════════════════════════════════════════════════════
section('niveau 3');

$escalade = new Escalade($stockage, $recovery, delaiRefusUs: 0);

$sesame = bin2hex(random_bytes(16));
$ouvert = $escalade->ouvrir('alice', hash('sha256', $sesame), null, $T0);
verifier('le dossier s\'ouvre', ($ouvert['ok'] ?? false) === true, (string) $ouvert['message']);

$numero = (string) ($ouvert['numero'] ?? '');
verifier('il porte un numéro non énumérable', $numero !== '' && !ctype_digit($numero), $numero);

$enBase = $pdo->query('SELECT status, claim_hash, expires_at FROM disputes WHERE dispute_number = ' .
                      $pdo->quote($numero))->fetch(PDO::FETCH_ASSOC);
verifier('la base porte le dossier en « open »', ($enBase['status'] ?? '') === 'open');
verifier('elle porte l\'empreinte du sésame, pas le sésame',
    ($enBase['claim_hash'] ?? '') === hash('sha256', $sesame));

$soumis = $escalade->soumettre($numero, $sesame, ['q1' => 'une réponse'], $T0 + 10);
verifier('les réponses se déposent', ($soumis['ok'] ?? false) === true, (string) $soumis['message']);

$statut = (string) $pdo->query('SELECT status FROM disputes WHERE dispute_number = ' .
                               $pdo->quote($numero))->fetchColumn();
verifier('le dossier attend l\'arbitre, en base', $statut === 'awaiting_admin', $statut);

// 🔑 Le faisceau et ses faits locaux — la case que le contrat prévoit pour ce
// que seul le déploiement sait.
$liste = $stockage->listerLitiges(10);
verifier('la console voit le dossier', count($liste) === 1);
verifier('elle en lit le faisceau', is_array($liste[0]['faisceau'] ?? null));

$faits = $stockage->faitsDuCompte($compteId);
verifier('faitsDuCompte rend les faits locaux', is_array($faits['faits_locaux'] ?? null));
verifier('dont l\'hôte de dérivation, tel qu\'enrôlé',
    ($faits['faits_locaux']['hote_derivation'] ?? null) === 'ancien.example');
verifier('et la date d\'émission de la passphrase',
    ($faits['faits_locaux']['passphrase_emise_le'] ?? null) !== null);

// ⚠️ Jamais un secret, jamais une empreinte : le contrat l'exige, et ce qui
// entre là est montré à un humain.
$platFaits = json_encode($faits, JSON_UNESCAPED_UNICODE) ?: '';
verifier('aucune empreinte Argon2id ne fuit dans les faits', !str_contains($platFaits, '$argon2'));

$etat = $escalade->etat($numero, $sesame, $T0 + 20);
verifier('l\'état se lit avec le sésame', ($etat['ok'] ?? false) === true);
$platEtat = json_encode($etat, JSON_UNESCAPED_UNICODE) ?: '';
verifier('et il ne renvoie pas le sésame en clair', !str_contains($platEtat, $sesame));

$tranche = $escalade->trancher($numero, 'accepte', 'arbitre-du-banc', $T0 + 30);
verifier('l\'arbitre tranche', ($tranche['ok'] ?? false) === true, (string) $tranche['message']);

// ⚠️ On resème une date ancienne juste avant : `reposerSecrets` émet une
// passphrase NEUVE, donc il doit réécrire sa date d'émission comme
// `remplacerEmpreintes` le fait. Sans ce semis, le contrôle plus bas passerait
// en relisant la valeur que le niveau 1 venait d'écrire.
$SEME_AVANT_L3 = $T0 - 6 * 365 * 86400;
$pdo->prepare('UPDATE accounts SET pass_emise_le = ? WHERE id = ?')->execute([$SEME_AVANT_L3, $compteId]);

$nouveauMot = str_repeat('b2', 32);
$reEnrole = $escalade->reEnroler($numero, $sesame, 'mot-de-passe-neuf', $nouveauMot,
                                 bin2hex(random_bytes(16)), $T0 + 40);
verifier('le ré-enrôlement passe', ($reEnrole['ok'] ?? false) === true, (string) $reEnrole['message']);

$apres = $pdo->query("SELECT recovery_hash, derivation_host, pass_emise_le FROM accounts WHERE id = {$compteId}")
             ->fetch(PDO::FETCH_ASSOC);
verifier('le mot mémorisé neuf est en base', Hashing::verify($nouveauMot, $apres['recovery_hash']));
verifier('l\'ancien ne vaut plus rien', !Hashing::verify($MOT, $apres['recovery_hash']));
// 🔑 Le compte a été enrôlé sous « ancien.example ». Ce contrôle ne peut donc
// passer que si reposerSecrets() a réellement réécrit la colonne.
// Le titulaire sort d'une perte totale : la passphrase qu'on lui imprime est
// neuve, et lui afficher l'âge de l'ancienne serait un mensonge au pire moment.
verifier('la date d\'émission est refaite au ré-enrôlement aussi',
    (int) $apres['pass_emise_le'] !== $SEME_AVANT_L3 && (int) $apres['pass_emise_le'] > time() - 60,
    'semé ' . gmdate('Y-m-d', $SEME_AVANT_L3) . ', relu ' . gmdate('Y-m-d', (int) $apres['pass_emise_le']));
verifier('l\'hôte de dérivation est REPOSÉ, pas laissé tel quel',
    $apres['derivation_host'] === HOTE, (string) $apres['derivation_host']);

$sesameApres = $escalade->etat($numero, $sesame, $T0 + 50);
verifier('le sésame ne rouvre rien après clôture', ($sesameApres['ok'] ?? true) === false);

// ═══════════════════════════════════════════════════════════════════════════
section('propriétés');

// 1. Le refus fail-closed de reposerSecrets.
$pdoB = baseNeuve();
$idB = creerCompte($pdoB, 'bob', $MOT, $PHRASE, $T0, $T0);
$sansHote = new StockagePdo($pdoB);   // construit SANS hôte, exprès
$r = abrite(static fn () => $sansHote->reposerSecrets($idB, 'a', 'b', 'c', 'd'));
verifier('reposerSecrets REFUSE sans hôte de dérivation', $r['ok'] === false);
verifier('et son message dit pourquoi, pas juste « erreur »',
    str_contains($r['message'], 'hôte de dérivation'), $r['message']);
$hoteB = (string) $pdoB->query("SELECT derivation_host FROM accounts WHERE id = {$idB}")->fetchColumn();
verifier('rien n\'a été écrit malgré le refus', $hoteB === HOTE);

// 2. La purge épargne ce qui arme le gel.
$pdoC = baseNeuve();
$idC = creerCompte($pdoC, 'carol', $MOT, $PHRASE, $T0, $T0);
$stC = new StockagePdo($pdoC, HOTE);
$insere = $pdoC->prepare(
    'INSERT INTO disputes (dispute_number, account_id, status, expires_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?)'
);
foreach (['open', 'refused', 'accepted', 'closed'] as $i => $st) {
    $insere->execute(["LIT-{$i}", $idC, $st, $T0 - 10, $T0 - 100, $T0 - 100]);
}
$efface = $stC->purgerLitigesExpires($T0);
$restants = $pdoC->query('SELECT status FROM disputes ORDER BY status')->fetchAll(PDO::FETCH_COLUMN);
verifier('la purge emporte les dossiers périmés ordinaires', $efface === 2, "{$efface} effacé(s)");
verifier('elle épargne « refused » — sans quoi le gel devient inatteignable',
    in_array('refused', $restants, true));
verifier('elle épargne « accepted » — sans quoi la porte se ferme sur qui a tout perdu',
    in_array('accepted', $restants, true));

// 3. Un gel échu n'est pas un gel.
$stC->poserGel($idC, $T0 + 100, $T0);
verifier('un gel en cours se lit', $stC->gelJusqua($idC, $T0) === $T0 + 100);
verifier('un gel ÉCHU rend zéro, pas sa date', $stC->gelJusqua($idC, $T0 + 200) === 0);
$stC->leverGel($idC, 'arbitre', $T0 + 10);
$trace = $pdoC->query("SELECT degele_par FROM l3_gel WHERE account_id = {$idC}")->fetchColumn();
verifier('le dégel garde sa trace au lieu d\'effacer la ligne', $trace === 'arbitre');

// 4. Un compte jamais connecté ne se fait pas passer pour un compte inactif.
$pdoD = baseNeuve();
$idD = creerCompte($pdoD, 'dave', $MOT, $PHRASE, $T0, null);   // jamais connecté
$faitsD = (new StockagePdo($pdoD, HOTE))->faitsDuCompte($idD);
verifier('« jamais connecté » rend null, pas zéro', $faitsD['derniere_connexion'] === null);
verifier('et le nombre de connexions aussi', $faitsD['nombre_connexions'] === null);
verifier('faitsDuCompte rend null sur un compte inexistant',
    (new StockagePdo($pdoD, HOTE))->faitsDuCompte(999_999) === null);

// 5. Les freins comptent ce qu'ils disent compter.
$stD = new StockagePdo($pdoD, HOTE);
$stD->tracerTentative('dave', false, '203.0.113.9', $T0);
$stD->tracerTentative('dave', false, '203.0.113.9', $T0 + 1);
$stD->tracerTentative('dave', true, '203.0.113.9', $T0 + 2);
verifier('les échecs par compte se comptent, les succès non',
    $stD->compterEchecsCompte('dave', $T0 - 1) === 2);
verifier('les échecs par IP aussi', $stD->compterEchecsIp('203.0.113.9', $T0 - 1) === 2);
verifier('la fenêtre borne bien le comptage', $stD->compterEchecsCompte('dave', $T0 + 10) === 0);
$stD->tracerTentative('erin', false, null, $T0);
verifier('une tentative sans IP s\'enregistre — service caché, proxy mutualisé',
    $stD->compterEchecsCompte('erin', $T0 - 1) === 1);

// 6. La révocation des sessions.
$pdoD->prepare('INSERT INTO sessions (account_id, token, created_at) VALUES (?, ?, ?)')
     ->execute([$idD, 'jeton-du-banc', $T0]);
$stD->revoquerSessions($idD);
verifier('reposer des secrets ferme ce qui était ouvert',
    (int) $pdoD->query("SELECT COUNT(*) FROM sessions WHERE account_id = {$idD}")->fetchColumn() === 0);

// 7. Le fil de messages survit à la clôture.
$pdoE = baseNeuve();
$idE = creerCompte($pdoE, 'frank', $MOT, $PHRASE, $T0, $T0);
$stE = new StockagePdo($pdoE, HOTE);
$stE->ouvrirLitige($idE, 'LIT-FIL', hash('sha256', 'sesame'), $T0, $T0 + 86400);
$litige = $stE->trouverLitigeParNumero('LIT-FIL');
$stE->ajouterMessageLitige($litige->id, 'user', 'bonjour', $T0);
$stE->ajouterMessageLitige($litige->id, 'admin', 'bonjour à vous', $T0 + 1);
$stE->cloreLitige($litige->id, $T0 + 2);
verifier('le fil reste lisible après clôture', count($stE->messagesDuLitige($litige->id)) === 2);
$clos = $stE->trouverLitigeParNumero('LIT-FIL');
verifier('mais l\'empreinte du sésame est vidée', $clos->empreinteSesame === '');

// 8. Les demandeurs concurrents.
$stE->compterDemandeurConcurrent($litige->id);
$stE->compterDemandeurConcurrent($litige->id);
verifier('les demandeurs concurrents s\'accumulent',
    $stE->trouverLitigeParNumero('LIT-FIL')->demandeursConcurrents === 2);

// 9. L'atomicité, y compris imbriquée.
$stE->commencerTransaction();
$r = abrite(static fn () => $stE->commencerTransaction());
verifier('une transaction imbriquée ne fait pas tomber SQLite', $r['ok'] === true, $r['message']);
$stE->annulerTransaction();
$r = abrite(static fn () => $stE->annulerTransaction());
verifier('annuler deux fois ne jette pas non plus', $r['ok'] === true, $r['message']);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n";
// 10. L'atomicité VALIDE vraiment — une méthode qui ne committe rien rend `void`
//     comme une qui committe. Seule une seconde connexion tranche, donc base
//     sur disque : en mémoire, la base meurt avec la connexion.
$fichier = tempnam(sys_get_temp_dir(), 'banc-sr-');
$pdoF = new PDO('sqlite:' . $fichier, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoF->exec((string) file_get_contents(CHEMIN_SCHEMA));
$idF = creerCompte($pdoF, 'grace', $MOT, $PHRASE, $T0, $T0);
$stF = new StockagePdo($pdoF, HOTE);
$stF->commencerTransaction();
$stF->remplacerEmpreinteMotDePasse($idF, 'DURABLE');
$stF->validerTransaction();
unset($stF, $pdoF);
$relu = new PDO('sqlite:' . $fichier, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// ⚠️ La base de ce cas vit sur DISQUE — une base en mémoire meurt avec sa
// connexion, et c'est justement la seconde connexion qui prouve la durabilité.
// Elle est donc effacée à la sortie, y compris si un contrôle jette.
register_shutdown_function(static function () use ($fichier): void {
    foreach ([$fichier, $fichier . '-wal', $fichier . '-shm'] as $compagnon) {
        if (is_file($compagnon)) {
            unlink($compagnon);
        }
    }
});
verifier('ce qui est validé survit à la connexion',
    $relu->query("SELECT pw_hash FROM accounts WHERE id = {$idF}")->fetchColumn() === 'DURABLE');
// ⚠️ Et que les cascades soient actives sur une connexion QUI N'A PAS chargé le
// schéma : c'est le montage réel, et le PRAGMA est propre à la connexion.
verifier('sans adaptateur, la connexion neuve ignore les clés étrangères',
    (int) $relu->query('PRAGMA foreign_keys')->fetchColumn() === 0);
$stRelu = new StockagePdo($relu, HOTE);   // c'est SON constructeur qui les arme
verifier('construire l\'adaptateur les active',
    (int) $relu->query('PRAGMA foreign_keys')->fetchColumn() === 1);
$avantCascade = (int) $relu->query('SELECT COUNT(*) FROM recovery_codes')->fetchColumn();
$relu->exec("INSERT INTO recovery_codes (account_id, code_lookup, code_hash, created_at)
             VALUES ({$idF}, 'l', 'h', {$T0})");
$relu->exec("DELETE FROM accounts WHERE id = {$idF}");
verifier('effacer un compte emporte ses codes — la cascade mord',
    (int) $relu->query('SELECT COUNT(*) FROM recovery_codes')->fetchColumn() === $avantCascade);

// 🔑 Les SIX cascades, pas une. Le constructeur promet nommément que l'effacement
// emporte « ses codes, ses clés d'appareil, et le texte qu'il a écrit à un
// arbitre » : chacune de ces trois-là se casse séparément, et quatre d'entre
// elles n'étaient observées par rien.
$stCasc = new StockagePdo($relu, HOTE);
$idCasc = creerCompte($relu, 'louise', $MOT, $PHRASE, $T0, $T0);
$relu->prepare('INSERT INTO sessions (account_id, token, created_at) VALUES (?, ?, ?)')
     ->execute([$idCasc, 'jeton-cascade', $T0]);
$stCasc->enregistrerCode($idCasc, 'idx-casc', Hashing::hash('c'), $T0);
$stCasc->enregistrerAppareil($idCasc, 'cred-casc', 'cle', $T0);
$stCasc->ouvrirLitige($idCasc, 'LIT-CASC', hash('sha256', 's'), $T0, $T0 + 86400);
$litCasc = $stCasc->trouverLitigeParNumero('LIT-CASC');
$stCasc->ajouterMessageLitige($litCasc->id, 'user', 'un texte pour l\'arbitre', $T0);
$stCasc->poserGel($idCasc, $T0 + 100, $T0);

$relu->exec("DELETE FROM accounts WHERE id = {$idCasc}");
foreach ([
    'sessions'           => "SELECT COUNT(*) FROM sessions WHERE account_id = {$idCasc}",
    'recovery_codes'     => "SELECT COUNT(*) FROM recovery_codes WHERE account_id = {$idCasc}",
    'device_credentials' => "SELECT COUNT(*) FROM device_credentials WHERE account_id = {$idCasc}",
    'disputes'           => "SELECT COUNT(*) FROM disputes WHERE account_id = {$idCasc}",
    'dispute_messages'   => "SELECT COUNT(*) FROM dispute_messages WHERE dispute_id = {$litCasc->id}",
    'l3_gel'             => "SELECT COUNT(*) FROM l3_gel WHERE account_id = {$idCasc}",
] as $table => $requete) {
    verifier("l'effacement n'a laissé aucun orphelin dans {$table}",
        (int) $relu->query($requete)->fetchColumn() === 0);
}
unset($relu);

// 11. Les contraintes du schéma, qu'aucun contrôle n'observait.
$pdoG = baseNeuve();
$stG = new StockagePdo($pdoG, HOTE);
$idG = creerCompte($pdoG, 'heidi', $MOT, $PHRASE, $T0, $T0);
$r = abrite(static fn () => creerCompte($pdoG, 'heidi', $MOT, $PHRASE, $T0, $T0));
verifier('deux comptes ne peuvent pas porter le même nom', $r['ok'] === false);
$stG->ouvrirLitige($idG, 'LIT-UNIQUE', hash('sha256', 's'), $T0, $T0 + 86400);
$r = abrite(static fn () => $stG->ouvrirLitige($idG, 'LIT-UNIQUE', hash('sha256', 's2'), $T0, $T0 + 86400));
verifier('deux dossiers ne peuvent pas porter le même numéro', $r['ok'] === false);
$r = abrite(static fn () => $pdoG->exec(
    "INSERT INTO sessions (account_id, token, created_at) VALUES (999999, 'orphelin', {$T0})"));
verifier('une session orpheline est refusée', $r['ok'] === false);
// ⚠️ Deux comptes ne partagent pas un jeton de session : sans cette unicité, le
// second qui l'obtient hérite de la session du premier.
$idG2 = creerCompte($pdoG, 'mallory', $MOT, $PHRASE, $T0, $T0);
$pdoG->prepare('INSERT INTO sessions (account_id, token, created_at) VALUES (?, ?, ?)')
     ->execute([$idG, 'jeton-partage', $T0]);
$r = abrite(static fn () => $pdoG->prepare(
    'INSERT INTO sessions (account_id, token, created_at) VALUES (?, ?, ?)'
)->execute([$idG2, 'jeton-partage', $T0]));
verifier('deux comptes ne peuvent pas partager un jeton de session', $r['ok'] === false);

// 12. Les lectures de compte ne confondent pas les trois empreintes.
$compte = $stG->trouverCompte('heidi');
verifier('trouverCompte rend l\'empreinte du MOT MÉMORISÉ',
    $compte !== null && Hashing::verify($MOT, $compte['empreinte_mot']));
verifier('et pas celle de la passphrase',
    $compte !== null && !Hashing::verify($PHRASE, $compte['empreinte_mot']));
$pass = $stG->trouverComptePourPassphrase('heidi');
verifier('trouverComptePourPassphrase rend celle de la PASSPHRASE',
    $pass !== null && Hashing::verify($PHRASE, $pass['empreinte_passphrase']));
verifier('et sa date d\'émission, pas null', ($pass['emise_le'] ?? null) === $T0);

// 13. Les écritures de compte touchent la bonne colonne, et elle seule.
$stG->remplacerEmpreinteMotDePasse($idG, 'PW-SEUL');
$apresPw = $pdoG->query("SELECT pw_hash, passphrase_hash, recovery_hash FROM accounts WHERE id = {$idG}")
                ->fetch(PDO::FETCH_ASSOC);
verifier('remplacerEmpreinteMotDePasse écrit pw_hash', $apresPw['pw_hash'] === 'PW-SEUL');
verifier('et ne touche ni la passphrase ni le mot mémorisé',
    Hashing::verify($PHRASE, $apresPw['passphrase_hash']) && Hashing::verify($MOT, $apresPw['recovery_hash']));

// 14. Les codes : purge, et horodatage de la consommation.
$stG->enregistrerCode($idG, 'idx-1', Hashing::hash('code-1'), $T0);
$stG->enregistrerCode($idG, 'idx-2', Hashing::hash('code-2'), $T0);
verifier('deux codes sont en base', $stG->compterCodesRestants($idG) === 2);
$trouve = $stG->trouverCodeParIndex('idx-1');
$stG->consommerCode((int) $trouve['code_id'], $T0 + 5);
verifier('la consommation est HORODATÉE, pas seulement marquée',
    (int) $pdoG->query("SELECT used_at FROM recovery_codes WHERE id = {$trouve['code_id']}")->fetchColumn() === $T0 + 5);
$stG->purgerCodes($idG);
verifier('purgerCodes efface le lot entier',
    (int) $pdoG->query("SELECT COUNT(*) FROM recovery_codes WHERE account_id = {$idG}")->fetchColumn() === 0);

// 15. La révocation ne déborde pas sur les autres comptes.
$idH = creerCompte($pdoG, 'ivan', $MOT, $PHRASE, $T0, $T0);
foreach ([[$idG, 'jeton-heidi'], [$idH, 'jeton-ivan']] as [$qui, $jeton]) {
    $pdoG->prepare('INSERT INTO sessions (account_id, token, created_at) VALUES (?, ?, ?)')
         ->execute([$qui, $jeton, $T0]);
}
$stG->revoquerSessions($idG);
verifier('révoquer ferme les sessions du compte visé',
    (int) $pdoG->query("SELECT COUNT(*) FROM sessions WHERE account_id = {$idG}")->fetchColumn() === 0);
verifier('et LAISSE celles des autres',
    (int) $pdoG->query("SELECT COUNT(*) FROM sessions WHERE account_id = {$idH}")->fetchColumn() === 1);

// 16. Le dossier : traçabilité de l'arbitrage, et concurrence détectée.
$litG = $stG->trouverLitigeParNumero('LIT-UNIQUE');
verifier('litigeActifDuCompte VOIT un dossier en cours',
    $stG->litigeActifDuCompte($idG, $T0 + 10)?->numero === 'LIT-UNIQUE');
$stG->trancherLitige($litG->id, 'accepted', 'arbitre-nommé', $T0 + 20);
$tr = $pdoG->query("SELECT decided_by, decided_at FROM disputes WHERE id = {$litG->id}")->fetch(PDO::FETCH_ASSOC);
verifier('trancher enregistre QUI a tranché', $tr['decided_by'] === 'arbitre-nommé');
verifier('et quand', (int) $tr['decided_at'] === $T0 + 20);
// ⚠️ Un dossier accepté échappe au TTL : sans ça, l'accord de l'arbitre meurt
// à l'expiration du dossier et la porte se referme sur qui a déjà tout perdu.
verifier('un dossier ACCEPTÉ reste actif bien après son expiration',
    $stG->litigeActifDuCompte($idG, $T0 + 999_999)?->numero === 'LIT-UNIQUE');
// ⚠️ L'autre borne de la même requête : un dossier OUVERT, lui, expire. Sans ce
// contrôle, retirer la clause de TTL laissait le banc vert et rouvrait la porte
// que l'expiration ferme.
$stG->ouvrirLitige($idG, 'LIT-EXPIRE', hash('sha256', 'x'), $T0, $T0 + 10);
$stG->trancherLitige($stG->trouverLitigeParNumero('LIT-UNIQUE')->id, 'closed', 'a', $T0 + 21);
verifier('un dossier OUVERT cesse d\'être actif passé son expiration',
    $stG->litigeActifDuCompte($idG, $T0 + 999_999) === null);
$stG->trancherLitige($litG->id, 'refused', 'arbitre-nommé', $T0 + 30);
verifier('les refus récents se comptent — c\'est eux qui arment le gel',
    $stG->compterRefusRecents($idG, $T0) === 1);
// ⚠️ Et la FENÊTRE compte autant que le total : sans elle, un refus vieux de
// deux ans armerait encore le gel.
verifier('un refus hors fenêtre n\'est plus compté',
    $stG->compterRefusRecents($idG, $T0 + 999_999) === 0);

// 17. Le gel réarmé efface la trace du dégel précédent.
$stG->poserGel($idG, $T0 + 100, $T0);
$stG->leverGel($idG, 'arbitre-1', $T0 + 10);
$stG->poserGel($idG, $T0 + 500, $T0 + 20);
verifier('un gel REPOSÉ n\'affiche plus le dégeleur d\'avant',
    $pdoG->query("SELECT degele_par FROM l3_gel WHERE account_id = {$idG}")->fetchColumn() === null);

// 18. Le fil : ordre et auteurs, que `count()` ne regardait pas.
$stG->ajouterMessageLitige($litG->id, 'user', 'le premier', $T0);
$stG->ajouterMessageLitige($litG->id, 'admin', 'le second', $T0 + 1);
$fil = $stG->messagesDuLitige($litG->id);
verifier('le fil est rendu dans l\'ordre d\'écriture',
    ($fil[0]['texte'] ?? '') === 'le premier' && ($fil[1]['texte'] ?? '') === 'le second');
verifier('et chaque message porte son auteur',
    ($fil[0]['auteur'] ?? '') === 'user' && ($fil[1]['auteur'] ?? '') === 'admin');

// 19. La console d'arbitrage : ce qu'elle montre, et ce qu'elle ne doit pas montrer.
$pdoI = baseNeuve();
$stI = new StockagePdo($pdoI, HOTE);
$idI = creerCompte($pdoI, 'judy', $MOT, $PHRASE, $T0, $T0);
$sesameI = bin2hex(random_bytes(16));
foreach (['LIT-A', 'LIT-B', 'LIT-C'] as $n) {
    $stI->ouvrirLitige($idI, $n, hash('sha256', $sesameI), $T0, $T0 + 86400);
}
verifier('listerLitiges respecte la limite demandée', count($stI->listerLitiges(2)) === 2);
$platListe = json_encode($stI->listerLitiges(10), JSON_UNESCAPED_UNICODE) ?: '';
// ⚠️ L'empreinte du sésame est une capability : elle ouvre le fil. La montrer à
// l'arbitre lui donnerait un pouvoir que le protocole réserve au demandeur.
verifier('la console ne publie pas l\'empreinte du sésame',
    !str_contains($platListe, hash('sha256', $sesameI)));
verifier('ni aucune empreinte Argon2id', !str_contains($platListe, '$argon2'));
$stI->poserGel($idI, $T0 + 10, $T0);
$avecGelEchu = $stI->listerLitiges(10);
verifier('un gel ÉCHU n\'est pas affiché comme actif par la console',
    ($avecGelEchu[0]['gele_jusqu_a'] ?? null) === null);

// 20. Les faits montrés à l'arbitre ne portent aucun secret.
$platFaitsI = json_encode($stI->faitsDuCompte($idI), JSON_UNESCAPED_UNICODE) ?: '';
$selI = (string) $pdoI->query("SELECT recovery_salt FROM accounts WHERE id = {$idI}")->fetchColumn();
verifier('le sel de dérivation ne part pas dans le faisceau',
    $selI !== '' && !str_contains($platFaitsI, $selI));

section('atomicité');

// 🔑 Vider les trois méthodes de transaction laissait ce banc à 118/118 : en
// autocommit, chaque écriture part quand même, et une relecture ne voit pas la
// différence. Ce qui distingue les deux, c'est ce qui arrive quand on ANNULE, et
// à qui appartient la transaction.

$fichierA = tempnam(sys_get_temp_dir(), 'banc-atom-');
register_shutdown_function(static function () use ($fichierA): void {
    foreach ([$fichierA, $fichierA . '-wal', $fichierA . '-shm'] as $c) {
        if (is_file($c)) {
            unlink($c);
        }
    }
});

$pdoA = new PDO('sqlite:' . $fichierA, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoA->exec((string) file_get_contents(CHEMIN_SCHEMA));
$idA = creerCompte($pdoA, 'karl', $MOT, $PHRASE, $T0, $T0);
$stA = new StockagePdo($pdoA, HOTE);
$pwDe = static fn (PDO $p, int $id): string
    => (string) $p->query("SELECT pw_hash FROM accounts WHERE id = {$id}")->fetchColumn();

// — Annuler annule réellement.
$avantAnnul = $pwDe($pdoA, $idA);
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'ANNULEE');
$stA->annulerTransaction();
verifier('annuler défait l\'écriture, en base', $pwDe($pdoA, $idA) === $avantAnnul);

// — Et valider valide.
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'VALIDEE');
$stA->validerTransaction();
verifier('valider la rend durable', $pwDe($pdoA, $idA) === 'VALIDEE');
verifier('et ne laisse aucune transaction ouverte', !$pdoA->inTransaction());

// — La transaction de l'APPELANT lui reste : c'est tout le design.
$pdoA->beginTransaction();
$pdoA->exec("UPDATE accounts SET username = 'par-lappelant' WHERE id = {$idA}");
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'PAR-NOUS');
$stA->validerTransaction();
verifier('notre validation ne ferme pas la transaction de l\'appelant', $pdoA->inTransaction());
$pdoA->rollBack();
$ligneA = $pdoA->query("SELECT username, pw_hash FROM accounts WHERE id = {$idA}")->fetch(PDO::FETCH_ASSOC);
verifier('son rollback défait TOUT, y compris ce que nous avions « validé »',
    $ligneA['username'] === 'karl' && $ligneA['pw_hash'] === 'VALIDEE');

// — Nous annulons, la transaction est la sienne : la nôtre part, la sienne reste.
$pdoA->beginTransaction();
$pdoA->exec("UPDATE accounts SET username = 'sien' WHERE id = {$idA}");
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'RATEE');
$stA->annulerTransaction();
$ligneA = $pdoA->query("SELECT username, pw_hash FROM accounts WHERE id = {$idA}")->fetch(PDO::FETCH_ASSOC);
verifier('notre écriture est défaite', $ligneA['pw_hash'] === 'VALIDEE');
verifier('la sienne survit', $ligneA['username'] === 'sien');
$r = abrite(static fn () => $pdoA->commit());
verifier('et son commit aboutit sans erreur', $r['ok'] === true, $r['message']);

// — Imbrication : l'interne s'annule sans emporter l'externe.
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'EXTERNE');
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'INTERNE');
$stA->annulerTransaction();
$stA->validerTransaction();
verifier('une imbriquée annulée n\'emporte pas l\'externe', $pwDe($pdoA, $idA) === 'EXTERNE');

// — 🔑 Deux adaptateurs qui ENTRELACENT leurs transactions sur une même
//   connexion : SQL ne sait pas le faire, et `RELEASE` libère en cascade tout ce
//   qui a été posé après le point nommé. On ne peut pas le rattraper — mais
//   l'échec doit DIRE pourquoi, au lieu d'un « no such savepoint » nu qui
//   enverrait chercher un défaut dans le stockage.
$stA2 = new StockagePdo($pdoA, HOTE);
$pdoA->beginTransaction();
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'DE-A');
$stA2->commencerTransaction();
$stA2->enregistrerCode($idA, 'idx-b', Hashing::hash('code-b'), $T0);
$stA->validerTransaction();                       // emporte le point de $stA2
$r = abrite(static fn () => $stA2->annulerTransaction());
verifier('l\'entrelacement de deux adaptateurs échoue au lieu de perdre une écriture',
    $r['ok'] === false);
verifier('et le message nomme la cause et la règle',
    str_contains($r['message'], 'entrelacés') || str_contains($r['message'], 'seul adaptateur'),
    $r['message']);
$pdoA->rollBack();

// ⚠️ Après un échec, l'instance doit rester UTILISABLE : sans le `finally` qui
// décompte, la profondeur reste figée et `commit()` n'est plus jamais appelé.
$stA2->commencerTransaction();
$stA2->remplacerEmpreinteMotDePasse($idA, 'APRES-ECHEC');
$stA2->validerTransaction();
verifier('une instance qui a jeté reste utilisable ensuite',
    $pwDe($pdoA, $idA) === 'APRES-ECHEC');

// — Une transaction disparue ne passe pas pour un succès.
$stA->commencerTransaction();
$stA->remplacerEmpreinteMotDePasse($idA, 'PERDUE');
$pdoA->rollBack();                       // quelqu'un d'autre l'annule dans notre dos
$r = abrite(static fn () => $stA->validerTransaction());
verifier('valider une transaction disparue LÈVE au lieu de se taire', $r['ok'] === false);
verifier('et le message dit ce qui s\'est passé',
    str_contains($r['message'], 'disparu'), $r['message']);

// — Le PRAGMA ne peut pas se poser dans une transaction : l'adaptateur refuse
//   d'être construit plutôt que de servir des cascades décoratives.
//
//   ⚠️ Sur une connexion VIERGE, exprès : le PRAGMA persiste pour la connexion,
//   donc en réutiliser une où un adaptateur l'a déjà posé ferait passer ce
//   contrôle sans rien mesurer — la relecture rendrait 1 de toute façon.
$fichierB = tempnam(sys_get_temp_dir(), 'banc-prag-');
register_shutdown_function(static function () use ($fichierB): void {
    foreach ([$fichierB, $fichierB . '-wal', $fichierB . '-shm'] as $c) {
        if (is_file($c)) {
            unlink($c);
        }
    }
});
// Le montage que documente le README : le schéma est chargé par une connexion
// (qui exécute son `PRAGMA`), puis jetée ; l'application en ouvre une autre.
$chargeur = new PDO('sqlite:' . $fichierB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$chargeur->exec((string) file_get_contents(CHEMIN_SCHEMA));
unset($chargeur);

$pdoB = new PDO('sqlite:' . $fichierB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
verifier('la connexion de l\'application n\'a pas ses clés étrangères actives',
    (int) $pdoB->query('PRAGMA foreign_keys')->fetchColumn() === 0);
$pdoB->beginTransaction();
$r = abrite(static fn (): object => new StockagePdo($pdoB, HOTE));
verifier('construire l\'adaptateur SOUS une transaction est REFUSÉ', $r['ok'] === false);
verifier('et le refus nomme la cause', str_contains($r['message'], 'transaction'), $r['message']);
$pdoB->rollBack();
$r = abrite(static fn (): object => new StockagePdo($pdoB, HOTE));
verifier('hors transaction, il se construit et active les clés',
    $r['ok'] === true && (int) $pdoB->query('PRAGMA foreign_keys')->fetchColumn() === 1);

// ⚠️ Ces deux lignes sont MESURÉES, pas écrites : un littéral s'imprimerait
// à l'identique même si le banc chargeait un autre fichier, et le contrôle
// de la CI qui les grep ne vaudrait rien.
$racine = ((string) realpath(dirname(__DIR__, 3))) . '/';
$fichierClasse = (string) (new ReflectionClass(StockagePdo::class))->getFileName();
echo "▸ implémentation exercée : " . str_replace($racine, '', $fichierClasse) . "\n";
echo "▸ schéma exercé          : " . str_replace($racine, '', (string) realpath(CHEMIN_SCHEMA)) . "\n";
echo "\n";

// ⚠️ Un banc dont une section entière disparaît rend le même vert qu'un banc
// complet, tant que le total reste au-dessus d'un plancher global. On vérifie
// donc section par section.
$parSection = [];
foreach ($journal as $entree) {
    $parSection[$entree['section']] = ($parSection[$entree['section']] ?? 0) + 1;
}

$manquantes = [];
foreach (PLANCHER as $nom => $minimum) {
    $fait = $parSection[$nom] ?? 0;
    if ($fait < $minimum) {
        $manquantes[] = "{$nom} : {$fait}/{$minimum}";
    }
}

$echecs = count(array_filter($journal, static fn (array $e): bool => !$e['ok']));
$passes = count($journal) - $echecs;

if ($manquantes !== []) {
    echo "\u{274C} Des sections ont fondu ou disparu — " . implode(' · ', $manquantes) . "\n";
    exit(1);
}

echo ($echecs === 0 ? "\u{2705} " : "\u{274C} ") . "{$passes} contrôle(s) passé(s), {$echecs} échec(s)"
   . ' sur ' . count($parSection) . " sections.\n";

// ⚠️ Le drapeau se lève À LA DERNIÈRE LIGNE, après le plancher ET après le
// verdict. Levé plus tôt, un `return` glissé entre les deux faisait sauter les
// deux en rendant 0 — la garde protégeait tout sauf ce qui décide.
$alleAuBout = true;
exit($echecs === 0 ? 0 : 1);
