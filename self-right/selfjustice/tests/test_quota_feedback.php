<?php
/**
 * Garde-fou — le stockage des retours anonymes a un plafond, en nombre et en volume.
 *
 * 🔑 Un envoi anonyme pèse jusqu'à 5 Mo : sans plafond, la limite de débit seule
 * laissait remplir le disque qui porte les bases. Les volumes sont fabriqués en
 * fichiers creux : leur taille apparente compte, sans écrire 200 Mo.
 *
 *     php tests/test_quota_feedback.php
 */

declare(strict_types=1);

require __DIR__ . '/../api/quota_feedback.php';

$echecs = 0;
function verdict(bool $ok, string $libelle): void
{
    global $echecs;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $libelle . "\n";
    if (!$ok) {
        $echecs++;
    }
}

function bac(): string
{
    $d = sys_get_temp_dir() . '/quota-feedback-' . bin2hex(random_bytes(4));
    mkdir($d);
    return $d;
}

function envoi(string $dossier, string $nom, int $octets): void
{
    mkdir("$dossier/$nom");
    $f = fopen("$dossier/$nom/document.txt", 'w');
    ftruncate($f, $octets);
    fclose($f);
}

function vider(string $dossier): void
{
    foreach (glob("$dossier/*/*") ?: [] as $f) {
        unlink($f);
    }
    foreach (glob("$dossier/*") ?: [] as $d) {
        rmdir($d);
    }
    rmdir($dossier);
}

$mo = 1024 * 1024;

echo "▸ En nombre d'envois\n";
$d = bac();
verdict(feedback_place_restante($d, 1000), 'stockage vide → accepté');
for ($i = 0; $i < FEEDBACK_MAX_ENVOIS - 1; $i++) {
    envoi($d, "slot-$i", 10);
}
verdict(feedback_place_restante($d, 1000), 'un envoi sous le plafond → accepté');
envoi($d, 'slot-dernier', 10);
verdict(!feedback_place_restante($d, 1000), 'plafond d\'envois atteint → refusé');
vider($d);
verdict(feedback_place_restante(sys_get_temp_dir() . '/quota-feedback-absent', 1000), 'dossier absent → accepté');

echo "\n▸ En volume\n";
$d = bac();
envoi($d, 'gros', FEEDBACK_MAX_OCTETS - $mo);
verdict(feedback_place_restante($d, $mo), 'envoi qui remplit tout juste le plafond → accepté');
verdict(!feedback_place_restante($d, 2 * $mo), 'envoi qui dépasse le plafond → refusé');
vider($d);

echo "\n";
if ($echecs > 0) {
    echo "✗ $echecs contrôle(s) en échec.\n";
    exit(1);
}
echo "✓ Le stockage des retours a un plafond.\n";
exit(0);
