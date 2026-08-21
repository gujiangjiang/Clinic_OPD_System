/**
 * ============================================================
 * call.js v1.2.0 — 诊室门口叫号屏幕逻辑
 * ============================================================
 * 说明：
 * 1. 右上角实时时钟（年月日 + 星期 + 时分秒）
 * 2. 每 10 秒轮询 /api/doctor?action=call_queue 刷新：
 *    就诊中患者、下一位患者、候诊人数、出诊医生
 * 3. 科室完全跟随医生端选择（服务端 current_dept_id）：
 *    医生工作站切换科室后，本屏幕自动显示对应科室，
 *    不再在大屏端提供科室切换按钮。
 * 4. 就诊中 / 下一位 只显示姓名与号次（第XXX号），
 *    预留「复诊」标记（is_followup 为真时显示）。
 * 依赖：ajax.js、datetime.js
 * ============================================================ */

(function () {
    /* ---------- 实时时钟 ---------- */
    Clinic.datetime.clock('#callClock', 'Y年m月d日');

    function pad3(n) {
        n = parseInt(n, 10) || 0;
        return n < 10 ? '00' + n : (n < 100 ? '0' + n : '' + n);
    }

    /* ---------- 渲染队列数据 ---------- */
    function render(d) {
        document.getElementById('callDept').textContent = '· ' + d.dept.name + ' ·';
        var cur = d.current, next = d.next;

        document.getElementById('callNowName').textContent = cur ? cur.name : '暂无就诊患者';
        document.getElementById('callNowSub').innerHTML = cur
            ? '第' + pad3(cur.visit_seq) + '号' + (cur.is_followup ? ' <span class="call-followup">复诊</span>' : '')
            : (d.waiting > 0 ? '候诊 ' + d.waiting + ' 人' : '当前无患者就诊');

        document.getElementById('callNextName').textContent = next ? next.name : '—';
        document.getElementById('callNextSub').innerHTML = next
            ? '第' + pad3(next.visit_seq) + '号' + (next.is_followup ? ' <span class="call-followup">复诊</span>' : '')
            : (d.waiting > 0 ? '候诊 ' + d.waiting + ' 人' : '暂无候诊患者');

        // 医生介绍（第一位出诊医生）
        var doc = (d.doctors && d.doctors.length) ? d.doctors[0] : null;
        var photo = document.getElementById('docPhoto');
        if (doc && doc.photo) {
            // 路径规范化为根绝对路径：接口返回相对 public 的 uploads/...，
            // 直接拼接会被浏览器按当前页面层级解析（/doctor/call 下变成 /doctor/uploads/... 404）
            var src = doc.photo.charAt(0) === '/' ? doc.photo : '/' + doc.photo;
            photo.innerHTML = '<img src="' + src + '" alt="医生照片">';
        } else {
            photo.textContent = '👨‍⚕️';
        }
        document.getElementById('docName').textContent = doc ? (doc.name + (doc.emp_no ? '（' + doc.emp_no + '）' : '')) : '医生出诊中';
        document.getElementById('docTitle').textContent = doc ? (doc.title || '') : '';
        document.getElementById('docIntro').textContent = doc ? (doc.intro || '') : '';
    }

    /* ---------- 轮询刷新（科室由服务端按医生端选择解析，大屏端不再传 dept_id） ---------- */
    function refresh() {
        Clinic.get('/api/doctor?action=call_queue', null, {
            onSuccess: function (json) {
                render(json.data);
            },
        });
    }

    /* 初始化：首次轮询 + 每 10 秒自动刷新 */
    refresh();
    setInterval(refresh, 10000);
})();
