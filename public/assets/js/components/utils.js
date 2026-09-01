/**
 * ============================================================
 * utils.js v1.0.0 — 通用工具函数
 * ============================================================
 * 说明：全站共享的零散工具函数，消除多处重复定义。
 * 依赖 ajax.js（Clinic 全局命名空间），须在 app.js 之前加载。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.utils = (function () {

    /** 补零（默认 2 位） */
    function pad(n, len) {
        len = len || 2;
        var s = String(n);
        while (s.length < len) { s = '0' + s; }
        return s;
    }

    /** 根据出生日期计算年龄 */
    function age(birthDate) {
        if (!birthDate) return 0;
        var bd = new Date(birthDate);
        if (isNaN(bd.getTime())) return 0;
        var now = new Date();
        var a = now.getFullYear() - bd.getFullYear();
        var m = now.getMonth() - bd.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < bd.getDate())) a--;
        return a < 0 ? 0 : a;
    }

    return { pad: pad, age: age };
})();