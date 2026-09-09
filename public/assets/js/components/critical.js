/**
 * ============================================================
 * critical.js v1.0.0 — 危急值组件
 * ============================================================
 * 说明：全院危急值全生命周期前端：
 * 1. 发送：检验科 save_result 检测到危急值 → openSend（默认开单医生，
 *    可点击另选，医生搜索无限滚动）；影像科写报告模态框「报危急值」
 *    手动录入 + 加入预览队列，随报告发布一并发送。
 * 2. 处理：医生站内消息/危急值管理点开 → 左右分栏处理弹窗（左侧患者
 *    信息 + 危急值 + 完整检验结果；右侧符合/不符合病情 + 处理措施），
 *    提交自动在病历插入「危急值记录」续写文书。
 * 3. 归档：检验/影像科与管理员以只读详情查看全过程。
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.critical = (function () {
    var esc = function (s) { return Clinic.escHtml(s); };

    /** 发送弹窗上下文（含当前所选接收医生） */
    var SEND_CTX = null;
    /** 医生搜索状态 */
    var DOC_Q = '', DOC_PAGE = 0, DOC_HAS_MORE = false, DOC_LOADING = false, DOC_PICK = null;
    /** 当前列表页刷新回调（危急值管理页注册） */
    var LIST_REFRESH = null;

    /* ==================== 基础渲染 ==================== */

    function sourceName(s) { return s === 'lab' ? '检验科' : '影像科'; }
    function kindName(s) { return s === 'lab' ? '检验' : '检查'; }
    function matchText(m) { return m === 'match' ? '符合病情' : (m === 'mismatch' ? '不符合病情' : ''); }

    function statusBadge(status) {
        return status === 'done'
            ? '<span class="badge badge-success">已处理</span>'
            : '<span class="badge badge-warning">待处理</span>';
    }

    /** 危急值详情 HTML（患者信息 + 危急值红色加粗 + 完整结果 / 影像内容） */
    function detailHtml(cv) {
        var snap = cv.snapshot || {};
        var rows = snap.rows || [];
        var items = cv.items || [];
        var findings = snap.findings || '';
        var conclusion = snap.conclusion || '';
        var critItems = items.length ? items : rows.filter(function (r) { return r.is_critical; });
        var html = '';
        html += '<div class="crit-patient">' +
            '<div class="crit-patient-name">' + esc(cv.patient_name || '—') + '</div>' +
            '<div class="crit-patient-meta">' +
            (esc(cv.patient_gender || '') + (cv.patient_age ? ' / ' + esc(cv.patient_age) : '') + (cv.patient_birth ? ' ｜ 出生日期 ' + esc(cv.patient_birth) : '')).trim() +
            '</div></div>';
        html += '<div class="crit-title crit-title-danger">⚠️ 危急值 ' + esc(kindName(cv.source)) + '结果</div>';
        if (critItems.length) {
            critItems.forEach(function (it) {
                html += '<div class="crit-item">' + esc(it.name || cv.item_name) + '：<b class="crit-item-val">' + esc(it.value || '') + '</b>' +
                    (it.unit ? ' ' + esc(it.unit) : '') +
                    (it.normal_range ? ' <span class="crit-range">（正常范围 ' + esc(it.normal_range) + '）</span>' : '') +
                    ((it.critical_low || it.critical_high) ? ' <span class="crit-range">（危急值阈值 低' + esc(it.critical_low || '—') + ' / 高' + esc(it.critical_high || '—') + '）</span>' : '') +
                    '</div>';
            });
        } else {
            html += '<div class="crit-item">' + esc(cv.item_name || '') + '</div>';
        }
        // 完整结果：检验为全项表格；影像为所见 + 诊断
        if (rows.length) {
            html += '<div class="crit-subtitle">完整检验结果</div>';
            html += '<table class="table crit-table"><thead><tr>' +
                '<th>项目</th><th>结果</th><th>单位</th><th>正常范围</th><th>危急值阈值</th></tr></thead><tbody>';
            rows.forEach(function (r) {
                var hit = r.is_critical;
                var thr = ((r.critical_low ? '低' + r.critical_low : '') + (r.critical_high ? ' 高' + r.critical_high : '')).trim();
                html += '<tr class="' + (hit ? 'crit-row-hit' : '') + '">' +
                    '<td>' + esc(r.name) + (hit ? ' <span class="badge badge-danger" style="font-size:10px">危急</span>' : '') + '</td>' +
                    '<td class="' + (hit ? 'crit-cell-hit' : '') + '"><b>' + esc(r.value || '—') + '</b></td>' +
                    '<td>' + esc(r.unit || '—') + '</td>' +
                    '<td>' + esc(r.normal_range || '—') + '</td>' +
                    '<td>' + esc(thr || '—') + '</td></tr>';
            });
            html += '</tbody></table>';
        } else if (findings || conclusion) {
            html += '<div class="crit-subtitle">影像所见</div><div class="crit-text">' + esc(findings || '—') + '</div>';
            html += '<div class="crit-subtitle">影像诊断</div><div class="crit-text">' + esc(conclusion || '—') + '</div>';
        }
        return html;
    }

    /** 处理记录（只读查看用） */
    function processInfoHtml(cv) {
        if (cv.status !== 'done') {
            return '<div class="crit-proc"><div class="fs-13 text-muted">该危急值尚未处理</div></div>';
        }
        return '<div class="crit-proc">' +
            '<div class="crit-proc-row"><span>处理结果</span><b>' + esc(matchText(cv.match_status)) + '</b></div>' +
            (cv.treatment ? '<div class="crit-proc-row"><span>处理措施</span>' + esc(cv.treatment) + '</div>' : '') +
            '<div class="crit-proc-row"><span>处理医生</span>' + esc(cv.processed_by_name || '') + '</div>' +
            '<div class="crit-proc-row"><span>处理时间</span>' + esc(cv.processed_at || '') + '</div>' +
            '<div class="crit-proc-row"><span>处理时长</span>' + esc(cv.duration || '—') + '</div>' +
            '</div>';
    }

    /* ==================== 医生搜索（分页 + 无限滚动） ==================== */

    function docListHtml(list) {
        return list.map(function (d) {
            return '<div class="dd-item crit-doctor-item" data-id="' + d.id + '" data-name="' + esc(d.name) + '" ' +
                'onclick="Clinic.critical._pickDoctor(this)">' +
                '<b>' + esc(d.name) + '</b> ' +
                '<span class="fs-12 text-muted">' + (d.emp_no ? '工号 ' + esc(d.emp_no) : '—') + (d.title ? ' · ' + esc(d.title) : '') + '</span>' +
                '</div>';
        }).join('');
    }

    function docLoad(append) {
        if (DOC_LOADING) return;
        DOC_LOADING = true;
        var box = document.getElementById('critDocList');
        var foot = document.getElementById('critDocFoot');
        if (!append && box) box.innerHTML = '<div class="crit-doc-tip">加载中…</div>';
        Clinic.get('/api/critical?action=doctor_search&q=' + encodeURIComponent(DOC_Q) + '&page=' + DOC_PAGE, null, {
            loading: false,
            onSuccess: function (json) {
                DOC_LOADING = false;
                var d = json.data;
                DOC_HAS_MORE = !!d.has_more;
                if (!box) return;
                if (append) {
                    box.insertAdjacentHTML('beforeend', docListHtml(d.list));
                } else {
                    box.innerHTML = d.list.length
                        ? docListHtml(d.list)
                        : '<div class="crit-doc-tip">未找到匹配的医生</div>';
                }
                if (foot) {
                    foot.style.display = DOC_HAS_MORE ? '' : 'none';
                    foot.textContent = DOC_HAS_MORE ? '↓ 下滑加载更多' : '已加载全部（共 ' + d.total + ' 人）';
                }
            },
            onError: function () { DOC_LOADING = false; },
        });
    }

    /** 打开医生搜索弹窗（无限滚动，每页 20 人） */
    function openDoctorSearch(onPick, title) {
        DOC_Q = ''; DOC_PAGE = 1; DOC_HAS_MORE = true; DOC_LOADING = false; DOC_PICK = onPick;
        var mask = Clinic.modal.open(
            '<div class="crit-doc-search">' +
            '  <div class="form-group"><input class="input" id="critDocQ" placeholder="🔍 输入医生姓名 / 工号搜索" autocomplete="off" ' +
            'oninput="Clinic.critical._docSearchInput(this.value)"></div>' +
            '  <div id="critDocList" class="crit-doc-list">' +
            '    <div class="crit-doc-tip">加载中…</div>' +
            '  </div>' +
            '  <div id="critDocFoot" class="crit-doc-foot">↓ 下滑加载更多</div>' +
            '</div>',
            { title: title || '选择接收医生', size: 'modal-sm', buttons: [{ text: '取消', cls: 'btn-outline' }] }
        );
        var listEl = mask.querySelector('#critDocList');
        if (listEl) {
            listEl.addEventListener('scroll', function () {
                if (!DOC_HAS_MORE || DOC_LOADING) return;
                if (listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 40) {
                    DOC_PAGE++;
                    docLoad(true);
                }
            });
        }
        setTimeout(function () {
            var q = document.getElementById('critDocQ');
            if (q) q.focus();
        }, 80);
        docLoad(false);
    }

    function _docSearchInput(v) {
        DOC_Q = (v || '').trim(); DOC_PAGE = 1; DOC_HAS_MORE = true;
        docLoad(false);
    }

    function _pickDoctor(el) {
        var id = el.getAttribute('data-id');
        var name = el.getAttribute('data-name');
        if (DOC_PICK) DOC_PICK({ id: parseInt(id, 10), name: name });
        Clinic.modal.close();
    }

    /* ==================== 发送（检验科检测 / 影像科手动） ==================== */

    /**
     * 打开发送弹窗
     * @param opts { source, report_id, mode:'lab'|'imaging', detected:[items],
     *               item_name, doctor_id, doctor_name, onAdd, onSent }
     */
    function openSend(opts) {
        SEND_CTX = {
            source: opts.source, report_id: opts.report_id, mode: opts.mode || 'lab',
            to_doctor_id: opts.doctor_id || 0, to_doctor_name: opts.doctor_name || '',
            onAdd: opts.onAdd, onSent: opts.onSent,
        };
        var detHtml = '';
        if (opts.detected && opts.detected.length) {
            detHtml = '<div class="crit-send-det">' +
                '<div class="crit-title crit-title-danger">⚠️ 检测到危急值</div>' +
                opts.detected.map(function (d) {
                    return '<div class="crit-item">' + esc(d.name) + '：<b class="crit-item-val">' + esc(d.value || '') + '</b>' +
                        (d.unit ? ' ' + esc(d.unit) : '') +
                        (d.normal_range ? ' <span class="crit-range">（正常范围 ' + esc(d.normal_range) + '）</span>' : '') +
                        ' <span class="crit-range">（危急值阈值 低' + esc(d.critical_low || '—') + ' / 高' + esc(d.critical_high || '—') + '）</span></div>';
                }).join('') + '</div>';
        }
        // 影像科：已加入预览队列的危急值（等待发送）在弹窗内展示，避免重复添加/遗漏
        var existingHtml = '';
        if (opts.mode === 'imaging' && opts.existing && opts.existing.length) {
            existingHtml = '<div class="crit-existing-box">' +
                '<div class="crit-subtitle">已添加的危急值（等待发送）</div>' +
                opts.existing.map(function (x) {
                    return '<div class="dw-crit-queue-item">' +
                        '<span class="crit-q-name">' + esc(x.item) + '</span>' +
                        '<span class="fs-12 text-muted">→ ' + esc(x.to_doctor_name || '') + '</span></div>';
                }).join('') +
                '<div class="fs-12 text-muted mt-4">以上危急值将在报告发布时一并发送，可继续添加。</div></div>';
        }
        var manualHtml = opts.mode === 'imaging'
            ? '<div class="form-group"><label class="form-label">危急值项目 <span class="req">*</span></label>' +
              '<input class="input" id="critItemInput" placeholder="手动输入危急值项目，如：脑疝、眼球破裂等"></div>'
            : '';
        var html = detHtml + existingHtml + manualHtml +
            '<div class="form-group">' +
            '  <label class="form-label">接收医生</label>' +
            '  <div class="crit-doc-sel" id="critDocSel" onclick="Clinic.critical._openPickerFromSend()">' +
            '    <span id="critDocName" class="fw-600">' + esc(SEND_CTX.to_doctor_name || '请选择医生') + '</span>' +
            '    <span class="fs-12 text-muted">（点击可另选医生）</span>' +
            '  </div>' +
            '</div>' +
            '<div class="fs-12 text-muted">' +
            (opts.mode === 'lab'
                ? '默认通知开单医生，如开单医生下班可点击医生姓名改选其他医生。'
                : '加入预览后不立即发送，随报告发布一并发出。') +
            '</div>';
        var buttons = opts.mode === 'imaging'
            ? [
                { text: '取消', cls: 'btn-outline' },
                { text: '＋ 加入危急值预览', cls: 'btn-primary', autoClose: false, onClick: addToPreview },
            ]
            : [
                { text: '取消', cls: 'btn-outline' },
                { text: '🚨 发送危急值', cls: 'btn-danger', autoClose: false, onClick: sendCritical },
            ];
        Clinic.modal.open(html, {
            title: opts.mode === 'lab' ? '危急值通知' : '报危急值',
            size: 'modal-md',
            buttons: buttons,
        });
    }

    function _openPickerFromSend() {
        openDoctorSearch(function (doc) {
            SEND_CTX.to_doctor_id = doc.id;
            SEND_CTX.to_doctor_name = doc.name;
            var nameEl = document.getElementById('critDocName');
            if (nameEl) nameEl.textContent = doc.name;
        }, '另选接收医生');
    }

    /** 检验科：直接发送危急值（报告已生成，snapshot 由后端回读固化） */
    function sendCritical() {
        if (!SEND_CTX.to_doctor_id) { Clinic.toast.warning('请选择接收医生'); return; }
        Clinic.ajax('/api/critical', {
            action: 'send', source: SEND_CTX.source, report_id: SEND_CTX.report_id, to_doctor_id: SEND_CTX.to_doctor_id,
        }, {
            loading: true,
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.modal.close();
                if (SEND_CTX.onSent) SEND_CTX.onSent(json);
            },
        });
    }

    /** 影像科：加入预览队列（不立即发送），报告发布时随 send 一并发出 */
    function addToPreview() {
        var item = ((document.getElementById('critItemInput') || {}).value || '').trim();
        if (!item) { Clinic.toast.warning('请填写危急值项目'); return; }
        if (!SEND_CTX.to_doctor_id) { Clinic.toast.warning('请选择接收医生'); return; }
        if (SEND_CTX.onAdd) {
            SEND_CTX.onAdd({ item: item, to_doctor_id: SEND_CTX.to_doctor_id, to_doctor_name: SEND_CTX.to_doctor_name });
        }
        Clinic.modal.close();
        Clinic.toast.success('已加入危急值预览，发布报告时一并发送');
    }

    /** 通用发送（影像科发布时逐条调用） */
    function send(opts, cb) {
        Clinic.ajax('/api/critical', {
            action: 'send', source: opts.source, report_id: opts.report_id,
            to_doctor_id: opts.to_doctor_id, item: opts.item || '',
        }, {
            loading: false,
            onSuccess: function (json) { if (cb && cb.onSuccess) cb.onSuccess(json); },
            onError: function () { if (cb && cb.onError) cb.onError(); },
        });
    }

    /* ==================== 医生处理 / 只读查看 ==================== */

    function openProcess(cvId) {
        Clinic.get('/api/critical?action=detail&id=' + cvId, null, {
            onSuccess: function (json) {
                var cv = json.data.cv;
                if (cv.status === 'done') { openView(cvId); return; }
                var html =
                    '<div class="crit-modal">' +
                    '  <div class="crit-left">' + detailHtml(cv) + '</div>' +
                    '  <div class="crit-right">' +
                    '    <div class="crit-subtitle">是否符合病情</div>' +
                    '    <div class="crit-match-options">' +
                    '      <label class="crit-opt"><input type="radio" name="critMatch" value="match"> <span>符合病情</span></label>' +
                    '      <label class="crit-opt"><input type="radio" name="critMatch" value="mismatch"> <span>不符合病情</span></label>' +
                    '    </div>' +
                    '    <div class="form-group"><label class="form-label">处理措施 <span class="req">*</span></label>' +
                    '      <textarea class="textarea" id="critTreatment" rows="4" placeholder="请填写处理措施，如：立即收入留观、吸氧心电监护、请上级医师会诊等"></textarea></div>' +
                    '    <div class="fs-12 text-muted">提交后将自动在病历中插入「危急值记录」续写文书（系统固化，只读不可修改）。</div>' +
                    '  </div>' +
                    '</div>';
                Clinic.modal.open(html, {
                    title: '⚠️ 危急值处理：' + cv.item_name,
                    size: 'modal-lg',
                    buttons: [
                        { text: '取消', cls: 'btn-outline' },
                        { text: '✅ 提交处理', cls: 'btn-primary', autoClose: false, onClick: function () { submitProcess(cv); } },
                    ],
                });
            },
        });
    }

    function submitProcess(cv) {
        var mEl = document.querySelector('input[name="critMatch"]:checked');
        var treatment = ((document.getElementById('critTreatment') || {}).value || '').trim();
        if (!mEl) { Clinic.toast.warning('请选择是否符合病情'); return; }
        if (!treatment) { Clinic.toast.warning('请填写处理措施'); return; }
        Clinic.ajax('/api/critical', { action: 'process', id: cv.id, match: mEl.value, treatment: treatment }, {
            loading: true,
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                Clinic.modal.close();
                // 仅当「正打开的就是该患者病历页」且「无未保存修改」时重载病历，
                // 展示新增的「危急值记录」节点——避免误重载其他患者或丢弃首诊未保存内容
                var curVid = document.getElementById('visitId');
                var emrClean = !(window.Clinic && Clinic.emr && Clinic.emr.isDirty && Clinic.emr.isDirty());
                if (curVid && cv.visit_id && curVid.value === cv.visit_id && emrClean && Clinic.emr && Clinic.emr.init) {
                    try { Clinic.emr.init(); } catch (e) { /* ignore */ }
                }
                if (LIST_REFRESH) LIST_REFRESH();
            },
        });
    }

    function openView(cvId) {
        Clinic.get('/api/critical?action=detail&id=' + cvId, null, {
            onSuccess: function (json) {
                var cv = json.data.cv;
                var html =
                    '<div class="crit-modal">' +
                    '  <div class="crit-left">' + detailHtml(cv) + '</div>' +
                    '  <div class="crit-right">' +
                    '    <div class="crit-subtitle">处理记录</div>' + processInfoHtml(cv) +
                    '    <div class="crit-send-info">' +
                    '      <div class="crit-proc-row"><span>发起科室</span>' + esc(cv.from_dept || '—') + '</div>' +
                    '      <div class="crit-proc-row"><span>发起人</span>' + esc(cv.from_name || '—') + '</div>' +
                    '      <div class="crit-proc-row"><span>接收医生</span>' + esc(cv.to_doctor_name || '—') + '</div>' +
                    '      <div class="crit-proc-row"><span>发送时间</span>' + esc(cv.created_at || '—') + '</div>' +
                    '    </div>' +
                    '  </div>' +
                    '</div>';
                Clinic.modal.open(html, {
                    title: '危急值详情：' + cv.item_name,
                    size: 'modal-lg',
                    buttons: [{ text: '关闭', cls: 'btn-outline' }],
                });
            },
        });
    }

    /* ==================== 消息 / 列表入口 ==================== */

    /** 站内消息危急值链接（/critical_value/<id>）统一入口 */
    function handleLink(url) {
        if (!url || url.indexOf('/critical_value/') !== 0) return false;
        var id = url.split('/').pop();
        if (!id) return false;
        var role = document.body.getAttribute('data-role') || '';
        if (role === 'doctor') openProcess(id);
        else openView(id);
        return true;
    }

    /** 列表行点击：医生待处理→处理弹窗，其余→只读详情 */
    function openRow(id) {
        var role = document.body.getAttribute('data-role') || '';
        if (role !== 'doctor') { openView(id); return; }
        Clinic.get('/api/critical?action=detail&id=' + id, null, {
            loading: false,
            onSuccess: function (json) {
                if (json.data.cv.status === 'pending') openProcess(id);
                else openView(id);
            },
        });
    }

    /* ==================== 危急值管理页 ==================== */

    /**
     * 初始化危急值管理页（医生/检验/影像/管理员共用）
     * @param cfg { role:'doctor'|'lab'|'imaging'|'admin', listBody:'容器id', totalEl?, footEl? }
     */
    function initListPage(cfg) {
        var state = { status: '', from: '', to: '', page: 1, has_more: false, loading: false };
        var isDoctor = cfg.role === 'doctor';
        var isAdmin = cfg.role === 'admin';

        /** 默认时间范围：最近 3 天（含今天），可手动修改；优先服务端站点时区日期 */
        function setDefaultRange() {
            var fmt = function (d) {
                return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
            };
            if (cfg.defaultFrom && cfg.defaultTo) {
                state.from = cfg.defaultFrom;
                state.to = cfg.defaultTo;
            } else {
                var t = new Date();
                var f = new Date();
                f.setDate(t.getDate() - 2);
                state.from = fmt(f);
                state.to = fmt(t);
            }
            var fromEl = document.getElementById('critFrom');
            var toEl = document.getElementById('critTo');
            if (fromEl) fromEl.value = state.from;
            if (toEl) toEl.value = state.to;
        }

        function head() {
            var cols = isDoctor || isAdmin
                ? '<th>患者</th><th>项目</th><th>发起科室</th><th>发起时间</th><th>处理时间</th><th>处理时长</th><th>状态</th>'
                : '<th>患者</th><th>项目</th><th>接收医生</th><th>发起人</th><th>发起时间</th><th>处理时间</th><th>处理时长</th><th>状态</th>';
            return '<table class="table"><thead><tr>' + cols + '</tr></thead><tbody id="' + cfg.listBody + '"></tbody></table>';
        }

        function rowHtml(r) {
            var colHtml = isDoctor || isAdmin
                ? '<td>' + esc(r.patient_name || '—') + '</td>' +
                  '<td>' + esc(r.item_name || '—') + '</td>' +
                  '<td>' + esc(r.from_dept || '—') + '</td>' +
                  '<td class="fs-12">' + esc(r.created_at || '—') + '</td>' +
                  '<td class="fs-12">' + esc(r.processed_at || '—') + '</td>' +
                  '<td>' + esc(r.duration || '—') + '</td>'
                : '<td>' + esc(r.patient_name || '—') + '</td>' +
                  '<td>' + esc(r.item_name || '—') + '</td>' +
                  '<td>' + esc(r.to_doctor_name || '—') + '</td>' +
                  '<td>' + esc(r.from_name || '—') + '</td>' +
                  '<td class="fs-12">' + esc(r.created_at || '—') + '</td>' +
                  '<td class="fs-12">' + esc(r.processed_at || '—') + '</td>' +
                  '<td>' + esc(r.duration || '—') + '</td>';
            return '<tr style="cursor:pointer" onclick="Clinic.critical.openRow(\'' + r.id + '\')">' + colHtml +
                '<td>' + statusBadge(r.status) + (r.status === 'done' && r.match_status ? ' <span class="badge badge-info">' + esc(matchText(r.match_status)) + '</span>' : '') + '</td>' +
                '</tr>';
        }

        function load(reset) {
            if (state.loading) return;
            state.loading = true;
            if (reset) state.page = 1;
            var box = document.getElementById(cfg.listBody);
            if (!box) { state.loading = false; return; }
            if (reset) box.innerHTML = '<tr><td colspan="9"><div class="text-center" style="padding:24px"><div class="spinner" style="border-top-color:var(--primary);margin:0 auto"></div></div></td></tr>';
            Clinic.get('/api/critical?action=list&page=' + state.page +
                '&status=' + encodeURIComponent(state.status) +
                '&from=' + encodeURIComponent(state.from) +
                '&to=' + encodeURIComponent(state.to), null, {
                loading: false,
                onSuccess: function (json) {
                    state.loading = false;
                    var d = json.data;
                    if (reset) {
                        box.innerHTML = d.list.length
                            ? d.list.map(rowHtml).join('')
                            : '<tr><td colspan="9"><div class="empty" style="padding:30px 0"><div class="empty-ico">🚨</div>暂无危急值记录</div></td></tr>';
                    } else {
                        box.insertAdjacentHTML('beforeend', d.list.map(rowHtml).join(''));
                    }
                    state.has_more = !!d.has_more;
                    var totalEl = document.getElementById(cfg.totalEl);
                    if (totalEl) totalEl.textContent = '共 ' + d.total + ' 条';
                    var footEl = document.getElementById(cfg.footEl);
                    if (footEl) {
                        footEl.style.display = state.has_more ? '' : 'none';
                    }
                },
                onError: function () { state.loading = false; },
            });
        }

        LIST_REFRESH = function () { load(true); };

        // 供页面上「查询 / 重置 / 加载更多」按钮经公共代理调用（操作闭包 state）
        window.__critListApply = function (s) {
            state.status = s.status || '';
            state.from = s.from || '';
            state.to = s.to || '';
            load(true);
        };
        window.__critListMore = function () {
            if (!state.has_more || state.loading) return;
            state.page++;
            load(false);
        };

        // 「重置」恢复默认 3 天范围（不清空），而非回到全量
        window.__critListResetDefault = function () {
            setDefaultRange();
            document.getElementById('critStatus').value = '';
            load(true);
        };

        var container = document.getElementById(cfg.container);
        if (container) {
            container.innerHTML =
                '<div class="card" style="margin-bottom:14px">' +
                '  <div class="flex gap-8" style="align-items:flex-end;flex-wrap:wrap;padding:14px">' +
                '    <div class="form-group" style="margin:0">' +
                '      <label class="form-label">开始日期</label>' +
                '      <input type="text" class="input" id="critFrom" readonly placeholder="开始日期" style="width:150px;cursor:pointer;background:var(--bg)" onclick="Clinic.datePicker.open(this,{maxToday:false})">' +
                '    </div>' +
                '    <div class="form-group" style="margin:0">' +
                '      <label class="form-label">结束日期</label>' +
                '      <input type="text" class="input" id="critTo" readonly placeholder="结束日期" style="width:150px;cursor:pointer;background:var(--bg)" onclick="Clinic.datePicker.open(this,{maxToday:true})">' +
                '    </div>' +
                '    <div class="form-group" style="margin:0">' +
                '      <label class="form-label">处理状态</label>' +
                '      <select class="select" id="critStatus">' +
                '        <option value="">全部</option>' +
                '        <option value="pending">待处理</option>' +
                '        <option value="done">已处理</option>' +
                '      </select>' +
                '    </div>' +
                '    <button class="btn btn-primary btn-sm" onclick="Clinic.critical._listFilter()">查询</button>' +
                '    <button class="btn btn-outline btn-sm" onclick="Clinic.critical._listReset()">重置</button>' +
                '    <span class="fs-13 text-muted" id="' + cfg.totalEl + '"></span>' +
                '  </div>' +
                '</div>' +
                '<div class="card"><div class="table-wrap">' + head() + '</div>' +
                '  <div class="text-center" style="padding:10px">' +
                '    <button class="btn btn-outline btn-sm" id="' + cfg.footEl + '" style="display:none" onclick="Clinic.critical._listMore()">加载更多</button>' +
                '  </div>' +
                '</div>';
            // 先渲染容器（日期输入框就位）再填充默认近 3 天日期，否则 placeholder 不会消失
            setDefaultRange();
            load(true);
        }
    }

    function _listFilter() {
        if (window.__critListApply) {
            window.__critListApply({
                status: ((document.getElementById('critStatus') || {}).value || ''),
                from: ((document.getElementById('critFrom') || {}).value || ''),
                to: ((document.getElementById('critTo') || {}).value || ''),
            });
        }
    }
    function _listReset() {
        document.getElementById('critFrom').value = '';
        document.getElementById('critTo').value = '';
        document.getElementById('critStatus').value = '';
        if (window.__critListResetDefault) window.__critListResetDefault();
    }

    function _listMore() {
        if (window.__critListMore) window.__critListMore();
    }

    return {
        openSend: openSend,
        send: send,
        openProcess: openProcess,
        openView: openView,
        openRow: openRow,
        handleLink: handleLink,
        initListPage: initListPage,
        _pickDoctor: _pickDoctor,
        _docSearchInput: _docSearchInput,
        _openPickerFromSend: _openPickerFromSend,
        _listFilter: _listFilter,
        _listReset: _listReset,
        _listMore: _listMore,
    };
})();
