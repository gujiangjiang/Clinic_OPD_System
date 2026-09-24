<?php
/**
 * ============================================================
 * includes/migrating_lock.php — 数据库迁移/切换全站锁定页
 * ============================================================
 * 说明：迁移进行期间所有页面/接口（除 migration 状态接口）被 index.php
 * 拦截至此页。半透明遮罩 + 进度条 + 重新登录按钮；管理员（localStorage
 * 持有迁移令牌）额外显示取消按钮；迁移成功后询问是否切换主库。
 * 前端每 2 秒轮询 /api/migration?action=status 更新进度。
 * ============================================================ */
header('HTTP/1.1 200 OK');
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>系统维护中 · 数据库迁移</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { height: 100%; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
    background: rgba(0, 0, 0, 0.55);
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh; color: #1f2937;
}
.lock-card {
    background: #fff; border-radius: 16px; padding: 36px 44px; width: 460px; max-width: 92vw;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35); text-align: center;
}
.lock-ico { font-size: 44px; line-height: 1; }
.lock-title { font-size: 18px; font-weight: 700; margin: 12px 0 6px; }
.lock-sub { font-size: 13px; color: #6b7280; margin-bottom: 20px; line-height: 1.7; }
.progress-track { height: 10px; background: #e5e7eb; border-radius: 6px; overflow: hidden; margin: 14px 0 8px; }
.progress-bar { height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #6366f1); border-radius: 6px; transition: width .6s ease; }
.progress-meta { font-size: 12px; color: #6b7280; margin-bottom: 16px; }
.progress-meta b { color: #374151; }
.table-now { font-size: 12px; color: #9ca3af; word-break: break-all; margin-bottom: 18px; }
.actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.btn {
    border: 0; border-radius: 8px; padding: 9px 18px; font-size: 13px; cursor: pointer;
    transition: opacity .15s; font-family: inherit;
}
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-primary { background: #3b82f6; color: #fff; }
.btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
.btn-danger { background: #dc2626; color: #fff; }
.btn-success { background: #16a34a; color: #fff; }
.err-box { margin-top: 14px; font-size: 12px; color: #dc2626; background: #fef2f2; border-radius: 8px; padding: 10px; text-align: left; word-break: break-all; }
.hidden { display: none !important; }
</style>
</head>
<body>
<div class="lock-card">
    <div class="lock-ico" id="ico">🔄</div>
    <div class="lock-title" id="lockTitle">系统数据库迁移中</div>
    <div class="lock-sub" id="lockSub">为避免数据读写不一致，系统已进入全站锁定维护。迁移完成后自动恢复访问，请勿刷新页面。</div>
    <div id="progressBox">
        <div class="progress-track"><div class="progress-bar" id="progressBar"></div></div>
        <div class="progress-meta">表进度：<b id="tableNow">0 / 0</b>　数据行：<b id="rowNow">0</b></div>
        <div class="table-now" id="curTable">准备中…</div>
    </div>
    <div id="doneBox" class="hidden">
        <div class="lock-sub" style="margin-bottom:16px">迁移已完成，数据已同步到目标数据库。<br>是否将主数据库切换为迁移后的数据库？</div>
    </div>
    <div class="actions" id="actions">
        <button class="btn btn-danger hidden" id="cancelBtn" onclick="cancelMig()">取消迁移（管理员）</button>
        <button class="btn btn-outline" onclick="location.href='/login'">重新登录</button>
        <button class="btn btn-success hidden" id="switchBtn" onclick="switchMain()">切换主库</button>
        <button class="btn btn-outline hidden" id="keepBtn" onclick="keepOld()">暂不切换</button>
    </div>
    <div id="errBox" class="err-box hidden"></div>
</div>
<script>
(function () {
    var TOKEN = '';
    try { TOKEN = localStorage.getItem('migration_token') || ''; } catch (e) {}
    if (TOKEN) document.getElementById('cancelBtn').classList.remove('hidden');

    function render(s) {
        var bar = document.getElementById('progressBar');
        var tableNow = document.getElementById('tableNow');
        var rowNow = document.getElementById('rowNow');
        var curTable = document.getElementById('curTable');
        var pct = s.total_tables > 0 ? Math.min(100, Math.round(s.done_tables / s.total_tables * 100)) : 0;
        bar.style.width = pct + '%';
        tableNow.textContent = s.done_tables + ' / ' + s.total_tables;
        rowNow.textContent = s.done_rows || 0;
        curTable.textContent = '正在迁移：' + (s.current_table || '…');

        if (s.status === 'running') {
            document.getElementById('lockTitle').textContent = '系统数据库迁移中';
            document.getElementById('lockSub').textContent = '为避免数据读写不一致，系统已进入全站锁定维护。迁移完成后自动恢复访问，请勿关闭页面。';
            document.getElementById('progressBox').classList.remove('hidden');
            document.getElementById('doneBox').classList.add('hidden');
            document.getElementById('switchBtn').classList.add('hidden');
            document.getElementById('keepBtn').classList.add('hidden');
            document.getElementById('ico').textContent = '🔄';
        } else if (s.status === 'done') {
            document.getElementById('lockTitle').textContent = '数据库迁移完成';
            document.getElementById('lockSub').textContent = '';
            document.getElementById('progressBox').classList.add('hidden');
            document.getElementById('doneBox').classList.remove('hidden');
            document.getElementById('cancelBtn').classList.add('hidden');
            document.getElementById('switchBtn').classList.remove('hidden');
            document.getElementById('keepBtn').classList.remove('hidden');
            document.getElementById('ico').textContent = '✅';
        } else if (s.status === 'cancelled') {
            document.getElementById('lockTitle').textContent = '迁移已取消';
            document.getElementById('lockSub').textContent = '迁移已取消，主数据库保持原库，数据未受影响。请重新登录。';
            document.getElementById('progressBox').classList.add('hidden');
            document.getElementById('doneBox').classList.add('hidden');
            document.getElementById('cancelBtn').classList.add('hidden');
            document.getElementById('switchBtn').classList.add('hidden');
            document.getElementById('keepBtn').classList.add('hidden');
            document.getElementById('ico').textContent = '⚠️';
        } else if (s.status === 'failed') {
            document.getElementById('lockTitle').textContent = '迁移失败';
            document.getElementById('lockSub').textContent = '迁移过程中发生错误，主数据库保持原库。请重新登录后重试。';
            document.getElementById('progressBox').classList.add('hidden');
            document.getElementById('doneBox').classList.add('hidden');
            document.getElementById('cancelBtn').classList.add('hidden');
            document.getElementById('switchBtn').classList.add('hidden');
            document.getElementById('keepBtn').classList.add('hidden');
            document.getElementById('errBox').classList.remove('hidden');
            document.getElementById('errBox').textContent = '错误信息：' + (s.error || '未知错误');
            document.getElementById('ico').textContent = '⛔';
        } else {
            location.href = '/login';   // idle：迁移已结束
        }
    }

    function poll() {
        fetch('/api/migration?action=status')
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.ok) render(j.data); else location.href = '/login'; })
            .catch(function () { setTimeout(poll, 3000); });
    }
    window.cancelMig = function () {
        if (!confirm('⚠️ 确定取消迁移吗？\n\n当前已完成部分数据将丢弃，主数据库保持原库。取消后请重新登录。')) return;
        fetch('/api/migration?action=cancel&token=' + encodeURIComponent(TOKEN))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.ok) { alert(j.msg); poll(); }
                else alert(j.msg || '取消失败');
            });
    };
    window.switchMain = function () {
        if (!confirm('确定将主数据库切换为迁移后的数据库吗？切换后所有数据读写将指向目标数据库。')) return;
        fetch('/api/migration?action=switch')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.ok) {
                    try { localStorage.removeItem('migration_token'); } catch (e) {}
                    alert(j.msg + '，即将重新登录');
                    location.href = '/login';
                } else alert(j.msg || '切换失败');
            });
    };
    window.keepOld = function () {
        fetch('/api/migration?action=done')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.ok) { try { localStorage.removeItem('migration_token'); } catch (e) {} location.href = '/login'; }
                else alert(j.msg || '操作失败');
            });
    };
    poll();
    setInterval(poll, 2000);
})();
</script>
</body>
</html>