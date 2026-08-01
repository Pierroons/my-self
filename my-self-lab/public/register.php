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

<script src="/js/sr-device.js"></script>
<script nonce="<?= nonce() ?>">
// Libellés traduits côté serveur ; les secrets, eux, viennent du client.
const I18N = <?= json_encode([
  'done'=>t('reg.done'),'copy'=>t('reg.copy_now'),'pw'=>t('reg.password'),
  'pp'=>t('reg.passphrase'),'keep'=>t('reg.keep_safe'),'goto'=>t('reg.goto_login'),
  'err'=>t('log.error'),'codes'=>t('reg.codes'),
  'devBtn'=>t('dev.enroll.btn'),'devNote'=>t('dev.enroll.note'),
  'devDoing'=>t('dev.enroll.doing'),'devOk'=>t('dev.enroll.ok'),'devFail'=>t('dev.enroll.fail'),
], JSON_UNESCAPED_UNICODE) ?>;
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
          // Les codes sont générés à l'inscription et ne seront plus jamais
          // affichés : ne pas les montrer ici rendait le L2 par code inutilisable.
          '<div class="field"><label>'+I18N.codes+'</label><textarea readonly rows="5" style="width:100%;font-family:monospace">'+(d.credentials.recovery_codes||[]).join('\n')+'</textarea></div>'+
          '<p class="muted">'+I18N.keep+'</p>'+
          '<div style="margin:10px 0">'+
            '<button class="btn" id="dev-enroll" type="button">'+I18N.devBtn+'</button> '+
            '<span id="dev-status" class="muted"></span>'+
            '<p class="muted" style="font-size:12px;margin-top:6px">'+I18N.devNote+'</p>'+
          '</div>'+
          '<a class="btn" href="/login.php">'+I18N.goto+'</a>';
        // Enrôlement de l'appareil : facteur de possession alternatif au code.
        // La clé privée est chiffrée par le mot mémorisé, jamais transmise.
        var db=document.getElementById('dev-enroll');
        db.addEventListener('click', async function(){
          db.disabled=true;
          var st=document.getElementById('dev-status');
          st.textContent=I18N.devDoing;
          try{
            var r=await srDeviceEnroll(username, recovery);
            st.textContent = r.ok ? I18N.devOk : (I18N.devFail+' — '+(r.message||''));
          }catch(e){ st.textContent=I18N.devFail; }
        });
      }else{
        msg.innerHTML='<div class="toast err">'+(d.message||I18N.err)+'</div>';
      }
    });
}
document.getElementById('btn-creer').addEventListener('click', creer);
</script>
<?php render_footer(); ?>
