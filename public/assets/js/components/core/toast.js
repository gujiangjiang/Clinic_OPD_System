/**
 * ============================================================
 * toast.js v1.0.0 — 轻提示组件
 * ============================================================
 * 说明：页面顶部的轻量提示消息，用于操作反馈，
 * 支持 success / error / info / warning 四种类型。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.toast = (function () {
    /** 容器元素 */
    let wrap = null;

    /**
     * 确保容器存在
     */
    function ensureWrap() {
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'toast-wrap';
            document.body.appendChild(wrap);
        }
        return wrap;
    }

    /**
     * 显示一条提示
     * @param {string} msg  消息内容
     * @param {string} type 类型 success/error/info/warning
     * @param {number} ms   显示时长（毫秒）
     */
    function show(msg, type, ms) {
        const el = document.createElement('div');
        el.className = 'toast toast-' + (type || 'info');
        const m = String(msg == null ? '' : msg);
        // 图标+文本分段渲染：消息含受控 SVG 图标（无论位于开头还是中间）时，
        // 整体经 iconSafeHtml 转义后渲染——图标放行、其余文本走转义，
        // 避免用户内容被当 HTML 解析的 XSS 面，也避免图标被当纯文本显示；
        // 图标模块未注入时退回「首段 SVG 直出 + 其余文本节点」的旧策略
        if (m.indexOf('<svg') !== -1 && window.iconSafeHtml) {
            el.innerHTML = iconSafeHtml(m);
        } else if (m.indexOf('<svg') === 0) {
            const end = m.indexOf('</svg>');
            if (end !== -1) {
                el.innerHTML = m.substring(0, end + 6);
                el.appendChild(document.createTextNode(m.substring(end + 6)));
            } else {
                el.textContent = m;
            }
        } else {
            el.textContent = m;
        }
        ensureWrap().appendChild(el);
        // 强制回流后显示动画
        requestAnimationFrame(function () {
            el.classList.add('show');
        });
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        }, ms || 2400);
    }

    return {
        success: function (msg, ms) { show(msg, 'success', ms); },
        error: function (msg, ms) { show(msg, 'error', ms || 3200); },
        info: function (msg, ms) { show(msg, 'info', ms); },
        warning: function (msg, ms) { show(msg, 'warning', ms); },
    };
})();
