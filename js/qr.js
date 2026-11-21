/* ==========================================================================
   qr.js — tiny dependency-free QR code encoder (byte mode, versions 1–10, ECC L/M/Q/H)
   Usage:  QR.matrix('text', 'H')            -> 2D array of booleans (true = dark)
           QR.svg('text', { ecl, logo, quiet, fg, bg })  -> SVG markup string
   Implements ISO/IEC 18004: Reed–Solomon over GF(256), block interleaving,
   function patterns, format/version info and penalty-based mask selection.
   ========================================================================== */
(function (global) {
  'use strict';

  var ECL = { L: 0, M: 1, Q: 2, H: 3 };
  var FORMAT_BITS = { L: 1, M: 0, Q: 3, H: 2 };
  // [ecl][version] — index 0 unused (versions 1–10)
  var ECC_PER_BLOCK = [
    [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18],
    [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26],
    [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24],
    [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28]
  ];
  var NUM_BLOCKS = [
    [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4],
    [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5],
    [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8],
    [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8]
  ];
  var MAX_VERSION = 10;

  function rawDataModules(ver) {
    var r = (16 * ver + 128) * ver + 64;
    if (ver >= 2) { var na = Math.floor(ver / 7) + 2; r -= (25 * na - 10) * na - 55; if (ver >= 7) r -= 36; }
    return r;
  }
  function dataCodewords(ver, e) { return Math.floor(rawDataModules(ver) / 8) - ECC_PER_BLOCK[e][ver] * NUM_BLOCKS[e][ver]; }

  /* ---- GF(256) Reed–Solomon ---- */
  function gfMul(x, y) {
    var z = 0;
    for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; }
    return z & 0xFF;
  }
  function rsDivisor(degree) {
    var res = [];
    for (var i = 0; i < degree - 1; i++) res.push(0);
    res.push(1);
    var root = 1;
    for (i = 0; i < degree; i++) {
      for (var j = 0; j < res.length; j++) { res[j] = gfMul(res[j], root); if (j + 1 < res.length) res[j] ^= res[j + 1]; }
      root = gfMul(root, 0x02);
    }
    return res;
  }
  function rsRemainder(data, div) {
    var res = div.map(function () { return 0; });
    data.forEach(function (b) {
      var f = b ^ res.shift();
      res.push(0);
      div.forEach(function (c, i) { res[i] ^= gfMul(c, f); });
    });
    return res;
  }

  function utf8(str) {
    var out = [], s = unescape(encodeURIComponent(str));
    for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i));
    return out;
  }

  function encode(text, eclName) {
    var e = ECL[eclName || 'M'], bytes = utf8(text), ver, cap;
    for (ver = 1; ver <= MAX_VERSION; ver++) {
      var ccBits = ver <= 9 ? 8 : 16;
      cap = dataCodewords(ver, e) * 8;
      if (4 + ccBits + bytes.length * 8 <= cap) break;
    }
    if (ver > MAX_VERSION) throw new Error('QR: text too long');

    // bit stream: mode 0100 (byte), count, data, terminator, pad
    var bits = [];
    var push = function (val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
    push(4, 4); push(bytes.length, ver <= 9 ? 8 : 16);
    bytes.forEach(function (b) { push(b, 8); });
    push(0, Math.min(4, cap - bits.length));
    push(0, (8 - bits.length % 8) % 8);
    for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) push(pad, 8);
    var data = [];
    for (var i = 0; i < bits.length; i += 8) { var b = 0; for (var k = 0; k < 8; k++) b = (b << 1) | bits[i + k]; data.push(b); }

    // split into blocks, add ECC, interleave
    var nb = NUM_BLOCKS[e][ver], eccLen = ECC_PER_BLOCK[e][ver], raw = Math.floor(rawDataModules(ver) / 8);
    var nShort = nb - raw % nb, shortLen = Math.floor(raw / nb), div = rsDivisor(eccLen), blocks = [], pos = 0;
    for (i = 0; i < nb; i++) {
      var dat = data.slice(pos, pos + shortLen - eccLen + (i < nShort ? 0 : 1));
      pos += dat.length;
      var ecc = rsRemainder(dat, div);
      if (i < nShort) dat.push(0);
      blocks.push(dat.concat(ecc));
    }
    var codewords = [];
    for (i = 0; i < blocks[0].length; i++) for (var j = 0; j < nb; j++) if (i !== shortLen - eccLen || j >= nShort) codewords.push(blocks[j][i]);

    return build(ver, eclName || 'M', codewords);
  }

  function build(ver, eclName, codewords) {
    var size = ver * 4 + 17, m = [], fn = [], x, y, i;
    for (y = 0; y < size; y++) { m.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
    var set = function (x, y, dark) { m[y][x] = dark; fn[y][x] = true; };

    // timing patterns
    for (i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    // finder patterns (+ separators)
    [[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
      for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
        var xx = c[0] + dx, yy = c[1] + dy, d = Math.max(Math.abs(dx), Math.abs(dy));
        if (xx >= 0 && xx < size && yy >= 0 && yy < size) set(xx, yy, d !== 2 && d !== 4);
      }
    });
    // alignment patterns
    var al = [];
    if (ver > 1) {
      var na = Math.floor(ver / 7) + 2, step = Math.floor((ver * 8 + na * 3 + 5) / (na * 4 - 4)) * 2;
      al = [6];
      for (var p = size - 7; al.length < na; p -= step) al.splice(1, 0, p);
    }
    al.forEach(function (ay, ai) {
      al.forEach(function (ax, aj) {
        if ((ai === 0 && aj === 0) || (ai === 0 && aj === al.length - 1) || (ai === al.length - 1 && aj === 0)) return;
        for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
      });
    });
    // reserve format areas (real bits drawn after masking) + version info
    drawFormat(0);
    if (ver >= 7) {
      var rem = ver;
      for (i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
      var vbits = (ver << 12) | rem;
      for (i = 0; i < 18; i++) { var bit = ((vbits >>> i) & 1) === 1, a = size - 11 + i % 3, b2 = Math.floor(i / 3); set(a, b2, bit); set(b2, a, bit); }
    }

    // place data in the zig-zag order
    var bi = 0, total = codewords.length * 8;
    for (var right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (var v = 0; v < size; v++) for (var jj = 0; jj < 2; jj++) {
        x = right - jj;
        var up = ((right + 1) & 2) === 0;
        y = up ? size - 1 - v : v;
        if (!fn[y][x] && bi < total) { m[y][x] = ((codewords[bi >>> 3] >>> (7 - (bi & 7))) & 1) === 1; bi++; }
      }
    }

    // choose the mask with the lowest penalty
    var best = 0, bestScore = Infinity;
    for (var mk = 0; mk < 8; mk++) {
      applyMask(mk); drawFormat(mk);
      var sc = penalty();
      if (sc < bestScore) { bestScore = sc; best = mk; }
      applyMask(mk);           // XOR again = undo
    }
    applyMask(best); drawFormat(best);
    return m;

    function drawFormat(mask) {
      var data = (FORMAT_BITS[eclName] << 3) | mask, r = data;
      for (var k = 0; k < 10; k++) r = (r << 1) ^ ((r >>> 9) * 0x537);
      var bits = ((data << 10) | r) ^ 0x5412, g = function (n) { return ((bits >>> n) & 1) === 1; };
      for (k = 0; k <= 5; k++) set(8, k, g(k));
      set(8, 7, g(6)); set(8, 8, g(7)); set(7, 8, g(8));
      for (k = 9; k < 15; k++) set(14 - k, 8, g(k));
      for (k = 0; k < 8; k++) set(size - 1 - k, 8, g(k));
      for (k = 8; k < 15; k++) set(8, size - 15 + k, g(k));
      set(8, size - 8, true);
    }
    function applyMask(mk) {
      for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) {
        if (fn[yy][xx]) continue;
        var inv;
        switch (mk) {
          case 0: inv = (xx + yy) % 2 === 0; break;
          case 1: inv = yy % 2 === 0; break;
          case 2: inv = xx % 3 === 0; break;
          case 3: inv = (xx + yy) % 3 === 0; break;
          case 4: inv = (Math.floor(xx / 3) + Math.floor(yy / 2)) % 2 === 0; break;
          case 5: inv = xx * yy % 2 + xx * yy % 3 === 0; break;
          case 6: inv = (xx * yy % 2 + xx * yy % 3) % 2 === 0; break;
          default: inv = ((xx + yy) % 2 + xx * yy % 3) % 2 === 0;
        }
        if (inv) m[yy][xx] = !m[yy][xx];
      }
    }
    function penalty() {
      var s = 0, dark = 0, r, c, run, k;
      for (r = 0; r < size; r++) {            // rule 1: runs of 5+ in rows / columns
        for (var dir = 0; dir < 2; dir++) {
          run = 1;
          for (c = 1; c < size; c++) {
            var a = dir ? m[c][r] : m[r][c], b = dir ? m[c - 1][r] : m[r][c - 1];
            if (a === b) { run++; if (run === 5) s += 3; else if (run > 5) s++; } else run = 1;
          }
        }
      }
      for (r = 0; r < size - 1; r++) for (c = 0; c < size - 1; c++) {   // rule 2: 2x2 blocks
        var v0 = m[r][c];
        if (v0 === m[r][c + 1] && v0 === m[r + 1][c] && v0 === m[r + 1][c + 1]) s += 3;
      }
      var pat = [true, false, true, true, true, false, true];           // rule 3: finder-like patterns
      for (r = 0; r < size; r++) for (c = 0; c + 7 <= size; c++) {
        var hz = true, vt = true;
        for (k = 0; k < 7; k++) { if (m[r][c + k] !== pat[k]) hz = false; if (m[c + k][r] !== pat[k]) vt = false; }
        if (hz) s += 40; if (vt) s += 40;
      }
      for (r = 0; r < size; r++) for (c = 0; c < size; c++) if (m[r][c]) dark++;   // rule 4: dark balance
      s += Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
      return s;
    }
  }

  function svg(text, o) {
    o = o || {};
    var mat = encode(text, o.ecl || (o.logo ? 'H' : 'M')), n = mat.length, q = o.quiet == null ? 4 : o.quiet, dim = n + q * 2;
    var path = '';
    for (var y = 0; y < n; y++) for (var x = 0; x < n; x++) if (mat[y][x]) path += 'M' + (x + q) + ' ' + (y + q) + 'h1v1h-1z';
    var logo = '';
    if (o.logo) {                                   // centred logo on a light badge (≈ 20% of the width, safe with ECC H)
      var ls = Math.round(n * 0.22), lx = (dim - ls) / 2;
      logo = '<rect x="' + (lx - 0.6) + '" y="' + (lx - 0.6) + '" width="' + (ls + 1.2) + '" height="' + (ls + 1.2) + '" rx="' + (ls / 3) + '" fill="' + (o.bg || '#fff') + '"/>' +
        '<image href="' + o.logo + '" x="' + lx + '" y="' + lx + '" width="' + ls + '" height="' + ls + '" preserveAspectRatio="xMidYMid meet"/>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + dim + ' ' + dim + '" shape-rendering="crispEdges" role="img" aria-label="QR code">' +
      '<rect width="100%" height="100%" fill="' + (o.bg || '#fff') + '"/><path d="' + path + '" fill="' + (o.fg || '#000') + '"/>' + logo + '</svg>';
  }

  global.QR = { matrix: encode, svg: svg };
})(typeof window !== 'undefined' ? window : globalThis);
