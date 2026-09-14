/**
 * ============================================================
 * push.js v1.0.0 — 实时推送客户端（SSE 长连接订阅）
 * ============================================================
 * 说明：基于 EventSource 订阅服务端 /api/push 推送通道，实现
 * 叫号大屏 / 站内消息 / 危急值提醒的毫秒级感知；EventSource 自带
 * 断线自动重连（服务端约 50 秒轮换连接，客户端无缝续流）。
 * 调用方保留原有固定轮询作为降级兜底（推送事件到达时立即刷新，
 * 轮询间隔可调大可降低服务端压力）。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.push = (function () {
    /** 通道订阅表：channel -> { onEvent, es, lastId } */
    var subs = {};
    var supported = typeof window.EventSource !== 'undefined';

    /**
     * 订阅一个推送通道
     * @param {string}   channel   通道（scr:{token} / msg:{uid} / room:{id} / dept:{id}）
     * @param {function} onEvent   事件回调（收到事件时立即触发）
     */
    function subscribe(channel, onEvent) {
        if (!channel) return;
        if (subs[channel]) { subs[channel].onEvent = onEvent; return; }
        subs[channel] = { onEvent: onEvent, es: null, lastId: 0 };
        if (!supported) return;
        var es = new EventSource('/api/push?channel=' + encodeURIComponent(channel) + '&cursor=0');
        es.onmessage = function (ev) {
            var s = subs[channel];
            if (!s) return;
            try {
                var d = JSON.parse(ev.data);
                var id = ev.lastEventId ? parseInt(ev.lastEventId, 10) : 0;
                if (id > s.lastId) { s.lastId = id; }
                if (s.onEvent) s.onEvent(d);
            } catch (e) {}
        };
        subs[channel].es = es;
    }

    /** 取消订阅（并关闭底层连接） */
    function close(channel) {
        var s = subs[channel];
        if (s && s.es) { try { s.es.close(); } catch (e) {} }
        delete subs[channel];
    }

    /** 是否支持 SSE（不支持时调用方应保留轮询兜底） */
    function isSupported() { return supported; }

    return { subscribe: subscribe, close: close, supported: isSupported };
})();