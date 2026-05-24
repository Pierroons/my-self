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
          <p style="margin-top:0"><strong>Réputation</strong> · <span style="color:<?= $repColor ?>;font-weight:700">★ <?= $repScore ?>/30</span>
            <?php if ($rep['banned']): ?><span style="color:#d96459"> · suspendu</span><?php endif; ?>
            <?php if (!$rep['voting_rights']): ?><span style="color:#d4a056"> · sans droit de vote</span><?php endif; ?>
          </p>
          <div style="height:8px;background:var(--elev);border-radius:4px;overflow:hidden;margin-bottom:14px">
            <div style="height:100%;width:<?= $repPct ?>%;background:<?= $repColor ?>"></div>
          </div>
          <?php if ($account && !$isSelf): ?>
            <div id="vmsg"></div>
            <button class="btn" onclick="voteMembre(<?= $memberId ?>,1)" <?= (!$canVote || $mine !== null) ? 'disabled' : '' ?> style="padding:6px 14px">▲ Soutenir</button>
            <button class="btn btn-ghost" onclick="voteMembre(<?= $memberId ?>,-1)" <?= (!$canVote || $mine !== null) ? 'disabled' : '' ?> style="padding:6px 14px">▼ Signaler</button>
            <?php if ($mine !== null): ?><span class="muted" style="margin-left:8px">Tu as déjà voté (<?= $mine === 1 ? '▲' : '▼' ?>)</span><?php endif; ?>
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
        <script>
        function voteMembre(id, value){
          labPost('/api/vote.php',{target_type:'member',target_id:id,value:value}).then(d=>{
            if(d.ok){ location.reload(); }
            else{ document.getElementById('vmsg').innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>'; }
          });
        }
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
$repLabel = $repScore >= 25 ? 'Confiance' : ($repScore >= 15 ? 'Membre établi' : ($repScore >= 5 ? 'Réputation fragile' : 'Sous surveillance'));
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

render_header('Mon espace', $account);
?>
<h1>Mon espace — @<?= h($account['username']) ?></h1>

<!-- Panel modération -->
<div class="card">
  <h2 style="margin-top:0">⚖️ Mon état de modération</h2>
  <p style="margin:0 0 6px"><span style="color:<?= $repColor ?>;font-weight:700;font-size:18px">★ <?= $repScore ?>/30</span>
     <span class="cat-pill" style="color:<?= $repColor ?>;border-color:<?= $repColor ?>;margin-left:8px"><?= $repLabel ?></span></p>
  <div style="height:9px;background:var(--elev);border-radius:5px;overflow:hidden;margin:8px 0 14px">
    <div style="height:100%;width:<?= $repPct ?>%;background:<?= $repColor ?>"></div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;font-size:13px">
    <div><span class="muted">Droit de vote</span><br><strong style="color:<?= $canVote ? 'var(--acc)' : 'var(--warn)' ?>"><?= $canVote ? '✓ actif' : '✗ '. ($rep['voting_rights'] ? 'restreint' : 'retiré') ?></strong></div>
    <div><span class="muted">Strikes</span><br><strong><?= $rep['strikes'] ?>/3</strong></div>
    <div><span class="muted">Statut</span><br><strong style="color:<?= $rep['banned'] ? 'var(--danger)' : 'var(--acc)' ?>"><?= $rep['banned'] ? 'suspendu' : 'actif' ?></strong></div>
  </div>
  <?php if (!$canVote && $whyNot): ?>
    <p class="muted" style="margin:12px 0 0">⚠ <?= h($whyNot) ?></p>
  <?php endif; ?>
</div>

<!-- Panel activité -->
<div class="card">
  <h2 style="margin-top:0">📊 Mon activité</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;font-size:13px">
    <div><span class="muted">Sujets ouverts</span><br><strong style="font-size:18px"><?= $nbThreads ?></strong></div>
    <div><span class="muted">Messages</span><br><strong style="font-size:18px"><?= $nbPosts ?></strong></div>
    <div><span class="muted">Votes émis</span><br><strong style="font-size:18px"><?= $nbVotesEmis ?></strong></div>
    <div><span class="muted">Votes reçus</span><br><strong style="font-size:18px"><?= $nbVotesRecus ?></strong></div>
  </div>
  <p style="margin:12px 0 0"><a href="/profile.php?u=<?= h($account['username']) ?>">Voir mon profil public →</a></p>
</div>

<h2 style="margin-top:24px">✏️ Modifier mon profil <span class="cat-pill" style="color:var(--warn);border-color:var(--warn)">🌐 public</span></h2>
<p class="muted" style="background:rgba(212,160,86,.10);border:1px solid rgba(212,160,86,.35);border-radius:8px;padding:10px 13px">
  🌐 <strong>Profil public</strong> — bio, localisation et lien sont <strong>visibles de tous</strong> (page <code>/profile.php?u=…</code>, même sans compte).
  Le chiffrement at-rest SelfDataGuard protège contre un <strong>vol de la base</strong>, pas contre l'affichage public : n'y mets <strong>aucune donnée sensible</strong>.
  Pour une note privée, utilise le <strong>mémo E2E</strong> ci-dessous.</p>
<div class="card" style="max-width:560px">
  <div id="msg"></div>
  <div class="field"><label>Bio</label><textarea id="bio" rows="3"><?= h($p['bio']) ?></textarea></div>
  <div class="field"><label>Localisation</label><input id="localisation" value="<?= h($p['localisation']) ?>"></div>
  <div class="field"><label>Lien (site, blog…)</label><input id="lien" value="<?= h($p['lien']) ?>" placeholder="https://…"></div>
  <button class="btn" onclick="enregistrer()">Enregistrer le profil public</button>
</div>

<?php $vaultExiste = \Pierroons\MySelfLab\MemoVault::exists($pdo, $myId); ?>
<h2 style="margin-top:24px">🎯 Mémo perso — chiffré de bout en bout (E2E)</h2>
<p class="muted">🔒 Chiffré <strong>dans ton navigateur</strong> par <strong>SelfDataGuard E2E</strong> : le serveur ne reçoit que des blobs, il ne détient <strong>aucune clé</strong>.
   Même l'administrateur ne peut pas le lire. C'est le <strong>secret à exfiltrer</strong> pour la red team — un dump de la base ne donne rien sans ton mot de passe.</p>
<div class="card" style="max-width:600px;border-color:var(--danger)">
  <div id="msgmemo"></div>

  <!-- État A : aucun coffre → création -->
  <div id="memo-create" style="display:<?= $vaultExiste ? 'none' : 'block' ?>">
    <p class="muted" style="margin-top:0">Crée ton coffre chiffré. Le <strong>mot de passe</strong> sert au quotidien ; la <strong>passphrase de récupération</strong> te permet de retrouver le mémo si tu perds ton mot de passe.</p>
    <div class="field"><label>Ton mot de passe</label><input type="password" id="c-pw" autocomplete="off"></div>
    <div class="field"><label>Passphrase de récupération <span style="text-transform:none;font-weight:400;color:var(--muted)">— réutilise celle reçue à l'inscription</span></label><input type="password" id="c-pass" autocomplete="off" placeholder="ex. correct horse battery staple"><span class="muted" style="font-size:11px">Au moins 4 mots. C'est ton unique filet si tu perds ton mot de passe — il doit être fort (idéalement ta passphrase SelfRecover).</span></div>
    <div class="field"><label>Mémo</label><textarea id="c-memo" rows="4" placeholder="Ex. FLAG-coaxis-2026 : mon RIB FR76…"></textarea></div>
    <button class="btn" onclick="memoCreer(this)">Créer le coffre (chiffrement local)</button>
  </div>

  <!-- État B : coffre existant → déverrouillage -->
  <div id="memo-locked" style="display:<?= $vaultExiste ? 'block' : 'none' ?>">
    <p style="margin-top:0">🔒 Coffre verrouillé.</p>
    <div class="field"><label>Mot de passe</label><input type="password" id="u-pw" autocomplete="off"></div>
    <button class="btn" onclick="memoDeverrouiller(this)">Déverrouiller</button>
    <button class="btn btn-ghost" onclick="document.getElementById('memo-recover').style.display='block';this.style.display='none'">Mot de passe oublié ?</button>
    <div id="memo-recover" style="display:none;margin-top:12px">
      <div class="field"><label>Passphrase de secours</label><input type="password" id="r-pass" autocomplete="off"></div>
      <button class="btn" onclick="memoRecuperer(this)">Récupérer avec la passphrase</button>
    </div>
  </div>

  <!-- État C : déverrouillé → édition -->
  <div id="memo-open" style="display:none">
    <div class="field"><label>Mémo (déchiffré localement)</label><textarea id="o-memo" rows="4"></textarea></div>
    <button class="btn" onclick="memoEnregistrer(this)">Enregistrer (re-chiffré local)</button>
    <button class="btn btn-ghost" onclick="memoVerrouiller()">Verrouiller</button>
  </div>
</div>
<script>
function enregistrer(){
  labPost('/api/profile_save.php',{
    bio:document.getElementById('bio').value,
    localisation:document.getElementById('localisation').value,
    lien:document.getElementById('lien').value
  }).then(d=>{
    document.getElementById('msg').innerHTML = d.ok
      ? '<div class="toast ok">Profil enregistré (chiffré).</div>'
      : '<div class="toast err">'+(d.message||'Erreur')+'</div>';
  });
}
</script>

<!-- Mémo E2E : toute la crypto est ici, côté navigateur -->
<script src="/js/e2e-memo.js"></script>
<script>
let _vaultKeyB64 = null; // vault_key déverrouillée, en mémoire de page uniquement
const memoMsg = (ok, txt) => { document.getElementById('msgmemo').innerHTML =
  '<div class="toast '+(ok?'ok':'err')+'">'+txt+'</div>'; };
const show = (id, on) => document.getElementById(id).style.display = on ? 'block' : 'none';

async function memoCreer(btn){
  const pw=document.getElementById('c-pw').value, pass=document.getElementById('c-pass').value, memo=document.getElementById('c-memo').value;
  if(!pw||!pass||!memo){ return memoMsg(false,'Mot de passe, passphrase et mémo requis.'); }
  // Garde-fou : passphrase de récupération FORTE (≥4 mots, ≥16 car.) — c'est le wrap volable/bruteforçable offline
  const mots = pass.trim().split(/\s+/).filter(Boolean);
  if(mots.length < 4 || pass.trim().length < 16){
    return memoMsg(false,'Passphrase de récupération trop faible : au moins 4 mots (réutilise celle de ton inscription). C\'est ce qui protège ton mémo si tu perds ton mot de passe.');
  }
  btn.disabled=true; memoMsg(true,'Chiffrement local en cours…');
  try{
    const blobs = await E2EMemo.createVault(pw, pass, memo);
    const d = await labPost('/api/memo_vault.php', blobs);
    if(!d.ok){ throw new Error(d.message||'Erreur serveur'); }
    memoMsg(true,'Coffre créé et chiffré localement. Le serveur n\'a reçu que des blobs.');
    show('memo-create',false); _vaultKeyB64=null; show('memo-locked',true);
  }catch(e){ memoMsg(false,e.message); } finally{ btn.disabled=false; }
}

async function _ouvrir(secret, which, btn){
  btn.disabled=true; memoMsg(true,'Dérivation de la clé (PBKDF2)…');
  try{
    const r = await fetch('/api/memo_vault.php').then(x=>x.json());
    if(!r.ok||!r.exists){ throw new Error('Coffre introuvable.'); }
    const out = await E2EMemo.unlock(secret, r.vault, which);
    _vaultKeyB64 = out.vaultKeyB64;
    document.getElementById('o-memo').value = out.memo;
    show('memo-locked',false); show('memo-recover',false); show('memo-open',true);
    memoMsg(true,'Déchiffré localement. La clé reste dans cette page, jamais envoyée.');
  }catch(e){
    memoMsg(false, e.message==='secret_incorrect' ? 'Secret incorrect.' : e.message);
  }finally{ btn.disabled=false; }
}
const memoDeverrouiller = (btn) => _ouvrir(document.getElementById('u-pw').value,'pw',btn);
const memoRecuperer     = (btn) => _ouvrir(document.getElementById('r-pass').value,'rec',btn);

async function memoEnregistrer(btn){
  if(!_vaultKeyB64){ return memoMsg(false,'Coffre verrouillé.'); }
  btn.disabled=true;
  try{
    const upd = await E2EMemo.reEncryptMemo(_vaultKeyB64, document.getElementById('o-memo').value);
    const d = await labPost('/api/memo_vault.php', {action:'update_memo', memo_iv:upd.memo_iv, memo_ct:upd.memo_ct});
    memoMsg(d.ok, d.ok ? 'Mémo re-chiffré et enregistré.' : (d.message||'Erreur'));
  }catch(e){ memoMsg(false,e.message); } finally{ btn.disabled=false; }
}
function memoVerrouiller(){
  _vaultKeyB64=null; document.getElementById('o-memo').value='';
  show('memo-open',false); show('memo-locked',true); memoMsg(true,'Coffre verrouillé.');
}
</script>
<?php render_footer(); ?>
