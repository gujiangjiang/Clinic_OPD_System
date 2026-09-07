/**
 * deptwork.js v1.0.0 — 科室工作台共用组件（护士站/检验科/影像科/药房）
 * ============================================================
 * 说明：四个医技角色工作台共用同一套交互框架，与医生工作站布局一致：
 *   1. 顶部患者信息横条（#dwHeader）+ 候诊按钮（#queueBtn）+ 状态 + 操作按钮组
 *      （叫号 #dwCallBtn / 返回 #dwHomeBtn）
 *   2. 左侧候诊列表（弹层面板）：页签按角色由接口下发（检验中/检查中/待发药/
 *      待处置/完成/当日），一行=一位患者，点击加载该患者工作台
 *   3. 主工作区（#dwMain）+ 右栏大纲（#dwSide）：由各角色 configure 注入的
 *      render(data) 渲染（所见即所得报告单 / 检验值列表 / 处方单 / 护理记录页）
 *   4. 叫号：科室排队悬浮窗（当前处理中/下一位/候诊队列，点击直接跳转患者），
 *      复用医生叫号悬浮窗样式，10 秒轮询
 * 数据接口：/api/deptwork（queue / queue_pref / patient / call_panel）
 * 依赖：ajax.js / modal.js / toast.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.deptwork = (function () {

    var ROLE = '';            // nurse / lab / imaging / pharmacy
    var RENDER = null;        // 角色个性化渲染 render(data)：负责 #dwMain + #dwSide
    var AFTER_ACTION = null;  // 角色操作后回调（可选，用于刷新局部数据）
    var DATA = null;          // queue 接口缓存
    var STATUS = 'doing';     // 状态页签（doing/done，互斥单选）
    var TODAY = false;        // 当日叠加筛选（可选）
    var TAB_LABELS = {};      // 页签名 {doing:'检查中', done:'完成', today:'当日'}
    var KEYWORD = '';         // 候诊搜索关键字（面板关闭清空）
    var VISIT = '';           // 当前患者混淆码
    var QUEUE_TIMER = null;   // 候诊数据 30s 轮询
    var CALL_TIMER = null;    // 排队悬浮窗 10s 轮询
    var PANEL_OPEN = false;
    var PANEL_BIND = null;  // 面板外部点击/Esc 关闭解绑句柄（queuePanelCore.bindClose 返回）
    var CALL_CACHE = null;    // 最近一次排队数据缓存
    var PREF_APPLIED = false; // 是否已应用登录会话记忆的页签

    function escHtml(s) { return Clinic.escHtml(s); }

    function pad3(n) { return Clinic.pad3(n); }

    /* ==================== 配置与初始化 ==================== */
    function configure(opts) {
        ROLE = opts.role || '';
        RENDER = opts.render || null;
        AFTER_ACTION = opts.afterAction || null;
    }

    function init() {
        if (!document.getElementById('dwMain')) return;
        bindButtons();
        // 深链 / 刷新保持：URL 带 visit_id 直接加载该患者
        var m = (location.search.match(/[?&]visit_id=([^&]+)/) || [])[1];
        if (m) {
            loadPatient(decodeURIComponent(m));
        } else {
            // 无患者：显示侧边栏占位符 + 关闭按钮隐藏 + 自动弹出候诊列表
            renderSidePlaceholder();
            setCloseBtn(false);
            setTimeout(function () { openPanel(); }, 150);
        }
        // 候诊数据轮询（计数/列表实时刷新；离开工作台页面自动停止，避免后台空轮询）
        if (QUEUE_TIMER) clearInterval(QUEUE_TIMER);
        QUEUE_TIMER = setInterval(function () {
            if (!document.getElementById('dwMain')) {
                clearInterval(QUEUE_TIMER);
                QUEUE_TIMER = null;
                return;
            }
            loadQueue(true);
        }, 30000);
    }

    /* ==================== 关闭按钮显隐 ==================== */
    function setCloseBtn(show) {
        var btn = document.getElementById('dwHomeBtn');
        if (btn) btn.style.display = show ? '' : 'none';
    }

    /* ==================== 无患者时侧边栏占位（参照病历右侧侧边栏空态） ==================== */
    function renderSidePlaceholder() {
        var side = document.getElementById('dwSide');
        if (!side) return;
        var cfg = {
            nurse: '📝 护理记录',
            lab: '🧪 检验项目',
            imaging: '🩻 检查项目',
            pharmacy: '💊 处方',
        }[ROLE] || '📋 项目';
        side.innerHTML =
            '<div class="ena-sec"><div class="ena-sec-title">' + cfg + '</div>' +
            '<div class="ena-empty">暂无患者，请从候诊列表选择</div></div>' +
            '<div class="ena-sec"><div class="ena-sec-title">📋 病历摘要</div>' +
            '<div class="ena-empty">暂无病历</div></div>';
    }

    function bindButtons() {
        var qb = document.getElementById('queueBtn');
        if (qb) qb.addEventListener('click', function () {
            if (panelEl()) closePanel(); else openPanel();
        });
        var hb = document.getElementById('dwHomeBtn');
        if (hb) hb.addEventListener('click', closePatient);
        // 叫号/工具箱按钮位于顶栏（Layout::deptToolsBar 注入），通过内联 onclick 调用，
        // 无需在此绑定（避免与内联 onclick 重复触发）
    }

    /* ==================== 关闭当前患者（返回空白工作台 + 自动弹出候诊列表） ==================== */
    function renderEmptyWork() {
        var conf = {
            nurse: { emoji: '💉', title: '欢迎使用护士工作站' },
            lab: { emoji: '🧪', title: '欢迎使用检验科工作台' },
            imaging: { emoji: '🩻', title: '欢迎使用影像科工作台' },
            pharmacy: { emoji: '💊', title: '欢迎使用药房工作台' },
        }[ROLE] || { emoji: '🏥', title: '欢迎使用工作台' };
        var main = document.getElementById('dwMain');
        if (main) main.innerHTML = '<div class="card wb-empty" style="padding:40px 20px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center">' +
            '<div style="font-size:72px;margin-bottom:16px">' + conf.emoji + '</div>' +
            '<div class="fs-18 fw-600 text-muted">' + conf.title + '</div>' +
            '<div class="fs-12 text-muted mt-8">候诊列表已自动打开，点击患者即可进入工作台</div></div>';
        var side = document.getElementById('dwSide');
        if (side) side.innerHTML = '';
        var head = document.getElementById('dwHeader');
        if (head) head.innerHTML = '';
        setStatus('');
    }
    function closePatient() {
        VISIT = '';
        closePanel();
        closeCallPop();
        setCloseBtn(false);
        renderEmptyWork();
        renderSidePlaceholder();
        setTimeout(function () { openPanel(); }, 120);
    }
    /* ==================== 顶栏工具箱（下拉） ==================== */
    function toggleToolbox() {
        var box = document.getElementById('dwToolbox');
        if (!box) return;
        box.style.display = box.style.display === 'none' ? 'block' : 'none';
    }
    document.addEventListener('click', function (e) {
        var box = document.getElementById('dwToolbox');
        var btn = document.getElementById('dwToolboxBtn');
        if (!box || box.style.display === 'none') return;
        if (!e.target.closest('#dwToolboxBtn') && !e.target.closest('#dwToolbox')) {
            box.style.display = 'none';
        }
    });

    /* ==================== 患者查询（全部就诊历史，统一走 Clinic.patientSearch 公共组件） ==================== */
    function openPatientSearch() {
        Clinic.patientSearch.open({ idPrefix: 'dwPs', title: '🔍 患者查询' });
    }

    function doPatientSearch() {
        Clinic.patientSearch.doSearch('dwPs');
    }

    function goHome() {
        var map = { nurse: '/nurse/home', lab: '/lab/home', imaging: '/imaging/home', pharmacy: '/pharmacy/home' };
        if (map[ROLE]) Clinic.nav.go(map[ROLE]);
    }

    /* ==================== 患者加载 ==================== */
    function loadPatient(code) {
        closePanel();
        if (!code) return;
        VISIT = code;
        // 注意：SPA 局部刷新下地址栏保持不变（地址栏可能是进入工作台前的旧路径，
        // 不可用 replaceState 写入 visit_id，否则刷新会回到错误地址）
        var main = document.getElementById('dwMain');
        var side = document.getElementById('dwSide');
        var head = document.getElementById('dwHeader');
        if (head) head.innerHTML = '';
        if (side) side.innerHTML = '';
        if (main) main.innerHTML = '<div class="card"><div class="empty"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div><div class="fs-13 text-muted mt-8">正在加载患者工作台…</div></div></div>';
        setStatus('加载中…');
        Clinic.get('/api/deptwork?action=patient&visit_id=' + encodeURIComponent(code), null, {
            onSuccess: function (json) {
                var d = json.data;
                renderHeader(d);
                // 就诊状态已在横条徽章展示，状态位保持空白
                setStatus('');
                setCloseBtn(true);
                if (RENDER) RENDER(d);
                // 排队悬浮窗立即刷新「当前处理中/下一位」
                if (callPopEl()) refreshCallPanel();
            },
            onError: function () {
                if (main) main.innerHTML = '<div class="card"><div class="empty"><div class="empty-ico">⚠️</div>患者数据加载失败，请刷新重试</div></div>';
                setStatus('');
            },
        });
    }

    /** 刷新当前患者（角色操作后调用，如提交报告/发药完成） */
    function reloadPatient() {
        if (VISIT) loadPatient(VISIT);
    }

    function renderHeader(d) {
        var p = d.patient || {}, v = d.visit || {};
        var head = document.getElementById('dwHeader');
        if (!head) return;
        head.innerHTML =
            '<div class="flex-between">' +
            '  <div class="flex gap-12" style="align-items:center">' +
            '    <div class="emr-patient-avatar" title="患者信息">👤</div>' +
            '    <div>' +
            '      <div class="fs-18 fw-700">' +
            '        <span class="emr-patient-name">' + escHtml(v.name) + '</span>' +
            '        <span class="badge badge-gray" style="margin-left:8px">' + escHtml(v.gender) + ' / ' + escHtml(v.age_fmt || '') + '</span>' +
            '        ' + (v.fee_type ? '<span class="badge badge-warning" style="margin-left:4px" title="费用类别">' + escHtml(v.fee_type) + '</span>' : '') +
            '        <span class="badge ' + (v.dept_type === 'emergency' ? 'badge-danger' : 'badge-primary') + '" style="margin-left:4px">' + (v.dept_type === 'emergency' ? '急诊' : '门诊') + '</span>' +
            '        <span class="badge ' + (v.status === 'finished' ? 'badge-gray' : (v.status === 'visiting' ? 'badge-success' : 'badge-primary')) + '" style="margin-left:4px" title="就诊状态">' + visitStatusName(v.status) + '</span>' +
            '        <span class="badge badge-warning" id="hdrTotal" style="display:none"></span>' +
            '      </div>' +
            '      <div class="text-muted fs-13">患者ID：' + escHtml(p.patient_id) + ' ｜ 流水号：' + escHtml(v.visit_no) +
            ' ｜ ' + escHtml(v.first_dept_name || v.dept_name) + ' 第' + pad3(v.visit_seq) + '号' +
            ' ｜ 挂号 ' + escHtml((v.created_at || '').substr(0, 16)) + '</div>' +
            '    </div>' +
            '  </div>' +
            '</div>';
        bindFeeBadge(d);
    }

    /* ==================== 总费用徽章 + 悬浮明细（参照医生工作站横条） ==================== */
    var feePopTimer = null;
    function feeRows(d) {
        var rows = [];
        var total = 0;
        var regFee = (d.visit && d.visit.fee) ? parseFloat(d.visit.fee) : 0;
        var regSt = (d.visit && d.visit.status === 'finished') ? 'done' : 'paid';
        var regDept = (d.visit && d.visit.first_dept_name) || '';
        if (regFee > 0) rows.push({ st: regSt, name: regDept ? ('挂号费（' + regDept + '）') : '挂号费', amt: regFee });
        (d.orders || []).forEach(function (o) {
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            (o.items || []).forEach(function (i2) {
                var amt = (parseFloat(i2.price) || 0) * (parseFloat(i2.quantity) || 1);
                total += amt;
                var st = (i2.status === 'done' || i2.status === 'dispensed') ? 'done'
                    : ((i2.status === 'dispensing' || i2.status === 'registered') ? 'yellow' : 'red');
                rows.push({ st: st, name: i2.item_name, amt: amt });
            });
        });
        total += regFee;
        return { rows: rows, total: total };
    }
    function feeStatusDot(st) {
        var cls = st === 'done' ? 'green' : (st === 'yellow' ? 'yellow' : (st === 'paid' ? 'red' : (st === 'gray' ? 'gray' : 'red')));
        var txt = st === 'done' ? '已完成' : (st === 'yellow' ? '进行中' : (st === 'gray' ? '未缴费' : '待完成'));
        return '<span class="status-indicator ' + cls + '" title="' + txt + '"></span>';
    }
    function showFeePop(anchor, d) {
        if (feePopTimer) { clearTimeout(feePopTimer); feePopTimer = null; }
        var stale = document.getElementById('feePop');
        if (stale) stale.remove();
        var fr = feeRows(d);
        if (!fr.rows.length) return;
        var pop = document.createElement('div');
        pop.id = 'feePop';
        pop.className = 'fee-pop';
        pop.innerHTML = fr.rows.map(function (r) {
            return '<div class="fee-pop-row">' +
                feeStatusDot(r.st) +
                '<span class="fee-pop-name" title="' + escHtml(r.name) + '">' + escHtml(r.name) + '</span>' +
                '<span class="fee-pop-amt">¥' + r.amt.toFixed(2) + '</span></div>';
        }).join('') +
            '<div class="fee-pop-total"><span>合计</span><span>¥' + fr.total.toFixed(2) + '</span></div>';
        document.body.appendChild(pop);
        var rect = anchor.getBoundingClientRect();
        pop.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        pop.style.left = Math.max(8, rect.right + window.scrollX - 270) + 'px';
        pop.addEventListener('mouseenter', function () { if (feePopTimer) { clearTimeout(feePopTimer); feePopTimer = null; } });
        pop.addEventListener('mouseleave', hideFeePop);
    }
    function hideFeePop() {
        if (feePopTimer) clearTimeout(feePopTimer);
        feePopTimer = setTimeout(function () {
            var pop = document.getElementById('feePop');
            if (pop) pop.remove();
        }, 180);
    }
    function bindFeeBadge(d) {
        var total = feeRows(d).total;
        var el = document.getElementById('hdrTotal');
        if (!el) return;
        if (total > 0) {
            el.textContent = '总费用 ¥' + total.toFixed(2);
            el.style.display = '';
            if (!el._feeHover) {
                el._feeHover = true;
                el.addEventListener('mouseenter', function () { showFeePop(el, d); });
                el.addEventListener('mouseleave', hideFeePop);
            }
        } else {
            el.style.display = 'none';
        }
    }

    function setStatus(t) {
        var el = document.getElementById('dwStatus');
        if (el) el.textContent = t || '';
    }

    /**
     * 状态徽章骨架（统一样式）：各角色视图仅需提供中文名与配色，
     * 徽章 HTML 统一在此生成，避免四处重复拼接导致样式漂移。
     * @param {string} name 状态中文名（已查 map）
     * @param {string} cls  badge 配色类（badge-success/warning/danger/gray…）
     * @returns {string}
     */
    function statusBadge(name, cls) {
        return '<span class="badge ' + cls + '" style="font-size:11px">' + name + '</span>';
    }

    /**
     * 右侧申请单/处方大纲（按单号分组，单号可点「+」展开项目）：
     * 检验/影像/药房 三视图共用，差异经 cfg 注入。
     * @param {Array}  orders 已过滤的申请单数组（含 order_id/order_no/items）
     * @param {object} cfg    { emoji, title, empty, pending(o), subItems(o), subDot(it), scrollTo }
     *                        · pending  单号行点色（false=ok 完成 / true=pending 进行中）
     *                        · subItems 展开的子项目数组（药房仅主药）
     *                        · subDot   子项目点色函数
     *                        · scrollTo 页面滚动函数后缀（如 'Lab' → scrollToLab）
     */
    function renderOrderSide(orders, cfg) {
        cfg = cfg || {};
        var subItems = cfg.subItems || function (o) { return o.items || []; };
        var subDot = cfg.subDot || function () { return 'done'; };
        var pending = cfg.pending || function () { return false; };
        var scrollFn = cfg.scrollTo ? 'scrollTo' + cfg.scrollTo : '';
        var sideItems = (orders || []).map(function (o) {
            var subs = subItems(o).map(function (it) {
                return '<div class="dw-side-item dw-side-subitem"><span class="dot ' + subDot(it) + '"></span>' + escHtml(it.item_name) + '</div>';
            }).join('');
            return '<div class="dw-side-order">' +
                '<div class="dw-side-item" onclick="' + scrollFn + '(\'' + escHtml(o.order_id) + '\')">' +
                '<span class="dw-side-plus" id="sidePlus_' + escHtml(o.order_id) + '" title="展开该单项目" ' +
                'onclick="event.stopPropagation();Clinic.deptwork.toggleSideOrder(\'' + escHtml(o.order_id) + '\')">+</span>' +
                '<span class="dot ' + (pending(o) ? 'pending' : 'ok') + '"></span>' +
                '<span class="dw-side-oname">' + escHtml(o.order_no) + '（' + o.items.length + ' 项）</span>' +
                '</div>' +
                '<div class="dw-side-sub" id="sideSub_' + escHtml(o.order_id) + '" style="display:none">' + subs + '</div>' +
                '</div>';
        }).join('');
        document.getElementById('dwSide').innerHTML =
            '<div class="dw-side-sec"><div class="dw-side-title">' + cfg.emoji + ' ' + cfg.title + '（' + (orders || []).length + ' 张）</div>' +
            (sideItems || '<div class="dw-side-item">' + cfg.empty + '</div>') + '</div>';
    }

    /**
     * 患者工作台抬头（医院名称+第二名称+标题+患者信息两行）。
     * 护士站 / 检验科 / 影像科 / 药房 四视图共用同一版式，仅标题文字不同；
     * 统一在此维护，避免四处重复实现导致样式漂移。
     * @param {object} data  /api/deptwork patient 返回（含 visit/patient）
     * @param {string} title 文档标题（如「检 验 报 告 单」）
     * @returns {string} 抬头 HTML
     */
    function headHtml(data, title) {
        var v = data.visit || {}, p = data.patient || {};
        var hosp = document.body.getAttribute('data-hosp') || '';
        var hosp2 = document.body.getAttribute('data-hosp2') || '';
        var cell = function (label, value) {
            return '<div class="dw-line-cell"><span class="lbl">' + escHtml(label) + '：</span><span class="val">' + escHtml(value || '—') + '</span></div>';
        };
        return '<div class="card dw-nurse-doc">' +
            '<div class="dw-hosp-block">' +
            '  <div class="dw-hosp">' + escHtml(hosp) + '</div>' +
            (hosp2 ? '  <div class="dw-sub">' + escHtml(hosp2) + '</div>' : '') +
            '</div>' +
            '<div class="dw-title-bar"><div class="dw-title">' + escHtml(title) + '</div></div>' +
            '<div class="dw-pat-lines">' +
            '  <div class="dw-line-row">' +
            cell('姓名', v.name) + cell('性别', v.gender) + cell('年龄', v.age_fmt || '') + cell('出生日期', p.birth_date || '') +
            '  </div>' +
            '  <div class="dw-line-row">' +
            cell('患者ID', p.patient_id) + cell('流水号', v.visit_no) + cell('首诊科室', v.first_dept_name || '') + cell('首诊时间', (v.created_at || '').substr(0, 16)) +
            '  </div>' +
            '</div></div>';
    }

    /* ==================== 候诊列表 ==================== */
    function panelEl() { return document.getElementById('dwQueuePanel'); }

    function loadQueue(force, cb) {
        Clinic.get('/api/deptwork?action=queue&status=' + encodeURIComponent(STATUS) + '&today=' + (TODAY ? 1 : 0), null, {
            loading: false,
            onSuccess: function (json) {
                DATA = json.data;
                TAB_LABELS = DATA.tabs || {};
                // 首次加载应用登录会话记忆的筛选（状态页签 + 当日）
                if (!PREF_APPLIED && DATA.pref) {
                    PREF_APPLIED = true;
                    var prefStatus = (DATA.pref.status === 'doing' || DATA.pref.status === 'reviewed' || DATA.pref.status === 'done') ? DATA.pref.status : STATUS;
                    var prefToday = !!DATA.pref.today;
                    // 会话记忆的筛选与本次请求不一致 → 按记忆重新拉取，
                    // 修复刷新后 tab 高亮与列表内容不一致的 bug
                    if (prefStatus !== STATUS || prefToday !== TODAY) {
                        STATUS = prefStatus;
                        TODAY = prefToday;
                        loadQueue(true, cb);
                        return;
                    }
                }
                PREF_APPLIED = true;
                renderQueueBtn();
                if (PANEL_OPEN && panelEl()) renderPanel();
                if (cb) cb();
            },
            onError: function () { if (cb) cb(); },
        });
    }

    /** 筛选偏好保存（登录会话，跨页面保持） */
    function saveTabPref() {
        try {
            var fd = new FormData();
            fd.append('csrf_token', document.body.getAttribute('data-csrf') || '');
            fd.append('status', STATUS);
            fd.append('today', TODAY ? 1 : 0);
            fetch('/api/deptwork?action=queue_pref&status=' + encodeURIComponent(STATUS) + '&today=' + (TODAY ? 1 : 0), {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).catch(function () {});
        } catch (e) {}
    }

    function renderQueueBtn() {
        var btn = document.getElementById('queueBtn');
        if (!btn || !DATA) return;
        var n = DATA.list ? DATA.list.length : 0;
        btn.innerHTML = '📋 候诊 <b>' + n + '</b>';
        btn.title = '候诊 / 患者列表（' + (TAB_LABELS[STATUS] || STATUS) + (TODAY ? ' · 当日' : '') + '）';
    }

    function scopedList() {
        if (!DATA) return [];
        var list = DATA.list || [];
        if (!KEYWORD) return list;
        return list.filter(function (r) {
            var hay = (r.name || '') + '|' + (r.dept_name || '') + '|' + pad3(r.visit_seq) + '|' + (r.flow_no || '');
            return hay.toLowerCase().indexOf(KEYWORD.toLowerCase()) !== -1;
        });
    }

    /** 行内明细摘要（按角色语义精准展示执行进度）
        · 护士站：处置1/2（2 个处置执行了 1 个）、医嘱2/3（3 条医嘱执行了 2 条），
          某类全部完成显示「处置执行完成 / 医嘱执行完成」
        · 药房（审方/发药拆分）：待审方 X · 待发药 Y · 已发药 Z（订单级计数）
        · 检验/影像：待登记/待报告/完成数量 */
    function itemSummary(r) {
        if (ROLE === 'nurse') {
            var parts = [];
            if (r.proc_total > 0) parts.push(r.proc_done >= r.proc_total ? '处置已完成' : '处置' + r.proc_done + '/' + r.proc_total);
            if (r.med_total > 0) parts.push(r.med_done >= r.med_total ? '医嘱已完成' : '医嘱' + r.med_done + '/' + r.med_total);
            var txt = parts.join('，') || ('共 ' + r.item_cnt + ' 项');
            return { html: txt, tip: txt };
        }
        var parts = [];
        if (ROLE === 'pharmacy') {
            // 药房侧：按订单状态分 待审方（paid）/ 待发药（reviewed）/ 已发药（dispensed）
            if (r.ord_paid) parts.push('待审方 ' + r.ord_paid);
            if (r.ord_reviewed) parts.push('待发药 ' + r.ord_reviewed);
            if (r.ord_dispensed) parts.push('已发药 ' + r.ord_dispensed);
        } else {
            if (r.st_paid) parts.push('待登记 ' + r.st_paid);
            if (r.st_reg) parts.push('待报告 ' + r.st_reg);
        }
        if (r.st_done && ROLE !== 'pharmacy') parts.push('完成 ' + r.st_done);
        var txt = parts.length ? parts.join(' · ') : ('共 ' + r.item_cnt + ' 项');
        return { html: txt, tip: '共 ' + r.item_cnt + ' 项：' + parts.join('，') };
    }

    function visitStatusName(s) {
        var map = { pending: '待缴费', paid: '候诊', visiting: '就诊中', finished: '诊毕', refunded: '已退费', cancelled: '已取消' };
        return map[s] || s;
    }

    /** 状态列徽章：按角色语义展示项目状态（待处置 / 待审方 / 待发药 / 完成…），
       而非就诊状态（候诊/就诊中） */
    function itemStatusBadge(r) {
        var pending = '';
        if (ROLE === 'pharmacy') {
            // 药房（审方/发药拆分）：优先级 待审方 > 待发药 > 完成
            if (r.ord_paid) pending = '待审方';
            else if (r.ord_reviewed) pending = '待发药';
        } else if (ROLE === 'nurse') {
            if (r.st_paid) pending = '待处置';
            else if (r.st_dispensing) pending = '待执行';
        } else {
            if (r.st_paid) pending = '待登记';
            else if (r.st_reg) pending = '待报告';
        }
        if (pending) return '<span class="badge badge-warning" style="font-size:11px">' + pending + '</span>';
        return '<span class="badge badge-success" style="font-size:11px">完成</span>';
    }

    /* 行：日期 | 时间 | 号别 | 姓名 | 性别 | 年龄 | 明细 | 状态
       时间 = 最后一次开具到本科室的处置/医嘱时间；状态 = 项目状态（待处置/完成…） */
    function rowHtml(r) {
        var sum = itemSummary(r);
        return '<div class="dw-qp-row" data-code="' + escHtml(r.code) + '" title="' + escHtml(sum.tip) + '">' +
            '<span class="qp-cell qp-c-date fs-12">' + escHtml((r.date || '').substr(5)) + '</span>' +
            '<span class="qp-cell qp-c-time fs-12">' + escHtml(r.time || '') + '</span>' +
            '<span class="qp-cell qp-c-seq fs-12 fw-600">' + pad3(r.visit_seq) + '</span>' +
            '<span class="qp-cell qp-c-name fs-13">' + escHtml(r.name) + '</span>' +
            '<span class="qp-cell qp-c-gender fs-12 text-muted">' + escHtml(r.gender) + '</span>' +
            '<span class="qp-cell qp-c-age fs-12 text-muted">' + escHtml(r.age_fmt || '') + '</span>' +
            '<span class="qp-cell qp-c-sum fs-12">' + escHtml(sum.html) + '</span>' +
            '<span class="qp-cell qp-c-st">' + itemStatusBadge(r) + '</span>' +
            '</div>';
    }

    function listHtml(list) {
        if (!list.length) {
            return '<div class="qp-empty">' + (KEYWORD ? '未找到匹配的患者' : '当前筛选条件下暂无患者') + '</div>';
        }
        var head = '<div class="dw-qp-row dw-qp-head">' +
            '<span class="qp-cell qp-c-date">日期</span>' +
            '<span class="qp-cell qp-c-time">时间</span>' +
            '<span class="qp-cell qp-c-seq">号别</span>' +
            '<span class="qp-cell qp-c-name">姓名</span>' +
            '<span class="qp-cell qp-c-gender">性别</span>' +
            '<span class="qp-cell qp-c-age">年龄</span>' +
            '<span class="qp-cell qp-c-sum">明细</span>' +
            '<span class="qp-cell qp-c-st">状态</span>' +
            '</div>';
        return head + list.map(rowHtml).join('');
    }

    function renderPanel() {
        var p = panelEl();
        if (!p) return;
        var list = scopedList();
        // 页签：状态页签（按后端 tabs 动态生成：doing/reviewed/done，互斥单选）+ 当日（叠加可选）
        var chips = '';
        ['doing', 'reviewed', 'done'].forEach(function (k) {
            if (TAB_LABELS[k]) {
                chips += '<button type="button" class="qp-chip' + (STATUS === k ? ' active' : '') + '" data-k="' + k + '">' + escHtml(TAB_LABELS[k]) + '</button>';
            }
        });
        if (TAB_LABELS.today) {
            chips += '<button type="button" class="qp-chip' + (TODAY ? ' active' : '') + '" data-k="today">' + escHtml(TAB_LABELS.today) + '</button>';
        }
        p.innerHTML =
            '<div class="qp-chips">' + chips +
            '  <span class="fs-12 text-muted qp-count">' + list.length + ' 人</span>' +
            '  <input class="input qp-search" id="dwQpSearch" placeholder="搜索：姓名/号别/流水号" value="' + escHtml(KEYWORD) + '">' +
            '</div>' +
            '<div class="qp-list">' + listHtml(list) + '</div>';
        // 页签切换：状态页签（doing/reviewed/done）互斥单选，today 叠加切换；切换后重新请求
        p.querySelectorAll('[data-k]').forEach(function (c) {
            c.addEventListener('click', function () {
                var k = c.getAttribute('data-k');
                if (k === 'today') {
                    TODAY = !TODAY;
                } else {
                    STATUS = k;
                }
                saveTabPref();
                p.querySelector('.qp-list').innerHTML = '<div class="qp-empty">加载中…</div>';
                loadQueue(true);
            });
        });
        // 搜索即时过滤（保留输入框焦点与光标位置）
        var search = p.querySelector('#dwQpSearch');
        search.addEventListener('input', function () {
            var pos = search.selectionStart;
            KEYWORD = search.value.trim();
            renderListOnly(p);
            var again = p.querySelector('#dwQpSearch');
            if (again) { again.focus(); again.setSelectionRange(pos, pos); }
        });
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        bindRowClicks(p);
        clampListHeight(p);
    }

    function renderListOnly(p) {
        var list = scopedList();
        var box = p.querySelector('.qp-list');
        if (!box) return;
        box.innerHTML = listHtml(list);
        var cnt = p.querySelector('.qp-count');
        if (cnt) cnt.textContent = list.length + ' 人';
        bindRowClicks(p);
        clampListHeight(p);
    }

    function clampListHeight(p) {
        Clinic.queuePanelCore.clampHeight(p);
    }

    function bindRowClicks(p) {
        p.querySelectorAll('.dw-qp-row:not(.dw-qp-head)').forEach(function (row) {
            row.addEventListener('click', function () {
                loadPatient(row.getAttribute('data-code'));
            });
        });
    }

    function openPanel() {
        closePanel();
        var btn = document.getElementById('queueBtn');
        if (!btn) return;
        var p = Clinic.queuePanelCore.createPanel(btn, 'dwQueuePanel');
        PANEL_BIND = Clinic.queuePanelCore.bindClose(p, closePanel);
        PANEL_OPEN = true;
        if (!DATA) {
            p.innerHTML = '<div class="qp-empty">加载中…</div>';
            loadQueue(true);
        } else {
            renderPanel();
        }
    }

    function closePanel() {
        var p = panelEl();
        if (p) p.remove();
        PANEL_OPEN = false;
        KEYWORD = '';
        if (PANEL_BIND) { PANEL_BIND.unbind(); PANEL_BIND = null; }
    }

    /* ==================== 科室排队悬浮窗 ==================== */
    function callPopEl() { return document.getElementById('dwCallPop'); }

    function openCallPop() {
        if (callPopEl()) return;
        var pop = document.createElement('div');
        pop.className = 'doc-call-pop';
        pop.id = 'dwCallPop';
        pop.style.right = '16px';
        pop.style.bottom = '64px';
        pop.innerHTML =
            '<div class="doc-call-pop-head">' +
            '  <span class="doc-call-pop-title">📢 科室排队</span>' +
            '  <span class="doc-call-pop-tools"><span class="doc-call-pop-x" data-act="hide" title="关闭">x</span></span>' +
            '</div>' +
            '<div class="doc-call-pop-body">' +
            '  <div class="doc-call-block">' +
            '    <div class="doc-call-label">当前处理中</div>' +
            '    <div class="doc-call-cur" id="dwcpCur">加载中…</div>' +
            '    <div class="doc-call-cur-sub" id="dwcpCurSub"></div>' +
            '  </div>' +
            '  <div class="doc-call-block">' +
            '    <div class="doc-call-label">下一位</div>' +
            '    <div class="doc-call-next-name" id="dwcpNext">—</div>' +
            '  </div>' +
            '  <div class="doc-call-pool">' +
            '    <div class="doc-call-pool-title">候诊队列</div>' +
            '    <div class="doc-call-pool-list" id="dwcpList"><div class="fs-12 text-muted">加载中…</div></div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(pop);
        bindCallPopDrag(pop);
        pop.querySelector('[data-act="hide"]').addEventListener('click', closeCallPop);
        pop.querySelector('#dwcpList').addEventListener('click', function (e) {
            var it = e.target.closest('[data-vc]');
            if (it) { closeCallPop(); loadPatient(it.getAttribute('data-vc')); }
        });
        if (CALL_CACHE) renderCallPanel(CALL_CACHE);
        refreshCallPanel();
        if (CALL_TIMER) clearInterval(CALL_TIMER);
        CALL_TIMER = setInterval(refreshCallPanel, 10000);
    }

    function closeCallPop() {
        var pop = callPopEl();
        if (pop) pop.remove();
        if (CALL_TIMER) { clearInterval(CALL_TIMER); CALL_TIMER = null; }
        // 清理文档级拖动监听（与 bindCallPopDrag 成对），防止反复开关累积泄漏
        document.removeEventListener('mousemove', dragMoveHandler, true);
        document.removeEventListener('mouseup', dragUpHandler, true);
    }

    var dragMoveHandler = null;
    var dragUpHandler = null;
    function bindCallPopDrag(pop) {
        var head = pop.querySelector('.doc-call-pop-head');
        var dragging = false, offX = 0, offY = 0;
        if (dragMoveHandler) document.removeEventListener('mousemove', dragMoveHandler, true);
        if (dragUpHandler) document.removeEventListener('mouseup', dragUpHandler, true);
        dragMoveHandler = function (e) {
            if (!dragging) return;
            var x = Math.max(0, Math.min(e.clientX - offX, window.innerWidth - 80));
            var y = Math.max(0, Math.min(e.clientY - offY, window.innerHeight - 80));
            pop.style.left = x + 'px';
            pop.style.top = y + 'px';
            pop.style.right = 'auto';
            pop.style.bottom = 'auto';
        };
        dragUpHandler = function () {
            dragging = false;
        };
        head.addEventListener('mousedown', function (e) {
            if (e.target.closest('.doc-call-pop-x')) return;
            dragging = true;
            offX = e.clientX - pop.getBoundingClientRect().left;
            offY = e.clientY - pop.getBoundingClientRect().top;
            e.preventDefault();
        });
        document.addEventListener('mousemove', dragMoveHandler, true);
        document.addEventListener('mouseup', dragUpHandler, true);
    }

    function refreshCallPanel() {
        var pop = callPopEl();
        if (!pop) return;
        // 携带当前打开的患者，排队悬浮窗据此展示「当前处理中」
        var qs = VISIT ? '&current_visit=' + encodeURIComponent(VISIT) : '';
        Clinic.get('/api/deptwork?action=call_panel' + qs, null, {
            loading: false,
            onSuccess: function (json) { renderCallPanel(json.data); },
            onError: function () {},
        });
    }

    function renderCallPanel(d) {
        CALL_CACHE = d;
        var pop = callPopEl();
        if (!pop) return;
        var title = pop.querySelector('.doc-call-pop-title');
        if (title) title.textContent = '📢 ' + (d.dept_name ? d.dept_name + ' · 排队' : '科室排队');
        var cur = d.current, next = d.next;
        var curEl = pop.querySelector('#dwcpCur');
        var curSubEl = pop.querySelector('#dwcpCurSub');
        if (curEl) {
            if (cur) {
                curEl.textContent = cur.name;
                if (curSubEl) curSubEl.textContent = '第' + pad3(cur.visit_seq) + '号 · ' + visitStatusName(cur.status);
            } else {
                curEl.textContent = '暂无';
                if (curSubEl) curSubEl.textContent = '当前无处理中患者';
            }
        }
        var nextEl = pop.querySelector('#dwcpNext');
        if (nextEl) nextEl.textContent = next ? next.name + '（第' + pad3(next.visit_seq) + '号）' : '—';
        var listEl = pop.querySelector('#dwcpList');
        if (listEl) {
            var items = (d.waiting || []).map(function (w) {
                return '<div class="doc-call-pool-item" data-vc="' + escHtml(w.visit_code) + '" title="点击打开该患者工作台">' +
                    '<span class="doc-call-pool-seq">' + pad3(w.visit_seq) + '</span>' +
                    '<span>' + escHtml(w.name) + '</span></div>';
            });
            listEl.innerHTML = items.join('') || '<div class="fs-12 text-muted">暂无候诊患者</div>';
        }
    }

    /* ==================== 对外 ==================== */
    return {
        configure: configure,
        init: init,
        openPanel: openPanel,
        closePanel: closePanel,
        loadPatient: loadPatient,
        reloadPatient: reloadPatient,
        refreshQueue: function () { loadQueue(true); },
        currentVisit: function () { return VISIT; },
        toggleToolbox: toggleToolbox,
        toggleCallPop: function () {
            if (callPopEl()) closeCallPop(); else openCallPop();
        },
        /** 侧边栏申请单号「+」展开/收起该单号下的项目列表 */
        toggleSideOrder: function (orderId) {
            var btn = document.getElementById('sidePlus_' + orderId);
            var sub = document.getElementById('sideSub_' + orderId);
            if (!sub) return;
            var open = sub.style.display !== 'none';
            sub.style.display = open ? 'none' : 'block';
            if (btn) btn.textContent = open ? '+' : '−';
        },
        openPatientSearch: openPatientSearch,
        closePatient: closePatient,
        /** 患者工作台抬头（医院名称+标题+患者信息两行），四医技角色共用 */
        headHtml: headHtml,
        /** 状态徽章骨架（中文名 + 配色），四医技角色共用 */
        statusBadge: statusBadge,
        /** 右侧申请单/处方大纲渲染（检验/影像/药房共用） */
        renderOrderSide: renderOrderSide,
        /** 拉取当前患者最新聚合数据（局部刷新用，不重建整页） */
        fetchPatient: function (cb) {
            if (!VISIT) return;
            Clinic.get('/api/deptwork?action=patient&visit_id=' + encodeURIComponent(VISIT), null, {
                loading: false,
                onSuccess: function (json) { if (cb) cb(json.data); },
            });
        },
    };
})();

/* 工作台页面就绪后由视图内联脚本调用：configure + init */
