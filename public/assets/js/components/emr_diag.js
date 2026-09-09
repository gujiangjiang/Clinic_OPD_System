/**
 * ============================================================
 * emr_diag.js — 初步诊断（ICD 联动 / 编辑 / 排序 / 侧边栏诊断行）
 * ============================================================
 * 说明：自 emr.js 拆出的诊断模块——诊断添加/编辑/排序悬浮窗、
 * 侧边栏诊断行缓存与同步、跨医生引用查重。经 Clinic.emr._ctx
 * 读写共享状态与内部函数（DATA/renderLeftNav/myDoctorId/
 * currentRecordEditable/clampPop/escHtml）。
 * 依赖：Clinic.get/Clinic.ajax/Clinic.modal/Clinic.toast/Clinic.emrEditor。
 * ============================================================ */
window.Clinic = window.Clinic || {};
Clinic.emr = Clinic.emr || {};

Clinic.emr.diag = (function () {
    var ctx = Clinic.emr._ctx;
    var escHtml = ctx.escHtml;
    var myDoctorId = ctx.myDoctorId;
    var currentRecordEditable = ctx.currentRecordEditable;
    var renderLeftNav = ctx.renderLeftNav;
    // 注意：clampPop 的本地实现就在本模块内（见下方 function clampPop），
    // 不可写 `var clampPop = ctx.clampPop`——该赋值会覆盖提升的函数声明，
    // 而 ctx.clampPop 是 emr.js 的桥接（指向本模块），自引用将导致无限递归
    // （Maximum call stack size exceeded，诊断弹窗打不开）

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
        if (ctx.DATA && ctx.DATA.visit && ctx.DATA.visit.status === 'finished') {
            Clinic.toast.warning('该患者已诊毕，诊断不可调整');
            return false;
        }
        // 当前编辑器可编辑判定（区别于开单的 hasEditableRecord）：
        // 会诊处理中 → 当前文书须是会诊文书；普通模式 → 当前科室文书或首诊新建中。
        // 解决「会诊病历编辑中 record_id=0 → 误判无可编辑病历」的自锁。
        if (!currentRecordEditable()) {
            Clinic.toast.warning('当前无可编辑的病历（会诊需先创建会诊病历，转科前文书只读），不可调整诊断');
            return false;
        }
        return true;
    }
    /** 本人当前诊断列表（ctx.DATA.record.emr.diagnoses） */
    function myDiags() {
        return (ctx.DATA && ctx.DATA.record && ctx.DATA.record.emr && ctx.DATA.record.emr.diagnoses) || [];
    }
    /** 诊断列表持久化 + 编辑器/缓存/侧边栏同步。
     *  首次保存前（record_id=0）仅本地暂存（编辑器+缓存），不调接口——
     *  诊断随首次 save() 一并写入；已保存则服务端即时持久化。 */
    function saveDiags(newDiags, okMsg) {
        var noRecord = !ctx.DATA || !ctx.DATA.record || !(ctx.DATA.record.record_id > 0);
        var myId = myDoctorId();
        function syncLocal(saved) {
            Clinic.emrEditor.setDiags(saved);
            if (ctx.DATA) {
                if (!ctx.DATA.record.emr) ctx.DATA.record.emr = {};
                ctx.DATA.record.emr.diagnoses = saved;
                // 仅更新当前编辑文书的 records_history 条目（精确匹配 record_id，
                // 避免污染本人其他续写/首诊的诊断列表，导致自己引用自己）
                var curRid = ctx.DATA.record && ctx.DATA.record.record_id;
                (ctx.DATA.records_history || []).forEach(function (h) {
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
            edit_record_id: (ctx.DATA && ctx.DATA.__edit_record_id) || 0,
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
            '<div class="fs-13 mb-8" style="display:flex;justify-content:space-between;align-items:center">' +
            '<span>编辑：<span class="text-muted">' + escHtml(d.code || '') + '</span> <b>' + escHtml(d.name) + '</b></span>' +
            '  <button type="button" class="btn btn-danger btn-sm" id="dpeDel" style="flex-shrink:0">🗑️ 删除</button>' +
            '</div>' +
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
        // 删除：从当前病历移除该诊断，并同步右侧诊断列表（saveDiags → renderLeftNav）
        var dpeDel = pop.querySelector('#dpeDel');
        if (dpeDel) {
            dpeDel.addEventListener('click', function () {
                var cur = myDiags();
                if (!cur[idx]) { closeDiagPop(); return; }
                var tgt = cur[idx];
                var isQuoted = Clinic.emrEditor.findPrevDiag(tgt.code);
                var doDel = function () {
                    var arr = myDiags().slice();
                    if (!arr[idx]) { closeDiagPop(); return; }
                    arr.splice(idx, 1);
                    closeDiagPop();
                    saveDiags(arr, '诊断已删除：' + (tgt.name || ''));
                };
                if (isQuoted) {
                    Clinic.modal.confirm('该诊断为引用诊断，只删除自己病历中的诊断，无法删除他人已开具的诊断。确定删除？', doDel,
                        { title: '删除引用诊断', okText: '确认删除' });
                } else {
                    Clinic.modal.confirm('确定删除该诊断？', doDel, { title: '删除诊断', okText: '确认删除' });
                }
            });
        }
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
            var _prog = ctx.DATA && ctx.DATA.record && ctx.DATA.record.record_type === 'progress';
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
        // 搜索结果分段加载状态（无限滚动：滚动到底部自动加载下一页，直至全部结果加载完成）
        var dpState = { kw: '', offset: 0, total: 0, loading: false, done: false };
        var dpRes = pop.querySelector('#dpRes');
        function dpRenderRows(list) {
            return list.map(function (x) {
                return '<div class="diag-pop-item" data-code="' + escHtml(x.icd10_code) + '" data-name="' + escHtml(x.diagnosis_name) + '">' +
                    '<span class="text-muted">' + escHtml(x.icd10_code) + '</span> <b>' + escHtml(x.diagnosis_name) + '</b></div>';
            }).join('');
        }
        function dpAppendMore() {
            if (dpState.loading || dpState.done) return;
            dpState.loading = true;
            Clinic.get('/api/icd10?action=search&kw=' + encodeURIComponent(dpState.kw) + '&offset=' + dpState.offset, null, {
                onSuccess: function (j) {
                    var list = j.data.list || [];
                    dpState.total = j.data.total || 0;
                    dpState.loading = false;
                    if (dpState.offset === 0) {
                        // 首次：整页替换
                        dpRes.innerHTML = list.length
                            ? dpRenderRows(list)
                            : '<div class="fs-12 text-muted" style="padding:8px 2px">未检索到匹配诊断</div>';
                    } else {
                        // 追加下一页
                        if (list.length) {
                            var tmp = document.createElement('div');
                            tmp.innerHTML = dpRenderRows(list);
                            while (tmp.firstChild) dpRes.appendChild(tmp.firstChild);
                        }
                    }
                    dpState.offset += list.length;
                    dpState.done = dpState.offset >= dpState.total;
                    // 结果列表撑高浮窗后重新夹紧视口
                    clampPop(pop);
                    // 若本页未填满可视区且仍有更多，自动继续加载（少数场景一次性显示完全部结果）
                    if (!dpState.done && dpRes.scrollHeight <= dpRes.clientHeight) dpAppendMore();
                },
            });
        }
        var timer = null;
        kw.addEventListener('input', function () {
            var q = this.value.trim();
            if (timer) clearTimeout(timer);
            if (!q) { dpState.done = true; dpState.offset = 0; dpState.total = 0; dpRes.innerHTML = '<div class="fs-12 text-muted" style="padding:8px 2px">输入关键词检索 ICD10 诊断</div>'; return; }
            timer = setTimeout(function () {
                dpState.kw = q;
                dpState.offset = 0;
                dpState.total = 0;
                dpState.done = false;
                dpAppendMore();
            }, 200);
        });
        // 滚动到底部自动加载下一页（无限滚动直至全部结果加载完成）
        dpRes.addEventListener('scroll', function () {
            if (dpRes.scrollTop + dpRes.clientHeight >= dpRes.scrollHeight - 8) dpAppendMore();
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

    /** 同步侧边栏诊断行缓存（emr.js renderLeftNav 渲染时调用）：
     *  DIAG_ROWS 为本模块私有状态——delDiag / openDiagOpsPop 据此定位行，
     *  emr.js 渲染侧边栏后必须经此 setter 同步（拆分模块间不可直接赋值），
     *  否则缓存恒为空数组 → 删除按钮/排序浮窗点击静默无反应 */
    function setDiagRows(rows) {
        DIAG_ROWS = rows || [];
    }

    function openDiagOpsPop(ev, idx) {
        var row = DIAG_ROWS[idx];
        if (!row) return;
        // 主诊断（全局首行）点击不弹操作浮窗
        if (idx === 0) return;
        // 会诊模式/会诊记录：不可调整诊断顺序/设为主诊断（后端 save_diag_order 同步拦截）
        if ((ctx.DATA && ctx.DATA.__consult_mode) || (ctx.DATA && ctx.DATA.record && ctx.DATA.record.consultation_id > 0)) {
            return;
        }
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
            if (ctx.DATA) ctx.DATA.diag_order = newKeys;   // 本地先行，渲染即时生效
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
                if (ctx.DATA) ctx.DATA.diag_order = j.data.diag_order || newKeys;
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

    return {
        clampPop: clampPop,
        closeDiagPop: closeDiagPop,
        placeDiagPop: placeDiagPop,
        diagEditable: diagEditable,
        myDiags: myDiags,
        saveDiags: saveDiags,
        openDiagEditPop: openDiagEditPop,
        openDiagPop: openDiagPop,
        openDiagOpsPop: openDiagOpsPop,
        saveDiagOrder: saveDiagOrder,
        delDiag: delDiag,
        setDiagRows: setDiagRows,
    };
})();
