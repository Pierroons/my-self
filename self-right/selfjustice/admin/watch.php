<?php
/**
 * SelfJustice — Endpoint admin de surveillance (privé).
 *
 * Ce fichier est servi uniquement via la route nginx /w-<token>/
 * avec un token 32-char hex fourni via fastcgi_param SJ_PROVIDED_TOKEN.
 *
 * Lecture seule sur les logs nginx. Aucune action destructive.
 * Aucune donnée exposée au public : 404 si le token est invalide.
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// 1. Authentification par token
// ------------------------------------------------------------------
$provided = $_SERVER['SJ_PROVIDED_TOKEN'] ?? '';
$token_file = '/var/lib/selfjustice/admin/token.txt';
$stored = '';
$readable = is_readable($token_file);
if ($readable) {
    $stored = trim((string) file_get_contents($token_file));
}

if ($stored === '' || !hash_equals($stored, $provided)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>404</title></head><body><h1>Not Found</h1></body></html>";
    exit;
}

// ------------------------------------------------------------------
// 2. Lecture et parsing des logs nginx
// ------------------------------------------------------------------
// Les logs sont copiés dans un path accessible par PHP (open_basedir
// restreint /var/log/nginx/) via le script admin_feed.sh (cron 2 min).
$log_files = [
    '/var/lib/selfjustice/admin/access.log',
    '/var/lib/selfjustice/admin/access.log.1',
];

$cutoff_ts = time() - 86400; // 24 heures
$lines = [];
foreach ($log_files as $f) {
    if (is_readable($f)) {
        $content = @file_get_contents($f) ?: '';
        $lines = array_merge($lines, explode("\n", $content));
    }
}

$total_24h = 0;
$ips_24h = [];
$uas_24h = [];
$paths_24h = [];
$status_4xx = [];
$status_5xx = [];
$ia_hits = [];

$suspicious_paths = ['/.env', '/.git', '/wp-admin', '/wp-login', '/phpmyadmin', '/.aws', '/admin/config', '/xmlrpc.php', '/.vscode', '/config.json', '/console/', '/server-status'];

$ia_patterns = [
    'Claude-User (utilisateur)'     => '/Claude-User/i',
    'ClaudeBot (crawler)'           => '/ClaudeBot|anthropic-ai|claude-web/i',
    'ChatGPT-User (utilisateur)'    => '/ChatGPT-User/i',
    'OpenAI bots (crawlers)'        => '/GPTBot|OAI-SearchBot/i',
    'Perplexity-User (utilisateur)' => '/Perplexity-User/i',
    'PerplexityBot (crawler)'       => '/PerplexityBot/i',
    'Mistral bots'                  => '/MistralAI|Mistral-Bot/i',
    'Google bots / Gemini'          => '/GoogleBot|Google-Extended|GoogleOther|Bard|Gemini/i',
    'Grok'                          => '/Grok/i',
    'Autres (You, DuckAssist…)'     => '/YouBot|DuckAssistBot|Bytespider|Applebot/i',
];

// Regex combinée log format standard
$pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"]*) HTTP\/[^"]*" (\d+) \S+ "[^"]*" "([^"]*)"/';

foreach ($lines as $line) {
    if (!preg_match($pattern, $line, $m)) continue;
    $ip     = $m[1];
    $date   = $m[2];
    $method = $m[3];
    $path   = $m[4];
    $code   = (int) $m[5];
    $ua     = $m[6];

    // Format nginx : "17/Apr/2026:20:47:02 +0200"
    // On parse manuellement pour fiabilité.
    if (!preg_match('#(\d{2})/(\w{3})/(\d{4}):(\d{2}):(\d{2}):(\d{2})\s*([+-]\d{4})?#', $date, $dm)) {
        continue;
    }
    $iso = sprintf('%s-%s-%s %s:%s:%s %s', $dm[3], $dm[2], $dm[1], $dm[4], $dm[5], $dm[6], $dm[7] ?? '+0000');
    $ts = @strtotime($iso) ?: 0;
    if ($ts < $cutoff_ts) continue;

    $total_24h++;
    $ips_24h[$ip] = ($ips_24h[$ip] ?? 0) + 1;
    $uas_24h[$ua] = ($uas_24h[$ua] ?? 0) + 1;

    // ignorer favicon.ico et robots.txt pour les tops paths
    if (!in_array($path, ['/favicon.ico', '/robots.txt', '/'])) {
        $paths_24h[$path] = ($paths_24h[$path] ?? 0) + 1;
    }

    if ($code >= 400 && $code < 500) {
        $status_4xx[] = ['time' => $date, 'ip' => $ip, 'path' => $path, 'code' => $code, 'ua' => $ua];
    }
    if ($code >= 500) {
        $status_5xx[] = ['time' => $date, 'ip' => $ip, 'path' => $path, 'code' => $code];
    }

    foreach ($ia_patterns as $label => $re) {
        if (preg_match($re, $ua)) {
            $ia_hits[$label] = ($ia_hits[$label] ?? 0) + 1;
            break;
        }
    }
}

arsort($ips_24h);
arsort($uas_24h);
arsort($paths_24h);
arsort($ia_hits);

// ------------------------------------------------------------------
// Feedback uploads (/var/lib/selfjustice/feedback)
// ------------------------------------------------------------------
$feedback_dir = '/var/lib/selfjustice/feedback';
$feedback_total = 0;
$feedback_items = [];

if (is_dir($feedback_dir)) {
    $dirs = @scandir($feedback_dir);
    if ($dirs !== false) {
        $entries = array_filter($dirs, function($d) use ($feedback_dir) {
            return $d !== '.' && $d !== '..' && is_dir($feedback_dir . '/' . $d);
        });
        $feedback_total = count($entries);
        rsort($entries); // noms du type YYYYMMDD-HHMMSS-xxx → tri alpha = tri chrono desc

        foreach (array_slice($entries, 0, 10) as $slot) {
            $slot_path = $feedback_dir . '/' . $slot;
            $meta = @json_decode(@file_get_contents($slot_path . '/meta.json') ?: '', true);
            if (!is_array($meta)) continue;
            $comment_path = $slot_path . '/comment.txt';
            $has_comment = is_readable($comment_path);
            $comment = $has_comment ? trim((string) @file_get_contents($comment_path)) : '';
            $feedback_items[] = [
                'slot'    => $slot,
                'date'    => $meta['received_at'] ?? '',
                'moteur'  => $meta['moteur_ia'] ?? '—',
                'ext'     => $meta['extension'] ?? '?',
                'size'    => (int) ($meta['size_bytes'] ?? 0),
                'comment' => $comment,
            ];
        }
    }
}

function format_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' kB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

// Tentatives d'intrusion = requêtes vers paths suspects OU scanners connus
$intrusion_attempts = [];
foreach ($status_4xx as $row) {
    foreach ($suspicious_paths as $sp) {
        if (stripos($row['path'], $sp) !== false) {
            $intrusion_attempts[] = $row;
            break;
        }
    }
}

// ------------------------------------------------------------------
// 3. Rendu HTML
// ------------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function truncate(string $s, int $n): string { return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>SelfJustice — Watch</title>
  <style>
    :root {
      --bg: #0f1419;
      --bg-card: #1a2028;
      --text: #e8eaed;
      --text-muted: #9aa0a6;
      --accent: #7ab7ff;
      --accent-dim: #5a97df;
      --border: #2a3038;
      --warning: #d4c058;
      --danger: #e07b7b;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
      background: var(--bg);
      color: var(--text);
      padding: 1.5rem;
      line-height: 1.5;
    }
    header {
      border-bottom: 1px solid var(--border);
      padding-bottom: 1rem;
      margin-bottom: 1.5rem;
    }
    h1 {
      font-size: 1.4rem;
      color: var(--accent);
      margin: 0;
    }
    header p {
      color: var(--text-muted);
      font-size: 0.85rem;
      margin-top: 0.3rem;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      gap: 1rem;
    }
    .card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 1rem 1.2rem;
    }
    .card h2 {
      color: var(--accent-dim);
      font-size: 0.95rem;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      margin-bottom: 0.7rem;
      padding-bottom: 0.4rem;
      border-bottom: 1px solid var(--border);
    }
    .card.alert h2 { color: var(--warning); }
    .card.danger h2 { color: var(--danger); }
    table {
      width: 100%;
      font-size: 0.85rem;
      border-collapse: collapse;
    }
    td, th {
      padding: 0.3rem 0.4rem;
      text-align: left;
    }
    tr:nth-child(even) { background: rgba(255,255,255,0.02); }
    .num { color: var(--accent); font-weight: 600; text-align: right; }
    .tag {
      display: inline-block;
      background: var(--bg);
      color: var(--text-muted);
      padding: 0.1rem 0.5rem;
      border-radius: 4px;
      font-size: 0.75rem;
      border: 1px solid var(--border);
    }
    code, .mono {
      font-family: SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 0.8rem;
      color: var(--text);
      word-break: break-all;
    }
    .muted { color: var(--text-muted); }
    .kpi {
      display: flex;
      gap: 1.5rem;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }
    .kpi > div {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 0.8rem 1.2rem;
      min-width: 140px;
    }
    .kpi .val {
      color: var(--accent);
      font-size: 1.7rem;
      font-weight: 700;
      line-height: 1;
    }
    .kpi .lbl {
      color: var(--text-muted);
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-top: 0.3rem;
    }
  </style>
</head>
<body>

<header>
  <h1>SelfJustice · Watch</h1>
  <p>Fenêtre rolling 24 h — rafraîchi à chaque requête. Page privée, <span class="tag">noindex</span>.</p>
</header>

<div class="kpi">
  <div><div class="val"><?= number_format($total_24h, 0, ',', ' ') ?></div><div class="lbl">Requêtes 24 h</div></div>
  <div><div class="val"><?= count($ips_24h) ?></div><div class="lbl">IPs uniques</div></div>
  <div><div class="val"><?= count($uas_24h) ?></div><div class="lbl">UA uniques</div></div>
  <div><div class="val"><?= count($status_4xx) ?></div><div class="lbl">4xx</div></div>
  <div><div class="val"><?= count($status_5xx) ?></div><div class="lbl">5xx</div></div>
  <div><div class="val"><?= count($intrusion_attempts) ?></div><div class="lbl">Intrusions</div></div>
  <div><div class="val"><?= $feedback_total ?></div><div class="lbl">Feedback uploads</div></div>
</div>

<div class="grid">

  <div class="card">
    <h2>IA détectées (24 h)</h2>
    <table>
      <?php if (empty($ia_hits)): ?>
        <tr><td colspan="2" class="muted">Aucune IA identifiée sur la fenêtre.</td></tr>
      <?php else: foreach ($ia_hits as $label => $count): ?>
        <tr><td><?= h($label) ?></td><td class="num"><?= $count ?></td></tr>
      <?php endforeach; endif; ?>
    </table>
  </div>

  <div class="card">
    <h2>Top 15 IPs</h2>
    <table>
      <?php foreach (array_slice($ips_24h, 0, 15, true) as $ip => $count): ?>
        <tr><td class="mono"><?= h($ip) ?></td><td class="num"><?= $count ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>Top 15 User-Agents</h2>
    <table>
      <?php foreach (array_slice($uas_24h, 0, 15, true) as $ua => $count): ?>
        <tr><td class="mono"><?= h(truncate($ua, 80)) ?></td><td class="num"><?= $count ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>Top 15 endpoints consultés</h2>
    <table>
      <?php if (empty($paths_24h)): ?>
        <tr><td colspan="2" class="muted">—</td></tr>
      <?php else: foreach (array_slice($paths_24h, 0, 15, true) as $path => $count): ?>
        <tr><td class="mono"><?= h(truncate($path, 70)) ?></td><td class="num"><?= $count ?></td></tr>
      <?php endforeach; endif; ?>
    </table>
  </div>

  <div class="card alert">
    <h2>Tentatives d'intrusion récentes (<?= count($intrusion_attempts) ?>)</h2>
    <table>
      <?php if (empty($intrusion_attempts)): ?>
        <tr><td colspan="4" class="muted">Rien à signaler.</td></tr>
      <?php else: foreach (array_slice($intrusion_attempts, -20) as $row): ?>
        <tr>
          <td class="mono"><?= h(truncate($row['path'], 40)) ?></td>
          <td class="mono muted"><?= h($row['ip']) ?></td>
          <td class="num"><?= $row['code'] ?></td>
          <td class="mono muted"><?= h(truncate($row['ua'], 30)) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>

  <div class="card">
    <h2>Feedback uploads — 10 plus récents (total : <?= $feedback_total ?>)</h2>
    <p class="muted" style="font-size: 0.8rem; margin-bottom: 0.7rem;">
      Documents transmis via <code>/api/feedback</code> pour debug mise en page. Stockés 30 jours dans <code>/var/lib/selfjustice/feedback/</code>.
    </p>
    <table>
      <?php if (empty($feedback_items)): ?>
        <tr><td class="muted">Aucun upload pour l'instant.</td></tr>
      <?php else: ?>
        <tr style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px;">
          <th>Date</th><th>Moteur</th><th>Type</th><th style="text-align: right;">Taille</th><th>Cmt</th>
        </tr>
        <?php foreach ($feedback_items as $item): ?>
          <tr>
            <td class="mono" style="font-size: 0.75rem;"><?= h(substr((string) $item['date'], 0, 16)) ?></td>
            <td><?= h(truncate((string) $item['moteur'], 18)) ?></td>
            <td class="mono"><?= h((string) $item['ext']) ?></td>
            <td class="num"><?= h(format_bytes($item['size'])) ?></td>
            <td style="font-size: 0.75rem;" title="<?= h((string) $item['comment']) ?>">
              <?= $item['comment'] !== '' ? '<span class="mono" style="color: var(--accent);">●</span>' : '<span class="muted">—</span>' ?>
            </td>
          </tr>
          <?php if ($item['comment'] !== ''): ?>
            <tr><td colspan="5" class="muted" style="font-size: 0.75rem; padding-left: 1rem;">↳ <?= h(truncate((string) $item['comment'], 140)) ?></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </table>
  </div>

  <div class="card danger">
    <h2>Erreurs 5xx (<?= count($status_5xx) ?>)</h2>
    <table>
      <?php if (empty($status_5xx)): ?>
        <tr><td class="muted">Aucune erreur serveur sur la fenêtre.</td></tr>
      <?php else: foreach (array_slice($status_5xx, -20) as $row): ?>
        <tr>
          <td class="mono"><?= h($row['time']) ?></td>
          <td class="mono"><?= h(truncate($row['path'], 40)) ?></td>
          <td class="num"><?= $row['code'] ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>

</div>

<p class="muted" style="margin-top: 2rem; font-size: 0.75rem; text-align: center;">
  SelfJustice — Watch · lecture seule · zéro stockage, zéro cookie · accès limité à 3 req/min.
</p>

</body>
</html>
