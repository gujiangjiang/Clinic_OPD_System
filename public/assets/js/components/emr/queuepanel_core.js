/**
 * queuepanel_core.js v1.0.0 — 候诊弹层面板基础设施
 * ============================================================
 * 说明：医生病历页候诊面板（queuepanel.js）与医技工作台候诊面板
 * （deptwork.js）共用同一套面板机制：
 *   1. createPanel   在按钮下方创建 fixed 定位面板（同一按钮 #queueBtn）
 *   2. bindClose     外部点击 / Esc 关闭（与按钮本身的打开/收起互斥）
 *   3. clampHeight   列表高度钳制（最高 46vh 且不溢出屏幕底部）
 * 各文件的差异（页签/行渲染/数据源/搜索）仍保留在各自模块内。
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.queuePanelCore = {

    /** 创建候诊面板：fixed 定位在触发按钮正下方 */
    createPanel: function (anchor, id) {
        var p = document.createElement('div');
        p.id = id;
        p.className = 'queue-panel';
        document.body.appendChild(p);
        var rect = anchor.getBoundingClientRect();
        p.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        p.style.left = Math.max(8, rect.left + window.scrollX) + 'px';
        return p;
    },

    /**
     * 注册外部点击 / Esc 关闭（与触发按钮互斥：点击按钮本身不触发关闭，
     * 由调用方 toggle 处理；btn 可能在局部刷新后被移除，缺省视为非面板内点击）
     * @param {HTMLElement} panel   面板元素
     * @param {Function}    onClose 关闭回调（调用方清理面板与状态）
     * @returns {{unbind: Function}} 解绑句柄（closePanel 时调用避免监听累积）
     */
    bindClose: function (panel, onClose) {
        var outsideClose = function (e) {
            var btn = document.getElementById('queueBtn');
            if (panel && !panel.contains(e.target) && (!btn || (e.target !== btn && !btn.contains(e.target)))) onClose();
        };
        var escClose = function (e) { if (e.key === 'Escape') onClose(); };
        setTimeout(function () {
            document.addEventListener('mousedown', outsideClose, true);
            document.addEventListener('keydown', escClose, true);
        }, 0);
        return {
            unbind: function () {
                document.removeEventListener('mousedown', outsideClose, true);
                document.removeEventListener('keydown', escClose, true);
            },
        };
    },

    /** 列表高度钳制：最高不超过视口 46vh，且不溢出屏幕底部
     *  （必须在 DOM 渲染完成后调用，依赖 offsetHeight 实测值） */
    clampHeight: function (p) {
        var listEl = p.querySelector('.qp-list');
        if (!listEl) return;
        var chromeH = p.offsetHeight - listEl.offsetHeight;   // 页签+搜索+内边距
        var avail = window.innerHeight - p.getBoundingClientRect().top - chromeH - 12;
        listEl.style.maxHeight = Math.max(140, Math.min(window.innerHeight * 0.46, avail)) + 'px';
    },
};
