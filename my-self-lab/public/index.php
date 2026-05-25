<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/forum.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Forum;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
$cat = $_GET['cat'] ?? null;
if ($cat !== null && !isset(Forum::CATEGORIES[$cat])) {
    $cat = null;
}
$threads = Forum::listThreads($pdo, $cat);

render_header('Forum', $account);
?>
<div class="hero">
  <svg viewBox="0 0 200 280" width="116" height="162" role="img" aria-label="MySelf · my-self.fr">
    <g fill="none" stroke="currentColor" stroke-width="18" stroke-linecap="square" stroke-linejoin="miter"><path d="M 36 158 L 36 70 L 100 138 L 158 50 L 158 158"/><path d="M 158 50 L 174 28"/></g>
    <circle cx="180" cy="20" r="11" fill="currentColor"/>
    <g fill="currentColor" text-anchor="middle" font-family="-apple-system,system-ui,'Segoe UI',sans-serif">
      <text x="100" y="220" font-size="38" font-weight="700" letter-spacing="-0.5">MySelf</text>
      <text x="100" y="255" font-size="16" font-weight="400" letter-spacing="3" font-family="DejaVu Sans Mono,monospace">my-self.fr</text>
    </g>
  </svg>
  <p class="pitch">Forum de démonstration : <strong>attaquez-le, vos données survivent.</strong><br>Auth sans email · messages chiffrés de bout en bout · modération anti-manipulation.</p>
  <div class="cta">
    <a class="btn" href="/redteam.php">🛡️ Tester la sécurité</a>
    <a class="btn btn-ghost" href="/security.php">Voir l'architecture</a>
  </div>
  <p class="muted" style="margin-top:16px;font-size:12px">Un cowork <strong>Pierroons × Claude</strong> — sécurité open source, à l'épreuve.</p>
</div>
<div style="display:flex;align-items:center;gap:14px;margin-bottom:6px">
  <h1>Forum souveraineté numérique</h1>
  <?php if ($account): ?>
    <a href="/thread_new.php" class="btn" style="margin-left:auto">+ Nouveau sujet</a>
  <?php endif; ?>
</div>
<p class="muted" style="margin-top:0">Discussions sur le logiciel libre, le RGPD, l'auto-hébergement et le chiffrement.</p>

<div class="cats">
  <a href="/index.php" class="<?= $cat === null ? 'active' : '' ?>">Tout</a>
  <?php foreach (Forum::CATEGORIES as $key => $label): ?>
    <a href="/index.php?cat=<?= h($key) ?>" class="<?= $cat === $key ? 'active' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
<?php if (!$threads): ?>
  <p class="muted" style="text-align:center;padding:24px 0">Aucun sujet<?= $cat ? ' dans cette catégorie' : '' ?> pour l'instant.<?php if (!$account): ?> <a href="/register.php">Crée un compte</a> pour lancer la discussion.<?php endif; ?></p>
<?php else: ?>
  <?php foreach ($threads as $t): ?>
    <div class="thread-row">
      <div>
        <a href="/thread.php?id=<?= (int) $t['id'] ?>" style="font-weight:600;font-size:15px"><?= h($t['titre']) ?></a>
        <div class="muted">
          <span class="cat-pill"><?= h(Forum::CATEGORIES[$t['categorie']] ?? $t['categorie']) ?></span>
          par <strong>@<?= h($t['auteur']) ?></strong>
        </div>
      </div>
      <div class="meta">
        <?= (int) $t['nb_posts'] ?> message<?= $t['nb_posts'] > 1 ? 's' : '' ?><br>
        <?= date('d/m/Y', (int) ($t['last_post_at'] ?? $t['created_at'])) ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<?php if (!$account): ?>
<p class="muted" style="text-align:center;margin-top:18px">
  Lecture libre. <a href="/register.php">Crée un compte sans email</a> (SelfRecover) pour participer.
</p>
<?php endif; ?>
<?php render_footer(); ?>
