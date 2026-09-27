/* ============================================================
 * assets/js/modules/render.js — 虚拟影像渲染模块
 * 以 patient/序列种子确定性生成仿真医学切片（CT 横断/冠状/矢状、
 * DR 平片、US），返回 ImageData（灰度编码到 0..255，伪 HU 全域）。
 * ============================================================ */
(function (global) {
    'use strict';

    var BASE = 512;
    var HU_MIN = -1000, HU_MAX = 1500;

    function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
    function huToByte(hu) { return clamp(Math.round((hu - HU_MIN) / (HU_MAX - HU_MIN) * 255), 0, 255); }
    function hashStr(str) {
        var h = 2166136261 >>> 0; str = String(str);
        for (var i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; }
        return h >>> 0;
    }
    function mulberry32(a) {
        a = a >>> 0;
        return function () {
            a |= 0; a = (a + 0x6D2B79F5) | 0;
            var t = Math.imul(a ^ (a >>> 15), 1 | a);
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }
    function makeNoise(seed) {
        var g = 64, tab = new Float32Array(g * g), r = mulberry32(seed);
        for (var i = 0; i < g * g; i++) tab[i] = r();
        return function (x, y) {
            x = x * g; y = y * g;
            var xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
            var x0 = ((xi % g) + g) % g, y0 = ((yi % g) + g) % g, x1 = (x0 + 1) % g, y1 = (y0 + 1) % g;
            var v00 = tab[y0 * g + x0], v10 = tab[y0 * g + x1], v01 = tab[y1 * g + x0], v11 = tab[y1 * g + x1];
            var a = v00 + (v10 - v00) * xf, b = v01 + (v11 - v01) * xf;
            return a + (b - a) * yf;
        };
    }
    function fbm(n, x, y, oct) { var s = 0, amp = .5, f = 1; for (var i = 0; i < oct; i++) { s += n(x * f, y * f) * amp; f *= 2; amp *= .5; } return s; }
    function ell(nx, ny, cx, cy, rx, ry) { var dx = (nx - cx) / rx, dy = (ny - cy) / ry; return dx * dx + dy * dy; }

    function axial(nx, ny, noise, p, lesion, on) {
        var body = ell(nx, ny, .5, .5, .36, .30);
        if (body > 1) return HU_MIN + 250 * fbm(noise, nx, ny, 3);
        var dist = Math.sqrt(body), hu = 45;
        if (dist > .80) hu = -95;
        if (dist > .96) hu = 60;
        var ls = .78 + .22 * Math.sin(Math.PI * clamp(p * 1.3, 0, 1));
        var lL = ell(nx, ny, .34, .50, .15 * ls, .20 * ls), lR = ell(nx, ny, .66, .50, .15 * ls, .20 * ls);
        if ((lL < 1 || lR < 1) && dist < .86) hu = -820 + 120 * fbm(noise, nx * 3, ny * 3, 4);
        if (Math.abs(nx - .5) < .085 && Math.abs(ny - .52) < .16) hu = 40 + 20 * fbm(noise, nx, ny, 3);
        if (ell(nx, ny, .44, .55, .09, .10) < 1) hu = 55;
        var sp = ell(nx, ny, .5, .76, .055, .05);
        if (sp < 1) hu = 380 - 260 * Math.sqrt(sp);
        if (ell(nx, ny, .5, .76, .02, .018) < 1) hu = 900;
        if (ell(nx, ny, .5, .245, .045, .016) < 1) hu = 500;
        if (on) { var q = ell(nx, ny, lesion.x, lesion.y, lesion.r, lesion.r); if (q < 1) hu = 30 + 40 * (1 - Math.sqrt(q)); }
        return hu;
    }
    function coronal(nx, ny, noise, p, lesion, on) {
        var body = ell(nx, ny, .5, .52, .40, .40);
        if (body > 1) return HU_MIN + 250 * fbm(noise, nx, ny, 3);
        var dist = Math.sqrt(body), hu = 45;
        if (dist > .86) hu = -95;
        var ls = .7 + .3 * Math.sin(Math.PI * clamp((1 - p) * 1.2, 0, 1));
        var lL = ell(nx, ny, .33, .40, .15 * ls, .22 * ls), lR = ell(nx, ny, .67, .40, .15 * ls, .22 * ls);
        if ((lL < 1 || lR < 1) && ny < .66) hu = -800 + 130 * fbm(noise, nx * 3, ny * 3, 4);
        if (Math.abs(nx - .5) < .07 && ny < .72) hu = 35 + 25 * fbm(noise, nx, ny, 3);
        if (ny > .70 - .06 * Math.sin(Math.PI * nx)) hu = 40 + 30 * fbm(noise, nx, ny, 3);
        if (ell(nx, ny, .36, .80, .16, .12) < 1) hu = 60;
        if (Math.abs(nx - .5) < .035 && ny > .30) hu = 400;
        if (on && ny < .6) { var q = ell(nx, ny, lesion.x, .36, lesion.r, lesion.r); if (q < 1) hu = 30 + 40 * (1 - Math.sqrt(q)); }
        return hu;
    }
    function sagittal(nx, ny, noise, p, lesion, on) {
        var body = ell(nx, ny, .5, .5, .30, .41);
        if (body > 1) return HU_MIN + 250 * fbm(noise, nx, ny, 3);
        var dist = Math.sqrt(body), hu = 45;
        if (dist > .86) hu = -95;
        if (ell(nx, ny, .40, .42, .17, .24) < 1 && ny < .7) hu = -800 + 130 * fbm(noise, nx * 3, ny * 3, 4);
        var spX = .72 + .05 * Math.sin(ny * 2.4);
        if (Math.abs(nx - spX) < .04) hu = 420 - 200 * Math.exp(-(ny - .5) * (ny - .5) * 20);
        if (ny > .72) hu = 45 + 30 * fbm(noise, nx, ny, 3);
        if (ell(nx, ny, .42, .80, .16, .11) < 1) hu = 62;
        if (on && ny < .62) { var q = ell(nx, .40, lesion.y, lesion.x, lesion.r, lesion.r); if (q < 1) hu = 30 + 40 * (1 - Math.sqrt(q)); }
        return hu;
    }
    function dr(nx, ny, noise, p, lesion, on) {
        var body = ell(nx, ny, .5, .52, .40, .47);
        if (body > 1) return -950 + 80 * fbm(noise, nx, ny, 3);
        var hu = -500;
        var lL = ell(nx, ny, .33, .44, .17, .26), lR = ell(nx, ny, .67, .44, .17, .26);
        if (lL < 1 || lR < 1) hu = -750 + 220 * fbm(noise, nx * 3, ny * 3, 4);
        if (Math.abs(nx - .5) < .075 && ny < .78) hu = 60;
        if (ell(nx, ny, .56, .60, .11, .13) < 1) hu = 90;
        var dia = .78 - .05 * Math.sin(Math.PI * nx);
        if (ny > dia) hu = 80 + 60 * fbm(noise, nx, ny, 3);
        if (Math.abs(nx - .5) < .03 && ny > .25) hu = 380;
        var rib = (ny - .24) / .055;
        if (rib > 0 && ((rib - Math.floor(rib)) < .30) && Math.abs(nx - .5) > .10 && body < .98) hu += 260;
        if (on && (lL < 1 || lR < 1)) { var q = ell(nx, ny, lesion.x, lesion.y, lesion.r * .8, lesion.r * .8); if (q < 1) hu = -300 + 300 * (1 - Math.sqrt(q)); }
        return hu;
    }
    function us(nx, ny, noise, rnd, p, lesion, on) {
        var dx = nx - .5, dy = ny - .05, r = Math.sqrt(dx * dx + dy * dy), ang = Math.atan2(dx, dy);
        if (r > .88 || Math.abs(ang) > .62) return -950;
        var depth = r / .88, gain = 120 - 90 * depth;
        var hu = gain + (rnd() - .5) * 220 + fbm(noise, nx * 6, ny * 6, 3) * 120 - 350;
        if (on) { var q = ell(nx, ny, lesion.x, .45, lesion.r, lesion.r); if (q < 1) hu = -400 + 200 * (1 - Math.sqrt(q)); }
        return hu;
    }

    function makeSlice(orientation, seed, idx, total) {
        orientation = String(orientation || 'AXIAL').toUpperCase();
        var img = new ImageData(BASE, BASE), d = img.data;
        var p = total > 1 ? idx / (total - 1) : 0;
        var rnd = mulberry32(hashStr(seed + '#' + idx));
        var noise = makeNoise(hashStr(seed) ^ (idx * 2654435761));
        var nr = mulberry32(hashStr(seed + '@lesion'));
        var lesion = { x: .32 + nr() * .36, y: .30 + nr() * .40, r: .03 + nr() * .05 };
        var on = (p > .35 && p < .75);
        for (var y = 0; y < BASE; y++) {
            var ny = (y + .5) / BASE;
            for (var x = 0; x < BASE; x++) {
                var nx = (x + .5) / BASE, hu;
                if (orientation === 'US') hu = us(nx, ny, noise, rnd, p, lesion, on);
                else if (orientation === 'PA' || orientation === 'DR') hu = dr(nx, ny, noise, p, lesion, on);
                else if (orientation === 'CORONAL') hu = coronal(nx, ny, noise, p, lesion, on);
                else if (orientation === 'SAGITTAL') hu = sagittal(nx, ny, noise, p, lesion, on);
                else hu = axial(nx, ny, noise, p, lesion, on);
                var b = huToByte(hu), i4 = (y * BASE + x) * 4;
                d[i4] = d[i4 + 1] = d[i4 + 2] = b; d[i4 + 3] = 255;
            }
        }
        return img;
    }

    global.PvRender = { BASE: BASE, HU_MIN: HU_MIN, HU_MAX: HU_MAX, makeSlice: makeSlice };
})(window);
