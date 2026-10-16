<?php
/**
 * ============================================================
 * services/his/HisInboundSync.php — HIS 入向同步服务
 * ============================================================
 * 说明：接收 HIS 推送的患者主数据与基础字典（药品/检验/检查/处置价表），
 * 幂等 upsert 写入业务库（原 app/api/external.php 内联函数下沉至此）。
 * 由 /api/external/his/sync-patient 与 /api/external/his/sync-catalog
 * 路由调用（鉴权在 InboundGuard）。数据访问统一走 Repository 收口。
 * ============================================================ */
class HisInboundSync {

    /**
     * HIS 患者主数据同步（幂等建档：按身份证号更新可改字段，姓名/性别/出生日期锁定）
     * @param array $p { id_card, name, gender, birth_date, phone, address, ... }
     * @return array { patient_no, created:bool }
     * @throws Exception
     */
    public static function syncPatient($p) {
        $idCard = strtoupper(trim((string)$p['id_card']));
        if ($idCard === '') throw new Exception('身份证号不能为空');
        $name = trim((string)(isset($p['name']) ? $p['name'] : ''));
        if ($name === '') throw new Exception('姓名不能为空');
        $patient = PatientRepository::one('SELECT * FROM patients WHERE id_card=?', array($idCard));
        $created = false;
        if ($patient) {
            PatientRepository::exec('UPDATE patients SET ethnicity=?, marital=?, occupation=?, work_unit=?, address=?, phone=? WHERE id_card=?',
                array(
                    (string)(isset($p['ethnicity']) ? $p['ethnicity'] : $patient['ethnicity']),
                    (string)(isset($p['marital']) ? $p['marital'] : $patient['marital']),
                    (string)(isset($p['occupation']) ? $p['occupation'] : $patient['occupation']),
                    (string)(isset($p['work_unit']) ? $p['work_unit'] : $patient['work_unit']),
                    (string)(isset($p['address']) ? $p['address'] : $patient['address']),
                    (string)(isset($p['phone']) ? $p['phone'] : $patient['phone']),
                    $idCard,
                )
            );
            return array('patient_no' => $patient['patient_no'], 'created' => false);
        }
        $age = 0;
        $birth = (string)(isset($p['birth_date']) ? $p['birth_date'] : '');
        if ($birth !== '') {
            $age = (int)floor((time() - strtotime($birth)) / 31536000);
        }
        // 并发撞号兜底：insert_unique_retry 冲突即重试（nextPatientNo 基于当日计数，冲突后计数已增）
        $patientNo = '';
        insert_unique_retry(
            function () { return self::nextPatientNo(); },
            function ($no) use (&$patientNo, $idCard, $name, $p, $birth, $age) {
                $patientNo = $no;
                return PatientRepository::insert(
                    'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array($no, $idCard, $name,
                        (string)(isset($p['gender']) ? $p['gender'] : ''), $birth, $age,
                        (string)(isset($p['ethnicity']) ? $p['ethnicity'] : ''),
                        (string)(isset($p['marital']) ? $p['marital'] : ''),
                        (string)(isset($p['occupation']) ? $p['occupation'] : ''),
                        (string)(isset($p['work_unit']) ? $p['work_unit'] : ''),
                        (string)(isset($p['address']) ? $p['address'] : ''),
                        (string)(isset($p['phone']) ? $p['phone'] : ''),
                        now_str())
                );
            }
        );
        return array('patient_no' => $patientNo, 'created' => true);
    }

    /** HIS 推送建档的患者编号生成（年月日 + 当日序号2位，与挂号建档同源） */
    private static function nextPatientNo() {
        $ymd = date('ymd');
        $seq = (int)PatientRepository::val('SELECT COUNT(*) FROM patients WHERE substr(patient_no,1,6)=?', array($ymd)) + 1;
        return $ymd . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
    }

    /**
     * HIS 基础字典同步（检验项目/检查项目/处置项目价表，幂等 upsert）
     *
     * 药品目录【不同步】：HIS 药品目录一般属中心药房，与门诊药房（本系统
     * 药品库）不是一个药房，不落库、不进入门诊审核流，仅统计并说明。
     * 新增的检验/检查/处置项目置「待审核」并同步提交审核中心（audits），
     * 保证审核中心可见可审——此前仅置 pending 不提交 audits 导致
     * 「列表显示待审核、审核中心却查无此条」。
     *
     * @param array $c { drugs:[], lab_items:[], exam_items:[], disposal_items:[] }
     * @return array { msg:string, counts:[], drugs_ignored:int }
     * @throws Exception
     */
    public static function syncCatalog($c) {
        $counts = array('lab_items' => 0, 'exam_items' => 0, 'disposal_items' => 0);
        // 药品目录：无条件忽略（仅按有效条目计数，供响应说明）
        $drugsIgnored = 0;
        if (isset($c['drugs']) && is_array($c['drugs'])) {
            foreach ($c['drugs'] as $d) {
                if (trim((string)(isset($d['name']) ? $d['name'] : '')) !== '') $drugsIgnored++;
            }
        }
        // 检验项目：按名称匹配更新价表，不存在则新建（待审核 + 提交审核中心）
        if (isset($c['lab_items']) && is_array($c['lab_items'])) {
            foreach ($c['lab_items'] as $li) {
                $name = trim((string)(isset($li['name']) ? $li['name'] : ''));
                if ($name === '') continue;
                $exist = OrderRepository::one('SELECT * FROM lab_items WHERE name=? AND category=?',
                    array($name, (string)(isset($li['category']) ? $li['category'] : '')));
                if ($exist) {
                    OrderRepository::exec('UPDATE lab_items SET price=?, unit=?, normal_range=? WHERE id=?',
                        array((float)(isset($li['price']) ? $li['price'] : $exist['price']),
                            (string)(isset($li['unit']) ? $li['unit'] : $exist['unit']),
                            (string)(isset($li['normal_range']) ? $li['normal_range'] : $exist['normal_range']),
                            (int)$exist['id']));
                } else {
                    $newId = (int)OrderRepository::insert(
                        'INSERT INTO lab_items(category, name, unit, price, normal_range, status, created_at) VALUES(?,?,?,?,?,?,?)',
                        array((string)(isset($li['category']) ? $li['category'] : '其他'), $name,
                            (string)(isset($li['unit']) ? $li['unit'] : ''), (float)(isset($li['price']) ? $li['price'] : 0),
                            (string)(isset($li['normal_range']) ? $li['normal_range'] : ''), 'pending', now_str())
                    );
                    self::submitItemAudit('item_lab', $newId, '新增检验项目：' . $name);
                }
                $counts['lab_items']++;
            }
        }
        // 检查项目
        if (isset($c['exam_items']) && is_array($c['exam_items'])) {
            foreach ($c['exam_items'] as $ei) {
                $name = trim((string)(isset($ei['name']) ? $ei['name'] : ''));
                if ($name === '') continue;
                $exist = OrderRepository::one('SELECT * FROM exam_items WHERE name=?', array($name));
                if ($exist) {
                    OrderRepository::exec('UPDATE exam_items SET price=?, category=? WHERE id=?',
                        array((float)(isset($ei['price']) ? $ei['price'] : $exist['price']),
                            (string)(isset($ei['category']) ? $ei['category'] : $exist['category']),
                            (int)$exist['id']));
                } else {
                    $newId = (int)OrderRepository::insert(
                        'INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)',
                        array((string)(isset($ei['category']) ? $ei['category'] : ''), $name,
                            (float)(isset($ei['price']) ? $ei['price'] : 0),
                            (string)(isset($ei['description']) ? $ei['description'] : ''), 'pending', now_str())
                    );
                    self::submitItemAudit('item_exam', $newId, '新增检查项目：' . $name);
                }
                $counts['exam_items']++;
            }
        }
        // 处置项目
        if (isset($c['disposal_items']) && is_array($c['disposal_items'])) {
            foreach ($c['disposal_items'] as $di) {
                $name = trim((string)(isset($di['name']) ? $di['name'] : ''));
                if ($name === '') continue;
                $exist = OrderRepository::one('SELECT * FROM disposal_items WHERE name=?', array($name));
                if ($exist) {
                    OrderRepository::exec('UPDATE disposal_items SET price=?, description=? WHERE id=?',
                        array((float)(isset($di['price']) ? $di['price'] : $exist['price']),
                            (string)(isset($di['description']) ? $di['description'] : $exist['description']),
                            (int)$exist['id']));
                } else {
                    $newId = (int)OrderRepository::insert(
                        'INSERT INTO disposal_items(name, price, description, status, created_at) VALUES(?,?,?,?,?)',
                        array($name, (float)(isset($di['price']) ? $di['price'] : 0),
                            (string)(isset($di['description']) ? $di['description'] : ''), 'pending', now_str())
                    );
                    self::submitItemAudit('item_disp', $newId, '新增处置项目：' . $name);
                }
                $counts['disposal_items']++;
            }
        }
        $total = array_sum($counts);
        if ($total === 0 && $drugsIgnored === 0) throw new Exception('载荷未包含可同步的字典数据');
        $msg = '已同步 ' . $total . ' 条字典（检验 ' . $counts['lab_items'] . ' / 检查 ' . $counts['exam_items'] . ' / 处置 ' . $counts['disposal_items'] . '）';
        if ($drugsIgnored > 0) {
            $msg .= '；药品目录 ' . $drugsIgnored . ' 条已忽略（HIS 中心药房不纳入门诊药品库）';
        }
        return array('msg' => $msg, 'counts' => $counts, 'drugs_ignored' => $drugsIgnored);
    }

    /** 字典同步新增项目的审核中心登记（待管理员审核；来源标记便于审核中心高亮） */
    private static function submitItemAudit($type, $itemId, $title) {
        submit_audit($type, $itemId, $title, 'HIS 字典同步推送新增项目，待管理员审核',
            array('proposer' => 'HIS 同步', 'proposer_id' => 0, 'creation_source' => 'HIS 同步'));
    }
}