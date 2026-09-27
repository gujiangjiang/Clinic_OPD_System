/* ============================================================
 * assets/js/search.js — 检索页交互
 * ============================================================ */
(function () {
    'use strict';
    var input = document.getElementById('pvKeyword');
    var btn = document.getElementById('pvSearchBtn');
    var box = document.getElementById('pvResults');
    var empty = document.getElementById('pvEmpty');
    var meta = document.getElementById('pvResultMeta');
    if (!input || !btn) return;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function render(list) {
        box.innerHTML = '';
        if (!list || !list.length) {
            empty.style.display = '';
            empty.querySelector('.pv-empty-title').textContent = '未找到匹配的检查记录';
            meta.style.display = 'none';
            return;
        }
        empty.style.display = 'none';
        meta.style.display = '';
        meta.textContent = '共找到 ' + list.length + ' 条已完成检查记录，点击卡片调阅影像';
        list.forEach(function (s) {
            var el = document.createElement('div');
            el.className = 'pv-study';
            el.innerHTML =
                '<div class="pv-study-head"><span class="pv-study-name">' + esc(s.name) + '</span>' +
                '<span class="pv-study-sex">' + esc(s.gender) + ' / ' + esc(s.age) + '</span>' +
                '<span class="pv-mod">' + esc(s.modality) + '</span></div>' +
                '<div class="pv-study-desc">' + esc(s.description || '影像检查') + '</div>' +
                '<div class="pv-study-rows">' +
                '<div><b>患者号：</b>' + esc(s.patient_id) + '　<b>门诊号：</b>' + esc(s.outpatient_no || '—') + '</div>' +
                '<div><b>检查号：</b>' + esc(s.accession_no || '—') + '</div>' +
                '<div><b>检查时间：</b>' + esc(s.study_date || '—') + '　<b>设备：</b>' + esc(s.station_name || '—') + '</div>' +
                '</div>' +
                '<div class="pv-study-foot"><span class="pv-study-status">' + esc(s.status_name || '已完成') + '</span>' +
                '<span class="pv-study-open">打开影像 →</span></div>';
            el.addEventListener('click', function () { location.href = PvApi.viewerUrl(s.study_uid || s.accession_no); });
            box.appendChild(el);
        });
    }

    function doSearch() {
        btn.disabled = true;
        btn.textContent = '检索中…';
        empty.style.display = 'none';
        PvApi.search(input.value).then(function (j) {
            btn.disabled = false; btn.textContent = '检索';
            if (!j || j.code !== 200) {
                box.innerHTML = '';
                empty.style.display = '';
                empty.querySelector('.pv-empty-title').textContent = (j && j.msg) || '检索失败';
                return;
            }
            render(j.data.list || []);
        }).catch(function () {
            btn.disabled = false; btn.textContent = '检索';
            empty.style.display = '';
            empty.querySelector('.pv-empty-title').textContent = '网络请求失败';
        });
    }

    btn.addEventListener('click', doSearch);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
    input.focus();
})();
