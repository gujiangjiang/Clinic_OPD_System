/**
 * authsync.js v2.0.0 — 跨窗口登录 Session 联动与阅片工作站安全锁定
 * ============================================================
 * 说明（任务2 模式 B 核心规范 + 优化项11/12）：
 * 主窗口与独立阅片窗口共享登录 Session；通过 BroadcastChannel
 * （通道名 clinic_auth_sync）+ localStorage 里程碑实现跨窗口联动：
 *   1. 主窗口：退出登录（Logout）或检测到 Session 鉴权失败（401）时，
 *      广播 auth:logout（携带 session id 与用户 id），并写 localStorage
 *      里程碑（兜底 BroadcastChannel 不可用时）；
 *   2. 独立阅片窗口：收到 auth:logout 后——仅当事件 sid 与本窗口 sid
 *      一致（同一登录会话）时——立即激活高斯模糊遮罩，提示
 *      「登录会话已失效，阅片工作站已锁定，请重新登录主系统」，
 *      并禁止任何影像操作；不同会话（换账号重登）的历史里程碑自动忽略；
 *   3. 登录成功（auth:login）时清除旧里程碑，防止陈旧事件误锁；
 *   4. 实时同步（优化项12）：主窗口选中患者/序列变化时广播
 *      viewer:context，阅片窗口订阅后实时刷新展示对象。
 * 依赖：无（纯原生，登录前页面亦可安全加载）
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.authSync = (function () {

    var CHANNEL = 'clinic_auth_sync';
    var LS_KEY = 'clinic_auth_logout_milestone';   // localStorage 兜底里程碑
    var bc = null;

    function supported() {
        return typeof BroadcastChannel !== 'undefined';
    }

    function getChannel() {
        if (!supported()) return null;
        if (!bc) {
            try { bc = new BroadcastChannel(CHANNEL); } catch (e) { return null; }
        }
        return bc;
    }

    /** 当前会话标识（body data-sid 由布局注入；独立页无则空串） */
    function currentSid() {
        return (document.body && document.body.getAttribute('data-sid')) || '';
    }
    function currentUid() {
        return (document.body && document.body.getAttribute('data-uid')) || '';
    }

    /**
     * 写 localStorage 兜底里程碑（BroadcastChannel 不可用/跨标签未及时建立时）。
     * 里程碑携带触发时点的 sid/uid，订阅方据此判定是否为「本会话」的登出。
     */
    function markLogout() {
        try {
            localStorage.setItem(LS_KEY, JSON.stringify({
                sid: currentSid(),
                uid: currentUid(),
                ts: Date.now(),
            }));
        } catch (e) { /* 忽略 */ }
    }

    /** 清除登出里程碑（登录成功时调用：旧会话的登出事件随新登录作废） */
    function clearLogoutMark() {
        try { localStorage.removeItem(LS_KEY); } catch (e) { /* 忽略 */ }
    }

    /**
     * 广播：登录会话已失效（主窗口在退出登录/401 时调用）
     * 双通道：BroadcastChannel 即时广播 + localStorage 里程碑兜底
     */
    function broadcastLogout() {
        markLogout();
        var payload = { type: 'auth:logout', sid: currentSid(), uid: currentUid(), ts: Date.now() };
        var ch = getChannel();
        if (ch) {
            try { ch.postMessage(payload); } catch (e) { /* 忽略 */ }
        }
    }

    /** 广播：登录成功（清里程碑 + 通知阅片窗口解除陈旧锁定态） */
    function broadcastLogin() {
        clearLogoutMark();
        var ch = getChannel();
        if (ch) {
            try { ch.postMessage({ type: 'auth:login', sid: currentSid(), uid: currentUid(), ts: Date.now() }); } catch (e) { /* 忽略 */ }
        }
    }

    /**
     * 广播：主窗口当前阅片上下文变化（患者/序列切换，优化项12）
     * @param {object} ctx { visit 混淆就诊码, item 检查项目 id（混淆）, label 展示文案 }
     */
    function broadcastContext(ctx) {
        var ch = getChannel();
        if (!ch) return;
        try { ch.postMessage({ type: 'viewer:context', ctx: ctx, ts: Date.now() }); } catch (e) { /* 忽略 */ }
    }

    /** 判断登出事件是否属于「本窗口当前会话」 */
    function isOwnSession(ev) {
        if (!ev) return false;
        var sid = currentSid();
        // 本窗口拿不到 sid（独立页未注入）→ 视为同会话（仅主窗口场景，保守锁定）
        if (!sid) return true;
        // 事件未携带 sid（旧版本主窗口）→ 按同会话处理（保守锁定）
        if (!ev.sid) return true;
        return ev.sid === sid;
    }

    /**
     * 订阅事件（独立阅片窗口在初始化时调用）
     * @param {Function} onLogout    会话失效回调（仅同会话事件触发；激活锁定遮罩）
     * @param {Function} [onContext] 主窗口上下文变化回调（ctx）
     * @returns {{milestone: object|null}} 既有里程碑（供调用方结合服务端会话预检判定）
     */
    function onLogout(onLogout, onContext) {
        var ch = getChannel();
        if (ch) {
            ch.onmessage = function (ev) {
                var d = ev && ev.data ? ev.data : null;
                if (!d || !d.type) return;
                if (d.type === 'auth:logout' && onLogout && isOwnSession(d)) onLogout(d);
                if (d.type === 'viewer:context' && onContext) onContext(d.ctx || {});
            };
        }
        // storage 事件兜底（同源其他标签页写里程碑时触发）
        window.addEventListener('storage', function (ev) {
            if (ev.key !== LS_KEY || !ev.newValue || !onLogout) return;
            try {
                var d = JSON.parse(ev.newValue);
                if (isOwnSession(d)) onLogout({ type: 'auth:logout', sid: d.sid, uid: d.uid, ts: d.ts });
            } catch (e) { /* 忽略 */ }
        });
        // 读取既有里程碑（仅供参考：是否锁定由调用方结合服务端预检决定，
        // 里程碑可能来自历史会话，不可单独作为锁定依据——优化项11 根因）
        var milestone = null;
        try {
            var raw = localStorage.getItem(LS_KEY);
            milestone = raw ? JSON.parse(raw) : null;
        } catch (e) { /* 忽略 */ }
        return { milestone: milestone };
    }

    return {
        broadcastLogout: broadcastLogout,
        broadcastLogin: broadcastLogin,
        broadcastContext: broadcastContext,
        clearLogoutMark: clearLogoutMark,
        onLogout: onLogout,
    };
})();
