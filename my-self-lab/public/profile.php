<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/profile.php';
require_once __DIR__ . '/../lib/moderate.php';
require_once __DIR__ . '/../lib/memo_vault.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Profile;
use Pierroons\MySelfLab\Moderate;
use Pierroons\MySelfLab\MemoVault;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);

// Vue publique d'un membre : ?u=username
$voirUsername = $_GET['u'] ?? null;
if ($voirUsername !== null) {
    $vue = Profile::getByUsername($pdo, $voirUsername);
    render_header('Profil @' . $voirUsername, $account);
    if (!$vue) {
        echo '<div class="card"><p class="muted">Membre introuvable.</p></div>';
    } else {
        $p = $vue['profil'];
        // Récupère l'account_id + réputation du membre vu
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE username = ?');
        $stmt->execute([strtolower($vue['username'])]);
        $memberId = (int) $stmt->fetchColumn();
        $rep = Moderate::getReputation($pdo, $memberId);
        $repScore = $rep['reputation'];
        $repPct = (int) round($repScore / Moderate::MAX_REPUTATION * 100);
        $repColor = $repScore >= 25 ? '#3fb98c' : ($repScore >= 15 ? '#9aa9b6' : ($repScore >= 5 ? '#d4a056' : '#d96459'));
        $mine = $account ? Moderate::userVote($pdo, (int) $account['id'], 'member', $memberId) : null;
        $isSelf = $account && (int) $account['id'] === $memberId;
        [$canVote, $whyNot] = $account ? Moderate::canVote($pdo, (int) $account['id']) : [false, ''];
        ?>
        <h1>@<?= h($vue['username']) ?></h1>
        <div class="card">
          <p style="margin-top:0"><strong><?= h(t('prf.rep')) ?></strong> · <span style="color:<?= $repColor ?>;font-weight:700">★ <?= $repScore ?>/30</span>
            <?php if ($rep['banned']): ?><span style="color:#d96459"> · <?= h(t('prf.suspended')) ?></span><?php endif; ?>
            <?php if (!$rep['voting_rights']): ?><span style="color:#d4a056"> · <?= h(t('prf.novote')) ?></span><?php endif; ?>
          </p>
          <div style="height:8px;background:var(--elev);border-radius:4px;overflow:hidden;margin-bottom:14px">
            <div style="height:100%;width:<?= $repPct ?>%;background:<?= $repColor ?>"></div>
          </div>
          <?php if ($account && !$isSelf): ?>
            <div id="vmsg"></div>
            <button class="btn js-votembr" data-mid="<?= $memberId ?>" data-val="1" <?= (!$canVote || $mine !== null) ? 'disabled' : '' ?> style="padding:6px 14px"><?= h(t('prf.support')) ?></button>
            <button class="btn btn-ghost js-votembr" data-mid="<?= $memberId ?>" data-val="-1" <?= (!$canVote || $mine !== null) ? 'disabled' : '' ?> style="padding:6px 14px"><?= h(t('prf.report')) ?></button>
            <?php if ($mine !== null): ?><span class="muted" style="margin-left:8px"><?= h(t('prf.voted', $mine === 1 ? '▲' : '▼')) ?></span><?php endif; ?>
            <?php if (!$canVote && $whyNot): ?><p class="muted" style="margin-top:8px">⚠ <?= h($whyNot) ?></p><?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="card">
          <p style="margin-top:0"><strong>Bio</strong><br><?= h($p['bio']) ?: '<span class="muted">—</span>' ?></p>
          <p><strong>Localisation</strong><br><?= h($p['localisation']) ?: '<span class="muted">—</span>' ?></p>
          <p><strong>Lien</strong><br><?php
            if ($p['lien'] === '') { echo '<span class="muted">—</span>'; }
            elseif (Profile::lienSur($p['lien'])) { echo '<a href="' . h($p['lien']) . '" rel="noopener nofollow">' . h($p['lien']) . '</a>'; }
            else { echo h($p['lien']); /* schéma non sûr : jamais cliquable */ }
          ?></p>
        </div>
        <script nonce="<?= nonce() ?>">
const PRF = <?= json_encode(['saved'=>t('prf.js.saved'),'required'=>t('prf.js.required'),'weakpass'=>t('prf.js.weakpass'),'created'=>t('prf.js.created'),'deriving'=>t('prf.js.deriving'),'decrypted'=>t('prf.js.decrypted'),'locked'=>t('prf.js.locked'),'saved2'=>t('prf.js.saved2'),'err'=>t('log.error')], JSON_UNESCAPED_UNICODE) ?>;
        function voteMembre(id, value){
          labPost('/api/vote.php',{target_type:'member',target_id:id,value:value}).then(d=>{
            if(d.ok){ location.reload(); }
            else{ document.getElementById('vmsg').innerHTML='<div class="toast err">'+(d.message||PRF.err)+'</div>'; }
          });
        }
        document.querySelectorAll('.js-votembr').forEach(function(b){
          b.addEventListener('click', function(){ voteMembre(+b.dataset.mid, +b.dataset.val); });
        });
        </script>
        <?php
    }
    render_footer();
    exit;
}

