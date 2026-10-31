<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/moderate.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Moderate;

require_method('POST');
require_csrf();
$pdo = Db::pdo();
require_admin($pdo);   // le balayage de tout le site est un geste d'arbitre

$result = Moderate::detectPackVoting($pdo);
// Les peines échues se referment aussi ici : un planificateur qui appelle cette route
// n'en laisse aucune attendre la console. Un refus du journal se dit dans la réponse ;
// une panne de la base n'en est pas un, et rend le 500 du lab.
try {
    $result['fins_refermees'] = Moderate::balayerBansEchus($pdo);
} catch (\PDOException $e) {
    throw $e;
} catch (\RuntimeException $e) {
    $result['fins_en_attente'] = $e->getMessage();
}
$result['flagged_for_review'] = Moderate::flaggedForReview($pdo);
json_out(['ok' => true] + $result);
