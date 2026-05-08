<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);

$body      = json_input();
$fieldName = (string) ($body['fieldName'] ?? '');
$value     = (string) ($body['value']     ?? '');

if ($fieldName === '' || $value === '') fail('fieldName and value are required');

$found = $dataGuard->findUserByField($fieldName, $value);
ok(['fieldName' => $fieldName, 'value' => $value, 'userId' => $found]);
