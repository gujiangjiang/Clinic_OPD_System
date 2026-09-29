/**
 * icons.js v1.0.0 — 内联 SVG 图标前端助手
 * 说明：读取 layout/screen/call 注入的 window.OPD_ICON_SVGS 映射（name → SVG 标记），
 * 提供 renderIconSvg(name[, size[, color]]) 供组件模板拼接，Clinic.icon 为同义别名。
 * 尺寸/颜色通过字符串替换实现（映射默认 16px），不解析/执行任何脚本（无 XSS 面）。
 */
(function () {
    function renderIconSvg(n, s, c) {
        var m = window.OPD_ICON_SVGS || {};
        var h = m[n] || '';
        if (!h) return '';
        if (s) h = h.replace(/width="16px" height="16px"/, 'width="' + s + 'px" height="' + s + 'px"');
        if (c) h = h.replace('>', ' style="color:' + c + '">');
        return h;
    }
    /**
     * 含受控图标的字符串 → 可安全 innerHTML 的 HTML 片段。
     * 用于文本型挂载点（toast 消息 / 模态标题 / 模态按钮文案等）：
     * 仅放行本系统图标标记 <svg class="opd-svg-icon">…</svg>，
     * 其余文本一律转义，防止消息/标题里拼入的用户数据被当 HTML 解析。
     * @param {*} s 任意值，null/undefined 视为空串
     * @returns {string}
     */
    function iconSafeHtml(s) {
        var keep = [];
        var str = String(s == null ? '' : s).replace(
            /<svg class="opd-svg-icon"[\s\S]*?<\/svg>/g,
            function (m) { keep.push(m); return '\uE000' + keep.length + '\uE001'; }
        );
        str = (window.Clinic && Clinic.escHtml)
            ? Clinic.escHtml(str)
            : String(str).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        return str.replace(/\uE000(\d+)\uE001/g, function (_, n) { return keep[+n - 1] || ''; });
    }
    window.iconSafeHtml = iconSafeHtml;
    window.renderIconSvg = renderIconSvg;
    if (window.Clinic) { Clinic.icon = renderIconSvg; Clinic.iconSafeHtml = iconSafeHtml; }
    else {
        var _boot = function () { if (window.Clinic) { Clinic.icon = renderIconSvg; Clinic.iconSafeHtml = iconSafeHtml; } };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', _boot);
        else _boot();
    }
})();