<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

$account = Auth::currentAccount(Db::pdo());
render_header(t('title.security'), $account);
?>
<style>
.sec-intro{background:linear-gradient(120deg,rgba(63,185,140,.10),transparent);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:18px}
.sec-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:12px}
.sec-card h2{margin-top:0}
.sec-card ul{margin:6px 0 0;padding-left:18px;line-height:1.65}
.sec-card code{background:var(--elev);color:#9ad9bf;padding:1px 5px;border-radius:3px;font-size:12.5px}
.mt{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:8px 0}
@media(max-width:680px){.mt{grid-template-columns:1fr}}
.mt .ok{background:rgba(63,185,140,.08);border:1px solid rgba(63,185,140,.3);border-radius:8px;padding:12px 14px}
.mt .no{background:rgba(217,100,89,.08);border:1px solid rgba(217,100,89,.3);border-radius:8px;padding:12px 14px}
.mt h4{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
.mt .ok h4{color:var(--acc)} .mt .no h4{color:var(--danger)}
.mt ul{margin:0;padding-left:16px;line-height:1.6;font-size:13px}
.pill{display:inline-block;font-size:11px;padding:1px 8px;border-radius:10px;border:1px solid var(--border);color:var(--txt2);margin-left:6px}
.roadmap{font-size:13px;color:var(--txt2)}
</style>

<h1><?= t('sec.h1') ?></h1>
<div class="sec-intro"><?= t('sec.intro') ?></div>

<div class="sec-card">
  <h2><?= t('sec.1.h2') ?></h2>
  <?= t('sec.1.body') ?>
</div>

<div class="sec-card">
  <h2><?= t('sec.2.h2') ?></h2>
  <?= t('sec.2.body') ?>
</div>

<div class="sec-card">
  <h2><?= t('sec.3.h2') ?></h2>
  <?= t('sec.3.body') ?>
</div>

<div class="sec-card">
  <h2><?= t('sec.4.h2') ?></h2>
  <?= t('sec.4.body') ?>
</div>

<div class="sec-card">
  <h2><?= t('sec.5.h2') ?></h2>
  <?= t('sec.5.body') ?>
</div>

<div class="sec-card">
  <h2><?= t('sec.6.h2') ?></h2>
  <?= t('sec.6.body') ?>
</div>

<div class="sec-card">
  <h2><?= t('sec.7.h2') ?></h2>
  <?= t('sec.7.body') ?>
</div>

<?php render_footer(); ?>
