/**
 * ============================================================
 * push.js v2.0.0 — 实时推送客户端（SSE 长连接，连接健康管理）
 * ============================================================
 * 说明：
 *  - 连接生命周期状态机：CONNECTING / CONNECTED / DISCONNECTED / RECONNECTING
 *  - 通过 document.dispatchEvent(CustomEvent) 广播状态事件（push:connected /
 *    push:disconnected / push:reconnecting），供 SmartPoller 等订阅调整轮询频率；
 *  - 指数退避重连（2s → 4s → 8s … 上限 30s），重连成功后退避归零；
 *  - 心跳假死检测：服务端每 20s 下发真实 heartbeat 事件，客户端跟踪最后活跃
 *    时间，超时（35s）判定连接假死并主动退避重连；
 *  - 调用方保留轮询作为降级兜底（推送健康时低频对齐、断开时应急高频）。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.push = (function () {
    /** 连接状态 */
    var ST = { CONNECTING: 0, CONNECTED: 1, DISCONNECTED: 2, RECONNECTING: 3 };
    var supported = typeof window.EventSource !== 'undefined';
    var status = supported ? ST.CONNECTING : ST.DISCONNECTED;
    /** 通道订阅表：channel -> { onEvent, es, lastId, retry, lastActivity, watch, __rt } */
    var subs = {};
    var WATCH_INTERVAL = 5000;   // 假死检测轮询间隔
    var STALE_MS = 35000;        // 超时判定假死（服务端心跳 20s，留 15s 余量）

    /** 广播连接状态变更（eventbus + CustomEvent 双通道） */
    function broadcast() {
        var name = status === ST.CONNECTED ? 'push:connected'
            : status === ST.DISCONNECTED ? 'push:disconnected'
            : status === ST.RECONNECTING ? 'push:reconnecting'
            : 'push:connecting';
        if (window.Clinic && Clinic.eventbus && Clinic.eventbus.emit) {
            Clinic.eventbus.emit(name, { status: status });
        }
        try {
            document.dispatchEvent(new CustomEvent(name, { detail: { status: status } }));
        } catch (e) {}
    }

    function setStatus(s) {
        if (s === status) return;
        status = s;
        broadcast();
    }

    /** 指数退避延迟（2s → 4s → 8s → … 上限 30s） */
    function backoffDelay(attempt) {
        return Math.min(2000 * Math.pow(2, attempt), 30000);
    }

    /** 建立连接（每次都是全新 EventSource；onerror 时手动退避重连，避免与内置重连叠加） */
    function connect(channel) {
        var s = subs[channel];
        if (!s) return;
        if (s.es) { try { s.es.close(); } catch (e) {} }
        setStatus(ST.CONNECTING);
        var es = new EventSource('/api/push?channel=' + encodeURIComponent(channel) + '&cursor=' + (s.lastId || 0));
        s.es = es;
        s.lastActivity = Date.now();

        es.onopen = function () {
            s.lastActivity = Date.now();
            s.retry = 0;              // 重连成功：退避归零
            setStatus(ST.CONNECTED);
        };
        es.onmessage = function (ev) {
            s.lastActivity = Date.now();   // 收到心跳/业务事件均视为存活
            var id = ev.lastEventId ? parseInt(ev.lastEventId, 10) : 0;
            if (id > (s.lastId || 0)) { s.lastId = id; }
            var d = null;
            try { d = JSON.parse(ev.data); } catch (e) { return; }
            if (!d || d.type === 'heartbeat') return;   // 心跳仅用于保活判定，不触发业务回调
            if (s.onEvent) s.onEvent(d);
        };
        es.onerror = function () {
            // 手动接管重连：关闭当前 EventSource，指数退避后重建
            if (subs[channel] && subs[channel].es === es) {
                try { es.close(); } catch (e) {}
                subs[channel].es = null;
            }
            setStatus(ST.RECONNECTING);
            s.retry = (s.retry || 0) + 1;
            clearTimeout(s.__rt);
            s.__rt = setTimeout(function () {
                if (subs[channel]) connect(channel);
            }, backoffDelay(s.retry));
        };
        // 假死看门狗：连接看似 OPEN 但已无任何数据（服务端挂死/网络黑洞）→ 主动重连
        if (s.watch) clearInterval(s.watch);
        s.watch = setInterval(function () {
            var cur = subs[channel];
            if (!cur || cur.es !== es) { clearInterval(s.watch); s.watch = null; return; }
            if (Date.now() - cur.lastActivity > STALE_MS) {
                clearInterval(s.watch);
                s.watch = null;
                setStatus(ST.RECONNECTING);
                s.retry = (s.retry || 0) + 1;
                try { es.close(); } catch (e) {}
                cur.es = null;
                clearTimeout(s.__rt);
                s.__rt = setTimeout(function () {
                    if (subs[channel]) connect(channel);
                }, backoffDelay(s.retry));
            }
        }, WATCH_INTERVAL);
    }

    /**
     * 订阅一个推送通道
     * @param {string}   channel   通道（scr:{token} / msg:{uid} / room:{id} / dept:{id}）
     * @param {function} onEvent   事件回调（收到业务事件时触发）
     */
    function subscribe(channel, onEvent) {
        if (!channel) return;
        if (subs[channel]) {
            subs[channel].onEvent = onEvent;
            return;
        }
        subs[channel] = { onEvent: onEvent, es: null, lastId: 0, retry: 0, lastActivity: 0, watch: null, __rt: null };
        if (!supported) return;
        connect(channel);
    }

    /** 取消订阅（并关闭底层连接与看门狗） */
    function close(channel) {
        var s = subs[channel];
        if (s) {
            if (s.watch) clearInterval(s.watch);
            if (s.__rt) clearTimeout(s.__rt);
            if (s.es) { try { s.es.close(); } catch (e) {} }
            delete subs[channel];
        }
    }

    /** 是否支持 SSE */
    function isSupported() { return supported; }

    /** 当前连接状态 */
    function getStatus() { return status; }

    return { subscribe: subscribe, close: close, supported: isSupported, status: getStatus, ST: ST };
})();