/**
 * ============================================================
 * doctor_tools.js v1.1.0 — 医生工作站（新）顶栏工具
 * ============================================================
 * 说明：以顶栏按钮形式集成到医生工作站（新）顶部标题区域：
 * 1. 标题「医生工作站-科室」：科室可点击切换（仅多科室权限可切，
 *    单科室点击无反应；后端 set_dept 亦做权限强校验）
 * 2. 工具箱下拉：加号 / 切换科室 / 患者查询 / 模板管理
 * 3. 叫号大屏绑定（工具箱左侧），绑定信息存会话 + 全局心跳保活
 * 依赖：ajax.js / modal.js / deptpicker.js / toast.js / validate / room_heartbeat.js
 * ============================================================ */

window.Clinic = window.Clinic || {};

Clinic.docTools = (function () {

    var CUR_DEPT = 0;      // 当前科室 ID
    var DEPT_LIST = [];    // 医生关联科室列表
    var ROOM_BOUND = null; // 当前绑定诊室 {id, name}
    var ROOM_DATA = [];    // 大屏列表缓存

    /* ==================== 初始化 ==================== */
    function init() {
        // 恢复本次登录会话已绑定的大屏（sessionStorage），心跳由全局 room_heartbeat.js 维持
        var bound = (window.Clinic && Clinic.roomHeartbeat) ? Clinic.roomHeartbeat.current() : null;
        if (bound && bound.room_id) {
            ROOM_BOUND = { id: bound.room_id, name: bound.room_name || '' };
        }
        loadDepts();
        bindOutsideClick();
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
                loadRoomList();
            },
        });
    }

    function unbindRoom(roomId) {
        Clinic.modal.confirm('确认解除与当前大屏的绑定？', function () {
            doUnbindRoom(roomId, false);
        }, { title: '解绑确认', okText: '确认解绑' });
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
    };
})();