// Édition de son propre profil
if (!$account) {
    header('Location: /login.php');
    exit;
}
$p = Profile::get($pdo, (int) $account['id']);
$myId = (int) $account['id'];

// Mon état de modération
$rep = Moderate::getReputation($pdo, $myId);
$repScore = $rep['reputation'];
$repPct = (int) round($repScore / Moderate::MAX_REPUTATION * 100);
$repColor = $repScore >= 25 ? '#3fb98c' : ($repScore >= 15 ? '#9aa9b6' : ($repScore >= 5 ? '#d4a056' : '#d96459'));
$repLabel = $repScore >= 25 ? t('prf.rep.trust') : ($repScore >= 15 ? t('prf.rep.member') : ($repScore >= 5 ? t('prf.rep.frail') : t('prf.rep.watch')));
// Activité
$cnt = function (string $sql) use ($pdo, $myId): int {
    $s = $pdo->prepare($sql);
    $s->execute([$myId]);
    return (int) $s->fetchColumn();
};
$nbPosts      = $cnt('SELECT COUNT(*) FROM posts WHERE account_id = ?');
$nbThreads    = $cnt('SELECT COUNT(*) FROM threads WHERE account_id = ?');
$nbVotesEmis  = $cnt('SELECT COUNT(*) FROM mod_votes WHERE voter_id = ? AND blocked = 0');
$nbVotesRecus = $cnt('SELECT COUNT(*) FROM mod_votes WHERE target_author = ? AND blocked = 0');
[$canVote, $whyNot] = Moderate::canVote($pdo, $myId);

render_header(t('prf.title'), $account);
?>
<h1>Mon espace — @<?= h($account['username']) ?></h1>

<!-- Panel modération -->
<div class="card">
  <h2 style="margin-top:0"><?= t('prf.mod.h2') ?></h2>
  <p style="margin:0 0 6px"><span style="color:<?= $repColor ?>;font-weight:700;font-size:18px">★ <?= $repScore ?>/30</span>
     <span class="cat-pill" style="color:<?= $repColor ?>;border-color:<?= $repColor ?>;margin-left:8px"><?= $repLabel ?></span></p>
  <div style="height:9px;background:var(--elev);border-radius:5px;overflow:hidden;margin:8px 0 14px">
    <div style="height:100%;width:<?= $repPct ?>%;background:<?= $repColor ?>"></div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;font-size:13px">
    <div><span class="muted"><?= h(t('prf.mod.right')) ?></span><br><strong style="color:<?= $canVote ? 'var(--acc)' : 'var(--warn)' ?>"><?= $canVote ? t('prf.mod.active') : '✗ '. t($rep['voting_rights'] ? 'prf.mod.limited' : 'prf.mod.removed') ?></strong></div>
    <div><span class="muted"><?= h(t('prf.mod.strikes')) ?></span><br><strong><?= $rep['strikes'] ?>/3</strong></div>
    <div><span class="muted"><?= h(t('prf.mod.status')) ?></span><br><strong style="color:<?= $rep['banned'] ? 'var(--danger)' : 'var(--acc)' ?>"><?= t($rep['banned'] ? 'prf.suspended' : 'prf.mod.st.active') ?></strong></div>
  </div>
  <?php if (!$canVote && $whyNot): ?>
    <p class="muted" style="margin:12px 0 0">⚠ <?= h($whyNot) ?></p>
  <?php endif; ?>
</div>

