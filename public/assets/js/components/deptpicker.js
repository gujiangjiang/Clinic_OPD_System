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
            // 作息时间：门诊科室非放号时段（未上班/午休/已下班）置灰不可选，急诊 24 小时
            if (d.type === 'clinic' && d.bookable === false) {
                cls += ' disabled';
                extra = '<div class="dept-pick-tags"><span class="badge badge-gray">' + (window.__deptPickStateText || '停挂') + '</span></div>' +
                    '<div class="dept-pick-sub"><span class="text-muted">当前非放号时段</span></div>';
            } else if (opts.onlyFree && d.fee > 0) {
                cls += ' disabled';
                extra = '<div class="dept-pick-tags"><span class="badge badge-gray">需实名挂号</span></div>' +
                    '<div class="dept-pick-sub"><span class="text-muted">挂号费 ¥' + d.fee.toFixed(2) + '</span></div>';
            } else if (d.type === 'emergency') {
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
        } else if (mode === 'select' || mode === 'call') {
            // 大屏统计模式（叫号大屏选择科室用）：只显示 🖥️ 在线/总数，不显示 门诊/急诊 徽章
            if (opts.showRoomStats) {
                if (typeof d.room_count === 'number' && d.room_count > 0) {
                    extra = '<div class="dept-pick-tags">' +
                        '<span class="badge badge-' + (d.online_count > 0 ? 'success' : 'gray') + '">' +
                        '🖥️ ' + d.online_count + '/' + d.room_count + ' 在线</span>' +
                        (d.id === opts.currentId ? '<span class="badge badge-success">当前</span>' : '') + '</div>';
                } else {
                    extra = '<div class="dept-pick-tags">' +
                        '<span class="badge badge-gray">无大屏</span>' +
                        (d.id === opts.currentId ? '<span class="badge badge-success">当前</span>' : '') + '</div>';
                }
                if (d.id === opts.currentId) cls += ' active';
            } else {
                // 常规 select（医生站切换/转科）：显示 门诊/急诊 徽章
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
        function render(list, schedule) {
            // 作息提示：非放号时段在弹窗顶部展示原因
            window.__deptPickStateText = '停挂';
            var notice = '';
            if (opts.mode === 'register' && schedule && schedule.msg) {
                notice = '<div class="mb-8" style="background:var(--warning-soft);color:var(--warning);border-radius:8px;padding:8px 12px;font-size:12px">⏰ ' + schedule.msg + '</div>';
                if (schedule.state === 'noon') window.__deptPickStateText = '午休';
                else if (schedule.state === 'after') window.__deptPickStateText = '已下班';
                else if (schedule.state === 'before') window.__deptPickStateText = '未开放';
            }
            var byType = { emergency: [], clinic: [], tech: [], other: [] };
            (list || []).forEach(function (d) {
                var t = d.type;
                if (t === 'emergency' || t === 'tech' || t === 'other') byType[t].push(d);
                else byType.clinic.push(d);   // 未知类型归门诊
            });

            // 无身份证挂号：仅急诊可用，门诊 Tab 置灰提示
            var lockClinic = (opts.mode === 'register' && opts.noIdCard);

            // 叫号大屏（call）显示 急诊/门诊/医技/其他 四个 Tab；
            // 医生站/挂号/转科等仅急诊/门诊，医技/其他科室自动过滤不渲染
            var tabKeys = (opts.mode === 'call') ? ['emergency', 'clinic', 'tech', 'other'] : ['emergency', 'clinic'];

            function tabHtml(key, label, count) {
                var disabled = key === 'clinic' && lockClinic;
                return '<button type="button" class="dept-tab" data-tab="' + key + '"' +
                    (disabled ? ' disabled title="填写身份证后可挂门诊"' : '') + '>' +
                    label + ' <span class="dept-tab-count">' + count + '</span></button>';
            }

            var tabDefs = {
                emergency: ['🚑 急诊', byType.emergency.length],
                clinic: ['🏥 门诊', byType.clinic.length],
                tech: ['🧪 医技', byType.tech.length],
                other: ['📦 其他', byType.other.length],
            };
            var emptyText = { emergency: '暂无急诊科室', clinic: '暂无门诊科室', tech: '暂无医技科室', other: '暂无其他科室' };

            var tabs =
                '<div class="dept-tabs">' +
                tabKeys.map(function (k) { return tabHtml(k, tabDefs[k][0], tabDefs[k][1]); }).join('') +
                '</div>' +
                (lockClinic ? '<div class="fs-12 text-warning mb-8">⚠️ 未填写身份证号码时仅可挂急诊科室（自费）；填写身份证后可挂全部门诊科室</div>' : '');

            var grids = tabKeys.map(function (key) {
                var cards = byType[key].map(function (d) { return cardHtml(d, opts, key); }).join('');
                if (!cards) {
                    cards = '<div class="text-muted fs-13" style="grid-column:1/-1;padding:18px 0;text-align:center">' +
                        (emptyText[key] || '暂无科室') + '</div>';
                }
                return '<div class="dept-tab-panel" data-panel="' + key + '" style="display:none">' +
                    '<div class="dept-pick-grid">' + cards + '</div></div>';
            }).join('');

            m.querySelector('.modal-body').innerHTML = notice + tabs + grids +
                '<div class="fs-12 text-muted mt-8">' +
                (opts.mode === 'register'
                    ? (opts.onlyFree
                        ? '快速挂号（无名氏）仅可挂挂号费为 0 元的科室，其余科室需实名挂号。'
                        : '点击科室卡片完成选择；门诊号源实时显示，满号科室可联系医生工作站加号。')
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

            /* 默认 Tab：
               · 非挂号模式且已选择科室（currentId）→ 定位到该科室所在 Tab
                 （如当前选药房 → 「其他」，选检验科 → 「医技」）
               · 未选择科室 → 默认「门诊」（叫号大屏 / 医生站）
               · 挂号：无身份证或指定 → 急诊，否则门诊 */
            var def = 'clinic';
            if (opts.mode !== 'register' && opts.currentId) {
                var curDept = null;
                (list || []).forEach(function (x) { if (x.id === opts.currentId) curDept = x; });
                if (curDept && curDept.type && tabKeys.indexOf(curDept.type) !== -1) def = curDept.type;
            } else if (opts.mode === 'register') {
                def = (opts.defaultTab === 'emergency' || lockClinic) ? 'emergency' : 'clinic';
            }
            if (!byType[def] || !byType[def].length) {
                def = (byType.clinic.length && !lockClinic) ? 'clinic' : 'emergency';
            }
            activate(def);

            /* 卡片点击 */
            m.querySelectorAll('.dept-pick-card').forEach(function (el) {
                el.addEventListener('click', function () {
                    var id = parseInt(el.getAttribute('data-id'), 10);
                    var d = null;
                    (list || []).forEach(function (x) { if (x.id === id) d = x; });
                    if (!d) return;
                    if (opts.mode === 'register' && d.type === 'clinic' && d.bookable === false) {
                        Clinic.toast.warning('【' + d.name + '】当前非放号时段：' + (window.__deptPickMsg || '请在作息时间内挂号'));
                        return;
                    }
                    if (opts.mode === 'register' && opts.onlyFree && d.fee > 0) {
                        Clinic.toast.warning('快速挂号（无名氏）仅可挂挂号费为 0 元的科室');
                        return;
                    }
                    if (opts.mode === 'register' && d.type !== 'emergency' && d.full) {
                        Clinic.toast.warning('【' + d.name + '】今日号源已满，请联系医生工作站加号');
                        return;
                    }
                    if ((opts.mode === 'select' || opts.mode === 'call') && d.id === opts.currentId) {
                        Clinic.toast.info('当前已在该科室');
                        return;
                    }
                    Clinic.modal.close();
                    if (opts.onSelect) opts.onSelect(d);
                });
            });
        }

        if (opts.mode === 'select' || opts.mode === 'call') {
            render(opts.depts || []);
        } else {
            Clinic.get(opts.fetchUrl, null, {
                onSuccess: function (json) {
                    if (json.data.schedule && json.data.schedule.msg) {
                        window.__deptPickMsg = json.data.schedule.msg;
                    }
                    render(json.data.list || [], json.data.schedule);
                },
            });
        }
        return m;
    }

    return { open: open };
})();
