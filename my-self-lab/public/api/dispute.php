<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Dispute;

require_method('POST'); // endpoint public : celui qui l'utilise n'a plus de compte

$body = json_in();
$r = Dispute::open(Db::pdo(), (string) ($body['username'] ?? ''), [
    'recit'   => (string) ($body['recit'] ?? ''),
    'contact' => (string) ($body['contact'] ?? ''),
], client_ip());

json_out($r, $r['ok'] ? 200 : 400);