<!-- Panel activité -->
<div class="card">
  <h2 style="margin-top:0"><?= t('prf.act.h2') ?></h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;font-size:13px">
    <div><span class="muted"><?= h(t('prf.act.threads')) ?></span><br><strong style="font-size:18px"><?= $nbThreads ?></strong></div>
    <div><span class="muted"><?= h(t('prf.act.posts')) ?></span><br><strong style="font-size:18px"><?= $nbPosts ?></strong></div>
    <div><span class="muted"><?= h(t('prf.act.given')) ?></span><br><strong style="font-size:18px"><?= $nbVotesEmis ?></strong></div>
    <div><span class="muted"><?= h(t('prf.act.got')) ?></span><br><strong style="font-size:18px"><?= $nbVotesRecus ?></strong></div>
  </div>
  <p style="margin:12px 0 0"><a href="/profile.php?u=<?= h($account['username']) ?>"><?= h(t('prf.act.public')) ?></a></p>
</div>

<h2 style="margin-top:24px"><?= t('prf.edit.h2') ?> <span class="cat-pill" style="color:var(--warn);border-color:var(--warn)"><?= h(t('prf.edit.tag')) ?></span></h2>
<p class="muted" style="background:rgba(212,160,86,.10);border:1px solid rgba(212,160,86,.35);border-radius:8px;padding:10px 13px">
  <?= t('prf.public.warn') ?></p>
<div class="card" style="max-width:560px">
  <div id="msg"></div>
  <div class="field"><label>Bio</label><textarea id="bio" rows="3"><?= h($p['bio']) ?></textarea></div>
  <div class="field"><label>Localisation</label><input id="localisation" value="<?= h($p['localisation']) ?>"></div>
  <div class="field"><label>Lien (site, blog…)</label><input id="lien" value="<?= h($p['lien']) ?>" placeholder="https://…"></div>
  <button class="btn" id="btn-saveprofil">Enregistrer le profil public</button>
</div>

<?php $vaultExiste = \Pierroons\MySelfLab\MemoVault::exists($pdo, $myId); ?>
<h2 style="margin-top:24px"><?= t('prf.memo.h2') ?></h2>
<p class="muted"><?= t('prf.memo.note') ?></p>
<div class="card" style="max-width:600px;border-color:var(--danger)">
  <div id="msgmemo"></div>

  <!-- État A : aucun coffre → création -->
  <div id="memo-create" style="display:<?= $vaultExiste ? 'none' : 'block' ?>">
    <p class="muted" style="margin-top:0"><?= t('prf.memo.create') ?></p>
    <div class="field"><label>Ton mot de passe</label><input type="password" id="c-pw" autocomplete="off"></div>
    <div class="field"><label><?= h(t('prf.memo.pass')) ?> <span style="text-transform:none;font-weight:400;color:var(--muted)"><?= h(t('prf.memo.pass_hint')) ?></span></label><input type="password" id="c-pass" autocomplete="off" placeholder="<?= h(t('prf.memo.pass_ph')) ?>"><span class="muted" style="font-size:11px"><?= h(t('prf.memo.pass_note')) ?></span></div>
    <div class="field"><label><?= h(t('prf.memo.label')) ?></label><textarea id="c-memo" rows="4" placeholder="<?= h(t('prf.memo.ph')) ?>"></textarea></div>
    <button class="btn" id="btn-memocreer"><?= h(t('prf.memo.btncreate')) ?></button>
  </div>

  <!-- État B : coffre existant → déverrouillage -->
  <div id="memo-locked" style="display:<?= $vaultExiste ? 'block' : 'none' ?>">
    <p style="margin-top:0"><?= h(t('prf.memo.locked')) ?></p>
    <div class="field"><label>Mot de passe</label><input type="password" id="u-pw" autocomplete="off"></div>
    <button class="btn" id="btn-memodev"><?= h(t('prf.memo.unlock')) ?></button>
    <button class="btn btn-ghost" id="btn-memoforgot"><?= h(t('prf.memo.forgot')) ?></button>
    <div id="memo-recover" style="display:none;margin-top:12px">
      <div class="field"><label>Passphrase de secours</label><input type="password" id="r-pass" autocomplete="off"></div>
      <button class="btn" id="btn-memorecup"><?= h(t('prf.memo.recover')) ?></button>
    </div>
  </div>

  <!-- État C : déverrouillé → édition -->
  <div id="memo-open" style="display:none">
    <div class="field"><label><?= h(t('prf.memo.decrypted')) ?></label><textarea id="o-memo" rows="4"></textarea></div>
    <button class="btn" id="btn-memosave"><?= h(t('prf.memo.save')) ?></button>
    <button class="btn btn-ghost" id="btn-memolock">Verrouiller</button>
  </div>
