/**
 * ============================================================
 * eventbus.js — 轻量事件总线（发布/订阅）
 * ============================================================
 * 说明：跨模块通信的解耦通道——发消息方无需知道谁关心，收消息方
 * 按需订阅。模块之间不再互相直接调用，降低耦合、便于扩展。
 * 用法：
 *   Clinic.eventBus.on('emr:dataChanged', fn)   // 订阅
 *   Clinic.eventBus.emit('emr:dataChanged', d)  // 发布
 * 注意：emit 为同步通知（同帧执行）；仅用于跨模块通知，模块内部
 * 强关联逻辑仍保持直接调用，避免过度设计。
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.eventBus = {
    _handlers: {},
    /** 订阅事件 */
    on: function (event, fn) {
        (this._handlers[event] = this._handlers[event] || []).push(fn);
    },
    /** 取消订阅 */
    off: function (event, fn) {
        var list = this._handlers[event];
        if (list) this._handlers[event] = list.filter(function (f) { return f !== fn; });
    },
    /** 发布事件（同步通知全部订阅者） */
    emit: function (event, data) {
        (this._handlers[event] || []).forEach(function (fn) { fn(data); });
    },
};
