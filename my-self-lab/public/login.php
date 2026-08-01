<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
render_header(t('log.title'), Auth::currentAccount(Db::pdo()));
?>
<h1><?= h(t('log.h1')) ?></h1>
<div class="card" style="max-width:420px">
  <div id="msg"></div>
  <div class="field"><label><?= h(t('log.username')) ?></label><input id="username" autocomplete="off"></div>
  <div class="field"><label><?= h(t('log.password')) ?></label><input id="password" type="password"></div>
  <button class="btn" id="btn-login"><?= h(t('log.submit')) ?></button>
  <p class="muted" style="margin-top:12px"><?= t('log.noaccount') ?></p>
  <p class="muted" style="margin-top:4px"><?= t('log.forgot') ?></p>
</div>
<script nonce="<?= nonce() ?>">
const LOG_ERR = <?= json_encode(t('log.error'), JSON_UNESCAPED_UNICODE) ?>;
function connecter(){
  const username=document.getElementById('username').value.trim();
  const password=document.getElementById('password').value;
  fetch('/api/login.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({username,password})})
    .then(r=>r.json()).then(d=>{
      if(d.ok){location.href='/index.php';}
      else{document.getElementById('msg').innerHTML='<div class="toast err">'+(d.message||LOG_ERR)+'</div>';}
    });
}
document.getElementById('btn-login').addEventListener('click', connecter);
document.getElementById('password').addEventListener('keydown', e=>{ if(e.key==='Enter') connecter(); });
</script>
<?php render_footer(); ?>
