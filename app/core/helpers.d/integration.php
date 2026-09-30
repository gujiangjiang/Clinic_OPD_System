<?php
/**
 * ============================================================
 * helpers.d/integration.php — 外部接口字段字典与集成枢纽
 * ============================================================
 * 说明：接口管理中心（/admin/integration）全部配置字段定义与集成辅助函数：
 *  1. integration_field_groups()：双向两舱字段字典（视图渲染与后端保存共用，
 *     杜绝"前端表单字段与后端白名单"两处维护漂移）。
 *  2. integration_cfg()：新命名空间 integration.outbound.* / integration.inbound.*
 *     读取，并回退历史平铺键（pacs_/hl7_/fhir_/his_/yibao_）保证向后兼容。
 *  3. integration_inbound_endpoints()：入向开放端点只读列表（UI 一键复制）。
 *  4. integration_log_inbound()：入向调用审计落账（inbound_events 表）。
 *  5. integration_after_*()：业务触发钩子——挂号/开单/缴费/发药本地事务提交后
 *     向 his_sync_tasks 异步入队，由后台 worker（tools/cli/integration_outbox_run.php）
 *     调用 HIS/FHIR/HL7/LIS 驱动投递，杜绝外部网络波动阻塞本地主事务。
 * ============================================================ */

/**
 * 接口管理字段分组
 * 结构：每组 = array(id, emoji, title, desc, endpoints, fields)
 * endpoints = 入向只读端点列表：array(label, method, path, note, example?)
 * fields 中的 zone 字段：'outbound' 出向集成 / 'inbound' 入向开放；
 * 未设 zone 的字段为模块公共配置（跨舱共享）。
 * 字段 type：input / select / textarea；select 项 options = val=>文案；
 * rule：'port' 端口 / 'int' 整数 / 'bool' 开关 / 'url' 地址 / 'timeout' 超时秒。
 * show_if = array(key, value) 依赖字段值联动显隐（如 PACS 协议模式）。
 * @return array
 */
