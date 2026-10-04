/**
 * vitals.js v1.0.0 — 生命体征录入悬浮窗组件
 * ============================================================
 * 说明：医生工作站（emr.js）与护士站工作台（nurse/dashboard.php）
 * 共用同一套「生命体征编辑」悬浮窗：6 项输入 + 非负整数/生理区间
 * 校验 + 视口内夹紧 + 外部点击关闭。差异（预填、提交地址、保存后
 * 刷新）由调用方经 opts 注入。
 * 依赖：ajax.js / toast.js
 * ============================================================ */
window.Clinic = window.Clinic || {};

Clinic.vitals = (function () {
    var outsideHandler = null;

    /** 关闭悬浮窗（移除 DOM + 清理外部点击监听） */
    function close() {
        var pop = document.getElementById('vitalsPop');
        if (pop) pop.remove();
        if (outsideHandler) {
            document.removeEventListener('mousedown', outsideHandler);
            outsideHandler = null;
        }
    }

    /**
     * 打开生命体征编辑悬浮窗
     * @param {object} opts
     *   cx/cy     点击处视口坐标（缺省居中偏上）
     *   hint      底部提示文案
     *   exempt    外部点击豁免选择器（如 'a#vitalLink'，点击其不关闭，交由触发方 toggle）
     *   prefill   预填对象；或 function(fill) 异步取值后回调 fill(value)
     *   onSubmit  保存回调 (vals, done)——vals 为清洗后的值
     *             （vSys/vDia 为数字，其余为字符串或 ''）；校验通过后
     *             调用方负责 ajax 提交，成功后调用 done() 关闭。
     */
    function open(opts) {
        opts = opts || {};
        // 已打开则先收起（再次触发 = 关闭）
        if (document.getElementById('vitalsPop')) { close(); return; }
        var cx = typeof opts.cx === 'number' ? opts.cx : window.innerWidth / 2 - 150;
        var cy = typeof opts.cy === 'number' ? opts.cy : 120;
        var pop = document.createElement('div');
        pop.id = 'vitalsPop';
        pop.className = 'finish-pop vitals-pop';
        pop.innerHTML =
            '<div class="fs-13 fw-700 mb-8">生命体征编辑</div>' +
            '<div class="vitals-grid">' +
            '  <div><label class="form-label">收缩压 mmHg</label><input class="input" id="vSys" type="number" min="0" value=""></div>' +
            '  <div><label class="form-label">舒张压 mmHg</label><input class="input" id="vDia" type="number" min="0" value=""></div>' +
            '  <div><label class="form-label">心率 次/分</label><input class="input" id="vHR" value=""></div>' +
            '  <div><label class="form-label">脉搏 次/分</label><input class="input" id="vPulse" value=""></div>' +
            '  <div><label class="form-label">血氧饱和度 %</label><input class="input" id="vSpO2" value=""></div>' +
            '  <div><label class="form-label">呼吸 次/分</label><input class="input" id="vResp" value=""></div>' +
            '</div>' +
            '<div class="fs-12 text-muted mt-4">' + (opts.hint || '保存后将同步显示。') + '</div>' +
            '<div class="flex gap-8 mt-8">' +
            '  <button type="button" class="btn btn-outline btn-sm" style="flex:1" id="vitalsCancel">取消</button>' +
            '  <button type="button" class="btn btn-primary btn-sm" style="flex:1" id="vitalsSave">保存</button>' +
            '</div>';
        document.body.appendChild(pop);
        // fixed 定位跟随点击处，视口内夹紧（四边均保持 8px 边距）
        pop.style.left = (cx + 12) + 'px';
        pop.style.top = (cy + 12) + 'px';
        var r = pop.getBoundingClientRect();
        var m = 8;
        if (r.right > window.innerWidth - m) pop.style.left = Math.max(m, window.innerWidth - r.width - m) + 'px';
        if (r.bottom > window.innerHeight - m) pop.style.top = Math.max(m, window.innerHeight - r.height - m) + 'px';
        if (r.left < m) pop.style.left = m + 'px';
        if (r.top < m) pop.style.top = m + 'px';
        pop.querySelector('#vitalsCancel').addEventListener('click', close);
        pop.querySelector('#vitalsSave').addEventListener('click', function () {
            // 数值校验：整数、生理合理区间；留空视为未测
            var spec = [
                { id: 'vSys', label: '收缩压', min: 1, max: 300 },
                { id: 'vDia', label: '舒张压', min: 1, max: 250 },
                { id: 'vHR', label: '心率', min: 1, max: 300 },
                { id: 'vPulse', label: '脉搏', min: 1, max: 300 },
                { id: 'vSpO2', label: '血氧饱和度', min: 1, max: 100 },
                { id: 'vResp', label: '呼吸', min: 1, max: 100 },
            ];
            var vals = {};
            for (var i = 0; i < spec.length; i++) {
                var s = spec[i];
                var raw = document.getElementById(s.id).value.trim();
                if (raw === '') { vals[s.id] = ''; continue; }
                if (!/^\d+$/.test(raw)) { Clinic.toast.warning(s.label + '须为非负整数（不留小数 / 负数 / 单位）'); return; }
                var n = parseInt(raw, 10);
                if (n !== 0 && (n < s.min || n > s.max)) {
                    Clinic.toast.warning(s.label + '超出合理范围（' + s.min + '-' + s.max + '）');
                    return;
                }
                vals[s.id] = raw;
            }
            if (opts.onSubmit) opts.onSubmit(vals, close);
        });
        // 点击面板以外区域关闭（exempt 指定元素不触发关闭，由触发方 toggle）
        outsideHandler = function (e) {
            var ex = opts.exempt ? document.querySelector(opts.exempt) : null;
            if (!pop.contains(e.target) && !(ex && ex.contains(e.target))) close();
        };
        setTimeout(function () { document.addEventListener('mousedown', outsideHandler); }, 0);
        // 预填（支持异步）
        var fill = function (v) {
            v = v || {};
            var map = { vSys: 'vital_sbp', vDia: 'vital_dbp', vHR: 'vital_heart_rate', vPulse: 'vital_pulse', vSpO2: 'vital_spo2', vResp: 'vital_respiration' };
            Object.keys(map).forEach(function (k) {
                var el = document.getElementById(k);
                var val = v[map[k]];
                if (el && val !== undefined && val !== null) el.value = val;
            });
        };
        if (opts.prefill) {
            if (typeof opts.prefill === 'function') opts.prefill(fill);
            else fill(opts.prefill);
        }
        return pop;
    }

    return { open: open, close: close };
})();
