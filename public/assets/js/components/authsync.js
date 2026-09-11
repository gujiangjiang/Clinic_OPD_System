/**
 * authsync.js v1.0.0 — 跨窗口登录 Session 联动与阅片工作站安全锁定
 * ============================================================
 * 说明（任务2 模式 B 核心规范）：
 * 主窗口与独立阅片窗口共享登录 Session；通过 BroadcastChannel
 * （通道名 clinic_auth_sync）+ localStorage 监听实现跨窗口联动：
 *   1. 主窗口：退出登录（Logout）或检测到 Session 鉴权失败（401）时，
 *      广播 auth:logout 事件，并写 localStorage 里程碑（兜底通道不可用时）；
 *   2. 独立阅片窗口：收到 auth:logout 后立即激活高斯模糊遮罩，页面正中
 *      提示「登录会话已失效，阅片工作站已锁定，请重新登录主系统」，
 *      并禁止任何影像操作（遮罩层 pointer-events 拦截 + API 停发）。
 * 主窗口侧监听：阅片窗口锁定后广播 viewer:locked（可选展示提示）。
 * 依赖：无（纯原生，登录前页面亦可安全加载）
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.authSync = (function () {

    var CHANNEL = 'clinic_auth_sync';
    var LS_KEY = 'clinic_auth_event';       // localStorage 兜底里程碑（新窗口打开时读取）
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

    /** 写 localStorage 兜底里程碑（BroadcastChannel 不可用/跨标签未及时建立时） */
    function markLogout() {
        try { localStorage.setItem(LS_KEY, String(Date.now())); } catch (e) { /* 忽略 */ }
    }

    /**
     * 广播：登录会话已失效（主窗口在退出登录/401 时调用）
     * 双通道：BroadcastChannel 即时广播 + localStorage 里程碑兜底
     */
    function broadcastLogout() {
        markLogout();
        var ch = getChannel();
        if (ch) {
            try { ch.postMessage({ type: 'auth:logout', ts: Date.now() }); } catch (e) { /* 忽略 */ }
        }
    }

    /**
     * 订阅登出事件（独立阅片窗口在初始化时调用）
     * @param {Function} onLogout 会话失效回调（激活锁定遮罩）
     * @returns {{isLoggedOut: boolean}} 初始化时是否已存在失效里程碑
     */
    function onLogout(onLogout) {
        var ch = getChannel();
        if (ch) {
            ch.onmessage = function (ev) {
                if (ev && ev.data && ev.data.type === 'auth:logout' && onLogout) onLogout(ev.data);
            };
        }
        // storage 事件兜底（同源其他标签页写里程碑时触发）
        window.addEventListener('storage', function (ev) {
            if (ev.key === LS_KEY && ev.newValue && onLogout) onLogout({ type: 'auth:logout', ts: ev.newValue });
        });
        // 初始化时检查既有里程碑（防止广播发出后才打开的窗口漏接）
        var stamped = '';
        try { stamped = localStorage.getItem(LS_KEY) || ''; } catch (e) { /* 忽略 */ }
        return { isLoggedOut: stamped !== '' };
    }

    return {
        broadcastLogout: broadcastLogout,
        onLogout: onLogout,
        markLogout: markLogout,
    };
})();
