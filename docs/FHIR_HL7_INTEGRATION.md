# FHIR R4 / HL7 MLLP 集成与全链路验证手册

本手册面向区域平台 / PACS / LIS / HIS 对接方与院内联调人员，覆盖：

1. 生成开箱即用的验证种子数据
2. FHIR R4 鉴权（OAuth2 / 静态 Token）与资源读取 / 多条件检索
3. HL7 v2 `ORU^R01` MLLP 报文发送与危急值闭环验证
4. 无效 Token（401）/ 未授权 Scope（403）异常拦截验证

> 基地址示例统一使用 `http://127.0.0.1:8000`（`npm run dev` 默认端口；生产以实际域名为准）。
> FHIR 基地址：`http://127.0.0.1:8000/api/fhir/r4`

---

## 0. 准备：启动服务并生成验证数据

```bash
# 本地无系统 PHP 时统一前缀：~/.local/bin/frankenphp php-cli
# ① 启动开发服务器（另开窗口常驻）
npm run dev

# ② 生成 3 套 FHIR/HL7 全链路验证数据（含 DICOM UID/Series 与危急值）
#    若尚未生成基础字典，可一次执行：--scene=fhir
php tools/bin/seed.php --scene=fhir
```

种子生成后，系统内将具备：

| 患者号 | 姓名 | 门诊流水号 | 诊断(ICD-10) | 危急值 |
|--------|------|-----------|--------------|--------|
| `FHD0001` | 张伟 | `FHD<ymd>0001` | J06.900 急性上呼吸道感染 | 白细胞计数(WBC) 45.0 ↑↑ |
| `FHD0002` | 李娜 | `FHD<ymd>0002` | K29.500 慢性胃炎 | —（可经 ORU 触发） |
| `FHD0003` | 王强 | `FHD<ymd>0003` | I10.x00 原发性高血压 | 空腹血糖 25.6 ↑↑ |

种子同时写入以下**演示凭证**（可在「管理员 → 接口管理 → FHIR / HL7 / LIS / HIS」查看或修改）：

| 用途 | 值 |
|------|----|
| FHIR 静态 Token（全资源只读） | `FHIR-DEMO-TOKEN` |
| FHIR 静态 Token（仅影像/患者） | `PACS-DEMO-TOKEN` |
| FHIR OAuth2 客户端 | `client_id=pacs` / `client_secret=PacsSecret123`（Scope：ImagingStudy/Patient/Encounter/Observation/Condition/MedicationRequest `.read`）|
| HIS 入向 Token | `HIS-DEMO-TOKEN` |
| LIS Webhook 密钥 | `LIS-DEMO-TOKEN` |
| DICOMweb 入向 Token | `PACS-DICOM-TOKEN`（`X-API-Key` 或 `Bearer`，Scope `pacs:read`）|

---

## 1. CapabilityStatement（免认证）

```bash
curl -s http://127.0.0.1:8000/api/fhir/r4/metadata | python3 -m json.tool
```

预期：`fhirVersion=4.0.1`、`status=active`、`kind=instance`，`rest[0].security` 含
SMART-on-FHIR 与 `oauth-uris` 扩展，`resource[]` 如实声明 6 类资源及各自搜索参数。

---

## 2. 获取 OAuth2 令牌（client_credentials）

```bash
curl -s -X POST http://127.0.0.1:8000/api/fhir/oauth/token \
  -d "grant_type=client_credentials" \
  -d "client_id=pacs" \
  -d "client_secret=PacsSecret123" \
  -d "scope=system/ImagingStudy.read system/Patient.read" | python3 -m json.tool
```

预期响应：

```json
{
  "access_token": "<HMAC 自包含令牌>",
  "token_type": "Bearer",
  "expires_in": 7200,
  "scope": "system/ImagingStudy.read system/Patient.read"
}
```

后续请求以 `Authorization: Bearer <access_token>` 携带；离线环境亦可直接使用静态
`FHIR-DEMO-TOKEN`（等价于 `system/*.read`）。

---

## 3. 资源读取

```bash
TOKEN=FHIR-DEMO-TOKEN

# 3.1 Patient 单资源读取（id 规则 patient-{patient_no}）
curl -s "http://127.0.0.1:8000/api/fhir/r4/Patient/patient-FHD0001" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 3.2 ImagingStudy 单资源读取（imagingstudy-{id}）
curl -s "http://127.0.0.1:8000/api/fhir/r4/ImagingStudy/imagingstudy-$( \
  curl -s "http://127.0.0.1:8000/api/fhir/r4/ImagingStudy?patient=FHD0001" \
  -H "Authorization: Bearer $TOKEN" | python3 -c 'import sys,json;print(json.load(sys.stdin)["entry"][0]["resource"]["id"].split("-")[-1])')" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
```

