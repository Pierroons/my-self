// Hero Descartes — pixelisation runtime du portrait Frans Hals
// Posterize en 7 niveaux de bleu MySelf, fond extrait par flood-fill,
// upscale en pixel art net via image-rendering: pixelated.
// L'algorithme tourne une fois au chargement de l'image source. Aucun lag.
(function () {
  var canvas = document.getElementById('descartes-pixel');
  if (!canvas || !canvas.getContext) return;
  var img = new Image();
  img.onerror = function () {
    // Fallback debug visible : signale dans la console + affiche un placeholder
    try { console.warn('[MySelf hero] image source introuvable :', img.src); } catch (e) {}
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#1a2028';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#7ab7ff';
    ctx.font = '14px system-ui, sans-serif';
    ctx.fillText('image source introuvable', 20, 30);
  };
  img.onload = function () {
    var ctx = canvas.getContext('2d');
    var W = canvas.width, H = canvas.height;
    var px = 8;
    var sw = Math.round(W / px), sh = Math.round(H / px);
    var tmp = document.createElement('canvas');
    tmp.width = sw; tmp.height = sh;
    var tctx = tmp.getContext('2d');
    tctx.imageSmoothingEnabled = true;
    var ar = img.width / img.height;
    var targetAr = W / H;
    var dw, dh, dx, dy;
    if (ar > targetAr) { dh = sh; dw = sh * ar; dx = (sw - dw) / 2; dy = 0; }
    else { dw = sw; dh = sw / ar; dx = 0; dy = (sh - dh) / 2 - sh * 0.05; }
    tctx.drawImage(img, dx, dy, dw, dh);

    var id = tctx.getImageData(0, 0, sw, sh);
    var d = id.data;
    function lumAt(x, y) {
      var i = (y * sw + x) * 4;
      return (d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114) / 255;
    }
    var corners = [lumAt(1, 1), lumAt(sw - 2, 1), lumAt(1, sh - 2), lumAt(sw - 2, sh - 2)];
    var bgLum = (corners[0] + corners[1] + corners[2] + corners[3]) / 4;
    var palette = [
      [8, 14, 24], [22, 38, 65], [40, 66, 110], [65, 100, 160],
      [95, 140, 210], [122, 183, 255], [165, 205, 245]
    ];
    var isBg = new Uint8Array(sw * sh);
    var stack = [];
    var tol = 0.18;
    function tryPush(x, y) {
      if (x < 0 || x >= sw || y < 0 || y >= sh) return;
      var idx = y * sw + x;
      if (isBg[idx]) return;
      if (Math.abs(lumAt(x, y) - bgLum) < tol) {
        isBg[idx] = 1;
        stack.push(x, y);
      }
    }
    for (var x = 0; x < sw; x++) { tryPush(x, 0); tryPush(x, sh - 1); }
    for (var y = 0; y < sh; y++) { tryPush(0, y); tryPush(sw - 1, y); }
    while (stack.length) {
      var sy = stack.pop(), sx = stack.pop();
      tryPush(sx + 1, sy); tryPush(sx - 1, sy); tryPush(sx, sy + 1); tryPush(sx, sy - 1);
    }
    for (var py = 0; py < sh; py++) {
      for (var px2 = 0; px2 < sw; px2++) {
        var i = (py * sw + px2) * 4;
        var idx2 = py * sw + px2;
        if (isBg[idx2]) { d[i + 3] = 0; continue; }
        var lum = (d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114) / 255;
        var palIdx = Math.min(palette.length - 1, Math.floor(lum * palette.length));
        d[i] = palette[palIdx][0];
        d[i + 1] = palette[palIdx][1];
        d[i + 2] = palette[palIdx][2];
        d[i + 3] = 255;
      }
    }
    tctx.putImageData(id, 0, 0);
    ctx.imageSmoothingEnabled = false;
    ctx.clearRect(0, 0, W, H);
    ctx.drawImage(tmp, 0, 0, W, H);
  };
  img.src = "/assets/descartes-hals.jpg";
})();

// Environnement dev (hostname en "dev.<domaine>") : réécrit les liens
// <sous-domaine>.<domaine> vers dev-<sous-domaine>.<domaine> pour rester
// dans l'env dev (Basic Auth, branche develop, prod intacte).
// Convention DNS niveau 2 = tiret. Domaine dérivé du hostname, aucun effet en prod.
(function rewriteDevLinks() {
  var m = location.hostname.match(/^dev\.(.+)$/i);
  if (!m) return;
  var base = m[1];
  var esc = base.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  var re = new RegExp("^(https?://)(?!dev-)([a-z0-9-]+)\\." + esc + "(?=[/:?#]|$)", "i");
  document.querySelectorAll('a[href*="' + base + '"]').forEach(function (a) {
    a.href = a.href.replace(re, "$1dev-$2." + base);
  });
})();
