<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/dm.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\DM;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
if (!$account) {
    header('Location: /login.php');
    exit;
}
$inbox = DM::inbox($pdo, (int) $account['id']);
$sent = DM::sent($pdo, (int) $account['id']);
DM::markRead($pdo, (int) $account['id']);

render_header(t('msg.title'), $account);
?>
<h1><?= h(t('msg.h1')) ?></h1>
<p class="muted"><?= t('msg.note') ?></p>

<div class="card" style="max-width:600px">
  <h2><?= h(t('msg.new.h2')) ?></h2>
  <div id="msg"></div>
  <div class="field"><label><?= h(t('msg.to')) ?></label><input id="dest" placeholder="<?= h(t('msg.to_ph')) ?>"></div>
  <div class="field"><label><?= h(t('msg.body')) ?></label><textarea id="contenu" rows="3"></textarea></div>
  <button class="btn" id="btn-envoyer"><?= h(t('msg.send')) ?></button>
</div>

<h2 style="margin-top:24px"><?= h(t('msg.inbox')) ?> (<?= count($inbox) ?>)</h2>
<?php if (!$inbox): ?>
  <p class="muted"><?= h(t('msg.inbox.none')) ?></p>
<?php else: foreach ($inbox as $m): ?>
  <div class="post">
    <div class="head"><span class="auteur">@<?= h($m['expediteur']) ?></span><span class="muted"><?= date('d/m/Y H:i', $m['created_at']) ?></span></div>
    <div class="contenu"><?= h($m['message']) ?></div>
  </div>
<?php endforeach; endif; ?>

<?php if ($sent): ?>
<h2 style="margin-top:24px"><?= h(t('msg.sent')) ?> (<?= count($sent) ?>)</h2>
<?php foreach ($sent as $m): ?>
  <div class="post" style="opacity:.8">
    <div class="head"><?= h(t('msg.to_prefix')) ?> <span class="auteur">@<?= h($m['destinataire']) ?></span><span class="muted"><?= date('d/m/Y H:i', $m['created_at']) ?></span></div>
    <div class="contenu"><?= h($m['message']) ?></div>
  </div>
<?php endforeach; endif; ?>

<script nonce="<?= nonce() ?>">
function envoyer(){
  const dest=document.getElementById('dest').value.trim();
  const contenu=document.getElementById('contenu').value.trim();
  labPost('/api/dm_send.php',{destinataire:dest,message:contenu})
    .then(d=>{
      if(d.ok){location.reload();}
      else{document.getElementById('msg').innerHTML='<div class="toast err">'+(d.message||<?= json_encode(t('log.error')) ?>)+'</div>';}
    });
}
document.getElementById('btn-envoyer').addEventListener('click', envoyer);
</script>
<?php render_footer(); ?>