---

## 4. 多条件检索（Bundle / 分页 / 排序 / _include）

```bash
TOKEN=FHIR-DEMO-TOKEN

# 4.1 Patient 按姓名/性别/出生日期检索 + 分页
curl -s "http://127.0.0.1:8000/api/fhir/r4/Patient?name=张&gender=male&_count=5&_page=1" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 4.2 Encounter 按患者检索并 _include 患者，_sort 倒序
curl -s "http://127.0.0.1:8000/api/fhir/r4/Encounter?patient=FHD0001&_include=Encounter:patient&_sort=-date" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 4.3 Condition（ICD-10）
curl -s "http://127.0.0.1:8000/api/fhir/r4/Condition?patient=FHD0001&code=J06.900" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 4.4 Observation（检验结果，默认 laboratory）
curl -s "http://127.0.0.1:8000/api/fhir/r4/Observation?patient=FHD0001" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 4.5 Observation（生命体征）
curl -s "http://127.0.0.1:8000/api/fhir/r4/Observation?patient=FHD0001&category=vital-signs" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 4.6 MedicationRequest（处方）
curl -s "http://127.0.0.1:8000/api/fhir/r4/MedicationRequest?patient=FHD0001&status=completed" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 4.7 ImagingStudy：按患者 + _include，按检查日期与模态检索
curl -s "http://127.0.0.1:8000/api/fhir/r4/ImagingStudy?patient=FHD0001&modality=CT&_include=ImagingStudy:patient" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 4.8 ImagingStudy：按 DICOM StudyInstanceUID 检索（identifier system=urn:dicom:uid）
STUDY_UID=$(curl -s "http://127.0.0.1:8000/api/fhir/r4/ImagingStudy?patient=FHD0001" \
  -H "Authorization: Bearer $TOKEN" | python3 -c 'import sys,json;print(json.load(sys.stdin)["entry"][0]["resource"]["identifier"][0]["value"])')
curl -s --get "http://127.0.0.1:8000/api/fhir/r4/ImagingStudy" \
  --data-urlencode "identifier=urn:dicom:uid|$STUDY_UID" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
```

预期：返回 `resourceType=Bundle`、`type=searchset`、含 `total` 与
`link[relation=self|next]`；`_include` 时 Bundle 中同时出现 `mode=include` 的 Patient。

### 4.9 ServiceRequest（影像医嘱）与 Task（检查工作项）

```bash
# 摄片登记工作列表：已缴费可执行的影像医嘱
curl -s "http://127.0.0.1:8000/api/fhir/r4/ServiceRequest?status=active&_include=ServiceRequest:patient" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool

# 工作项：待登记 / 已登记待摄片 / 摄片中（含患者、检查号与服务请求引用）
curl -s "http://127.0.0.1:8000/api/fhir/r4/Task?status=requested,accepted,in-progress&_include=Task:patient" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
```

- 状态机（对齐 IHE Scheduled Workflow / MPPS）：`requested`（待登记）→
  `accepted`（已登记待摄片）→ `in-progress`（摄片中）→ `completed`（已摄片）；`cancelled`（退费/取消）。
- `ImagingStudy` 仅在**摄片完成后**出现（`status=available`）。

### 4.10 工作项回写（PACS → 门诊，标准 FHIR 写入）

```bash
# PACS 侧登记：PUT Task 置 accepted（需 system/Task.write Scope）
curl -s -X PUT "http://127.0.0.1:8000/api/fhir/r4/Task/task-{order_item_id}" \
  -H "Authorization: Bearer $WRITE_TOKEN" -H "Content-Type: application/fhir+json" \
  -d '{"resourceType":"Task","status":"accepted","businessStatus":{"text":"已登记待摄片"},"owner":{"display":"PACS"},"executionPeriod":{"start":"2026-10-07T10:00:00+08:00"}}' \
  | python3 -m json.tool
```

- 写权限：令牌 Scope 需含 `system/Task.write`（登记/摄片回写）、`system/ServiceRequest.read`
  与 `system/Task.read`（读工作列表）；读接口仍需 `system/*.read`。
- 副作用：登记 / 摄片回写 `accepted/in-progress/completed` 时，若开单明细仍为 `paid` 会推进为
  `registered`；回写 `cancelled` 联动为 `refunded`。

---

## 5. HL7 MLLP `ORU^R01` 测试与危急值闭环

### 5.1 启动 MLLP 守护进程

监听端口取「接口管理 → HL7 → 入向 → MLLP 本地监听端口」（默认 `2575`）：

