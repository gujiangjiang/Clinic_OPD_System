/**
 * ============================================================
 * call.js v1.1.0 — 诊室门口叫号屏幕逻辑
 * ============================================================
 * 说明：
 * 1. 右上角实时时钟（年月日 + 星期 + 时分秒）
 * 2. 每 10 秒轮询 /api/doctor?action=call_queue 刷新：
 *    就诊中患者、下一位患者、候诊人数、出诊医生
 * 3. 多科室医生可通过顶部按钮切换科室
 * 依赖：ajax.js、datetime.js
 * ============================================================ */

(function () {
    var deptId = parseInt(document.getElementById('callDeptId').value, 10) || 0;
    var deptList = [];

    /* ---------- 实时时钟 ---------- */
    Clinic.datetime.clock('#callClock', 'Y年m月d日');

    /* ---------- 加载医生关联科室（切换用） ---------- */
    function loadDepts() {
        Clinic.get('/api/doctor?action=depts', null, {
            onSuccess: function (json) {
                deptList = json.data.list || [];
                var box = document.getElementById('callDeptOpts');
                // 医生未关联科室时给出明确提示，避免一直停留在「正在加载科室…」
                if (!deptList.length) {
                    document.getElementById('callDept').textContent = '当前医生未关联科室';
                    return;
                }
                // 未指定科室（dept_id=0）或指定科室不在列表中时，自动默认第一个科室
                var valid = false;
                for (var i = 0; i < deptList.length; i++) {
                    if (deptList[i].id === deptId) { valid = true; break; }
                }
                if (!valid) deptId = deptList[0].id;
                // 多科室：显示科室切换按钮
                if (deptList.length > 1) {
                    box.innerHTML = deptList.map(function (d) {
                        return '<button type="button" class="call-dept-btn' + (d.id === deptId ? ' on' : '') + '" ' +
                            'data-id="' + d.id + '">' + d.name + '</button>';
                    }).join('');
                    box.querySelectorAll('.call-dept-btn').forEach(function (b) {
                        b.addEventListener('click', function () {
                            deptId = parseInt(b.getAttribute('data-id'), 10);
                            box.querySelectorAll('.call-dept-btn').forEach(function (x) {
                                x.classList.toggle('on', parseInt(x.getAttribute('data-id'), 10) === deptId);
                            });
                            refresh();
                        });
                    });
                }
                refresh();
            },
        });
    }

    /* ---------- 渲染队列数据 ---------- */
    function render(d) {
        document.getElementById('callDept').textContent = '· ' + d.dept.name + ' ·';
        var cur = d.current, next = d.next;
        document.getElementById('callNowName').textContent = cur ? cur.name : '暂无就诊患者';
        document.getElementById('callNowSub').textContent = cur
            ? (cur.gender + ' / ' + cur.age + '岁 ｜ ' + d.dept.name + ' 第' + pad3(cur.visit_seq) + '号 ｜ ' + cur.flow_no)
            : (d.waiting > 0 ? '候诊 ' + d.waiting + ' 人' : '当前无患者就诊');
        document.getElementById('callNextName').textContent = next ? next.name : '—';
        document.getElementById('callNextSub').textContent = next
            ? (next.gender + ' / ' + next.age + '岁 ｜ 第' + pad3(next.visit_seq) + '号 ｜ 候诊 ' + d.waiting + ' 人')
            : (d.waiting > 0 ? '候诊 ' + d.waiting + ' 人' : '暂无候诊患者');

        // 医生介绍（第一位出诊医生）
        var doc = (d.doctors && d.doctors.length) ? d.doctors[0] : null;
        var photo = document.getElementById('docPhoto');
        if (doc && doc.photo) {
            photo.innerHTML = '<img src="' + doc.photo + '" alt="医生照片">';
        } else {
            photo.textContent = '👨‍⚕️';
        }
        document.getElementById('docName').textContent = doc ? (doc.name + (doc.emp_no ? '（' + doc.emp_no + '）' : '')) : '医生出诊中';
        document.getElementById('docTitle').textContent = doc ? (doc.title || '') : '';
        document.getElementById('docIntro').textContent = doc ? (doc.intro || '') : '';
    }

    function pad3(n) {
        n = parseInt(n, 10) || 0;
        return n < 10 ? '00' + n : (n < 100 ? '0' + n : '' + n);
    }

    /* ---------- 轮询刷新 ---------- */
    function refresh() {
        if (!deptId) return;
        // 请求前先展示当前科室名，避免数据返回前一直显示「正在加载科室…」
        var cur = null;
        deptList.forEach(function (d) { if (d.id === deptId) cur = d; });
        document.getElementById('callDept').textContent = '· ' + (cur ? cur.name : '') + ' ·';
        Clinic.get('/api/doctor?action=call_queue&dept_id=' + deptId, null, {
            onSuccess: function (json) { render(json.data); },
        });
    }

    /* 初始化：先取科室，再启动轮询 */
    loadDepts();
    setInterval(refresh, 10000);
})();
