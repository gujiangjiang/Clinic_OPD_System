/**
 * ============================================================
 * validation.js v1.0.0 — 表单校验工具
 * ============================================================
 * 说明：身份证号严格校验（18 位 + 校验码）、
 * 必填检查、手机号、数字等常用校验函数。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.validate = (function () {
    /** 身份证加权因子 */
    const WEIGHTS = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
    /** 校验码映射表 */
    const CODES = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];

    /**
     * 校验 18 位身份证号码（含校验码算法）
     * @param {string} id 身份证号
     * @returns {boolean} 是否合法
     */
    function idCard(id) {
        if (!id || typeof id !== 'string') return false;
        id = id.trim().toUpperCase();
        if (!/^\d{17}[\dX]$/.test(id)) return false;

        // 出生日期有效性检查
        const birth = id.substring(6, 14);
        const y = parseInt(birth.substring(0, 4), 10);
        const m = parseInt(birth.substring(4, 6), 10);
        const d = parseInt(birth.substring(6, 8), 10);
        const date = new Date(y, m - 1, d);
        if (date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d) {
            return false;
        }
        // 不能是未来日期
        if (date > new Date()) return false;

        // 校验码计算
        let sum = 0;
        for (let i = 0; i < 17; i++) {
            sum += parseInt(id.charAt(i), 10) * WEIGHTS[i];
        }
        return CODES[sum % 11] === id.charAt(17);
    }

    /**
     * 从身份证提取性别
     * @param {string} id 身份证号
     * @returns {string} 男/女
     */
    function genderFromId(id) {
        const n = parseInt(id.charAt(16), 10);
        return (n % 2 === 1) ? '男' : '女';
    }

    /**
     * 从身份证提取出生日期（YYYY-MM-DD）
     * @param {string} id 身份证号
     * @returns {string} 出生日期
     */
    function birthFromId(id) {
        const b = id.substring(6, 14);
        return b.substring(0, 4) + '-' + b.substring(4, 6) + '-' + b.substring(6, 8);
    }

    /**
     * 从身份证计算年龄
     * @param {string} id 身份证号
     * @returns {number} 年龄
     */
    function ageFromId(id) {
        const birth = birthFromId(id);
        const now = new Date();
        const bd = new Date(birth);
        let age = now.getFullYear() - bd.getFullYear();
        const md = now.getMonth() - bd.getMonth();
        if (md < 0 || (md === 0 && now.getDate() < bd.getDate())) age--;
        return age < 0 ? 0 : age;
    }

    /**
     * 按出生日期计算周岁数字（入库快照用；展示请用 formatAge）
     */
    function ageFromBirth(birth) {
        if (!birth) return 0;
        const bd = new Date(String(birth).replace(' ', 'T'));
        if (isNaN(bd.getTime())) return 0;
        const now = new Date();
        let age = now.getFullYear() - bd.getFullYear();
        const md = now.getMonth() - bd.getMonth();
        if (md < 0 || (md === 0 && now.getDate() < bd.getDate())) age--;
        return age < 0 ? 0 : age;
    }

    /**
     * 全年龄段医疗格式化年龄（EMR 规范，与服务端 age_format() 规则一致）：
     *   <24小时 → X小时/X小时Y分（不足1小时 Y分）；1~28天 → X天；
     *   <12个月 → X月/X月Y天（未满1月按天）；1~5岁 → X岁Y月；≥6岁 → X岁
     * @param {string} birth   出生日期（'Y-m-d' 或 'Y-m-d H:i:s'）
     * @param {string} target  目标时间（默认当前；可传就诊时间）
     * @returns {string} 异常返回 ''
     */
    function formatAge(birth, target) {
        if (!birth) return '';
        const b = new Date(String(birth).replace(' ', 'T'));
        const t = target ? new Date(String(target).replace(' ', 'T')) : new Date();
        if (isNaN(b.getTime()) || isNaN(t.getTime()) || t < b) return '';
        const secs = Math.floor((t.getTime() - b.getTime()) / 1000);
        if (secs < 86400) {
            const h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60);
            if (h > 0) return m > 0 ? h + '小时' + m + '分' : h + '小时';
            return m + '分';
        }
        // 日历精确 y/m/d 差值（借位按上一月实际天数，自动处理大小月/闰年）
        let y = t.getFullYear() - b.getFullYear();
        let mo = t.getMonth() - b.getMonth();
        let d = t.getDate() - b.getDate();
        if (d < 0) { mo--; d += new Date(t.getFullYear(), t.getMonth(), 0).getDate(); }
        if (mo < 0) { y--; mo += 12; }
        const totalDays = Math.floor(secs / 86400);
        const monthsTotal = y * 12 + mo;
        if (totalDays <= 28) return totalDays + '天';
        if (monthsTotal < 12) {
            if (monthsTotal < 1) return totalDays + '天';
            return d > 0 ? monthsTotal + '月' + d + '天' : monthsTotal + '月';
        }
        if (y < 6) return mo > 0 ? y + '岁' + mo + '月' : y + '岁';
        return y + '岁';
    }

    /**
     * 必填校验：检查表单中带 data-required 的控件
     * @param {HTMLElement} form 表单容器
     * @returns {boolean} 是否全部通过
     */
    function required(form) {
        const fields = form.querySelectorAll('[data-required]');
        let ok = true;
        fields.forEach(function (f) {
            const label = f.getAttribute('data-required');
            const val = (f.value || '').trim();
            if (!val) {
                Clinic.toast.warning('请填写：' + label);
                f.focus();
                f.classList.add('input-error');
                ok = false;
                return;
            }
            f.classList.remove('input-error');
        });
        return ok;
    }

    /**
     * 手机号校验
     * @param {string} phone 手机号
     * @returns {boolean}
     */
    function phone(phone) {
        return /^1[3-9]\d{9}$/.test(phone || '');
    }

    /**
     * 数字校验
     * @param {*} v 值
     * @returns {boolean}
     */
    function number(v) {
        return v !== '' && !isNaN(Number(v)) && isFinite(Number(v));
    }

    return {
        idCard: idCard,
        genderFromId: genderFromId,
        birthFromId: birthFromId,
        ageFromId: ageFromId,
        ageFromBirth: ageFromBirth,
        formatAge: formatAge,
        required: required,
        phone: phone,
        number: number,
    };
})();
