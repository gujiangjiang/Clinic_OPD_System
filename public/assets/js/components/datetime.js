/**
 * ============================================================
 * datetime.js v1.0.0 — 日期时间工具
 * ============================================================
 * 说明：日期格式化、根据出生日期计算年龄、
 * 叫号屏实时时钟等时间相关工具函数。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.datetime = (function () {
    /**
     * 补零
     * @param {number} n 数字
     * @returns {string} 两位字符串
     */
    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    /**
     * 格式化日期时间
     * @param {string|Date} date 日期（字符串或对象）
     * @param {string} fmt 格式：'Y-m-d H:i:s' / 'Y-m-d' / 'H:i:s' 等
     * @returns {string} 格式化结果
     */
    function format(date, fmt) {
        const d = date ? new Date(date) : new Date();
        if (isNaN(d.getTime())) return '';
        fmt = fmt || 'Y-m-d H:i:s';
        const map = {
            Y: d.getFullYear(),
            m: pad(d.getMonth() + 1),
            d: pad(d.getDate()),
            H: pad(d.getHours()),
            i: pad(d.getMinutes()),
            s: pad(d.getSeconds()),
        };
        return fmt.replace(/[YmdHis]/g, function (c) { return map[c]; });
    }

    /**
     * 根据出生日期计算年龄
     * @param {string} birthDate 出生日期（YYYY-MM-DD）
     * @returns {number} 年龄
     */
    function age(birthDate) {
        if (!birthDate) return 0;
        const bd = new Date(birthDate);
        if (isNaN(bd.getTime())) return 0;
        const now = new Date();
        let a = now.getFullYear() - bd.getFullYear();
        const m = now.getMonth() - bd.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < bd.getDate())) a--;
        return a < 0 ? 0 : a;
    }

    /**
     * 启动实时时钟（叫号屏等场景）
     * @param {string|HTMLElement} el    目标元素选择器或元素
     * @param {string} fmt 格式（默认含星期）
     */
    function clock(el, fmt) {
        const target = typeof el === 'string' ? document.querySelector(el) : el;
        if (!target) return null;
        const weekMap = ['日', '一', '二', '三', '四', '五', '六'];
        function tick() {
            const d = new Date();
            const text = format(d, fmt || 'Y年m月d日') +
                ' 星期' + weekMap[d.getDay()] + ' ' +
                pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
            target.textContent = text;
        }
        tick();
        const timer = setInterval(tick, 1000);
        return { stop: function () { clearInterval(timer); } };
    }

    return { format: format, age: age, clock: clock };
})();
