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
    window.renderIconSvg = renderIconSvg;
    if (window.Clinic) { Clinic.icon = renderIconSvg; }
    else {
        var _boot = function () { if (window.Clinic) Clinic.icon = renderIconSvg; };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', _boot);
        else _boot();
    }
})();