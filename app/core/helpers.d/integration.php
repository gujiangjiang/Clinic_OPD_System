<?php
/**
 * ============================================================
 * helpers.d/integration.php — 接口管理字段字典（统一数据源）
 * ============================================================
 * 说明：接口管理中心（/admin/integration）全部配置字段定义，
 * 视图渲染与后端保存（action=integration_save）共用同一字典，
 * 避免“前端表单字段与后端白名单”两处维护漂移。
 * 存储规范：settings 键值对，键名按接口域前缀（pacs_/hl7_/fhir_/
 * his_/pay_/yibao_），值统一字符串。
 * ============================================================ */

/**
 * 接口管理字段分组
 * 结构：每组 = array(id, emoji, title, desc, fields)
 * 字段 = array(key, label, type[input|select], placeholder, default,
 *              options[select 用 val=>文案], hint, monospace)
 * @return array
 */
function integration_field_groups() {
    return array(
        array(
            'id' => 'his', 'emoji' => '🏥', 'title' => 'HIS 接口',
            'desc' => '与院内 HIS 系统对接的基础配置（预留接口，密钥留空则关闭外部只读查询）',
            'fields' => array(
                array('key' => 'his_system_code', 'label' => '系统代码', 'type' => 'input',
                    'placeholder' => '本系统在 HIS 侧登记的系统编码', 'default' => '',
                    'hint' => '由 HIS 侧分配、用于在 HIS 方标识本系统的编码（如 CLINIC-OPD）。当前为预留字段，接口认证仅依赖密钥，不参与校验；配置后会在 ping 自检返回值中回显（同时返回医疗机构代码 org_code，便于联调确认机构归属）。'),
                array('key' => 'his_api_key', 'label' => '接口密钥（留空 = 关闭外部接口）', 'type' => 'input',
                    'placeholder' => '留空 = 关闭 HIS 外部接口', 'default' => '', 'monospace' => true),
                array('key' => 'his_sync_mode', 'label' => '同步模式', 'type' => 'select',
                    'default' => 'manual', 'options' => array(
                        'manual' => '手动同步（默认，人工触发）',
                        'scheduled' => '定时同步（按计划任务）',
                        'realtime' => '实时同步（事件驱动）',
                    ),
                    'hint' => '如何选择：外部系统按需调用本接口查询时选「手动同步」（当前推荐）；需要按固定计划自动拉取/推送数据时选「定时同步」（预留）；需要与 HIS 实时双向交互时选「实时同步」（预留）。'),
            ),
        ),
        array(
            'id' => 'pay', 'emoji' => '💳', 'title' => '支付接口',
            'desc' => '移动支付与聚合收单配置（占位预留，需接入支付能力后填写）',
            'fields' => array(
                array('key' => 'pay_wechat_mchid', 'label' => '微信支付商户号', 'type' => 'input',
                    'placeholder' => '如 1900000109', 'default' => '', 'monospace' => true),
                array('key' => 'pay_wechat_appid', 'label' => '微信支付 AppID', 'type' => 'input',
                    'placeholder' => '公众号/小程序 AppID', 'default' => '', 'monospace' => true),
                array('key' => 'pay_alipay_appid', 'label' => '支付宝应用 AppID', 'type' => 'input',
                    'placeholder' => '支付宝开放平台应用 ID', 'default' => '', 'monospace' => true),
                array('key' => 'pay_aggregate_mode', 'label' => '聚合收单模式', 'type' => 'select',
                    'default' => 'off', 'options' => array(
                        'off' => '未启用（默认，仅现金/线下收费）',
                        'wechat' => '仅微信支付',
                        'alipay' => '仅支付宝',
                        'both' => '微信 + 支付宝聚合',
                    )),
            ),
        ),
        array(
            'id' => 'yibao', 'emoji' => '🪪', 'title' => '医保接口',
            'desc' => '国家医保/地方医保前置机接入配置（医保结算上线前由管理员填写）',
            'fields' => array(
                array('key' => 'yibao_endpoint', 'label' => '医保前置机地址', 'type' => 'input',
                    'placeholder' => '如 http://10.0.10.8:10000/ybapi', 'default' => '', 'monospace' => true),
                array('key' => 'yibao_scope', 'label' => '医保体系', 'type' => 'select',
                    'default' => 'national', 'options' => array(
                        'national' => '国家医保平台（默认）',
                        'local' => '地方医保（按属地要求）',
                    )),
                array('key' => 'yibao_org_code', 'label' => '机构编码', 'type' => 'input',
                    'placeholder' => '医保中心分配的定点机构编码', 'default' => '', 'monospace' => true,
                    'hint' => '医保结算/监管报送的定点机构编码，建议与安装/系统设置中配置的医疗机构代码（org_code）保持一致；医保接口为预留配置，接入时以此编码作为机构唯一标识上报。'),
                array('key' => 'yibao_operator', 'label' => '操作员账号', 'type' => 'input',
                    'placeholder' => '医保结算操作员工号', 'default' => ''),
                array('key' => 'yibao_operator_pwd', 'label' => '操作员密码（加密存储由接入层实现）', 'type' => 'input',
                    'placeholder' => '建议接入时使用加密通道', 'default' => '', 'monospace' => true),
            ),
        ),
        array(
            'id' => 'pacs', 'emoji' => '🩻', 'title' => 'DICOM / PACS 接口',
            'desc' => '医疗影像工作站核心配置：PACS 服务器、DICOMweb 服务与 Web 阅片器（影像科阅片模式使用）',
            'fields' => array(
                array('key' => 'pacs_server_host', 'label' => 'PACS 服务器 IP / 主机名', 'type' => 'input',
                    'placeholder' => '如 192.168.1.60', 'default' => '', 'monospace' => true),
                array('key' => 'pacs_server_port', 'label' => 'PACS 服务器端口（DICOM）', 'type' => 'input',
                    'placeholder' => '如 104', 'default' => '', 'monospace' => true),
                array('key' => 'pacs_ae_title', 'label' => 'AETitle（本系统侧）', 'type' => 'input',
                    'placeholder' => '如 CLINICPACS', 'default' => '', 'monospace' => true),
                array('key' => 'pacs_wado_url', 'label' => 'WADO-RS / DICOMweb 服务地址', 'type' => 'input',
                    'placeholder' => '如 http://192.168.1.60:8080/dicomweb', 'default' => '', 'monospace' => true),
                array('key' => 'pacs_viewer_url', 'label' => 'Web 阅片器 URL 模板', 'type' => 'input',
                    'placeholder' => '如 https://viewer.hospital.local/viewer?study={study_uid}',
                    'default' => '', 'monospace' => true,
                    'hint' => '支持 {study_uid} 变量替换，打开阅片时自动填充当前检查的 Study UID'),
            ),
        ),
        array(
            'id' => 'hl7', 'emoji' => '📡', 'title' => 'HL7 v2.x 接口',
            'desc' => 'HL7 v2.x 消息通道配置（MLLP/HTTP 接收与消息路由标识）',
            'fields' => array(
                array('key' => 'hl7_transport', 'label' => '传输方式', 'type' => 'select',
                    'default' => 'mllp', 'options' => array(
                        'mllp' => 'MLLP（TCP 长连接，默认）',
                        'http' => 'HTTP(S) 推送',
                    )),
                array('key' => 'hl7_receiver_url', 'label' => 'HL7 接收地址', 'type' => 'input',
                    'placeholder' => 'MLLP 如 his.hospital.local:6661；HTTP 如 http://10.0.10.8:9090/hl7',
                    'default' => '', 'monospace' => true),
                array('key' => 'hl7_sending_app', 'label' => '发送方标识（Sending Application）', 'type' => 'input',
                    'placeholder' => 'HL7 MSH-3，如 CLINIC_OPD', 'default' => '', 'monospace' => true),
                array('key' => 'hl7_receiving_app', 'label' => '接收方标识（Receiving Application）', 'type' => 'input',
                    'placeholder' => 'HL7 MSH-5，如 HIS_SERVER', 'default' => '', 'monospace' => true),
            ),
        ),
        array(
            'id' => 'fhir', 'emoji' => '🔗', 'title' => 'FHIR 接口',
            'desc' => 'FHIR R4 互操作性服务接入配置（Patient/ImagingStudy/DiagnosticReport 资源交换）',
            'fields' => array(
                array('key' => 'fhir_endpoint', 'label' => 'FHIR R4 Endpoint 地址', 'type' => 'input',
                    'placeholder' => '如 https://fhir.hospital.local/fhir/r4', 'default' => '', 'monospace' => true),
                array('key' => 'fhir_auth_type', 'label' => 'Token 认证方式', 'type' => 'select',
                    'default' => 'bearer', 'options' => array(
                        'bearer' => 'Bearer Token（默认）',
                        'basic' => 'Basic Auth',
                        'oauth2' => 'OAuth 2.0 客户端凭证',
                    )),
                array('key' => 'fhir_token', 'label' => 'Token / 凭证参数', 'type' => 'input',
                    'placeholder' => '访问令牌或 client_id:client_secret', 'default' => '', 'monospace' => true),
            ),
        ),
        array(
            'id' => 'evid', 'emoji' => '🖋️', 'title' => '存证 / 电子签名',
            'desc' => '电子病历与诊断证明的存证扩展接口（时间戳 + 数字证书/CA 对接，正式医疗机构使用）',
            'fields' => array(
                array('key' => 'evid_mode', 'label' => '存证模式', 'type' => 'select',
                    'default' => 'off', 'options' => array(
                        'off' => '关闭（默认，不进行存证）',
                        'hash' => '本地哈希指纹（SHA-256 摘要入库，自证完整）',
                        'http' => '外部存证服务（调用自定义 HTTP 接口对接 CA/时间戳服务）',
                    ),
                    'hint' => '选择后病历保存与诊断证明开具时自动计算内容指纹并记录；需对接第三方 CA/时间戳服务时选「外部存证服务」并配置下方接口地址。'),
                array('key' => 'evid_endpoint', 'label' => '外部存证/签名服务接口地址', 'type' => 'input',
                    'placeholder' => '如 https://ca.hospital.local/evidence', 'default' => '', 'monospace' => true,
                    'hint' => 'POST JSON 调用：{ record_no, record_type, hash, content }；响应需返回 JSON { token, time }，token 将随病历/证明存证记录保存。'),
                array('key' => 'evid_token', 'label' => '存证服务认证令牌', 'type' => 'input',
                    'placeholder' => '第三方存证服务分配的接口令牌', 'default' => '', 'monospace' => true),
                array('key' => 'evid_signer', 'label' => '电子签名人（印章信息）', 'type' => 'input',
                    'placeholder' => '如 某医院医务科电子印章', 'default' => '',
                    'hint' => '记录在存证记录中用于标识签名主体，配合数字证书/CA 使用；留空则不记录。'),
            ),
        ),
    );
}

/**
 * 按 ID 取一组字段（后端保存白名单用）
 * @param string $groupId
 * @return array|null
 */
function integration_group($groupId) {
    foreach (integration_field_groups() as $g) {
        if ($g['id'] === $groupId) return $g;
    }
    return null;
}
