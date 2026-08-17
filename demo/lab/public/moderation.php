<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/moderate.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Moderate;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
$blocked = Moderate::blockedVotes($pdo, 30);

render_header(t('mod.title'), $account);
?>
<h1><?= t('mod.h1') ?></h1>
<p class="muted"><?= t('mod.intro') ?></p>

<div class="card">
  <h2><?= h(t('mod.how.h2')) ?></h2>
  <ul style="color:var(--txt2);font-size:13px;line-height:1.7;margin:0;padding-left:18px"><?= t('mod.how.body') ?></ul>
</div>

<?php if ($account): ?>
<div class="card">
  <h2><?= h(t('mod.detect.h2')) ?></h2>
  <p class="muted"><?= h(t('mod.detect.note')) ?></p>
  <div id="dmsg"></div>
  <button class="btn" id="btn-detecter"><?= h(t('mod.detect.btn')) ?></button>
</div>
<script nonce="<?= nonce() ?>">
const MOD_I18N = <?= json_encode(['cancelled'=>t('mod.js.cancelled'),'target'=>t('mod.js.target'),'spread'=>t('mod.js.spread'),'none'=>t('mod.js.none'),'err'=>t('log.error')], JSON_UNESCAPED_UNICODE) ?>;
function detecter(){
  labPost('/api/detect_abuse.php',{}).then(d=>{
    const m=document.getElementById('dmsg');
    if(d.ok){
      if(d.pack_detected){
        let html='<div class="toast ok">✓ '+d.cancelled_votes+' '+MOD_I18N.cancelled+'<ul>';
        d.packs.forEach(p=>{html+='<li>'+MOD_I18N.target+' #'+p.target_author+' — '+p.voters.join(', ')+' ('+MOD_I18N.spread+' '+p.spread_s+'s)</li>';});
        html+='</ul></div>';
        m.innerHTML=html;
        setTimeout(()=>location.reload(),2500);
      } else {
        m.innerHTML='<div class="toast ok">'+MOD_I18N.none+'</div>';
      }
    } else { m.innerHTML='<div class="toast err">'+(d.message||MOD_I18N.err)+'</div>'; }
  });
}
document.getElementById('btn-detecter').addEventListener('click', detecter);
</script>
<?php else: ?>
<div class="card"><p class="muted"><?= t('mod.detect.login') ?></p></div>
<?php endif; ?>

<div class="card">
  <h2><?= h(t('mod.blocked.h2', count($blocked))) ?></h2>
  <?php if (!$blocked): ?>
    <p class="muted"><?= h(t('mod.blocked.none')) ?></p>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead><tr style="text-align:left;color:var(--muted)"><th style="padding:6px 8px"><?= h(t('mod.col.date')) ?></th><th><?= h(t('mod.col.voter')) ?></th><th><?= h(t('mod.col.target')) ?></th><th><?= h(t('mod.col.type')) ?></th><th><?= h(t('mod.col.reason')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($blocked as $b): ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:6px 8px"><?= date('d/m H:i', (int) $b['created_at']) ?></td>
          <td>@<?= h($b['voter']) ?></td>
          <td>@<?= h($b['cible']) ?></td>
          <td><?= $b['value'] == 1 ? '▲' : '▼' ?> <?= h($b['target_type']) ?></td>
          <td><span style="color:var(--danger)"><?= h($b['blocked_reason']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
