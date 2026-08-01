<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
render_header(t('reg.title'), Auth::currentAccount(Db::pdo()));
?>
<h1><?= h(t('reg.h1')) ?></h1>
<p class="muted"><?= t('reg.intro') ?></p>

<div class="card" style="max-width:480px">
  <div id="msg"></div>
  <div id="form">
    <div class="field">
      <label><?= h(t('reg.username')) ?></label>
      <input id="username" placeholder="<?= h(t('reg.username_ph')) ?>" autocomplete="off">
    </div>
    <div class="field">
      <label><?= h(t('reg.recovery')) ?></label>
      <input id="recovery" placeholder="<?= h(t('reg.recovery_ph')) ?>" autocomplete="off">
    </div>
    <button class="btn" id="btn-creer"><?= h(t('reg.submit')) ?></button>
  </div>
</div>

<script nonce="<?= nonce() ?>">
// Libellés traduits côté serveur ; les secrets, eux, viennent du client.
const I18N = <?= json_encode(['done'=>t('reg.done'),'copy'=>t('reg.copy_now'),'pw'=>t('reg.password'),'pp'=>t('reg.passphrase'),'keep'=>t('reg.keep_safe'),'goto'=>t('reg.goto_login'),'err'=>t('log.error')], JSON_UNESCAPED_UNICODE) ?>;
function creer(){
  const username=document.getElementById('username').value.trim();
  const recovery=document.getElementById('recovery').value.trim();
  fetch('/api/register.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({username,recovery_word:recovery})})
    .then(r=>r.json()).then(d=>{
      const msg=document.getElementById('msg');
      if(d.ok){
        document.getElementById('form').style.display='none';
        msg.innerHTML='<div class="toast ok"><strong>'+I18N.done+'</strong> '+I18N.copy+'</div>'+
          '<div class="field"><label>'+I18N.pw+'</label><input value="'+d.credentials.password+'" readonly></div>'+
          '<div class="field"><label>'+I18N.pp.replace('%s', d.credentials.entropy_bits)+'</label><input value="'+d.credentials.passphrase+'" readonly></div>'+
          '<p class="muted">'+I18N.keep+'</p>'+
          '<a class="btn" href="/login.php">'+I18N.goto+'</a>';
      }else{
        msg.innerHTML='<div class="toast err">'+(d.message||I18N.err)+'</div>';
      }
    });
}
document.getElementById('btn-creer').addEventListener('click', creer);
</script>
<?php render_footer(); ?>
