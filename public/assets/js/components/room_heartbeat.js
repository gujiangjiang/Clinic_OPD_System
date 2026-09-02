/**
 * ============================================================
 * room_heartbeat.js v1.0.0 — 诊室大屏绑定心跳保活（全局）
 * ============================================================
 * 说明：医生端叫号大屏绑定心跳原只在新工作站页面运行，
 * 离开工作站（进首页/模板页/刷新）心跳即停，大屏端超过
 * 90 秒无医生心跳会自动解绑。现改为全局组件：
 * - 所有医生角色页面均加载；绑定信息存 sessionStorage（随
 *   登录会话存活），页面间跳转不丢失；
 * - 每次心跳前先从 sessionStorage 读取绑定，确保跨页面持续；
 * - 页面加载时自动从 sessionStorage 恢复绑定并立即发一次
 *   心跳（解决「刷新页面后绑定丢失/自动解绑」）。
 * 依赖：ajax.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.roomHeartbeat = (function () {

    var HEART_TIMER = null;
    var HEART_INTERVAL = 30000;   // 每 30 秒一次（大屏端 90 秒兜底解绑）

    /* ---------- sessionStorage 记忆（绑定账号 + 会话ID） ---------- */
    function memKey() {
        return {
            u: document.body.getAttribute('data-uid') || '',
            s: document.body.getAttribute('data-sid') || '',
        };
    }

    function readBound() {
        try {
            var sv = JSON.parse(sessionStorage.getItem('clinic_doc_room') || 'null');
            if (sv && String(sv.u) === memKey().u && String(sv.s) === memKey().s && sv.room_id) {
                return { room_id: parseInt(sv.room_id, 10) || 0, room_name: sv.room_name || '' };
            }
        } catch (e) { /* 忽略 */ }
        return null;
    }

    function saveBound(roomId, roomName) {
        try {
            sessionStorage.setItem('clinic_doc_room', JSON.stringify({
                u: memKey().u, s: memKey().s, room_id: roomId, room_name: roomName || '',
            }));
        } catch (e) { /* 忽略 */ }
    }

    function clearBound() {
        try { sessionStorage.removeItem('clinic_doc_room'); } catch (e) { /* 忽略 */ }
    }

    /* ---------- 心跳 ---------- */
    function beat() {
        var b = readBound();
        if (!b || !b.room_id) return;
        Clinic.ajax('/api/doctor', { action: 'room_heartbeat', room_id: b.room_id }, {
            loading: false,
            onError: function () { /* 静默 */ },
        });
    }

    function start() {
        if (!readBound()) return;
        if (HEART_TIMER) clearInterval(HEART_TIMER);
        beat();   // 立即发一次（刷新后马上恢复）
        HEART_TIMER = setInterval(beat, HEART_INTERVAL);
    }

    function stop() {
        if (HEART_TIMER) { clearInterval(HEART_TIMER); HEART_TIMER = null; }
    }

    /* ---------- 绑定/解绑（供 doctor_tools.js 调用） ---------- */
    function remember(roomId, roomName) {
        if (!roomId) return;
        saveBound(roomId, roomName);
        start();
    }

    function forget() {
        clearBound();
        stop();
    }

    /* 当前绑定（无则 null） */
    function current() {
        return readBound();
    }

    /* ---------- 页面加载自动恢复 ---------- */
    function init() {
        if (readBound()) start();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return { remember: remember, forget: forget, current: current, start: start };
})();
