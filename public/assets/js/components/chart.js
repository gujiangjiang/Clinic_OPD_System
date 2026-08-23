/**
 * ============================================================
 * chart.js v1.0.0 — 轻量图表组件（纯 SVG 点线图/柱状图，零第三方依赖）
 * ============================================================
 * 说明：医院运营分析专用，也可复用于其他页面：
 * 1. Clinic.chart.line(container, cfg)   多序列点线图（平滑趋势）
 *    cfg = {
 *      labels:  ['08-01', ...]        X 轴标签
 *      series:  [{ name:'药费', data:[123,...], color:'#409eff' }, ...]
 *      height:  260                   像素高（可选，默认 260）
 *      money:   true                  Y 轴按金额格式化（千分位 + ¥）
 *    }
 * 2. Clinic.chart.bars(container, cfg)   横向条形排行图
 *    cfg = { labels:['内科',...], data:[...], color:'#67c23a', height:..., money:true }
 * 特性：自适应容器宽度、Y 轴自动刻度、数据点悬停提示、图例、空数据占位。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.chart = (function () {

    function fmtNum(n, money) {
        if (money) return '¥' + Number(n || 0).toLocaleString('zh-CN', { maximumFractionDigits: 2 });
        return Number(n || 0).toLocaleString('zh-CN');
    }

    /** 生成"好看"的 Y 轴刻度上限（1/2/5×10^n） */
    function niceMax(v) {
        if (v <= 0) return 10;
        var exp = Math.floor(Math.log(v) / Math.LN10);
        var base = Math.pow(10, exp);
        var f = v / base;
        var m = f <= 1 ? 1 : (f <= 2 ? 2 : (f <= 5 ? 5 : 10));
        return m * base;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /** X 轴标签抽样（避免密集重叠） */
    function thinLabels(labels, maxShow) {
        if (labels.length <= maxShow) return labels.map(function (l) { return l; });
        var step = Math.ceil(labels.length / maxShow);
        return labels.map(function (l, i) {
            return i % step === 0 || i === labels.length - 1 ? l : '';
        });
    }

    /**
     * 多序列点线图
     */
    function line(container, cfg) {
        var el = typeof container === 'string' ? document.getElementById(container) : container;
        if (!el) return;
        cfg = cfg || {};
        var labels = cfg.labels || [];
        var series = (cfg.series || []).filter(function (s) { return s && s.data && s.data.length; });
        var H = cfg.height || 260;
        if (!labels.length || !series.length) {
            el.innerHTML = '<div class="empty" style="height:' + H + 'px;display:flex;align-items:center;justify-content:center;color:var(--text-muted)">暂无数据</div>';
            return;
        }
        var W = Math.max(el.clientWidth || 600, 320);
        var padL = 64, padR = 16, padT = 14, padB = 30;
        var iw = W - padL - padR, ih = H - padT - padB;
        var maxV = 0;
        series.forEach(function (s) { s.data.forEach(function (v) { if (v > maxV) maxV = v; }); });
        var yMax = niceMax(maxV * 1.05);
        var n = labels.length;
        var xAt = function (i) { return padL + (n === 1 ? iw / 2 : iw * i / (n - 1)); };
        var yAt = function (v) { return padT + ih - ih * v / yMax; };

        // 网格 + Y 轴刻度（4 条水平线）
        var g = '';
        for (var t = 0; t <= 4; t++) {
            var vy = yMax * t / 4;
            var yy = yAt(vy);
            g += '<line x1="' + padL + '" y1="' + yy + '" x2="' + (W - padR) + '" y2="' + yy +
                '" stroke="var(--border)" stroke-width="1" stroke-dasharray="3,3"/>' +
                '<text x="' + (padL - 8) + '" y="' + (yy + 4) + '" text-anchor="end" font-size="11" fill="var(--text-muted)">' +
                fmtNum(vy, cfg.money) + '</text>';
        }
        // X 轴标签
        thinLabels(labels, Math.max(4, Math.floor(iw / 46))).forEach(function (l, i) {
            if (!l) return;
            g += '<text x="' + xAt(i) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="11" fill="var(--text-muted)">' + esc(l) + '</text>';
        });
        // 序列折线 + 数据点（带悬停提示）
        var legend = '';
        series.forEach(function (s, si) {
            var color = s.color || ['#409eff', '#67c23a', '#e6a23c', '#f56c6c', '#909399'][si % 5];
            var pts = s.data.map(function (v, i) { return xAt(i).toFixed(1) + ',' + yAt(v).toFixed(1); }).join(' ');
            g += '<polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round"/>';
            s.data.forEach(function (v, i) {
                g += '<circle cx="' + xAt(i).toFixed(1) + '" cy="' + yAt(v).toFixed(1) + '" r="3" fill="' + color + '">' +
                    '<title>' + esc(labels[i]) + '\n' + esc(s.name) + '：' + fmtNum(v, cfg.money) + '</title></circle>';
            });
            legend += '<span style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;font-size:12px;color:var(--text-muted)">' +
                '<span style="width:18px;height:3px;background:' + color + ';border-radius:2px"></span>' + esc(s.name) + '</span>';
        });

        el.innerHTML = '<div>' +
            '<div style="margin-bottom:2px">' + legend + '</div>' +
            '<svg width="100%" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" style="display:block">' + g + '</svg>' +
            '</div>';
    }

    /**
     * 横向条形排行图
     */
    function bars(container, cfg) {
        var el = typeof container === 'string' ? document.getElementById(container) : container;
        if (!el) return;
        cfg = cfg || {};
        var labels = cfg.labels || [];
        var data = cfg.data || [];
        var color = cfg.color || '#409eff';
        var rowH = cfg.rowH || 34;
        if (!labels.length) {
            el.innerHTML = '<div class="empty" style="padding:24px;color:var(--text-muted)">暂无数据</div>';
            return;
        }
        var maxV = Math.max.apply(null, data.concat([0]));
        var maxW = niceMax(maxV * 1.05);
        var html = '';
        labels.forEach(function (l, i) {
            var w = maxV > 0 ? Math.max(2, 100 * data[i] / maxW) : 0;
            html += '<div style="margin-bottom:8px">' +
                '<div class="flex-between" style="font-size:12px;color:var(--text-muted);margin-bottom:3px">' +
                '<span style="max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(l) + '</span>' +
                '<span>' + fmtNum(data[i], cfg.money) + '</span></div>' +
                '<div style="background:var(--bg-soft,#eef2f7);border-radius:6px;height:14px;overflow:hidden">' +
                '<div style="width:' + w + '%;height:100%;background:' + color + ';border-radius:6px"></div></div></div>';
        });
        el.innerHTML = html;
    }

    return { line: line, bars: bars };
})();
