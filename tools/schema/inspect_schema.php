<?php
require __DIR__ . '/../app/config/bootstrap.php';
DatabaseManager::initAll();
$today = date('Y-m-d');
echo "== 今日就诊状态 ==\n";
foreach (DB::q("SELECT status, COUNT(*) n FROM registrations WHERE substr(registered_at,1,10)=? GROUP BY status", array($today)) as $r) {
    echo $r['status'], ' x', $r['n'], "\n";
}
echo "== 转归分布 ==\n";
foreach (DB::q("SELECT disposition, COUNT(*) n FROM registrations WHERE disposition<>'' GROUP BY disposition") as $r) {
    echo $r['disposition'], ' x', $r['n'], "\n";
}
echo "== 多人续写（文书数>=3）==\n";
foreach (DB::q('SELECT visit_id, COUNT(*) n FROM patient_records GROUP BY visit_id HAVING n>=3 ORDER BY n DESC LIMIT 8') as $r) {
    echo 'visit ', $r['visit_id'], ' x', $r['n'], " 份文书\n";
}
echo "== 运营口径（今日缴费人次/开单收入）==\n";
echo '人次=', DB::val("SELECT COUNT(*) FROM registrations WHERE status IN ('paid','visiting','finished') AND paid_at IS NOT NULL AND date(paid_at)=?", array($today)), "\n";
echo '开单收入=', DB::val("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('refunded','cancelled') AND paid_at IS NOT NULL AND date(paid_at)=?", array($today)), "\n";