```bash
# 无系统 PHP 时：
~/.local/bin/frankenphp php-cli tools/cli/hl7_mllp_server.php
```

### 5.2 发送 ORU^R01 报文（nc / netcat）

先取 `FHD0002` 的检验申请单号：

```bash
ORDER_NO=$(php -r '
require "app/config/bootstrap.php"; DatabaseManager::initAll();
echo DatabaseManager::val("SELECT order_no FROM orders WHERE order_type=\"lab\" AND patient_no=\"FHD0002\" ORDER BY id DESC LIMIT 1");
')
echo "ORDER_NO=$ORDER_NO"
```

以 MLLP 帧（`0x0B` 起始、`0x1C 0x0D` 结束）发送（`printf` 生成帧字符，管道给 `nc`）：

```bash
{
  printf '\x0B'
  printf 'MSH|^~\\&|LIS|LAB|CLINIC|HOSP|20261001150000||ORU^R01|MSG00042|P|2.4\r'
  printf 'PID|1||FHD0002||李娜||19860322|F\r'
  printf 'PV1|1|O\r'
  printf 'OBR|1|%s|9001|肝功能^肝功能\r' "$ORDER_NO"
  printf 'OBX|1|NM|ALT^谷丙转氨酶(ALT)||500|U/L|7-40|HH|||F\r'
  printf 'OBX|2|NM|TBIL^总胆红素(TBIL)||18.5|umol/L|3.4-17.1|H|||F\r'
  printf 'OBX|3|NM|ALB^白蛋白(ALB)||42|g/L|35-55|N|||F\r'
  printf '\x1C\x0D'
} | nc 127.0.0.1 2575
```

预期 ACK：

```
MSH|^~\&|CLINIC_OPD|...|ACK|<ctl>|P|2.4
MSA|AA|MSG00042|已回填 3 项检验结果；已触发 1 条危急值流转
```

### 5.3 验证落库与危急值

```bash
# ① 结果已回填（values_json 含异常标志 HH/H）
php -r '
require "app/config/bootstrap.php"; DatabaseManager::initAll();
foreach (DatabaseManager::q("SELECT r.id, r.values_json FROM results r JOIN orders o ON o.id=(SELECT order_id FROM order_items WHERE id=r.order_item_id) WHERE o.patient_no=\"FHD0002\" ORDER BY r.id DESC LIMIT 3") as $r)
  echo $r["id"]." ".$r["values_json"]."\n";
'
# ② 危急值流水已生成（status=pending，接收医生为开单医生）
php -r '
require "app/config/bootstrap.php"; DatabaseManager::initAll();
foreach (DatabaseManager::q("SELECT patient_no,item_name,status,to_doctor_name FROM critical_values WHERE patient_no=\"FHD0002\"") as $r)
  echo $r["patient_no"]." ".$r["item_name"]." ".$r["status"]." -> ".$r["to_doctor_name"]."\n";
'
```

`ORU` 亦可通过 HTTP 代理接收（携带 token，Scope 需 `report:write`）：

```bash
curl -s -X POST http://127.0.0.1:8000/api/external/hl7/receiver \
  -H "X-HL7-Token: LIS-DEMO-TOKEN" -H "Content-Type: text/plain" \
  --data-binary @- <<'HL7'
MSH|^~\&|LIS|LAB|CLINIC|HOSP|20261001150000||ORU^R01|MSG00043|P|2.4
PID|1||FHD0002||李娜||19860322|F
OBR|1|JY2026100114224692|9001|肝功能^肝功能
OBX|1|NM|ALT^谷丙转氨酶(ALT)||500|U/L|7-40|HH|||F
HL7
```

### 5.4 解析失败回执（AE/AR + ERR）

发送缺少 MSH 段的报文，预期 `MSA|AR|...` 并附 `ERR` 段：

```bash
{ printf '\x0B'; printf 'PID|1||X\r'; printf '\x1C\x0D'; } | nc 127.0.0.1 2575
# 预期：MSA|AR||无法解析 HL7 消息（缺少 MSH 段）|ERR|^^^100|...
```

---

## 6. 异常拦截验证（401 / 403）

### 6.1 缺少 Token → 401

```bash
curl -i http://127.0.0.1:8000/api/fhir/r4/Patient
# HTTP/1.1 401 Unauthorized
# WWW-Authenticate: Bearer error="invalid_token"
# {"resourceType":"OperationOutcome","issue":[{"severity":"error","code":"security",...}]}
```

### 6.2 无效 Token → 401

```bash
curl -i http://127.0.0.1:8000/api/fhir/r4/Patient -H "X-API-Key: bad-token"
```

