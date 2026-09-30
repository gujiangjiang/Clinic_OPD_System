<?php
/**
 * ============================================================
 * services/his/HisDriverInterface.php — HIS 出向驱动统一接口
 * ============================================================
 * 说明：统一约定 HIS 出向同步的驱动契约（抽象驱动层）：
 *  - RestHisDriver：REST/JSON 适配器
 *  - SoapHisDriver：SOAP WebService/XML 适配器
 * 两者按配置 integration.outbound.his.protocol 平滑切换，
 * 业务层仅依赖本接口，不感知协议差异。
 * ============================================================ */
interface HisDriverInterface {

    /** 驱动标识名（监控面板/日志展示） */
    public function name();

    /**
     * 发送业务数据到 HIS
     * @param string $businessType 业务类型：his_registration / his_settlement / his_prescription
     * @param array  $payload      业务上下文（visit_id/order_id/payment_id/patient_no/flow_no 等）
     * @return array { ok:bool, resp:string, error:string }
     */
    public function send($businessType, $payload);

    /**
     * 工厂：按当前配置创建驱动实例
     * @return HisDriverInterface
     */
    public static function make();
}