</div>
<script nonce="<?= nonce() ?>">
function enregistrer(){
  labPost('/api/profile_save.php',{
    bio:document.getElementById('bio').value,
    localisation:document.getElementById('localisation').value,
    lien:document.getElementById('lien').value
  }).then(d=>{
    document.getElementById('msg').innerHTML = d.ok
      ? '<div class="toast ok">'+PRF.saved+'</div>'
      : '<div class="toast err">'+(d.message||'Erreur')+'</div>';
  });
}
document.getElementById('btn-saveprofil').addEventListener('click', enregistrer);
</script>

<!-- Mémo E2E : toute la crypto est ici, côté navigateur -->
<script src="/js/e2e-memo.js"></script>
<script nonce="<?= nonce() ?>">
let _vaultKeyB64 = null; // vault_key déverrouillée, en mémoire de page uniquement
const memoMsg = (ok, txt) => { document.getElementById('msgmemo').innerHTML =
  '<div class="toast '+(ok?'ok':'err')+'">'+txt+'</div>'; };
const show = (id, on) => document.getElementById(id).style.display = on ? 'block' : 'none';

async function memoCreer(btn){
  const pw=document.getElementById('c-pw').value, pass=document.getElementById('c-pass').value, memo=document.getElementById('c-memo').value;
  if(!pw||!pass||!memo){ return memoMsg(false,PRF.required); }
  // Garde-fou : passphrase de récupération FORTE (≥4 mots, ≥16 car.) — c'est le wrap volable/bruteforçable offline
  const mots = pass.trim().split(/\s+/).filter(Boolean);
  if(mots.length < 4 || pass.trim().length < 16){
    return memoMsg(false,PRF.weakpass);
  }
  btn.disabled=true; memoMsg(true,'Chiffrement local en cours…');
  try{
    const blobs = await E2EMemo.createVault(pw, pass, memo);
    const d = await labPost('/api/memo_vault.php', blobs);
    if(!d.ok){ throw new Error(d.message||'Erreur serveur'); }
    memoMsg(true,PRF.created);
    show('memo-create',false); _vaultKeyB64=null; show('memo-locked',true);
  }catch(e){ memoMsg(false,e.message); } finally{ btn.disabled=false; }
}

async function _ouvrir(secret, which, btn){
  btn.disabled=true; memoMsg(true,PRF.deriving);
  try{
    const r = await fetch('/api/memo_vault.php').then(x=>x.json());
    if(!r.ok||!r.exists){ throw new Error('Coffre introuvable.'); }
    const out = await E2EMemo.unlock(secret, r.vault, which);
    _vaultKeyB64 = out.vaultKeyB64;
    document.getElementById('o-memo').value = out.memo;
    show('memo-locked',false); show('memo-recover',false); show('memo-open',true);
    memoMsg(true,PRF.decrypted);
  }catch(e){
    memoMsg(false, e.message==='secret_incorrect' ? 'Secret incorrect.' : e.message);
  }finally{ btn.disabled=false; }
}
const memoDeverrouiller = (btn) => _ouvrir(document.getElementById('u-pw').value,'pw',btn);
const memoRecuperer     = (btn) => _ouvrir(document.getElementById('r-pass').value,'rec',btn);

async function memoEnregistrer(btn){
  if(!_vaultKeyB64){ return memoMsg(false,PRF.locked); }
  btn.disabled=true;
  try{
    const upd = await E2EMemo.reEncryptMemo(_vaultKeyB64, document.getElementById('o-memo').value);
    const d = await labPost('/api/memo_vault.php', {action:'update_memo', memo_iv:upd.memo_iv, memo_ct:upd.memo_ct});
    memoMsg(d.ok, d.ok ? PRF.saved2 : (d.message||'Erreur'));
  }catch(e){ memoMsg(false,e.message); } finally{ btn.disabled=false; }
}
function memoVerrouiller(){
  _vaultKeyB64=null; document.getElementById('o-memo').value='';
  show('memo-open',false); show('memo-locked',true); memoMsg(true,PRF.locked);
}
document.getElementById('btn-memocreer').addEventListener('click', function(){ memoCreer(this); });
document.getElementById('btn-memodev').addEventListener('click', function(){ memoDeverrouiller(this); });
document.getElementById('btn-memorecup').addEventListener('click', function(){ memoRecuperer(this); });
document.getElementById('btn-memosave').addEventListener('click', function(){ memoEnregistrer(this); });
document.getElementById('btn-memolock').addEventListener('click', memoVerrouiller);
document.getElementById('btn-memoforgot').addEventListener('click', function(){ document.getElementById('memo-recover').style.display='block'; this.style.display='none'; });
</script>
<?php render_footer(); ?>
