/**
 * ============================================================
 * emr.js v1.0.0 — 电子病历编辑器逻辑
 * ============================================================
 * 说明：医生看诊页专用。负责：
 * 1. 加载病历数据（患者信息不可编辑区 + 可编辑病历区）
 * 2. 保存病历 / 保存并诊毕（必填校验）
 * 3. 初步诊断与 ICD10 编码联动（搜索下拉）
 * 4. 病历模板调用（个人/全科/全院，审核后生效）
 * 5. 转科、诊断证明、打印病历
 * 依赖：ajax.js、modal.js、print.js、editor.js、validation.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.emr = (function () {
    /** 当前就诊数据缓存 */
    var DATA = null;

    // 病历脏标记：编辑器 onChange 置位，保存成功/诊毕清除；
    // beforeunload 据此拦截未保存关闭/跳转，防止数据丢失
    var EMR_DIRTY = false;
    /** 已开项目缓存（病历正文 辅助检查/门诊处置 所见即所得展示用） */
    var ORDERS = [];

    /**
     * 初始化页面
     */
    function init() {
        var visitId = document.getElementById('visitId').value;
        // 病历编辑区域禁止右键菜单：仅放行输入类控件（输入框/文本域/下拉/
        // 富文本可编辑区，粘贴等操作不受影响），其余区域一律屏蔽。
        // 作用范围限定在电子病历文档卡片（#emrCard），页面其他区域不受影响。
        var cardEl = document.getElementById('emrCard');
        if (cardEl) {
            cardEl.addEventListener('contextmenu', function (ev) {
                var t = ev.target;
                if (t && t.closest && t.closest('input, textarea, select, [contenteditable="true"]')) return;
                ev.preventDefault();
            });
        }
        // 患者资料保存后自动局部刷新本页头部（订阅 patient.js 的更新广播；
        // 只重建患者卡与文档内患者信息区，绝不触碰下方未保存的病历正文）
        Clinic.patient.onInfoUpdated(refreshPatientHead);
        loadData(visitId);
    }

    /**
     * 加载病历数据
     */
    function loadData(visitId) {
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                DATA = j.data;
                renderPatientCard(j.data);
                // 页眉/主体分离：页眉公共可交互；他人文书只读段在
                // renderEmrCard 内部渲染（前序在上、后序在下）
                renderEmrCard(j.data);
                // 前序医生诊断上下文注入（诊断模态框跨医生引用查重用）
                injectPrevDiagContext();
                bindItemTokenDelegate();
                loadOrders(visitId);
                Clinic.order.init(visitId, j.data);
                // 一键引用前序病历
                // refId 取隐藏输入框字符串值：无 ref 参数时为 "0"（真值），
                // 必须转整数，否则 !refId 为 false 会误判「有引用」而跳过自动弹模板
                var refId = parseInt(document.getElementById('refRecordId').value, 10) || 0;
                if (refId) {
                    var prev = (j.data.prev_records || []).find(function (r) { return r.id == refId; });
                    if (prev) {
                        applyTemplate(prev);
                    }
                }
                // 首诊自动引导：本次挂号无任何已保存病历 → 自动唤起模板选择，
                // 引导医生秒级选模板开始书写（避免从空白起笔）；300ms 偏慢、
                // 0ms 偏快，采用 150ms 折中，兼顾页面渲染完成与响应感
                if (!refId && !(j.data.records_history || []).length && !(j.data.visit && j.data.visit.status === 'finished')) {
                    setTimeout(function () { openTemplatePicker(null); }, 150);
                }
            },
        });
    }

    /* 未保存离开拦截：病历有编辑未保存时，关闭/刷新/跳转给出浏览器确认
     *（置于 IIFE 内部以访问模块级 EMR_DIRTY / DATA）*/
    window.addEventListener('beforeunload', function (e) {
        if (EMR_DIRTY && DATA && DATA.visit && DATA.visit.status !== 'finished') {
            e.preventDefault();
            e.returnValue = '';   // Chrome/Edge/IE 必需；Firefox 读取返回值
            return '';
        }
    });

    /* ==================== 总费用悬浮明细（横条徽章 hover） ==================== */

    /** 汇总费用行：挂号费 + 全部有效开单「逐项」列出（退费/取消整单不计）；
     *  圆点灰=未缴费、绿=已缴费 */
    function buildFeeRows() {
        var rows = [];
        var total = 0;
        var regFee = (DATA && DATA.visit ? parseFloat(DATA.visit.fee) : 0) || 0;
        // 挂号费状态：诊毕 → 已完成(done，绿点)；否则已缴费(paid)
        var regSt = (DATA && DATA.visit && DATA.visit.status === 'finished') ? 'done' : 'paid';
        if (regFee > 0) rows.push({ st: regSt, name: '挂号费', amt: regFee });
        (ORDERS || []).forEach(function (o) {
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            (o.items || []).forEach(function (i2) {
                var amt = (parseFloat(i2.price) || 0) * (parseFloat(i2.quantity) || 1);
                total += amt;
                rows.push({ st: i2.status || o.status, name: i2.item_name, amt: amt });
            });
        });
        total += regFee;
        return { rows: rows, total: total };
    }

    var feePopTimer = null;
    function showFeePop(anchor) {
        // 清理旧面板与待执行的隐藏定时器（不可调 hideFeePop——
        // 其会重新排 180ms 移除定时器，把刚创建的面板又删掉）
        if (feePopTimer) { clearTimeout(feePopTimer); feePopTimer = null; }
        var stale = document.getElementById('feePop');
        if (stale) stale.remove();
        var d = buildFeeRows();
        if (!d.rows.length) return;
        var pop = document.createElement('div');
        pop.id = 'feePop';
        pop.className = 'fee-pop';
        pop.innerHTML = d.rows.map(function (r) {
            var cls = navDotCls(r.st);   // 灰=未缴费，黄=已缴费未完成，绿=已完成
            // 挂号费已完成时提示「已完成」（避免复用报告/发药文案）
            var tip = (r.name === '挂号费' && r.st === 'done') ? '已完成' : navDotText(r.st);
            return '<div class="fee-pop-row">' +
                '<span class="status-indicator ' + cls + '" title="' + tip + '"></span>' +
                '<span class="fee-pop-name" title="' + escHtml(r.name) + '">' + escHtml(r.name) + '</span>' +
                '<span class="fee-pop-amt">¥' + r.amt.toFixed(2) + '</span></div>';
        }).join('') +
            '<div class="fee-pop-total"><span>合计</span><span>¥' + d.total.toFixed(2) + '</span></div>';
        document.body.appendChild(pop);
        var rect = anchor.getBoundingClientRect();
        pop.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        pop.style.left = Math.max(8, rect.right + window.scrollX - 270) + 'px';
        clampPop(pop);
        pop.addEventListener('mouseenter', function () { if (feePopTimer) { clearTimeout(feePopTimer); feePopTimer = null; } });
        pop.addEventListener('mouseleave', hideFeePop);
    }
    function hideFeePop() {
        if (feePopTimer) clearTimeout(feePopTimer);
        feePopTimer = setTimeout(function () {
            var pop = document.getElementById('feePop');
            if (pop) pop.remove();
            feePopTimer = null;
        }, 180);
    }

    /**
     * 渲染患者信息卡（不可编辑区域）
     */
    function renderPatientCard(d) {
        var p = d.patient, v = d.visit;
        // 诊断证明入口全部收口至右侧大纲栏「诊断证明」分区：
        // 未归档 → 分区「＋」直接开具；归档未开具 → 分区「＋」确认后补开；
        // 已开具 → 分区条目查看。横条不再显示任何诊断证明链接。
        // 患者一栏只保留基本信息（就诊医生右上角已有展示，记录时间在病历文档左下角，均不在此重复）
        // 条形码位于病历文档页头右上角（与打印预览一致），不在此处显示
        // 交互入口：点击头像 → 就诊历史；点击患者姓名 → 「修改患者信息」弹窗
        // （p.patient_id 即患者档案号 patient_no，与就诊历史/资料编辑接口参数一致）
        // 外层不再包 .card——顶部横条 .emr-top-bar 自带卡片底色与边框
        var editModal = "Clinic.patient.editModal('" + p.patient_id + "')";
        var historyModal = "showPatientHistory('" + p.patient_id + "')";
        document.getElementById('emrHeader').innerHTML =
            '<div class="flex-between">' +
            '  <div class="flex gap-12" style="align-items:center">' +
            '    <div class="emr-patient-avatar" onclick="' + historyModal + '" title="点击查看就诊历史">👤</div>' +
            '    <div>' +
            '      <div class="fs-18 fw-700">' +
            // 修改患者信息点击范围仅限姓名文字本身——避免误触费用类别/门诊/总费用徽章
            '        <span class="emr-patient-name" onclick="' + editModal + '" title="点击修改患者信息">' + v.name + '</span>' +
            '        <span class="badge badge-gray" style="margin-left:8px">' + v.gender + ' / ' + (v.age_fmt || '') + '</span>' +
            // 费用类别徽章（自费/居民医保/职工医保/其他），历史数据为空则不渲染
            '        ' + (v.fee_type ? '<span class="badge badge-warning" style="margin-left:4px" title="费用类别">' + escHtml(v.fee_type) + '</span>' : '') +
            '        <span class="badge ' + (v.dept_type === 'emergency' ? 'badge-danger' : 'badge-primary') +
            '" style="margin-left:4px">' + (v.dept_type === 'emergency' ? '急诊' : '门诊') + '</span>' +
            '        <span class="badge badge-warning" id="hdrTotal" style="display:none"></span>' +
            '      </div>' +
            '      <div class="text-muted fs-13">患者ID：' + p.patient_id + ' ｜ 流水号：' + v.visit_no +
            ' ｜ ' + v.dept_name + ' 第' + String(v.visit_seq).padStart(3, 0) + '号</div>' +
            '    </div>' +
            '  </div>' +
            '</div>';
    }

    /**
     * 构建病历文档内的患者信息区 HTML（门诊两栏网格 / 急诊两行流式）。
     * 独立成函数供两处复用：整卡渲染 & 患者资料保存后的局部刷新
     * （所见即所得，与打印页版式一致）。
     * @param {Object} d          /api/record get 返回数据（record/visit/patient）
     * @param {string} vtOverride 初复诊当前值：局部刷新时保留用户已选项，传空取档案值
     */
    function patientGridHtml(d, vtOverride) {
        var r = d.record || {};
        var vv = d.visit || {};
        var p = d.patient || {};
        var vt = vtOverride || r.visit_type || '初诊';
        // 初复诊下拉（局部刷新时保留当前选择，避免打断医生操作）
        var vtSelect = '<select class="doc-cell-select" id="visitType">' +
            '<option value="初诊"' + (vt === '初诊' ? ' selected' : '') + '>初诊</option>' +
            '<option value="复诊"' + (vt === '复诊' ? ' selected' : '') + '>复诊</option></select>';
        var cellHtml = function (f) {
            // 初复诊为下拉框（可编辑），其余为纯文本展示
            var isSelect = (typeof f[1] === 'string' && f[1].indexOf('<select') === 0);
            if (isSelect) {
                return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' + f[1] + '</div>';
            }
            return '<div class="doc-cell"><span class="doc-cell-label">' + f[0] + '：</span>' +
                '<span class="doc-cell-value">' + f[1] + '</span></div>';
        };
        if (vv.dept_type === 'emergency') {
            var lines = [
                [['姓名', vv.name], ['性别', vv.gender], ['出生日期', p.birth_date], ['年龄', vv.age_fmt]],
                [['患者ID', p.patient_id], ['就诊科室', vv.dept_name], ['就诊时间', vv.created_at]],
            ];
            return '<div class="doc-patient-lines">' + lines.map(function (row) {
                return '<div class="doc-line-row">' + row.map(cellHtml).join('') + '</div>';
            }).join('') + '</div>';
        }
        var fields = [['姓名', vv.name], ['性别', vv.gender], ['年龄', vv.age_fmt], ['患者ID', p.patient_id],
           ['证件号码', p.id_card], ['出生日期', p.birth_date], ['民族', p.nation || '—'],
           ['职业', p.occupation || '—'], ['婚姻', p.marital || '—'], ['初复诊', vtSelect],
           ['科室', vv.dept_name], ['联系方式', p.phone || '—']];
        return '<div class="doc-patient-grid">' + fields.map(cellHtml).join('') + '</div>';
    }

    /**
     * 患者资料保存成功后的局部刷新：
     * 重新拉取就诊数据后仅重建两处——
     * 1) 顶部患者信息卡 #emrHeader；
     * 2) 病历文档内的患者信息区（初复诊下拉保留医生当前选择）。
     * 病历正文编辑器、签名、页脚、已开项目一律不动，
     * 未保存内容零丢失（不做 location.reload 整页刷新）。
     */
    function refreshPatientHead() {
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                DATA = j.data;
                renderPatientCard(j.data);
                var card = document.getElementById('emrCard');
                if (!card) return;
                var old = card.querySelector('.doc-patient-grid, .doc-patient-lines');
                if (!old) return;
                var sel = document.getElementById('visitType');
                var wrap = document.createElement('div');
                wrap.innerHTML = patientGridHtml(j.data, sel ? sel.value : '');
                if (wrap.firstElementChild) old.parentNode.replaceChild(wrap.firstElementChild, old);
            },
        });
    }

    /**
     * 构建生命体征节（外部注入 emreditor，首诊/续写通用）
     */
    function buildVitalSec(readOnly, vitals) {
        var sec = document.createElement('div');
        sec.className = 'doc-sec doc-sec-vital';
        if (!readOnly) {
            sec.setAttribute('onclick', 'Clinic.emr.openVitals(event)');
            sec.setAttribute('title', '点击编辑生命体征');
        }
        sec.innerHTML = '<span class="doc-sec-label">生命体征</span>' +
            '<span class="doc-sec-body" id="vitalDisplay">' + vitalDisplayText(vitals) + '</span>';
        return sec;
    }

    /**
     * 构建意识状态节（外部注入 emreditor，首诊/续写通用）
     */
    function buildConsciousNode(readOnly, curCon) {
        var consciousness = ['清醒', '嗜睡', '意识模糊', '昏睡', '昏迷', '谵妄'];
        curCon = curCon || '清醒';
        var node = document.createElement('div');
        node.className = 'doc-sec';
        if (readOnly) {
            node.innerHTML = '<span class="doc-sec-label">意识状态</span>' +
                '<span class="doc-sec-body">' + escHtml(curCon) + '</span>';
        } else {
            node.innerHTML = '<span class="doc-sec-label">意识状态</span>' +
                '<span class="ef-select-wrap"><select class="ef-select" id="consciousness">' +
                consciousness.map(function (c) {
                    return '<option value="' + c + '"' + (curCon === c ? ' selected' : '') + '>' + c + '</option>';
                }).join('') + '</select></span>';
        }
        return node;
    }

    /**
     * 填充续写条幅（记录医生 + 记录时间 + 病历续写徽章）。
     * 记录时间：有 created_at 用首次保存时间，否则用当前时间实时兜底
     * （首次保存后 onSuccess 会刷新为 created_at）。
     */
    function fillContHead(r) {
        var wrap = document.getElementById('contHeadWrap');
        if (!wrap || !r) return;
        var t = r.created_at || r.updated_at || fmtDateTime();
        wrap.innerHTML = '<div class="emr-cont-divider"></div>' +
            '<div class="prev-record-head">' +
            '<span class="fw-600">记录医生：' + escHtml(r.doctor_name) +
            (r.doctor_title ? ' ' + escHtml(r.doctor_title) : '') +
            (r.doctor_emp ? ' （工号 ' + escHtml(r.doctor_emp) + '）' : '') + '</span>' +
            '<span>记录时间：' + escHtml(t) + '</span>' +
            '<span class="badge badge-primary">病历续写</span></div>';
    }

    /**
     * 渲染续写编辑器（占位态「病历节点 +」点击后调用）：
     * 将 docBody 中的占位替换为续写编辑器，并滚动定位到本人病历区。
     * 场景：无本人文书但有他人文书（首次接诊续写）
     */
    function createProgressEditor() {
        var docBody = document.getElementById('docBody');
        if (!docBody || !DATA) return;
        var r = DATA.record;
        var d = DATA;
        try {
            fillContHead(r);
            var signEl = document.getElementById('signWrap');
            if (signEl) signEl.textContent = '医生：' + r.doctor_name;
            docBody.innerHTML = '';
            Clinic.emrEditor.render(docBody, r.emr || {}, {
                readonly: false,
                beforeVitals: buildVitalSec(false, d.vitals || {}),
                midNode: buildConsciousNode(false, r.consciousness || '清醒'),
                mode: 'progress',
                onChange: function () { EMR_DIRTY = true; },
            });
            refreshReadOnlyBodies(d);
            // 右侧病历节点列表追加临时续写节点（renderLeftNav 内自动处理占位）
            DATA.__pending_progress = true;
            renderLeftNav();
            scrollToEditor(0);
        } catch (e) {
            console.error('续写编辑器渲染失败', e);
            Clinic.toast.error('续写编辑器渲染失败，请刷新页面重试');
        }
    }

    /**
     * 本人已有文书后再续写（DOM 局部操作，不重渲染整页）：
     * 1) 将当前文书转为只读段插入 roBefore（置于其他只读段之后）；
     * 2) 在下方重建一个新的空白续写编辑器（保留既往史/过敏史等默认结构）；
     * 3) 填充条幅与签名；保存时以 progress_new 落库（可多次续写）。
     */
    function addProgressEditor() {
        var r = DATA.record;
        // 1. 当前文书转为只读段插入 roBefore（防重复：同一文书仅插一次）
        var beforeEl = document.getElementById('roBefore');
        if (beforeEl && r.record_id > 0 && !document.getElementById('recSeg' + r.record_id)) {
            var rec = {
                id: r.record_id,
                doctor_id: r.doctor_id,
                doctor_name: r.doctor_name,
                doctor_emp: r.doctor_emp || '',
                doctor_title: r.doctor_title || '',
                record_type: r.record_type,
                emr: JSON.parse(JSON.stringify(r.emr || {})),
                created_at: r.created_at || '',
                vitals: (DATA.vitals || {}),
                consciousness: r.consciousness || '',
            };
            beforeEl.insertAdjacentHTML('beforeend', roSegmentHtml(rec));
        }
        // 2. 续写编辑态：保留默认结构（既往史/过敏史默认「否认」等），
        //    仅将 progress 内容清空；record_id 置 0 表示新建，保存时落库。
        //    诊断不自动代入——续写是完全独立的文书，需要什么诊断医生手动添加
        DATA.__pending_progress = true;
        DATA.__progress_new = true;
        DATA.__edit_record_id = 0;   // 新建续写走 progress_new，不使用精确回写
        DATA.record.record_id = 0;
        DATA.record.record_type = 'progress';
        var base = JSON.parse(JSON.stringify(r.emr || {}));
        if (base.progress) base.progress.content = '';
        base.diagnoses = [];   // 续写不自动带入前序诊断
        DATA.record.emr = base;
        DATA.record.created_at = '';
        DATA.record.updated_at = '';
        // 3. 重建 docBody 为新续写编辑器
        var docBody = document.getElementById('docBody');
        if (docBody) {
            docBody.innerHTML = '';
            try {
                Clinic.emrEditor.render(docBody, DATA.record.emr, {
                    readonly: false,
                    beforeVitals: buildVitalSec(false, DATA.vitals || {}),
                    midNode: buildConsciousNode(false, '清醒'),
                    mode: 'progress',
                    onChange: function () { EMR_DIRTY = true; },
                });
            } catch (e) {
                console.error('续写编辑器渲染失败', e);
                Clinic.toast.error('续写编辑器渲染失败，请刷新页面重试');
            }
        }
        // 4. 条幅 + 签名
        fillContHead(DATA.record);
        var signEl2 = document.getElementById('signWrap');
        if (signEl2) signEl2.textContent = '医生：' + r.doctor_name;
        // 5. 更新左侧病历节点列表（renderLeftNav 内自动追加「续写编辑中」占位）
        renderLeftNav();
        scrollToEditor(0);
    }

    /**
     * 滚动到本人病历编辑区（#myRecordAnchor）：
     * 续写场景下正文默认只读展示，显式点击「病历节点 +」后定位到续写区
     */
    /**
     * 滚动到本人续写/编辑区：优先滚动到续写条幅（#contHeadWrap，位于所有
     * 只读段之后、编辑器之前），回退到 #myRecordAnchor。
     * @param {number} delay 滚动延迟 ms，默认 200；0 表示下一宏任务立即执行
     *                        （交互触发场景如点+续写、切换节点用 0，
     *                         初始加载等异步场景保留 200）
     */
    function scrollToEditor(delay) {
        var anchor = document.getElementById('contHeadWrap') || document.getElementById('myRecordAnchor');
        if (!anchor) return;
        if (delay == null) delay = 200;
        var doScroll = function () {
            var topbar = document.querySelector('.topbar');
            var topbarH = topbar ? topbar.offsetHeight : 0;
            var el = anchor.parentElement, scroller = null;
            while (el && el !== document.body) {
                var st = getComputedStyle(el);
                if (/(auto|scroll)/i.test(st.overflowY) || /(auto|scroll)/i.test(st.overflow)) {
                    if (el.scrollHeight > el.clientHeight + 1) { scroller = el; break; }
                }
                el = el.parentElement;
            }
            var rect = anchor.getBoundingClientRect();
            if (scroller) {
                var y = rect.top - scroller.getBoundingClientRect().top + scroller.scrollTop - 8;
                scroller.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
            } else {
                var yw = rect.top + (window.pageYOffset || document.documentElement.scrollTop) - topbarH - 8;
                window.scrollTo({ top: Math.max(0, yw), behavior: 'smooth' });
            }
        };
        if (delay === 0) {
            // 立即执行（下一宏任务，等当前同步栈结束 DOM 稳定）
            setTimeout(doScroll, 0);
        } else {
            setTimeout(doScroll, delay);
        }
    }

    /**
     * 渲染病历编辑区（WYSIWYG）
     * 场景 A：本次挂号无任何病历 → 标准首诊编辑器（record_type=initial）
     * 场景 B：前序已有其他医生病历 → 本卡为当前医生的续写编辑器
     *         （record_type=progress，顶部必填病历续写；前序病历在上方只读区展示）
     * 场景 C：已有保存病历但当前医生本人尚无文书 → 默认只读展示他人病历 +
     *         续写占位（不渲染空编辑器），仅显式点击「病历节点 +」才渲染续写编辑区
     */
    function renderEmrCard(d) {
        var r = d.record;
        var v = d.vitals || {};
        var vv = d.visit || {};   // 就诊信息（注意：v 为生命体征，患者信息网格必须用 vv）
        var p = d.patient || {};
        var isProgress = r.record_type === 'progress';
        // 病历模板功能已整合到右上侧「病历节点 +」入口，页眉不再保留独立模板按钮

        // 医院抬头与标题（与打印版式一致，所见即所得）；页眉归首诊文书所有，
        // 续写文书不带抬头/标题/患者信息/条形码，直接从「病历续写」开始
        var hosp = document.body.getAttribute('data-hosp') || '';
        var hosp2 = document.body.getAttribute('data-hosp2') || '';
        var docTitle = (d.visit && d.visit.dept_type === 'emergency' ? '急诊电子病历' : '门诊电子病历');

        // 患者信息区：门诊两栏网格 / 急诊两行流式（构建逻辑抽至
        // patientGridHtml，供患者资料保存后的局部刷新复用）
        var gridWrap = patientGridHtml(d, '');

        // 病历文档页头右上角条形码（与挂号凭条/打印预览一致：门诊号 flow_no，Code 128）
        var bcSrc = document.getElementById('emrBarcodeSrc');
        var bcHtml = (bcSrc && bcSrc.innerHTML)
            ? '<div class="doc-barcode">' + bcSrc.innerHTML +
              '<div class="doc-barcode-text">' + vv.visit_no + '</div></div>'
            : '';

        // 病历正文：结构化字段编辑器（[] 占位字段引擎，静态标签不可编辑，
        // 保存时仅收集字段内部文字；生命体征/意识状态两节由本函数外部构建注入。
        // 首诊与续写文书均支持生命体征/意识状态书写；只读（诊毕）时仅展示不可编辑）
        var readOnly = !!(d.visit && d.visit.status === 'finished');
        var vitalSec = buildVitalSec(readOnly, v);
        var midNode = buildConsciousNode(readOnly, r.consciousness || '清醒');
        // 场景 C：已有保存病历但当前医生本人尚无文书（record_id=0 且为续写）
        // → 默认只读展示他人病历 + 续写占位，不渲染空编辑器
        var needProgress = !readOnly && isProgress && !(r.record_id > 0) && (d.records_history || []).length > 0;
        // 场景 D：首诊空病历（无任何保存病历，本人也尚未创建）→ 不渲染空白编辑器，
        // 显示占位提示，等待自动弹出模板选择后创建首张电子病历
        var emptyInitial = !readOnly && !isProgress && !(r.record_id > 0) && !(d.records_history || []).length;

        // ===== 文档骨架：页眉区与病历主体区【分离】 =====
        // 页眉（医院抬头/标题/患者信息网格/条形码）属于整次就诊的公共区域，
        // 不随任何文书进入只读——任何接诊医生都可以继续更新患者资料、
        // 修改初复诊等；仅【病历主体】按文书归属区分：他人文书只读展示，
        // 本人文书可编辑（前序在上、后序在下，严格按时间正序接续）。
        // 诊毕只读：全部文书以只读段展示，不渲染编辑器 / 本人签名 / 页脚
        // （只读段内已有各自医生签名，绝不出现查看者本人签名的"幽灵行"）。
        // 公共页眉（抬头/标题/患者信息/条形码）
        var headHtml =
            bcHtml +
            (hosp ? '<div class="doc-hosp">' + hosp + '</div>' : '') +
            (hosp2 ? '<div class="doc-sub">' + hosp2 + '</div>' : '') +
            '<div class="doc-title-bar">' +
            '  <span class="doc-title">' + docTitle + '</span>' +
            '</div>' +
            gridWrap +
            '<div class="doc-line"></div>';
        var docHtml;
        if (readOnly) {
            // 只读骨架：无编辑器签名、无页脚（各只读段自带医生签名与记录时间）
            docHtml =
                '<div class="emr-doc">' +
                headHtml +
                '<div class="doc-body" id="docBody" data-ro="1"></div>' +
                '</div>';
        } else {
            // 编辑态骨架：续写定位锚点 + 本人文书右下角签名 + 页脚（记录时间 | 医生 | 最近保存）
            // 灰色署名条幅：仅在已有实际文书（非 needProgress 占位）时显示
            var contHtml = (needProgress || emptyInitial) ? '' :
                (isProgress ? '<div class="emr-cont-divider"></div>' : '') +
                '<div class="prev-record-head">' +
                '<span class="fw-600">记录医生：' + escHtml(r.doctor_name) +
                (r.doctor_title ? ' ' + escHtml(r.doctor_title) : '') +
                (r.doctor_emp ? ' （工号 ' + escHtml(r.doctor_emp) + '）' : '') + '</span>' +
                ((r.created_at || r.updated_at) ? '<span>记录时间：' + escHtml(r.created_at || r.updated_at) + '</span>' : '') +
                (isProgress ? '<span class="badge badge-primary">病历续写</span>' : '<span class="badge badge-gray">首诊</span>') +
                '</div>';
            docHtml =
                '<div class="emr-doc">' +
                headHtml +
                '<div id="myRecordAnchor"></div>' +
                '<div id="roBefore"></div>' +
                '<div id="contHeadWrap">' + contHtml + '</div>' +
                '<div class="doc-body" id="docBody"></div>' +
                '<div class="doc-body-sign" id="signWrap">' + ((needProgress || emptyInitial) ? '' : '医生：' + escHtml(r.doctor_name)) + '</div>' +
                '<div id="roAfter"></div>' +
                '</div>';
        }
        document.getElementById('emrCard').innerHTML = docHtml;

        // 最近保存时间：纸张外独立胶囊徽章（不再贴在病历纸内部）。
        // 自愈式创建：无论静态节点是否就绪（缓存/时序缺页），渲染时缺失即补建，
        // 杜绝「节点不存在→静默不显示」。
        // 取值优先本人文书的 updated_at；本人无文书（如查看他人归档病历）时
        // 回退流水内最新一条文书的 updated_at/created_at——归档病历同样统一展示。
        var savedBadge = document.getElementById('docSavedBadge');
        if (!savedBadge) {
            var scroller = document.querySelector('.emr-main-editor-scroll');
            if (scroller) {
                savedBadge = document.createElement('div');
                savedBadge.id = 'docSavedBadge';
                savedBadge.className = 'doc-saved-badge';
                scroller.appendChild(savedBadge);
            }
        }
        if (savedBadge) {
            var savedAt = r.updated_at || '';
            if (!savedAt && d.records_history && d.records_history.length) {
                var lastRec = d.records_history[d.records_history.length - 1];
                savedAt = (lastRec.updated_at || lastRec.created_at || '');
            }
            savedBadge.textContent = savedAt ? '最近保存：' + savedAt : '';
            savedBadge.style.display = savedAt ? '' : 'none';
        }

        if (readOnly) {
            // 诊毕只读：全部文书以只读段展示（打印版式），不渲染编辑器
            var hist = d.records_history || [];
            var docBody = document.getElementById('docBody');
            if (docBody && hist.length) {
                docBody.innerHTML = '<div class="prev-record-wrap">' + hist.map(roSegmentHtml).join('') + '</div>';
            }
            setReadonlyUI();
        } else {
            // 场景 C：已有他人保存病历但本人尚无文书 → 默认只读展示他人病历 +
            // 续写占位，不渲染空编辑器；显式点击「病历节点 +」才渲染续写编辑器
            if (needProgress) {
                var phBody = document.getElementById('docBody');
                if (phBody) {
                    phBody.innerHTML = '<div class="ro-placeholder" id="roPlaceholder">' +
                        '<div class="fs-14">📝 病历续写</div>' +
                        '<div class="fs-12 text-muted mt-4">该患者已有保存的病历（上方只读展示）。' +
                        '点击左侧「病历节点 ＋」开始书写续写病历。</div></div>';
                }
                refreshReadOnlyBodies(d);
            } else if (emptyInitial) {
                // 场景 D：首诊空病历 → 不渲染空白编辑器，显示占位提示，
                // 待自动弹出模板选择创建首张电子病历
                var eiBody = document.getElementById('docBody');
                if (eiBody) {
                    eiBody.innerHTML = '<div class="ro-placeholder" id="roPlaceholder">' +
                        '<div class="fs-14">📄 首张电子病历尚未创建</div>' +
                        '<div class="fs-12 text-muted mt-4">正在为你弹出模板选择，也可点击下方按钮选择模板创建病历</div>' +
                        '<button class="btn btn-primary btn-sm mt-8" onclick="Clinic.emr.openTemplates(event)">📋 选择病历模板</button></div>';
                }
                refreshReadOnlyBodies(d);
            } else {
                // 非诊毕：结构化字段编辑器渲染（mode 决定首诊全量/续写精简模块）
                Clinic.emrEditor.render(document.getElementById('docBody'), r.emr || {}, {
                    readonly: false,
                    beforeVitals: vitalSec,
                    midNode: midNode,
                    mode: isProgress ? 'progress' : 'initial',
                    // 脏标记：任何编辑置位，保存成功/诊毕后清除（beforeunload 拦截用）
                    onChange: function () { EMR_DIRTY = true; },
                });

                // 他人文书只读段渲染（此刻已开项目列表未必就绪，loadOrders 成功后会再刷新一次）
                refreshReadOnlyBodies(d);
            }
        }

        // 初始定位：默认滚动到当前本人可编辑文书（最后一个本人文书）的锚点；
        // · 只读（诊毕）或场景 C（本人无文书仅占位）→ 不自动滚动
        // · switchToRecord 切换时置 __noAutoScroll，由调用方立即滚动
        if (!readOnly && !needProgress && !d.__noAutoScroll) {
            scrollToEditor(200);
        }
    }

    /**
     * 诊毕确认悬浮面板：选择离院方式（自主离院/住院/转院/死亡/其他），
     * 非「自主离院」需填写对应补充信息（住院病区/接收医院/死亡原因/其他转归），
     * 确认后携带转归数据执行 save(true)。面板锚定在诊毕按钮下方，点外部关闭。
     */
    var finishPopHandler = null;
    function closeFinishPop() {
        var pop = document.getElementById('finishPop');
        if (pop) pop.remove();
        if (finishPopHandler) {
            document.removeEventListener('mousedown', finishPopHandler);
            finishPopHandler = null;
        }
    }
    function confirmFinish(btn) {
        // 已打开则收起（再次点击按钮 = 关闭）
        if (document.getElementById('finishPop')) { closeFinishPop(); return; }
        var opts = ['自主离院', '住院', '转院', '死亡', '其他'];
        var phMap = { '住院': '请填写住院病区', '转院': '请填写接收医院名称', '死亡': '请填写死亡原因', '其他': '请填写其他转归情况' };
        var pop = document.createElement('div');
        pop.id = 'finishPop';
        pop.className = 'finish-pop';
        pop.innerHTML =
            '<div class="fs-13 fw-700 mb-8">选择离院方式（转归）</div>' +
            opts.map(function (t, i) {
                return '<label class="finish-opt"><input type="radio" name="dispOpt" value="' + t + '"' + (i === 0 ? ' checked' : '') + '>' + t + '</label>';
            }).join('') +
            '<input class="input" id="dispDetail" style="display:none;margin-top:8px">' +
            '<div class="flex gap-8 mt-12">' +
            '  <button type="button" class="btn btn-outline btn-sm" style="flex:1" id="finishCancel">取消</button>' +
            '  <button type="button" class="btn btn-success btn-sm" style="flex:1" id="finishOk">确认诊毕</button>' +
            '</div>';
        document.body.appendChild(pop);
        var rect = btn.getBoundingClientRect();
        pop.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        pop.style.left = Math.max(8, rect.right + window.scrollX - 230) + 'px';
        clampPop(pop);

        var detailInput = pop.querySelector('#dispDetail');
        pop.querySelectorAll('input[name="dispOpt"]').forEach(function (r) {
            r.addEventListener('change', function () {
                if (this.value === '自主离院') {
                    detailInput.style.display = 'none';
                    detailInput.value = '';
                } else {
                    detailInput.style.display = '';
                    detailInput.placeholder = phMap[this.value] || '';
                }
            });
        });
        pop.querySelector('#finishCancel').addEventListener('click', closeFinishPop);
        pop.querySelector('#finishOk').addEventListener('click', function () {
            var checked = pop.querySelector('input[name="dispOpt"]:checked');
            var disp = checked ? checked.value : '';
            var detail = detailInput.value.trim();
            if (disp !== '自主离院' && !detail) {
                Clinic.toast.warning(phMap[disp] + '不能为空');
                return;
            }
            closeFinishPop();
            save(true, { disposition: disp, disposition_detail: disp === '自主离院' ? '' : detail });
        });
        // 点击面板/按钮以外区域关闭
        finishPopHandler = function (e) {
            if (!pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) closeFinishPop();
        };
        setTimeout(function () { document.addEventListener('mousedown', finishPopHandler); }, 0);
    }

    /**
     * 诊毕只读：禁用病历所有输入控件，避免误解为可继续编辑
     */
    function setReadonlyUI() {
        var card = document.getElementById('emrCard');
        if (card) {
            card.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = true;
            });
            // 结构化字段编辑器只读（[] 字段不可再编辑）
            Clinic.emrEditor.setReadonly(true);
            // 隐藏病历模板按钮（写操作）
            var tpl = document.getElementById('tplBtn');
            if (tpl) tpl.style.display = 'none';
        }
        // 隐藏工具栏写操作按钮（开单/保存/诊毕/转科/诊断证明），保留查看类（打印/历史/患者信息）
        // 例外：诊断证明分区「＋」在归档未开具时保留显示——归档病历补开的唯一入口
        document.querySelectorAll('.emr-write').forEach(function (b) {
            if (b.id === 'certAddBtn' && !(DATA && DATA.has_certificate)) return;
            b.style.display = 'none';
        });
        // 大纲栏分区「＋」在只读态直接移除（而非 display:none）：
        // 相邻选择器 .ena-add + .ena-arrow 不受 visibility 影响，若仅隐藏
        // 会让无金额汇总分区的折叠箭头失去 margin-left:auto 而贴到文字后。
        // 例外：诊断证明「＋」在归档未开具时保留——作为归档病历补开的唯一入口
        document.querySelectorAll('.ena-sec-title .ena-add').forEach(function (b) {
            if (b.id === 'certAddBtn' && !(DATA && DATA.has_certificate)) return;
            b.remove();
        });
        var status = document.getElementById('saveStatus');
        if (status) {
            status.textContent = '该患者已诊毕，病历为只读状态';
            status.style.color = 'var(--text-muted)';
        }
    }

    /* ==================== 多医生接诊：前序病历只读查看区 ====================
     * 前序医生的病历全只读展示（灰色只读背景、不可编辑），顶部标注
     * 「记录医生：XX医生，就诊时间」；当前医生只能在下方新建续写文书。
     * 展示文本格式与后端 emr_formatter.php 同规则（所见即所得）。 */

    /** HTML 转义（防 XSS：病历内容含医生手输文本） */
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /** 主诉文本：主要症状+时间+单位 [次要症状+时间+单位]（同 emr_cc_text） */
    function fmtCC(cc) {
        cc = cc || {};
        var seg = function (s, d, u) { return (s || '') + (d || '') + (u || ''); };
        return seg(cc.symptom, cc.duration, cc.unit) + seg(cc.second_symptom, cc.second_duration, cc.second_unit);
    }

    /** 现病史文本：供史者+时间+单位+内容[，来院途径]（同 emr_pi_text） */
    function fmtPI(pi) {
        pi = pi || {};
        var head = (pi.informant || '') + (pi.duration || '') + (pi.unit || '') + (pi.content || '');
        var way = pi.arrival_way || '';
        if (!head && !way) return '';
        return way ? (head ? head + '，' : '') + way : head;
    }

    /** 既往史文本（同 emr_ph_text） */
    function fmtPH(ph) {
        ph = ph || {};
        if ((ph.type || '否认') !== '承认') return '否认';
        return ph.detail ? '承认，' + ph.detail : '承认';
    }

    /** 过敏史文本（同 emr_al_text；兼容旧纯文本格式） */
    function fmtAL(al) {
        if (typeof al === 'string') return al;
        al = al || {};
        if ((al.type || '否认') !== '承认') return '否认';
        return al.detail || '承认';
    }

    /** 主要症状文本：仅输出已选项（同 emr_ms_text） */
    function fmtMS(ms) {
        ms = ms || {};
        return Object.keys(ms).filter(function (k) { return ms[k]; })
            .map(function (k) { return k + '：' + ms[k]; }).join('，');
    }

    /** 体格检查文本：已填项「名称：值」（同 emr_pe_text，全空返回 '-'） */
    function fmtPE(pe) {
        pe = pe || {};
        return Object.keys(pe).filter(function (k) { return String(pe[k] || '').trim(); })
            .map(function (k) { return k + '：' + pe[k]; }).join('，');
    }

    /** 诊断列表文本（复用编辑器同款格式：编码 部位名称（备注）疑似?） */
    function fmtDiags(list) {
        return (list || []).map(function (dg) {
            return dg && dg.name ? Clinic.emrEditor.diagText(dg) : '';
        }).filter(Boolean).join('，');
    }

    /**
     * 单条【他人】文书 → 只读段 HTML（标注带 + 正文节 + 医生签名）。
     * 页眉已独立为文档顶部公共区域，本段不再携带任何抬头/患者信息——
     * 仅病历主体呈现只读，谁书写谁签名。
     * @param {Object} rec records_history 条目（含 doctor_name/emr/primary_diagnosis 等）
     */
    function roSegmentHtml(rec) {
        var e = rec.emr || {};
        var isProgress = rec.record_type === 'progress';
        var secs = [];
        var push = function (label, val, dashWhenEmpty) {
            val = val == null ? '' : String(val).trim();
            if (!val && !dashWhenEmpty) return;
            secs.push('<div class="prev-sec"><span class="doc-sec-label">' + label + '：</span>' +
                escHtml(val || '-') + '</div>');
        };
        if (isProgress) push('病历续写', (e.progress || {}).content);
        push('主诉', fmtCC(e.chief_complaint));        push('现病史', fmtPI(e.history_present));
        push('既往史', fmtPH(e.past_history));
        push('过敏史', fmtAL(e.allergies));
        push('主要症状', fmtMS(e.main_symptoms));
        // 生命体征归属：本段医生本人录入的体征（rec.vitals），未录入显示 -
        push('生命体征', vitalDisplayText(rec.vitals || {}), true);
        // 意识状态：本段医生本人镜像回读，未记录显示 -
        push('意识状态', rec.consciousness || '', true);
        push('体格检查', fmtPE(e.physical_exam), true);
        push('初步诊断', fmtDiags(e.diagnoses));
        // 辅助检查/门诊处置按该文书医生本人的开单归属渲染（多医生接诊，
        // 项目跟随医生归档；已开项目文本与编辑器自动段同源同规则）
        var t = orderTextsFor(rec.doctor_id || 0);
        var auxParts = [];
        [e.aux_result, e.aux_external].forEach(function (x) {
            if (x && String(x).trim()) auxParts.push(escHtml(x));
        });
        t.aux.forEach(function (n) { auxParts.push(escHtml(n)); });
        push('辅助检查', auxParts.join('，'), true);
        var dispHtml = t.rxs.map(function (l) { return '<div>' + escHtml(l) + '</div>'; }).join('');
        var dispParts = t.proc.map(function (p) { return escHtml(p); });
        if (e.disposition_custom && String(e.disposition_custom).trim()) dispParts.push(escHtml(e.disposition_custom));
        if (dispParts.length) dispHtml += '<span>' + dispParts.join('，') + '</span>';
        secs.push('<div class="prev-sec"><span class="doc-sec-label">门诊处置：</span>' + (dispHtml || '-') + '</div>');
        // 是否留观：始终显示（否 / 是），与打印病历格式一致
        push('是否留观', e.is_leave_hospital === '是' ? '是' : '否', true);
        push('嘱托', e.advice);

        var typeBadge = isProgress
            ? '<span class="badge badge-primary">病历续写</span>'
            : '<span class="badge badge-gray">首诊</span>';
        // 只读归档表述：徽标已标明首诊/续写，这里仅中性标注记录医生，
        // 不再使用「接诊自」这类仅适用于活跃续写场景的承接性措辞
        // 只读归档表述：徽标已标明首诊/续写，此处标注本段记录医生
        var authorSpan = '<span class="fw-600">记录医生：' + escHtml(rec.doctor_name) +
            (rec.doctor_title ? ' ' + escHtml(rec.doctor_title) : '') +
            (rec.doctor_emp ? ' （工号 ' + escHtml(rec.doctor_emp) + '）' : '') + '</span>';
        return '<div class="prev-record-wrap-sec emr-record-readonly" id="recSeg' + rec.id + '">' +
            '<div class="prev-record-head">' +
            authorSpan +
            '<span>记录时间：' + escHtml(rec.created_at) + '</span>' +
            typeBadge +
            '</div>' +
            '<div class="prev-record-body">' +
            (secs.length ? secs.join('') : '<div class="text-muted fs-13">（该文书暂无内容）</div>') + '</div>' +
            // 只读段签名使用只读文字样式（灰色），与整段只读基调统一
            '<div class="doc-body-sign ro-sign">医生：' + escHtml(rec.doctor_name) + '</div>' +
            '</div>';
    }

    /**
     * 按创建顺序拆分【他人】文书：排在本人文书之前的归上侧只读区、
     * 之后的归下侧只读区——多医生接诊的病历严格按时间正序依次往下
     * 接续呈现（A 首诊 → B 续写 → C 续写……），后接医生绝不倒排到
     * 首诊上方。本人尚无文书时，全部他人文书都在上侧。
     */
    function splitOthers(d) {
        var mineRid = d.record && d.record.record_id;   // 当前编辑文书的 id
        var hist = d.records_history || [];
        var myIdx = -1;
        // 按当前编辑文书 id 精确定位（切换回首诊/中间续写时，只读段/编辑器
        // 按其真实顺序排列，而非总把最新本人文书当作当前项）
        for (var i = 0; i < hist.length; i++) {
            if (mineRid && ((hist[i].record_id || 0) === mineRid || (hist[i].id || 0) === mineRid)) {
                myIdx = i;
                break;
            }
        }
        if (myIdx === -1) {
            // 未匹配（本人无文书 / 新建续写 record_id=0）：全部文书视为只读段
            return { before: hist.slice(), after: [] };
        }
        return {
            before: hist.slice(0, myIdx),
            after: hist.slice(myIdx + 1),
        };
    }

    /**
     * 渲染/刷新文档内他人文书只读段（#roBefore / #roAfter）。
     * 注意：辅助检查/门诊处置文本依赖 ORDERS 缓存，loadOrders
     * 成功后会再次调用本函数补全内容；仅替换两个容器内部 HTML，
     * 绝不触碰编辑器（#docBody），未保存内容零丢失。
     */
    function refreshReadOnlyBodies(d) {
        if (!d) d = DATA;
        if (!d) return;
        // 诊毕只读：全部文书渲染在 #docBody（无编辑器），直接整段刷新
        if (d.visit && d.visit.status === 'finished') {
            var docBody = document.getElementById('docBody');
            if (docBody && (d.records_history || []).length) {
                docBody.innerHTML = '<div class="prev-record-wrap">' + d.records_history.map(roSegmentHtml).join('') + '</div>';
            }
            return;
        }
        var parts = splitOthers(d);
        var beforeEl = document.getElementById('roBefore');
        var afterEl = document.getElementById('roAfter');
        if (beforeEl) beforeEl.innerHTML = parts.before.length ? parts.before.map(roSegmentHtml).join('') : '';
        if (afterEl) afterEl.innerHTML = parts.after.length ? parts.after.map(roSegmentHtml).join('') : '';
    }

    /**
     * 收集前序【其他医生】已添加的诊断（含医生姓名），注入编辑器供
     * 诊断模态框跨医生引用查重；本人已选列表不参与提示。
     */
    function injectPrevDiagContext() {
        if (!DATA || !DATA.records_history) return;
        var mineId = DATA.record && DATA.record.doctor_id;
        var flat = [];
        DATA.records_history.forEach(function (r) {
            if (r.doctor_id === mineId) return;
            ((r.emr && r.emr.diagnoses) || []).forEach(function (dg) {
                if (dg && dg.name) {
                    flat.push({
                        code: dg.code || '', name: dg.name,
                        part: dg.part || '', note: dg.note || '',
                        suspected: dg.suspected || '',
                        doctor_name: r.doctor_name || '前序医生',
                    });
                }
            });
        });
        Clinic.emrEditor.setPrevDiagnoses(flat);
    }

    /**
     * 生命体征紧凑显示文本（与打印病历格式一致，以「；」分隔）：
     * 血压 125/75mmHg；心率 80次/分；脉搏 80次/分；血氧 98%；呼吸 18次/分
     * 全部为空显示 -，有数据则只展示已有项
     */
    function vitalDisplayText(v) {
        v = v || {};
        var parts = [];
        if (v.bp_systolic) parts.push('血压 ' + v.bp_systolic + '/' + (v.bp_diastolic || '—') + 'mmHg');
        if (v.heart_rate) parts.push('心率 ' + v.heart_rate + '次/分');
        if (v.pulse) parts.push('脉搏 ' + v.pulse + '次/分');
        if (v.spo2) parts.push('血氧 ' + v.spo2 + '%');
        if (v.respiration) parts.push('呼吸 ' + v.respiration + '次/分');
        return parts.length ? parts.join('；') : '—';
    }

    /**
     * 打开生命体征编辑弹窗（6 个输入框：收缩压/舒张压/心率/脉搏/血氧/呼吸，与护士站共用接口）
     */
    var vitalsPopHandler = null;
    function closeVitalsPop() {
        var pop = document.getElementById('vitalsPop');
        if (pop) pop.remove();
        if (vitalsPopHandler) {
            document.removeEventListener('mousedown', vitalsPopHandler);
            vitalsPopHandler = null;
        }
    }
    function openVitals(ev) {
        // 诊毕只读：不允许修改生命体征（仅展示）
        if (DATA && DATA.visit && DATA.visit.status === 'finished') {
            Clinic.toast.warning('该患者已诊毕，生命体征为只读状态');
            return;
        }
        // 已打开则先收起（再次点击 = 关闭）
        if (document.getElementById('vitalsPop')) { closeVitalsPop(); return; }
        var sec = document.querySelector('.doc-sec-vital');
        if (!sec) { Clinic.toast.warning('生命体征区域不可见'); return; }
        // 鼠标点击位置（视口坐标，面板 fixed 定位跟随点击处）
        var cx = ev && typeof ev.clientX === 'number' ? ev.clientX : window.innerWidth / 2 - 150;
        var cy = ev && typeof ev.clientY === 'number' ? ev.clientY : 120;
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                closeVitalsPop();   // 防御：请求期间重复点击
                var v = j.data.vitals || {};
                var val = function (x) { return x || ''; };
                var pop = document.createElement('div');
                pop.id = 'vitalsPop';
                pop.className = 'finish-pop vitals-pop';
                pop.innerHTML =
                    '<div class="fs-13 fw-700 mb-8">生命体征编辑</div>' +
                    '<div class="vitals-grid">' +
                    '  <div><label class="form-label">收缩压 mmHg</label><input class="input" id="vSys" type="number" min="0" value="' + val(v.bp_systolic) + '"></div>' +
                    '  <div><label class="form-label">舒张压 mmHg</label><input class="input" id="vDia" type="number" min="0" value="' + val(v.bp_diastolic) + '"></div>' +
                    '  <div><label class="form-label">心率 次/分</label><input class="input" id="vHR" value="' + val(v.heart_rate) + '"></div>' +
                    '  <div><label class="form-label">脉搏 次/分</label><input class="input" id="vPulse" value="' + val(v.pulse) + '"></div>' +
                    '  <div><label class="form-label">血氧饱和度 %</label><input class="input" id="vSpO2" value="' + val(v.spo2) + '"></div>' +
                    '  <div><label class="form-label">呼吸 次/分</label><input class="input" id="vResp" value="' + val(v.respiration) + '"></div>' +
                    '</div>' +
                    '<div class="fs-12 text-muted mt-4">保存后护士站将同步显示。</div>' +
                    '<div class="flex gap-8 mt-8">' +
                    '  <button type="button" class="btn btn-outline btn-sm" style="flex:1" id="vitalsCancel">取消</button>' +
                    '  <button type="button" class="btn btn-primary btn-sm" style="flex:1" id="vitalsSave">保存</button>' +
                    '</div>';
                document.body.appendChild(pop);
                // fixed 定位跟随鼠标点击处，实际尺寸夹紧在视口内
                pop.style.left = (cx + 12) + 'px';
                pop.style.top = (cy + 12) + 'px';
                clampPop(pop);
                pop.querySelector('#vitalsCancel').addEventListener('click', closeVitalsPop);
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
                    var data = {
                        action: 'save_vitals',
                        visit_id: visitId,
                        bp_systolic: vals.vSys === '' ? 0 : parseInt(vals.vSys, 10),
                        bp_diastolic: vals.vDia === '' ? 0 : parseInt(vals.vDia, 10),
                        heart_rate: vals.vHR,
                        pulse: vals.vPulse,
                        spo2: vals.vSpO2,
                        respiration: vals.vResp,
                    };
                    Clinic.ajax('/api/record', data, {
                        onSuccess: function (json) {
                            Clinic.toast.success(json.msg);
                            closeVitalsPop();
                            refreshVitalDisplay();
                        },
                    });
                });
                // 点击面板以外区域关闭
                vitalsPopHandler = function (e) {
                    if (!pop.contains(e.target) && !sec.contains(e.target)) closeVitalsPop();
                };
                setTimeout(function () { document.addEventListener('mousedown', vitalsPopHandler); }, 0);
            },
        });
    }

    /**
     * 刷新生命体征紧凑显示（保存后 / 护士站同步后调用）
     */
    function refreshVitalDisplay() {
        var el = document.getElementById('vitalDisplay');
        if (!el) return;
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var v = j.data.vitals || {};
                if (DATA) DATA.vitals = v;
                el.textContent = vitalDisplayText(v);
            },
        });
    }

    /** 当前登录医生 id（DATA.record 由后端按会话返回） */
    function myDoctorId() {
        return DATA && DATA.record ? DATA.record.doctor_id : 0;
    }

    /**
     * 该开单是否为本人生成（供全局函数 viewOrderFlow 使用——
     * 全局作用域访问不到模块内部函数，必须经由公开 API 进入）
     */
    function isMyOrder(o) {
        return !!o && (o.doctor_id || 0) === myDoctorId();
    }

    /**
     * 按开单医生过滤已开项目并生成病历正文文本（辅助检查/处方行/处置项）。
     * 多医生接诊下各医生文书只呈现本人开具的项目——谁开单归属谁的病历。
     * 处方行统一走 Clinic.orderRxLines 公共方法（成组医嘱树形格式，全系统一致）。
     */
    function orderTextsFor(doctorId) {
        var aux = [];
        var proc = [];
        var rxs = [];
        (ORDERS || []).forEach(function (o) {
            if ((o.doctor_id || 0) !== doctorId) return;
            // 已退费/已取消的开单不再计入病历内容
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            o.items.forEach(function (it) {
                if (o.order_type === 'lab' || o.order_type === 'imaging') {
                    aux.push(it.item_name);
                } else if (o.order_type === 'procedure') {
                    proc.push(it.item_name + '×' + it.quantity);
                }
            });
            if (o.order_type === 'prescription') {
                Clinic.orderRxLines(o.items).forEach(function (l) { rxs.push(l); });
            }
        });
        return { aux: aux, proc: proc, rxs: rxs };
    }

    /**
     * 渲染病历正文 辅助检查 / 门诊处置（所见即所得，与打印版式一致）
     * 仅渲染当前登录医生本人开具的项目（多医生接诊，项目跟随医生归档）
     */
    /** 项目交互 token：活跃病历正文中的可点击行内标签（只读段不使用） */
    function itemToken(o, it, extra) {
        var suffix = '';
        if ((o.order_type === 'lab' || o.order_type === 'imaging') && it.report_id) suffix = '（已出报告）';
        return '<span class="emr-item-link" data-otype="' + o.order_type + '" data-oid="' + o.id + '" data-iid="' + it.id + '">' +
            escHtml(it.item_name) + (extra || '') + suffix + '</span>';
    }

    /**
     * 病历正文 辅助检查/门诊处置 渲染（活跃编辑器）：
     * 项目渲染为交互式行内标签（点击弹出详情模态框，样式见 .emr-item-link）；
     * 只读历史文书段（roSegmentHtml）仍走 orderTextsFor 纯文本，二者互不影响。
     */
    function renderDocOrders() {
        var myId = myDoctorId();
        var auxT = [], rxLines = [], dispT = [];
        (ORDERS || []).forEach(function (o) {
            if ((o.doctor_id || 0) !== myId) return;
            if (o.status === 'refunded' || o.status === 'cancelled') return;
            if (o.order_type === 'lab' || o.order_type === 'imaging') {
                o.items.forEach(function (it) { auxT.push(itemToken(o, it)); });
            } else if (o.order_type === 'procedure') {
                o.items.forEach(function (it) { dispT.push(itemToken(o, it) + (it.quantity > 1 ? '×' + it.quantity : '')); });
            } else if (o.order_type === 'prescription') {
                var i3 = 0;
                while (i3 < o.items.length) {
                    var it0 = o.items[i3];
                    var g = it0.group_no || 0;
                    if (!g) {
                        rxLines.push('<div class="ef-rx-line">' + itemToken(o, it0) +
                            '\u3000' + escHtml([it0.single_dose, it0.frequency_name, it0.route_name].filter(Boolean).join('\u3000')) +
                            '\u3000\u00D7' + it0.quantity + '</div>');
                        i3++;
                        continue;
                    }
                    var arr = [it0];
                    var j3 = i3 + 1;
                    while (j3 < o.items.length && (o.items[j3].group_no || 0) === g) { arr.push(o.items[j3]); j3++; }
                    arr.forEach(function (x, xi) {
                        if (xi === 0) {
                            rxLines.push('<div class="ef-rx-line">' + itemToken(o, x) +
                                '\u3000' + escHtml([x.single_dose, x.frequency_name, x.route_name].filter(Boolean).join('\u3000')) +
                                '\u3000\u00D7' + x.quantity + '</div>');
                        } else {
                            var head = (xi === arr.length - 1 ? '\u2514\u2500 ' : '\u251C\u2500 ') + itemToken(o, x) +
                                (x.single_dose ? '\u3000' + escHtml(x.single_dose) : '');
                            rxLines.push('<div class="ef-rx-line ef-rx-sub">' + head + '</div>');
                        }
                    });
                    i3 = j3;
                }
            }
        });
        // 结构化编辑器自动段：辅助检查（token 逗号分隔）、处方行、处置项
        Clinic.emrEditor.setAuto('aux_orders', auxT.join('，'), auxT.length > 0);
        Clinic.emrEditor.setAuto('rx_lines', rxLines.join(''), rxLines.length > 0);
        Clinic.emrEditor.setAuto('disp_items', dispT.join('，'), dispT.length > 0);
    }

    /* ==================== 诊断悬浮窗（跟随鼠标：添加 / 排序操作） ==================== */

    var diagPopHandler = null;
    /** 悬浮窗视口夹紧：内容变化（搜索结果渲染/表单展开）导致尺寸变化后调用，
     *  将浮窗整体平移回可视范围内（右/下溢出时向内收，仍保证 8px 边距）。
     *  兼容 absolute（文档坐标，需加 scrollX/Y）与 fixed（视口坐标）定位。 */
    function clampPop(pop) {
        var r = pop.getBoundingClientRect();
        var m = 8;
        var left = r.left, top = r.top;
        if (r.right > window.innerWidth - m) left = Math.max(m, window.innerWidth - r.width - m);
        if (r.bottom > window.innerHeight - m) top = Math.max(m, window.innerHeight - r.height - m);
        if (r.left < m) left = m;
        if (r.top < m) top = m;
        var fixed = getComputedStyle(pop).position === 'fixed';
        var sx = fixed ? 0 : window.scrollX, sy = fixed ? 0 : window.scrollY;
        if (left !== r.left) pop.style.left = Math.round(left + sx) + 'px';
        if (top !== r.top) pop.style.top = Math.round(top + sy) + 'px';
    }
    function closeDiagPop() {
        var pop = document.getElementById('diagPop');
        if (pop) pop.remove();
        if (diagPopHandler) {
            document.removeEventListener('mousedown', diagPopHandler);
            diagPopHandler = null;
        }
    }
    function placeDiagPop(pop, ev) {
        document.body.appendChild(pop);
        // 紧贴鼠标点击处（absolute 需加滚动偏移），再夹紧视口
        pop.style.left = (ev.clientX + window.scrollX + 12) + 'px';
        pop.style.top = (ev.clientY + window.scrollY + 12) + 'px';
        clampPop(pop);
        diagPopHandler = function (e) {
            if (!pop.contains(e.target)) closeDiagPop();
        };
        setTimeout(function () { document.addEventListener('mousedown', diagPopHandler); }, 0);
    }
    /** 诊断可写校验：仅诊毕只读。
     *  首次保存前（record_id=0）允许添加/调整——诊断暂存本地编辑器，
     *  随首次 save() 一并持久化（否则与「保存必须有初步诊断」互相死锁）。 */
    function diagEditable() {
        if (DATA && DATA.visit && DATA.visit.status === 'finished') {
            Clinic.toast.warning('该患者已诊毕，诊断不可调整');
            return false;
        }
        return true;
    }
    /** 本人当前诊断列表（DATA.record.emr.diagnoses） */
    function myDiags() {
        return (DATA && DATA.record && DATA.record.emr && DATA.record.emr.diagnoses) || [];
    }
    /** 诊断列表持久化 + 编辑器/缓存/侧边栏同步。
     *  首次保存前（record_id=0）仅本地暂存（编辑器+缓存），不调接口——
     *  诊断随首次 save() 一并写入；已保存则服务端即时持久化。 */
    function saveDiags(newDiags, okMsg) {
        var noRecord = !DATA || !DATA.record || !(DATA.record.record_id > 0);
        var myId = myDoctorId();
        function syncLocal(saved) {
            Clinic.emrEditor.setDiags(saved);
            if (DATA) {
                if (!DATA.record.emr) DATA.record.emr = {};
                DATA.record.emr.diagnoses = saved;
                // 仅更新当前编辑文书的 records_history 条目（精确匹配 record_id，
                // 避免污染本人其他续写/首诊的诊断列表，导致自己引用自己）
                var curRid = DATA.record && DATA.record.record_id;
                (DATA.records_history || []).forEach(function (h) {
                    if ((h.record_id || h.id) === curRid && h.emr) h.emr.diagnoses = saved;
                });
            }
            renderLeftNav();
        }
        if (noRecord) {
            syncLocal(newDiags);
            Clinic.toast.success(okMsg || '诊断已添加（随病历保存一并提交）');
            return;
        }
        Clinic.ajax('/api/record', {
            action: 'save_diags',
            visit_id: document.getElementById('visitId').value,
            diagnoses: JSON.stringify(newDiags),
            edit_record_id: (DATA && DATA.__edit_record_id) || 0,
        }, {
            onSuccess: function (j) {
                var saved = j.data.diagnoses || newDiags;
                syncLocal(saved);
                Clinic.toast.success(okMsg || j.msg);
            },
        });
    }

    /**
     * 诊断编辑悬浮窗（跟随鼠标）：点击病历中已添加的诊断弹出，
     * 预填部位/备注/是否疑似，保存后即时持久化到本人文书
     */
    function openDiagEditPop(ev, idx) {
        if (!diagEditable()) return;
        var list = myDiags();
        var d = list[idx];
        if (!d) return;
        closeDiagPop();
        if (ev && ev.stopPropagation) ev.stopPropagation();
        var pop = document.createElement('div');
        pop.id = 'diagPop';
        pop.className = 'finish-pop diag-pop';
        pop.innerHTML =
            '<div class="fs-13 mb-8">编辑：<span class="text-muted">' + escHtml(d.code || '') + '</span> <b>' + escHtml(d.name) + '</b></div>' +
            '<div class="form-group"><label class="form-label">部位（选填）</label><input class="input" id="dpPart" value="' + escHtml(d.part || '') + '" placeholder="如：左侧、右上肢"></div>' +
            '<div class="form-group"><label class="form-label">备注（选填）</label><input class="input" id="dpNote" value="' + escHtml(d.note || '') + '" placeholder="如：中指挫擦伤"></div>' +
            '<div class="form-group"><label class="form-label">是否疑似（选填）</label><select class="select" id="dpSus">' +
            '<option value=""' + (d.suspected !== '是' ? ' selected' : '') + '>否</option>' +
            '<option value="是"' + (d.suspected === '是' ? ' selected' : '') + '>是</option></select></div>' +
            '<div class="flex gap-8">' +
            '  <button type="button" class="btn btn-outline btn-sm" style="flex:1" id="dpeCancel">取消</button>' +
            '  <button type="button" class="btn btn-primary btn-sm" style="flex:1" id="dpeSave">保存</button>' +
            '</div>';
        placeDiagPop(pop, ev);
        pop.querySelector('#dpeCancel').addEventListener('click', closeDiagPop);
        pop.querySelector('#dpeSave').addEventListener('click', function () {
            var arr = myDiags().slice();
            if (!arr[idx]) { closeDiagPop(); return; }
            arr[idx] = {
                code: arr[idx].code, name: arr[idx].name,
                part: pop.querySelector('#dpPart').value.trim(),
                note: pop.querySelector('#dpNote').value.trim(),
                suspected: pop.querySelector('#dpSus').value,
            };
            closeDiagPop();
            saveDiags(arr, '诊断已更新：' + arr[idx].name);
        });
    }

    /**
     * 诊断添加悬浮窗（跟随鼠标）：搜索（名称/ICD10/拼音首字母）→ 选中后
     * 填写部位/备注/是否疑似 → 保存（写入本人诊断列表并即时持久化）
     */
    function openDiagPop(ev) {
        if (!diagEditable()) return;
        // 添加诊断前置条件（仅限添加行为，编辑/删除不受限）：
        // 首诊需完善主诉与现病史；续写需完善续写内容
        // （采集编辑器当前内容，无需等待保存）
        var _allow = true;
        try {
            var _cur = Clinic.emrEditor.collect();
            var _prog = DATA && DATA.record && DATA.record.record_type === 'progress';
            if (_prog) {
                if (!((_cur.progress || {}).content || '').trim()) {
                    Clinic.toast.warning('请先填写病历续写内容后再添加诊断');
                    _allow = false;
                }
            } else {
                var _cc = ((_cur.chief_complaint || {}).symptom || '').trim();
                var _pi = ((_cur.history_present || {}).content || '').trim();
                if (!_cc || !_pi) {
                    Clinic.toast.warning('请先填写主诉与现病史后再添加诊断');
                    _allow = false;
                }
            }
        } catch (e) { /* editor not rendered → block */ _allow = false; }
        if (!_allow) return;
        closeDiagPop();
        if (ev && ev.stopPropagation) ev.stopPropagation();
        var pop = document.createElement('div');
        pop.id = 'diagPop';
        pop.className = 'finish-pop diag-pop';
        pop.innerHTML =
            '<div class="fs-13 fw-700 mb-8">添加诊断</div>' +
            '<input class="input" id="dpKw" placeholder="搜索诊断 / ICD10 / 拼音首字母" autocomplete="off">' +
            '<div class="diag-pop-res" id="dpRes"><div class="fs-12 text-muted" style="padding:8px 2px">输入关键词检索 ICD10 诊断</div></div>';
        placeDiagPop(pop, ev);
        var kw = pop.querySelector('#dpKw');
        setTimeout(function () { kw.focus(); }, 50);
        var timer = null;
        kw.addEventListener('input', function () {
            var q = this.value.trim();
            if (timer) clearTimeout(timer);
            if (!q) { pop.querySelector('#dpRes').innerHTML = '<div class="fs-12 text-muted" style="padding:8px 2px">输入关键词检索 ICD10 诊断</div>'; return; }
            timer = setTimeout(function () {
                Clinic.get('/api/icd10?action=search&kw=' + encodeURIComponent(q), null, {
                    onSuccess: function (j) {
                        var list = j.data.list || [];
                        pop.querySelector('#dpRes').innerHTML = list.length
                            ? list.map(function (x) {
                                return '<div class="diag-pop-item" data-code="' + escHtml(x.diagnosis_code) + '" data-name="' + escHtml(x.diagnosis_name) + '">' +
                                    '<span class="text-muted">' + escHtml(x.diagnosis_code) + '</span> <b>' + escHtml(x.diagnosis_name) + '</b></div>';
                            }).join('')
                            : '<div class="fs-12 text-muted" style="padding:8px 2px">未检索到匹配诊断</div>';
                        clampPop(pop);   // 结果列表撑高浮窗后重新夹紧视口
                    },
                });
            }, 200);
        });
        pop.querySelector('#dpRes').addEventListener('click', function (e) {
            var item = e.target.closest('.diag-pop-item');
            if (!item) return;
            var code = item.getAttribute('data-code');
            var name = item.getAttribute('data-name');
            // 当前已选同编码诊断 → 直接提示，不再展开表单
            var dup = myDiags().some(function (d) {
                return (d.code && d.code === code) || (!code && d.name === name);
            });
            if (dup) { Clinic.toast.warning('该诊断已存在'); return; }
            // 续写场景：诊断已存在于前序医生病历 → 询问是否引用（不弹部位表单）
            var prevDg = Clinic.emrEditor.findPrevDiag(code);
            if (prevDg) {
                var refName = prevDg.doctor_name || '前序医生';
                Clinic.modal.confirm(
                    '该诊断（' + escHtml(prevDg.code) + ' ' + escHtml(prevDg.name) + '）已由 ' +
                    escHtml(refName) + ' 开具。<br>是否直接引用该诊断？（引用后仍可点击诊断编辑部位/备注）',
                    function () {
                        var nd = {
                            code: prevDg.code || code, name: prevDg.name || name,
                            part: prevDg.part || '', note: prevDg.note || '',
                            suspected: prevDg.suspected || '',
                        };
                        var list = myDiags().slice();
                        list.push(nd);
                        closeDiagPop();
                        saveDiags(list, '已引用诊断：' + nd.name);
                    },
                    { title: '引用前序诊断', okText: '引用' }
                );
                return;
            }
            // 新诊断：展开部位/备注/是否疑似表单
            pop.innerHTML =
                '<div class="fs-13 mb-8">添加：<b>' + escHtml(name) + '</b> <span class="fs-12 text-muted">' + escHtml(code) + '</span></div>' +
                '<div class="form-group"><label class="form-label">部位（选填）</label><input class="input" id="dpPart" placeholder="如：左侧、右上肢"></div>' +
                '<div class="form-group"><label class="form-label">备注（选填）</label><input class="input" id="dpNote" placeholder="如：中指挫擦伤"></div>' +
                '<div class="form-group"><label class="form-label">是否疑似（选填）</label><select class="select" id="dpSus">' +
                '<option value="">否</option><option value="是">是</option></select></div>' +
                '<div class="flex gap-8">' +
                '  <button type="button" class="btn btn-outline btn-sm" style="flex:1" id="dpBack">返回</button>' +
                '  <button type="button" class="btn btn-primary btn-sm" style="flex:1" id="dpSave">保存</button>' +
                '</div>';
            pop.querySelector('#dpBack').addEventListener('click', function () { closeDiagPop(); openDiagPop(ev); });
            clampPop(pop);   // 确认表单比搜索态高，展开后重新夹紧视口（避免底部溢出看不到保存按钮）
            pop.querySelector('#dpSave').addEventListener('click', function () {
                var dup2 = myDiags().some(function (d) {
                    return (d.code && d.code === code) || (!code && d.name === name);
                });
                if (dup2) { Clinic.toast.warning('该诊断已存在'); return; }
                var nd = {
                    code: code, name: name,
                    part: pop.querySelector('#dpPart').value.trim(),
                    note: pop.querySelector('#dpNote').value.trim(),
                    suspected: pop.querySelector('#dpSus').value,
                };
                var list = myDiags().slice();
                list.push(nd);
                closeDiagPop();
                saveDiags(list, '诊断已添加：' + name);
            });
        });
    }

    /**
     * 诊断操作悬浮窗（跟随鼠标）：⭐ 设为主诊断 / ↑ 上移 / ↓ 下移。
     * 排序写入独立的 diag_order 存储（visit+医生维度）——跨医生全局交错
     * 排序；若被调整的是**他人诊断**，则自动引用该一条到本人病历
     * （按新顺序插入），未调整的诊断不引用；编辑器初步诊断同步新顺序。
     */
    var DIAG_ROWS = [];   // 侧边栏诊断行缓存（含显示顺序与原始诊断对象）
    function openDiagOpsPop(ev, idx) {
        var row = DIAG_ROWS[idx];
        if (!row) return;
        // 主诊断（全局首行）点击不弹操作浮窗
        if (idx === 0) return;
        if (!diagEditable()) return;
        closeDiagPop();
        if (ev && ev.stopPropagation) ev.stopPropagation();
        var isLast = idx === DIAG_ROWS.length - 1;
        var pop = document.createElement('div');
        pop.id = 'diagPop';
        pop.className = 'finish-pop diag-pop';
        pop.style.width = '150px';
        pop.innerHTML =
            '<div class="fs-13 mb-8" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><b>' + escHtml(row.name) + '</b></div>' +
            '<button type="button" class="btn btn-outline btn-sm btn-block" id="dopPrimary">⭐ 设为主诊断</button>' +
            '<button type="button" class="btn btn-outline btn-sm btn-block mt-8" id="dopUp">↑ 上移</button>' +
            (isLast ? '' : '<button type="button" class="btn btn-outline btn-sm btn-block mt-8" id="dopDown">↓ 下移</button>');
        placeDiagPop(pop, ev);
        var keys = DIAG_ROWS.map(function (x) { return x.key; });
        // 按新全局顺序持久化：
        // 1) 本人病历诊断列表 = 全局顺序 ∩（本人已有 ∪ 被调整的他人诊断）——
        //    被调整的他人诊断自动引用，未调整的不引用；
        // 2) 排序键写入 diag_order 独立存储；
        // 3) 编辑器初步诊断经 saveDiags→setDiags 同步新顺序。
        function persist(newKeys, okMsg) {
            var cur = myDiags();
            var keep = {};
            var keyOf = function (d) { return (d.code || '') + '|' + d.name; };
            cur.forEach(function (d) { keep[keyOf(d)] = d; });
            // 仅他人诊断（srcId !== 本人）调整时自动引用到当前文书；
            // 本人诊断（无论在本文书还是旧续写）调整时不复制——只更新排序键
            var mineDoctorId = myDoctorId();
            var isOtherDiag = row.srcId && row.srcId !== mineDoctorId;
            var newList = [];
            newKeys.forEach(function (k) {
                if (keep[k]) {
                    newList.push(keep[k]);
                } else if (isOtherDiag && k === row.key) {
                    newList.push({
                        code: row.code, name: row.name,
                        part: row.dg.part || '', note: row.dg.note || '', suspected: row.dg.suspected || '',
                    });
                }
            });
            if (DATA) DATA.diag_order = newKeys;   // 本地先行，渲染即时生效
            saveDiags(newList, okMsg);             // 持久化本人列表 + 同步编辑器 + 刷新侧边栏
            saveDiagOrder(newKeys);                // 持久化全局排序键（静默）
        }
        pop.querySelector('#dopPrimary').addEventListener('click', function () {
            var arr = keys.slice();
            var hit = arr.splice(idx, 1)[0];
            arr.unshift(hit);
            persist(arr, '已设为主诊断：' + row.name);
        });
        pop.querySelector('#dopUp').addEventListener('click', function () {
            var arr = keys.slice();
            var t = arr[idx - 1]; arr[idx - 1] = arr[idx]; arr[idx] = t;
            persist(arr, '已上移：' + row.name);
        });
        var downBtn = pop.querySelector('#dopDown');
        if (downBtn) downBtn.addEventListener('click', function () {
            var arr = keys.slice();
            var t = arr[idx + 1]; arr[idx + 1] = arr[idx]; arr[idx] = t;
            persist(arr, '已下移：' + row.name);
        });
    }

    /** 诊断全局排序持久化（独立存储，静默——提示由调用方负责） */
    function saveDiagOrder(newKeys) {
        Clinic.ajax('/api/record', {
            action: 'save_diag_order',
            visit_id: document.getElementById('visitId').value,
            ord_keys: JSON.stringify(newKeys),
        }, {
            onSuccess: function (j) {
                if (DATA) DATA.diag_order = j.data.diag_order || newKeys;
                renderLeftNav();
            },
        });
    }

    /**
     * 删除本人诊断（右栏行内按钮）：引用诊断给出专项提醒——
     * 仅从本人病历移除，他人病历与右侧聚合列表中的该诊断不受影响
     */
    function delDiag(ev, idx) {
        var row = DIAG_ROWS[idx];
        // 仅当前编辑病历中存在的诊断可删除；其他病历（首诊/续写/他人）的诊断
        // 不显示删除按钮，强制触发时在此拦截（跟随病历走）
        if (!row || !row.inCurrent) return;
        if (!diagEditable()) return;
        if (ev && ev.stopPropagation) ev.stopPropagation();
        var doDel = function () {
            var list = myDiags().filter(function (d) {
                return !((d.code || '') === row.code && d.name === row.name);
            });
            if (list.length === myDiags().length) { Clinic.toast.warning('诊断不存在或已删除'); return; }
            // 允许删空诊断（主诊断保护移除）：删除后主诊断置空，可进一步删除病历
            saveDiags(list, '诊断已删除：' + row.name);
        };
        if (row.quoted) {
            Clinic.modal.confirm('该诊断为引用诊断，只删除自己病历中的诊断，无法删除他人已开具的诊断。确定删除？', doDel,
                { title: '删除引用诊断', okText: '确认删除' });
        } else {
            Clinic.modal.confirm('确定删除该诊断？', doDel, { title: '删除诊断', okText: '确认删除' });
        }
    }

    /**
     * 删除病历记录（生命周期约束：仅本人创建、首诊有续写则锁定、续写可独立删）。
     * 前端预览拦截与后端权威校验双重保障。
     */
    function deleteRecord(recId) {
        var node = null;
        (DATA.records_history || []).forEach(function (h) { if ((h.id || h.record_id) === recId) node = h; });
        if (!node) { Clinic.toast.warning('该病历记录不存在'); return; }
        // 身份校验（预览拦截）
        var myUid = (DATA.record && DATA.record.doctor_id) || 0;
        if ((node.doctor_id || 0) !== myUid) {
            Clinic.toast.warning('无权删除非本人创建的病历记录');
            return;
        }
        // 首诊锁定（预览拦截）
        if (node.record_type === 'initial') {
            var hasProgress = (DATA.records_history || []).some(function (h) { return h.record_type === 'progress' && h.status !== 'draft'; });
            if (hasProgress) {
                Clinic.toast.warning('该病历已存在后续病程记录，不可删除首诊病历');
                return;
            }
        }
        var label = node.record_type === 'initial' ? '首诊病历' : '续写病历';
        Clinic.modal.confirm('确定删除该' + label + '？删除后不可恢复。' +
            (node.record_type === 'initial' ? '\n（删除后系统将自动引导重新选择模板开启首诊）' : ''),
            function () {
                Clinic.ajax('/api/record', {
                    action: 'delete_record',
                    visit_id: document.getElementById('visitId').value,
                    record_id: recId,
                }, {
                    onSuccess: function (j) {
                        Clinic.toast.success(j.msg);
                        // 刷新病历树/正文，删除后联动
                        handleRecordDeleted(j.data && j.data.record_type, recId);
                    },
                });
            },
            { title: '删除' + label, okText: '确认删除' }
        );
    }

    /**
     * 删除成功后联动：
     * · 首诊删除 → 刷新页面（records_history 为空，loadData 自动触发模板选择引导）
     * · 续写删除 → 刷新页面（病历树重建，初始定位回显上一有效可编辑文书）
     */
    function handleRecordDeleted(recordType, recId) {
        // 重置脏标记与 edit 标志，避免残留
        EMR_DIRTY = false;
        DATA.__edit_record_id = 0;
        setTimeout(function () { location.reload(); }, 600);
    }

    /**
     * 加载患者已开项目（病历处置区 + 病历正文所见即所得区）
     */
    function loadOrders(visitId) {
        // 未显式传参（如左栏 30 秒刷新总线）时回退页面隐藏域，
        // 避免 visit_id=undefined 拉到空列表覆盖左栏数据
        var vid = visitId || (document.getElementById('visitId') || {}).value || '';
        Clinic.get('/api/order?action=visit_orders&visit_id=' + vid, null, {
            onSuccess: function (j) {
                ORDERS = j.data.list || [];
                renderDocOrders();
                // 已开项目就绪后刷新他人文书只读段：辅助检查、门诊处置
                // （按各文书医生本人开单归属）此时才有数据
                if (DATA) refreshReadOnlyBodies(DATA);
                // 已开项目就绪后刷新左侧全景大纲栏（金额/状态灯/明细）
                renderLeftNav();
            },
        });
    }

    /* ==================== 左侧全景大纲栏 ==================== */

    /** 缴费/报告状态 → 指示灯颜色：灰=未缴费，红=已缴费未完成（醒目），绿=已完成 */
    function navDotCls(st) {
        if (st === 'open') return 'gray';
        if (st === 'done' || st === 'dispensed') return 'green';
        return 'red';   // paid / registered / in_progress / dispensing：已缴费未完成
    }
    /** 指示灯悬浮提示（title） */
    function navDotText(st) {
        if (st === 'open') return '未缴费';
        if (st === 'done') return '已完成（报告已出）';
        if (st === 'dispensed') return '已完成（已发药）';
        return '已缴费（报告 / 执行中）';
    }
    function navDot(st) {
        return '<span class="status-indicator ' + navDotCls(st) + '" title="' + navDotText(st) + '"></span>';
    }

    /**
     * 渲染左侧大纲栏 8 大模块（数据源：DATA.records_history + ORDERS + DATA.visit）
     */
    function renderLeftNav() {
        // ---------- 1. 病历节点 ----------
        // 条目格式：日期 时间 科室 （首/续） + 医生姓名靠右（与初步诊断条目同款式）
        var recEl = document.getElementById('navRecords');
        var hist = (DATA && DATA.records_history) || [];
        var myUid = (DATA && DATA.record && DATA.record.doctor_id) || 0;
        // 本次就诊是否存在已保存的续写病程（首诊锁定判定）
        var hasSavedProgress = hist.some(function (h) { return h.record_type === 'progress' && h.status !== 'draft'; });
        recEl.innerHTML = hist.length ? hist.map(function (r2) {
            var typeName = r2.record_type === 'progress' ? '（续）' : '（首）';
            var dt = (r2.created_at || '').substring(5, 16);   // MM-DD HH:MM
            // 删除按钮：仅本人创建；本人首诊且已有续写病程则锁定不显示
            var isMine = (r2.doctor_id || 0) === myUid;
            var isFinished = DATA && DATA.visit && DATA.visit.status === 'finished';
            var canDel = !isFinished && isMine && (r2.record_type !== 'initial' || !hasSavedProgress);
            return '<div class="ena-item" onclick="scrollToRecord(' + r2.id + ',' + r2.doctor_id + ')">' +
                '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                escHtml(dt) + ' ' + escHtml(r2.dept_name || '') + '</span>' +
                '<span class="text-muted" style="flex-shrink:0;font-size:11px">' + typeName + '</span>' +
                (canDel ? '<span class="ena-del" title="删除该病历记录" onclick="event.stopPropagation();Clinic.emr.deleteRecord(' + r2.id + ')">🗑️</span>' : '') +
                '<span class="ena-sub">' + escHtml(r2.doctor_name) + '</span></div>';
        }).join('') : '';
        // 空时显示「暂无病历文书」，但若有未保存的编辑中占位则不显示
        if (!hist.length && !(DATA && (DATA.__pending_initial || DATA.__pending_progress))) {
            recEl.innerHTML = '<div class="ena-empty">暂无病历文书</div>';
        } else if (!hist.length && recEl) {
            recEl.innerHTML = '';
        }
        // 续写编辑中占位（未保存，保存/reload 后自动清除）；点击跳转到续写编辑器锚点
        var _pn = (DATA.record && DATA.record.doctor_name) || '';
        var _del = '<span class="ena-del" title="删除未完成的病历" onclick="event.stopPropagation();Clinic.emr.cancelPendingRecord()">🗑️</span>';
        if (DATA && DATA.__pending_progress && recEl) {
            recEl.insertAdjacentHTML('beforeend',
                '<div class="ena-item" style="opacity:0.6;font-style:italic;cursor:pointer" ' +
                'title="定位到续写编辑区" onclick="Clinic.emr.scrollToPendingEditor(this)">' +
                '<span>📝 续写编辑中…（未保存）</span>' + _del +
                '<span class="ena-sub">' + escHtml(_pn) + '</span></div>');
        }
        // 首诊编辑中占位（空病历选择模板后未保存）；点击跳转到首诊编辑器锚点
        if (DATA && DATA.__pending_initial && recEl) {
            recEl.insertAdjacentHTML('beforeend',
                '<div class="ena-item" style="opacity:0.6;font-style:italic;cursor:pointer" ' +
                'title="定位到首诊编辑区" onclick="Clinic.emr.scrollToPendingEditor(this)">' +
                '<span>📝 首诊编辑中…（未保存）</span>' + _del +
                '<span class="ena-sub">' + escHtml(_pn) + '</span></div>');
        }

        // ---------- 3. 初步诊断（聚合：本人诊断顺序优先，其后他人诊断） ----------
        // 显示顺序 = 本人保存的全局排序（diag_order 独立存储，跨医生交错排序
        // 且不引用任何诊断）；全部行可点击弹操作浮窗；行内删除仅本人诊断；
        // 全局首行为主诊断（徽标 + 不弹浮窗 + 无删除按钮）
        var diagEl = document.getElementById('navDiags');
        var myList3 = (DATA && DATA.record && DATA.record.emr && DATA.record.emr.diagnoses) || [];
        var diagMap = {};
        var diagOrder = [];
        var mineDoctorId = myDoctorId();
        var pushDiag = function (dg, mine, others, srcId, ownOld, inCurrent) {
            if (!dg || !dg.name) return;
            var key = (dg.code || '') + '|' + dg.name;
            if (!diagMap[key]) {
                diagMap[key] = { key: key, idx: 0, code: dg.code || '', name: dg.name, dg: dg, mine: false, others: false, ownOld: false, inCurrent: false, srcId: srcId || 0 };
                diagOrder.push(diagMap[key]);
            }
            // 只要诊断出现在本人任何文书（当前或旧续写/首诊）→ 归属本人（srcId=本人），
            // 无论他人是否也有——自己引用自己绝不算「他人诊断」
            if (mine) { diagMap[key].mine = true; diagMap[key].srcId = mineDoctorId; }
            if (others) diagMap[key].others = true;
            if (ownOld) diagMap[key].ownOld = true;
            if (inCurrent) diagMap[key].inCurrent = true;   // 当前编辑文书中存在 → 可删除
        };
        // 当前编辑文书诊断：归属本人 + inCurrent（当前文书中存在 → 显示删除按钮）
        var curRid = DATA.record && DATA.record.record_id;
        myList3.forEach(function (dg) { pushDiag(dg, true, false, mineDoctorId, false, true); });
        // 其余文书（跳过当前编辑文书）：按书写者判定——本人旧文书=本人，
        // 他人文书=他人；ownOld 标记「诊断存在于本人旧文书」（自引判定用）
        (DATA && DATA.records_history ? DATA.records_history : []).forEach(function (h) {
            if ((h.record_id || h.id) === curRid) return;
            var isMine = (h.doctor_id || 0) === mineDoctorId;
            ((h.emr && h.emr.diagnoses) || []).forEach(function (dg) {
                pushDiag(dg, isMine, !isMine, h.doctor_id || 0, isMine, false);
            });
        });
        // 按本人保存的全局排序重排（未在排序中的键保持默认相对顺序追加在后）
        var ordRank = {};
        ((DATA && DATA.diag_order) || []).forEach(function (k, i) { ordRank[k] = i; });
        diagOrder.sort(function (a, b) {
            var ra = ordRank[a.key] === undefined ? 9999 : ordRank[a.key];
            var rb = ordRank[b.key] === undefined ? 9999 : ordRank[b.key];
            return ra - rb;
        });
        diagOrder.forEach(function (x, i) { x.idx = i; });
        DIAG_ROWS = diagOrder;
        diagEl.innerHTML = diagOrder.length ? diagOrder.map(function (x) {
            var quoted = x.others && !x.ownOld;  // 仅他人诊断（不在本人任何旧文书中）显示引用标记
            // 全局首行 = 主诊断：徽标提醒，但支持删除（主诊断保护移除——
            // 删除主诊断后第二位自动递补，无则主诊断置空）
            var diagReadOnlyFin = DATA && DATA.visit && DATA.visit.status === 'finished';
            var delBtn = (x.inCurrent && !diagReadOnlyFin)
                ? '<span class="ena-del" title="删除本病历中的该诊断" onclick="Clinic.emr.delDiag(event,' + x.idx + ')">🗑️</span>'
                : '';
            var tail = x.idx === 0
                ? '<span class="badge badge-primary" style="flex-shrink:0">主诊断</span>' + delBtn
                : delBtn;
            return '<div class="ena-item" onclick="Clinic.emr.openDiagOpsPop(event,' + x.idx + ')" style="cursor:pointer">' +
                '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escHtml(x.name) + '">' +
                (x.code ? '<span class="text-muted">' + escHtml(x.code) + '</span> ' : '') +
                escHtml(x.name) + (x.dg.suspected === '是' ? '?' : '') +
                (quoted ? ' <span class="fs-11 text-muted">引用</span>' : '') +
                '</span>' + tail + '</div>';
        }).join('') : '<div class="ena-empty">暂未开立诊断</div>';

        // ---------- 4-7. 检查/检验/处置/处方 ----------
        var sum = { imaging: 0, lab: 0, procedure: 0, prescription: 0 };
        var buckets = { imaging: [], lab: [], procedure: [] };
        var rxOrders = [];
        (ORDERS || []).forEach(function (o) {
            if (o.status === 'refunded' || o.status === 'cancelled') return; // 退费/取消不计入
            if (buckets[o.order_type]) {
                sum[o.order_type] += parseFloat(o.total_amount) || 0;
                o.items.forEach(function (it) {
                    buckets[o.order_type].push({ order: o, it: it });
                });
            } else if (o.order_type === 'prescription') {
                sum.prescription += parseFloat(o.total_amount) || 0;
                rxOrders.push(o);
            }
        });
        document.getElementById('sumImaging').textContent = buckets.imaging.length ? anaMoney2(sum.imaging) : '';
        document.getElementById('sumLab').textContent = buckets.lab.length ? anaMoney2(sum.lab) : '';
        document.getElementById('sumProc').textContent = buckets.procedure.length ? anaMoney2(sum.procedure) : '';
        document.getElementById('sumRx').textContent = rxOrders.length ? anaMoney2(sum.prescription) : '';

        // 分区标题项目数徽章：检查/检验/处置按明细项数；处方按处方单数量（0 隐藏）
        setNavCount('cntImaging', buckets.imaging.length);
        setNavCount('cntLab', buckets.lab.length);
        setNavCount('cntProc', buckets.procedure.length);
        setNavCount('cntRx', rxOrders.length);

        // 横条总费用徽章：挂号费 + 全部有效开单合计（退费/取消不计）
        var totalFee = (DATA && DATA.visit ? parseFloat(DATA.visit.fee) : 0) || 0;
        totalFee += sum.imaging + sum.lab + sum.procedure + sum.prescription;
        var hdrTotal = document.getElementById('hdrTotal');
        if (hdrTotal) {
            if (totalFee > 0) {
                hdrTotal.textContent = '总费用 ¥' + totalFee.toFixed(2);
                hdrTotal.style.display = '';
                if (!hdrTotal._feeHover) {
                    hdrTotal._feeHover = true;
                    hdrTotal.addEventListener('mouseenter', function () { showFeePop(hdrTotal); });
                    hdrTotal.addEventListener('mouseleave', hideFeePop);
                }
            } else {
                hdrTotal.style.display = 'none';
            }
        }

        fillTypeNav('navImaging', buckets.imaging, '检查');
        fillTypeNav('navLab', buckets.lab, '检验');
        fillTypeNav('navProc', buckets.procedure, '处置');

        // 处方模块：按处方单列出（处方N + 开单医生靠右），行内删除仅本人
        // 未缴费/已退费处方可见；点击条目展开药品明细与发药状态
        var rxE1 = document.getElementById('navRx');
        if (!rxOrders.length) {
            rxE1.innerHTML = '<div class="ena-empty">暂未开立处方</div>';
        } else {
            rxE1.innerHTML = rxOrders.map(function (o, oi) {
                var canDel = Clinic.emr.isMyOrder(o) && (o.status === 'open' || o.status === 'refunded');
                return '<div class="ena-item" onclick="showRxDetail(\'' + o.id + '\')">' +
                    navDot(o.status) +
                    '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">处方' + (oi + 1) + '</span>' +
                    '<span class="ena-sub">' + escHtml(o.doctor_name || '') + '</span>' +
                    (canDel ? '<span class="ena-del" title="毁方" onclick="delOrderFlow(\'' + o.id + '\',\'毁方\');event.stopPropagation()">🗑️</span>' : '') +
                    '</div>';
            }).join('');
        }

        // ---------- 8. 诊断证明 ----------
        // 注意：接口在根级返回 has_certificate（visit 载荷并无 cert_issued 字段，
        // 勿回退旧字段名，否则已开具也永远显示「暂未开具」）
        var certEl = document.getElementById('navCert');
        var certIssued = !!(DATA && DATA.has_certificate);
        // 一份病历（同一次就诊）只能开具一份诊断证明：已开具则移除标题「＋」，
        // 后端 certificate 接口同时按 visit_id 强制去重拦截
        var certAdd = document.getElementById('certAddBtn');
        if (certAdd && certIssued) certAdd.remove();
        if (certIssued) {
            // 条目格式与病历节点一致：日期 时间 科室 + 医生姓名靠右
            // （无首/续标记；科室取就诊当前科室，证明随就诊归属）
            var cert = (DATA && DATA.certificate) || {};
            var certTime = (cert.created_at || '').substring(5, 16);   // MM-DD HH:MM
            var certDept = (DATA.visit && DATA.visit.dept_name) || '';
            certEl.innerHTML = '<div class="ena-item" onclick=\"Clinic.emr.certificateModal(visitId.value, \'诊断证明\')\">' +
                '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                escHtml(certTime) + ' ' + escHtml(certDept) + '</span>' +
                '<span class="ena-sub">' + escHtml(cert.doctor_name || '') + '</span></div>';
        } else {
            // 未开具时不再放正文入口，统一走分区标题右侧「＋」（emrNavAdd('cert')）
            certEl.innerHTML = '<div class="ena-empty">暂未开具</div>';
        }
    }

    /** 处方金额显示（¥xx.xx，空单返回空串由标题隐藏） */
    function anaMoney2(v) { return '¥' + Number(v || 0).toFixed(2); }

    /** 分区标题项目数徽章：>0 显示数字，0 隐藏 */
    function setNavCount(id, n) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = n > 0 ? String(n) : '';
        el.style.display = n > 0 ? '' : 'none';
    }

    /** 检查/检验/处置三栏共用填充：状态灯 + 点击详情弹窗 + 开单医生靠右；
     *  行内删除按钮仅本人开具且未缴费/已退费的单子显示（复用 delOrderFlow） */
    function fillTypeNav(elId, arr, label) {
        var el = document.getElementById(elId);
        if (!arr.length) { el.innerHTML = '<div class="ena-empty">暂未开立' + label + '</div>'; return; }
        el.innerHTML = arr.map(function (x) {
            var st = x.it.status || 'open';
            var canDel = Clinic.emr.isMyOrder(x.order) && (x.order.status === 'open' || x.order.status === 'refunded');
            return '<div class="ena-item" onclick="showItemDetail(\'' + x.order.id + '\',\'' + x.it.id + '\')">' +
                navDot(st) + '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                escHtml(x.it.item_name) + '</span>' +
                '<span class="ena-sub">' + escHtml(x.order.doctor_name || '') + '</span>' +
                (canDel ? '<span class="ena-del" title="删除该开单" onclick="delOrderFlow(\'' + x.order.id + '\',\'删除\');event.stopPropagation()">🗑️</span>' : '') +
                '</div>';
        }).join('');
    }

    /** 病历节点定位：平滑滚动中栏到对应文书位置 */
    /**
     * 将当前编辑文书切换为指定本人文书（恢复为可编辑状态）。
     * 前置条件由调用方校验（当前文书必填已保存且无未保存修改）。
     * 切换后重渲染整卡：目标文书为编辑器，其余（含原当前文书）全部只读段。
     */
    function switchToRecord(recId) {
        var target = null;
        (DATA.records_history || []).forEach(function (h) { if (h.record_id === recId) target = h; });
        if (!target) { Clinic.toast.warning('未找到该病历文书'); return; }
        DATA.record = {
            record_id: target.record_id,
            id: target.id,
            doctor_id: target.doctor_id,
            doctor_name: target.doctor_name,
            doctor_emp: target.doctor_emp || '',
            doctor_title: target.doctor_title || '',
            record_type: target.record_type,
            emr: target.emr || {},
            consciousness: target.consciousness || '',
            created_at: target.created_at || '',
            updated_at: target.updated_at || '',
        };
        DATA.__edit_record_id = recId;   // 保存时精确回写该文书
        // 重渲染时抑制内部自动滚动（避免 200ms 延迟造成「先闪可编辑再滚动」），
        // 渲染完成后先让病历内容渐显动画播放，再平滑滚动到对应文书锚点
        DATA.__noAutoScroll = true;
        renderEmrCard(DATA);
        renderLeftNav();
        DATA.__noAutoScroll = false;
        // 切换动画：先「出现」续写病历内容，再平滑滚动到其锚点
        var cardEl2 = document.getElementById('emrCard');
        if (cardEl2) {
            cardEl2.classList.remove('emr-card-enter');
            void cardEl2.offsetWidth;   // 强制 reflow 重启动画
            cardEl2.classList.add('emr-card-enter');
        }
        scrollToEditor(350);
    }

    window.scrollToRecord = function (recId, doctorId) {
        var r = DATA && DATA.record;
        // 诊毕只读：所有文书均为只读段，统一滚动到对应 recSeg{id}（无编辑器锚点）
        if (DATA && DATA.visit && DATA.visit.status === 'finished') {
            var segF = document.getElementById('recSeg' + recId);
            if (segF) {
                var scF = document.querySelector('.emr-main-editor-scroll');
                if (!scF) return;
                var yF = segF.getBoundingClientRect().top - scF.getBoundingClientRect().top + scF.scrollTop - 8;
                scF.scrollTo({ top: Math.max(0, yF), behavior: 'smooth' });
            } else {
                Clinic.toast.info('该文书区域当前不可见');
            }
            return;
        }
        var mineId = r ? r.doctor_id : 0;
        // 1. 点击当前编辑文书 → 滚动到其编辑器锚点
        if (r && recId === r.record_id) {
            scrollToEditor(0);
            return;
        }
        // 2. 他人文书 → 只读段，直接滚动定位
        if (doctorId !== mineId) {
            var seg = document.getElementById('recSeg' + recId);
            if (seg) {
                var sc = document.querySelector('.emr-main-editor-scroll');
                if (!sc) return;
                var y2 = seg.getBoundingClientRect().top - sc.getBoundingClientRect().top + sc.scrollTop - 8;
                sc.scrollTo({ top: Math.max(0, y2), behavior: 'smooth' });
            } else {
                Clinic.toast.info('该文书区域当前不可见');
            }
            return;
        }
        // 3. 本人旧文书 → 切换为可编辑状态（前置：必填已保存 + 无未保存修改）
        if (EMR_DIRTY) {
            Clinic.toast.warning('当前病历有未保存的修改，请先点击「💾 保存」后再切换病历节点');
            return;
        }
        if (!isRecordComplete()) {
            var need3 = r && r.record_type === 'progress' ? '病历续写内容与初步诊断' : '主诉、现病史与初步诊断';
            Clinic.toast.warning('请先完善并保存当前病历的必填项（' + need3 + '），再切换病历节点');
            return;
        }
        switchToRecord(recId);
    };

    /** 左栏分区标题「＋」快捷添加入口：
     *  检查/检验/处置/处方/诊断/诊断证明 → 复用原右栏开单与表单能力；
     *  病历/知情同意书 → 暂为占位提示（后期完善）。
     *  只读状态由各能力自行拦截（emr-write 隐藏 / 编辑器 READONLY 校验）。 */
    window.emrNavAdd = function (type, ev) {
        if (!window.Clinic) return;
        switch (type) {
            case 'imaging': if (requireSaved('开单')) Clinic.order.open('imaging'); return;
            case 'lab': if (requireSaved('开单')) Clinic.order.open('lab'); return;
            case 'procedure': if (requireSaved('开单')) Clinic.order.open('procedure'); return;
            case 'prescription': if (requireSaved('开单')) Clinic.order.open('prescription'); return;
            case 'cert':
                // 归档病历：先确认再补开（区分是否接诊过该患者）；
                // 未归档：直接进入开具表单
                var dd = DATA || {};
                if (dd.visit && dd.visit.status === 'finished') {
                    var treatedNow = false;
                    (dd.records_history || []).forEach(function (h) {
                        if ((h.doctor_id || 0) === myDoctorId()) treatedNow = true;
                    });
                    var msg = '该病历已经归档' +
                        (treatedNow ? '' : '，且您未接诊过该病人') +
                        (treatedNow ? '，是否补开诊断证明？' : '，是否确认为该患者开具诊断证明？');
                    Clinic.modal.confirm(msg, function () {
                        certificateModal(document.getElementById('visitId').value, '补开诊断证明', null, true);
                    });
                } else {
                    openCertificate();
                }
                return;
            case 'diags':
                openDiagPop(ev);
                return;
            case 'records':
                // 病历节点「+」：
                // 首诊（无任何已保存病历且未编辑中）→ 弹模板选择；
                // 首诊编辑中（模板已选，编辑器渲染中，未保存）→ 必须先完善必填、
                //   保存、无脏数据后，才允许续写；
                // 本人已有文书 → 续写：先校验当前文书必填已保存，再将当前文书
                //   转为只读段、在下方新建续写编辑器（DOM 局部操作，不重渲染整页）；
                // 他人有文书本人无文书 → 渲染续写编辑器
                (function () {
                    if (DATA && DATA.visit && DATA.visit.status === 'finished') {
                        Clinic.toast.info('该患者已诊毕，病历为只读状态');
                        return;
                    }
                    // 首诊编辑中（模板已选，未保存）→ 必须保存后才能续写
                    if (DATA.__pending_initial) {
                        if (EMR_DIRTY) {
                            Clinic.toast.warning('当前首诊病历有修改未保存，请先点击「💾 保存」后再续写');
                            return;
                        }
                        // isRecordComplete 在 record_id=0 时返回 false，
                        // 此处必触发，提示完善+保存
                        if (!isRecordComplete()) {
                            Clinic.toast.warning('请先完善并保存当前首诊病历的必填项（主诉、现病史与初步诊断），再新建续写');
                            return;
                        }
                        Clinic.toast.warning('请先保存当前首诊病历后再续写');
                        return;
                    }
                    var hist = (DATA && DATA.records_history) || [];
                    if (!hist.length) {
                        // 首诊：弹模板选择
                        openTemplatePicker(ev);
                        return;
                    }
                    var r = DATA.record;
                    // 正在新建续写（未保存的续写编辑态）→ 提示先完成并保存当前续写，
                    // 避免再次点击「+」无反应
                    if (DATA.__pending_progress || DATA.__progress_new) {
                        Clinic.toast.warning('当前续写病历尚未保存，请先完善必填项并点击「💾 保存」后再续写');
                        return;
                    }
                    // 本人已有保存文书 → 续写（不限次数，先校验必填）
                    if (r && r.record_id > 0) {
                        if (!isRecordComplete()) {
                            var need = r.record_type === 'progress'
                                ? '病历续写内容与初步诊断'
                                : '主诉、现病史与初步诊断';
                            Clinic.toast.warning('请先完善并保存当前病历的必填项（' + need + '），再新建续写');
                            return;
                        }
                        addProgressEditor();
                        return;
                    }
                    // 无本人文书但有他人文书 → 占位态：渲染续写编辑器
                    var ph = document.getElementById('roPlaceholder');
                    if (ph) {
                        createProgressEditor();
                    } else {
                        scrollToEditor(0);
                    }
                })();
                return;
            default:
                Clinic.toast.info('添加知情同意书功能建设中，敬请期待');
        }
    };

    /**
     * 检查/检验/处置 项目详情弹窗：
     * 检查→影像报告查看；检验→化验指标明细；处置→执行记录与费用明细
     */
    window.showItemDetail = function (orderId, itemId) {
        var o = null, it = null;
        (ORDERS || []).forEach(function (x) {
            if (x.id !== orderId) return;
            o = x;
            x.items.forEach(function (x2) { if (x2.id === itemId) it = x2; });
        });
        if (!o || !it) return;
        var typeNames = { imaging: '检查详情与影像报告', lab: '化验报告与指标明细', procedure: '处置执行记录' };
        var stMap = { open: '<span class="badge badge-warning">待缴费</span>', paid: '<span class="badge badge-primary">已缴费</span>',
            registered: '<span class="badge badge-primary">已登记</span>', in_progress: '<span class="badge badge-primary">执行中</span>',
            done: '<span class="badge badge-success">已完成</span>' };
        var html = '<div style="display:grid;grid-template-columns:minmax(0,1fr) 170px;gap:16px;width:100%">' +
            '<div style="min-width:0">' +
            '<div class="fs-14 fw-600 mb-8">' + it.item_name + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</div>' +
            '<div class="fs-13 text-muted mb-8">单价：¥' + parseFloat(it.price || 0).toFixed(2) +
            ' ｜ 费用小计：¥' + (parseFloat(it.price || 0) * it.quantity).toFixed(2) + '</div>' +
            '<div class="fs-13 mb-4">执行状态：' + (stMap[it.status] || it.status) + '</div>';
        if (o.order_type === 'procedure' && it.executed_by) {
            html += '<div class="fs-13 text-success mb-4">执行人：' + escHtml(it.executed_by) + (it.executed_at ? ' ｜ ' + it.executed_at : '') + '</div>';
        }
        html += '<div class="fs-13 text-muted">申请单号：' + o.order_no + ' ｜ 开单医生：' + escHtml(o.doctor_name || '') + '</div>';
        if (it.report_id) {
            // 已出报告：打印预览按钮 + 报告文字结果内联展示（异步填充）
            html += '<button type="button" class="btn btn-primary btn-sm mt-12" ' +
                'onclick="Clinic.print.load(\'/api/print?action=report&report_id=' + it.report_id + '\')">📄 查看报告（打印预览）</button>' +
                '<div id="anaReportBox" class="mt-12 fs-13 text-muted">📄 报告结果加载中…</div>';
        } else if (o.order_type !== 'procedure') {
            html += '<div class="fs-12 text-muted mt-8">报告尚未出具，出具后可在此直接查看</div>';
        }
        html += '</div>';
        if (it.report_id) {
            Clinic.get('/api/doctor?action=report_detail&report_id=' + it.report_id, null, {
                onSuccess: function (rj) {
                    if (!rj.ok) return;
                    var box = document.getElementById('anaReportBox');
                    if (!box || !rj.data) return;
                    var d2 = rj.data, h2 = '';
                    if (d2.type === 'lab') {
                        h2 = '<div class="fw-600 mb-4">🧾 检验指标明细</div>' +
                            '<div class="table-wrap"><table class="table"><thead><tr>' +
                            '<th>项目</th><th>结果</th><th>单位</th><th>参考范围</th><th>危急值</th></tr></thead><tbody>' +
                            (d2.rows || []).map(function (r3) {
                                return '<tr><td>' + escHtml(r3.name) + '</td>' +
                                    '<td class="fw-600">' + escHtml(r3.value) + '</td>' +
                                    '<td>' + escHtml(r3.unit || '-') + '</td>' +
                                    '<td>' + escHtml(r3.range || '-') + '</td>' +
                                    '<td>' + (r3.critical ? '<span class="text-danger">' + escHtml(r3.critical) + '</span>' : '-') + '</td></tr>';
                            }).join('') +
                            '</tbody></table></div>';
                    } else {
                        h2 = '<div class="fw-600 mb-4">🩻 影像报告</div>' +
                            '<div class="mb-4"><b>影像所见：</b>' + escHtml(d2.findings || '-') + '</div>' +
                            '<div><b>诊断结论：</b>' + escHtml(d2.conclusion || '-') + '</div>';
                    }
                    h2 += '<div class="fs-12 text-muted mt-4">执行/报告人：' + escHtml(d2.executor || '-') +
                        ' ｜ ' + escHtml(d2.time || '') + '</div>';
                    box.innerHTML = h2;
                    box.className = 'mt-12 fs-13';
                },
            });
        }
        // 右侧闭环追踪：与开单弹窗右侧流程完全一致（开单→缴费→登记→完成/药房发药）
        var steps;
        if (o.order_type === 'procedure') {
            steps = [{ label: '开单' }, { label: '缴费' }, { label: '登记' }, { label: '执行完成' }];
        } else {
            steps = [{ label: '开单' }, { label: '缴费' }, { label: '登记' }, { label: '完成' }];
        }
        var curIdx = itemStepIdx(it.status, steps.length - 1);
        html += flowColumnHtml(steps, curIdx);
        html += '</div>';
        // 操作区：打印申请单（本人或他人均可补打）；删除仅限未缴费/已退费的开单医生本人
        var delLabel = o.order_type === 'prescription' ? '毁方' : '删除';
        var delBtn2 = (!Clinic.emr.isMyOrder(o) || (o.status !== 'open' && o.status !== 'refunded')) ? ''
            : '<button type="button" class="btn btn-danger btn-sm mt-8" onclick="delOrderFlow(\'' + o.id + '\',\'' + delLabel + '\')">🗑️ ' + delLabel + '</button>';
        html += '<div style="margin-top:12px">' +
            '<button type="button" class="btn btn-outline btn-sm" ' +
            'onclick="Clinic.print.load(\'/api/print?action=order&order_id=' + o.id + '\',null,\'a5\')">🖨️ 打印申请单</button>' +
            delBtn2 + '</div>';
        Clinic.modal.open(html, { title: typeNames[o.order_type] || '项目详情', size: 'modal-lg' });
    };

    /**
     * 闭环追踪流程列（纵向步骤条）：steps=[{label}], curIdx=-1 表示已退费/取消
     */
    function flowColumnHtml(steps, curIdx) {
        var flow = steps.map(function (st, i) {
            var cls = (curIdx >= 0 && i <= curIdx) ? 'var(--success)' : 'var(--border)';
            return '<div class="flex gap-8" style="align-items:center">' +
                '<div style="width:26px;height:26px;border-radius:50%;background:' + cls + ';' +
                'display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex-shrink:0">' +
                (i + 1) + '</div>' +
                '<div class="fs-13" style="color:' + (curIdx >= 0 && i <= curIdx ? 'var(--text)' : 'var(--text-muted)') + '">' +
                st.label + '</div></div>';
        }).join('<div style="width:2px;height:18px;background:var(--border);margin-left:12px"></div>');
        return '<div style="border-left:1px solid var(--border);padding-left:16px">' +
            '<div class="fw-600 mb-8 fs-13">流程进度</div>' + flow + '</div>';
    }

    /** 按条目状态换算流程步序（open=0 起；done=末步） */
    function itemStepIdx(st, lastIdx) {
        if (st === 'open') return 0;
        if (st === 'paid') return 1;
        if (st === 'registered' || st === 'in_progress') return Math.max(1, lastIdx - 1);
        if (st === 'done' || st === 'dispensed') return lastIdx;
        return -1; // refunded / cancelled 等异常态
    }

    /**
     * 处方组详情模态框：同组药品明细、规格、剂量频次途径、费用与药房发药状态
     */
    window.showRxDetail = function (orderId) {
        var o = null;
        (ORDERS || []).forEach(function (x) { if (x.id === orderId) o = x; });
        if (!o) return;
        var rxStatusMap = {
            open: '<span class="badge badge-warning">待缴费</span>',
            paid: '<span class="badge badge-primary">已缴费 · 待发药</span>',
            dispensing: '<span class="badge badge-warning">发药中</span>',
            dispensed: '<span class="badge badge-success">已发药</span>',
            refunded: '<span class="badge badge-gray">已退费</span>',
            cancelled: '<span class="badge badge-gray">已取消</span>',
        };
        var rows = '';
        var n = o.items.length;
        o.items.forEach(function (it, idx) {
            var isSub = (it.group_no || 0) > 0 && idx > 0 && (o.items[idx - 1].group_no || 0) === it.group_no;
            var tree = isSub ? ((idx === n - 1 || (o.items[idx + 1] || {}).group_no !== it.group_no) ? '\u2514\u2500 ' : '\u251C\u2500 ') : '';
            var subtotal = (parseFloat(it.price || 0) * (it.quantity || 0)).toFixed(2);
            rows += '<tr>' +
                '<td class="fs-13">' + tree + escHtml(it.item_name) +
                (it.spec ? '<div class="fs-12 text-muted">规格：' + escHtml(it.spec) + '</div>' : '') + '</td>' +
                '<td class="fs-13">' + escHtml(it.single_dose || '—') + '</td>' +
                '<td class="fs-13">' + escHtml(it.frequency_name || '—') + '</td>' +
                '<td class="fs-13">' + escHtml(it.route_name || '—') + '</td>' +
                '<td class="fs-13">' + (it.quantity || 0) + '</td>' +
                '<td class="fs-13 text-muted">¥' + subtotal + '</td></tr>';
        });
        var leftHtml = '<div style="min-width:0">' +
            '<div class="flex-between mb-8">' +
            '<span class="fs-13 text-muted">处方号：' + escHtml(o.order_no || '') + '</span>' +
            '<span>' + (rxStatusMap[o.status] || o.status) + '</span></div>' +
            '<div class="fs-12 text-muted mb-8">开单医生：' + escHtml(o.doctor_name || '') + ' ｜ ' + o.created_at + '</div>' +
            '<div class="table-wrap"><table class="table"><thead><tr>' +
            '<th>药品</th><th>剂量</th><th>频次</th><th>途径</th><th>数量</th><th>小计</th></tr></thead><tbody>' +
            rows + '</tbody></table></div>' +
            '<div class="flex-between mt-8"><span></span><span class="fw-600">合计：¥' + parseFloat(o.total_amount || 0).toFixed(2) + '</span></div>' +
            '<div style="margin-top:10px">' +
            '<button type="button" class="btn btn-outline btn-sm" ' +
            'onclick="Clinic.print.load(\'/api/print?action=order&order_id=' + o.id + '\',null,\'a5\')">🖨️ 打印处方笺</button>';
        var rxCanDel = Clinic.emr.isMyOrder(o) && (o.status === 'open' || o.status === 'refunded');
        if (rxCanDel) {
            leftHtml += ' <button type="button" class="btn btn-danger btn-sm" style="margin-left:8px" onclick="delOrderFlow(\'' + o.id + '\',\'毁方\')">🗑️ 毁方</button>';
        }
        leftHtml += '</div>' +   // 闭合「打印/毁方」按钮容器
            '</div>';            // 闭合左列容器（此前漏闭导致流程列被解析为其子元素、渲染到下方）
        // 右侧闭环追踪：与开单弹窗右侧流程完全一致（开单→缴费→登记→药房发药）
        var steps = [
            { label: '开单' }, { label: '缴费' },
            { label: '登记' }, { label: '药房发药' },
        ];
        var curIdx = 0;
        if (o.status === 'dispensed') curIdx = 3;
        else if (o.status === 'dispensing' || o.status === 'registered') curIdx = 2;
        else if (o.status === 'paid') curIdx = 1;
        else if (o.status === 'refunded' || o.status === 'cancelled') curIdx = -1;
        Clinic.modal.open('<div style="display:grid;grid-template-columns:minmax(0,1fr) 170px;gap:16px;width:100%">' + leftHtml + flowColumnHtml(steps, curIdx) + '</div>',
            { title: '处方明细', size: 'modal-lg' });
    };

    /**
     * 病历正文交互穿透：事件代理统一分发 .emr-item-link 点击 →
     * 检查/检验/处置走 showItemDetail，处方药品走 showRxDetail。
     * 只读历史文书段（.emr-record-readonly）与诊毕只读态不响应。
     */
    function bindItemTokenDelegate() {
        var scroller = document.querySelector('.emr-main-editor-scroll');
        if (!scroller || scroller.dataset.tokenBound === '1') return;
        scroller.dataset.tokenBound = '1';
        scroller.addEventListener('click', function (e) {
            var t = e.target.closest('.emr-item-link');
            if (!t) return;
            // 只读降级：诊毕只读文档或前序/后续他人只读文书段内不响应
            var docBody = document.getElementById('docBody');
            if (docBody && docBody.getAttribute('data-ro') === '1') return;
            if (t.closest('.prev-record-wrap-sec, .emr-record-readonly')) return;
            var oid2 = t.getAttribute('data-oid');
            var iid2 = t.getAttribute('data-iid');
            var o = null;
            (ORDERS || []).forEach(function (x) { if (x.id === oid2) o = x; });
            if (!o) return;
            if (t.getAttribute('data-otype') === 'prescription') showRxDetail(oid2);
            else showItemDetail(oid2, iid2);
        });
    }

    /** 左栏折叠/展开（挂 window：IIFE 执行期 Clinic.emr 尚未赋值，
     *  直接写 Clinic.emr.toggleNavSec 会报 Cannot set properties of undefined） */
    window.toggleNavSec = function (titleEl) {
        var sec = titleEl.parentNode;
        var body = sec.querySelector('.ena-sec-body');
        if (!body) { sec.classList.toggle('collapsed'); return; }
        var willCollapse = !sec.classList.contains('collapsed');
        if (willCollapse) {
            // 收起：先钉住当前高度，再过渡到 0（两帧保证起始值生效）
            body.style.maxHeight = body.scrollHeight + 'px';
            body.style.opacity = '1';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    body.style.maxHeight = '0';
                    body.style.opacity = '0';
                    sec.classList.add('collapsed');
                });
            });
        } else {
            // 展开：从 0 过渡到实际内容高度，完成后解除限制（长列表不被裁切）
            sec.classList.remove('collapsed');
            body.style.maxHeight = body.scrollHeight + 'px';
            body.style.opacity = '1';
            var done = function (e) {
                if (e.propertyName !== 'max-height') return;
                if (!sec.classList.contains('collapsed')) body.style.maxHeight = 'none';
                body.removeEventListener('transitionend', done);
            };
            body.addEventListener('transitionend', done);
        }
    };

    /**
     * 当前时间 YYYY-MM-DD HH:mm:ss（用于记录时间展示）
     */
    function fmtDateTime() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
            ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    /**
     * 保存病历（结构化：仅提交完整 emr_data JSON 对象）
     * @param {boolean} finish 是否诊毕
     */
    function save(finish, extra) {
        var emr = Clinic.emrEditor.collect();
        var cc = emr.chief_complaint || {};
        var pi = emr.history_present || {};
        // 按文书类型分支校验（与后端 save 同规则）：
        // 首诊=主诉/现病史/诊断；续写=病历续写内容/诊断
        var isProgress = DATA && DATA.record && DATA.record.record_type === 'progress';
        if (isProgress) {
            if (!((emr.progress || {}).content || '').trim()) { Clinic.toast.warning('请填写病历续写内容（必填，可快捷填入「病史同上」）'); return; }
            if (!emr.diagnoses || !emr.diagnoses.length) { Clinic.toast.warning('请添加初步诊断（必填）'); return; }
        } else {
            if (!(cc.symptom || '').trim()) { Clinic.toast.warning('请填写主诉（必填）'); return; }
            if (!(pi.content || '').trim()) { Clinic.toast.warning('请填写现病史（必填）'); return; }
            if (!emr.diagnoses || !emr.diagnoses.length) { Clinic.toast.warning('请添加初步诊断（必填）'); return; }
        }

        var data = {
            action: 'save',
            visit_id: document.getElementById('visitId').value,
            emr_data: JSON.stringify(emr),
            consciousness: document.getElementById('consciousness') ? document.getElementById('consciousness').value : '',
            visit_type: document.getElementById('visitType') ? document.getElementById('visitType').value : '初诊',
        };
        if (finish) data.finish = 1;
        // 续写落库标志：本人已有文书后点击「病历节点 +」新建续写（record_id=0，
        // 保存时以 progress_new 强制新建独立续写文书，而非更新旧文书）
        if (DATA && DATA.__progress_new) data.progress_new = 1;
        // 切换回本人旧文书编辑：保存时精确回写该文书
        if (DATA && DATA.__edit_record_id) data.edit_record_id = DATA.__edit_record_id;
        // 诊毕转归（confirmFinish 面板传入）：离院方式 + 补充信息
        if (finish && extra) {
            data.disposition = extra.disposition || '';
            data.disposition_detail = extra.disposition_detail || '';
        }
        Clinic.ajax('/api/record', data, {
            loading: true,
            onSuccess: function (j) {
                EMR_DIRTY = false;   // 保存成功清除脏标记（诊毕跳转不再触发离开提醒）
                document.getElementById('saveStatus').textContent = '已保存 ' + new Date().toLocaleTimeString();
                // 同步本地缓存：保存成功后无需刷新页面，开检验/检查/处置/处方与打印病历立即生效
                if (DATA) {
                    DATA.record.emr = emr;
                    // 关键：同步服务端返回的文书 ID。首次保存前 record_id=0，
                    // isRecordComplete() 会因「本人尚无文书」判定不完整，
                    // 导致开单/诊断证明/打印提示需完善病历；回写后即时生效免刷新。
                    if (j.data && (j.data.record_id || 0) > 0) {
                        DATA.record.record_id = j.data.record_id;
                        DATA.record.id = j.data.record_id;
                    }
                    DATA.record.consciousness = data.consciousness;
                    DATA.record.visit_type = data.visit_type;
                    DATA.record.status = finish ? 'done' : 'draft';
                    var now = fmtDateTime();
                    DATA.record.updated_at = now;
                    if (!DATA.record.created_at) DATA.record.created_at = now;
                    // 同步左侧大纲栏数据源：本人文书并入 records_history
                    // （首次保存新增节点，续存更新内容），诊断列表随之刷新
                    if (!DATA.records_history) DATA.records_history = [];
                    var mineId2 = DATA.record.doctor_id;
                    var curRid2 = DATA.record.record_id;
                    var histEntry = null;
                    // 精确匹配当前编辑文书（切换回旧文书时避免误更新最新本人文书）
                    DATA.records_history.forEach(function (h2) {
                        if (curRid2 && (h2.record_id || h2.id) === curRid2) histEntry = h2;
                    });
                    if (!histEntry) {
                        DATA.records_history.forEach(function (h2) {
                            if (h2.doctor_id === mineId2) histEntry = h2;
                        });
                    }
                    if (!histEntry) {
                        histEntry = {
                            id: DATA.record.record_id, record_id: DATA.record.record_id,
                            doctor_id: mineId2, doctor_name: DATA.record.doctor_name,
                            doctor_emp: DATA.record.doctor_emp, doctor_title: DATA.record.doctor_title,
                            dept_name: (DATA.visit && DATA.visit.dept_name) || '',
                            record_type: DATA.record.record_type,
                            created_at: DATA.record.created_at, updated_at: now,
                            emr: JSON.parse(JSON.stringify(emr)),
                            consciousness: data.consciousness,
                        };
                        DATA.records_history.push(histEntry);
                    } else {
                        histEntry.emr = JSON.parse(JSON.stringify(emr));
                        histEntry.updated_at = now;
                    }
                    DATA.__pending_initial = false;   // 首诊已保存，占位消失
                    renderLeftNav();
                    // 续写条幅时间实时刷新为首次保存时间（普通续写保存后不刷新页面）
                    if (DATA.record.record_type === 'progress') fillContHead(DATA.record);
                    // 记录时间 = 首次保存时间（created_at），后续多次保存不变；
                    // 最近保存 = 最近一次保存时间（updated_at），每次保存刷新，仅供医师参考
                    var st = document.getElementById('docSavedBadge');
                    if (st) {
                        st.textContent = '最近保存：' + now;
                        st.style.display = '';
                    }
                }
                Clinic.toast.success(j.msg);
                // 新建续写文书已落库：刷新页面以重建多文书结构
                // （本人旧文书转为只读段、新续写成为当前编辑文书），
                // 避免前端 records_history 手工同步错乱
                if (DATA && DATA.__progress_new) {
                    DATA.__progress_new = false;
                    DATA.__pending_progress = false;
                    setTimeout(function () { location.reload(); }, 800);
                    return;
                }
                if (finish) {
                    // 诊毕后关闭已诊毕患者病历页，回到空白工作台（自动弹出候诊列表）
                    // 无参 /doctor/emr 渲染空白工作台，自动弹候诊面板
                    setTimeout(function () { window.location.href = '/doctor/emr'; }, 700);
                }
            },
        });
    }

    /**
     * 打开病历模板选择（新模板库：结构化内容；列表仅元数据，选中后拉取内容）
     */
    function openTemplates(ev) {
        openTemplatePicker(ev);
    }

    /**
     * 病历模板选择悬浮框（「病历节点 +」首诊场景 / 空白病历自动唤起）：
     * 搜索栏 + 短列表，锚定在右侧「病历节点 +」按钮下方；
     * 选中模板后按其内容创建首张电子病历（套用到编辑器）。
     */
    var tplPickEl = null;
    function openTemplatePicker(ev) {
        closeTemplatePicker();
        // 先显示一个加载中的浮层（避免因请求延迟让用户感觉「没弹出」）
        var pop = document.createElement('div');
        pop.id = 'tplPick';
        pop.className = 'tree-box';
        pop.style.cssText = 'position:fixed;z-index:2600;width:340px;max-width:calc(100vw-16px);';
        pop.innerHTML = '<div class="fs-12 text-muted" style="padding:12px;text-align:center">加载模板…</div>';
        document.body.appendChild(pop);
        // 定位：手动点击入口（「病历节点 +」或占位区「选择病历模板」按钮，ev 有鼠标坐标）→ 跟随鼠标；
        // 自动弹出（无 ev）→ 锚定右侧「病历节点 +」按钮下方，queueBtn 兜底
        var W = 340, H = 380;
        if (ev && ev.clientX != null) {
            pop.style.left = Math.max(8, Math.min(ev.clientX + 12, window.innerWidth - W - 8)) + 'px';
            pop.style.top = Math.max(8, Math.min(ev.clientY + 12, window.innerHeight - H - 8)) + 'px';
        } else {
            var anchor = document.querySelector('.ena-add[title="添加病历"]') ||
                document.querySelector('.ena-add') || document.getElementById('queueBtn');
            if (anchor) {
                var r = anchor.getBoundingClientRect();
                pop.style.top = Math.max(8, r.bottom + window.scrollY + 6) + 'px';
                pop.style.left = Math.max(8, Math.min(r.left + window.scrollX, window.innerWidth - W - 8)) + 'px';
            } else {
                pop.style.top = '80px'; pop.style.left = '8px';
            }
        }
        // 点击外部 / Esc 关闭
        var outside = function (e) { var el = document.getElementById('tplPick'); if (el && !el.contains(e.target)) closeTemplatePicker(); };
        var esc = function (e) { if (e.key === 'Escape') closeTemplatePicker(); };
        pop.__handlers = [outside, esc];
        setTimeout(function () { document.addEventListener('mousedown', outside, true); document.addEventListener('keydown', esc, true); }, 0);
        // 拉取模板列表
        Clinic.get('/api/template?action=list&type=medical_record', null, {
            onSuccess: function (j) {
                var list = j.data.list || [];
                var scopeW = { hospital: 0, dept: 1, personal: 2 };
                var order = list.slice().sort(function (a, b) {
                    // 待审核模板按「个人」权重参与排序（审核通过前仅创建者本人可用）
                    var sa = a.status === 'pending_review' ? 'personal' : a.scope;
                    var sb = b.status === 'pending_review' ? 'personal' : b.scope;
                    var wa = scopeW[sa] != null ? scopeW[sa] : 9;
                    var wb = scopeW[sb] != null ? scopeW[sb] : 9;
                    if (wa !== wb) return wa - wb;
                    return (b.updated_at || '').localeCompare(a.updated_at || '');
                });
                var scopeNames = { hospital: '全院', dept: '科室', personal: '个人' };
                function renderItems(items) {
                    var box = document.getElementById('tplPickList');
                    if (!box) return;
                    box.innerHTML = items.length ? items.map(function (t) {
                        // 待审核模板审核通过前仅创建者本人可用，范围显示为「个人」；
                        // 审核通过后显示实际范围（全院/科室）；不展示适用科室明细
                        var effScope = t.status === 'pending_review' ? 'personal' : t.scope;
                        return '<div class="tree-search-item" style="display:flex;justify-content:space-between;align-items:center" data-id="' + t.id + '">' +
                            '<span>' + escHtml(t.title) + '</span>' +
                            '<span class="badge ' + (effScope === 'hospital' ? 'badge-primary' : (effScope === 'dept' ? 'badge-warning' : 'badge-gray')) + '" style="font-size:11px;flex-shrink:0">' +
                            (scopeNames[effScope] || t.scope) + '</span></div>';
                    }).join('') : '<div class="fs-12 text-muted" style="padding:8px 10px">暂无可用的病历模板，可前往「模板管理」创建</div>';
                    box.querySelectorAll('.tree-search-item').forEach(function (it) {
                        it.addEventListener('click', function () {
                            closeTemplatePicker();
                            applyTemplateById(parseInt(it.getAttribute('data-id'), 10));
                        });
                    });
                }
                var pop2 = document.getElementById('tplPick');
                if (pop2) {
                    pop2.innerHTML =
                        '<input class="input tree-box-search" id="tplPickKw" placeholder="🔍 搜索病历模板" autocomplete="off">' +
                        '<div class="send-tree" id="tplPickList" style="max-height:320px"></div>';
                    renderItems(order);
                    var kw = document.getElementById('tplPickKw');
                    if (kw) {
                        kw.addEventListener('input', function () {
                            var q = this.value.trim().toLowerCase();
                            renderItems(q ? order.filter(function (t) { return t.title.toLowerCase().indexOf(q) !== -1; }) : order);
                        });
                        kw.focus();
                    }
                }
            },
            onError: function () {
                var pop3 = document.getElementById('tplPick');
                if (pop3) pop3.innerHTML = '<div class="fs-12 text-muted" style="padding:12px;text-align:center">加载模板失败，请重试或前往「模板管理」创建</div>';
            },
        });
    }

    function closeTemplatePicker() {
        var pop = document.getElementById('tplPick');
        if (pop) {
            if (pop.__handlers) {
                document.removeEventListener('mousedown', pop.__handlers[0], true);
                document.removeEventListener('keydown', pop.__handlers[1], true);
            }
            pop.remove();
        }
    }

    /**
     * 按 ID 应用模板（拉取模板内容后套用，for_apply 允许读取系统通用模板）
     */
    function applyTemplateById(tplId) {
        Clinic.get('/api/template?action=get&id=' + tplId + '&for_apply=1', null, {
            onSuccess: function (j) {
                var t = j.data.template;
                if (t && t.content) {
                    applyTemplate(t.content);
                    closeTemplatePicker();
                    Clinic.toast.success('已应用模板，可在此基础上修改并保存');
                }
            },
        });
    }

    /**
     * 应用模板内容/前序病历到编辑器（兼容新旧两种格式）：
     * - 新模板（emr_templates）：结构化 emr（chief_complaint: {symptom:...}）
     * - 旧前序病历（prev_records）：扁平文本（chief_complaint: 'xxx'）
     * 与病历数据深合并，套用视为实质性变更（置脏标记）。
     */
    function applyTemplate(c) {
        if (!c || typeof c !== 'object') c = {};
        // 空病历占位态（首张电子病历未创建）：先渲染首诊编辑器，再套用模板
        var docBody = document.getElementById('docBody');
        var placeholder = docBody ? docBody.querySelector('.ro-placeholder') : null;
        if (placeholder) {
            var d2 = DATA;
            var r2 = d2.record;
            docBody.innerHTML = '';
            try {
                Clinic.emrEditor.render(docBody, r2.emr || {}, {
                    readonly: false,
                    beforeVitals: buildVitalSec(false, d2.vitals || {}),
                    midNode: buildConsciousNode(false, r2.consciousness || '清醒'),
                    mode: 'initial',
                    onChange: function () { EMR_DIRTY = true; },
                });
            } catch (e) { console.error('模板应用前编辑器渲染失败', e); }
            // 右侧病历节点显示「首诊编辑中…（未保存）」占位
            DATA.__pending_initial = true;
            renderLeftNav();
        }
        var cur = Clinic.emrEditor.collect();
        // 扁平转结构化（旧前序病历格式 → 编辑器字段路径）
        var flatKeyMap = {
            chief_complaint: 'chief_complaint.symptom',
            present_illness: 'history_present.content',
            past_history: 'past_history.detail',
            allergy_history: 'allergies.detail',
        };
        Object.keys(c).forEach(function (k) {
            var val = c[k];
            if (typeof val === 'string' && flatKeyMap[k]) {
                // 扁平文本 → 按映射路径写入
                var path = flatKeyMap[k].split('.');
                if (path.length === 2) {
                    if (!cur[path[0]]) cur[path[0]] = {};
                    cur[path[0]][path[1]] = val;
                }
                if (k === 'past_history' && val) {
                    cur.past_history = cur.past_history || {};
                    cur.past_history.type = '承认';
                }
                if (k === 'allergy_history' && val) {
                    cur.allergies = cur.allergies || {};
                    cur.allergies.type = '承认';
                }
            } else if (val && typeof val === 'object') {
                cur[k] = Object.assign(cur[k] || {}, val);
            } else {
                cur[k] = val;
            }
        });
        Clinic.emrEditor.set(cur);
        Clinic.emrEditor.markDirty();
    }

    /**
     * 打开转科弹窗（复用通用科室选择组件 transfer 模式：
     * 服务端已排除当前科室，弹窗内不显示挂号相关信息）
     */
    function openTransfer() {
        var visitId = document.getElementById('visitId').value;
        var curDept = DATA ? DATA.visit.current_dept_id : 0;
        Clinic.deptPicker.open({
            mode: 'transfer',
            fetchUrl: '/api/transfer?action=targets&dept_id=' + curDept,
            currentId: curDept,
            onSelect: function (d) {
                Clinic.modal.confirm(
                    '确定将患者转往【' + d.name + '】吗？转科后就诊序号、首次挂号科室等信息均保持不变。',
                    function () {
                        Clinic.ajax('/api/transfer', {
                            action: 'do', visit_id: visitId, target_dept: d.id,
                        }, {
                            onSuccess: function (j) {
                                Clinic.toast.success(j.msg);
                                setTimeout(function () { location.href = '/doctor/dashboard'; }, 900);
                            },
                        });
                    },
                    { title: '确认转科', okText: '确认转科' }
                );
            },
        });
    }

    /**
     * 查看已开具的诊断证明（弹出打印预览，可再次打印）
     */
    function viewCertificate() {
        var visitId = document.getElementById('visitId').value;
        Clinic.print.load('/api/print?action=certificate&visit_id=' + visitId, null);
    }

    /**
     * 诊断证明弹窗（开具/补开/查看共用同一套代码，方便维护）
     * ——区别仅是模态框标题与入参就诊 ID：
     * · 开具：visitId = 当前编辑页就诊（本次就诊的病历）
     * · 补开：visitId = 就诊历史中的目标就诊（那一次的病历）
     * 三种形态：
     * · 未开具 → 可编辑：病历概要 + 医生建议输入 +「开具并打印」
     * · 已开具 → 只读：概要含证明号/开具时间，医生建议只读，
     *   按钮显示为「打印」——打印内容始终由服务器 certificate_print
     *   从数据库重新渲染（前端只读区域仅作展示，改不了真实数据）。
     * @param bool warnOnIssued 已开具时是否弹「已开具过」提醒——仅用于
     *        「仍触发开具 / 补开动作」的场景；单纯点击查看不提示。
     */
    function certificateModal(visitId, title, onIssued, warnOnIssued) {
        Clinic.get('/api/record?action=get&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var r = j.data.record || {};
                var issued = !!j.data.has_certificate;
                var cert = j.data.certificate || {};
                var text = function (html) {
                    var t = document.createElement('div');
                    t.innerHTML = html || '';
                    return t.textContent.trim();
                };
                var esc = function (s) {
                    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                };
                var cc, pi, diag;
                // 病历摘要取值优先级：证书快照（已开具，固化不变）→
                // cert_summary（未开具，与开具时写入的快照同源，所见即所冻）
                // → 实时投影回退（历史证明兼容）。诊断证明为法律文书，
                // 一经出具内容永不随后续续写漂移。
                var cs = j.data.certificate || {};
                var cs2 = j.data.cert_summary || {};
                var pick = function (a, b, c) { return (a && String(a).trim()) || (b && String(b).trim()) || c || ''; };
                cc = text(pick(cs.chief_complaint, cs2.chief_complaint, r.chief_complaint));
                pi = text(pick(cs.present_illness, cs2.present_illness, r.present_illness));
                diag = pick(cs.initial_diagnosis, cs2.initial_diagnosis, r.initial_diagnosis);

                // 病历概要区（两种形态共用；已开具时附证明号与开具时间）。
                // 行间距统一规则：首行无边距，其余每行 mt-4——
                // 修复「开具时间与主诉贴在一起」的缺失行距问题。
                var rows = [];
                if (issued) {
                    rows.push('<div><strong>证明号：</strong>' + esc(cert.cert_no || '') + '</div>');
                    rows.push('<div class="mt-4"><strong>开具时间：</strong>' + esc(cert.created_at || '') + '</div>');
                }
                rows.push('<div' + (rows.length ? ' class="mt-4"' : '') + '><strong>主诉：</strong>' + esc(cc) + '</div>');
                rows.push('<div class="mt-4"><strong>现病史：</strong>' + esc(pi) + '</div>');
                rows.push('<div class="mt-4"><strong>初步诊断：</strong>' + esc(diag) + '</div>');
                var summary =
                    '<div class="fs-13 mb-8" style="border:1px solid var(--border);border-radius:8px;padding:10px">' +
                    rows.join('') +
                    '</div>';

                /* ---- 已开具：查看 + 打印（打印取服务器存档数据） ---- */
                if (issued) {
                    // 仅「已开具仍触发开具 / 补开动作」时提醒重复；
                    // 单纯查看已开具证明（右栏条目点击）不打扰
                    if (warnOnIssued) Clinic.toast.warning('该次就诊已开具过诊断证明');
                    Clinic.modal.open(
                        summary +
                        '<div class="form-group"><label class="form-label">医生建议</label>' +
                        // 纯展示只读框：灰底、禁用、去掉右下角拖拽手柄、不显示文本光标
                        '<textarea class="textarea" rows="3" disabled ' +
                        'style="background:var(--bg);cursor:default;resize:none;">' +
                        esc(cert.content || '') + '</textarea></div>',
                        {
                            title: title,
                            size: 'modal-sm',
                            buttons: [
                                { text: '关闭', cls: 'btn-outline' },
                                {
                                    // 打印走 certificate_print：由服务器重新渲染存档数据
                                    text: '🖨️ 打印', cls: 'btn-success',
                                    onClick: function () {
                                        Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                    },
                                },
                            ],
                        }
                    );
                    return;
                }

                /* ---- 未开具：可编辑开具 ---- */
                if (!cc || !pi || !diag) {
                    Clinic.toast.warning('该次就诊病历不完整（缺少主诉/现病史/初步诊断），无法开具诊断证明');
                    return;
                }
                Clinic.modal.open(
                    '<div class="fs-13 text-muted mb-8">将自动引用该次就诊病历，医生建议请手动填写：</div>' +
                    summary +
                    '<div class="form-group"><label class="form-label">医生建议</label>' +
                    '<textarea class="textarea" id="certContent" rows="3" placeholder="如：建议休息3天，清淡饮食，不适随诊"></textarea></div>',
                    {
                        title: title,
                        size: 'modal-sm',
                        buttons: [
                            { text: '取消', cls: 'btn-outline' },
                            {
                                text: '开具并打印', cls: 'btn-success', autoClose: false,
                                onClick: function () {
                                    var content = document.getElementById('certContent').value.trim();
                                    if (!content) { Clinic.toast.warning('请填写医生建议'); return; }
                                    Clinic.ajax('/api/record', {
                                        action: 'certificate', visit_id: visitId, content: content,
                                    }, {
                                        onSuccess: function () {
                                            Clinic.toast.success('诊断证明已开具');
                                            Clinic.modal.close();
                                            Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
                                            if (typeof onIssued === 'function') onIssued();
                                        },
                                    });
                                },
                            },
                        ],
                    }
                );
            },
        });
    }

    /**
     * 开具诊断证明（本次就诊，单次就诊仅一次）
     * 与「补开诊断证明」共用 certificateModal，仅标题不同；
     * visitId 固定取当前编辑页的就诊 ID——引用的是本次就诊病历。
     * 完整性判断与打印病历按钮完全一致（isRecordComplete）：
     * 已诊毕直接放行；未诊毕须本人文书已完善并保存，否则提示先完善病历。
     */
    function openCertificate() {
        // 病历有修改未保存：先保存再开具（证明快照取自已保存内容）
        if (!requireSaved('开具诊断证明')) return;
        var visitId = document.getElementById('visitId').value;
        // 与打印病历按钮同一套判断逻辑与提示语（仅场景词不同）
        if (!isRecordComplete()) {
            Clinic.toast.warning('请先在病历中完善主诉、现病史与初步诊断并保存，再开具诊断证明');
            return;
        }
        // warnOnIssued=true：已开具仍触发开具动作（正常已被「＋」隐藏拦截，
        // 此处为特殊手段强制打开的兜底提醒）
        certificateModal(visitId, '开具诊断证明', null, true);
    }

    /**
     * 病历是否可打印（明确规则）：
     * 1. 已诊毕（visit.status === 'finished'）→ 直接可打印。
     *    诊毕必然经过保存，不存在"未保存就打印"的问题；且诊毕病历为只读展示，
     *    打印渲染的是该就诊全部已保存文书。
     * 2. 未诊毕 + 当前医生尚无本人文书（record_id=0，新接诊未写）→ 不可打印，
     *    提示先完善主诉/现病史/初步诊断并保存（续写医生需完善自己的续写文书）。
     *    不回退判定他人文书——他人病历与本医生的续写文书互相独立。
     * 3. 未诊毕 + 本人有文书 → 按本人文书完整性判定：
     *    首诊 = 主诉 + 现病史 + 初步诊断；续写 = 病历续写内容 + 初步诊断。
     * 就诊历史 / 患者列表入口的打印不经本函数（走后端 print.php 校验：
     * 该就诊存在已保存病历即可渲染），与上述编辑页规则互不影响。
     */
    function isRecordComplete() {
        if (!DATA || !DATA.record) return false;
        // 已诊毕：病历必然已保存过，直接可打印
        if (DATA.visit && DATA.visit.status === 'finished') return true;
        var e = DATA.record.emr;
        if (!e) return false;
        // 未诊毕：本人尚无文书（新接诊未保存）→ 需先完善并保存
        if (!(DATA.record.record_id || 0)) return false;
        // 续写文书：病历续写内容 + 诊断（主诉/现病史归首诊文书，不参与判定）
        if (DATA.record.record_type === 'progress') {
            var pc = ((e.progress || {}).content || '').trim();
            return !!(pc && (e.diagnoses || []).length);
        }
        // 首诊文书：主诉症状 + 现病史内容 + 初步诊断
        var cc = ((e.chief_complaint || {}).symptom || '').trim();
        var pi = ((e.history_present || {}).content || '').trim();
        return !!(cc && pi && (e.diagnoses || []).length);
    }

    /**
     * 打印电子病历
     */
    /** 病历有未保存修改时拦截并提示（开单 / 打印 / 开诊断证明前调用） */
    function requireSaved(label) {
        if (EMR_DIRTY) {
            Clinic.toast.warning('病历有修改未保存，请先点击「💾 保存」后再' + label);
            return false;
        }
        return true;
    }

    function printRecord() {
        // 前置条件：病历已完善并保存（有未保存修改先拦截）
        if (!requireSaved('打印病历')) return;
        if (!isRecordComplete()) {
            Clinic.toast.warning('请先在病历中完善主诉、现病史与初步诊断并保存，再打印病历');
            return;
        }
        var visitId = document.getElementById('visitId').value;
        // 直接使用统一打印模板（print.php?action=record），与屏幕所见即所得病历版式一致；
        // A5 病历纸（竖版窄条，宽度受限、可向下延伸）
        Clinic.print.load('/api/print?action=record&visit_id=' + visitId, null, 'a5');
    }

    /** 编辑中占位点击时定位到编辑器（首诊/续写编辑中节点） */
    function scrollToPendingEditor(el) {
        scrollToEditor(0);
    }

    /** 删除未保存的编辑中病历（首诊/续写编辑中节点）：
     *  无需校验脏数据/必填项；但已添加诊断（避免无主诊断）或已开单则禁止删除 */
    function cancelPendingRecord() {
        if (!DATA) return;
        var curDiags = (DATA.record && DATA.record.emr && DATA.record.emr.diagnoses) || [];
        if (curDiags.length) {
            Clinic.toast.warning('当前病历已添加诊断，不可删除；请先删除诊断后再取消编辑');
            return;
        }
        if (ORDERS && ORDERS.length) {
            Clinic.toast.warning('当前病历已开单，不可删除');
            return;
        }
        Clinic.modal.confirm('确定删除该未完成的病历？未保存的内容将丢失。', function () {
            var wasInitial = !!DATA.__pending_initial;
            var docId = DATA.record ? (DATA.record.doctor_id || 0) : 0;
            var docName = DATA.record ? (DATA.record.doctor_name || '') : '';
            var docEmp = DATA.record ? (DATA.record.doctor_emp || '') : '';
            var docTitle = DATA.record ? (DATA.record.doctor_title || '') : '';
            // 清除编辑中标记
            DATA.__pending_initial = false;
            DATA.__pending_progress = false;
            DATA.__progress_new = false;
            DATA.__edit_record_id = 0;
            EMR_DIRTY = false;
            // 重置为占位态：首诊→空病历占位；续写→只读占位
            DATA.record = {
                record_id: 0, id: 0,
                doctor_id: docId, doctor_name: docName,
                doctor_emp: docEmp, doctor_title: docTitle,
                record_type: wasInitial ? 'initial' : 'progress',
                emr: {}, consciousness: '', vitals: {},
                created_at: '', updated_at: '',
            };
            renderEmrCard(DATA);
            renderLeftNav();
        }, { title: '删除未完成病历', okText: '确认删除' });
    }

    return {
        init: init,
        save: save,
        confirmFinish: confirmFinish,
        openDiagPop: openDiagPop,
        openDiagEditPop: openDiagEditPop,
        openDiagOpsPop: openDiagOpsPop,
        delDiag: delDiag,
        openTemplates: openTemplates,
        applyTemplateById: applyTemplateById,
        openTransfer: openTransfer,
        openCertificate: openCertificate,
        certificateModal: certificateModal,
        viewCertificate: viewCertificate,
        openVitals: openVitals,
        printRecord: printRecord,
        isRecordComplete: isRecordComplete,
        /** 返回当前病历是否有未保存的修改（候诊切换患者时拦截跳转用） */
        isDirty: function () { return EMR_DIRTY; },
        /** 删除病历记录（节点生命周期约束：仅本人/首诊锁定/续写独立删除） */
        deleteRecord: deleteRecord,
        loadOrders: loadOrders,
        isMyOrder: isMyOrder,
        scrollToPendingEditor: scrollToPendingEditor,
        cancelPendingRecord: cancelPendingRecord,
    };
})();

