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
        el.textContent = msg;
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