function integration_field_groups() {
    return array(

        /* ==================== FHIR R4 资源互联引擎 ==================== */
        array(
            'id' => 'fhir', 'emoji' => render_icon('action:link'), 'title' => 'FHIR R4',
            'desc' => 'FHIR R4 (v4.0.1) 互操作性引擎：出向按 Bundle 异步上报 Patient/Encounter/Condition/MedicationRequest；入向按 CapabilityStatement 对区域平台/上级机构开放调阅',
            'endpoints' => array(
                array('label' => 'FHIR Base URL', 'method' => 'GET', 'path' => '/api/fhir/r4/', 'note' => 'FHIR R4 服务根地址，区域平台/上级机构按此地址调用'),
                array('label' => '元数据 CapabilityStatement', 'method' => 'GET', 'path' => '/api/fhir/r4/metadata', 'note' => '返回标准 CapabilityStatement JSON，声明本系统支持的资源与能力'),
                array('label' => '患者资源', 'method' => 'GET', 'path' => '/api/fhir/r4/Patient/{patient_no}', 'note' => '按患者编号（patient_no）输出 FHIR Patient 资源'),
                array('label' => '就诊记录检索', 'method' => 'GET', 'path' => '/api/fhir/r4/Encounter?patient={patient_no}', 'note' => '输出该患者历史就诊记录 Bundle'),
            ),
            'fields' => array(
                // ---------- 出向集成（Client Push 数据上报） ----------
                array('key' => 'integration.outbound.fhir.enabled', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '启用出向上报', 'default' => '0', 'options' => array('0' => '关闭（默认）', '1' => '启用'),
                    'hint' => '启用后：门诊问诊完成、下达处方时自动将挂号、诊断与医嘱数据组装为 FHIR Bundle，异步推送至目标 FHIR Server。'),
                array('key' => 'integration.outbound.fhir.remote_endpoint', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '目标 FHIR Server 根地址', 'placeholder' => '如 https://fhir.hospital.local/fhir/r4', 'default' => '', 'monospace' => true, 'rule' => 'url'),
                array('key' => 'integration.outbound.fhir.auth_type', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '认证方式', 'default' => 'none', 'options' => array(
                        'none' => '无认证',
                        'basic' => 'Basic Auth',
                        'bearer' => 'Bearer Token',
                        'oauth2' => 'OAuth2 客户端凭证',
                    )),
                array('key' => 'integration.outbound.fhir.client_token', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '凭证 / Secret', 'placeholder' => 'Bearer Token 或 client_id:client_secret', 'default' => '', 'monospace' => true),
                // ---------- 入向开放（Provider Server 供外部调阅） ----------
                array('key' => 'integration.inbound.fhir.enabled', 'zone' => 'inbound', 'type' => 'select',
                    'label' => '启用入向开放', 'default' => '0', 'options' => array('0' => '关闭（默认）', '1' => '启用'),
                    'hint' => '启用后下方 FHIR Base URL / metadata 端点对外可访问。'),
                array('key' => 'integration.inbound.fhir.allowed_tokens', 'zone' => 'inbound', 'type' => 'textarea',
                    'label' => '授权调用方 Token 列表', 'placeholder' => '每行一组：调用方名称,Token', 'default' => '', 'monospace' => true,
                    'hint' => '格式：每行「调用方名,Token」（半角逗号分隔）。调用方以 Bearer Token 访问；留空 = 拒绝全部。'),
                array('key' => 'integration.inbound.fhir.ip_whitelist', 'zone' => 'inbound', 'type' => 'textarea',
                    'label' => 'IP 白名单', 'placeholder' => '每行一个 IP 或 CIDR 网段（如 10.0.0.0/8）', 'default' => '', 'monospace' => true,
                    'hint' => '留空 = 不限制来源 IP。'),
            ),
        ),

        /* ==================== DICOM / PACS 影像互联引擎 ==================== */
        array(
            'id' => 'pacs', 'emoji' => render_icon('nav:imaging'), 'title' => 'DICOM / PACS',
            'desc' => '影像互联双通道：DICOMweb（QIDO/WADO/STOW，HTTP RESTful）或传统 DIMSE（TCP C-STORE/C-MOVE）。本系统可作 SCU 出向调阅/上传，亦可作 SCP 入向接收设备直推',
            'endpoints' => array(
                array('label' => '入向 DIMSE 说明', 'method' => '', 'path' => '', 'note' => '本系统作为 SCP 接收设备直推时，请在 PACS 前置网关（如 Orthanc / dcm4chee）注册下方本地 AE Title 与监听端口，由网关承载 TCP 监听并回写本系统影像引用表', 'placeholder_endpoint' => true),
            ),
            'fields' => array(
                // ---------- 协议通道选择（公共） ----------
                array('key' => 'integration.pacs.protocol_mode', 'type' => 'select',
                    'label' => '协议通道', 'default' => 'dicomweb', 'options' => array(
                        'dicomweb' => 'DICOMweb（HTTP RESTful，推荐）',
                        'dimse' => 'DIMSE（传统 TCP DICOM）',
                    )),
                // ---------- 出向：DICOMweb ----------
                array('key' => 'integration.outbound.pacs.qido_url', 'zone' => 'outbound', 'type' => 'input',
                    'label' => 'QIDO-RS 检索端点', 'placeholder' => '如 http://pacs.hospital.local/dicomweb/studies', 'default' => '', 'monospace' => true,
                    'rule' => 'url', 'show_if' => array('key' => 'integration.pacs.protocol_mode', 'value' => 'dicomweb')),
                array('key' => 'integration.outbound.pacs.wado_url', 'zone' => 'outbound', 'type' => 'input',
                    'label' => 'WADO-RS 调阅端点', 'placeholder' => '如 http://pacs.hospital.local/dicomweb/studies/{study_uid}/series', 'default' => '', 'monospace' => true,
                    'rule' => 'url', 'show_if' => array('key' => 'integration.pacs.protocol_mode', 'value' => 'dicomweb')),
                array('key' => 'integration.outbound.pacs.stow_url', 'zone' => 'outbound', 'type' => 'input',
                    'label' => 'STOW-RS 上传端点', 'placeholder' => '如 http://pacs.hospital.local/dicomweb/studies', 'default' => '', 'monospace' => true,
                    'rule' => 'url', 'show_if' => array('key' => 'integration.pacs.protocol_mode', 'value' => 'dicomweb')),
                array('key' => 'integration.outbound.pacs.http_auth_header', 'zone' => 'outbound', 'type' => 'input',
                    'label' => 'HTTP 鉴权头', 'placeholder' => '如 Bearer xxxxxx 或 Basic 前缀', 'default' => '', 'monospace' => true,
                    'show_if' => array('key' => 'integration.pacs.protocol_mode', 'value' => 'dicomweb')),
                // ---------- 出向：DIMSE（本系统作 SCU） ----------
                array('key' => 'integration.outbound.pacs.remote_ae_title', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '远端 AE Title', 'placeholder' => '如 ORTHANC', 'default' => '', 'monospace' => true,
                    'show_if' => array('key' => 'integration.pacs.protocol_mode', 'value' => 'dimse')),
                array('key' => 'integration.outbound.pacs.remote_host', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '远端主机', 'placeholder' => '如 192.168.1.60', 'default' => '', 'monospace' => true,
                    'show_if' => array('key' => 'integration.pacs.protocol_mode', 'value' => 'dimse')),
                array('key' => 'integration.outbound.pacs.remote_port', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '远端端口', 'placeholder' => '如 104 / 11112', 'default' => '', 'monospace' => true, 'rule' => 'port',
                    'show_if' => array('key' => 'integration.pacs.protocol_mode', 'value' => 'dimse')),
                array('key' => 'integration.outbound.pacs.viewer_url', 'zone' => 'outbound', 'type' => 'input',
                    'label' => 'Web 阅片器 URL 模板', 'placeholder' => '如 https://viewer.hospital.local/viewer?study={study_uid}', 'default' => '', 'monospace' => true,
                    'rule' => 'url', 'hint' => '支持 {study_uid} 变量替换，打开阅片时自动填充当前检查的 Study UID。'),
                // ---------- 入向：本系统作 SCP ----------
                array('key' => 'integration.inbound.pacs.local_ae_title', 'zone' => 'inbound', 'type' => 'input',
                    'label' => '本地 AE Title', 'placeholder' => 'CLINIC_PACS', 'default' => 'CLINIC_PACS', 'monospace' => true,
                    'hint' => '本系统作为 SCP 接收设备直推时使用的 AE Title。'),
                array('key' => 'integration.inbound.pacs.local_port', 'zone' => 'inbound', 'type' => 'input',
                    'label' => '本地监听端口', 'placeholder' => '如 104 / 11112', 'default' => '', 'monospace' => true, 'rule' => 'port',
                    'hint' => '由 PACS 前置网关承载 TCP 监听（本系统不直接起 DICOM 监听进程）。'),
            ),
        ),

        /* ==================== HL7 v2.x 消息通信引擎 ==================== */
        array(
            'id' => 'hl7', 'emoji' => render_icon('nav:plug'), 'title' => 'HL7 v2',
            'desc' => 'HL7 v2.x 消息通道：出向按 MLLP over TCP 或 HTTP 发送 ADT^A04/A08 与 ORM^O01 并强校验 MSA ACK；入向支持 MLLP 守护进程与 HTTP 代理双选项，解析 ORU^R01 观察结果回传',
            'endpoints' => array(
                array('label' => 'HTTP 代理接收端点', 'method' => 'POST', 'path' => '/api/external/hl7/receiver', 'note' => '请求体为原始 HL7 文本（text/plain）或 JSON { "message": "..." }；系统解析后回传 MSA 应答报文'),
                array('label' => 'MLLP 监听端点', 'method' => '', 'path' => '', 'note' => 'TCP 0.0.0.0:{local_port}，由 CLI 守护进程 tools/cli/hl7_mllp_server.php 启动；设备/系统以 MLLP 帧（0x0B…0x1C0x0D）推送'),
            ),
            'fields' => array(
                // ---------- 出向：消息头与通道 ----------
                array('key' => 'integration.outbound.hl7.enabled', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '启用出向发送', 'default' => '0', 'options' => array('0' => '关闭（默认）', '1' => '启用'),
                    'hint' => '启用后：建档/挂号自动发送 ADT^A04/A08，处方/检查下达自动发送 ORM^O01；对外部返回的 MSA 段强校验（AA 成功，AE/AR 报错入日志）。'),
                array('key' => 'integration.outbound.hl7.transport', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '传输通道', 'default' => 'mllp_tcp', 'options' => array(
                        'mllp_tcp' => 'MLLP over TCP（默认，双闭环 ACK）',
                        'http_post' => 'HTTP(S) POST 推送',
                    )),
                array('key' => 'integration.outbound.hl7.remote_host', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '远端主机', 'placeholder' => '如 his.hospital.local', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.hl7.remote_port', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '远端端口', 'placeholder' => '如 2575', 'default' => '', 'monospace' => true, 'rule' => 'port'),
                array('key' => 'integration.outbound.hl7.timeout_seconds', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '超时秒数', 'placeholder' => '10', 'default' => '10', 'rule' => 'timeout'),
                array('key' => 'integration.outbound.hl7.sending_app', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '发送应用（MSH-3）', 'placeholder' => '如 CLINIC_OPD', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.hl7.sending_facility', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '发送机构（MSH-4）', 'placeholder' => '门诊机构编码', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.hl7.receiving_app', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '接收应用（MSH-5）', 'placeholder' => '如 HOSP_HIS / LIS', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.hl7.receiving_facility', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '接收机构（MSH-6）', 'placeholder' => '接收方机构编码', 'default' => '', 'monospace' => true),
                // ---------- 入向：MLLP 监听与白名单 ----------
                array('key' => 'integration.inbound.hl7.local_port', 'zone' => 'inbound', 'type' => 'input',
                    'label' => 'MLLP 本地监听端口', 'placeholder' => '如 2575', 'default' => '2575', 'monospace' => true, 'rule' => 'port',
                    'hint' => 'tools/cli/hl7_mllp_server.php 守护进程监听端口。'),
                array('key' => 'integration.inbound.hl7.ip_whitelist', 'zone' => 'inbound', 'type' => 'textarea',
                    'label' => 'IP 白名单', 'placeholder' => '每行一个 IP 或 CIDR 网段', 'default' => '', 'monospace' => true,
                    'hint' => 'MLLP 守护进程与 HTTP 代理接收端点共用；留空 = 不限制来源 IP。'),
            ),
        ),

        /* ==================== LIS 实验室检验双向闭环 ==================== */
        array(
            'id' => 'lis', 'emoji' => render_icon('nav:lab'), 'title' => 'LIS 检验',
            'desc' => '检验双向闭环：出向下发检验申请（order_url）；入向接收检验中心异步 Webhook 报告回调，数据验签后按申请单号幂等回填检验明细、异常标志、报告医生与 PDF 附件',
            'endpoints' => array(
                array('label' => '报告结果接收 Webhook', 'method' => 'POST', 'path' => '/api/external/lis/callback', 'note' => '调用方携带 X-LIS-Token: <webhook_secret>（或 ?token=）。JSON 体结构见下方样例', 'example' => 'JSON 样例：{"order_no":"JH20260930001","report_doctor":"李检验","pdf_url":"http://lis/reports/1.pdf","items":[{"name":"白细胞计数","value":"6.2","unit":"10^9/L","ref_range":"3.5-9.5","flag":"N"}]}'),
            ),
            'fields' => array(
                // ---------- 出向：申请下发 ----------
                array('key' => 'integration.outbound.lis.enabled', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '启用申请下发', 'default' => '0', 'options' => array('0' => '关闭（默认）', '1' => '启用')),
                array('key' => 'integration.outbound.lis.order_url', 'zone' => 'outbound', 'type' => 'input',
                    'label' => 'LIS 接收申请 API', 'placeholder' => '如 http://lis.hospital.local/api/orders', 'default' => '', 'monospace' => true, 'rule' => 'url'),
                array('key' => 'integration.outbound.lis.auth_token', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '调用凭证 Token', 'placeholder' => 'LIS 分配的调用令牌', 'default' => '', 'monospace' => true),
                // ---------- 入向：报告回调 ----------
                array('key' => 'integration.inbound.lis.webhook_secret', 'zone' => 'inbound', 'type' => 'input',
                    'label' => 'Webhook 验签密钥', 'placeholder' => '回调方须携带 X-LIS-Token 头', 'default' => '', 'monospace' => true,
                    'hint' => '留空 = 拒绝全部回调；配置后回调方以 X-LIS-Token 或 ?token= 携带本密钥验签。'),
                array('key' => 'integration.inbound.lis.ip_whitelist', 'zone' => 'inbound', 'type' => 'textarea',
                    'label' => 'IP 白名单', 'placeholder' => '每行一个 IP 或 CIDR 网段（建议限定检验中心出口）', 'default' => '', 'monospace' => true),
            ),
        ),

        /* ==================== 医院 HIS 接口双向与可靠性加固 ==================== */
        array(
            'id' => 'his', 'emoji' => render_icon('nav:hospital'), 'title' => 'HIS 接口',
            'desc' => '医院 HIS 双向对接：出向按 REST/JSON 或 SOAP/XML 上报挂号、结算、发药（Outbox 补偿 + 重试监控）；入向接收 HIS 推送的患者主数据与药品价表字典',
            'endpoints' => array(
                array('label' => '患者预约/建档推送', 'method' => 'POST', 'path' => '/api/external/his/sync-patient', 'note' => 'HIS 推送患者主数据，按身份证号幂等建档。携带 X-HIS-Token 头或 ?token='),
                array('label' => '基础字典同步', 'method' => 'POST', 'path' => '/api/external/his/sync-catalog', 'note' => '药品/耗材/价表数据同步，按内部编码幂等更新。携带 X-HIS-Token 头或 ?token='),
                array('label' => '历史只读查询接口（旧版兼容）', 'method' => 'GET', 'path' => '/api/his?action=ping', 'note' => '旧版外部只读查询：ping / patient_get / visit_list / visit_status / order_list / evidence_verify，鉴权同入向 Token'),
            ),
            'fields' => array(
                // ---------- 出向：协议适配器与连接认证 ----------
                array('key' => 'integration.outbound.his.enabled', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '启用出向同步', 'default' => '0', 'options' => array('0' => '关闭（默认）', '1' => '启用')),
                array('key' => 'integration.outbound.his.protocol', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '协议适配器', 'default' => 'rest_json', 'options' => array(
                        'rest_json' => 'REST / JSON（默认）',
                        'soap_xml' => 'SOAP WebService / XML',
                    )),
                array('key' => 'integration.outbound.his.gateway_url', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '网关地址', 'placeholder' => 'REST 接口地址或 WebService WSDL 地址', 'default' => '', 'monospace' => true, 'rule' => 'url'),
                array('key' => 'integration.outbound.his.hospital_code', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '院区/医疗机构代码', 'placeholder' => '本系统在 HIS 侧登记的机构编码', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.his.app_id', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '应用 AppID', 'placeholder' => 'HIS 分配的调用方 AppID', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.his.app_secret', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '应用 AppSecret', 'placeholder' => '用于请求签名（HMAC-SHA256）', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.his.timeout', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '超时秒数', 'placeholder' => '10', 'default' => '10', 'rule' => 'timeout'),
                array('key' => 'integration.outbound.his.sync_registration', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '挂号实时同步', 'default' => '0', 'options' => array('0' => '关闭', '1' => '启用')),
                array('key' => 'integration.outbound.his.sync_settlement', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '结算/退费记账', 'default' => '0', 'options' => array('0' => '关闭', '1' => '启用')),
                array('key' => 'integration.outbound.his.sync_prescription', 'zone' => 'outbound', 'type' => 'select',
                    'label' => '发药与库存核减', 'default' => '0', 'options' => array('0' => '关闭', '1' => '启用')),
                // ---------- 入向：接收 HIS 推送 ----------
                array('key' => 'integration.inbound.his.token', 'zone' => 'inbound', 'type' => 'input',
                    'label' => '入向鉴权 Token', 'placeholder' => '留空 = 拒绝 HIS 推送', 'default' => '', 'monospace' => true,
                    'hint' => 'HIS 推送/查询携带 X-HIS-Token 头或 ?token=；旧版 his_api_key 自动迁移为本值。'),
                array('key' => 'integration.inbound.his.ip_whitelist', 'zone' => 'inbound', 'type' => 'textarea',
                    'label' => 'IP 白名单', 'placeholder' => '每行一个 IP 或 CIDR 网段（建议限定 HIS 出口）', 'default' => '', 'monospace' => true),
            ),
        ),

        /* ==================== 国家医保接口与支付中心 ==================== */
        array(
            'id' => 'insurance', 'emoji' => render_icon('action:id-card'), 'title' => '医保 / 支付',
            'desc' => '医保前置机为纯出向接口（国家/地方平台结算）；支付回调为入向端点（微信/支付宝/银联聚合支付结果回调）',
            'endpoints' => array(
                array('label' => '支付结果回调', 'method' => 'POST', 'path' => '/api/cashier/pay-notify/{provider}', 'note' => 'provider ∈ wechat / alipay / unionpay。微信/支付宝以各自规范验签后应答 success'),
            ),
            'fields' => array(
                // ---------- 医保（纯出向） ----------
                array('key' => 'integration.outbound.insurance.gateway_url', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '医保前置机地址', 'placeholder' => '如 http://10.0.10.8:10000/ybapi', 'default' => '', 'monospace' => true, 'rule' => 'url'),
                array('key' => 'integration.outbound.insurance.fixmedins_code', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '定点医药机构编号', 'placeholder' => '医保中心分配的 fixmedins_code', 'default' => '', 'monospace' => true),
                array('key' => 'integration.outbound.insurance.secret_key', 'zone' => 'outbound', 'type' => 'input',
                    'label' => '接口密钥', 'placeholder' => '医保前置机分配的密钥', 'default' => '', 'monospace' => true),
                // ---------- 支付（公共配置，历史键保留兼容） ----------
                array('key' => 'pay_wechat_mchid', 'type' => 'input',
                    'label' => '微信支付商户号', 'placeholder' => '如 1900000109', 'default' => '', 'monospace' => true),
                array('key' => 'pay_wechat_appid', 'type' => 'input',
                    'label' => '微信支付 AppID', 'placeholder' => '公众号/小程序 AppID', 'default' => '', 'monospace' => true),
                array('key' => 'pay_alipay_appid', 'type' => 'input',
                    'label' => '支付宝应用 AppID', 'placeholder' => '支付宝开放平台应用 ID', 'default' => '', 'monospace' => true),
                array('key' => 'pay_aggregate_mode', 'type' => 'select',
                    'label' => '聚合收单模式', 'default' => 'off', 'options' => array(
                        'off' => '未启用（默认，仅现金/线下收费）',
                        'wechat' => '仅微信支付',
                        'alipay' => '仅支付宝',
                        'both' => '微信 + 支付宝聚合',
                    )),
            ),
        ),

        /* ==================== 存证 / 电子签名 ==================== */
        array(
            'id' => 'evid', 'emoji' => render_icon('action:edit'), 'title' => '存证 / 签名',
            'desc' => '电子病历与诊断证明的存证扩展接口（时间戳 + 数字证书/CA 对接，正式医疗机构使用）',
            'endpoints' => array(),
            'fields' => array(
                array('key' => 'evid_mode', 'type' => 'select',
                    'label' => '存证模式', 'default' => 'off', 'options' => array(
                        'off' => '关闭（默认，不进行存证）',
                        'hash' => '本地哈希指纹（SHA-256 摘要入库，自证完整）',
                        'http' => '外部存证服务（调用自定义 HTTP 接口对接 CA/时间戳服务）',
                    )),
                array('key' => 'evid_endpoint', 'type' => 'input',
                    'label' => '外部存证/签名服务接口地址', 'placeholder' => '如 https://ca.hospital.local/evidence', 'default' => '', 'monospace' => true),
                array('key' => 'evid_token', 'type' => 'input',
                    'label' => '存证服务认证令牌', 'placeholder' => '第三方存证服务分配的接口令牌', 'default' => '', 'monospace' => true),
                array('key' => 'evid_signer', 'type' => 'input',
                    'label' => '电子签名人（印章信息）', 'placeholder' => '如 某医院医务科电子印章', 'default' => ''),
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

/* ============================================================
 * 配置读取（新命名空间 + 旧键回退）
 * ------------------------------------------------------------
 * 新键存于 integration.outbound.* / integration.inbound.*；
 * 旧平铺键（pacs_/hl7_/fhir_/his_/yibao_）保留供旧调用方兼容。
 * $key 支持两种写法：完整键（integration.outbound.fhir.enabled）
 * 或省略前缀（outbound.fhir.enabled），内部自动归一。
 * 读取顺序：新键 → 旧键（$legacy 指定）→ 默认值。
 * ============================================================ */
function integration_cfg($key, $default = '', $legacy = null) {
    if (strpos($key, 'integration.') !== 0) {
        $key = 'integration.' . $key;
    }
    $v = setting($key, null);
    if ($v !== null && $v !== '') return $v;
    if ($legacy !== null && $legacy !== '') {
        $lv = setting($legacy, '');
        if ($lv !== '') return $lv;
    }
    return $default;
}

/** 布尔开关读取（1/true/on 均为真） */
function integration_flag($key, $legacy = null) {
    return in_array(strtolower((string)integration_cfg($key, '', $legacy)), array('1', 'true', 'on', 'yes'), true);
}

/** 旧 HL7 传输值兼容：'mllp'/'http' → 'mllp_tcp'/'http_post' */
function integration_hl7_transport() {
    $t = integration_cfg('outbound.hl7.transport', 'mllp_tcp', 'hl7_transport');
    if ($t === 'mllp') return 'mllp_tcp';
    if ($t === 'http') return 'http_post';
    return $t;
}

/* ============================================================
 * 入向开放端点（只读展示 + 一键复制）
 * ============================================================ */
function integration_host_base() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    return $scheme . '://' . $host;
}

/** 返回全部入向端点（含完整 URL 与说明） */
function integration_inbound_endpoints() {
    $base = integration_host_base();
    $list = array();
    foreach (integration_field_groups() as $g) {
        if (empty($g['endpoints'])) continue;
        foreach ($g['endpoints'] as $ep) {
            $list[] = array(
                'group' => $g['id'],
                'group_title' => $g['title'],
                'label' => $ep['label'],
                'method' => isset($ep['method']) ? $ep['method'] : '',
                'url' => $ep['path'] !== '' ? $base . $ep['path'] : '',
                'note' => isset($ep['note']) ? $ep['note'] : '',
                'example' => isset($ep['example']) ? $ep['example'] : '',
            );
        }
    }
    return $list;
}

/* ============================================================
 * 入向调用审计落账（inbound_events 表，监控面板溯源用）
 * ============================================================ */
function integration_log_inbound($endpoint, $provider, $ok, $summary, $body = '') {
    try {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        DB::insert(
            'INSERT INTO inbound_events(endpoint, provider, ok, summary, body, remote_ip, created_at) VALUES(?,?,?,?,?,?,?)',
            array($endpoint, $provider, $ok ? 1 : 0, (string)$summary, substr((string)$body, 0, 8000), $ip, now_str())
        );
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[inbound_events 落账失败] ' . $ex->getMessage());
    }
}

/* ============================================================
 * 业务触发钩子（本地事务提交后调用，异步入队，绝不阻塞主流程）
 * ------------------------------------------------------------
 * 说明：挂号/开单/缴费/发药等业务在本地事务 COMMIT 之后调用本组函数，
 * 将同步任务写入 his_sync_tasks（Outbox）。任务由后台 worker
 * （tools/cli/integration_outbox_run.php，或监控面板手动触发）投递。
 * 全部调用包裹 try/catch——Outbox 写入失败仅记录日志，不阻塞业务。
 * ============================================================ */

/** 触发后台 worker（fire-and-forget；失败仅日志，任务保持 pending 由定时/手动重试） */
function integration_spawn_worker() {
    if (ConfigStore::get('integration.outbox.spawning', '') === '1') return;
    $script = APP_ROOT . '/tools/cli/integration_outbox_run.php';
    if (!is_file($script)) return;
    $runner = '';
    foreach (array('~/.local/bin/frankenphp', '/usr/local/bin/frankenphp', '/opt/homebrew/bin/frankenphp') as $p) {
        $p = str_replace('~', isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '', $p);
        if (is_file($p)) { $runner = $p; break; }
    }
    if ($runner === '') $runner = 'frankenphp';
    $cmd = 'nohup ' . $runner . ' php-cli ' . $script . ' > /dev/null 2>&1 &';
    @pclose(@popen($cmd, 'r'));
}

/** 入队封装（重复业务合并为同一条任务；成功后不再重发） */
function integration_enqueue($businessType, $businessId, $payload) {
    require_once APP_ROOT . '/app/services/his/HisOutbox.php';
    try {
        HisOutbox::enqueue($businessType, (int)$businessId, $payload);
        integration_spawn_worker();
    } catch (Exception $ex) {
        if (defined('DEBUG') && DEBUG) error_log('[Outbox 入队失败] ' . $ex->getMessage());
    }
}

/**
 * 挂号完成钩子：向 HIS 上报挂号（sync_registration）+ 发送 HL7 ADT^A04。
 * @param int $visitId 挂号记录 id
 */
function integration_after_registration($visitId) {
    if (!ConfigStore::isSystemInstalled()) return;
    $visitId = (int)$visitId;
    if ($visitId <= 0) return;
    if (integration_flag('outbound.his.enabled') && integration_flag('outbound.his.sync_registration')) {
        integration_enqueue('his_registration', $visitId, array('visit_id' => $visitId));
    }
    if (integration_flag('outbound.hl7.enabled')) {
        integration_enqueue('hl7_adt', $visitId, array('visit_id' => $visitId));
    }
}

/**
 * 开单完成钩子：处方/检查下达后推送 FHIR Bundle、发送 HL7 ORM^O01、LIS 下发申请。
 * @param int $orderId 开单记录 id
 */
function integration_after_order($orderId) {
    if (!ConfigStore::isSystemInstalled()) return;
    $orderId = (int)$orderId;
    if ($orderId <= 0) return;
    if (integration_flag('outbound.fhir.enabled')) {
        $o = OrderRepository::one('SELECT visit_id, order_type FROM orders WHERE id=?', array($orderId));
        if ($o && (int)$o['visit_id'] > 0) {
            integration_enqueue('fhir_bundle', (int)$o['visit_id'], array('visit_id' => (int)$o['visit_id']));
        }
    }
    if (integration_flag('outbound.hl7.enabled')) {
        integration_enqueue('hl7_orm', $orderId, array('order_id' => $orderId));
    }
    if (integration_flag('outbound.lis.enabled')) {
        $o2 = OrderRepository::one('SELECT order_type FROM orders WHERE id=?', array($orderId));
        if ($o2 && $o2['order_type'] === 'lab') {
            integration_enqueue('lis_order', $orderId, array('order_id' => $orderId));
        }
    }
}

/**
 * 结算钩子：收费/退费完成后向 HIS 上报结算流水（sync_settlement）。
 * @param int $paymentId 缴费流水 id
 */
function integration_after_settlement($paymentId) {
    if (!ConfigStore::isSystemInstalled()) return;
    $paymentId = (int)$paymentId;
    if ($paymentId <= 0) return;
    if (integration_flag('outbound.his.enabled') && integration_flag('outbound.his.sync_settlement')) {
        integration_enqueue('his_settlement', $paymentId, array('payment_id' => $paymentId));
    }
}

/**
 * 发药钩子：处方发药/库存核减后向 HIS 上报（sync_prescription）。
 * @param int $orderId 处方单 id
 */
function integration_after_dispense($orderId) {
    if (!ConfigStore::isSystemInstalled()) return;
    $orderId = (int)$orderId;
    if ($orderId <= 0) return;
    if (integration_flag('outbound.his.enabled') && integration_flag('outbound.his.sync_prescription')) {
        integration_enqueue('his_prescription', $orderId, array('order_id' => $orderId));
    }
}