/* 页面就绪后初始化 */
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('visitId')) {
        Clinic.emr.init();
    }
});

/* 全局：转科 */
function openTransfer() {
    Clinic.emr.openTransfer();
}

/* ============================================================
 * 就诊历史入口（patient.php history 弹窗内按钮调用）
 * openHistoryCertificate：未开具时补开——与开具共用
 * Clinic.emr.certificateModal，仅标题不同；visitId 为就诊历史中
 * 目标那一次就诊的 ID，引用的是该次就诊的病历内容。
 * printHistoryCertificate：已开具时查看/再次打印
 * ============================================================ */
function openHistoryCertificate(visitId) {
    // warnOnIssued=true：补开动作遇到已开具的历史就诊 → 提醒重复
    Clinic.emr.certificateModal(visitId, '补开诊断证明', null, true);
}

/* 全局：归档病历补开诊断证明确认（就诊历史「补开」按钮调用）
 * 提示语区分是否接诊过该患者，确认后打开补开表单 */
function archiveCertificateConfirm(treated, visitId) {
    var msg = '该病历已经归档' +
        (treated ? '' : '，且您未接诊过该病人') +
        (treated ? '，是否补开诊断证明？' : '，是否确认为该患者开具诊断证明？');
    Clinic.modal.confirm(msg, function () {
        Clinic.emr.certificateModal(visitId, '补开诊断证明', null, true);
    });
}

