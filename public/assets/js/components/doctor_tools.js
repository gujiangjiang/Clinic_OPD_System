/**
 * ============================================================
 * doctor_tools.js v2.0.0 — 医生工作站（新）顶栏工具
 * ============================================================
 * 说明：以顶栏按钮形式集成到医生工作站（新）顶部标题区域：
 * 1. 标题「医生工作站-科室」：科室可点击切换（仅多科室权限可切，
 *    单科室点击无反应；后端 set_dept 亦做权限强校验）
 * 2. 工具箱下拉：加号 / 切换科室 / 患者查询 / 模板管理
 * 3. 叫号大屏绑定（工具箱左侧）：未绑定诊室 → 下拉选择可绑定的诊室列表；
 *    已绑定诊室 → 打开可拖动的叫号悬浮窗（诊室名称/当前就诊/再次叫号/
 *    过号/下一位/候诊号源池/解绑大屏），并支持病历联动（叫号自动切换病历）
 * 依赖：ajax.js / modal.js / deptpicker.js / toast.js / validate / room_heartbeat.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.docTools = (function () {

    var CUR_DEPT = 0;      // 当前科室 ID
    var DEPT_LIST = [];    // 医生关联科室列表
    var ROOM_BOUND = null; // 当前绑定诊室 {id, name}
    var ROOM_DATA = [];    // 大屏列表缓存
    var CALL_POP_TIMER = null; // 叫号悬浮窗轮询定时器
    var CALL_POOL_LIMIT = 20;  // 号源池已加载条数（滚动到末尾分段加载，上限 200）

    /* ==================== 会话记忆（悬浮窗开合 + 位置，跨页面保持） ==================== */
    function memKey() {
        return { u: document.body.getAttribute('data-uid') || '', s: document.body.getAttribute('data-sid') || '' };
    }
    function readCallPopOpen() {
        try {
            var k = memKey();
            var sv = JSON.parse(sessionStorage.getItem('clinic_doc_callpop') || 'null');
            return !!(sv && String(sv.u) === k.u && String(sv.s) === k.s && sv.open);
        } catch (e) { return false; }
    }
    function saveCallPopOpen(open) {
        try {
            var k = memKey();
            sessionStorage.setItem('clinic_doc_callpop', JSON.stringify({ u: k.u, s: k.s, open: !!open }));
        } catch (e) { /* 忽略 */ }
    }
    function readCallPopPos() {
        try {
            var k = memKey();
            var sv = JSON.parse(sessionStorage.getItem('clinic_doc_callpop_pos') || 'null');
            if (sv && String(sv.u) === k.u && String(sv.s) === k.s && typeof sv.x === 'number' && typeof sv.y === 'number') {
                return { x: sv.x, y: sv.y };
            }
        } catch (e) { /* 忽略 */ }
        return null;
    }
    function saveCallPopPos(x, y) {
        try {
            var k = memKey();
            sessionStorage.setItem('clinic_doc_callpop_pos', JSON.stringify({ u: k.u, s: k.s, x: x, y: y }));
        } catch (e) { /* 忽略 */ }
    }
    function pad3(n) {
        n = parseInt(n, 10) || 0;
        return n < 10 ? '00' + n : (n < 100 ? '0' + n : '' + n);
    }

    /* ==================== 悬浮窗模式记忆（精简版/完整版，跟随医生本地持久化） ====================
       用 localStorage 按医生 uid 保存，退出登录/重启浏览器后仍记住所选版本 */
    function readCallPopMode() {
        try {
            var sv = JSON.parse(localStorage.getItem('clinic_doc_callpop_mode') || 'null');
            var u = document.body.getAttribute('data-uid') || '';
            return (sv && String(sv.u) === u && sv.mode === 'mini') ? 'mini' : 'full';
        } catch (e) { return 'full'; }
    }
    function saveCallPopMode(mode) {
        try {
            localStorage.setItem('clinic_doc_callpop_mode',
                JSON.stringify({ u: document.body.getAttribute('data-uid') || '', mode: mode === 'mini' ? 'mini' : 'full' }));
        } catch (e) { /* 忽略 */ }
    }

    /* ==================== 初始化 ==================== */
    function init() {
        // 恢复本次登录会话已绑定的大屏（sessionStorage），心跳由全局 room_heartbeat.js 维持
        var bound = (window.Clinic && Clinic.roomHeartbeat) ? Clinic.roomHeartbeat.current() : null;
        if (bound && bound.room_id) {
            ROOM_BOUND = { id: bound.room_id, name: bound.room_name || '' };
        }
        loadDepts();
        bindOutsideClick();
        // 恢复叫号悬浮窗（跨患者切换保持打开）
        if (ROOM_BOUND && ROOM_BOUND.id && readCallPopOpen()) {
            setTimeout(openCallPop, 60);
        }
    }

    /* 加载医生科室列表（与医生工作站一致：接口按权限过滤） */
    function loadDepts() {
        Clinic.get('/api/doctor?action=depts', null, {
            onSuccess: function (json) {
                DEPT_LIST = json.data.list || [];
                CUR_DEPT = parseInt(json.data.current || document.body.getAttribute('data-dept') || 0, 10) || 0;
                if (!CUR_DEPT && DEPT_LIST.length) CUR_DEPT = DEPT_LIST[0].id;
                renderDeptTitle();
                // 自动加载房间绑定状态，叫号按钮实时显示已绑定诊室
                loadRoomList();
            },
        });
    }

    /* 渲染标题「医生工作站-科室」：科室名做成胶囊徽章样式，
       多科室权限 → 主色胶囊可点击切换；单科室 → 灰色不可点击 */
    function renderDeptTitle() {
        var el = document.getElementById('docWorkDept');
        if (!el) return;
        var cur = null;
        DEPT_LIST.forEach(function (d) { if (d.id === CUR_DEPT) cur = d; });
        var name = cur ? cur.name : '未选科室';
        el.innerHTML = '<span class="doc-dept-name"></span>';
        el.querySelector('.doc-dept-name').textContent = name;
        el.title = DEPT_LIST.length > 1 ? '点击切换科室' : '您仅有该科室权限，不可切换';
        // 多科室权限 → 可点击；单科室 → 无反应（不绑点击）
        if (DEPT_LIST.length > 1) {
            el.className = 'doc-work-dept clickable';
            el.onclick = function () { openDeptSwitch(); };
        } else {
            el.className = 'doc-work-dept';
            el.onclick = null;
        }
    }

    /* ==================== 科室切换 ==================== */
    function openDeptSwitch() {
        if (DEPT_LIST.length <= 1) { Clinic.toast.info('您仅有当前科室的权限，无需切换'); return; }
        Clinic.deptPicker.open({
            mode: 'select',
            depts: DEPT_LIST,
            currentId: CUR_DEPT,
            onSelect: function (d) {
                doSwitchDept(d.id);
            },
        });
    }

    function doSwitchDept(id) {
        if (id === CUR_DEPT) return;
        Clinic.ajax('/api/doctor', { action: 'set_dept', dept_id: id }, {
            onSuccess: function (json) {
                // 手动切换科室：自动解绑当前叫号大屏（静默），避免换科后大屏仍挂原科室叫号
                if (ROOM_BOUND && ROOM_BOUND.id) {
                    doUnbindRoom(ROOM_BOUND.id, true);
                }
                CUR_DEPT = id;
                // 同步记忆到会话存储（与医生工作站工作台同一记忆键：绑定账号+会话ID）
                try {
                    sessionStorage.setItem('clinic_doc_dept', JSON.stringify({
                        u: document.body.getAttribute('data-uid') || '',
                        s: document.body.getAttribute('data-sid') || '',
                        d: id,
                    }));
                } catch (e) { /* 忽略 */ }
                Clinic.toast.success(json.msg || '科室已切换');
                renderDeptTitle();
                loadRoomList();
                // 切换科室后进入医生工作站（新）：工作台自动读取已选科室并弹出候诊队列
                setTimeout(function () { location.href = '/doctor/emr'; }, 600);
            },
        });
    }

    /* ==================== 工具箱：加号 ====================
       沿用旧医生工作站 openAddSlot：仅限号科室、按身份证加号 */
    function openAddSlot() {
        var cur = null;
        DEPT_LIST.forEach(function (d) { if (d.id === CUR_DEPT) cur = d; });
        if (!cur) { Clinic.toast.warning('请先选择科室'); return; }
        if (!cur.limited) { Clinic.toast.warning('该科室为不限号科室，无需加号'); return; }
        Clinic.modal.open(
            '<div class="form-group"><label class="form-label">科室</label>' +
            '<input class="input" value="' + cur.name + '" disabled></div>' +
            '<div class="form-group"><label class="form-label">患者身份证号码 <span class="req">*</span></label>' +
            '<input class="input" id="asCard" placeholder="18位身份证号"></div>' +
            '<div class="form-group"><label class="form-label">患者姓名 <span class="req">*</span></label>' +
            '<input class="input" id="asName" placeholder="请输入患者姓名"></div>' +
            '<div class="fs-12 text-muted">加号成功后，仅该患者凭本人身份证在挂号处可挂此科室号源。</div>',
            {
                title: '医生加号',
                size: 'modal-sm',
                buttons: [
                    { text: '取消', cls: 'btn-outline' },
                    {
                        text: '确认加号', cls: 'btn-primary', autoClose: false,
                        onClick: function () {
                            var card = document.getElementById('asCard').value.trim().toUpperCase();
                            if (!Clinic.validate.idCard(card)) { Clinic.toast.warning('请输入正确的18位身份证号码'); return; }
                            var name = document.getElementById('asName').value.trim();
                            if (!name) { Clinic.toast.warning('请填写患者姓名'); return; }
                            Clinic.ajax('/api/doctor', {
                                action: 'add_slot',
                                dept_id: CUR_DEPT,
                                id_card: card,
                                name: name,
                            }, {
                                onSuccess: function (json) {
                                    Clinic.toast.success(json.msg);
                                    Clinic.modal.close();
                                },
                            });
                        },
                    },
                ],
            }
        );
    }

    /* ==================== 工具箱：患者查询 ====================
       沿用旧医生工作站 openPatientSearch / doPatientSearch */
    function openPatientSearch() {
        Clinic.modal.open(
            '<div class="form-group"><label class="form-label">患者ID / 身份证号 / 姓名</label>' +
            '<input class="input" id="psKw" placeholder="请输入患者ID / 身份证号 / 姓名" ' +
            'onkeydown="if(event.key===\'Enter\')Clinic.docTools.doPatientSearch()"></div>' +
            '<div id="psResult" class="fs-13"></div>',
            {
                title: '患者查询',
                size: 'modal-sm',
                buttons: [
                    { text: '关闭', cls: 'btn-outline' },
                    { text: '查 询', cls: 'btn-primary', autoClose: false, onClick: doPatientSearch },
                ],
            }
        );
        setTimeout(function () {
            var el = document.getElementById('psKw');
            if (el) el.focus();
        }, 80);
    }

    function doPatientSearch() {
        var kw = document.getElementById('psKw').value.trim();
        if (!kw) { Clinic.toast.warning('请输入患者ID / 身份证号 / 姓名'); return; }
        var box = document.getElementById('psResult');
        box.innerHTML = '<div class="spinner" style="border-top-color:var(--primary);width:24px;height:24px;margin:10px auto"></div>';
        Clinic.get('/api/patient?action=search&kw=' + encodeURIComponent(kw), null, {
            onSuccess: function (json) {
                var list = json.data.list || [];
                if (!list.length) { box.innerHTML = '<div class="text-muted">未检索到该患者</div>'; return; }
                box.innerHTML = '<div class="fs-13 text-muted mb-8">检索到 ' + list.length + ' 位患者，点击查看全部就诊历史</div>' +
                    list.map(function (p) {
                        return '<div class="dd-item" style="cursor:pointer" onclick="showPatientHistory(\'' + Clinic.escHtml(p.patient_no) + '\')">' +
                            '<div class="flex-between"><span class="fw-600">' + Clinic.escHtml(p.name) + '</span>' +
                            '<span class="text-muted fs-12">' + Clinic.escHtml(p.patient_no) + ' ｜ ' + Clinic.escHtml(p.gender) + '/' + Clinic.escHtml(p.age_fmt || Clinic.validate.formatAge(p.birth_date)) + '</span></div></div>';
                    }).join('');
            },
        });
    }

    /* ==================== 工具箱下拉 ==================== */
    function toggleToolbox() {
        var box = document.getElementById('docToolbox');
        if (!box) return;
        box.style.display = box.style.display === 'none' ? 'block' : 'none';
    }

    /* ==================== 叫号大屏绑定（工具箱左侧） ====================
       功能与旧医生工作站叫号设置一致：显示当前科室可用大屏，可绑定/解绑 */
    function loadRoomList() {
        if (!CUR_DEPT) { renderRoomList([]); return; }
        Clinic.get('/api/doctor?action=get_available_rooms&dept_id=' + CUR_DEPT, null, {
            onSuccess: function (json) {
                ROOM_BOUND = json.data.bound;
                renderRoomList(json.data.list || []);
            },
            onError: function () { renderRoomList([]); },
        });
    }

    function renderRoomList(list) {
        ROOM_DATA = list;
        var box = document.getElementById('docRoomList');
        var btn = document.getElementById('docCallName');
        if (btn) btn.textContent = ROOM_BOUND ? '叫号：' + ROOM_BOUND.name : '叫号';
        if (!box) return;
        if (!list.length) {
            box.innerHTML = '<div class="fs-13 text-muted text-center" style="padding:16px">该科室暂无大屏配置，请联系管理员在【叫号管理】中新建</div>';
            return;
        }
        var rows = list.map(function (r) {
            var icon = r.status === 'available' ? '🟢' : (r.status === 'bound' ? '🔵' : (r.status === 'occupied' ? '🟡' : '🔴'));
            var disabled = !r.selectable;
            var action = '';
            if (r.status === 'bound') {
                action = 'onclick="Clinic.docTools.unbindRoom(\'' + r.id + '\')"';
            } else if (r.status === 'available') {
                action = 'onclick="Clinic.docTools.bindRoom(\'' + r.id + '\',\'' + r.name.replace(/'/g, "\\'") + '\')"';
            }
            var cls = r.status === 'bound' ? 'style="background:var(--primary-soft);border-radius:6px"' : '';
            var hint = r.status === 'bound' ? '<span class="fs-12 text-primary">（点击解绑）</span>' : '';
            return '<div class="fs-13 flex-between" style="padding:8px 10px;cursor:' + (disabled ? 'not-allowed' : 'pointer') + ';opacity:' + (disabled ? '.55' : '1') + ';border-radius:6px"' + cls + ' ' + action + '>' +
                '<span>' + icon + ' ' + r.name + '</span>' +
                '<span class="fs-12" style="color:' + (r.status === 'offline' ? 'var(--danger)' : 'var(--text-muted)') + '">' + r.status_text + ' ' + hint + '</span></div>';
        }).join('');
        box.innerHTML = rows;
    }

    function toggleRoomList() {
        // 已绑定诊室：点击叫号按钮 → 打开/收起叫号悬浮窗
        if (ROOM_BOUND && ROOM_BOUND.id) {
            toggleCallPop();
            return;
        }
        // 未绑定诊室：保持原逻辑，下拉显示可绑定的诊室列表
        var box = document.getElementById('docRoomList');
        if (!box) return;
        if (box.style.display === 'none') {
            loadRoomList();
            box.style.display = 'block';
        } else {
            box.style.display = 'none';
        }
    }

    function bindRoom(roomId, roomName) {
        if (ROOM_BOUND && String(ROOM_BOUND.id) !== String(roomId)) {
            Clinic.modal.confirm(
                '当前已绑定「<strong>' + ROOM_BOUND.name + '</strong>」诊室大屏。<br>' +
                '是否将叫号大屏<strong>从「' + ROOM_BOUND.name + '」切换到「' + (roomName || '新诊室') + '」</strong>？<br>' +
                '<span class="fs-13 text-muted">切换后，该医生的叫号信息将显示在「' + (roomName || '新诊室') + '」大屏上。</span>',
                function () { doBind(roomId, roomName); },
                { title: '切换诊室大屏', okText: '确认切换', cls: 'btn-primary' }
            );
            return;
        }
        doBind(roomId, roomName);
    }

    function doBind(roomId, roomName) {
        Clinic.ajax('/api/doctor', { action: 'bind_room', room_id: roomId }, {
            onSuccess: function (json) {
                Clinic.toast.success(json.msg);
                var rid = json.data && json.data.room_id;
                var rname = (json.data && json.data.room_name) || roomName || '';
                ROOM_BOUND = rid ? { id: rid, name: rname } : null;
                // 绑定信息持久化到会话 + 启动全局心跳（离开工作站/刷新页面不自动解绑）
                if (window.Clinic && Clinic.roomHeartbeat && rid) {
                    Clinic.roomHeartbeat.remember(rid, rname);
                }
                loadRoomList();
                // 绑定成功后自动打开叫号悬浮窗
                if (ROOM_BOUND && ROOM_BOUND.id) openCallPop();
            },
            // 失败提示由 Clinic.ajax 统一 toast（此处不再重复弹出，避免双重提醒）
        });
    }

    /* 解绑大屏（内部静默版：不弹确认框，供「切换科室自动解绑」调用）
     * @param roomId  诊室 ID
     * @param silent  静默（true 不弹成功提示，切换科室场景用） */
    function doUnbindRoom(roomId, silent) {
        Clinic.ajax('/api/doctor', { action: 'unbind_room', room_id: roomId }, {
            onSuccess: function (json) {
                if (!silent) Clinic.toast.success(json.msg);
                if (window.Clinic && Clinic.roomHeartbeat) Clinic.roomHeartbeat.forget();
                ROOM_BOUND = null;
                closeCallPop();
                loadRoomList();
            },
        });
    }

    function unbindRoom(roomId) {
        Clinic.modal.confirm('确认解除与当前大屏的绑定？', function () {
            doUnbindRoom(roomId, false);
        }, { title: '解绑确认', okText: '确认解绑' });
    }

    /* ==================== 叫号悬浮窗（可拖动，绑定诊室后显示） ====================
       显示：诊室名称 / 当前就诊 / 再次叫号 / 过号 / 下一位 / 候诊号源池 / 解绑大屏。
       病历联动：点「下一位」自动认领号源池首位并跳转该患者病历页（有脏数据先拦截保存）。 */
    function callPopEl() { return document.getElementById('docCallPop'); }

    function toggleCallPop() {
        if (callPopEl()) closeCallPop();
        else openCallPop();
    }

    function openCallPop() {
        if (!ROOM_BOUND || !ROOM_BOUND.id) { toggleRoomList(); return; }
        if (callPopEl()) return;
        var mini = readCallPopMode() === 'mini';
        var pop = document.createElement('div');
        pop.className = 'doc-call-pop' + (mini ? ' doc-call-mini' : '');
        pop.id = 'docCallPop';
        var pos = readCallPopPos();
        if (pos) {
            pop.style.left = pos.x + 'px';
            pop.style.top = pos.y + 'px';
        } else {
            pop.style.right = '16px';
            pop.style.bottom = '64px';
        }
        pop.innerHTML = mini ? miniPopHtml() : fullPopHtml();
        document.body.appendChild(pop);
        saveCallPopOpen(true);
        CALL_POOL_LIMIT = 20;   // 重新打开时重置号源池加载量
        bindCallPopDrag(pop);
        bindCallPopActions(pop);
        refreshCallPanel();
        if (CALL_POP_TIMER) clearInterval(CALL_POP_TIMER);
        CALL_POP_TIMER = setInterval(refreshCallPanel, 10000);
    }

    /* 完整版悬浮窗 HTML（当前就诊/下一位/叫号按钮/完整号源列表/解绑） */
    function fullPopHtml() {
        return '<div class="doc-call-pop-head">' +
            '  <span class="doc-call-pop-title">📢 叫号 · ' + Clinic.escHtml(ROOM_BOUND.name || '') + '</span>' +
            '  <span class="doc-call-pop-tools">' +
            '    <span class="doc-call-pop-x" data-act="mini" title="最小化（切换到精简版）">-</span>' +
            '    <span class="doc-call-pop-x" data-act="hide" title="关闭">x</span>' +
            '  </span>' +
            '</div>' +
            '<div class="doc-call-pop-body">' +
            '  <div class="doc-call-block">' +
            '    <div class="doc-call-label">当前就诊</div>' +
            '    <div class="doc-call-cur" id="dcpCur">加载中…</div>' +
            '    <div class="doc-call-cur-sub" id="dcpCurSub"></div>' +
            '  </div>' +
            '  <div class="doc-call-block">' +
            '    <div class="doc-call-label">下一位</div>' +
            '    <div class="doc-call-next-name" id="dcpNext">—</div>' +
            '  </div>' +
            '  <div class="doc-call-actions">' +
            '    <button type="button" class="btn btn-outline btn-sm" id="dcpRepeat" title="重复呼叫当前就诊患者（防止患者没听到）">🔁 再次叫号</button>' +
            '    <button type="button" class="btn btn-warning btn-sm" id="dcpMiss" title="当前患者过号，自动呼叫下一位">⏭ 过号</button>' +
            '    <button type="button" class="btn btn-primary btn-sm" id="dcpNextBtn" title="呼叫下一位患者并打开其病历">⬇ 下一位</button>' +
            '  </div>' +
            '  <div class="doc-call-pool">' +
            '    <div class="doc-call-pool-title">候诊号源 <b id="dcpCount">0</b> 人</div>' +
            '    <div class="doc-call-pool-list" id="dcpPoolList"><div class="fs-12 text-muted">加载中…</div></div>' +
            '  </div>' +
            '  <div class="doc-call-foot">' +
            '    <span class="doc-call-status" id="dcpStatus"></span>' +
            '    <button type="button" class="btn btn-outline btn-sm" data-act="unbind">解绑大屏</button>' +
            '  </div>' +
            '</div>';
    }

    /* 精简版悬浮窗 HTML：标题（解绑/最大化/关闭）+ 当前就诊 + 下一位 + 三个叫号按钮 */
    function miniPopHtml() {
        return '<div class="doc-call-pop-head">' +
            '  <span class="doc-call-pop-title">📢 ' + Clinic.escHtml(ROOM_BOUND.name || '') + '</span>' +
            '  <span class="doc-call-pop-tools">' +
            '    <span class="doc-call-pop-x" data-act="unbind" title="解绑大屏">⊘</span>' +
            '    <span class="doc-call-pop-x" data-act="restore" title="最大化（恢复完整版）">+</span>' +
            '    <span class="doc-call-pop-x" data-act="hide" title="关闭">x</span>' +
            '  </span>' +
            '</div>' +
            '<div class="doc-call-pop-body doc-call-mini-body">' +
            '  <div class="doc-call-mini-row">' +
            '    <span class="doc-call-mini-label">当前</span>' +
            '    <span class="doc-call-mini-val" id="dcpCur">—</span>' +
            '  </div>' +
            '  <div class="doc-call-mini-row">' +
            '    <span class="doc-call-mini-label">下一位</span>' +
            '    <span class="doc-call-mini-val" id="dcpNext">—</span>' +
            '  </div>' +
            '  <div class="doc-call-actions">' +
            '    <button type="button" class="btn btn-outline btn-sm" id="dcpRepeat" title="重复呼叫当前就诊患者">🔁 重呼</button>' +
            '    <button type="button" class="btn btn-warning btn-sm" id="dcpMiss" title="过号并自动呼叫下一位">⏭ 过号</button>' +
            '    <button type="button" class="btn btn-primary btn-sm" id="dcpNextBtn" title="呼叫下一位患者并打开其病历">⬇ 下一位</button>' +
            '  </div>' +
            '</div>';
    }

    function closeCallPop() {
        var pop = callPopEl();
        if (pop) pop.remove();
        saveCallPopOpen(false);
        if (CALL_POP_TIMER) { clearInterval(CALL_POP_TIMER); CALL_POP_TIMER = null; }
    }

    /* 悬浮窗拖动：按住标题栏可在页面上任意拖动，松开记忆位置 */
    function bindCallPopDrag(pop) {
        var head = pop.querySelector('.doc-call-pop-head');
        var dragging = false, offX = 0, offY = 0;
        head.addEventListener('mousedown', function (e) {
            if (e.target.closest('.doc-call-pop-x')) return;
            dragging = true;
            offX = e.clientX - pop.getBoundingClientRect().left;
            offY = e.clientY - pop.getBoundingClientRect().top;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var x = Math.max(0, Math.min(e.clientX - offX, window.innerWidth - 80));
            var y = Math.max(0, Math.min(e.clientY - offY, window.innerHeight - 80));
            pop.style.left = x + 'px';
            pop.style.top = y + 'px';
            pop.style.right = 'auto';
            pop.style.bottom = 'auto';
        });
        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            saveCallPopPos(parseInt(pop.style.left, 10) || 0, parseInt(pop.style.top, 10) || 0);
        });
    }

    function bindCallPopActions(pop) {
        // 标题栏/底部操作按钮统一走 data-act：mini（进精简）/ restore（恢复完整）/
        // unbind（解绑大屏）/ hide（收起·最小化，点击顶栏「叫号」重新打开）
        pop.querySelectorAll('[data-act]').forEach(function (b) {
            b.addEventListener('click', function () {
                var act = b.getAttribute('data-act');
                if (act === 'mini') switchCallPopMode('mini');
                else if (act === 'restore') switchCallPopMode('full');
                else if (act === 'unbind') { if (ROOM_BOUND) unbindRoom(ROOM_BOUND.id); }
                else closeCallPop();
            });
        });
        pop.querySelector('#dcpNextBtn').addEventListener('click', doCallNext);
        pop.querySelector('#dcpMiss').addEventListener('click', doCallMiss);
        pop.querySelector('#dcpRepeat').addEventListener('click', doCallRepeat);
        // 号源池滚动到底部 → 分段加载更多
        var poolList = pop.querySelector('#dcpPoolList');
        if (poolList) {
            poolList.addEventListener('scroll', function () {
                if (poolList.scrollTop + poolList.clientHeight >= poolList.scrollHeight - 40) {
                    loadMorePool();
                }
            });
        }
    }

    /* 切换悬浮窗模式（精简版/完整版），保存医生记忆并立即按新版本重建 */
    function switchCallPopMode(mode) {
        saveCallPopMode(mode);
        closeCallPop();
        openCallPop();
    }

    /* 号源池分段加载：滚动到末尾时每次多加载 20 条（上限 200） */
    function loadMorePool() {
        if (CALL_POOL_LIMIT >= 200) return;
        CALL_POOL_LIMIT = Math.min(200, CALL_POOL_LIMIT + 20);
        refreshCallPanel();
    }

    /* 当前病历页就诊混淆码（工作台空态无 #visitId） */
    function currentVisitCode() {
        var el = document.getElementById('visitId');
        return el ? el.value : '';
    }

    /* 脏数据拦截：复用病历系统统一的「未保存修改」校验（打印/开单/切换患者同款逻辑） */
    function guardDirty() {
        if (window.Clinic && Clinic.emr && Clinic.emr.isDirty && Clinic.emr.isDirty()) {
            Clinic.toast.warning('当前病历有未保存的修改，请先点击「💾 保存」后再叫号下一位');
            return true;
        }
        return false;
    }

    function doCallNext() {
        if (!ROOM_BOUND || !ROOM_BOUND.id) { Clinic.toast.warning('请先绑定大屏诊室'); return; }
        if (guardDirty()) return;
        Clinic.ajax('/api/doctor', { action: 'call_next', room_id: ROOM_BOUND.id }, {
            onSuccess: function (json) {
                refreshCallPanel();
                var v = json.data && json.data.visit;
                if (v && v.visit_code && currentVisitCode() !== v.visit_code) {
                    // 病历联动：自动切换到该患者病历页（等同于手动点击候诊列表该患者）
                    location.href = '/doctor/emr?visit_id=' + v.visit_code;
                } else {
                    Clinic.toast.success(json.msg);
                }
            },
        });
    }

    function doCallMiss() {
        if (!ROOM_BOUND || !ROOM_BOUND.id) { Clinic.toast.warning('请先绑定大屏诊室'); return; }
        if (guardDirty()) return;
        Clinic.modal.confirm(
            '确认将当前就诊患者标记为「<strong>过号</strong>」？<br>' +
            '<span class="fs-13 text-muted">过号后自动呼叫下一位患者，该患者本次就诊不再进入号源队列（大屏显示（过号）标记）。</span>',
            function () {
                Clinic.ajax('/api/doctor', { action: 'call_miss', room_id: ROOM_BOUND.id }, {
                    onSuccess: function (json) {
                        refreshCallPanel();
                        var v = json.data && json.data.visit;
                        if (v && v.visit_code && currentVisitCode() !== v.visit_code) {
                            location.href = '/doctor/emr?visit_id=' + v.visit_code;
                        } else {
                            Clinic.toast.success(json.msg);
                        }
                    },
                });
            },
            { title: '过号确认', okText: '确认过号' }
        );
    }

    function doCallRepeat() {
        if (!ROOM_BOUND || !ROOM_BOUND.id) { Clinic.toast.warning('请先绑定大屏诊室'); return; }
        Clinic.ajax('/api/doctor', { action: 'call_repeat', room_id: ROOM_BOUND.id }, {
            onSuccess: function (json) { Clinic.toast.success(json.msg); refreshCallPanel(); },
        });
    }

    /* 轮询刷新悬浮窗数据（每 10 秒 + 每次动作后；携带当前已加载条数） */
    function refreshCallPanel() {
        var pop = callPopEl();
        if (!pop || !ROOM_BOUND || !ROOM_BOUND.id) return;
        Clinic.get('/api/doctor?action=call_panel&limit=' + CALL_POOL_LIMIT, null, {
            loading: false,
            onSuccess: function (json) { renderCallPop(json.data); },
            onError: function () { /* 静默，下次轮询自动恢复 */ },
        });
    }

    function renderCallPop(d) {
        var pop = callPopEl();
        if (!pop) return;
        if (!d.bound) {
            // 后端判定绑定失效（医生心跳超时 / 被管理员释放等）：
            // 收起悬浮窗并重置本地绑定，引导医生重新绑定
            closeCallPop();
            Clinic.toast.warning('大屏绑定已失效，请重新绑定');
            if (window.Clinic && Clinic.roomHeartbeat) Clinic.roomHeartbeat.forget();
            ROOM_BOUND = null;
            loadRoomList();
            return;
        }
        var cur = d.current, next = d.next;
        var isMini = pop.classList.contains('doc-call-mini');
        var titleEl = pop.querySelector('.doc-call-pop-title');
        if (titleEl) titleEl.textContent = (isMini ? '📢 ' : '📢 叫号 · ') + (d.room && d.room.name ? d.room.name : '');
        var curEl = pop.querySelector('#dcpCur');
        var curSubEl = pop.querySelector('#dcpCurSub');
        if (curEl) {
            if (cur) {
                curEl.textContent = cur.name;
                if (curSubEl) {
                    var st = cur.status === 'finished' ? '已诊毕' : (cur.status === 'visiting' ? '就诊中' : '候诊');
                    curSubEl.textContent = '第' + pad3(cur.visit_seq) + '号' + ' · ' + st + (cur.missed ? ' · 过号' : '');
                }
            } else {
                curEl.textContent = '暂无';
                if (curSubEl) curSubEl.textContent = '点击「⬇ 下一位」呼叫候诊患者';
            }
        }
        var nextEl = pop.querySelector('#dcpNext');
        if (nextEl) nextEl.textContent = next ? next.name + '（第' + pad3(next.visit_seq) + '号）' : '—';
        var countEl = pop.querySelector('#dcpCount');
        if (countEl) countEl.textContent = d.pool_count || 0;
        var poolListEl = pop.querySelector('#dcpPoolList');
        if (poolListEl) {
            // 完整显示已加载号源（分段加载），保留滚动位置（实时刷新不跳顶）
            var items = [];
            (d.pool || []).forEach(function (p) {
                items.push('<div class="doc-call-pool-item"><span class="doc-call-pool-seq">' + pad3(p.visit_seq) + '</span>' +
                    '<span>' + Clinic.escHtml(p.name) + '</span></div>');
            });
            (d.missed || []).slice(0, 4).forEach(function (p) {
                items.push('<div class="doc-call-pool-item"><span class="doc-call-pool-seq">' + pad3(p.visit_seq) + '</span>' +
                    '<span>' + Clinic.escHtml(p.name) + '</span><span class="doc-call-pool-missed">（过号）</span></div>');
            });
            if (d.has_more) {
                items.push('<div class="doc-call-pool-more">继续向下滚动加载更多…</div>');
            }
            var st = poolListEl.scrollTop;
            poolListEl.innerHTML = items.join('') || '<div class="fs-12 text-muted">暂无候诊患者</div>';
            poolListEl.scrollTop = st;
        }
        var statusEl = pop.querySelector('#dcpStatus');
        if (statusEl) statusEl.textContent = (d.room && d.room.dept_name) ? d.room.dept_name + ' · 数据实时同步' : '';
    }

    /* ==================== 点击下拉框外关闭 ==================== */
    function bindOutsideClick() {
        document.addEventListener('click', function (e) {
            ['docRoomList', 'docToolbox'].forEach(function (id) {
                var box = document.getElementById(id);
                if (!box || box.style.display === 'none') return;
                var btnId = id === 'docRoomList' ? 'docCallBtn' : 'docToolboxBtn';
                if (!e.target.closest('#' + btnId) && !e.target.closest('#' + id)) {
                    box.style.display = 'none';
                }
            });
        });
    }

    /* 页面就绪初始化 */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        toggleToolbox: toggleToolbox,
        openDeptSwitch: openDeptSwitch,
        openAddSlot: openAddSlot,
        openPatientSearch: openPatientSearch,
        doPatientSearch: doPatientSearch,
        toggleRoomList: toggleRoomList,
        bindRoom: bindRoom,
        unbindRoom: unbindRoom,
        openCallPop: openCallPop,
        closeCallPop: closeCallPop,
        refreshCallPanel: refreshCallPanel,
    };
})();
