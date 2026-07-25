<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
render_header('Créer un compte', Auth::currentAccount(Db::pdo()));
?>
<h1>Créer un compte</h1>
<p class="muted">Sans email, sans téléphone. Auth par <strong>SelfRecover</strong> : tu choisis un mot de récupération, le serveur te génère un mot de passe + une passphrase à conserver.</p>

<div class="card" style="max-width:480px">
  <div id="msg"></div>
  <div id="form">
    <div class="field">
      <label>Identifiant</label>
      <input id="username" placeholder="3-20 caractères, minuscules/chiffres/_" autocomplete="off">
    </div>
    <div class="field">
      <label>Mot de récupération (tu le choisis, garde-le secret)</label>
      <input id="recovery" placeholder="ex : monchat2024" autocomplete="off">
    </div>
    <button class="btn" id="btn-creer">Créer mon compte</button>
  </div>
</div>

<script src="/js/sr-derive.js"></script>
<script src="/js/sr-device.js"></script>
<script nonce="<?= nonce() ?>">
async function creer(){
  const username=document.getElementById('username').value.trim();
  const recovery=document.getElementById('recovery').value.trim();
  const msg=document.getElementById('msg');
  if(recovery.length<4){msg.innerHTML='<div class="toast err">Le mot de récupération doit faire au moins 4 caractères.</div>';return;}
  // Dérivation côté client : le mot mémorisé ne quitte jamais le navigateur.
  const recovery_derived_key=await srDerive(recovery);
  fetch('/api/register.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({username,recovery_derived_key})})
    .then(r=>r.json()).then(d=>{
      const msg=document.getElementById('msg');
      if(d.ok){
        document.getElementById('form').style.display='none';
        msg.innerHTML='<div class="toast ok"><strong>Compte créé !</strong> Copie ces secrets MAINTENANT (affichés une seule fois) :</div>'+
          '<div class="field"><label>Mot de passe</label><input value="'+d.credentials.password+'" readonly></div>'+
          '<div class="field"><label>Passphrase de secours ('+d.credentials.entropy_bits+' bits) — récupération L1</label><input value="'+d.credentials.passphrase+'" readonly></div>'+
          '<div class="field"><label>Codes de secours — 10, usage unique (récupération L2 avec ton mot mémorisé)</label><textarea readonly rows="5" style="width:100%;font-family:monospace">'+(d.credentials.recovery_codes||[]).join('\n')+'</textarea></div>'+
          '<p class="muted">Ton mot de récupération, tu le connais déjà. Garde mot de passe, passphrase ET codes en lieu sûr.</p>'+
          '<div style="margin:10px 0"><button class="btn" id="dev-enroll" type="button">📱 Activer la récupération depuis cet appareil</button> <span id="dev-status" class="muted"></span></div>'+
          '<a class="btn" href="/login.php">Aller à la connexion →</a>';
        var db=document.getElementById('dev-enroll');
        db.addEventListener('click',async function(){
          db.disabled=true; document.getElementById('dev-status').textContent='enrôlement…';
          try{ var r=await srDeviceEnroll(d.username, recovery);
               document.getElementById('dev-status').textContent = r.ok ? 'appareil enrôlé ✔' : ('⚠ '+(r.message||'échec')); }
          catch(e){ document.getElementById('dev-status').textContent='⚠ échec crypto'; }
        });
      }else{
        msg.innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>';
      }
    });
}
document.getElementById('btn-creer').addEventListener('click', creer);
</script>
<?php render_footer(); ?>