/* 查看已开具的诊断证明（弹窗打印预览，可再次打印） */
function printHistoryCertificate(visitId) {
    Clinic.print.load('/api/record?action=certificate_print&visit_id=' + visitId, null, 'a5');
}

/* 全局：开单详情弹窗内 删除 / 毁方（处方） */
function delOrderFlow(orderId, label) {
    var isRx = label === '毁方';
    Clinic.modal.confirm(isRx
        ? '确定毁方该处方？'
        : '确定删除该开单？', function () {
        Clinic.ajax('/api/order', { action: 'delete', order_id: orderId }, {
            onSuccess: function (j) {
                // 同步关闭所在详情弹窗（侧边栏行内调用时栈空，close 为安全空操作）
                Clinic.modal.close();
                Clinic.toast.success(j.msg);
                Clinic.emr.loadOrders(document.getElementById('visitId').value);
            },
        });
    });
}

/* 全局：删除开单 */
function delOrder(orderId) {
    Clinic.modal.confirm('确定删除该开单？', function () {
        Clinic.ajax('/api/order', { action: 'delete', order_id: orderId }, {
            onSuccess: function (j) {
                Clinic.modal.close();
                Clinic.toast.success(j.msg);
                Clinic.emr.loadOrders(document.getElementById('visitId').value);
            },
        });
    });
}

