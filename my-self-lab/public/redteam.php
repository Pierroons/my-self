<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/redteam.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Redteam;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
$hof = Redteam::hallOfFame($pdo);

$sevColor = [
    'info' => '#9aa9b6', 'faible' => '#6cb6ff', 'moyen' => '#d4a056',
    'eleve' => '#e0824f', 'critique' => '#d96459',
];

$stats = \Pierroons\MySelfLab\Stats::lab($pdo);
render_header(t('title.redteam'), $account);
?>
<style>
.rt-hero{background:linear-gradient(120deg,rgba(63,185,140,.10),rgba(217,100,89,.06));border:1px solid var(--border);border-radius:12px;padding:20px 22px;margin-bottom:20px}
.rt-hero h1{margin:0 0 8px}
.rt-hero p{margin:0;color:var(--txt2);max-width:760px}
.rt-sec{margin-bottom:18px}
.rt-sec h2{display:flex;align-items:center;gap:8px}
.scope{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:680px){.scope{grid-template-columns:1fr}}
.scope .col{border-radius:8px;padding:14px 16px;font-size:13px}
.scope .in{background:rgba(63,185,140,.08);border:1px solid rgba(63,185,140,.3)}
.scope .out{background:rgba(217,100,89,.07);border:1px solid rgba(217,100,89,.3)}
.scope h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.4px}
.scope.in h3,.scope .in h3{color:var(--acc)}
.scope .out h3{color:var(--danger)}
.scope ul{margin:0;padding-left:18px;line-height:1.7}
.flag{background:#0a0f13;border:1px solid var(--warn);border-radius:8px;padding:14px 16px;font-family:ui-monospace,monospace;font-size:13px;margin:10px 0}
.flag .lbl{color:var(--warn);font-weight:700}
.obj-list{list-style:none;padding:0;margin:0}
.obj-list li{padding:8px 0;border-bottom:1px solid var(--border);font-size:13.5px}
.obj-list li:last-child{border-bottom:none}
.ttp{display:inline-block;font-family:ui-monospace,monospace;font-size:10.5px;color:var(--muted);border:1px solid var(--border);border-radius:8px;padding:0 7px;margin-left:6px;white-space:nowrap}
.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:680px){.two{grid-template-columns:1fr}}
.ok-box{background:rgba(63,185,140,.07);border:1px solid rgba(63,185,140,.3);border-radius:8px;padding:14px 16px}
.no-box{background:rgba(217,100,89,.07);border:1px solid rgba(217,100,89,.3);border-radius:8px;padding:14px 16px}
.ok-box h3{color:var(--acc)} .no-box h3{color:var(--danger)}
.ok-box h3,.no-box h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.4px}
.ok-box ul,.no-box ul{margin:0;padding-left:18px;line-height:1.7;font-size:13px}
.safe{background:rgba(108,182,255,.08);border:1px solid rgba(108,182,255,.3);border-radius:8px;padding:14px 16px;font-size:13.5px}
.hof{display:flex;flex-wrap:wrap;gap:10px}
.hof .badge{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:6px 14px;font-size:13px}
.hof .badge .sev{font-size:11px;margin-left:6px}
.rt-form .field input,.rt-form .field textarea,.rt-form .field select{width:100%}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;text-align:center}
@media(max-width:680px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
.stats-grid > div{background:var(--elev);border:1px solid var(--border);border-radius:8px;padding:14px 8px}
.stats-grid .n{display:block;font-size:26px;font-weight:700;color:var(--acc);font-family:ui-monospace,monospace}
.stats-grid .n.flag{color:var(--warn)}
.stats-grid .l{display:block;font-size:11.5px;color:var(--txt2);margin-top:4px;line-height:1.35}
</style>

<div class="rt-hero">
  <h1><?= t('rt.hero.h1') ?></h1>
  <p><?= t('rt.hero.p') ?></p>
</div>

<div class="card rt-sec">
  <h2><?= t('stats.h2') ?></h2>
  <div class="stats-grid">
    <div><span class="n"><?= (int) $stats['jours'] ?></span><span class="l"><?= h(t('stats.days')) ?></span></div>
    <div><span class="n"><?= (int) $stats['repoussees'] ?></span><span class="l"><?= h(t('stats.repelled')) ?></span></div>
    <div><span class="n"><?= (int) $stats['rapports'] ?></span><span class="l"><?= h(t('stats.reports')) ?></span></div>
    <div><span class="n flag"><?= (int) $stats['flags'] ?></span><span class="l"><?= h(t($stats['flags'] > 1 ? 'stats.flags.p' : 'stats.flags')) ?></span></div>
  </div>
  <p class="muted" style="margin:12px 0 0;font-size:12px"><?= h(t('stats.note')) ?></p>
</div>

<div class="card rt-sec">
  <h2><?= t('rt.scope.h2') ?></h2>
  <div class="scope">
    <div class="col in">
      <h3><?= t('rt.scope.in.h3') ?></h3>
      <ul><?= t('rt.scope.in') ?></ul>
    </div>
    <div class="col out">
      <h3><?= t('rt.scope.out.h3') ?></h3>
      <ul><?= t('rt.scope.out') ?></ul>
    </div>
  </div>
  <p class="muted" style="margin:12px 0 0"><?= t('rt.scope.note') ?></p>
</div>

<div class="card rt-sec">
  <h2><?= t('rt.obj.h2') ?></h2>
  <p><?= t('rt.obj.intro') ?></p>
  <div class="flag"><?= t('rt.obj.flag') ?></div>
  <p class="muted"><?= t('rt.obj.note') ?></p>
  <p class="muted" style="margin:0 0 4px"><?= t('rt.obj.refs') ?></p>
  <ul class="obj-list"><?= t('rt.obj.list') ?></ul>
</div>

<div class="rt-sec two">
  <div class="ok-box">
    <h3><?= t('rt.allowed.h3') ?></h3>
    <ul><?= t('rt.allowed') ?></ul>
  </div>
  <div class="no-box">
    <h3><?= t('rt.forbidden.h3') ?></h3>
    <ul><?= t('rt.forbidden') ?></ul>
  </div>
</div>

<div class="card rt-sec">
  <h2><?= t('rt.rate.h2') ?></h2>
  <?= t('rt.rate.body') ?>
</div>

<div class="card rt-sec">
  <h2><?= t('rt.conduct.h2') ?></h2>
  <ul style="margin:0;padding-left:18px;line-height:1.8"><?= t('rt.conduct.body') ?></ul>
</div>

<div class="card rt-sec">
  <h2><?= t('rt.safe.h2') ?></h2>
  <div class="safe"><?= t('rt.safe.body') ?></div>
</div>

<div class="card rt-sec rt-form" id="signaler" style="max-width:640px">
  <h2><?= t('rt.form.h2') ?></h2>
  <p class="muted"><?= t('rt.form.note') ?></p>
  <div id="rtmsg"></div>
  <div class="field"><label><?= h(t('rt.form.handle')) ?></label><input id="handle" maxlength="60" placeholder="<?= h(t('rt.form.handle_ph')) ?>"></div>
  <div class="two" style="gap:12px">
    <div class="field"><label><?= h(t('rt.form.severity')) ?></label>
      <select id="severity">
        <option value="info"><?= h(t('rt.form.sev.info')) ?></option><option value="faible"><?= h(t('rt.form.sev.low')) ?></option>
        <option value="moyen"><?= h(t('rt.form.sev.med')) ?></option><option value="eleve"><?= h(t('rt.form.sev.high')) ?></option>
        <option value="critique"><?= h(t('rt.form.sev.crit')) ?></option>
      </select>
    </div>
    <div class="field"><label><?= h(t('rt.form.target')) ?></label>
      <select id="target">
        <option value="memo"><?= h(t('rt.form.tgt.memo')) ?></option><option value="auth"><?= h(t('rt.form.tgt.auth')) ?></option>
        <option value="dm"><?= h(t('rt.form.tgt.dm')) ?></option><option value="moderation"><?= h(t('rt.form.tgt.mod')) ?></option>
        <option value="web"><?= h(t('rt.form.tgt.web')) ?></option><option value="autre"><?= h(t('rt.form.tgt.other')) ?></option>
      </select>
    </div>
  </div>
  <div class="field"><label><?= h(t('rt.form.title')) ?></label><input id="titre" maxlength="200" placeholder="<?= h(t('rt.form.title_ph')) ?>"></div>
  <div class="field"><label><?= h(t('rt.form.desc')) ?></label><textarea id="description" rows="4" placeholder="<?= h(t('rt.form.desc_ph')) ?>"></textarea></div>
  <div class="field"><label><?= h(t('rt.form.repro')) ?></label><textarea id="repro" rows="4" placeholder="<?= h(t('rt.form.repro_ph')) ?>"></textarea></div>
  <div class="field"><label><?= h(t('rt.form.contact')) ?></label><input id="contact" maxlength="200" placeholder="<?= h(t('rt.form.contact_ph')) ?>"></div>
  <div class="hp"><label><?= h(t('rt.form.honeypot')) ?></label><input id="website" tabindex="-1" autocomplete="off"></div>
  <button class="btn" id="btn-rtsend"><?= h(t('rt.form.send')) ?></button>
</div>

<div class="card rt-sec">
  <p class="muted" style="margin:0"><?= t('rt.prevalence') ?></p>
</div>

<div class="card rt-sec">
  <h2><?= t('rt.hof.h2') ?></h2>
  <?php if (!$hof): ?>
    <p class="muted"><?= t('rt.hof.empty') ?></p>
  <?php else: ?>
    <div class="hof">
      <?php foreach ($hof as $c): ?>
        <span class="badge"><?= h($c['handle']) ?>
          <span class="sev" style="color:<?= $sevColor[$c['sev']] ?? '#9aa9b6' ?>">● <?= h($c['sev']) ?></span>
          <?php if ($c['nb'] > 1): ?><span class="muted">×<?= (int) $c['nb'] ?></span><?php endif; ?>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script nonce="<?= nonce() ?>">
// Messages traduits côté serveur : le JS ne connaît pas la langue courante.
const RT_I18N = <?= json_encode(['ok' => t('rt.js.ok'), 'err' => t('rt.js.err'), 'neterr' => t('rt.js.neterr')], JSON_UNESCAPED_UNICODE) ?>;
function envoyerRapport(){
  const box=document.getElementById('rtmsg');
  const payload={
    handle:document.getElementById('handle').value,
    severity:document.getElementById('severity').value,
    target:document.getElementById('target').value,
    titre:document.getElementById('titre').value,
    description:document.getElementById('description').value,
    repro:document.getElementById('repro').value,
    contact:document.getElementById('contact').value,
    website:document.getElementById('website').value
  };
  // Endpoint public (pas d'auth requise pour un chercheur externe) — pas de CSRF.
  fetch('/api/redteam_report.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        box.innerHTML='<div class="toast ok">'+RT_I18N.ok+'</div>';
        ['titre','description','repro','contact'].forEach(id=>document.getElementById(id).value='');
      }else{
        box.innerHTML='<div class="toast err">'+(d.message||RT_I18N.err)+'</div>';
      }
    }).catch(e=>{box.innerHTML='<div class="toast err">'+RT_I18N.neterr+e.message+'</div>';});
}
document.getElementById('btn-rtsend').addEventListener('click', envoyerRapport);
</script>
<?php render_footer(); ?>
