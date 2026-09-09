/**
 * ============================================================
 * emr_consult.js — 科室间会诊（发起 / 列表 / 详情 / 进入会诊模式）
 * ============================================================
 * 说明：自 emr.js 拆出的会诊模块——发起会诊（两步：选科室→会诊单）、
 * 右侧会诊列表、会诊详情（含进度）、接受/开始/完毕、进入会诊模式。
 * 经 Clinic.emr._ctx 读写共享状态与内部函数（DATA/CONSULTS/renderLeftNav/
 * loadOrders/flowColumnHtml/renderEmrCard/hasEditableRecord 等）。
 * 依赖：Clinic.get / Clinic.ajax / Clinic.modal / Clinic.toast /
 * Clinic.print / Clinic.nav / Clinic.deptPicker / Clinic.escHtml。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.consult = (function () {
    var ctx = Clinic.emr._ctx;
    var escHtml = ctx.escHtml;
    var renderLeftNav = ctx.renderLeftNav;

    var CONSULT_DEPT = 0;   // 已选择的会诊目标科室（deptPicker 回调写入）

    /** 发起会诊：第一步选择会诊科室（隐藏当前科室） */
    function openConsultCreate(ev) {
        // 会诊期间拦截：不可再发起新会诊
        if (ctx.DATA && ctx.DATA.__consult_mode) {
            Clinic.toast.warning('会诊期间不可再发起会诊');
            return;
        }
        if (window.Clinic && Clinic.emr.isDirty && Clinic.emr.isDirty()) {
            Clinic.toast.warning('当前病历有未保存的修改，请先点击「💾 保存」后再发起会诊');
            return;
        }
        // 可编辑病历校验：无当前上下文可编辑病历（会诊处理中无会诊病历 /
        // 转科前文书只读 / 未书写保存）→ 禁止发起会诊
        if (!ctx.hasEditableRecord()) {
            Clinic.toast.warning('当前无可编辑的病历（请先在本科室书写并保存首诊/续写病历，或创建保存会诊病历）后再发起会诊');
            return;
        }
        var visitId = document.getElementById('visitId').value;
        var curDept = ctx.DATA ? ctx.DATA.visit.current_dept_id : 0;
        CONSULT_DEPT = 0;
        Clinic.deptPicker.open({
            mode: 'transfer',
            title: '发起会诊 · 选择会诊科室',
            fetchUrl: '/api/transfer?action=targets&dept_id=' + curDept,
            currentId: curDept,
            // 会诊默认进入就诊当前科室所在类型 Tab（当前科室已被服务端排除）
            currentType: (ctx.DATA && ctx.DATA.visit && ctx.DATA.visit.dept_type) || '',
            onSelect: function (d) {
                // 重复会诊拦截（与后端 create 同规则）：本就诊已发往该科室且未完毕
                // 的进行中会诊 → 点击科室即提醒并中止；会诊完毕（done）后可再次发起
                var dup = (ctx.DATA.consults || []).some(function (cc) {
                    return (cc.target_dept_id || 0) === d.id && (cc.status === 'pending' || cc.status === 'doing');
                });
                if (dup) {
                    Clinic.toast.warning('该就诊已有发往【' + d.name + '】的进行中会诊，请等待会诊处理完毕后再发起');
                    return;
                }
                CONSULT_DEPT = d.id;
                openConsultForm(d);
            },
        });
    }

    /** 发起会诊：第二步会诊单（只读显示 主诉/现病史/查体/诊断 + 会诊描述/目的） */
    function openConsultForm(dept) {
        var visitId = document.getElementById('visitId').value;
        Clinic.get('/api/consultation?action=snapshot&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var s = j.data.snapshot || {};
                var ro = function (label, val) {
                    return '<div class="prev-sec" style="font-size:13px;line-height:1.9"><strong>' +
                        escHtml(label) + '：</strong>' + (val && String(val).trim() ? escHtml(val) : '-') + '</div>';
                };
                Clinic.modal.open(
                    '<div style="background:var(--bg-soft);border-radius:10px;padding:12px;margin-bottom:12px">' +
                    '<div class="fs-12 text-muted mb-4">病历快照（只读）</div>' +
                    ro('主诉', s.chief_complaint) +
                    ro('现病史', s.present_illness) +
                    ro('查体', s.physical_exam) +
                    ro('初步诊断', s.diagnoses) +
                    '</div>' +
                    '<div class="fs-12 text-muted mb-4">会诊科室：<b style="color:var(--primary)">' + escHtml(dept.name) + '</b></div>' +
                    '<div class="form-group"><label class="form-label">会诊描述</label>' +
                    '<textarea class="textarea" id="consDesc" rows="3" placeholder="病情摘要 / 邀请会诊说明…"></textarea></div>' +
                    '<div class="form-group"><label class="form-label">会诊目的</label>' +
                    '<textarea class="textarea" id="consPurpose" rows="2" placeholder="如：协助明确诊断 / 指导下一步治疗方案…"></textarea></div>',
                    {
                        title: '🤝 发起会诊 → ' + dept.name,
                        size: 'modal-md',
                        buttons: [
                            { text: '取消', cls: 'btn-outline' },
                            {
                                text: '发送', cls: 'btn-primary', autoClose: false,
                                onClick: function () {
                                    Clinic.ajax('/api/consultation', {
                                        action: 'create',
                                        visit_id: visitId,
                                        target_dept_id: dept.id,
                                        description: document.getElementById('consDesc').value.trim(),
                                        purpose: document.getElementById('consPurpose').value.trim(),
                                        // 会诊与病历强关联：记录发起时所在的病历（与开单一致）
                                        record_id: (ctx.DATA && ctx.DATA.record) ? (ctx.DATA.record.record_id || 0) : 0,
                                    }, {
                                        onSuccess: function (json) {
                                            Clinic.toast.success(json.msg);
                                            // 即时同步：响应附带完整会诊行，本地插入 DATA.consults
                                            // 并同步 CONSULTS 数据源后立即重渲染右侧列表与病历
                                            // 门诊处置「请X科会诊」——不依赖二次列表请求
                                            var nc = json.data && json.data.consultation;
                                            if (nc && ctx.DATA) {
                                                ctx.DATA.consults = (ctx.DATA.consults || []).concat([nc]);
                                            }
                                            Clinic.modal.close();
                                            renderConsultList();
                                            ctx.loadOrders(visitId);   // 病历门诊处置追加「请X科会诊」
                                            // 自动弹出会诊申请单打印预览（与诊断证明开具后
                                            // 自动打印同模式；code 兜底直接用会诊 id）
                                            if (nc && (nc.code || json.data.id)) {
                                                Clinic.print.load('/api/print?action=consultation&id=' + encodeURIComponent(nc.code || json.data.id), null, 'a5');
                                            }
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

    /** 渲染右侧会诊列表（本人发起，本就诊）：状态小圆点 + 日期 请X科会诊 + 删除（仅本人） */
    function renderConsultList() {
        var el = document.getElementById('navConsult');
        var visitId = document.getElementById('visitId').value;
        if (!visitId) return;
        Clinic.get('/api/consultation?action=visit_consults&visit_id=' + visitId, null, {
            onSuccess: function (j) {
                var list = j.data.list || [];
                // 同步会诊数据源：门诊处置「请X科会诊」据此渲染——
                // 会诊发送/删除后无需刷新页面即可实时更新病历正文
                ctx.CONSULTS = list;
                if (ctx.DATA) ctx.DATA.consults = list;
                if (el) {
                    renderConsultListBox(el, list);
                }
                // 会诊创建/删除后刷新病历正文处置区与左侧大纲
                if (window.Clinic && Clinic.emr.orders) {
                    Clinic.emr.orders.renderDocOrders();
                    renderLeftNav();
                }
            },
        });
    }

    /** 渲染右侧会诊列表内容（从 renderConsultList 抽出，列表刷新时复用） */
    function renderConsultListBox(el, list) {
        var myUid = parseInt(document.body.getAttribute('data-uid') || '0', 10) || 0;
        // 状态小圆点（与费用状态指示灯同款样式）：黄=待处理 绿=进行中 灰=完毕
        var dot = function (st) {
            var cls = st === 'done' ? 'green' : (st === 'doing' ? 'red' : 'gray');
            var txt = st === 'done' ? '会诊完毕' : (st === 'doing' ? '正在会诊' : '待处理');
            return '<span class="status-indicator ' + cls + '" title="' + txt + '"></span>';
        };
        el.innerHTML = list.length ? list.map(function (c) {
            // 删除按钮：仅发起人本人 + 会诊仍为待会诊（pending）时可删除；
            // 已在会诊中（doing/done）一律不显示删除按钮
            var delBtn = (c.from_doctor_id === myUid && c.status === 'pending')
                ? '<span class="ena-del" title="删除会诊" onclick="event.stopPropagation();Clinic.emr.delConsult(\'' + c.code + '\')">🗑️</span>'
                : '';
            return '<div class="ena-item" style="cursor:pointer" title="点击查看会诊详情" onclick="Clinic.emr.openConsultDetail(\'' + c.code + '\')">' +
                dot(c.status) +
                '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                escHtml((c.created_at || '').substring(5, 16)) + ' 请' + escHtml(c.target_dept_name) + '会诊</span>' +
                delBtn + '</div>';
        }).join('') : '<div class="ena-empty">暂无会诊</div>';
    }

    /** 删除会诊（仅发起医生本人，后端硬校验） */
    function delConsult(code) {
        Clinic.modal.confirm('确定删除该会诊？', function () {
            Clinic.ajax('/api/consultation', { action: 'delete', id: code }, {
                onSuccess: function (j) {
                    Clinic.toast.success(j.msg);
                    renderConsultList();
                    ctx.loadOrders(document.getElementById('visitId').value);
                },
            });
        }, { title: '删除会诊', okText: '确认删除' });
    }

    /** 会诊查询模态框：左=会诊单只读（主诉/现病史/体格检查/诊断/描述/目的），
     *  右=会诊进度（发起会诊 → 正在会诊 → 会诊完毕，三状态圆点） */
    function openConsultDetail(id, withAccept) {
        Clinic.get('/api/consultation?action=detail&id=' + encodeURIComponent(id), null, {
            onSuccess: function (j) {
                var c = j.data.consultation || {};
                var s = c.snapshot || {};
                var ro = function (label, val) {
                    return '<div class="prev-sec" style="font-size:13px;line-height:1.9;margin-bottom:4px"><strong>' +
                        escHtml(label) + '：</strong>' + (val && String(val).trim() ? escHtml(val) : '-') + '</div>';
                };
                var steps = [
                    { label: '发起会诊', operator: (c.from_doctor_name || ''), time: (c.created_at || ''), done: true },
                    { label: '正在会诊', operator: (c.status !== 'pending' ? (c.accepted_by || (c.record && c.record.doctor_name) || '') : ''), time: (c.status !== 'pending' ? (c.accepted_at || '') : ''), done: c.status !== 'pending' },
                    { label: '会诊完毕', operator: (c.status === 'done' ? (c.finished_by || c.accepted_by || (c.record && c.record.doctor_name) || '') : ''), time: (c.finished_at || ''), done: c.status === 'done' },
                ];
                var stepHtml = ctx.flowColumnHtml(steps, -1, '会诊进度');
                // 就诊已诊毕：病历强制快照只读，B 科不可再处理该会诊（提示 + 不显示确认会诊）
                var visitFinished = c.visit_status === 'finished';
                var finishedTip = visitFinished
                    ? '<div class="fs-13" style="background:var(--danger-soft, rgba(239,68,68,.08));border:1px solid var(--danger, #ef4444);color:var(--danger, #ef4444);border-radius:8px;padding:10px 12px;margin-bottom:10px">⚠️ 该患者已诊毕，无法进行会诊（诊毕病历已归档锁定）</div>'
                    : '';
                var buttons = [
                    { text: '关闭', cls: 'btn-outline' },
                ];
                // 「查看完整病历」入口：仅当从候诊列表【会诊】tab 进入（withAccept=true）
                // 且会诊已完毕（done）时显示——进入病历页全只读查看（后端跨科室状态驱动只读）。
                // 病历页右侧会诊列表/正文链接（withAccept=false/undefined）打开详情时不显示，
                // 避免已在完整病历内再出现入口。用 withAccept 区分两个点击来源（组件级区分）。
                if (withAccept && c.status === 'done') {
                    buttons.push({
                        text: '📋 查看完整病历', cls: 'btn-primary', autoClose: false,
                        onClick: function () {
                            Clinic.modal.close();
                            Clinic.nav.go('/doctor/emr?visit_id=' + c.visit_code);
                        },
                    });
                }
                // 非确认会诊页（withAccept=false）→ 添加打印会诊单按钮
                // 会诊科室点击确认会诊（withAccept=true）时不显示打印功能
                if (!withAccept && c.consult_no) {
                    buttons.push({
                        text: '🖨️ 打印会诊单', cls: 'btn-outline', autoClose: false,
                        onClick: function () {
                            Clinic.print.load('/api/print?action=consultation&id=' + c.code, null, 'a5');
                        },
                    });
                }
                // 候诊入口（withAccept=true）且待会诊 → 底部「确认会诊」进入病历书写
                // 就诊已诊毕时不显示（后端 accept 亦拦截）
                if (withAccept && c.status === 'pending' && !visitFinished) {
                    buttons.push({
                        text: '✅ 确认会诊', cls: 'btn-primary', autoClose: false,
                        onClick: function () {
                            Clinic.ajax('/api/consultation', { action: 'accept', id: c.code }, {
                                onSuccess: function (j2) {
                                    Clinic.toast.success(j2.msg || '会诊已开始');
                                    Clinic.modal.close();
                                    // 进入病历书写页：URL 携带 consult=code，页面内进入会诊模式
                                    Clinic.nav.go('/doctor/emr?visit_id=' + c.visit_code + '&consult=' + encodeURIComponent(c.code));
                                },
                            });
                        },
                    });
                }
                Clinic.modal.open(
                    finishedTip +
                    '<div class="flex gap-16" style="align-items:stretch">' +
                    '  <div style="flex:1.4;min-width:0;border-right:1px solid var(--border);padding-right:14px">' +
                    '    <div class="fs-13 fw-700 mb-8">' + escHtml(c.from_dept_name || '') + ' 请' + escHtml(c.target_dept_name || '') + '会诊</div>' +
                    '    <div class="fs-12 text-muted mb-8">会诊单号：' + escHtml(c.consult_no || '') + '</div>' +
                    '    <div style="background:var(--bg-soft);border-radius:10px;padding:10px">' +
                    ro('主诉', s.chief_complaint) + ro('现病史', s.present_illness) +
                    ro('体格检查', s.physical_exam) + ro('初步诊断', s.diagnoses) +
                    '    </div>' +
                    ro('会诊描述', c.description) + ro('会诊目的', c.purpose) +
                    '  </div>' +
                    '  <div style="width:190px;flex-shrink:0;padding-left:14px">' +
                    '    ' + stepHtml +
                    '  </div>' +
                    '</div>',
                    { title: '🤝 会诊详情', size: 'modal-lg', buttons: buttons }
                );
            },
        });
    }

    /** 开始会诊：接受会诊（pending→doing）并进入会诊模式。
     *  进入后仅展示只读病历 + 引导提示（不自动新建续写病历），
     *  医生点击右侧「病历节点 ＋」才创建会诊病历编辑器。 */
    function startConsult(consultId) {
        Clinic.ajax('/api/consultation', { action: 'accept', id: consultId }, {
            onSuccess: function () { enterConsultMode(consultId); },
            onError: function () { enterConsultMode(consultId); },
        });
    }

    /** 进入会诊模式：设置会诊状态、应用会诊界面、重新渲染占位（只读+引导） */
    function enterConsultMode(consultId) {
        ctx.DATA.__consult_id = consultId;
        ctx.DATA.__consult_mode = true;
        // 先渲染病历卡（创建按钮骨架），再应用会诊界面调整（诊毕→会诊完毕，隐藏转科）
        ctx.renderEmrCard(ctx.DATA);
        applyConsultMode(consultId);
        renderLeftNav();
    }

    /** 开始创建会诊病历编辑器（点击右侧「病历节点 ＋」后调用） */
    function enterConsultEditor(consultId) {
        // consultId 是 oid 混淆串（URL/__consult_id 均为 code）→ 先还原整数 id 并
        // 标记当前文书为会诊病历（record_write 以整数匹配会诊单，否则绑定恒为 0；
        // 须在编辑器渲染前设置，fillContHead 才能显示「会诊记录」徽标）
        ctx.DATA.record.consultation_id = consultRawId(consultId);
        // 新建会诊病历编辑器（复用续写编辑器链路；consultation_id 随保存关联）
        if (ctx.DATA.record.record_id > 0) ctx.addProgressEditor(); else ctx.createProgressEditor();
        applyConsultMode(consultId);
        // 恢复顶栏写操作按钮（保存 / 会诊完毕），仅隐藏转科
        document.querySelectorAll('.emr-top-actions .emr-write').forEach(function (b) {
            if (b.getAttribute('onclick') && b.getAttribute('onclick').indexOf('openTransfer') !== -1) {
                b.style.display = 'none';
            } else {
                b.style.display = '';
            }
        });
        renderLeftNav();
    }

    /** 会诊模式界面调整：隐藏转科、诊毕改为会诊完毕、侧边栏节点显示会诊编辑中 */
    function applyConsultMode(consultId) {
        ctx.DATA.__consult_id = consultId;
        ctx.DATA.__consult_mode = true;
        // 转科按钮隐藏（会诊病历与当前科室绑定）
        document.querySelectorAll('.emr-top-actions .emr-write').forEach(function (b) {
            if (b.getAttribute('onclick') && b.getAttribute('onclick').indexOf('openTransfer') !== -1) {
                b.style.display = 'none';
            }
        });
        // 诊毕按钮 → 会诊完毕
        document.querySelectorAll('.emr-top-actions .emr-write').forEach(function (b) {
            if (b.getAttribute('onclick') && b.getAttribute('onclick').indexOf('confirmFinish') !== -1) {
                b.innerHTML = '🏁 会诊完毕';
                b.classList.remove('btn-success');
                b.classList.add('btn-warning');
            }
        });
        // 会诊期间：隐藏「发起会诊」+（不可再发起会诊）与「诊断证明」＋（不可开具，可查看）
        var consAdd2 = document.querySelector('.ena-sec-title .ena-add[title="发起会诊"]');
        if (consAdd2) {
            consAdd2.style.display = 'none';
            // + 隐藏后箭头靠右修复
            var arrow = consAdd2.parentNode ? consAdd2.parentNode.querySelector('.ena-arrow') : null;
            if (arrow) arrow.style.marginLeft = 'auto';
        }
        // 诊断证明：保留分区可查看，仅隐藏 + 号
        var certAdd3 = document.getElementById('certAddBtn');
        if (certAdd3) certAdd3.style.display = 'none';
        // 恢复诊断证明分区显示（前次已隐藏则取消隐藏）
        var certSec3 = document.getElementById('certSec');
        if (certSec3) certSec3.style.display = '';
        renderLeftNav();
    }

    /** 会诊标识（oid 混淆串 或 纯数字 id）→ 整数会诊 id。
     *  用于 enterConsultEditor 绑定 consultation_id：DATA.consults 中有
     *  code/id 映射，按 code 优先精确还原；纯数字直接采用。 */
    function consultRawId(consultId) {
        var v = String(consultId || '');
        if (/^\d+$/.test(v)) return parseInt(v, 10);
        var hit = (ctx.DATA.consults || []).find(function (cc) { return String(cc.code) === v; });
        return hit ? (hit.id || 0) : 0;
    }

    return {
        openConsultCreate: openConsultCreate,
        renderConsultList: renderConsultList,
        delConsult: delConsult,
        openConsultDetail: openConsultDetail,
        startConsult: startConsult,
        enterConsultMode: enterConsultMode,
        enterConsultEditor: enterConsultEditor,
        applyConsultMode: applyConsultMode,
    };
})();