/* 全局：查看开单流程（纵向流程图） */
function viewOrderFlow(orderId) {
    Clinic.get('/api/order?action=visit_orders&visit_id=' + document.getElementById('visitId').value, null, {
        onSuccess: function (j) {
            var o = j.data.list.find(function (x) { return x.id === orderId; });
            if (!o) return;
            var typeNames = { lab: '检验', imaging: '检查', procedure: '处置', prescription: '处方' };
            var isLabImg = o.order_type === 'lab' || o.order_type === 'imaging';
            var steps;
            if (isLabImg) {
                // 检验/检查流程：登记 → 报告完成（报告情况单独成步）
                steps = [
                    { k: 'open', label: '开单' },
                    { k: 'paid', label: '缴费' },
                    { k: 'registered', label: '登记' },
                    { k: 'done', label: '报告完成' },
                ];
            } else {
                steps = [
                    { k: 'open', label: '开单' },
                    { k: 'paid', label: '缴费' },
                    { k: 'registered', label: '登记' },
                    { k: 'done', label: o.order_type === 'prescription' ? '药房发药' : '处置执行' },
                ];
            }
            // 处方流程：paid→dispensing→dispensed
            if (o.order_type === 'prescription') {
                steps = [
                    { k: 'open', label: '开单' },
                    { k: 'paid', label: '缴费' },
                    { k: 'dispensing', label: '药房处理' },
                    { k: 'dispensed', label: '发药完成' },
                ];
            }
            // 流程状态判定
            var curIdx = 0;
            if (o.status === 'done' || o.status === 'dispensed') curIdx = 3;
            else if (o.status === 'registered' || o.status === 'dispensing' || o.status === 'in_progress') curIdx = 2;
            else if (o.status === 'paid') curIdx = 1;
            else if (o.status === 'refunded' || o.status === 'cancelled') curIdx = -1;

            var flow = steps.map(function (s, i) {
                var cls = (curIdx >= 0 && i <= curIdx) ? 'var(--success)' : 'var(--border)';
                return '<div class="flex gap-8" style="align-items:center">' +
                    '<div style="width:26px;height:26px;border-radius:50%;background:' + cls + ';' +
                    'display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex-shrink:0">' +
                    (i + 1) + '</div>' +
                    '<div class="fs-13" style="color:' + (curIdx >= 0 && i <= curIdx ? 'var(--text)' : 'var(--text-muted)') + '">' +
                    s.label + '</div></div>';
            }).join('<div style="width:2px;height:18px;background:var(--border);margin-left:12px"></div>');

            // 处方单走组医嘱树形公共格式；检验/检查明细带执行状态与「查看报告」；
            // 其余类型保持「· 名称 ×数量」
            var items;
            if (o.order_type === 'prescription') {
                items = Clinic.orderRxLines(o.items).map(function (l) {
                    return '<div class="fs-13" style="padding:3px 0;white-space:pre-wrap">' + l + '</div>';
                }).join('');
            } else if (isLabImg) {
                // 检验/检查：逐项显示登记/报告状态，已出报告可直接查看报告单
                var stMap = {
                    open: '<span class="badge badge-warning">待登记</span>',
                    registered: '<span class="badge badge-primary">已登记</span>',
                    done: '<span class="badge badge-success">已出报告</span>',
                };
                items = o.items.map(function (it) {
                    var st = stMap[it.status] || ('<span class="badge badge-gray">' + (it.status || '—') + '</span>');
                    var rpt = it.report_id
                        ? ' <button type="button" class="btn btn-outline btn-sm" style="padding:0 8px;margin-left:8px" ' +
                          'onclick="Clinic.print.load(\'/api/print?action=report&report_id=' + it.report_id + '\')">📄 查看报告</button>'
                        : '';
                    return '<div class="flex-between fs-13" style="padding:3px 0">' +
                        '<span>· ' + it.item_name + (it.quantity > 1 ? ' ×' + it.quantity : '') + '</span>' +
                        '<span>' + st + rpt + '</span></div>';
                }).join('');
            } else {
                items = o.items.map(function (it) {
                    return '<div class="fs-13" style="padding:3px 0">· ' + it.item_name +
                        (it.quantity > 1 ? ' ×' + it.quantity : '') + '</div>';
                }).join('');
            }

            var catTitle = (o.order_type === 'imaging' && o.cat_name && o.cat_name !== '检查') ? o.cat_name : (typeNames[o.order_type] || '');
            var printBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px" ' +
                'onclick="Clinic.print.load(\'/api/print?action=order&order_id=' + o.id + '\',null,\'a5\')">🖨️ 打印' +
                catTitle + '单</button>';

            // 删除 / 毁方按钮：处方称「毁方」，其余称「删除」；
            // 非开单医生本人【直接隐藏】（避免误解，后端 delete 亦有硬拦截）；
            // 本人单子仅未缴费（open）或已退费（refunded）显示，
            // 已进入执行流程的点击提示到收费处退费。
            // 注意：本函数为全局函数，不可直接调用模块私有的 myDoctorId()，
            // 必须经由公开 API Clinic.emr.isMyOrder 判断（否则运行时
            // ReferenceError 会被 ajax catch 吞掉并误报「网络请求失败」）
            var delLabel = o.order_type === 'prescription' ? '毁方' : '删除';
            var delBtn;
            if (!Clinic.emr.isMyOrder(o)) {
                delBtn = '';
            } else if (o.status === 'open' || o.status === 'refunded') {
                delBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px;margin-left:8px" ' +
                    'onclick="delOrderFlow(\'' + o.id + '\',\'' + delLabel + '\')">🗑️ ' + delLabel + '</button>';
            } else {
                delBtn = '<button class="btn btn-outline btn-sm" style="margin-top:8px;margin-left:8px" ' +
                    'onclick="Clinic.toast.warning(\'' + delLabel + '仅限未缴费或已退费的开单，已进入执行流程的项目如需撤销请到收费处办理退费\')">🗑️ ' + delLabel + '</button>';
            }

            Clinic.modal.open(
                '<div class="flex gap-16">' +
                '  <div style="flex:1">' +
                '    <div class="fw-600 mb-8">' + catTitle + '：' + o.order_no + '</div>' +
                '    ' + items +
                '    <div class="fs-13 text-muted mt-8">金额：¥' + parseFloat(o.total_amount).toFixed(2) + '</div>' +
                '    <div class="fs-13 text-muted">开单医生：' + (o.doctor_name || '—') + ' ｜ ' + o.created_at + '</div>' +
                (o.done_by ? '<div class="fs-13 text-success mt-4">执行人：' + o.done_by + '</div>' : '') +
                printBtn + delBtn +
                '  </div>' +
                '  <div style="width:160px;border-left:1px solid var(--border);padding-left:16px">' +
                '    <div class="fw-600 mb-8 fs-13">流程进度</div>' + flow + '</div>' +
                '</div>',
                { title: '开单详情', size: 'modal-lg' }
            );
        },
    });
}


