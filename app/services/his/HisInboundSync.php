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
        $patientNo = self::nextPatientNo();
        $age = 0;
        $birth = (string)(isset($p['birth_date']) ? $p['birth_date'] : '');
        if ($birth !== '') {
            $age = (int)floor((time() - strtotime($birth)) / 31536000);
        }
        PatientRepository::insert(
            'INSERT INTO patients(patient_no, id_card, name, gender, birth_date, age, ethnicity, marital, occupation, work_unit, address, phone, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array($patientNo, $idCard, $name,
                (string)(isset($p['gender']) ? $p['gender'] : ''), $birth, $age,
                (string)(isset($p['ethnicity']) ? $p['ethnicity'] : ''),
                (string)(isset($p['marital']) ? $p['marital'] : ''),
                (string)(isset($p['occupation']) ? $p['occupation'] : ''),
                (string)(isset($p['work_unit']) ? $p['work_unit'] : ''),
                (string)(isset($p['address']) ? $p['address'] : ''),
                (string)(isset($p['phone']) ? $p['phone'] : ''),
                now_str())
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
     * HIS 基础字典同步（药品/检验项目/检查项目/处置项目价表，幂等 upsert）
     * @param array $c { drugs:[], lab_items:[], exam_items:[], disposal_items:[] }
     * @return array { msg:string, counts:[] }
     * @throws Exception
     */
    public static function syncCatalog($c) {
        $counts = array('drugs' => 0, 'lab_items' => 0, 'exam_items' => 0, 'disposal_items' => 0);
        // 药品：按名称+规格匹配更新价格/库存单位口径，不存在则新建（待审核）
        if (isset($c['drugs']) && is_array($c['drugs'])) {
            foreach ($c['drugs'] as $d) {
                $name = trim((string)(isset($d['name']) ? $d['name'] : ''));
                $spec = trim((string)(isset($d['spec']) ? $d['spec'] : ''));
                if ($name === '') continue;
                $exist = DrugRepository::one('SELECT * FROM drugs WHERE name=? AND spec=?', array($name, $spec));
                $price = isset($d['price']) ? (float)$d['price'] : 0;
                if ($exist) {
                    DrugRepository::exec('UPDATE drugs SET price=?, vendor=?, vendor_short=? WHERE id=?',
                        array($price,
                            (string)(isset($d['vendor']) ? $d['vendor'] : $exist['vendor']),
                            (string)(isset($d['vendor_short']) ? $d['vendor_short'] : $exist['vendor_short']),
                            (int)$exist['id']));
                } else {
                    DrugRepository::insert(
                        'INSERT INTO drugs(name, generic_name, category, vendor, vendor_short, package_unit, spec, form, single_dose, frequency, route, price, qty, status, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        array($name, (string)(isset($d['generic_name']) ? $d['generic_name'] : ''), (string)(isset($d['category']) ? $d['category'] : ''),
                            (string)(isset($d['vendor']) ? $d['vendor'] : ''), (string)(isset($d['vendor_short']) ? $d['vendor_short'] : ''),
                            (string)(isset($d['package_unit']) ? $d['package_unit'] : '盒'), $spec,
                            (string)(isset($d['form']) ? $d['form'] : ''), (string)(isset($d['single_dose']) ? $d['single_dose'] : ''),
                            (string)(isset($d['frequency']) ? $d['frequency'] : ''), (string)(isset($d['route']) ? $d['route'] : ''),
                            $price, 0, 'pending', now_str())
                    );
                }
                $counts['drugs']++;
            }
        }
        // 检验项目：按名称匹配更新价表，不存在则新建（待审核）
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
                    OrderRepository::insert(
                        'INSERT INTO lab_items(category, name, unit, price, normal_range, status, created_at) VALUES(?,?,?,?,?,?,?)',
                        array((string)(isset($li['category']) ? $li['category'] : '其他'), $name,
                            (string)(isset($li['unit']) ? $li['unit'] : ''), (float)(isset($li['price']) ? $li['price'] : 0),
                            (string)(isset($li['normal_range']) ? $li['normal_range'] : ''), 'pending', now_str())
                    );
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
                    OrderRepository::insert(
                        'INSERT INTO exam_items(category, name, price, description, status, created_at) VALUES(?,?,?,?,?,?)',
                        array((string)(isset($ei['category']) ? $ei['category'] : ''), $name,
                            (float)(isset($ei['price']) ? $ei['price'] : 0),
                            (string)(isset($ei['description']) ? $ei['description'] : ''), 'pending', now_str())
                    );
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
                    OrderRepository::insert(
                        'INSERT INTO disposal_items(name, price, description, status, created_at) VALUES(?,?,?,?,?)',
                        array($name, (float)(isset($di['price']) ? $di['price'] : 0),
                            (string)(isset($di['description']) ? $di['description'] : ''), 'pending', now_str())
                    );
                }
                $counts['disposal_items']++;
            }
        }
        $total = array_sum($counts);
        if ($total === 0) throw new Exception('载荷未包含可同步的字典数据');
        return array('msg' => '已同步 ' . $total . ' 条字典（药品 ' . $counts['drugs'] . ' / 检验 ' . $counts['lab_items'] . ' / 检查 ' . $counts['exam_items'] . ' / 处置 ' . $counts['disposal_items'] . '）', 'counts' => $counts);
    }
}