### 6.3 Scope 不足 → 403

用仅授权 `ImagingStudy/Patient` 的 OAuth 令牌访问 `Observation`：

```bash
ACC=$(curl -s -X POST http://127.0.0.1:8000/api/fhir/oauth/token \
  -d "grant_type=client_credentials&client_id=pacs&client_secret=PacsSecret123&scope=system/ImagingStudy.read system/Patient.read" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')

curl -i "http://127.0.0.1:8000/api/fhir/r4/Observation?_count=1" -H "Authorization: Bearer $ACC"
# HTTP/1.1 403 Forbidden
# {"resourceType":"OperationOutcome","issue":[{"severity":"error","code":"forbidden","diagnostics":"无权限访问该资源（缺少 Scope：system/Observation.read）"}]}
```

### 6.4 开放网关 Scope 隔离（HIS 只读查询）

```bash
# patient_get（HIS-DEMO-TOKEN 具备 patient:read）→ 200
curl -s "http://127.0.0.1:8000/api/external/his/read?action=patient_get&patient_no=FHD0001" \
  -H "X-HIS-Token: HIS-DEMO-TOKEN" | python3 -m json.tool

# 无效 Token → 401
curl -i "http://127.0.0.1:8000/api/external/his/read?action=ping" -H "X-HIS-Token: wrong"
```

### 6.5 凭证过期 / 禁用 → 401 / 403

在「接口管理 → FHIR 入向」将静态 Token 行的过期时间改为过去（或启用状态改为禁用），
再次调用受保护端点：

- 过期：`HTTP 401` + `WWW-Authenticate: Bearer error="invalid_token"`
- 禁用：`HTTP 403` + `OperationOutcome code=forbidden`（“该凭证已禁用”）

LIS/HIS 单密钥模块可分别配置 `..._expires_at` / `..._enabled` / `..._scopes` 元数据键验证同一套行为。

---

## 6.6 DICOMweb 入向服务（QIDO-RS / WADO-RS）

本系统对外提供轻量 DICOMweb 服务（数据源为影像引用，含真实 Study/Series UID）：

```bash
DTOKEN=PACS-DICOM-TOKEN

# QIDO-RS：按患者检索检查（DICOM JSON）
curl -H "X-API-Key: $DTOKEN" \
  "http://127.0.0.1:8000/api/dicomweb/studies?PatientID=FHD0001"

# 取 StudyInstanceUID 后检索序列
SUID=$(curl -s -H "X-API-Key: $DTOKEN" \
  "http://127.0.0.1:8000/api/dicomweb/studies?PatientID=FHD0001" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)[0]["0020000D"]["Value"][0])')
curl -H "X-API-Key: $DTOKEN" "http://127.0.0.1:8000/api/dicomweb/studies/$SUID/series"

# WADO-RS：实例元数据（不含像素）
curl -H "Authorization: Bearer $DTOKEN" \
  "http://127.0.0.1:8000/api/dicomweb/studies/$SUID/metadata"
```

- 鉴权：与 FHIR 一致，`X-API-Key: <Token>` 或 `Authorization: Bearer <Token>`，**只填 Token 原文**。
- 未启用 / Token 无效 / IP 不在白名单 → 401；Scope 不足 → 403。
- 出向调阅外部 PACS 时，「接口管理 → DICOM/PACS → 出向调阅」的鉴权已拆为「方式（无/Bearer/API Key/Basic/自定义）+ 值」，按方式自动拼装请求头，无需手写前缀。

## 7. 接口帮助（每个子模块内置教程）

接口管理每个子 Tab（FHIR / DICOM-PACS / HL7 / LIS / HIS / 医保支付 / 存证）左侧栏底部均有「帮助」入口，
点击以模态浏览器内嵌打开对应独立 HTML 教程页（介绍、功能、参数、使用方法、测试方法、错误码），支持新窗口打开。
帮助页静态地址：`/assets/help/integration-{fhir|pacs|hl7|lis|his|insurance|evid}.html`。

## 8. 常见问题

- **`/metadata` 返回 403“FHIR 入向接口未启用”**：前往「管理员 → 接口管理 → FHIR → 入向开放」开启「启用入向开放」并保存（种子已自动开启）。
- **`_include` 未返回患者**：确认参数为 `_include=Encounter:patient` 或 `_include=ImagingStudy:patient`（或 `_include=*`）。
- **MLLP 收不到 ACK**：确认端口与守护进程一致、IP 白名单放行来源；报文须以 `0x0B` 起、`0x1C 0x0D` 止。
- **ORU 回填失败（申请单不存在）**：`OBR-2` 必须为系统内 `orders.order_no`（或 `flow_no`）。
