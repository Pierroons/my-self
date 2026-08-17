<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/su_console.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\SuConsole;

require_method('POST');
require_csrf();
require_auth(Db::pdo());   // auth simple : tout est SANDBOX → pas de require_admin (aucun pouvoir réel)

$body = json_in();

// Terminal SU simulé : { cmd: "...", mutations: [...] }  (tout en sandbox, aucun effet réel)
if (array_key_exists('cmd', $body)) {
    $mutations = is_array($body['mutations'] ?? null) ? $body['mutations'] : [];
    json_out(tc_deep(SuConsole::terminal($mutations, (string) $body['cmd'])));
}

// Sélecteur de rôle : { role: "user"|"admin"|"su" }
$role = (string) ($body['role'] ?? '');
if (!in_array($role, SuConsole::ROLES, true)) {
    json_out(['ok' => false, 'message' => tc('Rôle inconnu.')], 400);
}

// La classe compose en français ; la traduction se fait ici, à la sortie, pour
// que le contenu suive la langue du lecteur sans disperser des appels dans la
// logique elle-même.
$result = tc_deep(SuConsole::run($role));
json_out($result, ($result['ok'] ?? false) ? 200 : 400);
