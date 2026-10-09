/**
 * ============================================================
 * callpop_common.js — 叫号悬浮窗公共交互（拖动 / 离线蒙板）
 * ============================================================
 * 说明：医生工作站（doctor_tools.js）与科室工作站（deptwork.js）各有一份
 * 叫号悬浮窗实现，其中「标题栏拖动记忆位置」「大屏离线蒙板」两段逐字重复，
 * 收敛为本模块（随 JS_CORE 全站加载）：
 *  · Clinic.callPop.bindDrag(pop, onSavePos)  — 标题栏拖动，松开回调保存位置
 *  · Clinic.callPop.unbindDrag()              — 移除文档级监听（关窗时调用）
 *  · Clinic.callPop.setOffline(pop, offline)  — 离线蒙板（含底部解绑栏留白）
 * 仅收敛无状态差异的交互；两端的悬浮窗 HTML/数据/动作仍各自维护。
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.callPop = (function () {
    var dragMove = null;
    var dragUp = null;

    /** 移除文档级拖动监听（与 bindDrag 成对，防反复开关累积泄漏） */
    function unbindDrag() {
        if (dragMove) document.removeEventListener('mousemove', dragMove, true);
        if (dragUp) document.removeEventListener('mouseup', dragUp, true);
        dragMove = null;
        dragUp = null;
    }

    /**
     * 悬浮窗拖动：按住标题栏可任意拖动，松开记忆位置
     * @param {HTMLElement} pop       悬浮窗根节点
     * @param {Function}    onSavePos 松开时回调 (x, y) 保存位置
     */
    function bindDrag(pop, onSavePos) {
        var head = pop.querySelector('.doc-call-pop-head');
        var dragging = false, offX = 0, offY = 0;
        // 先移除旧句柄再注册，避免 mode 切换（close+open）时文档级监听累积
        unbindDrag();
        dragMove = function (e) {
            if (!dragging) return;
            var x = Math.max(0, Math.min(e.clientX - offX, window.innerWidth - 80));
            var y = Math.max(0, Math.min(e.clientY - offY, window.innerHeight - 80));
            pop.style.left = x + 'px';
            pop.style.top = y + 'px';
            pop.style.right = 'auto';
            pop.style.bottom = 'auto';
        };
        dragUp = function () {
            if (!dragging) return;
            dragging = false;
            if (typeof onSavePos === 'function') {
                onSavePos(parseInt(pop.style.left, 10) || 0, parseInt(pop.style.top, 10) || 0);
            }
        };
        head.addEventListener('mousedown', function (e) {
            if (e.target.closest('.doc-call-pop-x')) return;
            dragging = true;
            offX = e.clientX - pop.getBoundingClientRect().left;
            offY = e.clientY - pop.getBoundingClientRect().top;
            e.preventDefault();
        });
        document.addEventListener('mousemove', dragMove, true);
        document.addEventListener('mouseup', dragUp, true);
    }

    /** 大屏离线蒙板：offline=true 显示（幂等），false 移除 */
    function setOffline(pop, offline) {
        var body = pop.querySelector('.doc-call-pop-body');
        if (!body) return;
        var mask = body.querySelector('.doc-call-offline');
        if (!offline) { if (mask) mask.remove(); return; }
        if (mask) return;
        var isMini = pop.classList.contains('doc-call-mini');
        mask = document.createElement('div');
        mask.className = 'doc-call-offline';
        mask.innerHTML = isMini
            ? '<div class="doc-call-offline-title">大屏已离线</div>' +
              '<div class="doc-call-offline-desc">叫号暂不可用，请联系管理员。</div>'
            : '<div class="doc-call-offline-ico">' + renderIconSvg('nav:screen') + '</div>' +
              '<div class="doc-call-offline-title">叫号大屏已离线</div>' +
              '<div class="doc-call-offline-desc">大屏未连接，叫号暂不可用。<br>请检查大屏电源与网络，<br>或请管理员在「叫号管理」重置大屏链接。</div>';
        // 标准版：蒙板底部留出「解绑」栏高度（离线时需手动解绑该大屏）
        var foot = body.querySelector('.doc-call-foot');
        if (foot) mask.style.bottom = (foot.offsetHeight + (parseFloat(getComputedStyle(body).paddingBottom) || 0)) + 'px';
        body.appendChild(mask);
    }

    return {
        bindDrag: bindDrag,
        unbindDrag: unbindDrag,
        setOffline: setOffline,
    };
})();
