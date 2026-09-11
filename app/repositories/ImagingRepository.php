<?php
/**
 * ============================================================
 * ImagingRepository.php — 影像引用仓库（PACS 三单匹配 / 只存引用）
 * ============================================================
 * 说明（优化项1/2 架构约定）：
 *   1. 三单匹配：以门诊流水号 flow_no 为唯一索引，把「影像（引用）、
 *      检查申请单、临床诊断快照」硬绑定——影像引用必须归属且仅归属
 *      一个 flow_no；写入与调阅双侧强制校验，杜绝张冠李戴；
 *   2. 只存引用：平台仅存储影像的引用 ID（Study/Series UID）与元数据
 *      （modality/张数/region），影像二进制交给区域影像存储
 *      （region 字段指向，如 Orthanc + 对象存储）与 Web 阅片器负责。
 * 表：imaging_refs（study_uid 唯一索引；flow_no 索引）。
 * ============================================================ */
class ImagingRepository extends BaseRepository {

    /**
     * 三单匹配校验：影像引用与申请单/就诊流水号归属一致性。
     * 不一致抛异常（调用方捕获返回 json_fail），硬拦截防张冠李戴。
     * @param array $ref 待写入的引用行（flow_no/order_id/visit_id/patient_no）
     * @param array $order 申请单行（orders）
     * @param array $item  开单明细行（order_items）
     */
    public static function assertTripleMatch($ref, $order, $item) {
        if (!$order || !$item) {
            throw new RuntimeException('三单匹配失败：申请单或检查明细不存在');
        }
        if ((string)$order['flow_no'] !== (string)$ref['flow_no']) {
            throw new RuntimeException('三单匹配失败：影像引用流水号与申请单流水号不一致');
        }
        if ((int)$order['visit_id'] !== (int)$ref['visit_id']) {
            throw new RuntimeException('三单匹配失败：影像引用就诊归属与申请单不一致');
        }
        if ((int)$item['order_id'] !== (int)$ref['order_id']) {
            throw new RuntimeException('三单匹配失败：影像引用申请单归属与检查明细不一致');
        }
        if ((string)$item['patient_no'] !== (string)$ref['patient_no']) {
            throw new RuntimeException('三单匹配失败：影像引用患者归属与检查明细不一致');
        }
        // 就诊侧：registration 流水号一致性
        $reg = self::one('SELECT flow_no, patient_no FROM registrations WHERE id=?', array((int)$ref['visit_id']));
        if (!$reg
            || (string)$reg['flow_no'] !== (string)$ref['flow_no']
            || (string)$reg['patient_no'] !== (string)$ref['patient_no']) {
            throw new RuntimeException('三单匹配失败：就诊登记流水号与患者归属不一致');
        }
    }

    /**
     * 写入影像引用（upsert：study_uid 冲突则更新；写入前强制三单匹配）。
     * @param array $ref {order_item_id, order_id, visit_id, patient_no, flow_no,
     *                    study_uid, series_uids[], instance_count, modality,
     *                    region, meta, created_by}
     * @return int 引用行 id
     */
    public static function putRef($ref) {
        // 三单硬绑定校验（引用 ← 申请单 ← 明细 ← 就诊 四方归属一致）
        $order = self::one('SELECT * FROM orders WHERE id=?', array((int)$ref['order_id']));
        $item = self::one('SELECT * FROM order_items WHERE id=?', array((int)$ref['order_item_id']));
        self::assertTripleMatch($ref, $order, $item);

        $now = now_str();
        $existing = self::one('SELECT id FROM imaging_refs WHERE study_uid=?', array((string)$ref['study_uid']));
        $row = array(
            ':order_item_id' => (int)$ref['order_item_id'],
            ':order_id' => (int)$ref['order_id'],
            ':visit_id' => (int)$ref['visit_id'],
            ':patient_no' => (string)$ref['patient_no'],
            ':flow_no' => (string)$ref['flow_no'],
            ':study_uid' => (string)$ref['study_uid'],
            ':series_uids' => json_encode(isset($ref['series_uids']) && is_array($ref['series_uids']) ? $ref['series_uids'] : array(), JSON_UNESCAPED_UNICODE),
            ':instance_count' => (int)(isset($ref['instance_count']) ? $ref['instance_count'] : 0),
            ':modality' => (string)(isset($ref['modality']) ? $ref['modality'] : ''),
            ':region' => (string)(isset($ref['region']) && $ref['region'] !== '' ? $ref['region'] : 'region-pacs'),
            ':meta_json' => json_encode(isset($ref['meta']) && is_array($ref['meta']) ? $ref['meta'] : array(), JSON_UNESCAPED_UNICODE),
        );
        if ($existing) {
            $row[':updated_at'] = $now;
            self::exec('UPDATE imaging_refs SET order_item_id=:order_item_id, order_id=:order_id, visit_id=:visit_id,
                patient_no=:patient_no, flow_no=:flow_no, series_uids=:series_uids, instance_count=:instance_count,
                modality=:modality, region=:region, meta_json=:meta_json, updated_at=:updated_at
                WHERE id=:id', array_merge($row, array(':id' => (int)$existing['id'])));
            return (int)$existing['id'];
        }
        $row[':created_by'] = (string)(isset($ref['created_by']) ? $ref['created_by'] : '');
        $row[':created_at'] = $now;
        $row[':updated_at'] = $now;
        return (int)self::insert('INSERT INTO imaging_refs(order_item_id, order_id, visit_id, patient_no, flow_no,
            study_uid, series_uids, instance_count, modality, region, meta_json, created_by, created_at, updated_at)
            VALUES(:order_item_id, :order_id, :visit_id, :patient_no, :flow_no,
            :study_uid, :series_uids, :instance_count, :modality, :region, :meta_json, :created_by, :created_at, :updated_at)', $row);
    }

    /** 按检查明细取影像引用（含 region 提示给阅片器） */
    public static function refByItem($orderItemId) {
        return self::one('SELECT * FROM imaging_refs WHERE order_item_id=? ORDER BY id DESC LIMIT 1', array((int)$orderItemId));
    }

    /** 按流水号取该就诊全部影像引用（三单匹配调阅口径） */
    public static function refsByFlowNo($flowNo) {
        return self::q('SELECT * FROM imaging_refs WHERE flow_no=? ORDER BY id ASC', array((string)$flowNo));
    }
}
