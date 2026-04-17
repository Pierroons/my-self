<?php
/**
 * SelfJustice — Endpoint feedback sur la mise en page des documents.
 *
 * L'utilisateur upload un document produit par son IA dont la mise en page
 * a mal rendu. But : nous (humains) analysons manuellement pour affiner
 * les directives de rendu PDF par moteur IA.
 *
 * Ce script n'extrait AUCUNE donnée du fichier. Il stocke le fichier brut
 * + le nom du moteur IA + un commentaire éventuel dans un dossier daté.
 *
 * Auto-purge à 30 jours via cron cleanup_feedback.sh.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function reject(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// Validation méthode
// ------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    reject(405, 'Méthode non autorisée');
}

// ------------------------------------------------------------------
// Validation fichier
// ------------------------------------------------------------------
if (empty($_FILES['document']) || !is_array($_FILES['document'])) {
    reject(400, 'Aucun fichier transmis');
}

$file = $_FILES['document'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    reject(400, 'Erreur upload (code ' . $file['error'] . ')');
}

$max_bytes = 5 * 1024 * 1024; // 5 Mo
if ($file['size'] > $max_bytes) {
    reject(413, 'Fichier trop volumineux (max 5 Mo)');
}
if ($file['size'] <= 0) {
    reject(400, 'Fichier vide');
}

$allowed_ext = ['pdf', 'md', 'txt', 'html'];
$orig_name = basename((string) $file['name']);
$ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext, true)) {
    reject(415, 'Format non accepté (autorisés : pdf, md, txt, html)');
}

// Contrôle MIME basique (trust-but-verify)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected_mime = $finfo->file($file['tmp_name']);
$allowed_mimes = [
    'application/pdf',
    'text/plain', 'text/markdown', 'text/html',
    'application/x-empty',
];
if (!in_array($detected_mime, $allowed_mimes, true)) {
    reject(415, 'MIME non reconnu : ' . $detected_mime);
}

// ------------------------------------------------------------------
// Validation champs annexes
// ------------------------------------------------------------------
$moteur = trim((string) ($_POST['moteur'] ?? ''));
if (strlen($moteur) < 2 || strlen($moteur) > 100) {
    reject(400, 'Moteur IA requis (2 à 100 caractères)');
}
// Whitelist basique des caractères (empêche l'injection)
if (!preg_match('/^[\p{L}\p{N}\s\.\-\+\/:]+$/u', $moteur)) {
    reject(400, 'Moteur IA contient des caractères non autorisés');
}

$comment = trim((string) ($_POST['comment'] ?? ''));
if (strlen($comment) > 2000) {
    reject(400, 'Commentaire trop long (max 2000 caractères)');
}

// ------------------------------------------------------------------
// Stockage
// ------------------------------------------------------------------
$base_dir = '/var/lib/selfjustice/feedback';
if (!is_dir($base_dir)) {
    @mkdir($base_dir, 0775, true);
}
if (!is_writable($base_dir)) {
    reject(500, 'Stockage indisponible');
}

$slot = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$slot_dir = $base_dir . '/' . $slot;
if (!@mkdir($slot_dir, 0750, true)) {
    reject(500, 'Impossible de créer le dossier');
}

$dest = $slot_dir . '/document.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    reject(500, 'Enregistrement échoué');
}

$meta = [
    'received_at'   => date('c'),
    'moteur_ia'     => $moteur,
    'original_name' => $orig_name,
    'size_bytes'    => $file['size'],
    'mime'          => $detected_mime,
    'extension'     => $ext,
    'user_agent'    => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    // Pas d'IP enregistrée, pas de cookie, pas de fingerprint.
];
@file_put_contents($slot_dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($comment !== '') {
    @file_put_contents($slot_dir . '/comment.txt', $comment);
}

// ------------------------------------------------------------------
// Réponse simple — pas de ref renvoyée à l'utilisateur.
// ------------------------------------------------------------------
echo json_encode([
    'ok'      => true,
    'message' => 'Merci, ton feedback a été enregistré. Il sera consulté manuellement pour améliorer le rendu des documents selon les moteurs IA.',
], JSON_UNESCAPED_UNICODE);
