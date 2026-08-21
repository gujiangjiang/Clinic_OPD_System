/**
 * ============================================================
 * deptpicker.js v1.0.0 — 通用科室选择模态框
 * ============================================================
 * 说明：参考医生站「切换科室」卡片式弹窗抽象出的通用组件，
 * 同一套 UI 复用于：挂号选科室 / 医生选择(切换)科室 / 转科。
 *
 * Clinic.deptPicker.open(opts):
 *   opts.mode       'register' 挂号（显示 急诊/门诊 子 Tab、剩余号源、挂号金额）
 *                   'select'   医生选择/切换科室（不显示挂号信息，标记当前科室）
 *                   'transfer' 转科（隐藏当前科室，不显示挂号信息）
 *   opts.title      弹窗标题（默认按 mode 取）
 *   opts.fetchUrl   register/transfer 模式的数据接口（GET，返回 {list:[...]})
 *   opts.depts      select 模式直接传入科室数组（免请求）
 *   opts.currentId  select/transfer 模式的当前科室 ID
 *   opts.noIdCard   register 模式：未填身份证（仅急诊 Tab 可用）
 *   opts.onSelect(dept)  点击科室卡片回调（挂号满号卡片会拦截并提示）
 *
 * 科室数据字段约定：{ id, name, type:'clinic'|'emergency',
 *   fee, remaining, full }（fee/remaining/full 仅挂号模式使用）
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.deptPicker = (function () {

    /** 类型中文名 */
    function typeName(t) {
        return t === 'emergency' ? '急诊' : '门诊';
    }

    /**
     * 渲染单个科室卡片
     * @param {object} d    科室数据
     * @param {object} opts open 选项
     * @param {string} tab  所属 Tab 标识（emergency/clinic）
     */
    function cardHtml(d, opts, tab) {
        var mode = opts.mode;
        var cls = 'dept-pick-card';
        var extra = '';

        if (mode === 'register') {
            if (d.type === 'emergency') {
                extra = '<div class="dept-pick-tags"><span class="badge badge-danger">急诊 · 不限号</span></div>' +
                    '<div class="dept-pick-sub">挂号费 ¥' + d.fee.toFixed(2) + '</div>';
            } else if (d.full) {
                cls += ' disabled';
                extra = '<div class="dept-pick-tags"><span class="badge badge-danger">已满号</span></div>' +
                    '<div class="dept-pick-sub"><span class="text-muted">余 0 号 · 可联系医生加号</span></div>';
            } else {
                extra = '<div class="dept-pick-tags"><span class="badge badge-success">余 ' + d.remaining + ' 号</span></div>' +
                    '<div class="dept-pick-sub">挂号费 ¥' + d.fee.toFixed(2) + '</div>';
            }
        } else if (mode === 'select') {
            if (d.id === opts.currentId) {
                cls += ' active';
                extra = '<div class="dept-pick-tags">' +
                    '<span class="badge badge-' + (d.type === 'emergency' ? 'danger' : 'primary') + '">' + typeName(d.type) + '</span>' +
                    '<span class="badge badge-success">当前</span></div>';
            } else {
                extra = '<div class="dept-pick-tags">' +
                    '<span class="badge badge-' + (d.type === 'emergency' ? 'danger' : 'primary') + '">' + typeName(d.type) + '</span>' +
                    (d.limited ? '<span class="badge badge-warning">限号</span>' : '') + '</div>';
            }
        } else { // transfer：目标科室列表（服务端已排除当前科室）
            extra = '<div class="dept-pick-tags">' +
                '<span class="badge badge-' + (d.type === 'emergency' ? 'danger' : 'primary') + '">' + typeName(d.type) + '</span></div>';
        }

        return '<div class="' + cls + '" data-id="' + d.id + '" data-tab="' + tab + '">' +
            '<div class="dept-pick-name">' + d.name + '</div>' + extra + '</div>';
    }

    /**
     * 打开科室选择弹窗
     */
    function open(opts) {
        opts = opts || {};
        var titles = { register: '选择挂号科室', select: '选择科室', transfer: '选择转往科室' };
        var m = Clinic.modal.open(
            '<div class="text-center" style="padding:30px"><div class="spinner" style="border-top-color:var(--primary)"></div></div>',
            { title: opts.title || titles[opts.mode] || '选择科室', size: 'modal-lg' }
        );

        /* 数据就绪后渲染（select 模式同步，其余走接口） */
        function render(list) {
            var byType = { emergency: [], clinic: [] };
            (list || []).forEach(function (d) {
                var t = d.type === 'emergency' ? 'emergency' : 'clinic';
                byType[t].push(d);
            });

            // 无身份证挂号：仅急诊可用，门诊 Tab 置灰提示
            var lockClinic = (opts.mode === 'register' && opts.noIdCard);

            function tabHtml(key, label, count) {
                var disabled = key === 'clinic' && lockClinic;
                return '<button type="button" class="dept-tab" data-tab="' + key + '"' +
                    (disabled ? ' disabled title="填写身份证后可挂门诊"' : '') + '>' +
                    label + ' <span class="dept-tab-count">' + count + '</span></button>';
            }

            var tabs =
                '<div class="dept-tabs">' +
                tabHtml('emergency', '🚑 急诊', byType.emergency.length) +
                tabHtml('clinic', '🏥 门诊', byType.clinic.length) +
                '</div>' +
                (lockClinic ? '<div class="fs-12 text-warning mb-8">⚠️ 未填写身份证号码时仅可挂急诊科室（自费）；填写身份证后可挂全部门诊科室</div>' : '');

            var grids = ['emergency', 'clinic'].map(function (key) {
                var cards = byType[key].map(function (d) { return cardHtml(d, opts, key); }).join('');
                if (!cards) {
                    cards = '<div class="text-muted fs-13" style="grid-column:1/-1;padding:18px 0;text-align:center">' +
                        (key === 'emergency' ? '暂无急诊科室' : '暂无门诊科室') + '</div>';
                }
                return '<div class="dept-tab-panel" data-panel="' + key + '" style="display:none">' +
                    '<div class="dept-pick-grid">' + cards + '</div></div>';
            }).join('');

            m.querySelector('.modal-body').innerHTML = tabs + grids +
                '<div class="fs-12 text-muted mt-8">' +
                (opts.mode === 'register'
                    ? '点击科室卡片完成选择；门诊号源实时显示，满号科室可联系医生工作站加号。'
                    : (opts.mode === 'transfer'
                        ? '点击科室卡片选择转往科室（当前科室已排除）。'
                        : '点击科室卡片即可选择。')) +
                '</div>';

            /* Tab 切换 */
            function activate(key) {
                m.querySelectorAll('.dept-tab').forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute('data-tab') === key);
                });
                m.querySelectorAll('.dept-tab-panel').forEach(function (p) {
                    p.style.display = p.getAttribute('data-panel') === key ? '' : 'none';
                });
            }
            m.querySelectorAll('.dept-tab').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (b.disabled) { Clinic.toast.warning('未填写身份证号码时仅可挂急诊科室'); return; }
                    activate(b.getAttribute('data-tab'));
                });
            });

            /* 默认 Tab：门诊可选（且挂号时已填身份证）则默认门诊，否则急诊 */
            var def = (byType.clinic.length && !lockClinic) ? 'clinic' : 'emergency';
            activate(def);

            /* 卡片点击 */
            m.querySelectorAll('.dept-pick-card').forEach(function (el) {
                el.addEventListener('click', function () {
                    var id = parseInt(el.getAttribute('data-id'), 10);
                    var d = null;
                    (list || []).forEach(function (x) { if (x.id === id) d = x; });
                    if (!d) return;
                    if (opts.mode === 'register' && d.type !== 'emergency' && d.full) {
                        Clinic.toast.warning('【' + d.name + '】今日号源已满，请联系医生工作站加号');
                        return;
                    }
                    if (opts.mode === 'select' && d.id === opts.currentId) {
                        Clinic.toast.info('当前已在该科室');
                        return;
                    }
                    Clinic.modal.close();
                    if (opts.onSelect) opts.onSelect(d);
                });
            });
        }

        if (opts.mode === 'select') {
            render(opts.depts || []);
        } else {
            Clinic.get(opts.fetchUrl, null, {
                onSuccess: function (json) { render(json.data.list || []); },
            });
        }
        return m;
    }

    return { open: open };
})();
