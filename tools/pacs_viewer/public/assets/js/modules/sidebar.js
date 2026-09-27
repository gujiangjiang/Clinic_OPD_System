/* ============================================================
 * assets/js/modules/sidebar.js — 左侧序列栏模块
 * 默认常驻显示；由工具栏按钮切换 visible/hidden。
 * ============================================================ */
(function (global) {
    'use strict';
    var THUMB = 128;

    function PvSidebar(el) {
        this.el = el;
        this.series = [];
        this.onPick = null;
        this.active = 0;
        this._offscreen = document.createElement('canvas');
        this._offscreen.width = PvRender.BASE; this._offscreen.height = PvRender.BASE;
    }
    PvSidebar.prototype.esc = function (s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    PvSidebar.prototype.render = function (series, activeIndex, onPick) {
        this.series = series || []; this.active = activeIndex || 0; this.onPick = onPick;
        var self = this;
        if (!this.el) return;
        if (!this.series.length) { this.el.innerHTML = '<div class="pv-film-empty">无序列</div>'; return; }
        var html = '';
        this.series.forEach(function (s, i) {
            html += '<div class="pv-thumb' + (i === self.active ? ' active' : '') + '" data-si="' + i + '">' +
                '<canvas class="pv-thumb-cv" width="' + THUMB + '" height="' + THUMB + '"></canvas>' +
                '<div class="pv-thumb-meta"><span class="pv-thumb-id">Ser ' + self.esc(s.series_id) + '</span>' +
                '<span class="pv-thumb-n">' + (s.slice_count || (s.images ? s.images.length : 1)) + ' 帧</span></div>' +
                '<div class="pv-thumb-desc" title="' + self.esc(s.description || '') + '">' + self.esc(s.description || '') + '</div></div>';
        });
        this.el.innerHTML = html;
        Array.prototype.forEach.call(this.el.querySelectorAll('.pv-thumb'), function (th) {
            th.addEventListener('click', function () {
                var i = parseInt(th.getAttribute('data-si'), 10) || 0;
                if (self.onPick) self.onPick(i);
            });
        });
        this.series.forEach(function (s, i) {
            var cv = self.el.querySelector('.pv-thumb[data-si="' + i + '"] canvas');
            if (cv) self.drawThumb(cv, s);
        });
    };
    PvSidebar.prototype.setActive = function (i) {
        this.active = i;
        if (!this.el) return;
        Array.prototype.forEach.call(this.el.querySelectorAll('.pv-thumb'), function (th, k) {
            th.classList.toggle('active', k === i);
        });
    };
    PvSidebar.prototype.drawThumb = function (cv, series) {
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height);
        if (series.is_mock) {
            var img = PvRender.makeSlice(series.orientation || 'AXIAL', series.seed, 0, series.slice_count || 1);
            var off = this._offscreen.getContext('2d');
            var out = off.createImageData(PvRender.BASE, PvRender.BASE);
            var sd = img.data, od = out.data;
            for (var i = 0; i < sd.length; i += 4) { var g = sd[i]; od[i] = od[i + 1] = od[i + 2] = g; od[i + 3] = 255; }
            off.putImageData(out, 0, 0);
            ctx.drawImage(this._offscreen, 0, 0, cv.width, cv.height);
        } else {
            var src = series.images && series.images[0];
            if (!src) return;
            var im = new Image();
            im.onload = function () {
                ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height);
                var sc = Math.min(cv.width / im.width, cv.height / im.height);
                var dw = im.width * sc, dh = im.height * sc;
                ctx.drawImage(im, (cv.width - dw) / 2, (cv.height - dh) / 2, dw, dh);
            };
            im.src = src;
        }
    };
    PvSidebar.prototype.toggle = function () {
        if (!this.el) return;
        this.el.classList.toggle('is-hidden');
        return !this.el.classList.contains('is-hidden');
    };

    global.PvSidebar = PvSidebar;
})(window);
