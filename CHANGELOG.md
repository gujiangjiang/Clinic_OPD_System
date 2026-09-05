# 更新日志（CHANGELOG）

本项目的所有重要变更都会记录在此文件中，便于回溯每个版本的改动。

> 格式说明（参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 的简化版）：
> - **新增**（Added）：新增的功能
> - **修复**（Fixed）：修复的缺陷
> - **变更**（Changed）：行为 / 界面的调整
> - **移除**（Removed）：删除的功能
> - **安全**（Security）：安全相关修复
>
> 版本号遵循 `主版本.次版本.修订号`，每次提交代码时如有功能变化请同步更新本文件。

---

## [6.9.0] - 2026-09-05

> 管理端叫号管理优化：选择科室模态框大屏统计实时更新，新建/删除大屏后无需刷新页面即可看到。

### 修复

- **选择科室模态框大屏统计不实时更新**：叫号管理页的科室统计（CM_DEPS）仅在页面加载时获取一次，
  新建/删除/编辑/重置 Token/强制释放大屏后，再次点击「选择科室」仍显示旧统计（如新建后仍提示
  「无大屏」）。修复：新增 `/api/admin?action=room_stats` 接口返回全科室大屏统计，增删改等操作
  成功后自动刷新统计并同步到模态框数据源。
  （`app/api/parts/admin_call.php`、`app/views/admin/callmanage.php`）

## [6.8.0] - 2026-09-05

> 叫号大屏语音播报优化：语音呼叫始终使用患者全名，不受屏幕文字脱敏影响。

### 修复

- **语音呼叫脱敏名怪声**：叫号大屏语音播报原在「患者姓名脱敏」开启时呼叫脱敏名
  （如「张*三 请到 XX 诊室」），十分怪异。修复：脱敏仅作用于大屏屏幕文字（name），
  语音播报（raw_name）始终下发真实全名，实现「屏幕脱敏、语音全名」。
  （`app/api/screen.php`、`public/assets/js/components/screen.js`）

## [6.7.0] - 2026-09-05

> 缴费管理与病历退费流程优化：未缴费项目改模态框、凭条详情按项目独立进度并含收费/退费
> 完整记录、退费流程与病历解耦（退费只影响费用结算，不影响病历文书）、详情页按钮布局统一。

### 新增

- **未缴费项目模态框**：缴费管理右侧不再铺开未缴费明细，改为顶部简洁提示条（N 项未缴费 + 合计 +
  「查看并缴费」按钮），点击弹出模态框展示全部未缴费项目（挂号费+开单），配合多选框、全选开关、
  实时合计与「一键全部缴费」「批量缴费（已选 N）」两个按钮；批量缴费按钮未勾选时 disabled、
  勾选后启用，一键全部缴费不受勾选影响。（`app/api/parts/cashier_read.php`、`app/views/cashier/paymanage.php`）
- **缴费凭条详情优化**：
  - 凭条头部显示 收费时间/收费方式/收费员；退费后保留收费信息并追加红色行
    ✕ 退费时间 ｜ 退费员 ｜ 退费理由（若有）
  - 项目进度按「每项目独立」展示（同一凭条不同医生开单、不同项目执行进度各异），
    项目行两段式（名称左 2/3 + 进度右 1/3 靠分隔线左对齐），进度只显示节点不显示姓名
  - 退费项目进度：保留「缴费」记录，缴费下方追加「已退费」红色节点（✕），
    后续执行节点（登记/报告/发药/执行完成）照常显示——已执行的项目经站内消息确认退费后，
   执行记录仍保留（按实际执行痕迹判定 done），未执行的项目显示 ○
  - 医生端开单详情/处方详情/处置详情的进度列同步此规则（`order_flow_steps`）
  （`app/api/parts/cashier_read.php`、`app/core/helpers.d/visit.php`、`app/views/cashier/paymanage.php`）

### 修复

- **退费只影响费用结算，不影响病历文书（重大逻辑）**：开单是病历的法律快照，退费后病历右侧
  大纲与病历正文（辅助检查/门诊处置/处方）的开单不应被隐藏或删除。修复：退费项目一律保留展示，
  仅费用结算（总费用徽章/费用悬浮窗/左栏金额）排除退费；删除权限仅限开单医生本人，任何人
  （含收费员）不能自动隐藏或删除医生开单。
  （`app/api/record.php`、`app/api/parts/order_read.php`、`app/includes/print/print_record.php`、
  `public/assets/js/components/emr.js`、`emr_orders.js`）
- **病历不显示「已退费」文字标注**：病历客观记录就诊与开单事实，与费用无关。退费项目在病历
  中正常展示、不标注费用状态；左侧大纲以「深灰圆点」区分已退费（未缴费浅灰、已退费深灰），
  仅靠圆点颜色区分、无文字。（`emr.js`、`emr_orders.js`、`components.css`）
- **续写文书打印缺失检查/检验**：`print_record.php` 辅助检查节原对续写文书只显示手工字段、
  丢弃本记录名下的开单（检查/检验），导致「处置/处方显示、检查/检验不显示」。修复：首诊/
  续写/会诊文书均合并本记录名下开单（record_id 强关联过滤），退费项目同样打印。
- **退费后右侧列表空白**：`searchVisits` 刷新左侧会重置右侧空态。修复：新增 keepDetail 参数，
  退费/缴费回调仅刷新左侧、保留右侧已加载详情，并保持当前选中项高亮。
- **退费后凭条详情按钮丢失**：整单退费分支原隐藏「📋 详情」按钮。修复：退费后仍可查看详情
  （项目执行进度），仅隐藏补打凭条与退费按钮。
- **统一详情页按钮间距**：开单详情与处方详情左下角打印/删除按钮间距不一致（开单页紧贴）。
  修复：删除按钮统一为 `margin-left:8px`，开单/处方/处置详情一致。

## [6.6.0] - 2026-09-04

> 本次为大规模代码复盘优化版本：修复逻辑漏洞 40+ 处、清理冗余死代码约 700 行、
> 抽取 8 个公共函数、前端工具收敛，全部改动保证逻辑与功能等价，经语法检查与
> 新旧实现对照验证。

### 安全

- **医生跨科室越权封堵**：候诊队列/候诊列表/诊室列表的 `dept_id` 参数此前无科室归属校验，
  医生可枚举任意科室患者姓名/流水号并查看任意诊室。修复：`dept_id` 必须在本人关联科室
  范围内，否则拒绝。（`app/api/parts/doctor/doctor_call_queue.php`、`doctor_queue_list.php`、`doctor_get_available_rooms.php`、`doctor_write.php`）
- **护士站越权封堵**：护理记录列表 `nursing_list`、处方详情 `med_detail` 此前无科室归属校验；
  待处置/待执行医嘱列表无科室过滤。修复：补 `nurse_visit_allowed` 校验 + 列表按就诊科室过滤。
  （`app/api/nurse.php`、`app/repositories/OrderRepository.php`）
- **退费流程角色/归属校验**：`check`/`apply` 此前任何 refund 角色可调用（医生/护士可代发起退费），
  `detail` 无归属校验（IDOR 可枚举他人退费申请）。修复：`check`/`apply` 限定收费员/管理员，
  `detail` 校验发起人/审批人/管理员。（`app/api/refund.php`）
- **病历模板越权读取**：`template.get?for_apply=1` 此前绕过全部可见性过滤，任意医生可读他人
  私有/待审核模板。修复：for_apply 采用与 list 一致的可见性规则。（`app/api/template.php`）
- **会诊列表跨科室查看**：`consultation.list` 的 `dept_id` 未校验归属。修复：限定本人科室。
  （`app/api/consultation.php`）
- **会诊病历归属校验**：`EmrContextResolver` 会诊可写分支未校验 `doctor_id`，同科室其他医生可
  改他人会诊文书。修复：仅书写医生本人可编辑。（`app/core/EmrContextResolver.php`）
- **XSS 封堵**：运营分析页科室/医生/转归/自定义统计多字段、通用选择器搜索结果、日期/方案下拉
  未转义拼 innerHTML——统一 `Clinic.escHtml`。（`app/views/admin/analytics.php`、`public/assets/js/components/selector.js`）
- **HIS 接口密钥泄漏面**：`api_key` 支持 GET 参数传递会进入 Web 日志/浏览器历史/Referer。
  修复：仅接受 `X-HIS-Key` 请求头，同步更新 README 示例。（`app/api/his.php`、`README.md`）
- **CSRF 补同源校验**：POST 校验 Origin（或 Referer 兜底）必须为本站，防 SameSite 不可靠场景。
  （`app/core/CSRF.php`）
- **CSV 导出公式注入防护**：以 `= + - @` 开头的单元格前缀单引号，防 Excel 将数据当公式执行。
  （`app/core/DataExportImport.php`）

### 修复

- **药房库存超扣竞态**：`stock` 出库为读判写分离（读库存→判充足→扣减），并发可超扣。
  修复：原子条件更新 `UPDATE drugs SET qty=qty-? WHERE id=? AND qty>=?`，type 白名单校验。
  （`app/api/pharmacy.php`）
- **子药库存泄漏**：开单时主药+子药均扣库存，但药房驳回/退费/删单恢复仅恢复主药（`sub_of=0`）。
  修复：三处恢复口径统一覆盖全部药品明细。（`app/api/pharmacy.php`、`app/api/parts/cashier_write.php`、`app/api/parts/order/order_delete.php`）
- **检验/影像报告生成无事务**：`save_result` 多表写（results + 回写 + reports + 状态）无事务，
  `done` 状态可重复提交生成重复报告。修复：包原生事务 + done 且已有非撤回报告时拦截重复提交。
  （`app/api/lab.php`、`app/api/imaging.php`）
- **user 数据导入必失败**：`admin_import` user 模块 SQL 占位符比绑定参数少 1（password 列在
  生成 SQL 后才追加），导入必抛 PDO 异常。修复：密码列在生成 SQL 前加入。（`app/api/parts/admin_import.php`）
- **导入 overwrite 策略空实现**：选择「覆盖」时冲突行实际被静默忽略。修复：按唯一键逐行 UPDATE
  （密码除外），预览时附带完整冲突数据。（`app/api/parts/admin_import.php`）
- **并发安装可产生两个管理员**：`install.php` 先查后插无锁。修复：事务内原子检查+创建+提交。
  （`app/api/install.php`）
- **登录失败计数竞态**：失败计数 read-modify-write 并发下互相覆盖。修复：原子 UPDATE +
  阈值条件上锁。（`app/core/Auth.php`）
- **混淆密钥并发首访竞态**：两个并发首访各自生成密钥互相覆盖，窗口期旧密文解不开。
  修复：写入后回读取库中最终值。（`app/core/IdObfuscator.php`）
- **药房新增药品表单断链**：规格编辑器 `openSpecEditor/seSaveSpec` 仅管理端内联定义，药房点击
  即报错，且保存丢规格/皮试字段。修复：抽取公共组件 `drugform.js` 全站加载，药房保存补齐字段
  与必填校验。（`public/assets/js/components/drugform.js`、`app/api/pharmacy.php`、`app/includes/layout.php`）
- **叫号管理 onclick 注入面**：诊室名/Token 拼进 onclick 字符串（引号可截断）。修复：改事件委托
  `data-room-action`。（`app/api/parts/admin_call.php`、`app/views/admin/callmanage.php`）
- **JS 请求失败静默**：开单目录加载失败弹窗毫无反应、候诊队列失败面板空白、大屏断连无提示。
  修复：均补 onError/失败提示/连续失败渲染断连提示。（`order.js`、`queuepanel.js`、`screen.js`）
- **EMR 30 秒轮询定时器不清理**：长驻页面反复进入累积多个定时器。修复：保存引用 +
  beforeunload 清理。（`app/views/doctor/emr.php`）
- **AJAX catch 后仍 throw**：全站产生 unhandled Promise rejection 红错。修复：catch 返回哨兵对象。
  （`ajax.js`）
- **`get_editable_record` 与 resolver 判定矛盾**：普通模式会命中已完结会诊病历（dept_id 一致），
  与 `consult_done` 只读熔断冲突。修复：查询排除 `consultation_id>0`。（`app/core/helpers.d/consult.php`）

### 重构

- **清理 Repository 死代码（约 -330 行）**：ConsultationRepository（仅保留 statusById）、
  OrderRepository（删 byNo/create/update/labItems*/disposalItems*/reportIdsByResultIds 等 19 个）、
  DrugRepository（删 byName/approved/settingsByType/deductStock/routeBindDisposal）、
  CashierRepository（删 dept/depts/visit/updateOrder*Status）、EmrRepository（删 recordById/
  templates 等）、QueueRepository（删 deptQueue/bindDoctor）、DeptRepository（仅保留 activeById）、
  UserRepository（删 updateCurrentDept）、BaseRepository（删 begin/commit/rollBack）。
  删除前逐方法全库验证 0 引用。
- **公共函数抽取（行为完全等价）**：
  - `gen_unique_no()`：统一「前缀+时间戳+随机，循环查重」单号生成（会诊/申请单/联动处置/证明号）
  - `user_queue_days()`：统一「会话快照→查库→2-7 钳制→默认 3」（会诊/候诊/病历可访问天数）
  - `in_placeholders()`：统一空数组 IN() 占位符生成（收敛全库 30+ 处）
  - `decorate_visit_patient()`：统一打印页患者信息装饰（print.php 5 处）
  - `Clinic.textOf()`：统一 html→textContent 提取（emr.js/historypanel.js 双实现）
  - `work_schedule()` 请求级缓存 + `work_schedule_reset()` 失效钩子
- **前端清理**：print.js 重复 contextmenu 绑定与重复 JSDoc；components-emr.css 删除 7 个无引用
  死样式类（doc-order-chip/doc-rx-line/doc-treat-proc/doc-tpl/doc-doctor/rich-editor/diag-pick*）；
  layout.css 合并重复 `:has` 规则。
- **pinyin 映射表去重**：105 处重复键收敛为 282 唯一键（脚本验证与 PHP 后写覆盖结果完全一致），
  截串改 `preg_split` 一次切分消除 O(n²)。
- **`json_fail` 事务内自动回滚**：检测 `inTransaction()` 先回滚再输出，为事务内失败调用提供安全网。
  （`app/core/helpers.d/string.php`）
- **BaseRepository 表名改反引号**：`FROM "drugs"` 在 MySQL 默认 sql_mode 下语法错误，统一反引号
  保证双驱动通用 CRUD 链路成立。（`app/repositories/BaseRepository.php`）
- **DatabaseManager 健壮性**：seedAll 事务化防并发重复种数据；resolve 旧签名判定加 SQL 关键字
  前置校验防误路由；删除 IFNULL 空操作死代码；ICD-10 注释对齐读写模式。

### 文档

- README 更新 HIS 接口调用示例（仅 X-HIS-Key 请求头）。
- package.json 版本号 6.4.3 → 6.5.0 同步（此前滞后于 bootstrap/README/CHANGELOG）。

## [6.5.0] - 2026-09-03

### 安全

- **字典管理按角色锁定**：检验科仅可改检验项目、影像科仅可改检查项目、药房仅可改药品（`item_save/item_form/item_list/cat_list` 的 type 按角色锁定，仅 admin 自由选择），`admin.php` 的 roleOpenActions 白名单按角色拆分——杜绝跨科室互改字典、绕过审核流语义。（`app/api/parts/admin_item.php`、`app/api/admin.php`）
- **停用/删除用户既有会话立即失效**：会话快照不实时校验用户状态，管理员停用/删除用户后其既有会话仍可调用全部接口/页面。新增 `Auth::assertActive()`（按 ID 实时读库，失活即强制登出），API 公共入口与页面路由两处调用。（`app/core/Auth.php`、`app/api/_init.php`、`app/core/Router.php`）
- **前端存储型 XSS 封堵**：消息标题/内容/患者名/收件人（`notify.js`、`messages.php`）、患者信息卡（姓名/性别/年龄/ID/证件号/电话，`emr_patient.js`）、患者搜索结果（onclick 引号注入，`doctor_tools.js`）、药品目录行（`order.js`）等用户可控字段未转义直接拼 innerHTML——统一补 `Clinic.escHtml()`。
- **大屏脱敏不再泄露患者全名**：`screen.php` 无论 `enable_mask` 是否开启都返回 `raw_name` 全名（供语音播报），公开常驻大屏链接持有者可读到患者完整姓名。修复：脱敏开启时 `raw_name` 返回脱敏名，脱敏关闭时才下发原始姓名。（`app/api/screen.php`）
- **诊断保存补科室隔离校验**：`record_save_diags.php` 的 `edit_record_id>0` 分支此前仅校验 doctor_id 归属，未做就诊科室授权校验，非关联科室医生可越权调整他人就诊诊断——补充 `visit_dept_authorized()`。（`app/api/parts/record/record_save_diags.php`）
- **患者档案接口角色白名单**：`patient` 接口（by_card/search/edit_form/update/history，含身份证/电话/住址等敏感数据）此前不在 `_init.php` roleMap 白名单内、任何登录角色可查改任意患者档案。修复：限定 `cashier/doctor/nurse`（需患者数据的角色）；`message`（站内互发）与 `icd10`（字典查询）保持全员开放——均为设计意图。（`app/api/_init.php`）

### 修复

- **退费/取消挂号并发竞态**：`refund_order` 存在 TOCTOU 竞态（两请求同时读到 paid → 双倍退费 + 双倍回补库存）；`cancel_visit` 已缴费退费状态迁移无守卫。修复：改为条件状态迁移（`WHERE status='paid'`）+ 影响行数判定，库存恢复仅对本事务实际置为 refunded 的明细执行。（`app/api/parts/cashier_write.php`）
- **编号生成撞号重试**：挂号事务加唯一约束冲突重试（最多 3 次，撞号时回滚重新生成 patient_no/flow_no/visit_seq）；加号标记 `markSlotUsed` 改条件更新（used=0→1）+ 行数校验防双发；报告编号改 MAX+1 + `reports.report_no` 唯一索引（schema v11）+ `insert_report` 撞号重试，杜绝并发重复报告号。新增 `is_unique_conflict()` / `next_report_no()` / `insert_report()`。（`app/api/parts/cashier_write.php`、`app/repositories/CashierRepository.php`、`app/core/helpers.d/input.php`、`app/config/schema/main.php`、`app/api/lab.php`、`app/api/imaging.php`）
- **转科补事务**：`transfer.php` 写 referrals + 更新 registrations 原无事务，第二步失败产生孤儿转诊记录——包裹原生事务。（`app/api/transfer.php`）
- **事务内失败显式回滚**：order_submit / cashier_read / cashier_write 的 register 中，事务内 `json_fail()` 直接 exit 不回滚（MySQL 下危险）——在失败路径前显式 rollBack，register 用内联 `$fail()` 闭包统一处理。（`app/api/parts/order/order_submit.php`、`app/api/parts/cashier_read.php`、`app/api/parts/cashier_write.php`）
- **空数组 SQL 防护**：`OrderRepository::reportIdsByResultIds` 空数组会生成 `IN ()` 语法错误——空数组直接返回。（`app/repositories/OrderRepository.php`）
- **读/打印接口补就诊科室隔离**：`print.php` report（doctor 角色）、`doctor_report_detail`、`order_read` prev_items/visit_orders、`consultation` snapshot/visit_consults、`record_cert` certificate_print 此前仅校验角色/存在性，未做就诊归属校验，知晓 ID 即可跨科室查看开单/报告/会诊/证明。修复：统一补 `visit_dept_authorized()`——已诊毕归档直接放行（历史就诊不受影响），仅收紧未诊毕跨科室查看；lab/imaging 为报告出具科室、admin 为打印中心，维持原行为。（`app/api/print.php`、`app/api/parts/doctor/doctor_report_detail.php`、`app/api/parts/order_read.php`、`app/api/consultation.php`、`app/api/parts/record_cert.php`）
- **护士操作科室归属校验（宽松版）**：护士站 complete/med_start/med_done/vitals/save_vitals/nursing_add 此前无科室归属校验。修复：新增 `nurse_visit_allowed()`——护士默认不绑定科室（dept_ids 空 = 全院）一律放行，仅当确实配置了 dept_ids 时才校验就诊科室归属，不破坏现有护士站流程。（`app/api/nurse.php`、`app/core/helpers.d/authz.php`）
- **时钟 interval 泄漏**：`datetime.js` `clock()` 的 `setInterval` 永不清理且 `stop()` 里 `clearInterval()` 无参数（无效调用）——保存 timer 引用，stop 正确清理。（`public/assets/js/components/datetime.js`）
- **身份证输入重复监听**：`register.php` 的 `#idCard` 重复绑定两个 input 监听导致每次击键重复执行——合并为单一监听，空卡分支补 `refreshRegState` 保持行为一致。（`app/views/cashier/register.php`）
- **函数定义覆盖**：`drugs.php` 的 `drugCatFilter`、`labitems.php` 的 `buildLabCats` 各定义两遍，靠 JS 函数提升覆盖、行为依赖加载时序——删除旧版。（`app/views/admin/drugs.php`、`app/views/admin/labitems.php`）
- **温馨提示解析脆弱**：`callmanage.php` `editRoom` 用 `tips.split('","')` 字符串解析脆弱（含引号/转义的 tips 会错位）——改为 `JSON.parse`，`erName` 补 `escHtml`。（`app/views/admin/callmanage.php`）
- **开单缴费 500 崩溃**：`pay_orders` 缴费在全局函数作用域调用 `BaseRepository::updateWhere()`，而该方法为 `protected` —— PHP 抛 `Error: Call to protected method`，且 `catch (Exception)` 捕获不到 `Error`（PHP7 中 Error 非 Exception 子类），致命错误吞掉 JSON 响应，前端报 `Unexpected end of JSON input`（500）。修复：`updateWhere` 改为 `public`（与 `q/one/val/exec/insert` 数据访问门面对称，内部仅调用 public 的 `self::exec`，无副作用）。（`app/repositories/BaseRepository.php`）
- **护士站页面列表无法加载**：`nurse/dashboard.php` 内联脚本中 4 处 HTML 拼接漏了反斜杠转义（`onclick="openVisitDetail('" + v.id + "')"` 写法错误），产生语法错误导致整个 script 块解析失败——`switchTab`/`loadTreatments` 等函数全部未定义，页面一直转圈、点击 Tab 无反应。修复：补齐转义，并用 JavaScriptCore 验证内联脚本语法恢复 OK。（`app/views/nurse/dashboard.php`）
- **药房发药按钮无反应**：`pharmacy.php` 的 queue 接口渲染发药按钮 `onclick="dispense(...)"`，但前端 `pharmacy/dashboard.php` 定义的是 `dispenseDrug()`——函数名不一致，点击调用未定义函数。修复：后端渲染改名 `dispenseDrug(...)` 与前端对齐，并全面核查全库 37 个后端 onclick 函数名与前端定义一致性（其余全部匹配）。（`app/api/pharmacy.php`）

### 变更

- **科室ID别名收敛**：删除 `doctor_dept_ids` / `nurse_dept_ids` / `tpl_dept_ids` 三个仅包装 `user_dept_ids` 的别名函数，调用方统一直调 `user_dept_ids()`。
- **`current_dept_id()` 公共函数**：全站 12 处内联 `SELECT current_dept_id FROM users` 收敛为公共函数——优先读会话快照，缺省走一次查询。（`app/core/helpers.d/authz.php`）

### 重构

- **清理全库死代码（约 -880 行）**：
  - Repository 死方法：`EmrRepository` 删除 16 个无引用方法（镜像系/vitals 系/recordsByVisitDoctorOther 等）；`UserRepository` 精简为 3 个在用方法；`CoreRepository` / `AnalyticsRepository` 业务方法全库零调用（运营分析/消息/审核均内联 SQL 直调继承的 `q/one/val/exec/insert`），精简为空白门面类，消除双份 SQL 维护；`CashierRepository` 删除 `visitByFlow` / `ordersOfVisit`。
  - 死文件：删除 `editor.js`（`Clinic.editor` 0 引用）、`utils.js`（`Clinic.utils` 0 引用，功能由 `datetime.js` 承担），同步清理 `layout.php` 3 处加载标签；删除 `record_create_progress.php`。
  - JS 死函数：`emr.js` 删除 `delOrder` / `viewOrderFlow`（约 110 行）、`itemStepIdx`、`feePopTimer`；`anaMoney2` 改名 `money2`；`scrollToPendingEditor` 移除未用参数；`validation.js` 删除 0 调用的 `required`；`upload_url()` 0 引用删除。
  - API 死 action：`auth.me` / `auth.profile_save` / `doctor.take` / `record.create_progress` / `record_cert.check_previous_diagnoses` / `template.review` / `admin_call.room_token` / `order.print`（均经前端全库验证无调用，同步清理分发器 case）。

### 文档

- CHANGELOG：版本 6.4.3 小节补充候诊列表修复、chips 选中态、影像 INSERT 占位符 3 条记录。

## [6.4.3] - 2026-09-03

### 变更

- **ICD-10 诊断编码库升级至 2.0**：替换 `data/db/icd10.db` 标准字典库（31681 → 33304 条诊断，净增约 1600 条），新增一批诊断编码并优化部分诊断拼音检索（`search_tags`），诊断搜索、ICD-10 联动与管理员诊断管理即时生效。（`data/db/icd10.db`）

### 修复

- **候诊列表人数过多时行高被压缩**：`.qp-list` 为 flex 列容器（`max-height:46vh` + `overflow-y:auto`），而 `.qp-row` 保持默认 `flex-shrink:1`，行数超过约 50 后 flex 压缩行高而非触发滚动条，导致列表样式丢失、行变得非常窄。修复：`.qp-row` 增加 `flex-shrink:0`，行高恒定、超量患者仅在面板内部滚动；同时将原误嵌在 chips 点击回调内（首次打开面板从不执行）的高度钳制逻辑抽取为 `clampListHeight()`，在面板渲染 / 列表刷新 / chips 点击时统一调用，面板最高不超过视口 46vh 且不溢出屏幕底部。（`public/assets/js/components/queuepanel.js`、`public/assets/css/layout.css`）
- **候诊列表筛选 chips 点击无选中态反馈**：为保留搜索输入框焦点，chips 点击由整面板重渲染改为仅刷新列表区（`renderListOnly`），但该路径不再重渲染 chips 的 `active` 选中态。修复：点击回调内直接 `classList.toggle('active')`，选中态即时更新、列表与计数同步刷新。（`public/assets/js/components/queuepanel.js`）
- **影像科报告录入首次必失败**：`imaging.php` 保存检查报告时 `results` 表 INSERT 声明 12 列但 VALUES 仅 11 个占位符，PDO 抛 `Invalid parameter number`，首次生成报告（result 行不存在走 INSERT 分支）即失败。修复：补为 12 个占位符，与 12 个绑定值对齐。（`app/api/imaging.php`）

## [6.4.1] - 2026-09-02

### 变更

- **切换科室自动解绑叫号大屏**：医生手动切换科室后，自动静默解绑当前绑定的叫号大屏（`doUnbindRoom` 静默版），避免换科后大屏仍挂原科室叫号；解绑后进入新科室工作台。（`public/assets/js/components/doctor_tools.js`）
- **科室名胶囊徽章样式**：标题区「医生工作站-科室」的科室名由「蓝色文字+底部虚线」改为更精致的胶囊徽章——多科室权限为主色浅底胶囊 + 下拉箭头（悬停加深、箭头旋转），单科室为灰色中性胶囊不可点击。（`public/assets/css/layout.css`、`app/includes/layout.php`、`public/assets/js/components/doctor_tools.js`）
- **胶囊箭头移除**：科室名胶囊去掉「▾」下拉箭头（箭头造成视觉不平衡），保留胶囊与悬停加深效果；版本号 6.4.1→6.4.2 强制刷新 JS 缓存。（`public/assets/css/layout.css`、`public/assets/js/components/doctor_tools.js`）

## [6.4.0] - 2026-09-02

### 移除

- **彻底删除旧医生工作站**：移除 `/doctor/dashboard` 路由、`app/views/doctor/dashboard.php` 视图、专属接口 `action=list`（`doctor_list.php`）；移除侧边栏「旧工作站」菜单、医生首页「旧工作站」快速入口、EMR 页「就诊记录不存在」回退链接中的 `/doctor/dashboard` 引用。切换科室后统一跳转新工作站 `/doctor/emr`。（`app/core/Router.php`、`app/includes/layout.php`、`app/views/doctor/home.php`、`app/views/doctor/emr.php`、`app/api/doctor.php`、`app/api/parts/doctor_read.php`、`public/assets/js/components/doctor_tools.js`）

### 新增

- **诊室大屏绑定心跳全局化（room_heartbeat.js）**：医生角色所有页面（工作站/首页/模板页等）均加载心跳组件，绑定信息存 `sessionStorage`（绑定账号+会话ID），跨页面跳转不丢失；页面加载自动从会话恢复绑定并立即发送心跳。解决「离开工作站页面 / 刷新页面后大屏自动解绑」问题——绑定仅随退出登录（`Auth::logout` 已解绑）或异常断开释放。（`app/includes/layout.php`、`public/assets/js/components/room_heartbeat.js`）
- **工具箱新增「切换科室」**：位于加号与患者查询之间，复用科室选择弹窗（仅多科室权限可切）。（`app/includes/layout.php`）

### 变更

- **叫号按钮实时显示绑定状态**：医生工作站加载后自动拉取诊室绑定信息，按钮即时显示「叫号：诊室名」，无需手动点击展开。（`public/assets/js/components/doctor_tools.js`）
- **大屏自动解绑超时放宽**：医生心跳保活检测由 90 秒放宽至 300 秒，配合全局心跳彻底避免正常使用中自动解绑。（`app/repositories/QueueRepository.php`）

## [6.3.0] - 2026-09-02

### 新增

- **医生工作站（新）顶栏工具**：标题区域「明亮模式」左侧新增「工具箱」下拉（加号 / 患者查询 / 模板管理），加号与患者查询沿用旧工作站模块；工具箱左侧新增「叫号」大屏绑定按钮（参考旧工作站叫号设置，含绑定/解绑/心跳保活）。（`app/includes/layout.php`、`public/assets/js/components/doctor_tools.js`、`public/assets/css/layout.css`）
- **医生工作站（新）标题科室切换**：标题由「医生工作站」改为「医生工作站-科室」（如 医生工作站-外科门诊），科室名可点击调出科室切换；仅多科室权限可切换，单科室点击无反应；后端 `set_dept` 已有权限强校验（无权选择即拦截）。（`app/includes/layout.php`、`public/assets/js/components/doctor_tools.js`）

### 变更

- **打印抬头优化**：病历 / 知情同意 / 开单项目 / 会诊申请 / 诊断证明等所有文档打印抬头统一以「医院名称」长度为标准宽度（`pt_header` 输出包裹块 `.print-hosp-block`），第二名称（若存在）左右两端与第一名称两边对齐；同步到病历编辑页面顶部医院名称区域（`.doc-hosp-block`）。（`app/includes/print/print_common.php`、`public/assets/css/print.css`、`public/assets/css/components-emr.css`、`public/assets/js/components/emr.js`、`public/assets/js/components/print.js`）

### 修复

- **A5 病历打印第 2 页起底部留白**：分页器原按「完整页眉」高度预留正文可用高度，但第 2 页起实际使用更矮的精简页眉，导致每页底部多出「完整页眉-精简页眉」的高度差（约 4-5 行空白）。修复：测量精简页眉高度，第 2 页起按其计算可用高度，正文铺满整页。（`public/assets/js/components/print.js`）
- **就诊历史补开诊断证明报错**：`archiveCertificateConfirm is not defined`——该函数原定义于仅 EMR 页加载的 `emr.js`，而就诊历史面板（`historypanel.js`）全局加载并在医生工作站调用。修复：将 `openHistoryCertificate / archiveCertificateConfirm / printHistoryCertificate` 迁至全局加载的 `historypanel.js`，内置自包含证书弹窗（已加载 `Clinic.emr` 时复用其 `certificateModal`）。（`public/assets/js/components/historypanel.js`、`public/assets/js/components/emr.js`）

## [6.2.7] - 2026-09-02

### 变更

- **会诊申请单打印排版优化**：正文区纵向按 2:1 分栏——上 2/3 显示病历摘要（主诉/现病史/体格检查/初步诊断），中部一条虚线分隔，下 1/3 显示会诊信息（会诊科室/会诊详情/会诊目的）；补充初步诊断展示。（`app/includes/print/print_consult.php`、`app/api/print.php`、`public/assets/css/print.css`）

## [6.2.6] - 2026-09-02

### 修复

- **续写保存后切换病历节点 dept_match 误判**：本会话新建并保存的续写病历，点击会诊记录后再切回该续写时被误判为「科室不一致只读」。根因：前端保存回调新增 records_history 条目时缺少 `dept_id` 字段，`switchToRecord` 依赖 `target.dept_id` 计算 `dept_match`，缺失导致 `calcDeptMatch` 返回 0。修复：保存时取 `DATA.record.dept_id` 或就诊当前科室回填 `dept_id`；后端 `record_read` 返回的 `recordData` 补充 `dept_id` 字段。同时强制版本号递增刷新前端 JS 缓存（`?v=6.2.6`）。（`app/api/parts/record_read.php`、`public/assets/js/components/emr.js`）

## [6.2.5] - 2026-09-02

### 修复

- **会诊上下文判定精细化**：修复非会诊科室被误判为会诊模式、会诊病历被错误设为可编辑的 bug。
  
  根因：`record_read.php`、`get_editable_record()`、前端 `calcDeptMatch()` 三处在非会诊模式下均存在 `OR (consultation_id>0 AND 会诊进行中)` 条件，导致会诊病历在非会诊科室被识别为可编辑，并抢占默认编辑位（`$mine`），使首诊病历被锁定为只读。

  修复：非会诊模式下，仅 `dept_id == 就诊当前科室` 的记录可编辑；会诊病历（`consultation_id>0`）在非会诊科室永久只读（`dept_match=0`），不抢占编辑位、不触发会诊模式锁，原科室医生可正常编辑/续写首诊病历和开单。（`app/api/parts/record_read.php`、`app/core/helpers.d/consult.php`、`public/assets/js/components/emr.js`）

## [6.2.4] - 2026-09-02

### 变更

- **管理员诊断管理搜索浮层复用分段加载**：管理端 `diagnosis.php` 搜索浮层与医生工作站诊断搜索采用同一套规则——无限滚动分段加载（每页 50 条），滚动到底部自动加载下一页，直至全部匹配诊断加载完成，模糊搜索不再漏掉任何诊断。（`app/views/admin/diagnosis.php`）

## [6.2.3] - 2026-09-02

### 变更

- **ICD-10 诊断搜索改为分段加载（无总条数上限）**：医生工作站添加诊断搜索不再固定截断（原 limit 20 → 50），改为后端分页（offset/limit，每页 50）+ 前端结果区无限滚动——滚动到底部自动加载下一页，直至全部匹配结果加载完成，模糊搜索不再漏掉任何诊断。（`app/repositories/Icd10Repository.php`、`app/api/icd10.php`、`public/assets/js/components/emr.js`）

## [6.2.2] - 2026-09-02

### 修复

- **ICD-10 诊断搜索相关度优化**：医生工作站病历添加诊断搜索"1型糖尿病"等关键词时找不到对应诊断（如 E10.900）。根因是搜索仅按 `diagnosis_code` 字母序排序且 LIMIT 20，最简洁的核心诊断排到截断位之后。
  改进：① 相关度排序——名称完全等于关键词 > 名称前缀匹配 > 编码前缀匹配 > 拼音匹配 > 名称包含，同级按名称长度升序（更简洁的核心诊断优先）；② 搜索返回上限 20 → 50；③ 管理端诊断检索（paginate）关键字搜索同样应用相关度排序，无关键字层级浏览保持编码升序稳定分页。（`app/repositories/Icd10Repository.php`、`app/api/icd10.php`）

## [6.2.1] - 2026-09-02

### 修复

- **搜索交互优化**：统一打印中心与缴费退费搜索栏支持回车键直接搜索（无需点击查询按钮）。（`app/views/admin/printcenter.php`、`app/views/cashier/paymanage.php`）
- **缴费明细展开修复**：缴费与退费页点击就诊记录后未展开明细——混淆 ID 在 `onclick` 内缺少引号导致 JS 错误，补全引号。（`app/views/cashier/paymanage.php`）
- **缴费凭条权限修复**：收费员缴费后打印凭条提示「无权打印该单据」——`print_guard` 对 cashier 角色误判科室归属，豁免收费员（全院收费）。（`app/api/print.php`）
- **诊断证明列表即时刷新**：开具诊断证明成功后立即刷新左侧病历导航栏，无需手动刷新页面。（`public/assets/js/components/emr.js`）
- **诊断证明删除功能（新增）**：certificates 表新增 `dept_id` 字段（迁移 v10），开具时记录科室；后端 `certificate_delete` 校验（仅开具医生本人 + 开具科室与医生当前科室一致 + 非会诊期）；前端仅在 `can_delete` 标志为真时显示删除按钮，前后端双重拦截。（`app/config/schema/main.php`、`app/api/parts/record_cert.php`、`app/api/record.php`、`app/api/parts/record_read.php`）
- **会诊列表日期时间修正**：候诊列表「会诊」Tab 下左侧日期/时间应为发起会诊的时间，而非患者挂号时间。（`app/api/parts/doctor/doctor_queue_list.php`）
- **会诊病历门诊处置误显示修复**：B 科室医生新建会诊病历骨架时，门诊处置误显示 A 医生发起的「请X科会诊」——`renderDocOrders` 会诊过滤补充「当前记录未保存时排除已绑定其他病历的会诊」分支，与 `orderTextsFor` 规则对齐。（`public/assets/js/components/emr_orders.js`）
- **病历生命体征隔离修复**：打印病历时间体征跨病历串用——`get_record_vitals` 回退路径增加 `AND record_id=0` 限定，只回退未归属任何病历的体征（护士站录入），已归属其他病历/会诊的体征永不跨病历引用。（`app/core/helpers.d/authz.php`）

## [6.2.0] - 2026-09-02

### 修复

- **修复 6 处已确认逻辑缺陷**：`admin/analytics.php` 医生统计页重复 `docDeptSel` 元素 ID（筛选失效）；`record_delete.php` 重复查询；`pharmacy/dashboard.php` 发药后 `loadQueue()` 未传参数；`admin_drug.php` 删除药品设置项未校验引用计数；`emr.js` 公开 API 重复 `isMyOrder` 属性；`base.css` 重复变量与滚动条声明。

### 安全

- **跨科室授权校验补齐**：`consent.php`（list/get）、`consultation.php`（detail）、`transfer.php`（do）补充 `visit_dept_authorized` 等科室授权校验，杜绝知晓 ID 即跨科室读取/操作。
- **报告打印角色白名单补充 admin**：管理员打印中心可打印检验/检查报告。
- **未读消息可见性修复**：`admin_settings.php` 消息计数改用与 `message.php` 一致的规则 `(to_user_id=? OR (to_user_id=0 AND to_role=?))`。
- **会话 Cookie 增加 secure 标志**（HTTPS 下启用）；`DatabaseManager::columnExists` 增加表名/列名白名单校验。

### 变更

- **BaseRepository 去重**：ICD-10 查询与 `prepare*` 冗余方法委托 `DatabaseManager`，移除重复 PDO 样板；跨仓库 8 组重复 SQL 改为互相委托；`OrderRepository` 字典查询统一走 `findAllByField`。
- **打印模块公共助手抽取**：新增 `pt_info_cell` / `pt_barcode` / `pt_doc_foot`，消除 5 处 `$cell` 闭包、4 处条形码块、6 处页脚重复；删除死代码 `pt_patient_info`、`print_record.php` 死三元、`print_report.php` 未用参数。
- **检验/影像共享逻辑合并**：`imaging.php` 与 `lab.php` 的 home_stats/queue/register/withdraw 抽取至 `dept_common.php`，各减少约 150 行重复。
- **drug_save 统一动态列构建**：`admin_drug.php` 删除手写 26 列 INSERT + `array_slice` 偏移拼接，改用 `insertRow` 动态列。
- **JS 工具收敛**：新增 `Clinic.utils`（pad/age）；`Clinic.get` 委托 `Clinic.ajax` 复用统一错误处理。
- **helpers.php 按域拆分**：811 行拆分为 `helpers.d/` 下 13 个职责单一文件，入口 `helpers.php` 保留为统一加载器。
- **大文件按动作拆分**：`record_write.php`（592 行）拆为 5 个动作文件；`order_write.php`（513 行）拆为 submit/delete；`doctor_read.php`（377 行）拆为 8 个动作文件。各文件保留分发器，逻辑零变动（已用 SQL/消息等价性校验）。
- **移除遗留代码**：`auth.php` 无前端调用的 `profile` 兼容接口；`EmrRepository::certificatesByVisitOtherDoctors` 更名 `recordsByVisitOtherDoctors`（实际查询 patient_records 表）。
- **forms.php 查询优化**：药品设置字典一次查出按类型分组，消除每页 5 次重复查询。

## [6.1.1] - 2026-09-01

### 变更

- **ICD-10 诊断字典升级为完整标准编码库（医保版）**：`data/db/icd10.db` 纳入版本管理（不再忽略），
  31681 条完整诊断数据，每行含四级分类链：章(chapter)→节(section)→类目(category)→亚目(subcategory)→最终诊断。
  `Icd10Repository` / `api/icd10.php` 全面重写适配新库字段（diagnosis_code / diagnosis_name / search_tags），
  schema 同步更新。（`app/repositories/Icd10Repository.php`、`app/api/icd10.php`、`app/config/schema/icd10.php`、`.gitignore`）

- **诊断管理页面树形浏览**：左侧三级树（章→节→类目，懒加载折叠），点击类目右侧显示亚目列表 + 诊断明细，
  支持按诊断码/名称/拼音实时检索并跳转类目；标准库只读，移除新增/编辑/删除与导入模块。
  （`app/views/admin/diagnosis.php`、`app/core/DataExportImport.php`、`app/api/parts/admin_import.php`）
- **诊断搜索交互优化**：搜索浮层展示结果（不遮挡左侧树与右侧详情），点击结果自动展开左侧树到对应类目并蓝色高亮+滚动定位，
  右侧跳转详情并蓝色高亮匹配诊断，子诊断自动展开。右侧卡片固定高度铺满背景，默认居中引导语。
  （`app/views/admin/diagnosis.php`）

### 修复

- **ICD-10 编码抓取拆分异常**：修复 34 条因亚目名过长被拆行的异常编码
  （31 条「前缀+真编码」、2 条「编码跑到诊断名开头」、1 条「前缀含 subcategory_code 自身」），
  同步补齐被截断的亚目名与 search_tags 拼音。新增可复用修复脚本
  `tools/fix_icd10_split.php`，二次校验 0 条残留。

## [6.1.0] - 2026-09-01

### 修复

- **退费崩溃**：`CashierRepository::createInventoryTrans` 参数签名不匹配，导致处方退费时 500 崩溃（`app/repositories/CashierRepository.php`、`app/api/parts/cashier_write.php`）
- **越权打印**：`print.php` 全部打印接口（receipt/payment/order/record/certificate/report）新增角色白名单+就诊归属校验，防止任何登录用户打印他人病历（`app/api/print.php`）
- **事务丢失**：`order_write.php:delete` 库存恢复+级联删除无事务包裹；`record_write.php:save` 的 C/C2/D 段在 commit 后执行；`record_delete.php` 会诊回退/就诊状态回退在事务外 —— 均修复为原子事务（`app/api/parts/order_write.php`、`app/api/parts/record_write.php`、`app/api/parts/record_delete.php`）
- **并发竞态**：会诊 accept/finish、pay_visit、pay_orders 改为原子条件 UPDATE + 影响行数判定（`app/api/consultation.php`、`app/api/parts/cashier_write.php`、`app/api/parts/cashier_read.php`）
- **物理删除孤岛**：项目/药品/处置/科室删除增加引用检查（关联开单/结果/库存/皮试/挂号/病历/会诊/诊室/用户时禁止删除）（`app/api/parts/admin_item.php`、`admin_drug.php`、`admin_disp.php`、`admin_dept.php`）
- **版本号锚定**：`APP_VERSION` 从 6.0.0 同步至 6.1.0（`app/config/bootstrap.php`）

### 新增

- **核心表索引**：为 patient_records/orders/order_items/results/vitals/payments/registrations 创建 13 个高频查询索引（幂等迁移，兼容新旧库升级路径）（`app/config/schema/main.php`）
- **数据导入校验**：admin_import 预检新增 CSV 扩展名 + 二进制内容拦截（`app/api/parts/admin_import.php`）
- **json_decode 深度限制**：病历保存 json_decode 限深 512（`app/api/parts/record_write.php`）

### 变更

- **DB/BaseRepository 收敛**：`BaseRepository::q/one/val/exec/insert` 委托 `DatabaseManager` 作为唯一底层 PDO 门面，消除两套 prepare/execute/fetch 实现（`app/repositories/BaseRepository.php`）
- **dept_ids 解析统一**：`doctor_dept_ids`/`nurse_dept_ids`/`tpl_dept_ids`/`visit_dept_authorized` 4 处内联解析统一为 `user_dept_ids()` 公共函数（`app/core/helpers.php`、`app/api/doctor.php`、`app/api/nurse.php`、`app/api/template.php`、`app/api/parts/admin_user.php`）
- **生命体征查询统一**：`get_record_vitals()` 覆盖 record_read.php 与 print.php 两处重复（`app/core/helpers.php`）
- **诊断证明快照统一**：`cert_fallback_snapshot()` 覆盖 print.php 与 record_cert.php 两处重复（`app/core/helpers.php`）
- **患者搜索统一**：`search_visit_records()` 覆盖 cashier_read.php 与 nurse.php 两处重复（`app/core/helpers.php`）
- **php-lint 递归修复**：`glob('**/*.php')` 不递归问题改为 `RecursiveIteratorIterator`，检测文件数从 62 提升至 144（`tools/php-lint.php`）
- **seed 会话值统一**：seed_demo_data/seed_test_data 的 `registrations.session` 存储值由中文 `'上午'/'下午'` 改为业务约定 `'am'/'pm'`（`tools/seed_demo_data.php`、`tools/seed_test_data.php`）

## [6.0.4] - 2026-08-31

### 修复

- **删除会诊病历后退回医生工作站并自动弹出候诊列表**：删除会诊病历（会诊状态回退待会诊）后，
  不再停留在原患者病历页，而是关闭病历页跳转 `/doctor/emr` 医生工作站（已选科室占位页，
  显示「🏥 已选择科室 候诊列表已打开，点击患者即可进入病历书写」），并按会话记忆的科室
  自动弹出左上角候诊列表，便于继续接诊或重新处理会诊。
  - 涉及：`public/assets/js/components/emr.js`（handleRecordDeleted 会诊分支改为整页跳转）。

## [6.0.3] - 2026-08-31

### 修复

- **会诊进度状态与实际不符（待会诊/删除后仍显示医生姓名）**：
  - 会诊详情模态框「正在会诊」步骤的医生姓名/时间仅在会诊已进入处理中（doing/done）时显示，
    待会诊（pending）时不再提前显示（原逻辑无状态判定直接显示 accepted_by）。
  - 删除会诊病历后回退会诊状态时，`revertConsultation` 同时清空 accepted_by/accepted_at/
    finished_by/finished_at；且回退条件由「仅 doing」放宽为「非 done」，确保任何未完毕状态的
    会诊病历删除后都正确回退待会诊，不再残留医生信息。
  - 涉及：`app/repositories/EmrRepository.php`、`app/api/parts/record_delete.php`、
    `public/assets/js/components/emr.js`。
- **接受会诊后立即进入「会诊中」，消除删除按钮矛盾**：
  - 会诊科室点击「确认会诊/开始会诊」时，状态由 pending 直接置 doing（原为仅记录接收医生、
    待保存会诊病历后才置 doing）。因此接受后侧栏会诊列表不再显示删除按钮（删除按钮仅
    pending 且发起人本人可见），后端 `delete` 动作的「非 pending 不可删除」硬校验随即生效，
    与会诊科室进入会诊处理的状态语义一致，不再自相矛盾。
  - 涉及：`app/api/consultation.php`、`app/api/parts/record_write.php`（注释同步）。

## [6.0.2] - 2026-08-31

### 修复

- **会诊详情模态框「会诊完毕」不显示医生姓名**：前端 `会诊进度` 三步中「会诊完毕」步骤
  `operator` 硬编码为空字符串，后端 `finish` 动作也未记录完成医生，导致该步骤仅显示时间、
  无医生姓名。
  - 后端新增 `consultations.finished_by` 字段（schema v3 + 迁移），`finish` 动作记录完成医生；
    `consultation_row` 返回 `finished_by`。
  - 前端「会诊完毕」operator 改为 `finished_by || accepted_by || record.doctor_name`（兼容旧数据）；
    「正在会诊」operator 增加 `record.doctor_name` 兜底。
  - 涉及：`app/api/consultation.php`、`app/config/schema/main.php`、
    `app/config/schema/legacy/013_consultation.php`、`public/assets/js/components/emr.js`。
- **排查结论：检验/检查/处方/处置开单页面进度医生姓名正常**——`order_flow_steps` 中「开单」
  步骤 operator 恒为 `doctor_name`，后续步骤（缴费/登记/报告/发药/执行）operator 来自实际数据
  （收费员/执行人），不存在硬编码空值，无需修改。

## [6.0.1] - 2026-08-31

### 修复

- **删除会诊病历节点 500 错误**：`record_delete.php` 调用 `EmrRepository::deleteMirrorByRecordId()` 但该方法不存在（命名错误，应为 `deleteMirrorByPatientRecord`），导致 PHP 致命错误引发 500 响应，前端解析空 JSON 报 Unexpected end of JSON input。修正方法名后删除会诊病历正常。（`app/api/parts/record_delete.php:69`）

## [6.0.0] - 2026-08-31

### 变更

- **数据访问层架构升级（引入 Repository 数据仓库模式，实现业务与 SQL 彻底解耦）**：
  - 新增 `app/repositories/` 数据访问层，按业务领域划分 12 个专属 Repository 类：
    `BaseRepository`、`Icd10Repository`、`PatientRepository`、`QueueRepository`、
    `CashierRepository`、`EmrRepository`、`DrugRepository`、`OrderRepository`、
    `UserRepository`、`DeptRepository`、`AnalyticsRepository`、`ConsultationRepository`、
    `CoreRepository`。所有 SQL 编写、预编译参数绑定与原生 PDO 操作统一收敛至该层。
  - **净化业务 API 控制层**：全部 42 个 `app/api/*.php` / `app/api/parts/*.php` 文件
    彻底剥离原生 SQL（全盘 `DB::` 门面调用与直接 PDO `prepare()` 残留为 0），仅保留
    身份认证/权限检查、入参校验、调用对应 Repository 方法、事务控制与 JSON 响应组装。
  - **保持双驱动兼容与安全性**：所有 Repository 内部 SQL 严格经 `DatabaseManager::getMain()`
    （或 `getIcd10()`）预编译参数绑定执行，保持 SQLite / MySQL 双驱动方言兼容。
  - **事务分层**：复合写操作（挂号收费、接诊开方、药房发药、退费冲正、病历保存等）
    在 API 层统一 `beginTransaction()/commit()/rollBack()`，跨 Repository 协同一致。
- 系统版本号由 `5.0.2` 升级为 `6.0.0`（大版本：数据访问层架构级重构）。

### 修复

- **会诊模式新建病历保存死锁**：会诊处理中且无会诊病历（record=null）时，
  EmrContextResolver 返回可写 consultation 上下文（can_write/can_diag=true）而非 consult_lock；
  record_write 守卫对会诊病历新建（$isConsultCreate）豁免容器存在性检查，
  前端 fallback 派生 consult_editing 可写，消除「需诊断但诊断需保存」互锁。
- **会诊完毕只读态病历节点滚动失效**：scrollToRecord 增加 DATA.__readonly_view 判定，
  只读模式点击病历节点滚动到对应 recSeg 锚点（与会诊完毕查看完整病历一致）。
- **转科切换病历后删除按钮残留**：emr_rules.js 新增 contextConsistent() 一致性校验，
  switchToRecord 切换病历节点后，缓存 active_context 与当前记录不一致时回退本地派生
  （基于 dept_match），正确判定只读并隐藏 + 与删除按钮。

### 新增

- `app/repositories/BaseRepository.php`：通用 PDO 查询助手（q/one/val/exec/insert、
  事务辅助、ICD-10 查询、动态 SQL 门面 prepareExec/prepareQ/prepareInsert/dbVal 等）
  以及通用 CRUD 助手（insertRow/updateRow/updateWhere/findById，含表名白名单校验）
- `app/repositories/` 各业务域 Repository（Icd10/Patient/Queue/Cashier/Emr/Drug/Order/
  User/Dept/Analytics/Consultation/Core）
- `app/config/bootstrap.php`：repositories 目录自动加载（BaseRepository 优先）

### 变更

- **消除 Repository 层重复的 CRUD 读写代码（DRY 优化）**：
  - 动态插入收敛：Dept/Drug/Order/User `create`、Emr `insertRecord/insertMirror/
    insertVitals/insertCertificate/insertReferral`、Core `insertMessage/insertSentMessage/
    insertAudit`、Order `insertItem` 等 12 处统一复用 `BaseRepository::insertRow`；
  - 动态更新收敛：各 `update`/`updateXxx`（含 Cashier 状态流转、Consultation 会诊状态、
    Patient 资料更新等 13 处）统一复用 `updateRow`/`updateWhere`；
  - 按 ID 查询收敛：13 处 `*ById` 方法统一复用 `findById`；
  - 跨仓库重复方法收敛：`CashierRepository::createInventoryTrans` 委托
    `DrugRepository` 统一实现。
  - 效果：全盘动态插入/更新拼接残留归零，后续新增同构操作直接调用通用助手，
    降低维护成本。

### 文档

- README：版本徽章与目录结构同步更新（新增 repositories/ 数据访问层说明）。

## [5.0.2] - 2026-08-31

### 修复

- **转科前旧文书（非本科室）误判为可编辑**：`switchToRecord` 切换病历节点时重新构造 `DATA.record` 遗漏 `dept_match` 字段，导致转科前（如急诊科写的）首诊被渲染为可编辑编辑器、且切回续写后右侧开单「＋」全部消失（需保存/刷新才恢复）。新增 `calcDeptMatch()` 镜像后端权威规则，切换时即时计算并写入 `dept_match`，驱动编辑器只读/可编辑与右侧「＋」实时联动：
  - 转科前旧文书（书写科室 ≠ 当前就诊科室）→ 只读展示（docBody 直接渲染该文书只读段 + 「仅可查看」提示），隐藏顶栏写操作按钮；
  - 切回当前科室续写 → 恢复可编辑，右侧检验/检查/处置/处方/诊断等「＋」立即出现；
  - 会诊进行中例外、跨科室绝对只读（readonly_view）与后端规则保持一致。

## [5.0.1] - 2026-08-31

### 修复

- **会诊 / 转科病历只读判定首次进入失效（刷新后正常）**：
  医生在病历页通过科室选择切换科室时，此前仅存 sessionStorage、未调用 `set_dept` API，后端 `users.current_dept_id` 数据库值未同步（保持 0 或旧科室），导致 `readonly_view` / `crossDeptView` / `dept_match` 计算错误：
  - 跨科室查看会诊完毕患者「查看完整病历」时首诊病历被误判为可编辑；
  - 转科患者转科前（非本科室）首诊病历被误判为可编辑、续写打印提示不可编辑。
  修复：`wbPickDept` 增加调用 `/api/doctor?action=set_dept` 同步后端；EMR 页 `init` 时读取 sessionStorage 记忆科室，若与页面渲染科室不同，先 `set_dept` 同步完成后再加载病历数据（`syncDeptThenLoad`），首次进入即用正确科室判定只读状态，无需刷新。

- **只读段不显示已开具的检验/检查/处置/处方项目（点击病历节点后才显示）**：
  跨科室绝对只读（`readonly_view`）模式下，全部文书渲染进 `docBody` 只读段，但 `refreshReadOnlyBodies` 此前仅刷新 `roBefore`/`roAfter` 不刷新 `docBody`；ORDERS 异步加载完成后只读段始终不显示开单项目，直到点击病历节点触发重渲染才出现。
  修复：`refreshReadOnlyBodies` 增加 `__readonly_view` 分支，ORDERS 就绪后重新渲染 `docBody` 显示全部只读文书（含开单），与诊毕只读同逻辑。

## [5.0.0] - 2026-08-31

### 变更

- **数据库架构原子化重构（分散式多库 → 统一单主库 clinic_main）**：
  - 原 core / user / dept / patient / order / drug / medical / nurse / lab / disp / emr_templates / clinic_rooms / consultation 等 13 个分散 SQLite 库全部合并为统一业务主库 `clinic_main`，统一经 `DatabaseManager::getMain()` 访问，原生支持跨表 JOIN 与 ACID 事务。
  - **ICD-10 独立字典库**：独立文件 `icd10.sqlite`，经 `DatabaseManager::getIcd10()` 访问，与业务主库隔离；业务表仅冗余 `icd10_code` / `diagnosis_name`，不参与事务；管理端支持新增/编辑/删除诊断（小规模维护），大范围导入可走管理端数据导入功能。
  - **双驱动一键切换**：`DB_DRIVER = sqlite | mysql`，SQLite 为 `data/db/clinic_main.db`，MySQL 为 `his_main` 库；建表 / 种子 / 迁移 SQL 由方言层自动转换（`AUTOINCREMENT→AUTO_INCREMENT`、`INSERT OR IGNORE→INSERT IGNORE`、`datetime('now','localtime')→NOW()`）。
  - **字段全盘规范化（snake_case & 语义化）**：
    - 时间字段 `_time → _at`：`register_time→registered_at`、`payment_time→paid_at`、`finish_time→finished_at`
    - 生命体征 `vital_*`：`bp_systolic→vital_sbp`、`bp_diastolic→vital_dbp`、`heart_rate→vital_heart_rate`、`pulse→vital_pulse`、`spo2→vital_spo2`、`respiration→vital_respiration`
    - EMR 核心字段：`initial_diagnosis→preliminary_diagnosis`、`diagnosis_code→icd10_code`、`main_symptom→chief_complaint`、`allergies→allergy_history`、`primary_icd10→icd10_code`、`primary_diagnosis→diagnosis_name`、`advice→doctor_advice`（仅数据库列，emr_data JSON 键保留）
    - 布尔字段：`need_nurse→is_nurse`、`need_skin_test→is_skin_test`
    - 语义化：`cat_name→category_name`、`frequency_name→frequency`、`route_name→route`、`unit_name→unit`
  - **数据平滑迁移工具**：`tools/migrate_split_to_unified.php` 按字段映射字典读取旧分散库、清洗补丁字段并写入统一主库，保留原主键 ID，事务性迁移 + 迁移后一致性校验报告。
  - **核心工作流原生 ACID 事务**：挂号、批量缴费、开单（含联动处置与库存扣减）、退费、发药等复合操作统一 `beginTransaction()/commit()/rollBack()`。
  - **N+1 查询优化**：收费处就诊详情等由逐订单循环查询改为批量 `IN` 查询分组。
- **数据库连接 API 变更**：`DB::q/one/val/exec/insert` 门面统一走主库（兼容旧分散库 key 签名自动路由）；`icd10.php` 改用 `getIcd10()`。
- 系统版本号由 `4.16.10` 升级为 `5.0.0`（大版本：架构级重构）。

### 新增

- `app/config/schema/main.php`：统一业务主库 Schema（36 张表，双驱动兼容）
- `app/config/schema/icd10.php`：ICD-10 独立字典库 Schema
- `app/config/schema/legacy/`：归档旧分散式 Schema（供迁移工具引用）
- `tools/migrate_split_to_unified.php`：分散库→统一主库自动迁移与字段清洗工具
- `docs/db-refactor-mapping.md`：《字段与表结构重构映射总表》

### 文档

- README：更新数据库徽章（SQLite/MySQL 双驱动）、目录结构与运行说明同步。

## [4.16.10] - 2026-08-30

### 修复

- **「查看完整病历」按钮仅候诊列表会诊tab显示**：`openConsultDetail` 中该按钮改为
  **仅当从候诊列表【会诊】tab 进入（withAccept=true）且会诊已完毕（done）** 时显示。
  病历页右侧会诊列表/病历正文链接（withAccept=false/undefined）打开详情时不显示，
  避免已在完整病历内重复出现入口。通过 withAccept 参数精确区分两个点击来源
  （组件级区分，不再时有时无）。

## [4.16.9] - 2026-08-30

### 修复

- **知情同意书跨科室只读锁定**：此前 `consent.php` 的 save/delete 仅检查诊毕归档，
  未校验跨科室只读——会诊完毕后外科医生查看急诊科就诊（跨科室绝对只读）时仍可
  删除/编辑知情同意书。现后端 save/delete 均增加 `get_editable_record` 可编辑校验
  （医生当前科室 != 就诊当前科室且非会诊处理中 → 拒绝），前端 `emr_consent.js`
  的删除/编辑/保存入口在 `readonly_view` 模式下全部隐藏或拦截，仅保留查看/打印。

- **「查看完整病历」按钮恒定显示**：`openConsultDetail` 会诊完毕（done）时始终显示
  「📋 查看完整病历」按钮，不再因当前是否已在病历页而时有时无。

## [4.16.8] - 2026-08-30

### 修复

- **跨科室病历绝对只读（根治跨科室可编辑漏洞）**：此前 `dept_match` / `get_editable_record`
  仅按「文书书写科室 == 就诊当前科室」判定可编辑，未校验「医生当前所在科室 == 就诊
  当前科室」——外科门诊医生查看急诊科就诊时，急诊科首诊病历（dept_id 匹配就诊科室）
  会被误判可编辑。现统一规则：**医生当前科室 == 就诊当前科室 才可能有可编辑文书**，
  跨科室（非会诊处理中）一律只读。`get_editable_record`、`record_read.php` 的
  `dept_match` / `$mine` 选择、`emr_rules.php` 的 `emr_record_state` 均已同步该规则。
  跨科室书写须走转科/续写/发会诊流程。

- **移除 URL `&view=1` 只读参数方案**：不再依赖地址栏参数做只读筛选。会诊完毕后
  会诊科室「查看完整病历」进入病历页，由后端根据「医生当前科室 vs 就诊当前科室」
  状态驱动只读（`readonly_view` 标志），前端据此全锁死。

- **修复只读查看模式无法打印病历**：`printRecord` 此前走 `requireSaved` → 校验可编辑
  病历，跨科室只读模式下被拦截。现只读查看（`readonly_view`）或诊毕时打印直接放行
  （打印为只读操作，后端渲染全部已保存文书）。

- **候诊列表会诊 Tab 记忆**：`queue_pref` 增加 `consult` 字段，刷新后保持会诊筛选勾选
  状态（与已诊/当日一致）。

- **病历页内会诊详情不再显示「查看完整病历」**：`openConsultDetail` 判断当前页面就诊
  与会诊就诊相同（已在完整病历内）时隐藏该按钮，避免反复套娃。

## [4.16.7] - 2026-08-30

### 新增

- **会诊完毕「查看完整病历」只读模式**：会诊完毕后，从会诊科室的会诊列表点击患者
  显示会诊详情，新增「📋 查看完整病历」按钮（`openConsultDetail` 会诊完毕时展示）。
  点击后进入病历页，URL 携带 `view=1` 参数，后端权威返回 `readonly_view` 标志，
  前端强制全只读展示（类似诊毕但区别：不可补开诊断证明）：
  · 后端 `record_read.php` 识别 `view=1`，强制 `dept_match=0`、跳过可编辑文书选择、
    返回 `readonly_view` 标志；
  · 前端 `renderEmrCard` 中 `readOnly` 在 `view_only` 模式为 true（走诊毕式只读骨架）；
  · `setReadonlyUI` 在 `view_only` 模式下不保留诊断证明补开入口（会诊完毕不可开具）；
  · 规则引擎 `emr_rules.js` 新增 `view_only` 状态，`hasEditableRecord` /
    `currentRecordEditable` 全部委托返回 false，开单/诊断/会诊/续写/删除全拦截；
  · 删除按钮、`emrNavAdd` 各入口均增加 `__view_only` 守卫，杜绝误操作。

## [4.16.6] - 2026-08-30

### 修复

- **修复发送会诊500错误（跨库子查询bug）**：`consultation.php` 的 `$ownConsult` 查询此前
  在 `medical` 库查询中内嵌 EXISTS 子查询引用 `consultations` 表，但该表位于独立的
  `consultation` 数据库，导致 `no such table: consultations` 致命错误（500）。
  现改为两段独立查询：先在 `consultation` 库取进行中会诊 id 列表，再在 `medical` 库
  以参数占位符 `IN (...)` 关联，彻底消除跨库子查询。

## [4.16.5] - 2026-08-30

### 修复

- **修复会诊完毕后再次发送会诊被误拦**：`consultation.php` create 的「您正在会诊处理中」
  拦截此前只判断 `patient_records.consultation_id > 0`，不区分会诊是否已完毕——即便会诊
  已 done，关联的会诊病历（`consultation_id` 仍存在）也会触发误拦。现改为仅统计
  **进行中/待处理（pending/doing）** 的会诊病历，会诊完毕（done）后即可再次向同科室
  或其他科室发送会诊（同科室重复拦截仍按 `target_dept_id + pending/doing` 精确判定）。

## [4.16.4] - 2026-08-30

### 修复

- **修复会诊完毕后新建续写保存误拦「该会诊已完毕」**：`record_write.php` save 的非
  `edit_record_id` 分支，此前对「本人最新文书」校验会诊完毕状态——当本人最新文书是
  已完毕会诊病历（C）时，即使本次是**新建续写**（`progress_new=1`，保存目标是全新
  文书，与旧会诊无关）也被误拦。现仅当「非新建续写」（保存目标即本人最新现有文书）
  时才校验会诊完毕，`progress_new` 一律放行。

### 新增

- **病历状态机与操作权限统一规则引擎**：新增 `app/core/emr_rules.php`（后端权威）
  与 `public/assets/js/components/emr_rules.js`（前端镜像，同规则）——
  集中判定当前文书状态（`visit_finished` 诊毕 / `consult_editing` 会诊处理中可写 /
  `consult_lock` 会诊处理中其他只读 / `consult_done` 会诊完毕永久只读 /
  `editable` 当前科室可写 / `dept_mismatch` 转科前只读 / `others` 他人只读 /
  `new` 新建中）及操作权限（能否书写/保存/开单/发会诊/加诊断），
  消除各处散落判断导致的状态区分不一致（如会诊完毕误拦续写）。
  前端 `hasEditableRecord` / `currentRecordEditable` 已委托规则引擎。

## [4.16.3] - 2026-08-30

### 修复

- **修复续写病历自动带入之前病历开单**：新建续写/会诊病历编辑中（当前记录未保存、
  `record_id=0`）时，`orderTextsFor` 与 `renderDocOrders` 此前回退按医生归属展示全部
  开单，把绑定在之前首诊/续写/会诊病历上的检查、处置、处方、会诊项目一并带入新续写。
  现改为：当前记录未保存（`recId=0`）时仅展示旧数据（`record_id=0` 历史开单），
  绝不展示绑定在其他病历上的新开单；当前记录已保存（`recId>0`）时仅展示绑定到本
  记录的（或旧数据按医生）。杜绝续写中串显示上个续写/会诊的开单。

- **修复续写病历添加诊断自锁**：新建续写编辑中（`record_id=0` 且 `__pending_progress`
  或 `__progress_new`）`currentRecordEditable()` 此前判定不可编辑，导致「添加诊断提示
  当前无可编辑的病历、保存又要求先添加诊断」的互相死锁。现增加续写/会诊病历编辑中
  的兜底判定：编辑器已渲染（未保存）即允许添加诊断（诊断随首次保存一并提交）。
  此修复与既有会诊病历自锁修复同源，覆盖首诊/续写/会诊三类新建编辑场景。

## [4.16.2] - 2026-08-30

### 修复

- **修复开单 500 错误（跨库子查询 bug）**：`get_editable_record()` 普通模式分支此前在
  `medical` 数据库查询中内嵌 `SELECT id FROM consultations ...` 子查询，但
  `consultations` 表位于独立的 `consultation` 数据库——开单提交时触发
  `no such table: consultations` 致命错误（500）。现改为先在 `consultation` 库查询
  进行中会诊 id 列表，再在 `medical` 库以参数占位符 `IN (...)` 关联，彻底消除跨库
  子查询。修复后同一医生 A 首诊 / B 续写 / C 会诊完毕 三份文书并存时，默认定位
  可编辑续写病历（B），开单正常。

## [4.16.1] - 2026-08-30

### 修复

- **修复会诊完毕后回原科室点击会诊记录节点仍可编辑 + 保存成功**：
  场景——会诊完毕后回到原始就诊科室，右侧显示【外科门诊】会诊病历节点（默认只读），
  但手动点击该节点后：① 会诊记录直接变为可编辑；② 横条徽章由「会诊记录」变为
  「续写病历」；③ 修改后可正常保存（前后端均未拦截）。
  根因与修复：
  1. 前端 `scrollToRecord` 分支3（本人旧文书→切换为可编辑）未校验目标记录是否已完毕
     会诊——现改为：目标为 done 会诊记录时仅滚动到只读段并提示，不切换为可编辑；
  2. `switchToRecord` 重建 `DATA.record` 时丢失 `consultation_id`——导致徽章错变
     「续写病历」、保存时 `consultation_id` 传 0 绕过后端 done 拦截；现保留该字段，
     并新增防御式兜底：done 会诊记录拒绝切换；
  3. 后端 `record_write.php` save 的会诊完毕锁定改为以「记录自身 consultation_id」
     权威判定（`edit_record_id` 分支与默认分支均校验），不再依赖前端传参
     `consultation_id`（防切换旧文书时传 0 绕过）。

## [4.16.0] - 2026-08-30

### 新增

- **会诊编号**：为每个会诊生成独立单号（`HZ + 时间戳 + 随机`，与申请单号同规则），
  存储于 `consultations.consult_no`（schema v3 迁移），会诊详情页顶部展示单号；
  历史数据首次读取时惰性补齐编号。

- **会诊申请单独立打印模板**：新增 `print_consult.php`（`/api/print?action=consultation`），
  样式参考检验检查申请单：标题「会诊申请单」、右上角条形码 + 申请单号；
  患者信息区「开单科室」改为「申请科室」、去掉「临床诊断」；
  正文为 主诉 / 现病史 / 体格检查 / 会诊详情 / 会诊目的 / 会诊科室；
  右下角「申请医生」，左下角提示改为「请凭本会诊单至相应科室会诊。」，
  左下角「申请时间」、右下角「打印时间」保留。

### 修复

- **会诊重复发起拦截**：同一次就诊向同一科室发起会诊后，在该会诊未完毕
  （pending/doing）前不可再次向该科室发起；会诊完毕（done）后可再次发起。
  前端：科室选择页点击目标科室时即时提醒并中止；后端：`create` 接口按
  `target_dept_id + status IN ('pending','doing')` 权威拦截。

- **会诊详情页打印会诊单**：普通会诊详情页新增「🖨️ 打印会诊单」按钮；
  会诊科室点击确认会诊（候诊入口 withAccept）时不显示打印按钮；
  后端打印接口硬拦截：仅发起医生本人 / 会诊目标科室已确认接收的医生可打印，
  确认会诊前的接收方及其他科室一律拒绝。

- **会诊完毕会诊记录永久只读**：会诊完毕（done）后其会诊病历对任何人
  （含本人）不可编辑、不可删除：
  · 后端 `record_write.php`（save/save_diags）已有 done 锁定，本次补充
    `record_delete.php` 删除拦截 + `order_write` 经 `get_editable_record`
    排除 done 会诊记录（开单不落入已完毕会诊病历）；
  · 前端 `hasEditableRecord`/`currentRecordEditable` 已含 done 判定，
    病历节点删除按钮对 done 会诊记录隐藏，`deleteRecord` 预览拦截。

## [4.15.6] - 2026-08-30

### 修复

- **修复会诊完毕后急诊医生误入会诊模式 + 缺失前序病历**：
  场景——急诊科患者发给外科门诊会诊，外科会诊已完毕（done），急诊科医生
  回到本科室从候诊列表正常点开该患者，此前被误判进入会诊状态，且左侧只显示
  会诊记录、缺失会诊前的全部病历资料。
  根因一：前端 `loadData` 第三个分支只要 `DATA.record.consultation_id > 0`
  就无条件进入会诊模式，未校验会诊状态；会诊已完毕（done）的会诊病历
  （`consultation_id=2`）也会触发。现仅当关联会诊 `pending/doing` 才进入
  会诊模式，done 会诊病历恢复普通就诊视图，完整展示前序病历 + 会诊病历。
  根因二：`record_read.php` 中 `$mine` 取「本人最新文书」——急诊医生（关联
  急诊+外科）会诊完毕后最新文书即外科会诊病历（429，done），导致 `dept_match=0`
  且前端误判会诊。现改为：优先取「当前上下文可编辑」的本人文书——
  会诊处理中仅会诊病历可编辑；普通模式下优先书写科室==当前科室的本人文书
  （急诊的 415/416），已完毕会诊病历不再抢占编辑位。

## [4.15.5] - 2026-08-30

### 修复

- **修复开单保存后会诊病历页面误转只读 + 双灰底横条双签名**：`refreshReadOnlyBodies`
  在会诊模式（`__consult_mode`）下此前无条件把 `docBody` 替换为只读段——当会诊病历
  正处于可编辑状态（已创建且会诊未完毕）时，这会摧毁编辑器、并把骨架自带的
  `contHeadWrap`/`signWrap` 与只读段自带的横条/签名叠加，出现「两个灰底横条、
  右下角两个签名」。现在仅在「当前记录不可编辑」（会诊锁查看非会诊病历 / 会诊已完毕）
  时才替换 `docBody`，可编辑会诊病历保留编辑器，只刷新他人只读段。

- **会诊已完毕的会诊病历彻底只读**：`hasEditableRecord()` / `currentRecordEditable()`
  在会诊模式下新增 `consDone` 判定——会诊状态为 done 的会诊病历一律视为只读，
  不再允许开单 / 加诊断，与后端 `status=done` 锁定一致。

- **修复只读病历重复渲染**：`splitOthers` 在会诊模式下（`__consult_mode`）不再把
  当前记录误归入 `roBefore`——此前 `dept_match` 恒为 0 被误判为转科场景，导致当前
  续写记录在 `roBefore` 和 `docBody` 各渲染一次，显示两份。

- **修复会诊病历编辑自锁**：
  · `enterConsultEditor` 先设置 `record.consultation_id` 再创建编辑器（此前先
    `addProgressEditor` 后设 consultation_id，`fillContHead` 横幅误显示「病历续写」
    而非「会诊记录」）；
  · 新增 `currentRecordEditable()`：诊断添加判定与开单解耦——会诊处理中只要当前文书
    是会诊文书（consultation_id>0）即可添加诊断，不再要求 `record_id>0`；解决
    「会诊病历编辑中提示无可编辑病历、保存又要求先加诊断」的自锁；
  · `diagEditable` 改用 `currentRecordEditable()`。

## [4.15.4] - 2026-08-30

### 修复

- **会诊模式后端权威锁定（根治刷新后会诊状态丢失）**：新增 `get_consult_context()`
  （`helpers.php`），基于「就诊 + 目标科室」判定——该就诊存在「发给当前医生所在科室」
  的进行中/待处理会诊（pending/doing）即判定为会诊模式，与 URL 参数无关。
  `record_read.php` 返回 `consult_mode`/`consult_code`，前端 `loadData` 优先后端
  权威判定进入会诊模式：急诊科发会诊给外科门诊后，外科医生打开该患者无论怎么刷新
  都保持会诊模式；流水号无会诊申请或会诊不发给当前科室（含转科）则显示普通就诊/续写。

- **开单拦截二次加固（会诊模式下只读病历彻底禁开单）**：`get_editable_record()` 重构为
  会诊处理中仅「会诊病历（consultation_id=进行中会诊）」可编辑，其余（含本科室转科前
  文书）一律只读；前端新增统一 `hasEditableRecord()` 并与后端同规则，`requireSaved`/
  `diagEditable`/`openConsultCreate`/`syncNavAdds` 全部改用它动态判定——存在可编辑
  病历才允许开单/会诊/诊断/诊断证明，不存在即拒绝，杜绝会诊模式下将开单塞入只读续写
  记录。

- **修复会诊病历 consultation_id 绑定恒为 0**：`enterConsultEditor` 此前将 oid 混淆串
  直接写入 `record.consultation_id`，后端以整数匹配导致绑定失败；新增 `consultRawId()`
  将 code 还原为整数 id 再提交。

- **dept_match 精确化**：会诊处理中仅会诊病历匹配；普通模式下会诊文书须会诊未完毕才匹配
  （已完毕的会诊病历彻底只读）。

## [4.15.3] - 2026-08-30

### 修复

- **开单/会诊/诊断证明/诊断添加必须存在可编辑病历**：转科后未在本科室书写续写病历、
  或进入会诊患者但尚未创建会诊病历时，所有需要病历支撑的操作（开检验/检查/处置/处方、
  发起会诊、开具诊断证明、添加诊断）一律强制拦截——前后端均按「可编辑病历」规则校验。
  后端：新增 `get_editable_record()` 辅助函数（`helpers.php`），`order_write.php`、
  `consultation.php`（发起会诊）、`record_cert.php`（诊断证明）统一要求本人当前科室
  可编辑病历（`dept_id === current_dept_id || consultation_id > 0`），杜绝 `record_id=0`
  的悬空开单；前端：`requireSaved` 增加转科 only-read 拦截、`diagEditable` 增加
  dept_match 校验、`openConsultCreate` 增加 dept_match 校验、新增 `syncNavAdds()`
  按可编辑病历状态显示/隐藏右侧大纲栏「＋」按钮（病历节点/知情同意书除外）。

- **诊断证明归档补开保全**：已诊毕归档病历保留接诊过即可补开证明的原有逻辑，
  未归档病历才要求可编辑病历。

## [4.15.2] - 2026-08-30

### 修复

- **移除宽泛的会诊检测根治误判**：仅 URL 带 `?consult=code` 参数或当前记录
  `consultation_id>0` 才进入会诊模式，移除原有通过就诊状态宽泛推断会诊的逻辑，
  避免非会诊患者被误判定为会诊模式导致只读锁死。

- **只读段开单完整显示**：`refreshReadOnlyBodies` 增加 consultLock/finished 分支，
  在 ORDERS 加载完成后重刷所有只读段，确保辅助检查/门诊处置完整展示。

- **会诊绑定病历 ID**：会诊记录 `consultations.record_id` 记录发起时的病历 id，
  会诊病历按 `record_id` 精确展示。

## [4.15.1] - 2026-08-30

### 修复

- **续写/会诊病历只读段显示本记录开单与会诊**：续写/会诊病历处于只读状态
  （诊毕只读 / 查看他人文书 / 会诊锁只读）时，辅助检查 / 门诊处置恢复显示
  本记录名下开具的检查、检验、处置、处方与发起的会诊——开单按 record_id
  强关联精确归属，不串显示其他病历开单；与打印行为一致。

- **续写/会诊病历只读段隐藏空节**：只读状态下，续写/会诊病历的既往史、
  过敏史在「否认」时不再显示（与打印一致）；主诉/现病史/主要症状归首诊
  文书，续写只显示「病历续写 / 会诊记录」内容。

---

## [4.15.0] - 2026-08-30

### 变更（重要架构优化）

- **开单与病历强关联（record_id）**：orders 表新增 `record_id` / `dept_id` /
  `dept_name` 字段（v6 迁移）——开单时记录当前所在病历（首诊/续写/会诊）与
  开单科室固化快照。病历正文 / 只读段 / 打印均按 record_id 精确归属开单，
  彻底杜绝「会诊开具的检查显示在首诊病历」「转科后开单科室漂移」等跨病历
  串显示问题；兼容旧数据（record_id=0）回退按医生归属。

- **会诊期间开单删除规则修正**：会诊期间仅可删除「本会诊记录名下」开具的
  开单（本会诊期间开具的可删），其他病历名下开单一律只读——前后端同步拦截，
  不再一刀切禁止删除会诊期间开单。

### 修复

- **只读病历会诊重复显示**：`emr_orders.js` 删除重复的会诊遍历代码段，
  只读段不再出现两个相同的「请X科会诊」。

- **+ 号隐藏时箭头靠右统一**：新增 CSS `:has()` 规则——任意分区 + 号以
  display:none 隐藏时，相邻折叠箭头自动保持右对齐，无需各分区逐一修复。

- **只读病历门诊处置格式优化**：只读段门诊处置与打印一致——处方每行一条，
  处置项/会诊汇总于其后；辅助检查 / 门诊处置按本记录开单归属展示。

- **会诊期间点击首诊病历**：固定滚动到该只读段锚点 + 轻提示
  「会诊期间，其他病历仅可查看（只读）」；不再渲染灰底提示条幅。

- **打印会诊记录科室修正**：续写 / 会诊记录打印头部科室改用记录自身
  dept_id（会诊目标科室），不再取就诊当前科室（避免急诊科请外科门诊会诊时
  误显示急诊科）。

---

## [4.14.1] - 2026-08-30

### 修复

- **只读模式门诊处置显示开单与会诊**：首诊病历的只读段（他人文书/诊毕/会诊
  只读）恢复显示该医生开具的检查/检验/处置/处方及发起的会诊（「请X科会诊」），
  与编辑态所见一致；续写/会诊记录仍完全独立（仅展示本记录自身手工字段）。
  打印病历同步补充首诊的会诊信息展示。

- **会诊 + 号隐藏时箭头靠右**：隐藏「发起会诊」+ 时，相邻折叠箭头因 CSS
  相邻选择器 margin-left 覆盖而失去靠右对齐，已显式置回 margin-left:auto。

- **会诊期间仅隐藏会诊 + 号**：修正此前误将检查/检验/处置/处方/病历节点等
  全部 + 号隐藏的问题——会诊期间仅隐藏「发起会诊」+，其余分区 + 保持可用。

- **会诊期间诊断证明可查看不可开具**：会诊期间右侧「诊断证明」分区恢复显示
  （可查看已开具证明），仅隐藏 + 号禁止新开具；后端 certificate 接口同步拦截。

- **无本人病历禁止开单/会诊/诊断证明**：所有开单项目（检验/检查/处置/处方）、
  会诊、诊断证明均需在病历中自动记录与展示——本人未保存病历（含转科后尚无
  续写病历）时前后端统一拦截，提示先书写并保存病历（首诊或续写）。

---

## [4.14.0] - 2026-08-30

### 修复

- **候诊会诊中非会诊医生不可点开**：候诊列表「会诊」Tab 中标记为「会诊中」
  （doing）的会诊，仅会诊医生本人可点击进入病历页；非会诊医生点击时提示
  「xxx 正在会诊该患者」并阻止进入，确保一次会诊仅一个医生操作。

- **会诊期间所有病历只读（前后端拦截）**：进行中的会诊期间，本就诊全部
  非会诊病历一律锁定为只读，不可编辑（无论是否本人书写）；后端 save /
  save_diags 同步拦截；会诊医生只能编辑自己的会诊病历。

- **会诊期间禁止删除开单**：进行中的会诊期间，所有检查/检验/处置/处方
  均不可删除（后端 order delete 拦截 + 前端隐藏删除按钮）；仅可查看。

- **会诊完毕后病历永久只读**：会诊完毕后（done），会诊病历永久锁定为
  只读，不可修改（后端 save / save_diags 拦截 + 前端只读渲染 + 状态提示
  「该会诊已完毕，会诊病历已永久锁定为只读状态」）；会诊期间开单同样
  禁止删除。

- **会诊期间隐藏诊断证明**：进行中的会诊期间，右侧边栏「诊断证明」分区
  整体隐藏（前端 certSec display:none + 后端 certificate 接口拦截）。

- **会诊期间隐藏发起会诊+按钮**：进行中的会诊期间，右侧边栏「会诊」
  分区 + 按钮隐藏（不可再发起新会诊）；openConsultCreate 前端入口拦截；
  后端 create 接口同步拦截（已有进行中会诊时拒绝重复发起）。

- **打印会诊记录不携带首诊内容**：续写/会诊病历打印时，辅助检查/门诊
  处置/处方不再拉取该医生历史开单（仅展示本记录自身手工字段
  aux_result / aux_external / disposition_custom），从根源上阻断首诊
  内容带入。

---

## [4.13.0] - 2026-08-30

### 修复

- **会诊进入不再自动新建续写**：点进已接诊会诊患者（URL 携带 consult=code
  或自动识别进行中的会诊）时，默认只读展示原病历 + 引导提示，医生点击右侧
  「病历节点 ＋」后才创建会诊病历编辑器（不再自动弹出续写编辑器）。

- **会诊记录科室归属修正**：会诊病历的书写科室改为会诊目标科室（如急诊科
  请外科门诊会诊 → 会诊记录归属外科门诊），右侧病历节点显示「外科门诊
  （会）」而非「急诊科（续）」；会诊记录不受转科只读限制，保存/诊断/删除
  均按会诊目标科室校验。

- **会诊诊断排序拦截**：会诊模式/会诊记录右侧初步诊断不再弹「设为主诊断 /
  上移 / 下移」操作悬浮窗，后端 `save_diag_order` 同步拦截；会诊医生仍可
  添加/删除自己的诊断。

- **会诊过程隐藏加号与删除按钮**：会诊过程中隐藏「发起会诊 ＋」按钮（不可
  再发起新会诊，后端 `create` 同步拦截重复发起）；会诊列表中仅「待会诊
  （pending）」且本人发起的会诊显示删除按钮，进行中/已完毕一律不显示
  （后端 `delete` 仅允许 pending）。

- **候诊列表已接诊会诊直接进病历**：候诊「会诊」Tab 中，已接受（doing）的
  会诊点击直接进入病历页，不再弹出会诊详情；待会诊（pending）仍弹详情并可
  确认会诊；会诊病历被删除后自动回退待会诊，恢复弹详情确认。

- **续写/会诊病历完全独立**：续写（含会诊）病历不再关联首诊的生命体征、
  辅助检查、门诊处置——编辑器与只读段均只展示本记录自身内容（生命体征按
  record_id 归属，辅助检查/门诊处置不再自动带入该医生历史开单）。

- **会诊记录徽章统一**：会诊续写横条、打印页均显示「会诊记录」徽章（打印
  页承接头由「续写病历」改为「会诊记录」）。

---

## [4.12.11] - 2026-08-30

### 修复

- **费用悬浮窗挂号费显示挂号科室**：横条总费用悬浮明细中的「挂号费」
  行改为显示「挂号费（挂号科室）」，如「挂号费（急诊科）」，费用来源
  更清晰（挂号费归属首次挂号科室，不随转科改变）。

- **转科 / 会诊号源直接显示挂号时确定的号源**：号源（上午 / 下午 / 昼夜）
  在挂号时就已确定并随挂号记录存储，展示时不再根据科室类型重新推算——
  急诊科挂号的患者即使转科或请外科门诊会诊，号源仍显示「昼夜」。
  挂号时对急诊科室统一存储 `all`（昼夜），新增 `session_display_text()`
  统一映射，删除候诊列表与会诊列表中基于 `first_dept_id` 的重复判断；
  并修复候诊列表查询未取 `first_dept_id` 导致急诊号源误显示为「上午」的问题。

- **会诊 Tab 点击「确认会诊」按钮缺失**：修复「确认会诊」按钮逻辑被误放
  入诊断证明 `certificateModal` 的问题——恢复 `openConsultDetail` 中按
  `withAccept` 参数展示「确认会诊」按钮（pending 状态时点击后 accept 并
  跳转病历页自动进入会诊模式），同时清理 `certificateModal` 内误植的
  会诊代码段（引用未定义的 `withAccept` / `c` / `closePanel`）。

- **候诊列表 Tab 组合筛选深度修复**：会诊列表此前未返回 `created_at`
  字段，导致勾选「当日」+「会诊」时筛选恒为空；已诊筛选误用就诊状态
  `status` 而非会诊状态 `consult_status`。统一按以下组合规则修复：
  - 都不选：近 N 天未诊患者（候诊），按挂号时间正序；
  - 仅「已诊」：近 N 天诊毕患者，按诊毕时间倒序；
  - 仅「当日」：当日未诊毕患者（候诊）；
  - 「已诊」+「当日」：当日诊毕患者；
  - 会诊 Tab：「已诊」= 会诊完毕（consult_status='done'）、「当日」=
    今日发起的会诊（created_at）；并修复会诊搜索字段名不匹配问题。

---

## [4.12.10] - 2026-08-29

### 修复

- **会诊 Tab 空列表修复**：会诊就诊集合查询去掉了多余的
  `current_dept_id` 过滤——患者可能又被转走，按会诊 target_dept_id
  与可见天数过滤即可；实测 doctor2001 的会诊列表可正常显示。

---

## [4.12.10] - 2026-08-29

### 修复

- **会诊 Tab 空列表**：会诊就诊集合查询去掉多余的 `current_dept_id`
  过滤（患者转科后 current_dept 已变，导致会诊被自己过滤掉）；
- **会诊状态徽章**：去掉小圆点，与非会诊列表一致直接用普通徽章
  （待会诊/会诊中/会诊完毕）；
- **转科患者号源显示**：号源（昼夜/上午/下午）按患者【挂号科室】判断
  而非当前科室——急诊科挂号转走后仍显示昼夜（补查 first_dept_id 字段）。

---

## [4.12.9] - 2026-08-29

### 修复

- **候诊会诊 Tab 报错修复**：上版误删的列表渲染函数导致点击会诊 Tab
  报 `consultListHtml is not defined`——恢复候诊列表原版渲染（会诊行
  与候诊行同构，状态列显示小圆点+待会诊/会诊中/会诊完毕）；
- **转科患者横条科室显示**：患者信息横条改为显示挂号科室（转科不影响
  患者归属科室显示，record 接口返回 first_dept_name）。

---

## [4.12.8] - 2026-08-29

### 修复

- **候诊会诊行点击后面板不关闭**：先关闭候诊面板再弹会诊详情模态框；
- **会诊详情两套代码合并**：候诊会诊行点击直接复用病历页
  openConsultDetail（统一渲染器/标题/确认会诊按钮），删除 queuepanel
  内重复实现；
- **会诊 Tab 行样式还原**：会诊列表复用候诊列表原版九列行样式，状态列
  显示【待会诊】（小圆点+文字）；
- **startConsult 容错**：会诊已被接收时 accept 失败不阻断，直接进入
  会诊编辑器。

---

## [4.12.7] - 2026-08-29

### 修复

- **检查/检验/处置详情圆点全灰**：后端 order_flow_steps 的「开单」节点
  缺少 done 标记，前端整列误判为未完成。开单节点固定 done=1。
- **会诊进度缩进与日期**：进度区双层 padding 收敛为单层（贴近分隔线）；
  时间显示完整「MM-DD HH:MM」（此前列宽不足被截断）；右列加宽至
  190px 与开单详情一致。

---

## [4.12.6] - 2026-08-29

### 修复

- **流程进度三处统一**（flowColumnHtml 支持自定义标题）：
  - 会诊详情：去掉重复的「会诊进度」标题与多余竖线（左右两列各一条
    border 叠加），进度区直接复用统一渲染器；
  - 处方详情（showRxDetail）：流程列接入后端 flow 数据（操作人/时间），
    并补上左分隔线（此前丢失）；
  - 开单详情流程列加宽（170→190px），避免操作人/时间换行。

---

## [4.12.5] - 2026-08-29

### 修复

- **会诊进度双竖线**：根因是 flowColumnHtml 自带 border-left 与会诊详情
  右列的 border-left 叠加。统一后 flowColumnHtml 不再自带分隔线，由调用
  方处理（开单详情包 border-left，会诊详情去掉重复线）。
- **开单详情流程操作人/时间渲染**：确认走 showItemDetail → flowColumnHtml
  （统一渲染器：圆形节点+操作人/时间），数据来自后端 order_flow_steps。

---

## [4.12.4] - 2026-08-29

### 变更

- **开单进度流程显示操作人与时间**：后端 visit_orders 新增 flow 节点数组
  （order_flow_steps：开单/缴费/登记/发药(完成)，每节点带操作人与时间——
  开单=医生+创建时间、缴费=收费员(payments)+缴费时间、登记/发药=
  executed_by/executed_at）；
- **开单详情流程与会诊进度样式统一**：viewOrderFlow 改用后端 flow 数据
  并渲染为圆形步骤节点+操作人/时间（与会诊进度同款竖排步骤样式）；
- **修复**：候诊会诊行点击改为弹出会诊详情（待处理时底部「确认会诊」
  进入病历书写）。

---

## [4.12.3] - 2026-08-29

### 修复

- **生命体征弹窗点击行为对齐**：外部点击关闭的豁免范围由整行
  （.doc-sec-vital）收窄为内容链接（#vitalLink）——点击「生命体征：」
  标签或行内空白时弹窗正常关闭（与收窄后的点击区域一致）。

---

## [4.12.2] - 2026-08-29

### 修复

- **生命体征行鼠标样式**：移除 `.doc-sec-vital` 整行 `cursor:pointer`，
  可点击指针仅在内容区（.emr-item-link）显示，标签与空白回归普通文字。

---

## [4.12.1] - 2026-08-29

### 修复

- **生命体征空值样式统一**：去掉 vital-empty 降级，「—」同样使用
  .emr-item-link 样式（空值也是病历内容的一部分）；
- **生命体征点击区域收窄**：点击弹窗仅限「—」/内容区（.emr-item-link），
  「生命体征：」标签与后侧空白不再触发。

---

## [4.12.0] - 2026-08-29

### 统一

- **病历生命体征内容复用 .emr-item-link 样式**：编辑态与只读段的生命
  体征内容统一包裹项目标签样式（与诊断/开单一致）；空值（未录入「—」）
  自动降级灰色无下划线（vital-empty）。

---

## [4.11.9] - 2026-08-29

### 变更

- **候诊列表排序完善**：非诊毕患者（无论是否勾选当日）一律按挂号时间
  正序（新的在下）；会诊 Tab 按发起时间正序（新的在下）；诊毕仍按诊毕
  时间倒序（最后诊毕在最上）。
- **候诊搜索栏自适应宽度**：由固定 350px 改为 flex 自适应（120-350px），
  跟随前侧筛选 chip 数量自动伸缩。
- **过敏史按钮复用 .emr-item-link 样式**（病历项目标签同款）。

---

## [4.11.8] - 2026-08-29

### 变更

- **会诊发起/删除权限收紧**：
  - 会诊医生（正在处理会诊）隐藏会诊分区「＋」，后端拦截其再发起会诊；
  - 发起人的会诊被接收科室处理中/完毕后，右侧列表不再显示删除按钮；
    后端仅允许删除未被接收（pending）的会诊；
  - B 科室医生删除会诊病历 = 放弃本次会诊处理 → 会诊状态回退待会诊
    （pending），发起人侧删除按钮随之恢复。

---

## [4.11.7] - 2026-08-29

### 变更

- **会诊状态圆点配色对齐费用指示灯**：待处理=灰（同未缴费）、正在会诊=
  红（同已缴费未完成）、会诊完毕=绿（同已完成）；
- **候诊会诊行点击改为弹会诊详情**：详情底部「✅ 确认会诊」→ accept 接受
  会诊 → 跳转病历书写页（URL 携带 consult=code），页面自动开始会诊并
  新建会诊病历编辑器（不再直接进病历）。

---

## [4.11.6] - 2026-08-29

### 修复

- **会诊三处修复**：
  - 会诊详情/删除报「会诊记录不存在」：id 未走混淆串——列表/正文统一返回
    并传递 code（oid），后端 did 解码；
  - 右侧会诊列表状态改为**费用状态同款小圆点**（黄=待处理/绿=进行中/灰=
    完毕，悬浮显示状态文字）；
  - 病历门诊处置「请X科会诊」样式交由病历系统统一渲染（复用
    .emr-item-link 项目标签样式，只读段纯文本），会诊不再自带样式。

---

## [4.11.5] - 2026-08-29

### 修复

- **会诊快照接口 500**：consultation.php 缺少 `emr_formatter.php` 引入，
  snapshot 调用 emr_cc_text 等函数报未定义错误。补 require 后恢复。

---

## [4.11.4] - 2026-08-29

### 修复

- **会诊病历诊断操作拦截**：会诊模式下点击诊断不弹出「设为主诊断/上移/
  下移」操作浮窗（后端 save_diag_order 同步拦截）——只能依次在已有诊断
  下方添加新诊断。

---

## [4.11.3] - 2026-08-29

### 新增

- **会诊功能 Step 4 · B 科室会诊病历模式**：
  - 候诊会诊列表点击患者进入病历 → 占位区显示会诊请求状态 +「开始会诊」
    按钮（accept：pending→doing）；
  - 开始会诊后新建会诊病历编辑器（复用续写链路，consultation_id 关联）；
    隐藏转科按钮（后端同步拦截会诊医生转科）；「诊毕」按钮变为「会诊完毕」
    （确认后 finish 关闭会诊，回到候诊列表）；
  - 会诊病历限制：不可调整诊断顺序（save_diag_order 拦截），保存发送
    consultation_id 关联；
  - 灰色条幅新增【会诊】徽标；侧边栏节点显示「（会）」与「会诊病历编辑中…」；
  - 打印/只读显示【会诊记录】（consultation_id>0 的续写文书）。

---

## [4.11.2] - 2026-08-29

### 新增

- **会诊功能 Step 3 · 发起会诊与右侧会诊列表**：
  - 右侧处方与诊断证明之间新增「🤝 会诊」分区（＋发起会诊）；
  - 发起流程：科室选择模态框（隐藏当前科室）→ 会诊单模态框（病历快照
    只读：主诉/现病史/查体/诊断 + 会诊描述/会诊目的）→ 发送成功；
  - 右侧会诊列表：日期 时间 请X科会诊 + 删除按钮（仅发起人，后端硬校验）；
  - 会诊查询模态框：左侧会诊单只读（主诉/现病史/体格检查/诊断/描述/目的），
    右侧会诊进度三状态圆点（发起会诊→正在会诊→会诊完毕）；
  - 病历门诊处置自动追加「请X科会诊」（点击弹出会诊详情）；
  - 后端新增 snapshot（发起表单病历快照）与 visit_consults（本就诊会诊
    列表）接口。

---

## [4.11.1] - 2026-08-29

### 新增

- **会诊功能 Step 2 · 候诊列表会诊 Tab**：
  - 候诊面板新增「会诊」筛选（与已诊/当日多选组合：会诊+已诊=已完毕、
    会诊+当日=今日发起、会诊+已诊+当日=今日已完毕）；
  - 会诊列表：日期/患者/发起科室/请X科会诊/发起医生/状态（三色圆点：
    发起会诊/正在会诊/会诊完毕）；
  - 列表受医生候诊可见天数（queue_days）限制，后端同步返回会诊数据；
  - 点击会诊行进入患者病历（B 科室医生处理会诊入口）。

---

## [4.11.0] - 2026-08-29

### 新增

- **会诊功能（分期实施）**：
  - Step 1 · 数据库与后端 API：新建 consultations 表（013 schema，
    发起/目标科室、描述/目的、pending→doing→done 状态线）；
    patient_records 增加 consultation_id（007 v10）关联会诊病历；
    新增 `/api/consultation`（create/list/detail/accept/finish/delete），
    列表受医生候诊可见天数（queue_days 2-7）限制，后端强制拦截。

---

## [4.10.4] - 2026-08-29

### 修复

- **删除病历后全面局部重载**：删除成功后调用 loadData 局部重载病历区
  （AJAX 拉取最新数据完整重渲染），横条/签名/续写提示/编辑态判断
  （书写科室、书写者）/锚点滚动全部套用既有逻辑：
  - 删除续写 → 残余「续写编辑中」占位消失；本人续写本人删除后直接回到
    可编辑首诊（无提示框）；他人病历续写删除后恢复续写提示；
  - 删除首诊 → 回到「首张电子病历尚未创建」页（无横幅无签名），并自动
    弹出病历模板选择框；
  - 就诊状态实时同步（当前科室无文书回退待就诊）。

---

## [4.10.3] - 2026-08-29

### 修复

- **保存后新诊断右侧删除按钮消失**：新建文书保存时 `dept_match` 未同步
  （新建时为 0），导致侧边栏诊断 `inCurrent` 误判为否、删除按钮不渲染；
  保存成功后修正 `dept_match=1`，刷新前后删除按钮一致。
- **删除续写病历不再强制刷新页面**：局部更新——records_history 移除该
  文书、移除对应只读段、重置为续写占位态、同步就诊状态（当前科室无
  文书回退待就诊）。

---

## [4.10.2] - 2026-08-29

### 简化

- **新建续写保存页面保持不变**：保存后编辑器与内容原样保留，仅右侧
  「续写编辑中」占位转为正式病历节点；再次保存按本人最新文书更新同一条
  记录（不再归档只读段/重建编辑器）。

---

## [4.10.1] - 2026-08-29

### 修复

- **新建续写保存后不再自动新建空白续写编辑器**：保存成功后，刚保存的
  续写转为只读段（__archived 标记 + splitOthers 归入只读区，可被后续
  refreshReadOnlyBodies 正确重渲染），正文显示续写占位——点击右侧
  「病历节点＋」才继续续写。

---

## [4.10.0] - 2026-08-29

### 变更

- **转科后病历只读（按书写科室校验）**：
  - 病历书写科室与就诊当前科室不一致 → 只读展示（类似查看他人病历），
    下方提示续写；点击右侧「病历节点＋」续写，保存后当前科室状态改为
    就诊中；
  - 后端硬校验：编辑/诊断调整/删除旧科室病历均拒绝（即使本人）；
  - 删除当前科室续写病历后，若当前科室已无文书则状态回退待就诊
    （按 dept_id 统计，转回原科室仍显示就诊中）；
  - record 接口返回 dept_match / dept_id，前端据此判定只读与占位；
- **新建续写保存不再全局刷新**；
- **转科后跳转**：转科成功回到空白工作台（候诊列表自动弹出）。

---

## [4.9.6] - 2026-08-29

### 变更

- **转科后病历只读（按书写科室校验）**：
  - 病历书写科室与就诊当前科室不一致 → 只读展示（类似查看他人病历），
    下方提示续写；点击右侧「病历节点＋」续写，保存后当前科室状态改为
    就诊中；
  - 后端硬校验：编辑/诊断调整/删除旧科室病历均拒绝（即使本人）；
  - 删除当前科室续写病历后，若当前科室已无文书则状态回退待就诊
    （按 dept_id 统计，转回原科室仍显示就诊中）；
  - record 接口返回 dept_match / dept_id，前端据此判定只读与占位；
- **新建续写保存不再全局刷新**：保存成功后客户端直接归档当前文书为
  只读段、重建新续写编辑器（复用 addProgressEditor），无缝继续续写；
- **转科后跳转**：转科成功回到空白工作台（候诊列表自动弹出），可点击
  新科室患者进入病历书写。

---

## [4.9.5] - 2026-08-29

### 修复

- **续写只读体格检查/生命体征空节仍显示**：格式化函数空值时返回占位符
  （emr_pe_text 返回 '-'、vitalDisplayText 返回 '—'），非空判断失效。
  改为按原始数据判断是否有值——续写空节彻底隐藏，首诊维持显示 '-'。

---

## [4.9.4] - 2026-08-29

### 变更

- **续写病历只读显示空节隐藏**：历史记录浏览（emr_segments.js）与打印
  （print_record.php）中，续写病历未填写的内容（既往史、过敏史、生命体征、
  意识状态、体格检查、辅助检查、门诊处置、嘱托）不再显示；首诊病历维持
  现状（空节显示 -）。

---

## [4.9.3] - 2026-08-29

### 修复

- **续写病历诊断显示问题**：
  - 新添加的诊断不再跑到右侧列表顶部/变主诊断——侧边栏构建改为
    **旧文书诊断在前、当前文书诊断在后**（新诊断默认追加在下方）；
  - 保存病历后诊断短暂消失又出现——新建续写（progress_new）保存时
    不再按医生回退匹配旧文书，改为新建侧边栏条目，避免误覆盖首诊 emr
    导致暂时错乱，随后刷新恢复；
- **续写留观状态空白**：续写编辑器 defaults 补充 `is_leave_hospital: '否'`，
  留观下拉不再空白。

---

## [4.9.2] - 2026-08-29

### 修复

- **过敏史重构为以患者主表为唯一数据源的模态框管理**：
  - 模态框始终从患者主表（patients.allergies）读取/写入；病历显示的过敏史
    是保存时的快照，与模态框分离；
  - 仅当通过模态框修改过（allergy_modified=1）才同步患者主表，未打开模态框
    直接保存病历不改变主表（避免误清空）；
  - 修复隐藏字段 innerText 在 display:none 上返回空导致保存丢失；
  - 下划线颜色与边框一致（var(--border)）；
  - 有过敏史时按钮直接显示内容（不再带「承认：」前缀），默认显示「否认」。

---

## [4.9.1] - 2026-08-29

### 修复

- **过敏史样式统一**：否认/承认 由按钮改为蓝色下划线可点击文字（同诊断
  点击样式），点击弹模态框；有过敏史时显示「承认：内容」。
- **过敏史删除失效**：原每次打开都把历史过敏史重新合并，删掉的保存后再
  打开又出现。改为：当前「否认」时预载历史，「承认」时用已保存列表
  （删除持久生效）。
- **续写过敏史默认否认**：续写编辑器不再继承前序病历过敏史（仅既往史
  自动引用），默认显示否认；点击后在模态框内核对历史，保存才引入当前
  病历。

---

## [4.9.0] - 2026-08-29

### 新增

- **过敏史重构为模态框多条目管理**：
  - 过敏史区域由「否认/承认下拉+文本框」改为**按钮**：默认显示「否认」，
    点击弹出过敏史模态框；
  - 模态框：输入框 + ＋ 添加 / 列表（每项右侧 ✕ 删除，超长滚动）+
    取消 / 保存；
  - 保存后自动变为「承认：过敏史内容」，再次点击（承认或内容）可重开
    模态框编辑；
  - 续写/再次挂号默认**否认**（不自动引用），但模态框内**预载患者历史
    过敏史**（患者主表 allergies），直接保存即引用到当前病历；
  - 诊毕只读时不弹编辑框。

---

## [4.8.2] - 2026-08-29

### 修复

- **record_write.php 语法错误导致全站 500**：`save_vitals` 的 if/else 结构
  缺少一个闭合 `}`（括号不平衡 68/67），PHP 解析失败，`/api/record`
  所有动作（含 GET）返回 500。补全闭合括号修复。

---

## [4.8.1] - 2026-08-29

### 变更

- **生命体征录入区分新增与修改**：vitals 表新增 `record_id`（v2 迁移）：
  - 同一病历内录入/修改体征 → **更新**该记录对应条目（纠错不产生新记录）；
  - 新病历（首诊/续写）首次录入 → **新增**条目（新测量，记录在案）；
  - 新病历保存时自动回填 record_id（此前未保存时的录入关联到病历）；
  - 护士站录入始终新增（多次测量留档，record_id=0）。

---

## [4.8.0] - 2026-08-29

### 新增

- **生命体征多次记录与趋势展示**：
  - 每次录入（首诊/续写/护士站）都会新增一条体征记录，全程留档；
  - 护士站体征弹窗升级：顶部展示各指标**趋势折线图**（内联 SVG，收缩压/
    舒张压/心率/血氧），下方历史记录表格（时间/数值/录入人）+ 录入表单；
  - 护士站 `vitals` 接口返回全部记录（`vitals_history`）+ 最新一条。

---

## [4.7.8] - 2026-08-29

### 修复

- **续写病历生命体征完全独立**：生命体征改为随各记录 emr_data 持久化
  （`emr.vitals`）：
  - 续写编辑器初始化 `emr.vitals={}`，不继承首诊体征；
  - 体征编辑弹窗：续写用记录自身 vitals（新建为空），初始病历取就诊
    vitals（护士站同步）；
  - 体征保存同步写入当前记录 emr.vitals + 就诊 vitals 表（护士站可见）；
  - 只读段显示优先记录自身 emr.vitals。

---

## [4.7.7] - 2026-08-29

### 修复

- **续写病历不应带入嘱托和生命体征**：续写编辑器（`createProgressEditor` /
  `addProgressEditor`）基于前次病历克隆 emr 时只清了诊断和 progress 内容，
  嘱托（advice）、生命体征（vitals）等被自动代入。修复：续写仅保留
  既往史和过敏史，其余字段（主诉/现病史/体格检查/主要症状/辅助检查/
  门诊处置/嘱托）重置为空，生命体征传空对象。

---

## [4.7.6] - 2026-08-29

### 变更

- **申请单条形码下方数字限宽**：文字与条形码同宽约束（max-width:38mm +
  overflow:hidden），字号 12→11px、字距 1px→0.5px，避免数字超长时
  超出条形码宽度。

---

## [4.7.5] - 2026-08-29

### 新增

- **处方联动处置级联删除**：orders 表新增 `source_order_id`（v5 迁移），
  联动处置单（皮试/途径绑定）记录来源处方 ID；删除处方时自动级联删除
  关联的联动处置单（已进入执行流程的则拦截提示）；独立删除联动处置单
  不反删处方。

---

## [4.7.4] - 2026-08-29

### 修复

- **通用选择器「新建项目」无反应**：`showCreateForm` 引用了 `open()`
  局部变量 `isAdmin`，作用域外访问抛 ReferenceError，导致表单未赋值
  前中断（列表仍显示、无输入框）。`isAdmin` 提升为模块级 `IS_ADMIN`，
  修复绑定计费处置、关联皮试处置等所有通用选择器的新建项目功能。

---

## [4.7.3] - 2026-08-29

### 变更

- **处方按护士药品拆分生成单据（修正）**：按主医嘱 need_nurse 分组（子医嘱
  跟随主药），护士药品组额外生成一张处方笺（单号+N，给药房取药）+ 一张
  门诊输液（注射）笺副本（单号+Z，给护士站）；非护士药品组生成处方笺
  （原单号）。示例：AB 非护士 + C 护士 → 三张单据（AB处方笺 / C处方笺 /
  C输液笺），缺一不可。

---

## [4.7.2] - 2026-08-29

### 变更

- **输液笺生成逻辑修正**：处方含护士药品时，额外生成一张门诊输液（注射）
  笺，**内容与处方笺完全一致**（含全部药品、子医嘱），仅区别 抬头 / 左下
  提示（药房取药 vs 护士站输液注射）/ 单号（原单号 + Z）。处方笺始终包含
  全部药品给药房取药，不再按 need_nurse 拆分药品。

---

## [4.7.1] - 2026-08-29

### 修复

- **处方自动拆分 bug**：原按单项 `need_nurse` 拆分导致子医嘱（need_nurse=0）
  被错误分到药房笺（无主药→空白处方），护士笺只显示主药。改为**按主医嘱
  need_nurse 分组**，子医嘱跟随其主药同组同笺，并重排子医嘱 sub_of 以匹配
  新分组内序号；混合处方两组内容完整。

---

## [4.7.0] - 2026-08-29

### 新增

- **门诊输液（注射）笺 + 处方自动拆分**：
  - 处方打印时按药品 `need_nurse` 自动拆分：
    - 非护士药品 → 门诊处方笺（药房取药提示，原单号）；
    - 护士药品 → 门诊输液（注射）笺（护士站输液/注射提示，派生单号
      原单号+Z）；
  - 一张处方同时含护士与非护士药品时自动拆成两张（药房笺 + 输液笺），
    单号不同；
  - 仅含护士药品时只生成输液笺；仅含非护士药品时只生成药房笺；
  - `pt_order` 增加 `$opts`（display_no / note_type）支持。

---

## [4.6.5] - 2026-08-29

### 修复

- **开单页子医嘱连接符**：多个子医嘱时首个不再显示 `┌`，统一为非末行
  `├`、末行 `└`（单个即 `└`）。
- **子医嘱重复添加校验**：不能添加与主药相同的药品为子医嘱，也不能添加
  已作为其它主医嘱的药品（原仅校验子医嘱内部重复）。
- **打印处方中间子医嘱连接符**：多个子医嘱时中间显示朝左连接符 `┤`
  （原为 `│`），首行 `┐`、中间 `┤`、末行 `┘`。

---

## [4.6.4] - 2026-08-29

### 新增

- **处方单组合医嘱大括号**：剂量与途径之间新增制表符列，组合医嘱用
  `┌│└` 纵向连接（首行 ┌ / 中间 │ / 末行 └），把主药与子医嘱整体括起来，
  视觉更正式；非组合医嘱该列留空。

---

## [4.6.3] - 2026-08-29

### 变更

- **申请单患者信息「单号」改为「开单科室」**：右上角条形码已展示单号，
  原重复显示的位置改为开单科室（取开单医生当前科室，兜底就诊当前科室）。

---

## [4.6.2] - 2026-08-29

### 变更

- **处方单底部重构为标准医院处方布局**：
  - 移除「护士站执行」标注（途径后不再显示，取药提示不再提及）；
  - 医师签名与金额合并为同一行（金额靠左、签名靠右），位于取药提示下方；
  - 调配/复核/发药置于两条实线之间的窄条区域（新增一条实线与医师签名隔开）；
  - 本处方当日内有效改为 foot 区块最后一个节点，居中于页码正上方；
  - 处方合计金额（主药+子医嘱）累计计算并显示。
- **清理废弃 CSS**：移除 `.print-rx-pharm` / `.print-rx-valid`（已改用
  print-note + 内联样式）。

---

## [4.6.1] - 2026-08-29

### 新增

- **处方单底部增加「调配 / 复核、发药」行**：医师签名下方新增左右两列
  （均靠左对齐），左侧 调配：、右侧 复核、发药：，仅处方单显示。
- **处方单新增「本处方当日内有效」提示**：页脚页码正上方，仅处方单显示。

### 修复

- **处方单组医嘱数量列拆分**：途径/频次保持纵向合并（rowspan），但数量
  列改为每行独立显示（子医嘱各自显示 ×N，不与主药合并）。

---

## [4.6.0] - 2026-08-29

### 变更

- **处方单打印改标准医院处方样式**：
  - 去除表格表头、序号，隐藏表格线；
  - 名称列合并显示「名称 规格 厂商」，去掉单独的规格/含量列；
  - 列顺序：名称 | 剂量 | 途径 | 频次 | 数量（×N，最后一列小列右对齐）；
  - 组医嘱：子医嘱仅显示 名称/剂量（与主药一致），主药的 途径/频次/数量
    纵向合并（rowspan）跨其子医嘱，垂直居中靠左显示一次。

---

## [4.5.3] - 2026-08-29

### 修复

- **申请单临床诊断精简为仅名称（+疑似?）**：新增 `emr_diag_names()`
  函数，仅输出诊断名称（+疑似?），不含编码/部位/备注。

---

## [4.5.2] - 2026-08-29

### 修复

- **申请单页眉临床诊断不再显示 ICD10 编码**：`emr_diag_text` 增加
  `$withCode` 参数（默认 true 不影响病历等既有调用），申请单页眉传
  false 仅显示 部位/名称（备注）疑似?。

---

## [4.5.1] - 2026-08-29

### 新增

- **申请单页眉新增「临床诊断」第三行**（检验/检查/处置/处方统一）：取就诊
  结构化病历诊断（旧镜像表 initial_diagnosis 兜底）；过长自动换行，换行后
  从「临床诊断：」后悬挂缩进对齐（.print-diag，padding-left + text-indent）。

---

## [4.5.0] - 2026-08-29

### 变更

- **申请单打印优化**：
  - 检验/检查申请单不涉及数量，表格仅显示 序号 / 项目名称 / 单价
    （移除数量/金额列，项目名称列加宽）；
  - 处置申请单保留 数量/单价/金额（含数量）；
  - 处方单样式保持不变（后续单独完善）。

---

## [4.4.0] - 2026-08-28

### 新增

- **药品规格结构化（规格管理升级，分步实施）**：
  - **Step 1 · 数据库修复与数据填充**：drugs 表修复 v3 迁移损坏状态
    （user_version=3 但结构化列缺失），schema 升至 v4 幂等补列
    （spec_dose / spec_dose_unit / spec_pack_qty / spec_pack_unit /
    single_use_qty）；新增 `tools/refill_drug_spec.php` 将现有 17 种药品
    的规格文本（0.5g×24粒 / 250ml×1瓶 / 8万U×10支）解析为结构化字段，
    单次剂量（2粒 / 按说明书）解析为单次数量（默认 1）。
  - **Step 2 · 药品管理表单升级**：药物规格改为只读可点击输入框，点击
    弹出二级模态框（规格编辑器）结构化编辑——单剂量值 + 单位、包装数量 +
    单位；保存后回显组合串（0.5g×24粒）；单次使用剂量改为「单次使用数量」
    数字输入（默认 1，placeholder 提示如「1（1粒/1袋）」）；单位输入改为
    datalist 组合框（同检验编辑「计量单位」：历史已用单位下拉 + 可直接
    输入）；保存接口接收结构化字段并落库。
  - **Step 3 · 处方剂量优化**：目录接口返回结构化规格字段；已开药品的
    「剂量」改为只读可点击按钮，点击弹出迷你悬浮窗——剂量输入框 + 固定
    单位（不可改）+ 快速选择（0.25/0.5/1/1.5/2/3/4/5，单位粒/袋/片），
    点击快速值立即应用；手动输入按「剂量/单剂量值」向上取整自动调整数量
    （110ml 生理盐水自动 2 袋、不足 1 按 1、超 1 不满 2 按 2）；
    子医嘱同样支持剂量悬浮窗、规格显示、数量可调并计费。
  - **Step 4 · 后端数量不足强制校验**：提交时按剂量计算所需数量（主药与
    子医嘱统一），数量低于所需直接拦截并提示「xxx 药品数量不足，请修改」；
    单次剂量展示串（1g / 110ml）落库 order_items.single_dose。

---

## [4.3.3] - 2026-08-28

### 新增

- **处方已开列表优化**：
  - 频次/途径改为下拉选择（选项取自管理员设置 drug_settings，含兜底），
    默认回显药品设置值；
  - 药品名后显示规格；
  - 子医嘱：药品名后显示规格、剂量可改（默认管理员设置）、数量可增减，
    且**独立计费**（updateTotal 与后端 total/groupTotal 均计入子医嘱）；
  - 子医嘱提交带真实 item_id/价格/数量，后端按药品权威价计费并扣库存；
  - 子医嘱单个时分支符号修正为 └（原先单个误显示 ┌）。
- **药品搜索下拉第二行顺序**：分类 / 规格 / 频次 / 途径 / 库存。

---

## [4.3.2] - 2026-08-28

### 变更

- **药品搜索下拉优化**：第一行药品名后显示厂商；第二行恢复显示
  频次/途径（主药搜索）；子医嘱搜索下拉不显示频次/途径（继承主医嘱，
  且面板较短）。

---

## [4.3.1] - 2026-08-28

### 修复

- **处方搜索下拉报错**：`rxItemHtml` 中 `escHtml` 未定义（应为
  `Clinic.escHtml`），点击开处方搜索栏报 ReferenceError，已修复。

---

## [4.3.0] - 2026-08-28

### 新增

- **处方开单模态框重构**：
  - 布局改为左侧搜索横条（焦点弹出药品下拉）+ 右侧流程模块；
  - 搜索栏输入即筛选，点击药品后搜索栏清空、收起下拉、加入已选列表；
  - 下拉条目显示药品名/金额/厂商/分类/规格/库存（不含频次/途径）；
  - 子医嘱改为跟随鼠标的内联搜索下拉（复用顶部搜索与数据源），点击即
    追加，无需另开模态框；
  - 删除原全局「护士站执行」勾选与路由自动勾选逻辑，改为每药品独立
    「护士」切换（默认取管理员设置，医生可自由修改）；
  - 后端 `order_write` 处方逐项 `need_nurse` 落库。

### 变更

- **移除 `order.js` 中废弃的 `addSub` 模态框子医嘱选择**（已替换为内联
  搜索下拉）。

---

## [4.2.5] - 2026-08-28

### 修复

- **患者就诊历史按时间排序**：就诊历史（患者就诊历史弹窗、护士站/收费处
  按患者查询）由 `ORDER BY id DESC` 改为 `ORDER BY register_time DESC, id
  DESC`。原按 id 排在实际时间不随 id 单调（补挂号/改时间）时会乱序；
  现在无论待就诊/就诊中/诊毕/退号均按就诊时间如实展示，最新在上。

---

## [4.2.4] - 2026-08-28

### 修复

- **开单模态框固定高度内部布局**：高度由 560px 提至 640px；三层布局
  （左目录/中已选/右流程）填满 body（order-flex height:100%），目录与
  已选列表各自内部滚动（flex:1 + min-height:0），body 不再整体滚动、
  内容不再显示不全。

---

## [4.2.3] - 2026-08-28

### 变更

- **开检验模态框筛选徽章精简**：移除「全部」，仅保留「单个 / 组合」两个
  徽章，默认「单个」。

---

## [4.2.2] - 2026-08-28

### 新增

- **开单模态框固定高度**：新增 `.modal.order-modal` 固定 560px 高度，
  避免筛选（全部/单个/组合）或内容增减导致弹窗忽高忽低。
- **检验目录显示全部单项**：单个视图包含所有独立检验项目（含被组合包含的
  组内成员，如白细胞计数 WBC 等 35 项），均可单独开具；与所属组合的互斥
  关系自动检测（前端已有关联冲突校验 lab_group_members）。

---

## [4.2.1] - 2026-08-28

### 新增

- **开检验模态框：目录筛选徽章**：搜索栏与项目列表之间新增「全部 / 单个 /
  组合」筛选徽章（复用 qp-chip 胶囊样式），单个仅显示单项检验、组合仅显示
  检验组合，与搜索关键字叠加生效（搜索结果同样适用）。

---

## [4.2.0] - 2026-08-28

### 新增

- **处置开单：护士站处置改为逐项设置**：
  - 处置项目新增「需护士站处置」管理设置（disposal_items.need_nurse，
    v2 迁移，管理端处置表单/列表支持勾选与显示）；
  - 开处置模态框移除「全局护士站处置」勾选，改为已选列表中**逐项勾选**
    （每项名称右侧紧凑显示「护士」复选框），默认取管理员设置的
    need_nurse，医生可自由修改——勾选后缴费完护士站显示待执行，取消
    则不显示；
  - 后端按单项 need_nurse 落库（order_items.need_nurse），处方仍按原
    全局「护士站执行」逻辑（给药途径自动勾选）不变。

---

## [4.1.14] - 2026-08-28

### 变更

- **输入框 / 下拉框取消固定 42px 高度**：移除 `.input, .select { height: 42px }`
  全局规则，输入框/下拉框改由 padding 决定高度（更紧凑，与按钮高度更协调），
  开处置等模态框中的数量输入框不再显得过高。

---

## [4.1.13] - 2026-08-28

### 变更

- **知情同意书打印：签字告知部分加粗**：签名区上方「患者/委托人已知晓……
  配合治疗。」告知文字加粗显示（font-weight:700）。

---

## [4.1.12] - 2026-08-28

### 修复

- **知情同意书模板选择悬浮窗定位**：右侧分区「＋」入口 `emrNavAdd('consent')`
  漏传鼠标事件，导致悬浮窗不跟随鼠标、错误锚定在病历节点的「＋」附近；
  改为 `emrNavAdd('consent',event)`，弹窗跟随鼠标显示（与诊断入口一致）。

---

## [4.1.11] - 2026-08-28

### 新增

- **知情同意书查看模态框加入删除按钮**：查看已保存的知情同意书时，脚部
  新增「删除」按钮（仅本人创建可见，btn-danger 样式），点击确认后删除
  并关闭模态框、刷新列表。`get` 接口补充返回 `doctor_id` 用于权限判断。

---

## [4.1.10] - 2026-08-28

### 修复

- **知情同意书列表排序反转**：列表查询改为 `ORDER BY id ASC`，新建的
  显示在下方，与诊断/检查/检验/处置/处方保持一致。
- **打印病历项目排序反转**：`print_record.php` 中已开项目（检查/检验/
  处置/处方）改为 `ORDER BY id ASC`，新开的显示在下方，与屏幕 EMR
  一致。

---

## [4.1.9] - 2026-08-27

### 变更

- **知情同意书打印：正文连续流式分页，签名留在正文底部最后一页**：
  - 撤回「按固定字数/标点切块」方案；正文按段落原样输出（保留换行，
    `white-space:pre-wrap`），作为连续文本流，文字自然换行；
  - 分页器新增「可拆分文本流」（print-split）：节点放不下时在剩余高度内
    按自然换行位置二分查找切断点，剩余文字自动续到下一页，不再硬切断词、
    不再「一个标点一行」、也不再整段跳页；
  - 签名区（虚线告知 + 双列签名，print-foot-sec）作为正文最底部整体块：
    正文先整页填满，签名直接追加到末页底部，不预留、不重排、不额外加页；
    仅当末页正文已满（签名放不下）时该页标记 a5-overflow 自动扩展，
    非末页不留空白、签名不落单；
  - 签名两列均靠左，横线与文字底对齐（flex 弹性下划线）；
  - 页脚精简为一行提示语（一式两份），每页重复。

---

## [4.1.8] - 2026-08-27

### 修复

- **知情同意书正文按块拆分（根治按段落分页/第二页巨长）**：
  - 原按换行分段导致超长段落成为单个超高节点，分页器无法拆开 →
    a5-overflow 溢出、第二页无限加长；
  - 现 `consent_chunk_lines()` 按换行分段后再按 ~40 字拆小块，每块
    为独立小节点，分页器按块流式填充页面，文字填满第一页后再跨页接续
    （不再整段跳页、不再无限加长）；
  - 签名区两列均 `text-align:left`（覆盖 `print-rec-sign` 的右对齐），
    无分隔线、纵向排列；签名作为正文最后内容落在最后一页；
  - 页脚精简为一行提示语，保证版心大、分页正常。

---

## [4.1.7] - 2026-08-27

### 变更

- **知情同意书打印重构（参考电子病历分页）**：
  - 页脚精简为一行提示语（一式两份），不再把告知+签名塞入页脚——
    页脚高度大幅降低，`availH` 增大，避免 `a5-overflow` 溢出导致
    第二页巨长；
  - 告知提示 + 双列签名改为正文尾部内容（`print-rec-sign`，与电子病历
    签名同款——不沉底，留在正文流，多页时随内容分配到末页）；
  - 签名区两列（左：患者/委托人签名+时间；右：医生签名+时间），均
    靠左对齐、纵向排列、无分隔线；
  - 主诉/初步诊断（病情介绍）仍仅首页；正文按行分节点跨页接续。

---

## [4.1.6] - 2026-08-27

### 变更

- **知情同意书打印再优化**：
  1. 主诉/初步诊断仅第一页显示（移出页眉改为正文首段，不跨页重复）；
  2. 签名区去掉中间分隔线，左右内容均靠左、纵向排列；
  3. 页脚去掉记录/打印时间，改为提示语「本知情同意书一式两份，
     一份交患者/委托人保管，一份由科室存档」；
  4. 页眉精简（不再含病情介绍），`availH` 增大，正文按行流式填满页面
     后再分页（不再整段分隔留白）；
  5. 修复分页：精简页眉避免 `a5-overflow` 溢出导致后续页巨长。

---

## [4.1.5] - 2026-08-27

### 变更

- **知情同意书五项优化**：
  1. 侧边栏列表去掉打印按钮，仅保留删除按钮（打印在模态框内）；
  2. 模态框查看态输入框 `disabled`（名称+内容均不可点击），编辑态恢复；
  3. 打印去掉现病史，病情介绍仅主诉+初步诊断，初步诊断与「请仔细阅读
     以下内容」之间加虚线分隔；
  4. 打印正文按行拆分为独立节点，A5 自动分页（头/尾每页重复、正文跨页
     接续），不再无限加长；
  5. 签名区中间虚拟竖线分隔左右两栏：左侧患者/委托人签名+签名时间、
    右侧医生签名+签名时间（均纵向排列）。

---

## [4.1.4] - 2026-08-27

### 变更

- **知情同意书模态框查看/编辑双态 + 保存后自动打印预览**：
  - 新建：内容可编辑（保存按钮）；
  - 查看已保存：内容默认只读，脚部 取消/✏️编辑/🖨️打印；
  - 点击编辑 → 内容可编辑、打印按钮消失（保存按钮出现）；
  - 保存成功 → 自动弹出打印预览 → 关闭模态框 → 刷新列表。

---

## [4.1.3] - 2026-08-27

### 新增

- **知情同意书删除功能**：侧边栏列表为本人创建的条目显示 🗑️ 删除按钮
  （`list` 返回 `doctor_id`，仅本人可见）；后端 `delete` 动作校验
  `doctor_id` 归属 + 归档锁定，仅本人可删。

---

## [4.1.2] - 2026-08-27

### 变更

- **知情同意书打印模板重构（急诊抬头 + 两段正文 + 分页）**：
  - 抬头：医院名称 + 第二名称 + XX知情同意书 + 患者信息（姓名/性别/
    出生日期/年龄 + 就诊科室/患者ID/就诊时间，急诊两行流式）；
  - 正文分两段：① 病情介绍（主诉/现病史/初步诊断）→ 「请仔细阅读
    以下内容：」→ ② 知情同意正文（唯一可变区）；
  - 底部：虚线分隔的笼统告知提示（不限定内容）→ 双列签名（患者/委托人
    签名+时间、医生签名+时间自动生成）→ 页脚（记录时间/打印时间）；
  - 分页：头部（抬头+病情介绍+请仔细阅读）与底部（告知+签名+页脚）在
    `headRe`/`footSet` 中，每页重复显示，正文跨页接续（print.js 新增
    `print-head-sec` 页眉类）。

---

## [4.1.1] - 2026-08-27

### 修复

- **知情同意书标题不可更改**：名称输入框只读；后端保存时——新建由
  `template_id` 从模板推导标题（模板的 name + 知情同意书），编辑仅更新
  内容保留原标题，前端无法指定/篡改标题。

---

## [4.1.0] - 2026-08-27

### 变更

- **知情同意书模板功能完成**（4.0.17~4.0.25 并入）：
  - 模板管理页开放「知情同意书模板」标签（管理员/医生共用同一套代码），
    新建/编辑表单为「知情同意书名称（XX）+ 知情同意内容」；
  - consent 模板内容 `{ name, content }`，创建逻辑与病历模板一致——全院/
    科室需审核、管理员/个人免审、提交后个人可用、驳回回退个人；
  - 审核中心模板预览支持 consent（名称+正文）；
  - EMR 页「知情同意书 ＋」复用模板选择框（type=consent）→ 专属编辑
    模态框（可再编辑）→ 保存 `/api/consent` → 侧边栏列表渲染（点击可
    再编辑、🖨️ 打印）；
  - A5 打印模板：医院抬头 + XX知情同意书标题 + 患者信息 + 知情内容 +
    医生/患者双签名栏 + 页脚（记录时间/打印时间）。

---

## [4.0.25] - 2026-08-27

### 新增

- **审核中心知情同意书模板预览**：模板类型为 consent 时，预览显示
  「知情同意书名称（XX）+ 知情同意内容」文本（病历模板仍用 emrEditor
  只读渲染）。

---

## [4.0.24] - 2026-08-27

### 新增

- **知情同意书侧边栏条目可再编辑**：点击已保存条目加载内容打开编辑
  模态框（`edit`），保存时按 id 更新；打印按钮保留。

---

## [4.0.23] - 2026-08-27

### 新增

- **知情同意书 A5 打印模板**：`print/print_consent.php` 的 `pt_consent()`
  ——医院名称抬头 + 「XX知情同意书」大标题（标题取模板/保存的名称）、
  患者信息、知情内容正文区、下方左右两栏（医生签名 + 患者/代理人签名）、
  页脚（左下记录时间=首次保存、右下打印时间=当前）；`print.php` 新增
  `consent` 动作。

---

## [4.0.22] - 2026-08-27

### 新增

- **知情同意书模块 `emr_consent.js`**：
  - EMR 页「知情同意书 ＋」打开模板选择框（复用病历模板选择框，
    type=consent，搜索词/空态文案不同）；
  - 选择模板后打开专属编辑模态框（知情名称 + 正文可编辑），保存到
    `/api/consent`；
  - 侧边栏「知情同意书」分区渲染已保存列表，含 🖨️ 打印按钮；
  - emrNavAdd('consent') 接线，loadData 成功后自动渲染列表。

---

## [4.0.21] - 2026-08-27

### 重构

- **模板选择框参数化（复用基础）**：`openTemplatePicker(ev, opts)` 支持
  `type`（medical_record/consent）、`pickPlaceholder`、`emptyText`、
  `onApply` 回调；默认保持病历模板行为，为知情同意书选择框复用做准备。

---

## [4.0.20] - 2026-08-27

### 新增

- **知情同意书接口 `consent.php`**：save（新建/编辑，含归档锁定与可访问
  天数校验）、list（就诊知情同意书列表）、get（单条详情）；角色限定医生。

---

## [4.0.19] - 2026-08-27

### 新增

- **模板管理页知情同意书模板表单**：切换「知情同意书模板」标签后，新建/
  编辑模板表单显示「知情同意书名称（XX）+ 知情同意内容」输入框（病历模板
  仍用结构化 EMR 编辑器），保存按类型提交 `{ name, content }`。

---

## [4.0.18] - 2026-08-27

### 新增

- **template.php 支持知情同意书模板内容**：`consent` 类型模板存
  `{ name: XX, content: 正文 }`（不走病历模板的 EMR 字段剥离）；审核
  通知文案按类型区分「知情同意书模板/病历模板」。

---

## [4.0.17] - 2026-08-27

### 新增

- **知情同意书模板功能（开始搭建）**：
  - `consents` 表（medical 库 v9 迁移）：每次就诊可开具多份知情同意书，
    存 title（如「手术知情同意书」）、content（正文）、医生、时间；
  - 模板管理页开放「知情同意书模板」标签（原为预留禁用）。

---

## [4.0.16] - 2026-08-27

### 修复

- **超期病历隐藏顶栏打印按钮**：`loadData` 加载失败时隐藏整个
  `.emr-top-actions .btn`（保存/诊毕/转科/打印）。打印按钮无 `emr-write`
  类，此前未隐藏。
- **明确两个打印入口的区分**：
  - EMR 页打印（`printRecord`）→ 前端守卫（按钮隐藏 + `isRecordComplete`
    在无患者数据时拦截），超期病历无法从 EMR 页打印；
  - 历史就诊记录面板打印（直接调 `/api/print?action=record`）→ 后端
    `print.php` 无时间限制，任何历史病历均可打印，不受 `queue_days` 影响。

---

## [4.0.15] - 2026-08-27

### 修复

- **超期病历（无患者数据）侧边栏空态完善**：
  - `loadData` 加载失败时调用 `renderLeftNavEmpty()`——各分区显示
    「暂无病历文书/暂无诊断/暂无检查…」，隐藏所有「＋」添加入口，
    展开箭头 `margin-left:auto` 靠右对齐；
  - `emrNavAdd` 增加 `DATA` 空守卫（未加载患者信息时提示并拦截，
    不再抛 `__pending_initial` / `record` 空指针错误）；
  - `renderLeftNav` 保留无数据守卫（转 `renderLeftNavEmpty`）。

---

## [4.0.14] - 2026-08-27

### 修复

- **超期病历提示页尺寸与医生工作站欢迎页一致**：加载失败时直接替换
  `#emrCard` 为与欢迎页同款的 `.card.wb-empty` 空态（同结构、同置于
  `.emr-main-editor-scroll` 内、复用同一 CSS 的 `height:100%`），而非
  嵌套卡片，保证高度与右侧侧边栏一致。

---

## [4.0.13] - 2026-08-27

### 修复

- **超期病历加载失败显示友好提示**：`loadData` 增加 `onError` 处理——
  病历加载失败（如超期历史病历被拦截）时，编辑器区域显示「🔒 无法
  加载病历」+ 提示文案 + 「打开候诊列表」按钮，替代原本停留的
  「病历编辑器加载中…」，并隐藏保存按钮（只读态）。

---

## [4.0.12] - 2026-08-27

### 修复

- **病历可访问天数覆盖所有就诊状态**：`visit_access_allowed` 移除活跃就诊
  （paid/visiting）豁免，所有就诊（含待就诊/就诊中）均须在医生
  `queue_days` 天数内。门诊挂号一次管 N 天，过期后即使未诊毕也不可见。

---

## [4.0.11] - 2026-08-27

### 修复

- **病历可访问天数后端拦截（防越权访问超期历史病历）**：新增
  `visit_access_allowed()`——管理员放行；待就诊/就诊中（活跃就诊）始终
  放行（接诊/续诊不受历史天数限制）；已诊毕（归档历史）须在医生
  `queue_days`（2-7，默认 3）可查看天数内。应用于 `record.php`
  get/save/create_progress/save_vitals/save_diags/save_diag_order。
  通过直接链接/API 访问超期历史病历返回拦截提示；历史只读面板
  （print.php）不受影响。

---

## [4.0.10] - 2026-08-27

### 新增

- **病历模板选择弹窗增加适用范围筛选徽章**：搜索栏上方新增
  「全部 / 全院 / 科室 / 个人」四个互斥徽章（复用队列面板 chip 样式），
  只能单选；按模板适用范围（待审核模板按「个人」计）过滤，与搜索
  关键字叠加生效。

---

## [4.0.9] - 2026-08-27

### 新增

- **轻量事件总线 `eventbus.js`**（`Clinic.eventBus`：on/off/emit 发布订阅）：
  跨模块通信解耦通道——发消息方无需知道谁关心，收消息方按需订阅。
- **跨模块通信改造（演示）**：`emr_template.js` 应用模板后不再直接调用
  `_ctx.renderLeftNav()`，改为 `emit('emr:dataChanged')`；emr.js 核心在
  `init()` 订阅该事件并刷新左侧大纲 + 他人文书只读段。模板模块与大纲
  模块完全解耦，新增「数据变更」的其它订阅方无需改动发消息方。

---

## [4.0.8] - 2026-08-27

### 重构

- **emr.js 结构化拆分（步骤 6）**：`_ctx` 补充 vitalDisplayText，提取只读段
  模块到 `emr_segments.js`（`Clinic.emr.segments`）——`roSegmentHtml`、
  `splitOthers`、`refreshReadOnlyBodies`、`injectPrevDiagContext`。
  emr.js 内改为本地别名，内部调用与公共 API 不变；emr.js 已由 3205 行
  减至约 2626 行。

---

## [4.0.7] - 2026-08-27

### 重构

- **emr.js 结构化拆分（步骤 5）**：`_ctx` 补充 myDoctorId，提取病历正文
  开单展示模块到 `emr_orders.js`（`Clinic.emr.orders`）——只读段纯文本
  `orderTextsFor`、活跃编辑器交互标签 `itemToken`/`renderDocOrders`。
  emr.js 内改为本地别名，内部调用与公共 API 不变。emr.js 已由 3205 行
  减至约 2756 行。

---

## [4.0.6] - 2026-08-27

### 重构

- **emr.js 结构化拆分（步骤 4）**：提取患者信息模块到 `emr_patient.js`
  （`Clinic.emr.patient`）——顶部横条信息卡 `renderPatientCard`、
  病历内患者信息网格 `patientGridHtml`、资料保存局部刷新
  `refreshPatientHead`。emr.js 内改为本地别名，内部调用与公共 API 不变。

---

## [4.0.5] - 2026-08-27

### 重构

- **emr.js 结构化拆分（步骤 3）**：`_ctx` 补充 navDotCls/navDotText/clampPop，
  提取总费用悬浮明细模块到 `emr_fee.js`（`Clinic.emr.fee`，含自身
  `feePopTimer` 定时器），emr.js 内改为本地别名，内部调用与公共 API 不变。

---

## [4.0.4] - 2026-08-27

### 修复

- **修复共享上下文 `_ctx` 未定义**：`Clinic.emr._ctx = {...}` 原写在
  `Clinic.emr = (function(){...})()` IIFE 内部，此时 `Clinic.emr` 尚未
  赋值（IIFE 返回值未落地），导致 TypeError。现改为 IIFE 局部变量
  `var _ctx`，并在 return 对象中暴露 `_ctx: _ctx`，子模块经
  `Clinic.emr._ctx` 正常访问。

---

## [4.0.3] - 2026-08-27

### 重构

- **emr.js 结构化拆分（步骤 2）**：建立共享上下文 `Clinic.emr._ctx`
  （accessor 同步模块级状态 DATA/EMR_DIRTY/ORDERS + 暴露内部函数），
  提取病历模板选择与应用模块到 `emr_template.js`
  （`Clinic.emr.template`），emr.js 内改为本地别名，内部调用与公共 API
  不变。加载顺序调整为 emr.js → emr_format.js → emr_template.js
  （emr.js 整体赋值 Clinic.emr，须先加载保证 format/template 挂载）。

---

## [4.0.2] - 2026-08-27

### 重构

- **emr.js 结构化拆分（步骤 1）**：提取 7 个格式化纯函数（主诉/现病史/
  既往史/过敏史/主要症状/体格检查/诊断列表）到新文件
  `emr_format.js`（`Clinic.emr.format`），emr.js 内改为本地别名调用，
  内部引用不变；layout.php 加载 emr_format.js（在 emr.js 前）。
  备份 emr.js/emreditor.js（.bak）供回退。

---

## [4.0.1] - 2026-08-27

### 修复

- **修复病历节点切换闪烁**：`switchToRecord` 原给整个 `#emrCard` 加
  `emr-card-enter` 淡入动画（opacity 0→1），切换病历节点时卡片内容先
  消失再淡入（视觉闪烁），再延迟 350ms 滚动。现移除该整体淡入动画
  （同步清理死 CSS），恢复渲染后立即平滑滚动到锚点，无闪烁。

---

## [4.0.0] - 2026-08-27

### 变更

- **门诊一体化系统整体复盘与优化完成**（主版本号提升至 4.0.0）。
  涵盖 Phase 1 逻辑漏洞修复 9 项、Phase 2 代码冗余整理 5 项、
  Phase 3 大文件拆分 6 项、Phase 4 整体收尾与全量自测。
  详见下方各版本条目。

---

## [3.4.0] - 2026-08-27

### 变更

- **Phase 3 大文件拆分完成**（3.3.1~3.3.6 并入）：
  - `record.php`（971→parts/）、`order.php`（610→parts/）、
    `doctor.php`（469→parts/）、`cashier.php`（508→parts/）；
  - `print_templates.php`（619→print/ 按单据类型）；
  - `components.css`（1051→拆出 components-emr.css 437 行）。
  - 均沿用 admin parts 模式 + awk 原样提取，逻辑零改动，接口自测通过。
- **emr.js（3205 行）本次暂缓拆分**：紧耦合单 IIFE、200+ 处交叉引用，
  拆分需全量重写共享上下文，风险与收益不成正比，保留单文件确保核心
  病历功能零风险。

---

## [3.3.6] - 2026-08-27

### 重构

- **components.css 拆分**：1051 行组件样式拆出 EMR 相关部分（病历只读/
  患者信息头/病历文档/条形码/续写承接头，437 行）到新文件
  `components-emr.css`，components.css 精简到 618 行；layout.php 两个
  位置加载新文件，样式零改动。

---

## [3.3.5] - 2026-08-27

### 重构

- **print_templates.php 拆分**：619 行统一打印模板按单据类型拆分到
  `app/includes/print/` 子目录——`print_common`（公共 helper）、
  `print_receipt`（挂号/缴费凭条）、`print_order`（申请单/处方/处置）、
  `print_report`（检验/检查报告）、`print_record`（电子病历）、
  `print_cert`（诊断证明）。print_templates.php 改为加载器，保持对所有
  调用方兼容。awk 原样提取函数体，逻辑零改动。

---

## [3.3.4] - 2026-08-27

### 重构

- **cashier.php 拆分**：508 行挂号收费接口拆分到
  `parts/cashier_read.php` 与 `cashier_write.php`，编号规则共享函数
  （next_patient_no/next_flow_no/next_visit_seq/dept_used_count）保留在
  调度器。awk 原样提取，逻辑零改动。

---

## [3.3.3] - 2026-08-27

### 重构

- **doctor.php 拆分**：469 行医生工作站接口拆分到
  `parts/doctor_read.php`（读取类动作）与 `doctor_write.php`（写入类
  动作），共享函数 `doctor_dept_ids`/`dept_is_limited` 保留在调度器。
  awk 原样提取，逻辑零改动。

---

## [3.3.2] - 2026-08-27

### 重构

- **order.php 拆分**：610 行开单接口拆分到
  `app/api/parts/order_read.php`（catalog/prev_items/print/visit_orders）
  与 `order_write.php`（submit/delete）。awk 原样提取，逻辑零改动。

---

## [3.3.1] - 2026-08-27

### 重构

- **record.php 拆分**：971 行电子病历接口按功能拆分到
  `app/api/parts/`（沿用 admin parts 模式）——`record_read`（get）、
  `record_write`（create_progress/save/save_vitals/save_diag_order/
  save_diags）、`record_delete`（delete_record）、`record_cert`
  （certificate/certificate_print/check_previous_diagnoses）。
  record.php 保留公共引导、`cert_snapshot_summary`/`emr_order_snapshot`
  共享函数与动作分发。代码经 awk 原样提取，逻辑零改动。

---

## [3.3.0] - 2026-08-27

### 变更

- **Phase 2 代码冗余整理完成**（3.2.2~3.2.6 并入）：treeToggle 去重、
  JS HTML 转义统一 `Clinic.escHtml`、PHP 徽章统一 `badge_html`、审核提交
  统一 `submit_audit`、列表外壳统一 `render_list_wrapper`。详见下方各补丁。

---

## [3.2.6] - 2026-08-27

### 重构

- **列表外壳统一**：`helpers.php` 新增 `render_list_wrapper()`（计数行 + 空态
  /表格外壳，支持可选计数 id），`admin_dept/admin_disp/admin_user/admin_item/
  admin_drug/admin_call` 六处列表渲染改为统一调用；审核中心列表因含分组
  视图保留原样。

---

## [3.2.5] - 2026-08-27

### 重构

- **审核提交统一**：`helpers.php` 新增 `submit_audit()` 统一审计记录 INSERT
  （支持可选 `data`/`creation_source` 列），`admin_item/admin_drug/admin_disp/
  template/pharmacy/lab/imaging` 共 14 处审核提交改为统一调用；`auth.php`
  的密码重置/资料修改因 proposer 为目标用户（无登录场景）保留原样。

---

## [3.2.4] - 2026-08-27

### 重构

- **PHP 状态徽章统一**：`helpers.php` 新增 `badge_html($cls, $text)`，
  `admin_item/admin_disp/admin_drug/admin_dept/admin_user/pharmacy`
  六处重复的徽章 HTML（span + e()）改为统一调用。

---

## [3.2.3] - 2026-08-27

### 重构

- **JS HTML 转义统一**：`ajax.js` 新增全局 `Clinic.escHtml`（单实现），
  `historypanel/queuepanel/emr/emreditor/chart/print` 六个组件的私有
  转义函数统一改为 `Clinic.escHtml` 别名，消除重复实现（且统一转义
  `'` 单引号，补齐个别旧实现缺失的字符）。

---

## [3.2.2] - 2026-08-27

### 重构

- **treeToggle 去重**：`depttree.js` 删除私有 `treeToggle`，统一复用
  `app.js` 的全局 `window.treeToggle`（逻辑等价）。

---

## [3.2.1] - 2026-08-27

### 修复

- **修复开单 500 报错**：`emr_merge_defaults`/`emr_normalize`/`emr_default_data`
  原定义于 `record.php` 内部，`order.php` 开单完整性校验调用时函数不存在，
  导致 500。现将其移入共享模块 `app/includes/emr_formatter.php`，供
  `record.php`、`order.php`、`seed_demo_data.php` 共用（seed 的本地副本
  已移除），消除重复定义。

---

## [3.2.0] - 2026-08-27

### 变更

- **Phase 1 逻辑漏洞修复完成**（3.1.1~3.1.9 全部并入本版本）：
  归档病历全链路锁定、开单价格服务端权威核价、存储型 XSS 修复、开单
  病历完整性实际内容校验、库存扣减原子化、科室数据隔离、过敏史/既往史
  全局同步保护、登录限速/锁定、诊断证明权限校验。详见下方各补丁条目。

---

## [3.1.9] - 2026-08-27

### 修复

- **诊断证明权限校验**：`certificate` 开具诊断证明仅限接诊过该患者的
  医生（或管理员），防止任意医生为任意患者开具法律文书。

---

## [3.1.8] - 2026-08-27

### 新增

- **登录限速/锁定**：`users` 表新增 `login_fail_count`/`login_locked_until`
  字段（schema v6 迁移）。连续密码错误 5 次锁定 15 分钟，锁定期间提示剩余
  等待时间；登录成功重置计数，防暴力破解密码。

---

## [3.1.7] - 2026-08-27

### 修复

- **过敏史/既往史全局同步保护**：`record.php save` 同步患者全局既往史/
  过敏史时，阳性（承认）信息优先保留——本次文书为「否认」但全局已有
  「承认」详情时不再覆盖，避免多医生接诊时后写的否认覆盖先确认的
  过敏/病史。

---

## [3.1.6] - 2026-08-27

### 修复

- **科室数据隔离**：非挂号科室的医生不能查看/接诊当前就诊。新增
  `visit_dept_authorized()` 统一判断——管理员、已诊毕归档（历史查看）、
  就诊科室在医生科室范围内、或医生已在本就诊写过病历（临床连续性）均
  放行；应用于 `record.php get/save`、`doctor.php take`。患者历史就诊
  （既往病历）查询不受限。

---

## [3.1.5] - 2026-08-27

### 修复

- **库存扣减原子化**：`order.php` 处方开单减库存由「先判后扣」改为原子
  条件更新 `UPDATE drugs SET qty=qty-? WHERE id=? AND qty>=?`，并检查
  影响行数，避免并发开单时 TOCTOU 竞态导致库存变负。

---

## [3.1.4] - 2026-08-27

### 修复

- **开单病历完整性校验改为实际内容校验**：`order.php submit` 原仅校验
  就诊下存在任意病历记录（不限医生/不限内容），空骨架草稿即可通过。
  现改为校验**当前开单医生本人**已保存病历的实际必填内容——首诊校验
  主诉+现病史+初步诊断；续写校验续写内容+初步诊断，与前端
  `isRecordComplete` 一致。

---

## [3.1.3] - 2026-08-27

### 修复

- **修复存储型 XSS**：`emr.js` `showItemDetail` / `viewOrderFlow` 渲染
  `item_name` 未转义，恶意项目名可执行脚本。现统一 `escHtml()` 转义；
  后端 `order.php` 同步改为存储权威项目名（处方取 `drugs.name`、检验/检查/
  处置取对应表 `name`），从源头防篡改。

---

## [3.1.2] - 2026-08-27

### 修复

- **开单价格改为服务端权威核价**：`order.php submit` 不再信任前端提交的
  `price`。药品取 `drugs.price`（含子药）、检验/检查取 `lab_items/exam_items`
  的 `price`、处置取 `disposal_items.fee`，杜绝医生篡改金额（0 元开单 /
  高价开单）。

---

## [3.1.1] - 2026-08-27

### 修复

- **归档病历全链路锁定**：已诊毕（归档）病历后端补上 `finished` 状态拦截
  ——`save`（保存病历）、`save_vitals`（生命体征）、`save_diags`（诊断）、
  `create_progress`（新建续写）均拒绝操作，与前端的只读拦截保持一致，
  杜绝通过直接请求修改归档病历。

---

## [3.1.0] - 2026-08-27

### 变更

- **病历模板审核移入审核中心**：医生提交的科室/全院病历模板（`pending_review`）
  现在会在【审核中心】生成一条待审核记录，管理员统一在审核中心处理
  （通过 → 发布全院/科室可用；驳回 → 通知创建人并降级为个人模板）。
  模板管理页不再提供内联「通过/驳回」按钮，仅保留状态展示与「去审核中心
  审核」跳转入口。

### 新增

- **审核中心「预览」功能**：所有经模态框表单提交的审核事项，操作列新增
  「预览」按钮——点击后复用原提交模态框，以只读方式展示全部提交内容：
  - 病历模板 → 复用模板编辑模态框（emrEditor 只读渲染模板正文）；
  - 检验/检查项目 → 复用 `item_form` 表单；
  - 药品 → 复用 `drug_form` 表单；
  - 处置项目 → 复用 `disposal_form` 表单；
  - 药品设置 → 复用设置项只读展示（名称/需护士站/绑定处置）。
  - 预览模态框内所有输入框、下拉、按钮、可编辑区均为只读不可点击，
    但滚动不受影响；模态框脚部替换为「🔒 只读预览」提示。
- 无模态框表单的事项（报告撤回、密码重置、个人资料修改）预览按钮置灰
  （`disabled`），保持各行按钮布局一致。

### 修复

- **模板列表不显示创建者本人的待审核模板**：医生新建科室/全院模板后，
  列表 API（`template.php list`）因仅查询 `status='published'`，导致
  本人也看不到。现补充 `(status='pending_review' AND creator_id=?)` 条件，
  待审核模板对创建者本人可见可用（审核通过前仅自己能使用）。
- **审核中心驳回按钮颜色不醒目**：改为 `btn-danger`（红色高亮）。
- **审核中心操作列宽度不足**：`<th>` 与 `<td>` 分别设置 `min-width:200px`
  和 `white-space:nowrap`，确保预览+通过+驳回三按钮同行显示。
- **模板预览模态框样式不一致**：审核页未加载 `.tpl-form` 的左右分栏 CSS，
  预览模板时变为上下排列。现已在 review.php 补充该样式，预览与编辑版式一致。
- **模板提交审核未通知管理员**：`template.php` save 创建/更新待审核记录后，
  新增 `send_msg('admin')` 站内消息提醒管理员前往审核中心处理。
- **审核中心预览/通过/驳回三按钮换行**：根因是「预览」按钮直接置于 `<td>`，
  而「通过/驳回」包在 `<div class="flex">` 内，块级 div 导致换行（与列宽无关）。
  现将三按钮统一放入同一 flex 容器，并恢复操作列原始宽度（移除 min-width）。
- **待审核模板的适用范围展示**：
  - 模板管理页：待审核模板范围徽章显示目标范围（全院/科室），并附注
    「（待审核·暂仅个人可用）」；
  - 病历模板选择弹出框：待审核模板审核通过前范围显示为「个人」（仅创建者
    本人可用），审核通过后才显示「全院/科室」，排序亦按个人权重排列。
- **待审核模板锁定编辑/删除**：提交审核的模板（`pending_review`）不允许
  编辑/删除，前端隐藏按钮（管理员显示「去审核中心审核」、创建者显示
  「待审核·不可编辑」），后端 `save`/`delete` 接口同步拦截；审核通过
  或驳回后恢复可编辑/删除。
- **病历模板选择弹出框不展示科室明细**：范围仅显示「全院 / 科室 / 个人」，
  不再拼接适用科室名单（避免科室过多时挤爆列表）。
- **审核中心预览模态框禁止右键与复制**：预览模态框内禁止右键菜单
  （contextmenu）、禁止复制/剪切/粘贴（copy/cut/paste 事件）、禁止
  Ctrl/Cmd+C/X/V/A 快捷键、`user-select: none` 禁止文本选中，
  防止审核人员无意中泄露患者数据。
- **病历节点切换动画**：点击左侧病历节点（含续写）时，先让目标病历
  内容渐显出现（淡入 + 轻微上移，0.32s），再平滑滚动到对应锚点，
  替代原先的直接跳转。
- **移除病历节点定位的边框高亮闪烁**：去掉定位后的 `emr-seg-flash`
  外圈边框高亮动画（视觉过重），仅保留内容渐显 + 平滑滚动。
- **修复候诊面板「仅当日」误显示已诊毕患者**：过滤逻辑仅按日期过滤
  未排除 `finished` 状态，导致只勾选「当日」时显示当日全部患者（含
  已诊毕）。现修正为当日未诊毕（`date=t && status!=='finished'`），
  并同步更新四种组合的注释说明。
- **「首诊/续写编辑中」节点可点击定位**：左侧病历节点区的「首诊编辑
  中…（未保存）」「续写编辑中…（未保存）」占位节点现在可点击，点击
  后平滑滚动到对应编辑器锚点（`scrollToPendingEditor`，暴露为
  `Clinic.emr.scrollToPendingEditor`）。
- **编辑中病历节点可删除（未完成）**：左侧「首诊编辑中…」「续写
  编辑中…（未保存）」节点新增医生姓名与删除图标，可删除未完成的
  首诊/续写病历（无需校验脏数据/必填项）；但已添加诊断或已开单的
  禁止删除（避免无主诊断/开单失去病历归属）。
- **修复开单报「请先接诊该患者后再开单」**：从病历页左上角候诊列表
  打开患者时仅跳转 URL、未调用接诊（`take`），就诊状态停留在 `paid`，
  后端 `order.php submit` 校验 `status!=='visiting'` 便拦截开单。
  现 `loadData` 打开患者时若状态为 `paid` 自动调用 `take` 标记为
  `visiting`，首诊/续写均可正常开单。
- **删除病历增加开单锁定**：后端 `delete_record` 校验该就诊已开单
  （非取消/退费）时禁止删除病历，防止开单失去病历归属。
- **就诊判定改为以病历为准**：打开病历页不再自动标记就诊（移除
  loadData 自动调用 `take`）。改为——保存病历（首次）时就诊状态由
  `paid` 置为 `visiting`（视为接诊）；删除该就诊最后一条病历后退回
  `paid`（未就诊，便于退号）。仅打开查看、未保存病历不计为就诊。
- **修复续写病历自动代入诊断**：新建续写时 `base.diagnoses` 清空，
  续写是完全独立的文书（除既往史/过敏史这类不变量外），需要什么
  诊断由医生手动添加，不再自动带入前序诊断。
- **修复候诊按钮人数与列表不一致**：按钮原仅统计 `paid`（待就诊），
  而列表显示「未诊毕」（含就诊中），导致如「列表5人、按钮0」错位。
  现按钮人数 = 当前筛选组合下的列表条数（与展开列表一致），标签仍为
  「候诊」。
- **修复费用悬浮窗挂号费状态**：挂号费行原固定显示「已缴费」，诊毕后
  仍为红色已缴费。现随就诊状态——诊毕时挂号费显示「已完成」（绿点），
  未诊毕显示「已缴费」。
- **移除主诊断保护，支持删除首诊诊断**：原「主诊断不可删除」保护导致
  首诊病历陷入悖论——删除病历需先清空诊断，但主诊断不可删，首诊病历
  永远无法删除。现移除保护：
  - 前端：主诊断行新增 🗑️ 删除按钮；`delDiag` 允许删除最后一个诊断；
  - 后端：`save_diags` 移除主诊断保护校验，允许空诊断列表（删除全部
    诊断后主诊断自动置空）；空 `$clean` 时 `$firstCode`/`$diagText` 为空。
  - 删除主诊断后第二位自动递补成为新主诊断，无则主诊断置空。
- **修复续写病历删除被「已开单」误拦截**：`cancelPendingRecord` 原校验
  全局 `ORDERS`（整次就诊所有开单），首诊开过单导致续写编辑中病历无法
  删除——但未保存的续写(record_id=0)根本不可能有自己的开单。现移除该
  全局校验，仅保留当前节点自身的诊断校验；后端 `delete_record` 亦移除
  按就诊维度的开单校验（订单表无 `record_id`，无法归属到具体病历节点）。
  每个病历节点保持完全独立，互不影响。
- **续写创建入口防御性清空诊断**：`createProgressEditor`（无本人文书
  时新建续写）同样清空诊断，确保任何入口的新续写都是干净的全新病历。
- **诊断编辑悬浮窗新增删除按钮**：点击病历中已添加的诊断后弹出的部位
  编辑框中新增 🗑️ 删除按钮（红色，仅已添加且符合删除逻辑时显示），
  删除同步右侧诊断列表并与侧边栏 `delDiag` 使用同一 `saveDiags` 逻辑。
- **已保存病历删除增加诊断锁定**：`deleteRecord`（前端+后端）校验该
  病历节点存在诊断时禁止删除，需先删除该病历内的全部诊断后再删除病历
  ——与未保存病历（`cancelPendingRecord`）的拦截规则一致，消除「编辑中
  不能删、保存后反而能删」的不一致。
- **修复首诊模板创建病历缺少锚点条幅**：模板应用创建首诊编辑器时，
  原因 `emptyInitial` 状态抑制了 `contHeadWrap` 灰色条幅（记录医生/记录
  时间/首诊徽标），导致：① 记录时间条幅不显示（保存刷新后才出现）；
  ② 无锚点导致点击「首诊编辑中」无法滚动定位。现 `applyTemplate` 渲染
  首诊编辑器后调用 `fillContHead` 补齐条幅，`fillContHead` 支持首诊
  （badge-gray 首诊、无分割线）与续写（badge-primary 病历续写、有分割线）
  两种版式；保存成功后统一刷新条幅时间（记录时间固定为首次保存时间，
  未保存时显示创建时刻）。
- **修复删除续写后进入「幽灵续写」状态**：`cancelPendingRecord` 删除
  编辑中的续写后，原逻辑把 `DATA.record` 置为 `record_type='progress',
  record_id=0` 的幽灵状态，导致保存提示「请填写病历续写内容」、切换首诊
  节点提示「请先完善保存必填项」。现改为：删除续写后回到最近一条已保存
  病历（`records_history` 最后一条），不留下任何未保存的续写状态；首诊
  编辑中删除则回到空病历占位。病历节点之间完全独立、互不牵扯。

---

## [3.0.21] - 2026-08-26

### 变更

- **病历模板选择改为「搜索栏 + 短列表」悬浮框**：锚定在右侧
  「病历节点 +」按钮下方（相当于点击 + 号），支持输入搜索过滤、
  点击外部/Esc 关闭；选择模板后按其内容创建首张电子病历
  （套用到编辑器，可修改后保存）。
- **修复「通用模板不可编辑」误提示**：`get` 接口新增 `for_apply=1`
  用途——应用模板/创建病历场景允许读取系统通用模板，编辑模板
  （默认）仍保留系统/归属越权拦截。
- 空白病历患者（无保存病历）进入编辑页自动唤起模板选择悬浮框；
- 首诊空病历患者进入时**不渲染空白电子病历编辑器**（显示「首张
  电子病历尚未创建」占位），选择模板后才渲染并填充创建第一张
  电子病历（`applyTemplate` 在占位态先渲染编辑器再套用模板）。
- **模板选择悬浮框自动弹出延迟调整为 150ms**：原 300ms `setTimeout`
  偏慢、0ms 偏快，采用 150ms 折中，兼顾页面渲染完成与响应感。
- **添加初步诊断增加前置校验（前后端双重拦截）**：首诊文书须先
  完善并填写主诉与现病史后才允许添加诊断；续写文书须先填写病历
  续写内容（无主诉/现病史要求）。首张电子病历尚未创建（空患者）
  时右侧「初步诊断 +」不再能弹出诊断选择，需先选模板完善必填
  后再添加；接口直调同样拦截（`save_diags` 校验已保存文书的
  主诉/现病史/续写内容）。

### 修复

- **修复病历节点「+」在首诊编辑中误弹模板选择**：模板已应用、首诊
  编辑器渲染中（未保存）时点击「病历节点 +」，原逻辑因
  `records_history` 为空再次弹出模板选择框；现改为沿用续写逻辑拦截
  ——有未保存修改先提示保存、未完善/未保存提示完善主诉/现病史/初步
  诊断并保存，确保首诊落库后才允许进入续写。
- **修复病历保存后右侧病历节点缺科室名**：首诊/续写首次保存时前端
  本地构造的 `records_history` 条目缺少 `dept_name`，导致病历节点显示
  「日期 时间（首）」无科室，需刷新页面后才正确；现保存回写时从
  `DATA.visit.dept_name` 补齐科室名，保存后立即显示完整。
- **修复病历模板选择悬浮框不跟随点击按钮定位**：占位区「📋 选择
  病历模板」按钮未传入点击事件，导致悬浮框固定锚定在右侧
  「病历节点 +」按钮下方；现按钮改为 `openTemplates(event)` 传入
  鼠标坐标，与「病历节点 +」一致，弹窗显示在点击处附近（自动
  弹出场景仍锚定 + 号下方，不受影响）。
- **修复候诊列表打开空患者不自动弹出模板选择**：`refId` 取自隐藏
  输入框的字符串值，无 `ref` 参数时为 `"0"`（JS 中为真值），
  导致 `!refId` 为 `false` 跳过自动弹出。现改为 `parseInt` 转整数，
  空患者点击候诊条目标准进入 → 自动弹出模板选择（与页内「病历
  节点 +」首诊自动弹出体验一致）。

---

## [3.0.20] - 2026-08-26

### 变更

- **新版医生工作站（病历工作台）科室选择优化**：
  - 进入时加载医生关联科室：单科室自动进入；多科室检查
    登录会话内记忆的科室（`sessionStorage` 绑定账号+会话 ID，
    退出重登自动失效），未选择则弹出科室选择；
  - 未选择科室时工作台显示「🩺 请先选择科室后开始接诊」+
    「选择科室」按钮（背景仍显示候诊按钮，点开提示先选科室）；
  - 选定科室后候诊列表按所选科室加载并自动弹出开始接诊，
    科室选择仅保存在本次登录会话，与旧版工作站交互一致。

### 文档

- 工作台科室选择改为仅保存在本次登录会话（`sessionStorage` 绑定
  账号+PHP会话 ID，退出重登自动失效），不再持久化到服务器；
  `queuePanel` 新增 `setDept(id)` 按所选科室加载候诊（未选科室时
  按钮显示「候诊 -」、面板提示先选科室）；未选科室时背景仍显示
  候诊按钮但不拉取任何患者数据；
- 选定科室后自动弹出候诊列表；`queuePanel.init` 自动恢复本次登录
  会话记忆的科室（layout.php 为 body 注入 `data-sid`），病历编辑页
  进入时候诊不再显示「-」。

---

## [3.0.19] - 2026-08-26

### 变更

- **诊断管理页布局与交互优化**：
  - 搜索框移到标题下方（参考处置项目页），页头仅保留标题与「新增诊断」；
  - 新增诊断按钮恢复正常大小（不再与搜索框并排被撑高）；
  - 诊断列表默认按**诊断码升序**排序（A 前 B 后、数字从小到大）；
  - 「加载更多」按钮改为**滚动到底自动加载**（无限滚动，监听 `.content`
    滚动容器，内容不足一屏时自动补齐）——同时消除表格下方空白区域；
  - 全部加载后显示「已全部加载（N 条）」。

---

## [3.0.18] - 2026-08-26

### 修复

- **诊断管理列表只显示 50 条**：`icd10 list` 接口默认 `limit=50` 且无分页，
  扩充后的 868 条诊断只能看到最新 50 条，误以为未写入成功。
  - 后端：`list` 支持 `offset` 分页，返回 `total` 总数（按 id 升序，
    追加加载不重复）；
  - 前端：诊断管理页默认加载 50 条，显示「共 N 条，已显示 M 条」，
    「加载更多」按钮追加下一页；搜索/编辑/删除后自动重置分页。

---

## [3.0.17] - 2026-08-26

### 变更

- **模板管理页布局调整**：
  - 搜索框移到工具条左侧，范围 Tab（全部/个人/全院/科室）紧跟其右侧，
    与其他管理页面（如科室管理）一致；
  - 模板类型选择（病历模板/知情同意书/嘱托）从搜索栏区域移出，改为
    页头右上角下拉选择，与「新建模板」按钮并排，避免与范围 Tab 混淆。

---

## [3.0.16] - 2026-08-26

### 新增

- **模板列表范围子 Tab**：在类型 Tab（病历模板/知情同意书/嘱托）下方
  新增「全部 / 个人 / 全院 / 科室」范围子 Tab，前端按 `TPL_SCOPE`
  过滤显示，列表计数与搜索同步联动。

### 修复

- **模板编辑弹窗恢复「模板正文」标题**：此前误删整个标题，仅需去掉
  括号内说明文字而非标题本身，已恢复「📝 模板正文」标题。

### 确认

- **科室范围 vs 全院范围逻辑**：现有实现已满足——
  · 科室范围：通过 `emr_template_depts` 关联表静态匹配，新增科室
    不自动纳入；
  · 全院范围：直接匹配 `scope='hospital'`，新增科室自动包含。
  无需额外改动。

---

## [3.0.15] - 2026-08-26

### 变更

- **新建/编辑模板弹窗改为左右布局**：
  - 左侧：模板名称、适用范围（选择「科室」时下方展开科室三级树）；
  - 右侧：模板正文（复用 emreditor 模板模式，去掉多余说明文字，
    只显示模板正文）。
- **科室三级树组件化**：新增 `depttree.js` 组件（`Clinic.deptTree`），
  封装全院/门诊/急诊分组、全选/分组联动、搜索定位、折叠展开，
  样式与添加/编辑医生时的科室树一致，供模板弹窗等复用，方便维护。
- **模板保存必填校验**：主诉（主要症状）与现病史（具体内容）为必填项，
  未填写不允许保存模板。

### 修复

- **模板列表排序**：原 `ORDER BY scope ASC, id DESC` 导致按适用范围字母序
  排而非创建时间。改为 `ORDER BY is_system DESC, id DESC`——系统模板置顶，
  其余按创建时间倒序（新创建的显示在上）。

---

## [3.0.14] - 2026-08-26

### 修复

- **续写编辑中再次点击"+"无反应**：正在新建续写（`__pending_progress`）时，
  再次点击病历节点"+"因无占位锚点而静默无反应。现增加提示——
  "当前续写病历尚未保存，请先完善必填项并点击「💾 保存」后再续写"。

---

## [3.0.13] - 2026-08-26

### 修复

- **恢复续写中占位节点**：点击续写后右侧病历节点列表应显示
  「📝 续写编辑中…（未保存）」占位提示。此前 `createProgressEditor`
  （他人文书占位续写场景）缺少该占位；且占位节点依赖手动追加，
  30 秒轮询 `renderLeftNav` 重建列表时会被覆盖丢失。
- 修复方案：新增 `DATA.__pending_progress` 标志，`renderLeftNav` 渲染
  病历节点列表时自动追加占位节点；本人续写（`addProgressEditor`）与
  他人文书占位续写（`createProgressEditor`）均设置该标志；
  保存成功/reload 后清除。

---

## [3.0.12] - 2026-08-26

### 变更

- **医生工作站空白区与右侧大纲栏等高对齐**：空白工作台卡片填满编辑区
  高度（`.wb-empty`），无患者时与右侧病历节点大纲栏视觉等高，布局更协调。
- **页面标题改名**：病历书写页 `<title>` 由「电子病历」改为「医生工作站」，
  与菜单命名一致（病历文档内的「门诊/急诊电子病历」打印标题保持不变）。

---

## [3.0.11] - 2026-08-26

### 新增

- **全新医生工作站（病历书写工作台）**：左侧菜单原「医生工作站」改为
  「旧工作站」（保留原有患者列表/加号等功能），新增「医生工作站」
  指向 `/doctor/emr` 病历书写页面：
  - 无患者时病历区域显示空白工作台（🩺 提示"请从左侧候诊列表选择患者
    开始就诊"）；
  - 首次进入自动弹出左上角候诊列表，医生可直接选患者进入病历书写。

### 变更

- **诊毕后关闭已诊毕病历页**：诊毕保存成功后不再停留在已诊毕患者的
  病历页，自动跳转到无参 `/doctor/emr` 空白工作台（并自动弹出候诊列表），
  无缝接诊下一位患者。

### 文档

- 恢复医生侧边栏被误删的「首页」(/doctor/home) 菜单项，旧工作站图标
  改为 🖥️ 避免混淆。

---

## [3.0.10] - 2026-08-26

### 修复

- **诊毕后病历节点点击不滚动**：诊毕只读分支无 `#contHeadWrap`/`#myRecordAnchor`
  锚点，`scrollToRecord` 对诊毕场景统一滚动到对应 `recSeg{id}`（只读段）。
- **诊毕后诊断删除按钮误显示**：诊毕时 `renderLeftNav` 诊断聚合不显示删除按钮
  （已归档，诊断不可增删改），`diagEditable` 的 toast 拦截保留作兜底。
- **诊毕后病历节点删除按钮误显示且可删除（严重）**：
  - 后端 `delete_record` 新增诊毕锁定——`status === 'finished'` 时拒绝
    删除，返回「该患者已诊毕，病历已归档，不可删除」；
  - 前端 navRecords 渲染诊毕时隐藏删除按钮。

### 变更

- **诊毕后自动弹出候诊列表**：诊毕保存成功后不再跳转医生工作站页面，
  改为自动弹出左上角「📋 候诊」面板，医生可直接选择下一位患者，
  提高看诊效率（回退 `window.location.href` 兜底）。
- `queuepanel.js` 暴露 `open()` 方法供诊毕回调调用。

---

## [3.0.9] - 2026-08-26

### 新增

- **病历节点删除与生命周期约束**：
  - 后端 `delete_record` 接口（record.php）：
    · 身份越权拦截——仅记录创建者本人可删除，否则返回
      「无权删除非本人创建的病历记录」；
    · 首诊锁定——该就诊下已存在已保存的续写病程时，删除首诊返回
      「该病历已存在后续病程记录，不可删除首诊病历」；
    · 续写节点本人创建的允许独立删除，不影响首诊及其余续写；
    · 删除时同步清理旧 records 镜像（按 patient_record_id 精确匹配）。
  - 前端病历节点删除按钮渲染控制：
    · 非本人节点不显示删除按钮；
    · 本人首诊且有已保存续写 → 锁定不显示（「已有续写病程，首诊已锁定」）；
    · 本人首诊无续写 / 本人续写 → 正常显示删除按钮。
  - 删除确认弹窗与联动：
    · 首诊删除成功 → 刷新页面，records_history 为空时自动触发
      「病历节点 +」模板选择，无缝引导重新选择模板开启首诊；
    · 续写删除成功 → 刷新页面重建病历树，初始定位回显上一有效可编辑文书。

---

## [3.0.8] - 2026-08-26

### 修复

- **诊断删除逻辑跟随病历走**：
  - 删除按钮仅显示在当前编辑病历中存在的诊断上（`inCurrent` 标记）；
    其他病历（首诊/续写/他人）创建的诊断不显示删除按钮；
  - 其他病历中存在、当前病历也存在（引用/手动添加）的诊断可删除，
    但**仅删除当前病历中的该诊断**，其他病历的诊断保持不变；
  - `delDiag` 强制触发非当前病历诊断时直接拦截（不响应）。
- **后端 save_diags 支持 `edit_record_id`**：切换回旧文书编辑时，诊断
  增删改精确定位到该文书（此前只更新最新一条本人文书，导致在旧续写
  里删诊断会误改最新文书）；主诊断保护/诊断列表非空校验保留
  （病历必须有诊断的底层原则）。

---

## [3.0.7] - 2026-08-26

### 修复

- **诊断归属与引用逻辑全面修复**：此前诊断归属按「是否在当前编辑文书」
  判断而非「书写者」，导致一系列"自己引用自己"的错乱：
  1. `saveDiags` 同步本地缓存时按 doctor_id 更新了本人**所有文书**的
     诊断列表，污染旧续写 → 新增诊断也被误标【引用】标记；
  2. 诊断聚合按是否当前文书标记 mine/others，本人旧续写诊断被误标为
     others（他人）→ 显示引用标记、调整顺序/设主诊断被自动引用；
  3. 调整顺序用 `!keep[row.key]`（不在当前文书=他人）判定 → 本人旧续写
     诊断调整也被自动复制引用。
- 修复方案：
  - `saveDiags` 按 record_id 精确匹配，仅更新当前编辑文书；
  - 聚合按**书写者 doctor_id** 判断归属：本人旧文书=本人，他人文书=他人；
  - 新增 `ownOld` 标记（诊断是否存在于本人旧文书），引用标记判定改为
    `others && !ownOld`——本人诊断（无论多少条文书）永不显示【引用】；
  - 调整顺序/设主诊断按来源医生 `srcId` 判定，仅他人诊断才自动引用，
    本人诊断调整只更新排序不复制。

---

## [3.0.6] - 2026-08-26

### 修复

- **候诊面板夜间模式未适配**：候诊队列面板（queuepanel 相关样式）使用了
  `var(--card)`/`var(--bd)`，而深色主题定义的是 `--bg-card`/`--border`，
  导致夜间模式下面板背景/边框不生效。已统一替换为正确的 CSS 变量
  （全站排查，其余组件变量均已匹配深色主题）。

### 变更

- **右侧初步诊断聚合修复**：此前 `renderLeftNav` 聚合诊断时跳过当前登录
  医生本人全部文书（`if (h.doctor_id === mineId) return`），导致同一病人
  多个续写病历各有诊断时，右侧仅显示当前节点的诊断。现改为聚合**全部文书**
  诊断（含本人首诊/各续写，与看待其他医生一致）——当前编辑文书的诊断
  标为本人（可删改），其余文书（含本人旧续写）的诊断标为引用显示来源，
  该引用引用、该显示显示。

---

## [3.0.5] - 2026-08-26

### 变更

- **候诊切换患者脏数据提示优化**：当前病历有未保存修改时，点击候诊
  列表切换患者不再触发系统 Alert 弹窗，改为 toast 提醒并拒绝跳转
  （"当前病历有未保存的修改，请先点击「💾 保存」后再切换患者"），
  保持使用一体性。
- **病历节点切换滚动优化**：点击右侧病历节点时，滚动定位与变为
  可编辑状态同步完成——取消 200ms 延迟（先闪可编辑再滚动的割裂感），
  重渲染完成即立即滚动到对应文书锚点。初始加载保留 200ms 延迟
  （等待异步开单/体征刷新稳定定位）。

---

## [3.0.4] - 2026-08-26

### 修复

- **病历节点切换排列错乱**：`splitOthers` 此前按「最后一个本人文书」定位
  当前编辑文书，切换回首诊/中间续写时，当前文书被错误排到文档最底部，
  只读段与编辑器顺序混乱。现改为按**当前编辑文书 id 精确匹配**——
  切换后只读段/编辑器按其真实时间顺序排列（当前编辑文书位于对应位置，
  其余全部只读段），并正确定位滚动到该文书锚点。

---

## [3.0.3] - 2026-08-26

### 新增

- **病历节点切换（可编辑/只读自由切换）**：点击右侧病历节点——
  - 当前编辑文书 → 滚动到编辑器锚点；
  - 他人文书 → 滚动到对应只读段（始终只读）；
  - 本人旧文书 → 前置校验（当前文书必填已保存且无未保存修改）通过后，
    目标文书恢复为可编辑状态，其余（含原当前文书/他人文书）全部只读段，
    便于回头修改既往续写病历。
- **初始定位**：进入患者病历默认滚动到最后一个本人可编辑文书的锚点。

### 变更

- **后端 save 支持 `edit_record_id`**：精确更新指定本人文书（不再局限于
  最新一条）；`parentRow`/`$pr`/`$old` 均按指定 id 定位。
- **schema v8**：records 镜像表新增 `patient_record_id` 列，保存镜像时
  精确关联对应文书（旧数据按 visit_id+doctor_id 最新回退）。
- 前端保存成功后的 `records_history` 同步改为按 `record_id` 精确匹配
  （编辑旧文书时不误更新最新本人文书）；`addProgressEditor` 清除
  `__edit_record_id` 避免与 `progress_new` 冲突。

---

## [3.0.2] - 2026-08-26

### 新增

- **续写添加诊断前序引用确认**：搜索诊断点击结果时——
  - 当前已选同编码 → 提示"该诊断已存在"（提前拦截）；
  - 前序医生已有同编码诊断 → 弹「引用前序诊断」确认框，确认后直接
    引用（含前序部位/备注/疑似，标注来源医生），不弹部位表单；
  - 全新诊断 → 照常展开部位/备注/是否疑似表单。
  - 引用后点击已引用诊断仍可编辑部位/备注。
- emreditor 新增 `findPrevDiag(code)`（查找前序医生同编码诊断）。

---

## [3.0.1] - 2026-08-26

### 修复

- **右侧病历节点点击定位错误**：此前点击任一本人病历节点都滚动到
  `myRecordAnchor`（位于所有只读段之前=首诊位置），现修复为——
  当前编辑文书滚动到续写条幅 `#contHeadWrap`（编辑态），其余文书
  （本人旧文书/他人文书）滚动到对应只读段 `#recSeg{id}`（只读态）。

---

## [3.0.0] - 2026-08-26

> 大版本：病历模板管理上线 + 病历编辑工作流重构。

### 新增

- **病历模板管理（全新模块）**：
  - 独立 schema `010_emr_templates`：`emr_templates` + `emr_template_depts` 关联表，
    内置 `is_system=1` 的「通用病历模板」（全院可用、不可修改删除）；
  - 接口完整能力：`list`（按范围+关键词，管理员全量，医生可见
    本人个人+已发布全院/科室）、`get`、`save`、`review`、`delete`；
  - 模板内容过滤：后端强制剥离诊断/生命体征/意识状态/既往史/过敏史/
    辅助检查/留观，仅保留主诉/现病史/主要症状/体格检查/处置/嘱托；
  - 审核流：医生创建 personal 免审、dept/hospital 进审核；
    管理员审核通过发布、驳回自动降级为 personal 并消息通知创建人；
  - 模板管理页（管理员/医生共用，Router 双入口）：Tab 分类
    （病历模板 / 知情同意书预留 / 嘱托预留）、列表（名称/范围/创建人/
    审核状态/操作）、新建/编辑弹窗复用 emreditor（`templateMode`
    仅渲染允许节）；
  - 侧边栏「模板管理」菜单（管理员 / 医生端）。

### 变更

- **病历编辑工作流重构（病历节点「+」）**：
  - 首诊（无保存病历）→ 弹模板选择（全院 > 科室 > 个人）；
  - 本人已有文书 → 先校验当前文书必填已保存（首诊=主诉/现病史/诊断；
    续写=续写内容/诊断），满足后 DOM 局部操作将当前文书转为只读段、
    下方新建续写编辑器（不重渲染整页，可无限次续写）；
  - 续写不限次数：保存以 `progress_new` 强制新建独立续写文书
    （链式关联父记录），保存后刷新重建多文书结构；
  - 多文书（首诊+多段续写）只读/编辑正确拼接显示：`splitOthers`
    取最新一条为当前编辑项，其余（含本人旧文书/他人文书）全部只读段；
  - 续写条幅（记录医生/记录时间/续写徽章）与右下角签名改为跟随
    文书动态显示——占位态（无本人文书）不显示，渲染续写编辑器时才出现；
  - 续写条幅记录时间实时显示：点+续写时用当前时间，保存后刷新为
    首次保存时间，之后再保存不变；
  - 右侧病历节点列表点+续写后同步追加「续写编辑中…（未保存）」节点；
  - 点+续写后滚动定位到续写条幅（`#contHeadWrap`）而非首诊病历顶部。

### 修复

- **模板新建/编辑弹窗**：新建不再走 `get?id=0`（避免"模板不存在"报错）；
  科室多选改用医生可访问的 `/api/template?action=depts`（修复医生
  新建模板"无权限访问该功能"）；
- **模板操作权限**：医生仅本人创建模板可编辑/删除，他人模板只读显示；
- **移除遗留入口**：医生站「病历模板」按钮、病历页眉「病历模板」按钮
  及 `bindTemplateBtn`/`openTemplateMgr` 旧实现全部移除（模板入口
  统一收口至「病历节点 +」与侧边栏「模板管理」）。

---

## [2.15.2] - 2026-08-26

### 变更

- **打印预览防复制**：预览层整体（单据正文 + 工具栏）禁用右键菜单、
  文本选择、拖拽与复制（单据含患者隐私），预览层外页面不受影响。

---

## [2.15.1] - 2026-08-26

### 修复

- **就诊历史只读病历无样式**：文档 HTML 未包裹 `.print-area` 容器，
  print.css 全部规则以该作用域编写，导致正文纯文本堆砌无格式。
  现已正确包裹，纸面版式与打印预览一致。

### 变更

- **只读病历区防复制**：禁用右键菜单、文本选择与拖拽
  （仅右侧文档区，左侧列表与操作条不受影响）。

---

## [2.15.0] - 2026-08-26

### 变更

- **患者就诊历史弹窗重构（左右两栏）**：布局参考检验组合管理器——
  - 顶部横条：患者信息（姓名/性别年龄/患者ID/身份证/电话）；
  - 左栏：就诊列表（日期 时间 科室（序号）+ 状态徽章 + 证明标记，
    转科显示 → 现科室），顶部支持按日期/科室搜索；
  - 右栏：选中就诊后展示**只读病历文档**（复用打印版式
    /api/print?action=record，所见即所得纸面观感），
    顶部操作条含「🖨️ 打印电子病历」（A5 病历纸）与
    诊断证明三态按钮（查看/补开/新增，逻辑与原版一致）；
  - 病历未保存的就诊显示占位提示，按钮置灰引导。
- 后端 `patient.php history` 由返回拼装 HTML 改为结构化 JSON
  （patient + visits 标志位）；新增组件 `historypanel.js`
  提供全局 `showPatientHistory`（替换各视图内联定义）。

---

## [2.14.5] - 2026-08-26

### 变更

- **三级树搜索结果改为悬浮层**：搜索结果列表不再推挤下方树结构，
  改为绝对定位悬浮于树区之上（紧贴搜索行下方，实底背景 + 阴影 +
  底部圆角），点击结果后悬浮层消失并定位闪烁树节点。
  容器改为 position:relative（去掉 overflow 裁切），搜索行/树区
  分别随容器顶部/底部圆角。

---

## [2.14.4] - 2026-08-26

### 变更

- **三级树搜索栏与树框视觉融合**：添加/编辑用户的科室选择树与
  发送消息的用户选择树，搜索栏与树区此前为两个独立边框（割裂感），
  现合并为一个整体框——顶部搜索行、搜索结果行、树区共享同一
  边框圆角容器，聚焦时整体高亮（focus-within），搜索结果在框内
  展开而非独立浮层。

---

## [2.14.3] - 2026-08-26

### 修复

- **医技/其他科室混入临床列表**：为叫号大屏添加的检验科/影像科/
  药房/护士站等非临床科室此前混入各处临床科室列表，现已隔离：
  - 添加/编辑医生：所属科室树仅显示临床科室（门诊/急诊），
    后端保存同步剔除医技/其他科室 ID（双层校验）；
  - 科室管理页：类型 Tab 改为「临床（默认）/门诊/急诊/医技/其他」，
    默认仅显示临床科室，医技/其他经独立 Tab 查看管理；
  - 科室表单：新增「医技/其他（叫号大屏专用）」类型选项——
    修复此前编辑医技科室时类型被静默改为门诊的数据损坏问题，
    保存时医技/其他号源强制清零；
  - 运营分析科室筛选、仪表盘「启用科室」统计均改为仅临床科室。
  - 叫号管理大屏科室选择保持不变（需展示医技/其他 Tab）。

---

## [2.14.2] - 2026-08-26

### 变更

- **候诊天数输入即时强制修正**：输入小于 2 的数字（如 1）自动修正
  为 2 并 toast 提示；超过 7（如 8）自动修正为 7 并提示。
  输入即校验（input 事件），保存时校验保留作兜底。

---

## [2.14.1] - 2026-08-26

### 变更

- **管理员用户表单布局优化**：
  - 默认密码与候诊天数合并为一行（form-row，各占一半）；
  - 移除标题中括号说明文字，移除字段下方说明文字；
  - 前端+后端双层校验：留空默认 3，填写必须 2-7（非法值直接拒绝，带提示信息）。

---

## [2.14.0] - 2026-08-26

### 新增

- **医生候诊列表可显示天数可配置**：管理员添加/编辑医生时可设置
  候诊列表可回看天数（2-7 天，默认 3 天，留空按 3 天）。医生病历页
  候诊弹窗按此天数回看患者列表；最低 2 天确保急诊 0 点后仍能看到
  前一天患者，最高 7 天。数据库迁移 v5（users 表新增 queue_days 列）。

---

## [2.13.9] - 2026-08-26

### 变更

- **候诊搜索栏宽度**：180px → 350px（实测更合适）。

---

## [2.13.8] - 2026-08-26

### 变更

- **候诊面板搜索栏移入 chips 右侧**：搜索输入框从 chips 行下方
  移入同一行右侧空白区（宽度 180px），与已诊/当日按钮、人数计数
  共用一行，节省纵向空间且无需单独占位。列表高度限制自动适配。

---

## [2.13.7] - 2026-08-26

### 修复

- **候诊双选组合显示错误**：同时勾选「已诊 + 当日」时旧逻辑为
  「近3天全部诊毕 + 今日全部未诊」的并集，混入隔日患者导致
  组合结果混乱。改为交集——仅显示**今日已诊毕患者**（已诊∩当日），
  最后诊毕在最上，与其他组合语义一致（各选项为叠加约束）。

---

## [2.13.6] - 2026-08-26

### 修复

- **悬浮窗溢出屏幕**：诊断添加浮窗在选中诊断展开确认表单、
  检索结果渲染撑高后未重新定位，病历下方点击时底部溢出视口，
  保存按钮不可见。新增通用 `clampPop` 视口夹紧（兼容 absolute/fixed
  定位），覆盖诊断添加/编辑/排序、诊毕确认、费用悬浮、生命体征
  全部浮窗——内容变化与初始定位后均保证完整显示在页面范围内。

---

## [2.13.5] - 2026-08-26

### 修复

- **病历保存与添加诊断互相死锁**：保存病历要求必须先有初步诊断，
  而添加诊断又要求先保存病历（record_id>0），新就诊两者互斥卡死。
  现首次保存前允许添加/编辑/排序诊断——暂存本地编辑器与缓存，
  随首次保存一并持久化；已保存病历仍为服务端即时持久化。

---

## [2.13.4] - 2026-08-26

### 变更

- **候诊面板固定表头与列表高度限制**：表头行 sticky 固定于列表
  滚动容器顶部，不随患者列表滚动（勾选项/搜索栏本就在滚动区外）；
  列表高度按视口自适应限制（≤46vh 且不溢出屏幕底部），患者再多
  仅在面板内部滚动，不覆盖页面其他区域。

---

## [2.13.3] - 2026-08-26

### 变更

- **候诊列表纵向对齐**：单行条目由自然流式布局改为九列 CSS Grid
  （日期/时间/科室/号别/号源/姓名/性别/年龄/状态），新增表头行，
  各列跨行严格对齐；超长科室名/姓名截断省略并悬停提示完整内容；
  序号/时间列使用等宽数字，面板加宽至 560px 容纳九列。

---

## [2.13.2] - 2026-08-26

### 变更

- **候诊面板体验优化（三项）**：
  - 「候诊」按钮人数跟随「当日」勾选：勾选当日 → 显示当日待就诊
    人数；未勾选 → 显示已有待就诊人数（不限日期）；
  - 已诊/当日 勾选偏好跟随当次登录会话：新增 `queue_pref` 接口
    存 `$_SESSION`，本次登录期间跨页面保持，退出登录自动还原不勾选；
  - 搜索关键字切换已诊/当日勾选时保留（便于跨列表找同一患者），
    面板关闭时自动清空重置。

---

## [2.13.1] - 2026-08-26

### 修复

- **候诊面板空壳与误报「网络请求失败」**：queuepanel.js 误用了
  emr.js IIFE 私有的 escHtml 函数，渲染时 ReferenceError 使面板
  innerHTML 赋值中断（只剩空边框），异常沿 promise 传播被
  Clinic.get 静默吞掉后误报网络错误且控制台无任何报错。
  组件内改为自带私有转义函数；Clinic.get 失败路径改为重抛异常
  （与 Clinic.ajax 一致），此类问题今后可在控制台直接定位。
- 版本号递增至 2.13.1，强制浏览器刷新 queuepanel.js 缓存。

---

## [2.13.0] - 2026-08-26

### 新增

- **病历页候诊队列面板（大型优化）**：医生病历编辑页顶部患者信息
  横条左侧新增「📋 候诊XX」按钮（XX=当前科室今日未就诊人数）：
  - 弹出近3天患者列表面板，含「已诊 / 当日」两个子多选项，任意组合：
    · 仅「已诊」→ 近3天诊毕患者，最后诊毕在最上；
    · 仅「当日」→ 当日挂号患者；
    · 双选 → 近3天诊毕在上 + 今日未诊候诊顺序在下；
    · 全不选 → 近3天未诊患者，最早挂号在最上（候诊顺序）。
  - 多选项下方搜索栏：仅在当前筛选结果范围内匹配（姓名/科室/序号/日期），
    切换多选项自动清空关键字；
  - 患者单行展示：日期 时间 挂号科室（就诊序号）号源（上午/下午/昼夜，
    急诊统一昼夜）姓名 性别 年龄 + 状态徽章；点击直接跳转该患者病历页。

### 变更

- **数据库迁移 v4**：registrations 新增 finish_time 诊毕时间列，
  诊毕时写入，用于「最后诊毕排最上」排序（旧数据回退挂号时间）。
- 新增前端组件 `queuepanel.js`：数据一次拉取本地过滤（30 秒轮询 +
  打开面板强制刷新），多选切换与搜索零请求。

---

## [2.12.17] - 2026-08-26

### 变更

- **叫号管理新建诊室交互优化**：
  - 未选择科室时隐藏「＋ 新建诊室 / 大屏」按钮，选定科室后显示；
  - 移除新建模态框，改为跟随鼠标位置的轻量悬浮窗：科室固定为
    当前科室、大屏类型按科室自动推断（名称含检验/影像/药/护士等
    关键字优先，否则按科室类型 医技→检验、其他→护士站、门诊/急诊→医生诊室），
    仅需输入诊室/窗口名称即可创建；支持 Enter 提交、Esc 或点击外部关闭。

---

## [2.12.16] - 2026-08-26

### 变更

- **科室选择默认定位当前科室 Tab**：非挂号模式打开科室选择弹窗时，
  默认定位到当前已选科室所在的 Tab（如当前选药房 → 默认「其他」，
  选检验科 → 默认「医技」）；未选择科室时默认进入「门诊」Tab。
  挂号模式保持原逻辑（无身份证或锁定 → 急诊，否则门诊）。

---

## [2.12.15] - 2026-08-25

### 修复

- **叫号大屏选择科室网络错误**：deptPicker 新 mode=call 未走同步渲染，
  误走接口分支导致请求 `/admin/undefined`；call 模式与 select 一样
  使用传入 depts 同步渲染，当前科室提示逻辑同步兼容。

---
---

## [2.12.14] - 2026-08-25

### 修复

- **医生回退首个科室过滤医技/其他**：医生当前科室无效时回退关联科室
  亦过滤 tech/other，防止误关联被选中为接诊科室。

---
---

## [2.12.13] - 2026-08-25

### 新增

- **叫号大屏科室选择新增「医技 / 其他」Tab**：
  - departments 新增虚拟科室：检验科 / 影像科（tech）、药房 / 护士站
    （other），仅叫号管理可见；
  - 叫号大屏科室选择弹窗（mode=call）显示 急诊 / 门诊 / 医技 / 其他
    四个 Tab；医生站 / 挂号 / 转科等仍仅急诊 / 门诊（tech/other
    过滤不显示，后端接口同步过滤，防技术手段强入）；
  - 科室管理页对医技 / 其他类型显示对应徽章。

---
---

## [2.12.12] - 2026-08-25

### 变更

- **审核提示按角色动态显示**：管理员（免审核）不再显示各类「新增需
  审核」提示，非管理员保留——
  - 检验 / 检查项目、药品表单底部提示、药品设置新增表单提示、
    通用选择器（检索 / 快捷创建）提示，均按当前角色显隐；
  - 检验 / 检查 / 药品 / 处置管理页标题描述中的「需审核」文字
    同样按角色动态拼接。

---
---

## [2.12.11] - 2026-08-25

### 新增

- **检验组合项目合计徽章**：组合管理「＋ 添加项目」按钮右侧显示
  「项目合计 ¥xxx.xx」徽章——为该组合**所有成员项目价格之和**
  （非组价），随成员增删 / 切换组合实时更新。

---
---

## [2.12.10] - 2026-08-25

### 变更

- **检验组合管理成员表格同步固定表头**：组合成员表格表头吸顶，数据行
  在右侧区域内滚动；取消其外层 table-wrap 限高，避免嵌套双滚动。

---
---

## [2.12.9] - 2026-08-25

### 变更

- **表格内部滚动 + 固定表头**：管理员各页表格在内容超出时仅数据行
  内部滚动，表头吸顶固定、页面不再整体滚动（.table-wrap 限高 +
  thead sticky）。
- **输入控件尺寸优化**：输入框 / 下拉框高度 36→42px；文件选择按钮
  padding 调整为 5px 5px。
- **滚动条美化**：全站滚动条改为细条半透明样式（悬停加深），视觉更
  轻量不突兀。

---
---

## [2.12.8] - 2026-08-25

### 变更

- **管理员侧边栏重新分组**：从一组「管理」拆分为「首页」「医院管理」
  「基础数据」「运营管理」「系统」五个分组，各菜单项归类更清晰；
  原「工作台」统一更名为「首页」。
- **文件选择按钮统一样式**：全站 `<input type="file">` 应用系统主题
  样式（蓝色按钮 + 悬停变深），不再显示浏览器默认样式。
- **新建项目隐藏搜索**：通用选择器「新建项目」时隐藏搜索框与新建按钮，
  返回检索时恢复。

---
---

## [2.12.7] - 2026-08-25

### 变更

- **数据管理下拉按钮**：各管理页（科室 / 用户 / 检验 / 检查 / 药品 /
  处置 / 诊断）的「下载模板 / 导出全部 / 批量导入」三个独立按钮合并为
  一个「📊 数据管理 ▾」下拉菜单，点击展开三个选项。
- **检验管理补充数据管理**：检验项目页补上「数据管理」（模板 / 导出 /
  导入），后端模块已支持；非管理员（检验科等）自动隐藏数据管理与
  组合 / 分类按钮。

---
---

## [2.12.6] - 2026-08-25

### 变更

- **品牌外观 LOGO 上传体验优化**：左侧说明文字 + 右侧 LOGO 展示
  （未上传显示 🏥 占位），点击 LOGO 直接弹出文件选择，选中后自动上传
  并刷新；移除浏览器默认样式的文件选择框与上传按钮。

---
---

## [2.12.5] - 2026-08-25

### 新增

- **夏令时日期快捷选择器**：夏令时开始 / 结束日期新增 📅 快捷选择——
  弹出「月份 + 日期」联动网格（按月份自动显示有效天数），点选填充
  MM-DD，亦可手动输入。

---
---

## [2.12.4] - 2026-08-25

### 新增

- **作息时间快捷选择器**：设置作息 / 夏令时时间时，时间输入框旁新增
  ▾ 快捷选择——弹出 06:00~23:30 每半小时的网格点选填充，亦可手动输入。

---
---

## [2.12.3] - 2026-08-25

### 变更

- **系统设置布局再优化**：HIS 接口独立成卡；移除纯跳转的「账号安全」卡；
  医院信息卡跨两列，其余卡片 2×2 紧凑排列（品牌外观与 HIS、作息时间与
  安全设置互补无空白），窄屏自动单列。

---
---

## [2.12.2] - 2026-08-25

### 变更

- **系统设置页面分区重排**：原三个并排卡片（医院信息 + LOGO + URL密钥 +
  密码混杂）改为五张分区卡片网格：医院信息（含时区与 HIS 接口分组）、
  品牌外观（LOGO）、作息时间、安全设置（URL 混淆密钥）、账号安全
  （修改密码）；HIS 密钥从医院信息中分出「接口设置」小组，URL 密钥
  与密码各自独立成卡，排版整齐统一。

---
---

## [2.12.1] - 2026-08-25

### 变更

- **登录默认落地首页**：各角色登录后进入自己的「首页」而非工作站
  （医生首页不含科室选择，进入工作站时再选择，不影响原流程）。
- **管理员工作台加趋势图**：近 7 天挂号人次 + 缴费金额双线图。
- **各角色首页加快速入口 + 使用提示**：左「快速入口」、右「使用提示」
  （按角色撰写：医生 / 检验 / 影像 / 护士 / 药房 / 收费各自的工作流程提示）。

---
---

## [2.12.0] - 2026-08-25

### 新增

- **各角色个性化首页（工作台总览）**：医生 / 护士 / 检验科 / 影像科 /
  药房 / 收费处侧边栏新增「首页」入口，展示与角色相关的 KPI 卡片与
  近 7 天趋势图 + 快速入口：
  - 医生：今日接诊人次、开单金额（药费 / 检验 / 检查 / 处置分列）、
    今日门诊人次、我的待完成病历；
  - 检验科：今日标本量、检验费用、待登记 / 待出报告、项目总数与
    待审核项目；
  - 影像科：今日检查量、检查费用、待登记 / 待出报告、项目总数与
    待审核项目；
  - 药房：药品总数、今日发药数 / 金额、待发药处方、低库存药品（红色
    高亮）、待审核药品；
  - 护士站：今日处置执行数、待执行处置、处置费用、处置项目总数；
  - 收费处：今日挂号数、挂号费收入、缴费金额、退费金额（红色高亮）、
    待就诊。
- 每个角色首页新增对应 `home_stats` 接口（/api/{role}?action=home_stats），
  趋势图复用 Clinic.chart。

### 变更

- 次版本号提升至 2.12.0（角色门户首页模块）。

---
---

## [2.11.4] - 2026-08-25

### 修复

- **管理员未收到待审核通知**：检验 / 检查 / 药品 / 药品设置提交审核时
  现在会向管理员发送站内消息（链接到审核中心）。
- **审核中心类型名汉化**：新增 drugsetting → 药品设置。

### 变更

- **药品设置新增表单提示**：与检验 / 检查新增一致，标注「新增设置项需
  管理员审核，提交后待审核通过方可使用」。

---
---

## [2.11.3] - 2026-08-25

### 新增

- **药品设置权限管理**：药房新增「药品设置」项走管理员审核
  （drugsetting_save 非管理员创建 audits type=drugsetting，通过后由
  审核中心落库）；列表对非管理员只读（隐藏编辑 / 删除），新增按钮保留
  并提交审核；删除仍仅限管理员。

---
---

## [2.11.2] - 2026-08-25

### 修复

- **药房药品设置页「无权访问」**：只读白名单接口名写错
  （drug_settings_list → 实际为 drugsetting_list），药房加载设置列表
  被拒；已修正，设置新增 / 删除仍仅限管理员。

---
---

## [2.11.1] - 2026-08-25

### 修复

- **检验/影像/药房管理页面访问被拒**：页面路由（Router.php）只允许
  admin 角色，非 admin 点击菜单跳到「无权限访问该页面」；已为
  labitems/examitems/drugs/drugsettings 路由添加对应角色。

---
---

## [2.11.0] - 2026-08-25

### 新增

- **角色权限扩展：检验科/影像科/药房可使用本职管理页面**：
  - 侧边栏新增「检验管理」「检查管理」「药品信息」「药品设置」菜单项
    （lab/imaging/pharmacy 角色可见）；
  - 控件权限：新增 / 修改提交走管理员审核（status=pending + audits 记录），
    **删除、分类管理、组合管理、药品设置新增等操作仅限管理员**；
  - 管理页面列表操作按钮对非管理员隐藏（显示「只读」）；
  - 组合管理模态框对非管理员只读（隐藏编辑/删除/添加项目按钮）；
  - body 新增 `data-role` 属性，前端 JS 统一判断角色。
- **审核流程完善**：item_save / drug_save 对非管理员提交创建审核记录
  （item_lab / item_exam / item_drug 类型），管理员处理驳回后回跳地址
  统一指向管理页面（`/admin/labitems?edit=` 等），不再指向工作台。

### 变更

- **精简工作台按钮**：检验科 / 影像科 / 药房工作台右上角的新增项目 /
  新增药品 / 新增分类按钮移除（由独立管理页面替代）；药房「库存管理」
  tab 保留。
- 次版本号提升至 2.11.0（权限体系扩展 + 角色门户）。

---
---

## [2.10.7] - 2026-08-25

### 修复

- **检查项目管理 500**：item_list 重构时删除了 `$rows` 定义（仅 lab 分支
  需要早期 return，exam 分支仍依赖 `$rows`），补回。

---
---

## [2.10.6] - 2026-08-25

### 新增

- **检验项目计量单位组合框**：新增/编辑检验项目时，计量单位改为
  datalist 组合框——既可下拉选择历史已用单位（去重），也可自由输入；
  尚无任何检验项目（无历史单位）时退化为纯输入框。

---
---

## [2.10.5] - 2026-08-25

### 变更

- **组合管理固定头部与布局优化**：左侧「新增组合 + 搜索框」、右侧
  「添加项目」栏固定不随列表滚动；添加项目悬浮窗搜索框同样固定，
  候选列表独立滚动。
- **组合成员表格化**：右侧成员以表格展示（名称 / 分类 / 价格 / 单位 /
  操作），操作列为文字按钮「编辑 / 移除」靠左显示；表格
  `table-layout: fixed` 固定列宽（34 / 16 / 12 / 10 / 28%），切换组合
  列宽保持稳定，操作列不被截断；名称等长文本超长省略号截断。
- **左侧组合计数实时同步**：添加 / 移除成员成功后立即刷新左侧
  「（x项）」计数（保留选中态），无需点保存。
- **保存组合不再清空成员**：仅显式提交成员列表时重建（信息保存不触碰成员）。

---
---

## [2.10.4] - 2026-08-25

### 修复

- **保存组合清空成员（严重）**：保存组合信息时误清空全部成员
  （lab_group_save 无条件重建成员，saveComboInfo 传空 member_ids）；
  现仅显式提交成员列表时才重建，信息保存不再触碰成员。
- **检验项目多组合归属**：成员关系由单归属 parent_id 改为多对多关联表
  lab_group_members（schema v3 迁移并回填旧数据），一个项目可加入
  多个组合，同一组合内不可重复；医生开单端组合成员查询同步切换。
- **组合管理打开默认不选中**：不再自动选中第一个组合，右侧提示
  「选择一个检验组合」或新建，避免打开时闪跳。

---
---

## [2.10.3] - 2026-08-25

### 修复

- **新增检验组合悬浮窗重复叠加**：每次点击新建一层，未先关闭旧层；
  改为先关闭旧层再创建，且点击外部自动消失。
- **悬浮窗跟随鼠标**：改为在点击处弹出（视口边缘夹紧），不再固定在
  按钮位置。

---
---

## [2.10.2] - 2026-08-25

### 修复

- **分类「全部」按钮导致所有分类高亮**：tab 按钮缺 data-cat 属性，
  全部按钮空值比较时全命中；检验与检查两页 tab 按钮补 data-cat。
- **组合管理悬浮窗被模态框遮挡**：新增组合 / 添加项目悬浮窗 z-index
  低于模态框遮罩（1000-2000），关闭模态框后才可见；提升至 3200。
- **删除组合按钮统一红色**（btn-danger）。

---
---

## [2.10.1] - 2026-08-25

### 修复

- **检验组合管理报「未知操作」**：新增的组合 API（lab_groups / lab_group_get /
  lab_group_candidates / lab_group_add_item / lab_group_remove_item）
  未在 admin.php 注册路由，已补全。
- **检验主列表只显示独立项**：改为展示全部检验项目（`is_group=0` 的所有单项，
  含已加入组合的成员），是否成组与本列表无关。

---
---

## [2.10.0] - 2026-08-25

### 新增

- **检验项目管理重构**：
  - 主列表仅展示全部「独立单项」（不含组合与组内成员），新增分类子标签
    （全部 / 按分类）+ 快速搜索 + 计数联动；
  - 「检验组合管理」改为两列模态框：左侧搜索 + 组合列表 + 「新增检验组合」；
    右侧选中组合的信息编辑（名称 / 价格 / 分类 + 保存 / 删除）、
    「＋ 添加项目」搜索面板、成员列表（点击编辑 / 移除）；
  - 新增组合弹窗（名称查重 / 价格 / 分类），添加项目实时保存；
    后端新增 lab_groups / lab_group_get / lab_group_candidates /
    lab_group_add_item / lab_group_remove_item，lab_group_save 支持
    空组合创建与名称查重，组合删除成员自动还原为独立项目。

### 变更

- 次版本号提升至 2.10.0（检验组合管理模块重构）。

---
---

## [2.9.9] - 2026-08-25

### 变更

- **三级树缩进统一**：L1→L2、L2→L3 文字缩进步进统一为 28px
  （L3 补 22px 抵消行首 +/− 按钮宽度，文字横向对齐忽略按钮）；
  各级间距统一为 4px。

---
---

## [2.9.8] - 2026-08-25

### 变更

- **三级树间距缩紧与层级加深**：L2 组间距由 8px→4px；
  L3 缩进由 20px→34px（三级与二级的横向层级更分明）。

---
---

## [2.9.7] - 2026-08-25

### 修复

- **三级树折叠按钮失效**：+/− 按钮漏绑 onclick，导致无法展开、
  三级变两级的假象；已为发送消息与用户科室树全部按钮补
  `onclick="treeToggle(this)"`。
- **三级项再次横向**：L3 容器改用 .send-grp-children 后未加纵向样式，
  回退为横向；已为其定义 flex 纵向排列（展开时生效）。

---
---

## [2.9.6] - 2026-08-25

### 变更

- **三级树层级修正与折叠**（发送消息选人 / 用户表单科室）：
  - 层级明确：L1 全院 → L2 门诊 / 急诊（或角色组，缩进）→ L3 各科室 / 用户，
    不再与全院同层；
  - 各级右侧新增 **+/−** 折叠按钮，**默认全部折叠**，按需展开；
  - 搜索定位时自动展开命中项的所有折叠祖先并高亮（通用助手扩展）。

---
---

## [2.9.5] - 2026-08-25

### 变更

- **三级树交互优化（发送消息选人 / 用户表单科室）**：
  - 三级项由横向换行改为**纵向排列**（项目多时更清晰）；
  - 树顶部新增**搜索定位框**——输入关键词弹出短结果列表，点击滚动定位
    到树中对应项并高亮闪烁（通用助手 Clinic.treeSearch，两处复用）；
  - 发送消息模态框由 860px 收窄至 400px（纵向列表无需宽幅）。

---
---

## [2.9.4] - 2026-08-25

### 新增

- **科室管理类型子标签**：全部 / 门诊 / 急诊（列表行携带类型标记），
  与快速搜索组合过滤，计数联动（如「门诊科室共 2 个」）。
- **用户表单科室三级树**：所属科室改为三级多选——全院 → 门诊 / 急诊 →
  各科室（样式与发送消息选人一致）；勾选全院自动全选，勾选分组自动
  勾选组内全部，支持半选态；保存逻辑不变（dept_ids 逗号串）。

---
---

## [2.9.3] - 2026-08-25

### 修复

- **科室管理搜索计数不联动**：快速搜索时「共 6 个科室」文字不随筛选
  更新；现搜索显示「科室 N 个」，清空恢复「共 N 个科室」。
- **药品设置表格缺「操作」列抬头**：分类 / 包装 / 剂型 / 频次 / 途径
  各设置表 thead 漏掉 `<th>操作</th>`（拼接时丢失开标签）。
- **CHANGELOG 版本顺序**：2.9.2 小节移至 2.9.1 之上（降序排列）。

---
---

## [2.9.2] - 2026-08-25

### 修复

- **运营统计三个搜索框失效并报「网络请求失败」**：科室 / 医生 / 转归
  搜索框缺少 id，渲染函数 getElementById 取 null 抛错致回调中断；
  已补齐 deptSearch / docSearch / dispSearch。

### 新增

- **运营分析三大统计搜索**：科室统计（按科室名）、医生统计（按工号 /
  姓名 / 职称，列表新增工号列）、转归查询（按患者姓名 / 门诊号 /
  身份证号，接口补充 id_card）——均带动态计数（搜索时去掉「共」）。
- **科室统计类型子标签**：全部 / 门诊 / 急诊（接口补充 dept_type），
  计数文案随类型与搜索联动。
- **检查项目管理**：快速搜索框 + 分类子标签（全部 / CT / MR / DR / 超声…，
  按数据动态生成），计数随筛选联动（如「检查项目（CT）共 3 项」）。
- **用户管理角色子标签**：全部 / 医生 / 护士 / 收费员…（按数据动态生成），
  搜索与计数联动。
- **诊断管理搜索改实时长条**：输入即 300ms 防抖检索，去掉查询按钮。
- **处置项目计数联动**：搜索时显示「处置项目 N 个」（去掉「共」）。

### 变更

- **管理页计数随筛选动态更新**：检验 / 药品 / 用户 / 检查页面的
  「共 X 个/项/种」在搜索或切换子标签时实时刷新；搜索态去掉「共」
  （如「检验项目 5 项（含组合 1 个）」「药品（西药）12 种」）。

---
---

## [2.9.1] - 2026-08-25

### 修复

- **运营分析日期查询无效果（根因修复）**：ana_range 误用 post() 读取
  日期（前端为 GET 参数），导致任何范围都回落到「今天」；改用 req()
  兼容 GET/POST——今日 / 昨日 / 近7天 / 自定义范围现在正确生效。
- **检查项目管理**：新增快速搜索框与分类子标签（全部 / CT / MR / DR /
  超声等，按数据动态生成），列表行携带分类标记。
- **检验演示数据规范化**：肝功能十项 / 肾功能三项 / 电解质五项 /
  凝血功能四项 / 甲状腺功能五项 / 心肌酶谱由独立项目转为**组合**
  （组内含对应单项：如肝功能十项含 ALT/AST/TBIL 等 10 项），
  独立项目仅保留 CRP / PCT / 血糖 / 血型 / 支原体 / 血沉；
  生成器支持 `catalog` 模式（仅重建目录）。

---
---

## [2.9.0] - 2026-08-25

### 新增

- **管理端快速搜索**：科室管理 / 用户管理 / 检验项目（含组合与组内项）/
  药品信息 / 处置项目页面新增快速搜索框，按行内容即时过滤定位。
- **药品信息分类子标签**：按药品分类动态生成「全部 / 西药 / 中成药…」
  子标签，点击即筛选。
- **运营分析快捷范围激活态**：今日 / 昨日 / 近7天等快捷按钮点击后
  高亮显示当前所选范围。

### 修复

- **医生开单目录不再重复列出组合内单项**：检验组合的组内项目不再作为
  独立项目出现在开单目录（组合整体与独立项目并列，组内项目仅随组合开具）。
- **演示账号密码统一 123456**：生成器与现有账号密码全部重置为 123456
  （pwd_changed=0）。

### 变更

- 次版本号提升至 2.9.0（管理端多项交互优化）。

---
---

## [2.8.14] - 2026-08-25

### 新增

- **演示数据生成器**（tools/seed_demo_data.php，仅限测试环境 CLI 执行）：
  基础引导（6 科室 / 14 账号 / 医院设置 / 基础目录，仅空表时执行）+
  目录补充（检验 / 检查 / 处置 / 药品，含皮试药品与皮试项目绑定）+
  48 名患者 + 近 30 天 136 条多状态就诊（今日含待就诊 / 就诊中 / 诊毕）+
  277 份规范结构化病历（含 8+ 份 3-4 人续写）+ 250 张医嘱单 /
  425 条明细 / 169 份报告 / 205 条体征 / 6 份诊断证明 / 五类转归。
- **运营分析「全 0」说明**：统计口径按 payment_time / paid_at 落在所选
  日期范围（默认当天）计算，此前无当日数据所致；生成器已补充当日数据，
  当天默认视图即有数值。

---
---

## [2.8.13] - 2026-08-25

### 修复

- **侧边栏调整诊断顺序未同步病历编辑器**：排序原只写独立存储不动
  病历数据。现调整为他人诊断时**自动引用该一条**并按新顺序并入本人
  病历（编辑器初步诊断同步显示新顺序）；未调整的诊断不引用；
  本人诊断排序原地生效。排序键仍存 diag_orders（跨医生全局顺序）。

---
---

## [2.8.12] - 2026-08-25

### 变更

- **右栏诊断行显示精简**：仅显示 ICD-10 编码、诊断名称，疑似诊断以
  名称尾部「?」标记；不显示部位与备注（编辑悬浮窗内仍可查看 / 修改）。

---
---

## [2.8.11] - 2026-08-25

### 变更

- **诊断与 ICD10 显示顺序统一**：右栏诊断行、诊断添加悬浮窗搜索结果、
  诊断编辑悬浮窗标题三处统一为「ICD10 编码在前、诊断名称在后」
  （编码弱化灰显）。

---
---

## [2.8.10] - 2026-08-25

### 变更

- **删除 / 毁方确认语精简**：去掉括号内的规则说明文字，仅保留
  「确定删除该开单？」「确定毁方该处方？」。

---
---

## [2.8.9] - 2026-08-25

### 修复

- **病历内开单项目补底部虚线**：对齐诊断字段样式（border-bottom 虚线，
  悬浮时虚线同步加深），与诊断字段视觉完全一致。

---
---

## [2.8.8] - 2026-08-25

### 新增

- **开单 / 打印 / 开诊断证明前未保存拦截**：病历有修改未保存（脏标记
  置位）时，点击开单（检查/检验/处置/处方）、打印病历、开具诊断证明
  会 toast 提醒「病历有修改未保存，请先保存后再操作」，防止快照 / 打印
  内容与编辑器不一致。

### 变更

- **病历内开单项目样式统一**：自动插入的项目标签（辅助检查 / 处方 /
  门诊处置）由蓝底胶囊改为与初步诊断一致的样式（青绿色文字、无背景、
  悬浮淡蓝底），视觉更统一；点击详情与只读降级行为不变。

---
---

## [2.8.7] - 2026-08-25

### 变更

- **诊断全局排序去引用化**：排序载体由「本人诊断列表」改为独立的
  diag_orders 存储（visit+医生维度，schema v7 迁移）——上移 / 下移 /
  设为主诊断只写显示顺序键，**不引用、不改动任何人的诊断数据**，
  彻底实现跨医生全局交错排序；主诊断 = 全局首行（点击不弹窗）。
- **点击病历中已添加的诊断改为编辑悬浮窗**：预填部位 / 备注 / 是否疑似，
  保存后即时持久化；不再弹搜索框（添加走字段框后「＋」或右栏「＋」）。

---
---

## [2.8.6] - 2026-08-25

### 变更

- **诊断排序恢复跨医生全局交错排序**：上移 / 下移 / 设为主诊断基于
  聚合列表操作（不区分开单医生）——如本人 135、他人 246 可自由调整为
  123456 交错顺序；调整结果整表持久化到本人文书（他人诊断按其位置以
  引用副本并入，仅作排序载体，他人原始病历不受影响）。
- **移除旧诊断管理模态框**：病历「初步诊断」字段点击改为直接弹出
  跟随鼠标的诊断添加悬浮窗（搜索 → 部位 / 备注 / 疑似 → 保存），
  相关旧代码（openDiagModal / renderSelected / openDiagEdit 等）删除；
  诊断的删除与排序统一在右侧边栏完成。

---
---

## [2.8.5] - 2026-08-25

### 修复

- **诊断排序 / 设主诊断不再自动引用他人诊断**：操作只作用于本人诊断
  列表——本人诊断行按位置显隐「上移 / 下移」并原地调序；他人诊断行
  仅提供「设为主诊断」（单独引用该一条到本人列表首位），不再把全部
  诊断整表并入本人病历。
- **诊断悬浮窗贴紧鼠标**：按面板实际尺寸夹紧视口定位（原用固定常量
  在窄面板 / 屏幕边缘时偏离点击处）。

---
---

## [2.8.4] - 2026-08-25

### 修复

- **病历诊断「＋」无文字**：创建元素时漏设 textContent（只显示空圆圈），
  已补「＋」字符。
- **诊断操作浮窗体验**：宽度收窄为 150px；主诊断点击不再弹浮窗；
  最后一个诊断隐藏「下移」选项。

---
---

## [2.8.3] - 2026-08-25

### 变更

- **主诊断保护（前后端双重）**：右栏主诊断行不再显示删除按钮，
  改为「主诊断」徽标提醒；后端 save_diags 拦截当前主诊断被移除的
  提交（调整顺序允许），需先将其他诊断设为主诊断方可删除原主诊断。
- **病历诊断「＋」位置调整**：由字段框内首个诊断后改为**字段框后侧、
  最后一个诊断之后**（有诊断且可编辑时显示），不再挤占框内空间。

---
---

## [2.8.2] - 2026-08-25

### 修复

- **save_diags 响应 500（数据实际已保存）**：镜像表同步 UPDATE 误用
  不存在的列 primary_icd10（镜像表 ICD 列名为 diagnosis_code），异常
  发生在结构化文书更新之后——数据生效但响应中断。改为先查镜像行 id
  再按 id 更新 initial_diagnosis / diagnosis_code。

---
---

## [2.8.1] - 2026-08-25

### 修复

- **病历「＋」添加诊断 500**：save_diags 案例使用了仅在 save 案例内
  初始化的 $pdo（null）；补 `$pdo = DatabaseManager::pdo('medical')`。
- **诊断行删除按钮未靠右**：移除医生姓名后行内失去右侧推挤元素，
  诊断名 span 改 flex:1 占位使删除按钮贴右。
- **诊断排序操作区分医生**：设为主诊断 / 上移 / 下移改为基于聚合列表
  操作（不区分开单医生）——调整结果整表持久化到本人文书，
  他人诊断自动以引用副本并入；侧边栏聚合顺序本人优先。

---
---

## [2.8.0] - 2026-08-25

### 新增

- **诊断管理悬浮窗化（跟随鼠标）与右栏诊断操作**：
  - 添加诊断改为跟随鼠标的悬浮窗：搜索（名称 / ICD10 / 拼音首字母）→
    选中后填写部位 / 备注 / 是否疑似 → 保存即时持久化
    （新增 record save_diags 接口，服务端校验本人文书 + 未诊毕）；
  - 病历正文首个诊断后显示「＋」快捷入口，右栏「初步诊断」「＋」
    同样弹出该悬浮窗（原模态框保留：点击诊断字段进入完整管理）；
  - 右栏诊断行重构：移除医生姓名，本人诊断可点击弹出操作浮窗
    （⭐ 设为主诊断 / ↑ 上移 / ↓ 下移，每次调整 toast 提醒），
    行内 🗑️ 删除本人诊断，他人诊断只读；
  - 引用诊断专项处理：本人与他人均有同诊断时显示单行并标注「引用」，
    删除时提醒「只删除自己病历中的诊断，无法删除他人已开具的诊断」，
    删除后他人病历与聚合列表中的该诊断不受影响；
  - 编辑器导出 setDiags（服务端持久化后同步显示不置脏标记）。

### 变更

- 次版本号提升至 2.8.0（诊断交互重构：悬浮窗 + 即时持久化 +
  排序 / 主诊断 / 引用删除）。

---
---

## [2.7.6] - 2026-08-25

### 修复

- **打印病历生命体征恒显示**：未录入生命体征时打印/快照不再整节省略，
  统一显示「生命体征：-」（首诊与续写一致）；涉及打印模板 pt_record
  与入库快照 emr_print_text 两处渲染。

---
---

## [2.7.5] - 2026-08-25

### 变更

- **生命体征面板跟随鼠标点击处显示**：面板 fixed 定位锚定鼠标点击坐标
  （视口边缘自动夹紧），替代原先固定在小节右下方。
- **生命体征数值校验（前后端双重）**：所有项目须为非负整数（拒绝小数 /
  负数 / 带单位），并按生理区间校验——收缩压 1-300、舒张压 1-250、
  心率 / 脉搏 1-300、血氧饱和度 1-100、呼吸 1-100；留空视为未测；
  后端 save_vitals 同步硬校验，绕过前端也无法写入非法值。

---
---

## [2.7.4] - 2026-08-25

### 变更

- **生命体征编辑改为悬浮面板**：点击病历「生命体征」小节，在其下方
  弹出悬浮编辑面板（两列紧凑表单：收缩压/舒张压/心率/脉搏/血氧/呼吸），
  替代原模态框；再次点击小节或点面板外部收起，保存后即时刷新显示，
  诊毕只读拦截与保存逻辑保持不变。

---
---

## [2.7.3] - 2026-08-25

### 变更

- **编辑模式统一显示记录医生条幅**：首诊编辑态同样显示灰色署名条幅
  （记录医生 ｜ 记录时间 ｜ 首诊灰徽），与续写编辑态（蓝徽 + 虚线分隔）
  及只读段头逻辑一致；首次保存前未生成记录时间则不显示时间项。

---
---

## [2.7.2] - 2026-08-25

### 修复

- **未选科室时患者列表加载圈圈不消失**：多科室医生关闭科室选择弹窗
  未选择时，列表区停留在初始加载 spinner；现改为显示
  「请先选择科室后开始接诊」引导提示，选定科室后正常加载。

---
---

## [2.7.1] - 2026-08-25

### 修复

- **发送消息投递错位（严重）**：send 校验查询参数绑定顺序颠倒——
  SQL 为 `id<>? AND id IN (...)` 但参数把本人 id 放在末尾，导致
  `id<>收件人 AND id IN (发件人自己)`，消息发给了发件人而非目标用户
  （如管理员发给 2001，2001 未收到、管理员自己收到）；已修正为
  [本人 id, ...收件人 ids] 顺序。
- **管理员每次登录重复收到「修改管理员密码提醒」**：去重条件含
  is_read=0，已读/清空后登录即重发；改为只要发过一次（无论已读否）
  不再重发。

### 新增

- **已发送消息视图**：消息中心新增「📤 已发送」——独立发送日志表
  sent_messages（schema v5 迁移），展示标题 / 接收者 / 内容 / 时间，
  支持多选删除与一键清空；日志删除仅删发送记录行，
  **不影响接收者查看已收到的消息**。

---
---

## [2.7.0] - 2026-08-25

### 新增

- **站内消息用户互发**：消息中心新增「✉️ 发送消息」——
  - 管理员：三级分类**多选**（全院 → 角色组 → 个人），勾选全院自动
    勾选全部用户，勾选角色组自动勾选该组全部用户，也可单独勾选个人，
    角色组复选框支持半选态；
  - 普通用户：同结构**单选**（仅可发送给一位用户）；
  - **30 秒限流（后端强制）**：普通用户两次发送间隔不足 30 秒直接拒绝
    （基于 messages.from_user_id 最近一条用户消息时间，防止技术手段
    批量发送），提示剩余等待秒数；
  - 标题 ≤ 50 字 / 内容 ≤ 500 字校验，接收者须为启用账号且非本人；
  - 消息表新增 from_user_id（schema v4 迁移），用户消息以蓝色
    「用户」徽章区分（消息中心与铃铛面板同步）。

### 变更

- 次版本号提升至 2.7.0（新增用户互发消息模块：库表迁移 + 通讯录
  三级选择树 + 限流）。

---
---

## [2.6.11] - 2026-08-25

### 修复

- **转归查询 500 错误**：SQL 误用 registrations 不存在的
  dept_name / doctor_name 列——dept_name 改为
  COALESCE(current_dept_name, first_dept_name)；医生在 medical 分库
  不可 JOIN，改为按 visit_id 批量二段查询回填首诊医生（旧镜像表兜底）。
- **就诊历史 $u 未定义警告**：history 案例补充 `$u = Auth::user()`，
  接诊判定不再产生 Undefined variable 告警。

---
---

## [2.6.10] - 2026-08-25

### 修复

- **登录页默认 LOGO**：未设置医院 LOGO 时显示 🏥 默认占位
  （与系统主布局同款样式），不再空白。
- **登录页品牌区与登录卡片间距**：auth-body 弹性布局增加 32px 间距，
  医院名称与右侧登录框不再紧贴。

---
---

## [2.6.9] - 2026-08-25

### 变更

- **缴费状态圆点语义统一**：右栏大纲栏与总费用明细的圆点统一为
  灰 / 红 / 绿——灰=未缴费，红=已缴费但未完成（检查检验结果未出 /
  药品未发放 / 处置未执行，红色更醒目），绿=已完成（结果已出 /
  药品已发 / 处置已完成）；费用明细圆点同步按明细项状态显示。

---
---

## [2.6.8] - 2026-08-25

### 新增

- **状态圆点悬浮提示**：右栏大纲栏与总费用明细中的缴费状态圆点
  悬停显示文字说明——灰=未缴费、红=已缴费（报告/执行中）、
  绿=已完成（报告已出 / 已发药）；费用明细圆点为未缴费 / 已缴费。

---
---

## [2.6.7] - 2026-08-25

### 变更

- **病历字段点击行为调整**：单击输入字段仅定位光标（原单击即全选，
  光标难以精准放置）；双击才全选字段内容，符合常规输入习惯。

---
---

## [2.6.6] - 2026-08-25

### 修复

- **闲置后刷新误弹未保存提醒**：编辑器 render 末尾的 set() 程序化填充
  会对既往史/过敏史下拉派发合成 change 事件（显隐同步用），恰好命中
  markDirty 导致页面一加载脏标记即为 true——只要点过一次页面，闲置后
  刷新就误弹离开确认。现 set() 期间临时屏蔽 onChange（程序化填充豁免），
  并显式导出 markDirty 供模板套用后主动置位（套用内容仍视为未保存变更）。

---
---

## [2.6.5] - 2026-08-25

### 变更

- **费用明细逐项显示**：总费用悬浮列表由「按单合并（检验：血常规、
  尿常规）」改为逐项一行（血常规 / 尿常规各一行，金额 = 单价 × 数量），
  缴费圆点按明细项状态显示。

---
---

## [2.6.4] - 2026-08-25

### 修复

- **总费用悬浮窗闪现即消失**：showFeePop 开头误调 hideFeePop 清理旧面板，
  后者总会排一个 180ms 移除定时器，把刚创建的面板又删掉；改为直接
  移除旧节点 + 仅清定时器，悬停期间面板稳定显示。

---
---

## [2.6.3] - 2026-08-25

### 修复

- **修改患者信息误触**：点击范围由整个姓名行（含性别年龄 / 费用类别 /
  门诊急诊 / 总费用徽章）收窄至患者姓名文字本身——悬停总费用查看
  明细、点击徽章不再误弹修改患者信息弹窗。

---
---

## [2.6.2] - 2026-08-25

### 修复

- **EMR_DIRTY 未定义报错**：beforeunload 拦截器误注册在模块 IIFE 外部
  的全局作用域，访问不到模块级脏标记；移入 IIFE 内部。

### 变更

- **总费用徽章悬浮明细**：hover 总费用徽章弹出费用列表面板（替代原
  title 提示）——圆点区分缴费状态（灰=未缴费、绿=已缴费）+ 项目名称
  （挂号费与各开单含项目，不含医生姓名）+ 单项金额，底部合计；
  样式与右栏项目列表同风格。

---
---

## [2.6.1] - 2026-08-25

### 新增

- **患者信息横条总费用徽章**：「门诊/急诊」徽章后新增橙色徽章显示
  总费用（挂号费 + 全部有效开单合计，退费 / 取消不计），随开单 /
  缴费状态 30 秒轮询实时刷新，无费用时隐藏。
- **未保存离开拦截**：病历编辑器接入脏标记——任何编辑未保存时，
  关闭标签页 / 刷新 / 跳转其他页面会触发浏览器原生确认弹窗；
  保存成功或诊毕后自动清除标记，不再误拦。

---
---

## [2.6.0] - 2026-08-25

### 新增

- **诊毕转归（离院方式）记录与运营查询**：
  - 点击「✅ 诊毕」弹出悬浮面板选择离院方式（自主离院 / 住院 / 转院 /
    死亡 / 其他）；选择住院 / 转院 / 死亡 / 其他时分别要求填写
    住院病区 / 接收医院名称 / 死亡原因 / 其他转归情况（必填校验，
    前后端双重校验）；面板锚定按钮下方，点外部自动收起。
  - 转归数据随诊毕写入 registrations（disposition / disposition_detail，
    schema v3 自动迁移）。
  - 运营分析页新增「🧭 转归查询」子标签：按全部 / 各离院方式筛选，
    最近 200 条诊毕记录；自主离院不显示额外列，其余按类型显示
    住院病区 / 接收医院 / 死亡原因 / 其他转归情况列。

### 变更

- 次版本号提升至 2.6.0（新增转归记录模块：数据库迁移 + 诊毕流程 +
  运营统计子标签）。

---
---

## [2.5.34] - 2026-08-25

### 修复

- **删除 / 毁方后详情弹窗不关闭**：项目详情、处方详情、开单流程弹窗内
  触发删除 / 毁方成功后仅刷新数据，所在模态框残留；成功回调现同步
  close 当前弹窗（侧边栏行内调用时栈空，close 为安全空操作）。

---
---

## [2.5.33] - 2026-08-25

### 修复

- **新开项目排序颠倒**：visit_orders 与病历已开项目快照原按 id DESC
  返回，新开的单/项目显示在列表最前；统一改为 id ASC——
  右侧大纲栏与病历正文均已开项目按开具时间正序排列，新开的追加在最下。

---
---

## [2.5.32] - 2026-08-25

### 修复

- **需皮试药品无法开具**：开单目录行模板漏输出 `data-need-skin-test`、
  itemFromEl 未读取该字段，导致点击药品时 `need_skin_test` 恒为
  undefined、皮试方案选择弹窗永不弹出，提交被后端拦截
  （「请先选择本次处置方案」）。现目录行携带该字段，点选需皮试药品
  正常弹出「需要皮试 / 无需皮试」选择框。
- **处方分区计数口径**：项目数徽章改为处方单数量（此前误为药品项数合计）。
- **项目数徽章样式增强**：灰底改为蓝底（primary-soft/primary），
  与「组合」徽章同款式、更醒目。

---
---

## [2.5.31] - 2026-08-25

### 新增

- **大纲栏分区项目数徽章**：检查 / 检验 / 门诊处置 / 处方分区标题文字后
  新增小胶囊数字徽章，显示当前项目数量（检查 / 检验 / 处置按明细项数，
  处方按药品项数合计），0 项时自动隐藏，不影响金额汇总 / 「＋」/ 箭头。

---
---

## [2.5.30] - 2026-08-25

### 安全

- **站内消息越权可见修复（严重）**：消息查询原条件
  `to_role=? OR to_user_id=?` 使同角色所有用户都能看到发给其他医生的
  定向通知（如王强收到李娜 / 赵敏就诊的「药品已发」「检验报告已出」，
  点击还会跳入他人病历页）。全部 8 处查询（未读数 / 最新消息ID / 列表 /
  消息中心 / 已读 / 删除 / 清空 / 全部已读）改为
  `to_user_id=本人 OR (to_user_id=0 AND to_role=本人角色)`——
  定向消息仅收件人可见，角色广播（to_user_id=0）全员可见；
  消息数据写入侧（开单医生 id）经核查无误，无需订正。

---
---

## [2.5.29] - 2026-08-25

### 变更

- **就诊历史卡片重排版**：标题行（就诊时间 / 科室 / 序号 + 状态徽标）
  通栏置顶；信息区按行拆分「病历：首诊医生」「诊断：第一诊断」「开单：
  类型计数徽章」；「查看电子病历 / 诊断证明」按钮改为右下角竖排，
  查看病历更名为查看电子病历。

---
---

## [2.5.28] - 2026-08-25

### 修复

- **就诊历史病历行空括号**：旧镜像表 records 无 updated_at 列，
  拼接时间括号恒为空；病历行改为仅展示首诊医生与第一诊断
  （结构化病历表优先，旧数据兜底取最早一条）。

### 变更

- **就诊历史开单行徽章化**：按类型计数显示「检验x项 / 检查x项 /
  处置x项 / 处方x项」灰底徽章，不再逐单罗列单号与状态。

---
---

## [2.5.27] - 2026-08-25

### 修复

- **归档未开具时诊断证明「＋」不显示且箭头错位**：诊毕只读隐藏
  .emr-write 时误将补开「＋」一并 display:none（节点仍在 DOM，
  相邻选择器继续抢走箭头右对齐）；现与移除逻辑同样豁免该按钮。

---
---

## [2.5.26] - 2026-08-25

### 变更

- **归档病历补开诊断证明入口收口**：患者信息横条不再显示任何诊断证明
  链接，补开统一走右栏「诊断证明」分区「＋」——归档未开具时该「＋」
  保留（不再随只读态移除），点击弹确认框：
  接诊过该患者提示「该病历已经归档，是否补开诊断证明？」，
  未接诊过则插入「且您未接诊过该病人」；确认后打开补开表单。
- **就诊历史按钮三态化**：查看病历去掉「（预览/打印）」后缀；
  诊断证明按钮按状态动态显示——未归档未开具=新增、已归档未开具=补开
  （同样先弹归档 / 接诊确认）、已开具=查看。

---
---

## [2.5.25] - 2026-08-25

### 变更

- **病历段头医生署名语序调整**：记录医生署名由
  「李娜（工号 2002） ｜ 主治医师」改为「李娜 主治医师 （工号 2002）」，
  姓名在前、职称居中、工号括注收尾，读感更顺；同步调整续写承接头
  与前序只读段头两处。

---
---

## [2.5.24] - 2026-08-25

### 变更

- **诊断证明条目格式统一**：右栏已开具条目改为「日期 时间 科室 +
  医生姓名靠右」（与病历节点同款式、无首/续标记），科室取就诊当前科室，
  一眼可辨开具门诊。
- **「已开具」提醒按意图区分**：单纯点击查看已开具证明不再弹出
  「该次就诊已开具过诊断证明」提示；仅当已开具仍触发开具 / 补开动作
  （特殊手段强制打开开具框）时才提醒重复。

---
---

## [2.5.23] - 2026-08-25

### 变更

- **大纲栏条目信息版式统一**：
  - 「全部诊断」分区更名为「初步诊断」；
  - 病历节点条目改为「日期 时间 科室 （首/续） + 医生姓名靠右」
    （接口 records_history 补充各文书书写科室 dept_name，转科后按
    文书自身归属显示）；
  - 检查 / 检验 / 处置 / 处方条目开单医生改为靠右显示（与诊断同款式，
    行内删除按钮紧随医生名之后）；
  - 诊断证明已开具条目改为「日期 时间 + 医生姓名靠右」，不再显示
    「✅ 已开具（点击查看）」字样。

---
---

## [2.5.22] - 2026-08-25

### 变更

- **横条诊断证明文案精简**：患者信息横条不再显示「已开具诊断证明
  （点击查看）」，已开具状态统一由右侧大纲栏「诊断证明」分区承载；
  保留「诊毕未开具」时的补开链接（只读态左栏「＋」已移除，此为唯一入口）。

---
---

## [2.5.21] - 2026-08-25

### 修复

- **归档病历显示最近保存时间**：徽章取值优先本人文书 updated_at，
  本人无文书（如查看他人归档病历）时回退流水内最新一条文书的
  updated_at / created_at，编辑态与归档态统一展示。
- **诊断证明状态误报「暂未开具」**：左栏误读不存在的
  `visit.cert_issued` 字段，改为读取接口根级 `has_certificate`，
  已开具后正确显示「✅ 已开具（点击查看）」。
- **只读态分区箭头错位**：诊毕只读时「＋」由 display:none 改为物理移除——
  相邻选择器不受可见性影响导致无金额汇总分区（病历节点/知情同意书/
  全部诊断/诊断证明）的折叠箭头失去右对齐而紧贴文字。

### 变更

- **诊断证明单次开具联动收紧**：一份病历（同一次就诊）仅可开具一份，
  已开具后左栏标题「＋」自动隐藏（转科 / 续写同就诊流水同样受限，
  重新挂号可重新开具）；后端 certificate 接口既有按 visit_id 去重拦截，
  前后端双重保障。

---
---

## [2.5.20] - 2026-08-25

### 变更

- **病历页脚与纸张分离**：「最近保存」不再以分隔线页脚形式贴在病历纸
  内部底部，改为纸张外独立胶囊徽章（圆角 pill、灰底、居中显示于病历
  结尾下方），病历纸保持完整一整张的观感；同步清理废弃的
  doc-footer / doc-saved-at / doc-rec-time 样式，不参与打印。

### 修复

- **徽章节点自愈式创建**：静态节点缺失（页面缓存旧 DOM / 时序问题）
  时在编辑器滚动容器内就地补建，避免「节点不存在→静默隐藏」。

---
---

## [2.5.19] - 2026-08-25

### 新增

- **患者信息横条费用类别徽章**：姓名行「性别 / 年龄」徽章后新增
  费用类别徽章（自费 / 居民医保 / 职工医保 / 其他，取挂号登记的
  fee_type，历史数据为空时不显示）；`/api/record get` 的 visit 载荷
  同步补充 fee_type 字段。

---
---

## [2.5.18] - 2026-08-25

### 变更

- **顶部通栏患者信息横条**：新增横跨整页的患者信息条——点击头像弹出
  就诊历史、点击姓名弹出「修改患者信息」；右侧为 💾保存 / ✅诊毕 /
  ↔️转科 / 🖨️打印 图标按钮组，诊毕只读时写操作按钮自动隐藏，
  保存状态文字内嵌横条展示。
- **全景大纲栏迁移至右侧**：原右栏工具栏整体移除，功能全部分拆
  （开单/诊断/证明走分区标题「＋」，见 2.5.15；其余上移横条或改为
  头像/姓名入口）；大纲栏由左侧迁移至右侧并加宽至 250px，
  仅中栏编辑器独立滚动，窄屏（≤1100px）自动退化为纵向堆叠。
- **清理旧 sticky 患者头遗留样式**：移除 components.css 中 #emrHeader 的
  灰色底 / 负边距 / 吸附定位与缝隙封堵伪元素等规则（新横条位于滚动区外，
  无需遮盖滚动内容），信息区在横条内垂直居中。

---
---

## [2.5.17] - 2026-08-25

### 新增

- **大纲栏条目行内删除**：检查 / 检验 / 门诊处置 / 处方条目右侧新增删除
  （毁方）按钮，仅本人开具且未缴费 / 已退费的单子显示（与开单详情弹窗内
  删除按钮同规则），点击弹确认框走既有 `/api/order?action=delete`
  （服务端二次校验本人 + 状态，处方删除自动恢复库存），删除后左栏局部刷新。

### 变更

- **处方条目移除小计金额**：处方分区条目不再显示 ¥ 小计（金额汇总仍保留
  在分区标题右侧）。

---

## [2.5.16] - 2026-08-25

### 变更

- **大纲栏空态文案精简**：知情同意书「暂无知情同意书」、诊断证明
  「暂未开具」移除括号内的引导说明，仅保留状态描述。

---

## [2.5.15] - 2026-08-25

### 新增

- **病历大纲栏分区快捷添加「＋」**：左侧全景大纲栏 8 个分区标题右侧新增
  圆形「＋」快捷入口（悬停放大、悬浮提示，点击不触发分区折叠）——
  检查 / 检验 / 门诊处置 / 处方复用原统一开单弹窗；全部诊断直接打开
  ICD10 诊断选择弹窗（只读状态拦截提示）；诊断证明打开开具 / 查看表单；
  病历节点与知情同意书暂为占位提示（功能建设中，后期完善）。
- **诊毕只读联动**：各分区「＋」携带 emr-write 标记，诊毕只读时自动隐藏，
  与右栏既有写操作按钮行为一致。

### 变更

- **右栏工具栏精简**：移除已被左栏「＋」替代的开检验 / 开检查 / 开处置 /
  开处方 / 诊断证明五个按钮，保留保存病历、保存并诊毕、转科、打印病历、
  就诊历史与修改患者信息；诊断证明未开具时的正文入口与知情同意书占位项
  同步清理为空态引导文案（点标题右侧＋开具/添加）。

---
---

## [2.5.14] - 2026-08-24

### 变更

- **病历页脚精简**：记录时间 / 医生 / 工号 / 职称信息已由各文书段头承载，
  页脚仅保留「最近保存：时间」并靠右对齐；同步清理保存回调中的
  失效元素更新。

### 文档

- **AGENTS.md 提交流程细化**：新增「任务拆分与分级提交推送」约定——
  小步骤完成即本地 commit；主要任务完成时递增版本号并推送远程；
  大重构 / 大变动提升次版本号。
- **新增 CLAUDE.md 兼容引导文件**：内容指向 AGENTS.md 并附重点速记
  （本地运行 / 提交约定 / PHP 7.x 兼容约束），便于其他 AI 工具
  （Claude Code 等）自动遵循项目约定。
- **提交信息规范化**：AGENTS.md 明确采用 Conventional Commits 前缀
  （feat / fix / docs / style / refactor / perf / chore）+ 中文简述，
  禁止无前缀的纯中文提交信息。
- **提交正文细化**：每次提交在标题之外，必须空一行后附详细正文，
  逐条记录本次更新细节（改动点、涉及模块、CHANGELOG 同步情况）。

---
---

## [2.5.13] - 2026-08-24

### 新增

- **续写编辑器承接头**：续写病历进入编辑时，在前序只读段与本人编辑器
  之间的虚线分隔线下方显示「✍️ 病历续写 · 记录医生：xxx · 记录时间」
  承接头（版式对齐打印续写段），明示当前正处于续写场景；
  首次续写未保存时不显示时间。

---
---

## [2.5.12] - 2026-08-24

### 修复

- **只读病历段落的「接诊自：xxx」措辞移除**：该短语仅适用于活跃续写场景
  （且其后跟随的是本段书写医生本人，语义本就文不对题）；
  诊毕只读与历史文书段统一改为中性归档表述「记录医生：xxx」，
  首诊/续写类型由既有徽标标示。

---
---

## [2.5.11] - 2026-08-24

### 变更

- **病历左栏折叠/展开动画**：点击分组标题时高度与透明度平滑过渡
  （精确测量内容高度，收起至 0、展开至实际高度后解除限制，
  长列表不被裁切），配套箭头旋转指示，替代原先生硬的瞬间显隐。

---
---

## [2.5.10] - 2026-08-24

### 变更

- **病历页左右侧边栏宽度统一为 175px**（原左 240 / 右 176），视觉更协调。
- **左栏处方条目精简**：移除「N 味」提示，仅保留处方序号、开单医生、
  状态圆点与金额。

---
---

## [2.5.9] - 2026-08-24

### 新增

- **检验/检查项目详情内联展示报告文字结果 + 打印预览按钮**：
  - 项目详情弹窗（左栏与病历正文 token 共用）在报告已出时，
    内联展示结构化文字结果——检验为指标明细表（项目/结果/单位/参考范围/危急值，
    组合检验逐成员列行）；检查为影像所见与诊断结论；附执行/报告人与时间。
  - 同时提供「📄 查看报告（打印预览）」按钮，调起与医技工作站一致的
    报告打印预览页——文字速览与正式文书两用。
  - 新增医生站接口 `doctor?action=report_detail`（按 report_id 返回
    解析后的指标行 / 所见结论），数据与打印模板同源。

---
---

## [2.5.8] - 2026-08-24

### 修复

- **恢复右栏丢失的「🖨️ 打印病历」与「✏️ 修改患者信息」按钮**
  （三栏重构重写页面模板时遗漏）。
- **项目/处方详情模态框恢复闭环追踪进度并与开单弹窗完全一致**：
  左栏与病历正文打开的详情弹窗右侧恢复纵向闭环流程列，
  步骤与开单弹窗右侧流程严格对齐——检验/检查/处置为
  「开单→缴费→登记→完成」、处方为「开单→缴费→登记→药房发药」
  （审方在系统中无独立状态、普通处方无登记动作，遵循"无此状态则不显示"原则），
  按条目/单据实时状态点亮进度；流程列抽取为公共方法 flowColumnHtml，
  与左栏详情数据 100% 同源。
- **详情弹窗补回操作按钮**：检验/检查/处置详情补「🖨️ 打印申请单」，
  处方详情补「🖨️ 打印处方笺」；未缴费/已退费且为开单医生本人时
  显示「🗑️ 删除 / 毁方」（后端硬拦截兜底）。
- **修复处方详情弹窗流程列被挤到下方**：外层双列容器改用
  内联 `display:flex;flex-wrap:nowrap` 强制单行（左列表 min-width:0 可收缩、
  右流程列定宽不缩），任何内容宽度下均保持左右并排。
- **APP_VERSION 随发版同步至 2.5.8**：静态资源缓存戳随之更新，
  确保本轮脚本变更即时生效（后续发版需同步维护该常量）。
- **左栏处方条目补齐状态圆点**：与检查/检验/处置一致的
  灰（待缴费）/ 红（已缴费·发药流程中）/ 绿（已发药）三色指示灯，
  样式统一；圆点已表达缴费状态，条目右侧仅保留金额，
  移除冗余的状态文字。

---
---

## [2.5.7] - 2026-08-24

### 修复

- **系统侧边栏展开/收起动画异常（菜单项"自下而上"出现）**：
  宽度过渡期间菜单文字在中间宽度下发生折行，菜单项高度随之变化，
  下方项被反复顶动产生诡异的纵向跳动。现固定菜单项高度并禁止文字换行，
  侧边栏与菜单区对过渡期溢出做横向裁切；并为侧边栏本体补上
  `width .25s` 过渡（原先仅主区滑入，侧栏宽度瞬间跳变，两侧不同步）。
  **二轮核实**：品牌名 / 菜单分组标题 / 页脚版权未禁止折行，
  过渡期窄宽下被挤成一字一行竖排、展开后才恢复横排——观感即
  "文字自下而上滑入"。现已对侧栏内全部文本块统一禁用折行，
  过渡期仅横向裁切，任何阶段都不会出现竖排。
- **静态资源缓存戳**：全部 CSS/JS 引用追加 `?v=APP_VERSION`
  （APP_VERSION 与发布版本号同步维护），根治浏览器缓存旧样式/脚本
  导致"改了没生效"的问题。

---
---

## [2.5.6] - 2026-08-24

### 修复

- **修复三栏重构引入的样式冲突与左栏数据丢失（三项）**：
  - **系统侧边栏样式被污染**：病历左栏此前复用了系统侧边栏的全局类名
    `.nav-item` 等，文件末尾的同名规则覆盖了深色侧边栏的白字样式导致展开显示异常。
    现病历左栏全部改用独立命名空间 `.ena-*`（ena-sec / ena-item / ena-sum /
    ena-arrow / ena-sub / ena-empty），两套样式彻底隔离。
  - **左栏文字空白**：同源于上述类名冲突（浅色主题下继承了侧边栏浅白文字色），
    随命名空间隔离一并修复。
  - **左栏内容 30~60 秒后丢失变「暂未开立」**：左栏刷新总线
    `refreshLeftNavSummary()` 调用 `loadOrders()` 未传就诊 ID，
    请求以 `visit_id=undefined` 发出，服务端返回成功空列表覆盖了数据。
    现 `loadOrders` 未显式传参时自动回退读取页面隐藏域就诊 ID。
- **左栏处方点击行为更正**：由「就地展开明细」改为弹出对应
  【处方详情模态框】（组医嘱树形明细、规格剂量频次途径、费用与发药状态），
  条目附药品味数提示。

---

## [2.5.5] - 2026-08-24

### 新增

- **病历正文医嘱交互穿透（In-text Interactive Tokens）**：
  - 活跃编辑病历中，【辅助检查】与【门诊处置】由系统自动带出的检验、检查、
    处置、处方项目文本渲染为可点击的行内标签（淡蓝底色 + 点状下划线 + 悬停反馈），
    点击直接弹出对应【项目详情模态框】：检验/检查/处置走既有 showItemDetail
    （费用、执行状态、报告结果），处方药品弹出整张处方的组明细模态框
    （规格、剂量频次途径、费用小计与药房发药状态）。
  - **状态感知降级**：只读历史文书段（.emr-record-readonly）与诊毕只读文档内
    标签自动降级为纯文本（无底色无下划线，pointer-events:none 彻底禁用点击）；
    `@media print` 打印输出同样彻底降级，保持严肃医学文书排版。
  - **事件代理**：在中栏滚动容器统一绑定一次 click 委托分发（data-otype/
    data-oid/data-iid），动态重渲染无需重复绑定；已出报告的检验检查标签
    自动追加「（已出报告）」后缀。

---

## [2.5.4] - 2026-08-24

### 变更

- **门诊医生站病历页重构为三栏式工作台（100vh 视口锁定）**：
  左侧固定全景大纲栏（病历节点定位、知情同意书占位、跨医生诊断去重汇总、
  检查/检验/处置金额汇总与缴费报告三色状态灯 + 详情弹窗含查看报告、
  处方按单展开组医嘱明细与发药状态、诊断证明开具/查看）；中间编辑区为唯一
  独立滚动区；右侧工具栏静态锁定；移除底部已开项目模块；
  左栏异步刷新总线 refreshLeftNavSummary() + 30 秒轮询。
### 修复

- **修复重构首版报错**：`toggleNavSec` 原在 IIFE 内部对 `Clinic.emr` 赋值
  （此时 Clinic.emr 尚未由 return 赋值，报 Cannot set properties of undefined），
  改为挂载 window 并同步调整左栏标题点击入口。

---

## [2.5.3] - 2026-08-23

### 新增

- **开单详情支持检验/检查报告查看**：
  - 检验/检查单流程进度细化为「开单 → 缴费 → 登记 → 报告完成」四步；
  - 明细逐项显示执行状态徽标（待登记 / 已登记 / 已出报告）；
  - 已出报告的项目附「📄 查看报告」按钮，弹窗内直接调起该报告的打印预览
    （检验值 / 影像所见与结论，与检验科 / 影像科工作站所见一致）。
  - `visit_orders` 接口扩展：检验/检查明细新增 `status` 与 `report_id`
    （混淆串，经 results→reports 链路解析最新有效报告）。

---

## [2.5.2] - 2026-08-23

### 变更

- **成组医嘱全药品开放**：开单时「＋ 子医嘱」按钮此前仅在药品给药途径含
  「静脉」时显示（静脉输液场景遗留限制），现所有药品均可添加子医嘱，
  不限给药途径；相关提示文案同步去输液化（「子处方」统一更名为「子医嘱」）。

---

## [2.5.1] - 2026-08-23

### 变更

- **运营分析日期选择接入通用日期组件**：日期范围输入由原生 `type=date` 改为
  全站通用的 `Clinic.datePicker` 日历弹层（只读点击弹出，拒绝手输避免格式错误）；
  选定日期后自动刷新当前统计视图；结束日期限制不可选未来。

---

## [2.5.0] - 2026-08-23

### 新增

- **医院运营分析（管理员 → 📊 运营分析）**：
  - **统计口径（统一为已缴费）**：项目收入按 `orders.paid_at` 落账日归属并排除已退费/取消；
    挂号费取 `payments(kind=visit)`；门诊人次 = 已缴费/就诊中/诊毕挂号；
    医生接诊人次 = 医生本人病历创建数。
  - **运营总览**：日期范围（今日/昨日/近7天/近30天/本月/本年快捷选择 + 自定义区间）
    KPI 卡——门诊人次、总收入、挂号费、药费、检验费、检查费、处置费；
    收入构成趋势点线图（总收入/药费/检验费/检查费/处置费五条序列）与门诊人次趋势图，
    数据点悬停显示明细。
  - **科室统计**：按科室汇总门诊人次、挂号费、四类项目费与合计收入，
    附科室收入排行横向条形图，表格按收入降序排列。
  - **医生统计**：支持科室筛选，按医生汇总接诊人次与分类型缴费收入
    （处方/检验/检查/处置分开列示 + 合计），可查看某天/某月/某年每位医生的业绩。
  - **自定义统计**：时间粒度（日/月/年）× 维度（科室/医生）× 指标（人次/挂号费/药费/
    检验费/检查费/处置费/总收入任意多选）自由组合，结果以折线图（时间维度）/
    条形排行图（科室医生维度）+ 明细表呈现。
- **轻量图表组件 chart.js**：纯 SVG 实现的多序列点线图与横向条形图
  （自适应宽度、Y 轴自动刻度、数据点悬停提示、图例），零第三方依赖。

---

## [2.4.8] - 2026-08-23

### 修复

- **续写病历自动滚动失效**：页面实际滚动发生在 `.content` 容器（overflow-y:auto）
  而非 window，此前 `window.scrollTo` 不生效导致续写进入完全无滚动。
  现自动查找锚点的实际可滚动祖先容器进行滚动（兜底回退 window），
  定位点保持患者信息栏下方。

### 变更

- **组医嘱子药行补充剂量**：`├─ / └─` 子药行在名称后显示单次剂量（临床必填要素），
  频次/途径/数量仍仅在主药行显示一次。
- **组医嘱格式统一为公共方法**：
  - 前端新增 `Clinic.orderRxLines(items)`，后端新增 `emr_rx_display_lines(items)`；
  - 应用范围：病历正文所见即所得、他人文书只读段、已开项目卡片列表、
    开单详情弹窗（流程卡片）、病历打印文本快照与实时渲染——
    全系统同一套组医嘱展示规则，后续维护只改一处。

---

## [2.4.7] - 2026-08-23

### 变更

- **病历续写滚动定位修正**：改用计算式滚动（锚点位置减去吸顶栏高度），
  定位点精确落在上方患者信息栏下方区域，不再出现停在页面中间的偏差。
- **成组医嘱树形展示（病历正文 + 病历打印门诊处置节）**：
  - 组内主药行带全部要素：名称　剂量　频次　途径　×数量；
  - 子药以树形连接符缩进列出（`├─` / 末行 `└─`），组内频次/途径/数量一致仅主药行显示一次，
    不再将组内药品渲染为多条独立处方行；
  - 非成组药品各自独立一行、全要素显示，不受影响；
  - 覆盖三处渲染路径：病历编辑页所见即所得与他人文书只读段（JS）、
    病历保存时的打印文本快照、病历补打实时渲染。

---

## [2.4.6] - 2026-08-23

### 修复

- **病历自动滚动逻辑修正**：
  - 单人文书（整份病历只有当前医生一份，含未保存首诊）不再触发自动滚动，
    修复此前单人病打开页时异常滚动到过敏史附近的问题。
  - 仅在「存在他人文书」的续写场景下自动定位到【病历续写】处；
    且首次续写进入（本人尚无文书）即立即定位，无需先保存一次。
- **开单 / 开处方 / 开诊断证明 / 打印病历的完整性校验实时生效**：
  病历保存成功后，服务端返回的文书 `record_id` 未回写到本地缓存，
  导致「本人尚无文书」判定持续不通过、必须刷新页面才能开单。
  现保存成功后立即回写 `record_id`，必填项齐全时无需刷新即可开单。

---

## [2.4.5] - 2026-08-23

### 修复

- **作息时间保存提示「未知操作」**：`admin.php` 接口分发 switch 中遗漏了
  `work_save` 分支，前端提交的作息保存请求落入默认分支被误判为未知操作。
  已补充分发规则，常规作息与夏令时作息（含日期范围、四要素时间、生效校验）
  均可正常保存。

---

## [2.4.4] - 2026-08-23

### 新增

- **医生退出登录后叫号大屏自动取消关联**：
  - 退出登录时（所有登出路径统一经过 `Auth::logout()`），自动释放该医生绑定的
    全部诊室大屏（清空 `current_doctor_id` / `current_doctor_name` / `doctor_heartbeat`），
    大屏随即恢复无医生状态。
  - **异常退出兜底**：大屏轮询时检测绑定医生的保活心跳，超过 90 秒未更新
    （直接关闭浏览器 / 会话过期等未走登出流程的场景）也自动取消关联；
    心跳正常时不受影响，不会误释放。

---

## [2.4.3] - 2026-08-23

### 变更

- **医生叫号大屏布局打磨（多轮迭代汇总）**：
  - **医生信息卡**：中间以虚拟虚线平分左右两列——左侧照片在单元格内等比缩放到
    最大（宽度或高度先触边即以其为准），有照片时无底色、无照片时显示黄色占位；
    右侧均分 7 行网格：第 1-2 行合并显示姓名（JS 动态拟合单元格可容纳的最大字号后
    减 10 号），第 3 行职称、第 4 行工号、第 5-7 行合并显示医生介绍（字号比工号小 1 号，
    无介绍时「暂无医生介绍」上下左右居中）；职称 / 工号 / 介绍随姓名字号比例联动。
  - **下方候诊区三块面板统一**：「正在就诊」「下一位」「等待就诊」标题固定在各自方框
    顶部正中；就诊信息在方框中部居中显示；无患者时提示语（如「暂无候诊患者」）
    在方框正中显示；等待就诊空态文案与下一位区域统一。
  - **顶部栏与科室诊室条**：文字与高度改为 `vmin` 等比缩放，大屏自动放大；
    医院名称与第二名称两行始终左右两端对齐（徽标式排版）。
  - **字号全面相对化**：就诊患者姓名、序号、候诊列表、面板标题、空态提示等
    全部采用 `clamp(px, cqmin, px)` 随屏自适应，杜绝绝对字号在不同屏幕上过大或过小。

---

## [2.4.2] - 2026-08-23

### 变更

- **医生叫号大屏布局重构为 CSS Grid 自适应网格（竖屏/横屏/不同尺寸通用）**：
  - 整个大屏主区以「隐藏线条表格」式 CSS Grid 划分：上方医生信息卡、下方左右两列
    （左列上「正在就诊」下「下一位」，右列「等待就诊」名单），竖屏横屏共用同一套网格。
  - 字号全部改为相对单位 `clamp(px, cqmin, px)`（基于容器最小边缩放），
    不再使用绝对字号：小屏自动缩小、大屏自动放大，适配不同分辨率与方向。
- **医生信息卡信息分行展示**：姓名独占一行（最大字号），下一行工号，下一行职称，
  姓名+工号+职称约占头像高度一半；下半部分显示医生介绍（无介绍时下半部分居中显示
  「暂无医生介绍」）。
- **竖屏下半部分布局调整**：改为左列（正在就诊 / 下一位上下两块）+ 右侧等待就诊列表，
  与横屏同构；等待就诊面板补充边框。

---

## [2.4.1] - 2026-08-23

### 修复

- **医生叫号大屏转诊标记误判**：`screen.php` 接口的 `fmt` 闭包未捕获 `$deptId`
  （`use ($mask)` 缺少 `$deptId`），导致闭包内 `$deptId` 为 0，
  凡 `first_dept_id > 0` 的患者（含本科室挂号、非转诊患者）都被错误标记为转诊，
  显示「（转）」。已补充闭包捕获，转诊判定恢复为「挂号科室 ≠ 当前就诊科室」。
- **医生叫号大屏布局优化**：
  - 医生信息卡放大并占据上方约 1/2 区域：左侧圆形头像（无照片显示默认头像），
    右侧姓名 / 工号 / 职称 + 大块医生介绍区（无介绍显示「暂无医生介绍」）。
  - 下方改为左右两列：左侧列上下分两块（上「正在就诊」、下「下一位」），
    右侧列显示「等待就诊」名单（转诊患者序号带 ★ 标记）。
  - 竖屏与横屏分别适配该新布局（竖屏医生卡压缩、就诊区左右分列、候诊名单随动）。

---

## [2.4.0] - 2026-08-23

### 新增

- **叫号大屏样式全面优化（2.4.0）**：
  - **顶部布局精简**：诊室名称从标题栏移出，改为在标题栏下方显示「科室 + 诊室」名称条
    （如「骨科 10号诊室」「影像科 2号室」「检验科 抽血诊室」）；移除右上角喇叭静音按钮
    （语音开关统一在【叫号管理】编辑页配置）；顶部时钟字号缩小为单行紧凑显示，
    与左侧医院名称协调。
  - **温馨提示**：大屏底部新增温馨提示栏，支持按科室类型内置默认提示；
    管理员可在【叫号管理】编辑诊室时自定义多条提示（每行一条）并设置轮播间隔（秒）。
    多条提示自动定时轮播切换；单条提示超长（>30 字）时自动以跑马灯左右滚动显示。
  - **竖屏 / 横屏自适应**：按屏幕宽高比自动切换布局——横屏医生大屏横向排布
    （医生卡 + 正在就诊 + 右侧请就诊/候诊列表），竖屏纵向紧凑排布
    （正在就诊上、请就诊与候诊左右分列），医技看板同样适配。
  - **医生大屏信息增强**：医生诊室大屏新增医生信息卡（照片 / 姓名 / 工号 / 职称 / 介绍，
    无照片显示默认头像，无介绍显示「暂无医生介绍」）；就诊患者为转科转入时，
    序号完整显示「原科室 + 序号 + （转）」（如「内科门诊 001号（转）」）并在候诊列表
    以 ★ 标记，避免与本科室号码混淆。
- **数据库新增字段**：`clinic_rooms` 表新增 `screen_tips`（温馨提示 JSON 数组）与
  `tip_interval`（轮播间隔秒，默认 5），通过 schema 迁移 v2 自动升级。

---

## [2.3.20] - 2026-08-23

### 新增

- **消息实时提醒（不刷新页面）**：
  - 未读消息轮询由 30 秒缩短至 15 秒。
  - 后端 `unread_count` 新增返回 `latest_id`（最新未读消息 ID），
    前端通过比较其是否增大来准确检测「新消息到达」（比比较数量更可靠，
    避免多端已读导致的计数波动误判）。
  - 新消息到达时：若消息面板已打开则自动刷新列表，否则弹出
    「💬 收到 N 条新消息」轻提示；铃铛角标实时更新。

### 修复

- **消息中心「全部已读」后右上角角标不刷新**：原实现逐个消息发异步
  `read` 请求后立即刷新角标，存在竞态导致角标仍显示旧未读数。
  现改为后端新增 `read_all` 动作一次性原子标记全部已读，完成后刷新角标，
  避免竞态。

---

## [2.3.19] - 2026-08-23

### 新增

- **站内消息支持单独删除与一键清空**：
  - 消息中心每条消息右侧新增 🗑 删除按钮，可单独删除某条消息。
  - 顶部新增【🗑 一键清空】按钮，确认后清空当前用户可见的全部消息（确认弹窗防止误删）。
  - 删除仅影响当前登录用户可见范围（`to_role` 匹配自身角色或 `to_user_id` 指向本人），
    不影响其他角色收到的消息。

---

## [2.3.18] - 2026-08-23

### 变更

- **个人信息页精简提示词**：移除「基本信息（只读，需联系管理员修改）」与
  「📋 需审核修改（学历 / 学位 / 个人介绍）」两个区块标题，
  保留「学历、学位、个人介绍修改需提交管理员审核，审核通过后才生效。」一句即可。

### 修复

- **上传头像后右上角头像不更新**：头像审核通过后仅更新了 `users.photo`，
  已登录用户的会话快照仍是旧值，导致页面右上角与悬浮窗头像不刷新。
  现于页面框架层检测 `users.photo` 与会话快照差异并自动校准同步，即时生效。

### 安全

- **头像展示改为 base64 Data URI 内联（不再暴露文件 URL）**：
  页面右上角 / 悬浮窗头像、个人信息页头像、用户管理弹窗头像、叫号大屏医生照片，
  统一改为 `img_data()` 转 base64 Data URI 内联展示，服务端不直接返回
  `uploads/user/...` 真实路径，防止上传文件路径泄露；同时避免二级路径页
  相对路径解析 404 问题。大屏接口 `call_queue` 的医生 `photo` 字段同样返回
  Data URI。

---

## [2.3.17] - 2026-08-23

### 变更

- **用户管理新增/编辑弹窗头像交互优化**：
  - 表单顶部居中展示头像，点击头像即弹本地照片选择并预览，移除底部「照片（可选）」文件选择框。
  - 登录用户名下方「须以英文字母开头……工号同样可用于登录」的提示语移除，
    输入框占位符已提示，校验规则不变。
- **个人信息页头像改为点击上传 + 审核制**：
  - 移除「即时生效（头像/界面主题）」区域的文件选择与保存按钮，改为直接点击用户头像上传照片。
  - 上传后头像立即预览并叠加半透明「审核中」标识，审核通过后自动刷新生效；
    审核被拒绝时自动删除待审照片文件，头像还原为原图。
  - 学历/学位/个人介绍仍走审核；界面主题改为独立下拉选择、即时保存（无需审核）。
- **头像审核纳入审核池**：`profile_submit` 支持上传 `photo` 字段并写入 `audits` 表
  `profile_update` 记录的 `data` JSON；审核通过后写入 `users.photo`，拒绝时删除已上传文件。
  仅上传头像时不再误清空其他需审核字段（按提交字段分别判断）。

---

## [2.3.16] - 2026-08-23

### 新增

- **个人信息页改造（需审核字段 + 即时保存）**：
  - 姓名 / 职称 / 职务改为只读展示，需联系管理员在用户管理中修改。
  - 学历 / 学位 / 个人介绍改为「提交审核」制：提交后写入审核池
    （`audits` 表 `type=profile_update`，`data` 存 JSON 新值），
    审核通过才生效；待审核期间相关字段灰显只读并提示「等待审核中」，
    禁止重复提交；审核结果通过站内消息通知申请人。
  - 头像 / 界面主题改为即时保存，无需审核，立即生效。
- **审核中心分组查看**：新增「平铺列表 / 按申请人分组 / 按类型分组」三种视图，
  按申请人分组时以卡片展示各申请人的事项汇总，按类型分组时按事项类型聚合，
  便于管理员批量定位与处理同一用户或同一类型的申请。

### 修复

- **个人资料审核申请通知工号为空**：`profile_submit` 通知管理员的站内消息中
  「工号」字段从会话快照取值导致为空，改为从用户表查询填充。

---

## [2.3.15] - 2026-08-23

### 修复

- **叫号大屏姓名脱敏规则优化**：原逻辑保留首字其余全星号（如"张小三"→"张**"），
  现改为按名字长度精细脱敏：
  - 2 字 → 首字 + `*`（如"张三"→"张*"、"欧阳"→"欧*"）
  - 3 字 → 首尾保留、中间 `*`（如"张小三"→"张*三"、"李小明"→"李*明"）
  - 4 字及以上 → 保留首尾各 1 字、中间全 `*`（如"王小明三"→"王**三"、
    "买买提·肉孜"→"买****孜"、英文"John"→"J**n"）
  - 以「无名氏」开头的匿名患者：保留原样（无真实姓名，脱敏无意义）
- **语音叫号不受脱敏影响**：大屏接口新增 `raw_name`（原始姓名），
  屏幕显示用脱敏后的 `name`，语音播报用 `raw_name` 喊全名。

---

## [2.3.14] - 2026-08-23

### 修复

- **统一打印中心搜索报错（JS 语法错误）**：`printcenter.php` 就诊列表行内
  `onclick="showPrintItems('" + v.id + "')"` 的引号拼接错误，导致整段脚本
  `Unexpected string` 语法错误解析失败，`searchVisit` 函数未定义、查询按钮无响应。
  修复为 `showPrintItems(\'' + v.id + '\')`（混淆串以单引号包裹）。
  - 端到端验证：搜索就诊返回混淆 visit_id，打印中心可正确展示该就诊可打印单据。

---

## [2.3.13] - 2026-08-23

### 变更

- **科室选择器按场景区分显示**：通用 deptPicker 增加 `showRoomStats` 选项。
  - 叫号大屏选择科室：只显示大屏统计（🖥️ 在线数/总数 在线 / 无大屏），
    不显示【门诊】【急诊】徽章；
  - 医生工作站切换科室、转科：维持原逻辑，正常显示【门诊】/【急诊】/【限号】徽章，
    不显示大屏统计。

---

## [2.3.12] - 2026-08-23

### 变更

- **叫号管理科室选择器显示大屏统计**：科室选择卡片下方显示该科室大屏数量
  （🖥️ 在线数/总数 在线），数据直接从 `clinic_rooms` 表按 `dept_id` 统计
  （无需新增数据库字段）；选择器同时正确区分急诊/门诊分组与类型徽章。

## [2.3.11] - 2026-08-23

### 修复

- **叫号管理科室选择器未区分急诊/门诊**：`CM_DEPS` 补全 `type` 字段，
  选择器正确显示急诊/门诊 Tab 与类型徽章。

---

## [2.3.10] - 2026-08-23

### 修复

- **叫号管理页面未选科室时一直显示加载转圈**：页面默认在 `#cmList` 显示 spinner，
  但未选科室时无任何初始化加载逻辑，导致一直转圈（易被误认为网络错误）。
  修复：默认区域改为「请先选择科室」提示文案（点击「🏥 选择科室」按钮或直接新建），
  选择科室后才加载大屏列表。

---

## [2.3.9] - 2026-08-23

### 修复

- **药品编辑表单皮试处置项目名称不回显**：`form_drug` 中 `$skinName`
  （关联皮试处置项目名称）在 HTML 拼接**之后**才计算，导致 `f_skin_item_name`
  渲染为空字符串（显示为 placeholder「点击右侧按钮选择或新建」），
  即使数据库已正确保存 `skin_test_item_id`。修复：将 `$skinName` 计算
  移至 HTML 拼接之前。
  - 端到端验证：云南白药创可贴关联青霉素皮试（`skin_test_item_id=9`），
    重新打开表单正确回显 `青霉素皮试`。

---

## [2.3.8] - 2026-08-23

### 修复

- **药品新增/编辑保存丢失皮试处置项目（根因）**：`drug_save` 的 INSERT 分支（新增药品）
  SQL 列数与参数不匹配。`$data` 数组在 1.8.0 新增 `need_skin_test`/`skin_test_item_id` 后
  变为 18 项，但 INSERT SQL 的列、占位符与 `array_merge` 参数组合未同步更新，导致
  `need_skin_test` 和 `skin_test_item_id` 值错位到 `status`/`created_at` 列，
  皮试数据实际未写入。修复：INSERT SQL 补充 `need_skin_test, skin_test_item_id` 两列，
  参数严格按 `$data` 键顺序拼接。
  - 端到端验证：新增药品 `skin=1, item=9` → 保存后重新打开表单回显 `青霉素皮试` ✓
  - 编辑药品（UPDATE 分支）不受影响，但同步确认正确。

---

## [2.3.7] - 2026-08-23

### 修复

- **药品编辑：勾选"需要皮试"未选处置项目时禁止保存**：新增前端必填校验——
  勾选"需要皮试药品"但未选择关联皮试处置项目时，点击保存提示并拦截，
  不提交（后端已有同校验，双保险）。
- **药品编辑：皮试处置项目保存回显**：确认 `openDrugForm` 提交时携带
  `skin_test_item_id` 字段，且表单回显 `f_skin_item` 值正确
  （端到端验证：保存 `need_skin_test=1, skin_test_item_id=9` 后重新打开
  表单回显 `青霉素皮试`）。
- **管理员侧边栏导航高亮误匹配**：`/admin/drugs` 与 `/admin/drugsettings`
  前缀包含关系导致选择「药品设置」时「药品信息」也高亮。导航高亮由
  `path.indexOf(href) === 0` 改为**精确匹配或路径段前缀匹配**
  （`path === href || path.startsWith(href + '/')`），避免 `drugs` 误匹配
  `drugsettings` 等包含关系路径。

---

## [2.3.6] - 2026-08-23

### 修复

- **药品编辑保存丢失皮试设置**：`openDrugForm` 的保存代码手动收集各字段，
  但遗漏了 `need_skin_test` 和 `skin_test_item_id`，导致勾选"需要皮试"
  并关联皮试处置项目后保存，再次打开时设置丢失。已补全这两个字段的提交。
  （后端 `drug_save` 接口已正确处理，仅前端遗漏。）

---

## [2.3.5] - 2026-08-23

### 修复

- **药品编辑弹窗皮试函数仍未生效（ReferenceError）**：上一版把函数绑定放在 `loadModal`
  内部，但药品编辑走 `openDrugForm`（直接调 `Clinic.modal.load`），未经过 `loadModal`，
  监听器从未附加。现改为在 ui.js 末尾注册**全局 `modal:loaded` 捕获监听器**
  （`document.addEventListener(..., true)`），对所有弹窗路径生效：
  `syncSkinBox` / `pickSkinDisposal` / `clearSkinDisposal` 一律无条件定义
  （无皮试字段时做空操作兜底），按钮点击不再报错；同时移除 loadModal 内重复定义。

---

## [2.3.4] - 2026-08-23

### 修复

- **药品编辑弹窗皮试函数报错**：`syncSkinBox()` / `pickSkinDisposal()` /
  `clearSkinDisposal()` 仅在表单含皮试字段时才被定义，导致点击报 ReferenceError。
  现改为在 `modal:loaded` 时**无条件定义**（无皮试字段时做空操作兜底），
  点击按钮不再报错。
- **管理端诊断中心报错**：`Clinic.importer._reloads['icd10'] = loadDiagList`
  引用了不存在的函数名（实际为 `loadDiag`），导致进入诊断中心时 ReferenceError。
  已改为 `loadDiag`。
- **叫号管理默认「请选择科室」下拉**：改为「🏥 选择科室」按钮，调用通用科室选择
  模态框（deptPicker）选择/切换科室；选中后显示该科室「共 X 块大屏，X 块在线」。
- **叫号管理大屏状态实时刷新**：每 10 秒自动刷新列表（无需手动刷新页面），
  实时反映大屏在线/离线状态。
- **管理员改密提醒改为站内消息**：移除页面顶部横幅提示，改为管理员首次登录
  （未修改默认密码）时自动生成一条「修改管理员密码提醒」站内消息，
  点击消息跳转 `/password` 修改密码（幂等：已存在未读提醒时不再重复生成）。

---

## [2.3.3] - 2026-08-23

### 变更

- **叫号大屏切换确认**：医生已绑定某诊室大屏后，再点击另一空闲诊室大屏时，
  弹出确认提示「当前已绑定「A」诊室大屏，是否将叫号大屏从「A」切换到「B」？
  切换后该医生的叫号信息将显示在「B」大屏上」，确认后完成切换。
  后端保证一次仅绑定一块大屏（切换时自动释放原诊室），前端同步更新绑定状态。

---

## [2.3.2] - 2026-08-23

### 修复

- **医生站叫号大屏绑定/解绑逻辑修复**：
  - 已绑定诊室点击 = 解绑（弹出确认），不再误调用绑定接口；
  - 页面加载时自动调用 `loadRoomList()`（选科室后即加载绑定状态），
    不再需要手动点击按钮才显示已绑定；
  - 解绑后停止心跳定时器，释放资源。
- **叫号大屏页面样式未生效**：`screen.js` 使用的 `screen-*` 类（`screen-cur-name`、
  `screen-panel`、`screen-doctor-grid`、`screen-dept-item` 等）在 `call.css` 中
  完全缺失，导致大屏页面双模式布局无样式。现已补充完整：
  - 模式A（医生诊室）：大卡片排版——正在就诊、请就诊、候诊列表；
  - 模式B（医技列表）：看板排版——队列 + 当前呼叫高亮呼吸灯；
  - 窄屏响应式适配。
- **医生工作站侧边栏移除「叫号屏幕」**：旧版 `/doctor/call` 入口已由统一的
  叫号大屏系统（`screen.php` + 大屏绑定选择器）替代，去掉菜单项。

---

## [2.3.1] - 2026-08-23

### 修复

- **ID 混淆导致接诊/已开项目详情/Del 按钮无响应**：混淆串（如 `-kSEdP2swuR7rCvuxu_4Qg`）
  在 HTML onclick 属性中未用引号包裹，浏览器将其当作未定义标识符抛出 ReferenceError，
  导致接诊按钮、开单详情查看、检验/影像/药房队列按钮完全失效。
  - 修复 PHP 后端 13 处函数参数型 onclick 闭引号转义（`doctor.php` `cashier.php`
    `lab.php` `imaging.php` `patient.php` `pharmacy.php`），使混淆串以
    `takePatient('CODE')` 形式渲染；
  - 修复 `emr.js` 前端动态渲染的 `viewOrderFlow`、`delOrder`、`delOrderFlow`
    三处拼接遗漏（`o.id` 未加引号包裹）；
  - 全量扫描确认零遗漏（0 处后端 `visit_id/order_id/report_id/payment_id` 未 did 解码、
    0 处函数参数型 onclick 混淆串缺引号）。

---

## [2.3.0] - 2026-08-23

### 新增

- **通用数据导入导出（CSV）**：覆盖科室 / 药品 / 人员 / 检验 / 检查 / 处置 / ICD10 诊断
  7 大核心模块，各管理页顶部统一提供「📥 下载模板 / 📤 导出全部 / 📥 批量导入」按钮。
  - **模板下载**：标准中文表头 + 示例行，UTF-8 BOM 编码（Excel 直接打开不乱码）；
  - **数据导出**：当前有效数据一键导出 CSV（UTF-8 with BOM）；
  - **两阶段安全导入**：上传后先预检（解析 + 唯一键冲突比对，不落库），
    冲突明细列表呈现；操作员选择【忽略冲突】或【覆盖更新】后事务批量写入，
    非法数据（缺必填 / 数字格式错误）逐行标注并拦截，失败整体回滚。
- **冲突唯一键规则**：人员按工号/用户名、药品按名称、科室按名称、
  检验/检查/处置按名称、诊断按 ICD-10 编码。

### 变更

- 管理端新增 `DataExportImport` 核心类（零依赖 CSV 生成/解析）；
  新增导入导出 API（`download_template`/`export_data`/`import_preview`/`import_confirm`）
  与前端 `import.js` 通用组件（冲突确认模态框）。

---

## [2.2.0] - 2026-08-23

### 新增

- **叫号前奏提示音**：Web Audio API 生成轻量「叮咚」提示音（零依赖、无音频文件），
  在语音朗读前播放，增强叫号辨识度（TTS 队列串行播报 + 自动播放解锁已就绪）。
- **医生工作台大屏绑定选择器**：右上角「叫号大屏」下拉框，三态展示
  （🟢 在线空闲可绑定 / 🟡 被占用置灰 / 🔴 大屏离线置灰），绑定后每 30 秒心跳保活，
  点击外部自动关闭。
- **大屏在线监测**：`screen_last_heartbeat`/`is_screen_online` 字段维护，
  管理端列表实时显示 🟢 在线 / ⚫ 离线与最后活跃时间；绑定接口对离线大屏强拦截。

---

## [2.0.0] - 2026-08-23

> 跨量级功能更新：叫号大屏系统与诊室管理（次版本号提升）

### 新增

- **叫号大屏/诊室管理系统**：
  - 新增 `clinic_rooms` 数据库表（schema 012），支持大屏 CRUD / Token 管理 / 在线状态；
  - 管理端【叫号管理】页面：按科室管理大屏（新建/编辑/删除/重置 Token/强制释放/预览/复制链接），
    大屏在线状态心跳阈值 30 秒自动判定（🟢 在线 / ⚫ 离线）；
  - 每块大屏拥有独立 `screen_token`（32 位随机十六进制），重置 Token 后旧链接立即失效。
- **免登大屏页面 `public/screen.php`**：
  - 通过 `?token=xxx` 访问，独立于登录会话，电视/平板/浏览器常驻运行；
  - 双模式自适应：**医生诊室模式**（经典大卡片排版：正在就诊/请就诊/候诊列表）和
    **医技列表模式**（队列看板 + 当前呼叫高亮置顶）；
  - 每 3 秒轮询心跳 + 数据，姓名脱敏（服务端按 `enable_mask` 配置处理）。
- **Web Speech API 语音播报（TTS）**：
  - 基于浏览器原生 `speechSynthesis`，零依赖，队列串行播报（默认 2 遍）；
  - 开机点击解锁自动播放权限（Chrome 自动播放策略绕过）；
  - 屏幕右上角静音开关（🔊/🔇），实时切换不影响显示。
- **医生站诊室绑定**：
  - 工作台右上角选择诊室（防冲突：在线空闲/已占用/离线 三种状态）；
  - 心跳保活（每 30 秒）与离岗自动解绑（管理员后台可强制释放）；
  - 后端强拦截：大屏离线时绑定被拒绝。

### 变更

- 管理端侧边栏新增「叫号管理」菜单项。

---

## [1.9.0] - 2026-08-23

### 新增

- **成组医嘱（子药/输液组方）**：
  - `order_items` 表新增 `group_no`、`is_parent`、`parent_item_id` 字段（schema v4 迁移），
    同一组内药品共享组号，子药自动继承主药的给药途径与执行频次。
  - 前端子药列表改用树状连线符（┌ 首个 / ├ 中间 / └ 末尾），视觉上明确归属。
  - 删除主药时弹出级联确认框（同时移除所有子药），避免误删组内关联项。
- **处置费用按组核算**：静注/输液类处置由「按药品数量累计」改为「按组数（group_no）计算」——
  同一瓶液体加入多种药物只产生 1 次注射/输液处置费，子药不叠加。
- **药房发药队列按组聚类**：主药下方按树状连线展示同组子药（含剂量），便于药师核对配伍禁忌。
- **打印处方单树状连线符**：处方打印中成组子药以 ┌/├/└ 树状形式呈现。

### 变更

- 提交处方时后端自动分配 `group_no`，子药强制继承主药途径与频次；
  联动处置单生成时按组数核算（`+= 1` 而非 `+= $qty`）。

---

## [1.8.0] - 2026-08-23

### 新增

- **通用检索 + 快捷创建模态框（UniversalSelector）**：`selector.js` 新增
  `Clinic.universalSelector.open()`——面向多场景复用的解耦组件，仅负责 UI 交互；
  数据源 action、是否允许就地新建、快建接口与审计追溯文案全部由调用方声明。
  `allowCreate:false` 时彻底隐藏「+ 新建项目」入口。
- **药品皮试联动（Skin Test）**：
  - 药品表新增 `need_skin_test`、`skin_test_item_id`（schema v2 迁移）；
  - 药品管理表单支持勾选「需皮试药品」并通过通用选择器关联皮试处置项目；
  - 医生开方遇需皮试药品时，弹出**阻断式确认框**（需要皮试 / 免试 / 取消），
    选择后药名自动追加 `(需要皮试)` / `(无需皮试)` 标注；
  - 选择「需要皮试」时，提交处方自动在同一请求内生成对应皮试处置单（×1）。
- **给药途径 → 计费处置自动联动**：
  - 给药途径设置支持绑定处置项目（`drug_settings.bind_disposal_item_id`）；
  - 开立注射/输液类药品时，按药品数量自动聚合生成处置单（如 静脉输液 → 静脉输液费 ×N）；
  - 联动处置单置入护士站执行队列（need_nurse=1）并站内消息提醒。
- **快捷创建处置 + 来源审计**：处置管理新增 `disposal_search`（通用检索）与
  `disposal_quick_create`（快捷创建）接口——管理员直接生效入库；非管理员提交至
  审核池并强制记录 `creation_source`（如「在维护药品[青霉素]时快捷创建皮试处置」），
  审核中心以高亮标签展示创建来源。

### 变更

- 处方提交接口 `order.php submit` 新增 `skin_choices` 入参（与明细下标对齐），
  后端对需皮试药品做硬校验（未选择方案直接拒绝），并事务化生成联动处置单。
- 处方目录（catalog）返回 `link_dicts`（皮试处置详情 + 途径绑定映射），
  供前端弹窗与联动预览使用。

---

## [1.7.0] - 2026-08-23

> 大型安全架构升级：业务实体 ID 全链路混淆加密（次版本号提升）

### 新增

- **URL 混淆密钥（防撞库遍历）**：新增 `core/IdObfuscator.php`——以管理员可重置的
  混淆密钥（settings 表 `obf_token`，首次使用自动生成）派生 AES-128-CBC 密钥与 IV，
  将就诊、申请单、报告、缴费等患者级实体 ID 加密为 22 字符 base64url 不透明串。
  链接形如 `/doctor/emr?visit_id=CSDUJCYGhFyM_LGRzEu3LA`，不再暴露可遍历的自增数字。
- **系统设置·URL 安全混淆密钥管理**：查看当前密钥 / 一键重置 / 复制；
  重置后所有旧链接即刻失效，系统功能不受影响（新链接按新密钥即时生成）。

### 变更

- **全站输入侧接入 `did()` 解码**（明文数字一律拒绝，杜绝降级绕过）：
  - 页面入口：`/doctor/emr?visit_id=` 与 `ref`（无效时提示「链接无效或已过期」）；
  - 病历：record 的 get/save/save_vitals/certificate/certificate_print/check_previous_diagnoses；
  - 开单：order 的 submit/prev_items/visit_orders/delete/print（含批量 order_ids）；
  - 收费：cashier 的 register 响应/pay_visit/cancel_visit/visit_detail/
    visit_search（就诊行 id）/pay_orders（批量 JSON）/refund_order；
  - 医生站：doctor 的 take（响应 ref_record_id 同步编码）/list 按钮 URL；
  - 护士站：nurse 的 complete/med_start/med_done/med_detail/visit_detail/
    vitals/save_vitals/nursing_list/nursing_add/patients/search；
  - 医技：lab 与 imaging 的登记/录结果/撤回 + 报告打印外链；pharmacy 发药；
  - 打印：print 的 receipt/payment/order(批量)/record/certificate/report；
  - 消息：message 列表与通知跳转的 visit_id 输出混淆串。
- **输出侧统一 `oid()` 编码**：医生列表、收费挂号管理/缴费详情、护士站患者列表
  与队列按钮、检验/影像/药房队列、就诊历史打印入口、打印中心、站内消息等
  所有 HTML onclick / 跳转 URL / JSON 回传字段。
- **前端透传改造**：混淆 ID 为不透明字符串——修复 paymanage `parseInt`、
  notify `>0` 数值判断、护士站/打印中心内联 JS 引号包裹等兼容点。
- **范围说明**：科室 dept_id 与管理端字典 id 属非患者敏感面且已有角色校验，
  保持原样；HIS 外部接口（api_key 认证）继续使用原始 ID 以保证外部兼容。

### 安全

- 明文数字 ID 访问一律返回「记录不存在 / 链接无效」，不再泄露数据存在性；
- 密钥重置后旧链接解密失败，等效整体吊销历史分享链接的能力。

---

## [1.6.73] - 2026-08-23

### 修复

- **只读双冒号修复未生效（CSS 优先级不足）**：[1.6.72] 的覆盖规则
  `.prev-sec .doc-sec-label::after`（2 类，优先级 0,2,1）低于原冒号规则
  `.emr-doc .doc-body .doc-sec-label::after`（3 类，优先级 0,3,1），
  `content:none` 被压制，「主诉：：」依旧。现改用
  `.emr-doc .doc-body .prev-sec .doc-sec-label::after`（4 类，优先级 0,4,1）
  确保覆盖生效，并经选择器优先级逐条比对验证。

---

## [1.6.72] - 2026-08-23

### 修复

- **只读病历双冒号**：诊毕只读时全部文书移入 `#docBody` 后，只读段标签命中
  `.doc-body .doc-sec-label::after { content: '：' }` 的 CSS 冒号，与 HTML 内
  已拼的冒号叠加，导致「主诉：：」「现病史：：」双冒号。现以
  `.prev-sec .doc-sec-label::after { content: none }` 覆盖，冒号仅由 HTML 提供。
- **诊毕只读出现查看者签名"幽灵行"**：未参与书写的医生打开诊毕病历，
  编辑器骨架中的「医生：XX」签名与页脚仍按当前登录医生渲染。现改为诊毕只读
  分支从 HTML 源头不渲染编辑器签名 / 页脚 / 模板按钮（各只读段自带医生签名）。
- **补开诊断证明判断统一**：「📄 诊断证明」按钮改用与打印病历按钮完全一致的
  `isRecordComplete()` 判定与提示语（已诊毕直接放行；未诊毕须本人文书完善并保存）。

### 变更

- **页脚「最近保存」靠右对齐**：`.doc-saved-at` 加 `margin-left:auto; text-align:right`，
  无论左侧元素显隐均贴右显示。
- **续写病历自动定位**：编辑态文档新增 `#myRecordAnchor` 锚点，非诊毕且本人已有文书时，
  进入病历页面自动平滑滚动到本人文书区；诊毕（含无本人病历）保持默认位置不滚动。
- **默认简易 LOGO**：未上传医院 LOGO 时侧边栏顶部显示 🏥 默认占位标识
  （`.brand-default-logo`），避免 mini 模式下顶部空白。

---

## [1.6.71] - 2026-08-23

### 修复

- **留观项目名称统一**：打印病历中「留观」统一为「是否留观」，与病历编辑页、
  只读段展示名称一致。
- **生命体征分隔格式统一**：前端编辑页 / 只读段的生命体征分隔符由「｜」改为
  「；」（中文分号），与打印病历格式一致：
  血压 125/75mmHg；心率 80次/分；脉搏 80次/分；血氧 98%；呼吸 18次/分。

### 变更

- **明确病历打印规则（isRecordComplete）**：
  1. **已诊毕** → 直接可打印：诊毕必然经过保存，不存在未保存打印的问题，
     且诊毕病历为只读展示，打印渲染该就诊全部已保存文书；
  2. **未诊毕 + 当前医生无本人文书** → 不可打印，提示先完善主诉/现病史/
     初步诊断并保存（续写医生需完善自己的续写文书后方可打印）；
     不再回退判定他人文书——他人病历与本医生的续写文书互相独立；
  3. **未诊毕 + 本人有文书** → 按本人文书完整性判定
     （首诊 = 主诉 + 现病史 + 初步诊断；续写 = 续写内容 + 初步诊断）；
  4. 就诊历史 / 患者列表入口的打印不经此前端校验，走后端 print.php 校验
     （该就诊存在已保存病历即可渲染），规则互不影响。

---

## [1.6.70] - 2026-08-23

### 修复

- **生命体征归属显示**：生命体征按录入医生归属，谁的体征显示在谁的病历段中（可编辑/只读均适用），
  未录入的医生段显示 `-`。后端 `record.php` 按 `operator` 字段匹配医生姓名取各自最新体征，
  `print.php` 打印时每段传该段医生自己的体征，不再混显。
- **只读病历缺少"是否留观"项目**：`roSegmentHtml` 中「是否留观」始终显示（否/是），
  与打印格式一致。同时补充只读段中缺失的「生命体征」和「意识状态」项目。
- **诊毕病历不应出现续写编辑器**：`renderEmrCard` 诊毕（`visit.status === 'finished'`）时，
  不再渲染 emrEditor，全部文书以只读段展示（`roSegmentHtml`），不再显示续写输入框。
- **续写/只读段体征及留观项目刷新**：`refreshReadOnlyBodies` 诊毕时直接刷新 `#docBody`，
  确保订单加载后辅助检查等数据完整显示。
- **`emrEditor.setReadonly` 空指针防护**：编辑器未渲染时（诊毕只读），`ROOT` 为空不报错。

### 变更

- 后端 `mapRecord` 增加 `vitals`（按医生归属的体征）和 `consciousness`（镜像回读）字段。
- 前端 `roSegmentHtml` 增加生命体征、意识状态，是否留观始终显示。
- 护士站与医生站 `save_vitals` 一致用 `operator` 记录录入者，供归属查询。

---

## [1.6.69] - 2026-08-23

### 修复

- **续写病历缺少生命体征书写**：续写文书（progress 模式）原来不渲染生命体征节和意识状态节，
  导致续写医生无法录入/查看患者当前体征。现改为：首诊与续写文书均支持生命体征和意识状态的
  书写与展示（生命体征为就诊级数据，续写时同样可记录）。
- **只读时生命体征项目丢失不显示**：诊毕只读状态下，续写文书因无生命体征节导致体征数据不可见。
  修复后：续写文书也显示生命体征节（只读时仅展示纯文本，不可点击编辑）。
- **诊毕后所有医生病历全部只读**：诊毕时生命体征节仍可点击编辑，违反只读语义；现修复为：
  诊毕后生命体征节不响应点击、意识状态改为纯文本展示，整份病历（含续写文书）完全只读。
- **续写段打印缺少生命体征**：续写文书打印时未传入生命体征数据，现改为每段打印均携带
  该就诊最新体征（与首诊段一致）。

### 变更

- **续写文书模式下增加生命体征/意识状态节**：`emr.js` 中 `renderEmrCard`、
  `emreditor.js` 中 `render` 的 progress 分支，及 `print.php` 中续写段打印。

---

## [1.6.68] - 2026-08-23

### 修复

- **病历编辑页打印拦截误判**：当前医生本人尚无文书时（如 admin 查看他人病历），
  `isRecordComplete()` 因空骨架被判定为未完善，导致打印病历被前端拦截（提示
  「请先在病历中完善主诉、现病史与初步诊断并保存」）。现改为：本人无文书时
  回退以该就诊流水下已存在的任意完整文书判定（打印渲染的正是该就诊全部文书）。

---

## 文档（未开版本号） - 2026-08-23

### 文档

- **本地无系统 PHP 环境的启动方案**：本机（macOS arm64）未安装 php，
  新增单文件无依赖的静态 PHP 运行时（FrankenPHP）启动方式。
  `npm run dev` / `npm run start` 改为使用 `frankenphp php-server --root public/`
  （public 为 Web 根目录，等同生产 Nginx 配置）。
- **新增 `tools/php-lint.php` 语法检查脚本**：依赖系统 php 的 `npm run lint`
  改为借助 FrankenPHP 内置 tokenizer 校验全部 PHP 文件，无需系统 php。
- **新增 `AGENTS.md`**：记录本地运行环境与「每次修改同步 CHANGELOG/README 并
  自动 commit + push 到远程」的自动化约定，供后续协作维护遵循。

---

## [1.6.67] - 2026-08-22

### 变更

- **续写文书隐藏「病历模板」入口**：病历模板仅首诊文书支持，
  续写为承接性记录不提供模板套用，页眉左上角按钮在续写模式下不再渲染。
- **续写部分与原病历之间虚线分隔**：编辑页文档主体内，本人续写区
  与上方 / 下方他人只读段之间以虚线隔开（容器为空时自动不显示），
  文书边界一目了然。
- **他人文书只读段签名统一只读文字样式**：段末医生签名改用灰色
  弱化字重（与该段灰色只读正文一致），与本人可编辑文书的黑色签名
  形成视觉区分。

---

## [1.6.66] - 2026-08-22

### 修复

- **诊断证明内容固化（快照化，法律文书不可变性）**：修复续写场景下
  开具 / 查看诊断证明时摘要漂移的问题——此前概要实时取「最新文书」
  投影，B 医师开具后主诉现病史显示首诊内容、初步诊断却变成 B 的
  续写诊断，补打时三者又全部变为续写病历内容。现改为**快照机制**：
  - `certificates` 表新增 `chief_complaint` / `present_illness` /
    `initial_diagnosis` 三列（schema v6 迁移，兼容旧库平滑升级）；
  - **开具瞬间固化**：以该挂号流水【首诊文书】为锚点投影主诉 /
    现病史 / 初步诊断并写入证书（无结构化病历的历史就诊回退旧镜像）；
  - **查看与打印均只认快照**：开具弹窗、certificate_print 打印、
    print.php 补打入口三处统一优先读取证书快照——无论谁开具、
    谁补打、后续有多少次续写修改，内容完全一致；
  - 历史证明（无快照）保持原行为不变；
  - 未开具时的预览改为「将固化内容」预览（与写入快照同源），所见即所冻。

---

## [1.6.65] - 2026-08-22

### 变更

- **编辑页「页眉」与「病历主体」分离，仅主体随文书只读**：
  此前续写场景下 B 医师看到的 A 首诊只读文书把患者信息区一并锁死，
  无法修改初复诊、点击更新用户资料。现将文档拆为两个区域——
  - **页眉区（公共、始终可交互）**：医院抬头 / 病历标题 / 患者信息
    两栏网格（含初复诊下拉）/ 条形码，属于整次就诊，任何接诊医生
    都可正常操作（点击姓名头像改资料、切换初复诊）；
  - **病历主体区（按文书归属只读）**：他人文书以虚线标注带 +
    灰色只读正文呈现（前序在上、后序在下、时间正序接续），
    本人文书为可编辑器；谁书写谁签名不变。
- **续写文书恢复完整页眉**：页眉独立后，续写医生与首诊医生看到
  同一套可交互抬头（含条形码），下方直接接自己的续写编辑器；
  「病历模板」按钮对续写文书同样可用。
- 只读段渲染重构：旧「独立只读卡片」模式退役，改为文档内嵌只读段
  （#roBefore / #roAfter 容器局部刷新），开单项目加载完成后仅重刷
  这两个容器，编辑器与未保存内容零丢失。

---

## [1.6.64] - 2026-08-22

### 修复

- **打印病历「记录时间」统一为首诊医师首次保存时间**：此前多医生
  连续文书取的是最后一段（最近续写）文书的首次保存时间。现由
  打印接口统一传入首段文书（首诊）的 `created_at`，整份打印文档
  页脚记录时间固定为首诊首次保存时刻，不随续写改变；单文书 /
  历史数据行为不变。

---

## [1.6.63] - 2026-08-22

### 新增

- **编辑页页脚新增「最近保存」时间**：病历文档页脚右侧在医生签名
  之后显示最近一次保存时间（每次保存刷新，仅供医师自己参考）；
  该时间仅存在于编辑页面，打印病历不输出。

### 变更

- **「记录时间」与「最近保存」语义分离**：左下角记录时间为该文书
  首次保存时间（不变），右下角最近保存随每次保存实时更新，
  两个时间各司其职，不再混淆。

---

## [1.6.62] - 2026-08-22

### 变更

- **打印续写承接头三段定位并整行加粗**：虚线下一行改为
  「日期 时间（靠左）｜ 续写病历（居中）｜ 科室（靠右）」三段式版式
  （grid 三列等宽，保证「续写病历」四字严格居中），整行加粗，
  观感与纸质病历续写页惯例一致。

---

## [1.6.61] - 2026-08-22

### 变更

- **开单详情弹窗：非本人开具的单子直接隐藏删除 / 毁方按钮**：
  此前对非本人单子渲染一个点击仅弹提示的占位按钮，容易引起
  「可以删但被拦」的误解。现非本人单子不渲染任何删除 / 毁方控件
  （后端 delete 硬拦截继续兜底）；本人单子的按钮显隐规则不变
  （未缴费 / 已退费可删，已进入执行流程的点击提示到收费处退费）。

---

## [1.6.60] - 2026-08-22

### 修复

- **病历「记录时间」固定为首次保存时间**：记录时间语义为该文书
  首次保存的时刻——首诊即首诊医生首次保存时间、续写即该次续写首次
  保存时间；后期无论修改保存多少次均不再变化。同步修正三处：
  编辑页病历左下角记录时间（此前每次保存都会刷新为当前时间）、
  保存成功后的本地回显、打印页脚的记录时间（改取 `created_at`，
  旧数据无值时回退 `updated_at`）。

---

## [1.6.59] - 2026-08-22

### 修复

- **前序【首诊】病历只读版式补回右上角条形码**：上一轮页眉归首诊
  改造时，只读首诊文书漏带了页头右上角的门诊号条形码。现原样补回
  （Code 128 SVG + 流水号文本，与编辑页 / 打印完全一致）；
  左上角「病历模板」按钮在只读文书中继续隐藏。

---

## [1.6.58] - 2026-08-22

### 变更

- **打印续写段承接头版式**：由「分割线 + 病历续写（续写时间 /
  续写医生）」改为更贴近纸质病历的书写惯例——一条**虚线**分割上下
  文书，虚线下的一行左右两端对齐：左端为该次续写的日期时间
  （如 `2026-08-22 11:15`），右端为 `续写病历　科室`（当前就诊科室），
  随后直接接「病历续写：病史同上……」等续写正文。

---

## [1.6.57] - 2026-08-22

### 修复

- **开单详情弹窗误报「网络请求失败」**：`viewOrderFlow` 为全局函数，
  上一版在其回调中直接调用了模块私有的 `myDoctorId()`，运行时
  `ReferenceError` 被 ajax 层 catch 吞掉后统一提示为网络错误——
  任何医生（包括开单医生本人）点开详情都必然复现。现改经公开 API
  `Clinic.emr.isMyOrder(o)` 判断归属，详情弹窗恢复正常打开，
  非本人开单仍不显示可用的删除 / 毁方按钮。
- **前序病历只读区缺失已开项目**：只读区渲染先于已开项目列表加载，
  渲染时项目缓存还是空数组，导致 A 医生的处方 / 检验 / 检查 / 处置
  没有出现在 A 的只读病历中。现已改为开单列表加载成功后重渲只读区，
  辅助检查与门诊处置按各文书医生本人的开单归属正常显示。

### 变更

- **多医生病历严格按时间正序接续排列**：此前所有他人文书一律堆在
  当前医生编辑卡上方——B 医师续写完毕后 A 医师再打开，B 的续写
  反而排在首诊上方。现按创建顺序拆分：本人文书之前的他人文书在
  上侧只读区、之后的在下侧只读区（编辑卡与已开项目卡之间），
  后续 C / D 医师的文书依次向下接续，符合临床时间线。

---

## [1.6.56] - 2026-08-22

### 变更

- **电子病历打印重构为「一份连续文书」**：多医生接诊下不再每份病历
  各带一套页眉页脚，同一挂号流水的全部文书合并输出为一张连续 A5 文档——
  - **页眉归首诊**：医院抬头 / 病历标题 / 条形码 / 患者信息网格仅在
    首段渲染；续写段以「末尾分割线 + 病历续写（续写时间 / 续写医生）
    承接头」开始，直接承接首诊正文接着书写，消除重复页眉的空间浪费；
  - **医生签名紧跟各段正文右下角**：签名改用独立类 `.print-rec-sign`
    （刻意避开 A5 分页器的页脚识别集合），签名随段出现在本段正文
    末尾右下角，不再沉到整页底部；页脚（记录时间 / 打印时间）整份
    文档仅输出一次，取最后一段的记录时间；
  - **已开项目快照按医生过滤**：打印中辅助检查 / 门诊处置仅取该段
    文书医生本人开具的项目；保存病历时入库的打印文本快照同步按
    当前医生过滤——谁开单归属谁的病历。

---

## [1.6.55] - 2026-08-22

### 变更

- **病历页眉归首诊文书（编辑页与只读查看区）**：
  - 续写编辑器不再重复渲染医院抬头 / 病历标题 / 患者信息网格 /
    条形码——顶部仅保留一条「病历续写」标识带（续写医生 / 工号职称 /
    续写开始时间），下方分割线后直接从「病历续写」正文开始，
    承接首诊文书接着书写，消除无意义页眉的空间浪费；
  - 前序【首诊】病历只读区升级为完整文档版式（复用所见即所得的
    `emr-doc` 版面：医院抬头 + 标题 + 只读患者信息网格），
    与打印版式一致；前序【续写】病历只读区保持轻量标注条 +
    正文，不带页眉。

---

## [1.6.54] - 2026-08-22

### 修复

- **辅助检查 / 门诊处置按医生归属显示（多医生接诊项目归档）**：
  此前病历正文的已开项目自动段取该就诊全部开单，A 医生开的检验 /
  检查 / 处置 / 处方错误出现在 B 医生的续写文书中。现按开单医生
  `doctor_id` 过滤——
  - 编辑器自动段：仅渲染当前医生本人开具的项目；
  - 前序病历只读卡片：辅助检查 / 门诊处置按该文书医生的本人开单渲染；
  - 「已开项目与流程」列表仍展示就诊全部开单，但每张卡片标注
    开单医生（多医生场景下非本人开单高亮提示归属）。

---

## [1.6.53] - 2026-08-22

### 新增

- **开单删除 / 毁方按开单医生鉴权（多医生接诊权责闭环）**：
  - `visit_orders` 列表接口补充返回 `doctor_id`，前端据此判定按钮可见性；
  - `/api/order?action=delete` 后端**硬拦截**：仅开单医生本人可删除 /
    毁方自己开具的处方或申请单，其他医生即使绕过前端强制提交也一律
    拒绝（提示开单医生姓名）——谁开单谁负责。

---

## [1.6.52] - 2026-08-22

### 变更

- **电子病历打印适配多文书堆叠输出**：`/api/print?action=record` 由
  「仅取最新一条病历」改为该挂号流水下全部文书按创建时间升序逐份渲染
  （分页器自动跨页），每位医生一份独立 A5 文档、各自签名与记录时间
  （谁书写谁签名，与编辑页只读查看区一致）：
  - 续写文书标题显示「门诊/急诊电子病历（病历续写）」，置顶输出
    「病历续写」内容节；主诉 / 现病史 / 主要症状归首诊文书不再重复出现；
  - 生命体征属就诊级数据，仅在首份文书上展示；意识状态 / 初复诊按各
    文书医生本人的镜像行回读；
  - 无结构化病历的历史就诊仍回退旧 records 扁平数据单文档渲染，
    兼容行为不变。

---

## [1.6.51] - 2026-08-22

### 新增

- **诊断模态框跨医生引用机制**：医生在诊断选择模态框中点击添加某个
  诊断条目时，自动与该挂号流水下前序其他医生的诊断比对
  （编码精确匹配优先、名称完全相同兜底，取最近一次）：
  - **命中** → 弹出 Confirm 确认框：「XX 医生此前已添加过该诊断
    【诊断名称】（ICD-10），是否直接引用？」并展示原部位 / 备注 /
    疑似标记；
    - 点击【引用】：原样拷贝追加到当前医生右侧已选诊断列表，
      并自动弹出二级模态框供修改部位、备注、是否疑似；
    - 点击【取消】：仍作为普通新诊断走常规添加流程；
  - **未命中或本人已选过同编码诊断** → 不提示，照常添加。
  - 编辑器引擎新增 `setPrevDiagnoses(list)` 上下文注入接口，
    由病历加载完成后自动装配（服务端 `check_previous_diagnoses`
    查重接口同步可用，双通道互为备份）。
- **诊断排序与主诊断联动保持不变**：右侧已选诊断列表支持上移 / 下移
  调序，排在第 1 位的诊断即当前医生的主诊断；保存后仅更新本文书
  `primary_icd10` / `primary_diagnosis`，前序医生的主诊断与文书原样保留。

---

## [1.6.50] - 2026-08-22

### 新增

- **接诊视图动态渲染（场景 A / 场景 B，1:N 接诊前端落地）**：
  - **场景 A**：本次挂号无任何病历时照常渲染标准首诊编辑器
    （`record_type = initial`，主诉 / 现病史等全量模块）；
  - **场景 B**：前序已有其他医生病历时，页面分上下两区——
    - 上部【前序病历查看区】：以只读视图渲染前序医生的全部文书
      （灰色只读背景、纯文本不可编辑、禁用选中），顶部标注
      「接诊自：XX 医生（工号 / 职称），就诊时间」，并带首诊 / 病历续写
      类型徽标与该文书主诊断摘要；展示格式与打印规则一致
      （续写内容置顶，体格检查 / 辅助检查 / 处置空节显示「-」）；
    - 下部【当前续写编辑区】：生成当前医生专属的续写编辑器
      （`record_type = progress`，文档标题追加「（病历续写）」），
      顶部必填项「病历续写」并配【病史同上】快捷填入按钮；
      既往史 / 过敏史自动载入患者主表全局最新值；
      下方为当前医生专属的体格检查、初步诊断、辅助检查、门诊处置模块。
  - **续写编辑器模块裁剪**：主诉 / 现病史 / 主要症状 / 生命体征 /
    意识状态归首诊医生文书所有，续写文书不再重复录入
    （结构化编辑器引擎新增 `mode` 参数区分首诊全量 / 续写精简模块）。

### 变更

- **保存与开单前置校验按文书类型分支**：续写文书必填「病历续写内容 +
  初步诊断」（与后端同规则）；开检验 / 检查 / 处置 / 处方与打印病历的
  完整性判定对续写文书改查续写内容；诊断证明本地预校验对续写文书放行，
  由服务端投影（含前序文书回退）统一判定。

---

## [1.6.49] - 2026-08-22

### 新增

- **前序诊断查重接口（跨医生引用第三步）**：新增
  `/api/record?action=check_previous_diagnoses`，入参 `visit_id`
  （兼容规格中的 `reg_id` 别名）+ `keyword`（诊断名称或 ICD-10 编码，
  留空返回全部）。检索该挂号流水下**前序其他医生**已添加过的诊断，
  命中项返回医生姓名、原诊断名称、原 ICD-10 编码、部位、备注与疑似
  标记及记录时间——前端据此弹出「是否直接引用」确认框。
  匹配规则为名称 / 编码包含关键词且不区分大小写
  （兼容未启用 mbstring 的运行环境）。

---

## [1.6.48] - 2026-08-22

### 新增

- **病历保存接口支持多医生续写（1:N 接诊第二步）**：`/api/record?action=save`
  - **文书类型服务端权威判定**：本人已有文书则维持原类型（草稿续存不重写
    历史）；本人无文书时，流水下已有他人病历即判为 `progress`（续写）并
    关联 `parent_record_id` = 流水内最近一条他人病历，否则为 `initial`
    （首诊）。新增记录写入 `record_type` / `parent_record_id`，
    同一挂号流水天然形成「首诊 → 续写」文书链。
  - **按文书类型分支校验必填**：首诊要求 主诉 / 现病史 / 初步诊断；
    续写要求 病历续写内容（`emr_data.progress.content`）/ 初步诊断。
  - **主诊断独立抽取不变**：仍取当前医生诊断列表第 1 项写入本文书
    `primary_icd10` / `primary_diagnosis`，各医生文书互不影响。
  - **旧 records 镜像兼容**：续写文书的现病史投影为空时回填续写内容，
    就诊历史 / 转科引用等既有消费方照常可读。

### 变更

- **打印文本支持「病历续写」节**：`emr_print_text` 置顶输出续写内容
  （仅续写文书非空时出现）；顺带修复过敏史为结构化数组时打印文本出现
  「Array」字样的潜在缺陷（统一经 `emr_al_text` 格式化）。

---

## [1.6.47] - 2026-08-22

### 新增

- **病历加载接口多医生数据（1:N 接诊第一步）**：`/api/record?action=get`
  新增返回三组数据，支撑「同一次挂号流水、多位医生各自文书」：
  - `records_history`：该挂号流水下已有的全部病历列表（按创建时间升序），
    每条含医生姓名 / 工号 / 职称、文书类型（`initial` 首诊 / `progress` 续写）、
    主诊断（ICD-10 + 名称）、完整结构化 `emr` 数据与记录时间——
    前序医生的病历对后续接诊医生全只读展示，谁书写谁签名；
  - `current_doctor_record`：当前登录医生本人在该流水下已保存的草稿 / 病历
    （无则 `null`），用于回显继续编辑，绝不回退他人病历；
  - `global_patient_info`：患者主表最新的既往史（承认 / 否认 + 详情）与
    过敏史，任何医生保存后全局同步，供本次及后续就诊实时调用。

### 变更

- **多医生接诊数据基座核对就绪**（此前迭代已完成、本次接入确认，
  规格对应关系如下）：
  - **`patient_records` 表结构（medical 库 schema v5）已具备全部所需字段与索引**：
    `record_type`（`initial` 首诊 / `progress` 续写，默认 `initial`）、
    `parent_record_id`（续写关联前序病历 id，默认 0）、`primary_icd10` /
    `primary_diagnosis`（各文书独立主诊断）、`emr_data`（完整结构化 JSON）；
    并已建立 `(visit_id)`（即规格中的 reg_id 挂号流水）、`(patient_no)`
    与 `(visit_id, doctor_id)` 三个索引；存量病历已回填为 `initial` 首诊；
  - **患者长期档案字段以既有列实现规格语义**：`patients` 主表已有
    `past_history_type`（「承认 / 否认」，其中「否认」等价于规格中
    `past_history_denied = 1`）、`past_history_detail`（详细既往史）、
    `allergies`（最新过敏史）三列——不再重复建列，保持单一事实来源；
  - **保存接口已按 `(visit_id, doctor_id)` 区分新增 / 修改**：同一医生
    在同一挂号流水中重复保存为更新本人文书，不同医生各自插入独立
    文书（谁书写谁签名）；保存后同一事务内同步患者主表全局既往史 /
    过敏史，跨就诊自动调用、以最新修改为准。

---

## [1.6.46] - 2026-08-22

### 新增

- **检查申请单按检查分类自动拆单**：一次开具多个检查项目时，按 `exam_items.category`（管理员在检查项目管理中自定义，如 CT / MR / DR（数字化X线）/超声等）自动拆分为多张申请单；同分类合并为一张、不同分类拆分为不同申请单。前台一次开具 `CT+MR` 将自动产生 2 张独立申请单，开 4 类 → 打印时即显示 4 张。
- **检查申请单标题动态化**：打印标题由固定「检查申请单」改为按分类动态显示，如 `CT申请单` / `DR（数字化X线）申请单`——自定义的分类名称是什么就显示什么，更符合「不同检查需前往不同地点分散执行」的实际流程。站内消息提醒标题同步动态化。
- **已开项目与打印预览同步拆分**：拆分后在「已开项目与流程」中即显示为多条独立记录（等同于单独开了多张），打印预览/打印一次展示多张 A5 申请单（分页器已支持多文档聚合分页，页码跨单连续）。

### 变更

- **申请单号规则不变**：每张拆分出的检查单独立生成单号，仍遵循原有 `JC + YmdHis + 2位随机` 规则并循环查重，保证与历史单号不重复且互不撞号；同组内的主/子项目序号局部重编，对外保持一致。

---

## [1.6.45] - 2026-08-22

### 变更

- **诊断证明初步诊断去掉重复的 ICD-10 括号编码**：诊断名称本身已含
  编码前缀（如「M51.9 腰椎间盘突出」），开具/查看弹窗与 A5 打印模板
  不再以括号追加 ICD-10 编码（原先显示为
  「M51.9 腰椎间盘突出（M51.9）」，重复冗余）。

---

## [1.6.44] - 2026-08-22

### 修复

- **诊断证明查看弹窗细节**：
  - 修复「开具时间」与「主诉」之间缺少行间距、两行贴在一起的问题
    （概要区改为逐行构建：首行无边距、其余行统一 mt-4，间距不再遗漏）；
  - 医生建议只读框去掉右下角拖拽调整大小的手柄（resize:none），
    并明确灰底 + 默认光标样式，呈现为纯展示的不可编辑状态。

---

## [1.6.43] - 2026-08-22

### 变更

- **病历编辑页「已开具诊断证明」可点击**：患者信息栏的已开具文案
  （含诊毕/未诊毕两种状态）统一改为可点击，点击打开诊断证明只读
  预览模态框（打印仍取服务器存档数据）；文案更新为
  「已开具诊断证明（点击查看）」。
- **诊断证明查看弹窗优化**：去掉「（只读）」提示文字；
  证明号与开具时间分行显示；医生建议改用 disabled 文本域样式，
  与可编辑开具弹窗视觉一致。
- **必填项标识由星号改为红色文字**：主诉/现病史/初步诊断的小节标签
  不再追加 * 星号，改以红色字体标识必填，视觉更直观整洁。
- **病历正文固定词加大加粗**：主诉/现病史/既往史等小节标签字号
  13px → 14px（保持加粗）。

---

## [1.6.42] - 2026-08-22

### 修复

- **自动打印偏好实时生效**：勾选「自动打印」后原先仅异步写服务器，
  页面内存态与 DOM 属性未同步——同一页面再次弹出预览仍按旧值执行，
  需刷新页面才生效。现勾选即同步内存态与 body 属性，
  并弹出「已开启/已关闭自动打印」提示；【个人信息】页开关同样即时生效。

### 新增

- **诊断证明升级为标准 A5 单据（版式对齐申请单）**：
  - certificates 表新增证明号 cert_no（schema v3→v4 自动迁移，
    存量证明按 ZM+开具日期+4位序号回填）；
  - 证明号命名规则 ZM+年月日时分秒+2位随机，与申请单号
    （JY/JC/CZ/CF/DD）同源且前缀互不冲突；
  - 版式：抬头右上角证明号条形码、患者信息两行（第二行含证明号）、
    主诉/现病史/初步诊断/医生建议分节、签名右下角、
    左下角开具时间/右下角打印时间；打印统一走 A5 分页器。
- **已开具的诊断证明支持只读查看**：再次点击诊断证明时提示已开具的
  同时打开只读预览弹窗（概要含证明号/开具时间、医生建议只读展示），
  右下角按钮显示为「打印」。安全边界：只读区域仅是前端展示，
  真正打印由 certificate_print 从服务器重新渲染存档数据，
  篡改前端内容不影响纸质输出。

### 变更

- **病历编辑区域禁止右键菜单**：#emrCard 范围内除输入类控件
  （输入框/文本域/下拉/富文本可编辑区，粘贴等操作不受影响）外
  一律屏蔽右键；页面其他区域不受影响。

---

## [1.6.41] - 2026-08-22

### 修复

- **打印输出重叠/错位**（v1.6.40 模态框分层改造的遗留）：预览层拆分出的
  `.pp-scroll`（绝对定位+固定高+内部滚动）与 `.pp-backdrop`（遮罩+虚化）
  未同步编写打印还原规则——浏览器不会在固定高度的滚动容器内做分页，
  导致多张 A5 纸内容挤在一页重叠、遮罩灰边也被打出。
  现已在 @media print 中还原：遮罩层直接移除、滚动层恢复普通文档流，
  分页器生成的每张 sheet 恢复各自独占一页。

> 说明：本系统打印为「所见即所得」架构——预览与打印共用同一份 DOM，
> 打印靠 @media print 规则把预览结构变换成纸张流；
> 因此任何预览 DOM 结构调整都必须同步其打印还原规则。

---

## [1.6.40] - 2026-08-22

### 修复

- **打印预览右侧工具栏跟随滚动、无法固定悬浮**：根因是背景虚化的
  `backdrop-filter` 写在了预览层容器上——按 CSS 规范，带
  filter/backdrop-filter 的祖先会把后代 `position:fixed` 的定位基准
  降级为该祖先（即滚动容器），固定随之失效。
  现将预览层拆为三层：`.pp-backdrop`（遮罩+虚化）/ `.pp-scroll`
  （内容滚动）/ 工具栏（三者平级），工具栏祖先链无任何 filter，
  `fixed` 恢复相对视口定位——滚动单据时工具栏纹丝不动。

---

## [1.6.39] - 2026-08-22

### 变更

- **自动打印偏好保存到服务器**：由浏览器 localStorage 迁移为
  服务端用户偏好（users 表新增 print_auto 列，v3→v4 自动平滑迁移），
  通过 `/api/auth action=print_auto` 存取——跟随账号在所有设备生效；
  打印预览工具栏与【个人信息】页的开关均改为读写服务器。
- **打印工具栏改为纵向悬浮控件**：自动打印/关闭/打印三个按钮
  纵向排列，固定悬浮在预览层右侧（top 与预览卡片顶边齐平的 64px 处、
  右侧留 20px），不随单据内容滚动。

---

## [1.6.38] - 2026-08-22

### 变更

- **打印预览改为模态框样式**：不再整屏铺满——半透明遮罩 + 背景虚化
  （backdrop-filter），单据以居中卡片呈现，视觉焦点集中在待打印内容上；
  A5 多页病历在遮罩上逐页平铺，凭条为白色小卡片。

### 修复

- **自动打印「关不掉」的死角**：开启自动打印后预览会打印完自动关闭，
  导致没机会取消勾选。现于【个人信息】页新增「打印偏好」卡片，
  提供始终可达的总开关（与预览工具栏勾选项共用同一存储键、按账号记忆）；
  预览工具栏勾选框提示文案同步指引。
- **凭条黑边四周增加等宽白边缓冲**：预览与纸质输出一致——凭条黑边框
  外围留 4mm 白边（打印时以更高优先级保留该内边距），
  动态纸张尺寸改为量取「白边+黑边」整体，四周距离完全相等。

---

## [1.6.37] - 2026-08-22

### 新增

- **打印预览「自动打印」选项（偏好跟随用户保存）**：预览层工具栏
  新增「自动打印」勾选项——勾选后每次弹出预览会自动调起系统打印，
  打印对话框关闭后预览层自动收起；不勾选则保持手动点击打印。
  偏好按登录账号持久化（localStorage 绑定用户 ID），换账号互不影响。

### 变更

- **打印工具栏改为固定悬浮右上角**：关闭/自动打印/打印按钮不再
  随单据内容滚动，长单据（多页病历）滚动到任意位置都能直接操作。
- **打印预览层禁用右键菜单**，避免误操作打断打印流程。
- **凭条打印纸张动态生成**：窄条凭条打印时按凭条实际渲染尺寸动态
  注入 `@page { size: 宽mm 长mm; margin: 0 }`——浏览器打印对话框中
  的纸张即凭条本身，消除默认 A4 纸上的大片空白区域。

---

## [1.6.36] - 2026-08-22

### 新增

- **开具诊断证明与补开共用同一套弹窗代码**：开具页面现在同样展示
  病历概要（主诉/现病史/初步诊断），两者唯一区别是模态框标题
  （「开具」/「补开」）与就诊 ID 来源——开具固定取当前编辑页的
  本次就诊，补开取就诊历史中目标那一次的就诊，各自引用各自的病历，
  绝不混淆；开具成功后仍会刷新本页的诊断证明入口状态。

### 变更

- **凭条打印改用窄条纸张**：挂号凭条/缴费凭条的打印预览与输出
  不再是一张留白巨大的大纸——预览纸张宽度收缩为凭条实际宽度
  （以四周黑边框为准）、长度随内容自然延伸；打印按原尺寸居中输出。
  涵盖挂号缴费、补打凭条、缴费管理、消息中心等全部凭条入口。

---

## [1.6.35] - 2026-08-22

### 修复

- **就诊历史补开诊断证明误报「病历不完整」**（结构化病历升级残留缺陷）：
  补开流程读取的是扁平字段（主诉/现病史/初步诊断），而 get 接口在
  结构化改造后只返回 emr 对象、不再返回这些投影字段，导致已完善保存的
  病历也被判定缺少主诉现病史。现已在 get 接口补齐三个扁平投影字段：
  优先由结构化 emr 投影生成（初步诊断自动附带 ICD-10 编码），
  为空时回退 records 镜像表兼容未结构化的历史病历。
- **病历页患者信息头内边距**：#emrHeader 上内边距 14px → 0，
  患者卡与顶栏贴合同步收紧。

---

## [1.6.34] - 2026-08-22

### 挂号管理

- **未缴费不再显示补打凭条**：凭条是缴费凭证，仅已实际缴费的状态
  （已缴费/就诊中/就诊完毕）提供补打；待缴费/已取消/已退费不再显示。
- **待缴费支持继续缴费**：待缴费记录新增「继续缴费」按钮，
  确认后完成挂号费缴纳并自动打印凭条、刷新列表（原先只能取消）。

### 病历

- **意识状态默认清醒 + 回显修复**：去掉「请选择」空选项，
  默认选中「清醒」；修复保存后刷新丢失的缺陷——根因是意识状态/初复诊
  保存在 records 镜像表，而读取接口只查结构化表从未回读，
  现已在 get 接口回读镜像表数据。
- **患者头吸附位校准**：按实测调整——患者头吸附线取内容区顶部（top:0），
  右侧看诊操作工具栏 top 调整为 topbar高度+20px，两者顶部对齐且滚动无缝隙。
- **科室选择改为「每次登录必选」**：多科室医生每次登录都必须先选科室
  才能进入工作站；记忆键同时绑定账号 ID 与 PHP 会话 ID
  （登录/退出均会重新生成会话 ID），记忆只在本次登录过程中有效——
  今天普通门诊、明天专家门诊互不影响。

---

## [1.6.33] - 2026-08-22

### 新增

- **患者资料保存后自动刷新病历头部**：patient.js 内置轻量发布/订阅，
  「修改患者信息」保存成功后广播更新事件；病历编辑页订阅后仅重建
  顶部患者信息卡与文档内患者信息区两处（初复诊下拉保留医生当前选择），
  **不做整页刷新**——病历正文、签名、已开项目等未保存内容零丢失。

- **医生工作站首次登录必选科室**：多科室医生每次新登录（新会话/新标签页/
  换账号）都弹出科室选择弹窗；单科室直接进入、未关联科室给出文字提醒。
  此前服务端 current_dept_id 会跨登录自动恢复导致跳过弹窗；
  现改为「本账号 + 本标签页会话」内才记忆所选科室（绑定登录账号）。

### 修复

- **病历患者头吸附缝隙漏字**：患者头吸附在 top:16px 后，其上方
  [0,16px) 区域无元素遮挡，正文滚动时从该缝「漏出」。新增随卡片
  吸附的同色遮盖带（::before，向上延伸 20px）封死缝隙，
  高出滚动容器的部分被自然裁剪，无副作用。
- **打印预览与正式打印分页不一致**：屏幕预览纸张内容区约 190mm 而
  打印纸张锁定 187mm，临界内容「预览完整、打印末行被裁半」。
  分页可用高度基准统一为 184mm 并叠加 14px 安全余量，
  预览与打印逐行一致，宁可页底留白也不截断任何一行。
- **框架**：纳入 .app 固定视口高度改动（内容区成为唯一滚动容器），
  病历页 sticky 吸附定位从此可预期。

---

## [1.6.32] - 2026-08-22

### 变更

- **病历编辑页患者信息头吸附位上移**：滚动吸附后与右侧「看诊操作」
  工具栏顶部齐平（top 0 → 16px）。原先负上边距参与 sticky 吸附基准
  计算，导致吸住后卡片顶部残留约一行高的空隙；现上边距归零，
  吸附位置由 top 精确控制，滚动时不再浪费顶部空间。

### 修复

**打印页眉压缩与条形码对齐（保持医院名称居中、字号不变）**
- **页头行距压缩**：医院名称固定行高 30px、第二名称/标题的上下边距
  收紧（10px→4px、14px→9px），顶部留白明显减少、正文可用空间变大；
  同时将 A5 紧凑规则的作用域由 `.print-record-doc` 改为 `.print-area`——
  分页器重组 DOM 后原包裹层不存在，紧凑规则此前在分页输出中整体失效、
  页头行距回弹偏大的问题一并修复。
- **条形码与医院名称顶部齐平**：病历编辑页 / 打印预览 / 病历打印 /
  申请单打印统一按「半行距 + 字形上留白」下移对齐
  （编辑器 top 26→30px、移动端 18→22px；打印 top 0→4px），
  修正条形码略高于医院名称的问题。
- **长单号条形码限宽**：条形码 SVG 增加 `max-width:38mm`，
  超长申请单号自动整体等比缩小，不再压到医院名称。
- **多页病历精简页眉**：分页后第 2 页起患者信息参考急诊病历样式
  压缩为两行（姓名/性别/出生日期/年龄 + 患者ID/初复诊/科室/联系方式），
  标题仍为「门诊电子病历」，缩短重复页眉占用的版心高度。

---

## [1.6.31] - 2026-08-22

### 修复

**打印模板回归修复（根因：v1.6.30 A5 分页器重组 DOM 后丢失 `.print-record-doc` 包裹层，
所有以它为祖先选择器的样式在分页后集体失效）**
- **医生签名回到右下角**：`.print-record-sign` 右对齐样式的作用域由
  `.print-record-doc` 改为 `.print-area`（分页前后均存在），分页后不再回退左对齐。
- **开单时间靠左 / 打印时间靠右**：`.print-record-foot` 两端对齐样式同步改作用域，
  分页后两个时间不再连在一起挤在左侧。
- **病历条形码回到页面右上角**：分页后 `.print-record-doc` 定位锚点消失，
  绝对定位的条形码一路冒泡到 `position:fixed` 的预览层（网页右上角）；
  改为锚定每页 `.a5-head`（`position:relative`），每页页头右上角各一枚，
  与医院名称顶部齐平。
- **提示词沉底**：分页器页脚组识别支持 `print-note` 类（含多类名节点），
  「请凭本申请单至相应科室登记执行。」「请凭本处方单至药房取药」「温馨提示」
  等随签名一起固定在页面底部，显示为医生签名上一行、靠左对齐，
  不再紧跟表格/列表。
- **A5 病历真实分页**：病历正文原先整体包在一个节点里，分页器按整节点
  分配导致内容超页时永远不分页、直接被裁切；现改为每个小节独立节点，
  可在小节边界翻页。另修正逐节点测高不含外边距导致的累计低估
  （末页底部被裁数像素），并对超过整页版心的单个超高小节增加
  `.a5-overflow` 兜底（放开该页裁剪交由浏览器自然续页，不丢内容）。

### 新增

- **处方单 / 检验检查申请单右上角条形码**：与电子病历同款式样，
  编码内容为处方单号 / 申请单号，打印预览与输出每页页头右上角显示。

---

## [1.6.30] - 2026-08-22

### 变更

- **A5 单据真·固定版式（分页器）**：打印预览与输出改为手动分页——
  每一页都是固定 A5 纸张，三段式布局：页眉（医院抬头/标题/患者信息）
  固定顶部、页脚（签名/时间行）固定底部 + 「第 X 页 / 共 Y 页」页码，
  正文居中弹性区域，内容少时中间留白、内容多时自动拆分为多张完整单据。
  屏幕预览按 148×210mm 纸张逐页呈现，所见即所得；打印输出每张 sheet
  精确占满一页物理纸张（187mm 高度锁定，防舍入溢出）。
- 适用范围：病历、处方笺、检验/检查/处置申请单等所有 A5 单据；
  付款凭条保持窄条随内容拉长形态。

---

## [1.6.29] - 2026-08-22

### 修复

**电子病历体验修复**
- **患者信息头常驻与遮盖**：sticky 吸附顶栏下方、负边距铺满实体底色防透字；
  修复 sticky 偏移双重叠加导致的定位偏下。
- **完整性校验失效**：开单/打印病历的「请先完善主诉现病史」校验改为读取
  结构化 patient_records（兼容旧 records），已保存病历不再误报。
- **旧格式病历加载 500**：emr_merge_defaults 对标量数据递归导致致命错误；
  加类型守卫并新增 emr_normalize 统一归一化旧版纯文本 allergies 字段。
- **转科科室分类错误**：目标科室接口补充 type 字段，急诊/门诊 Tab 恢复正确。
- **意识状态样式**：改用 Word 式内联虚线下拉，与病历字段统一。
- **节名重复**：节标签自带冒号，删除编辑器内重复静态前缀。
- **空值选项**：既往史/是否留观下拉移除空占位项；过敏史同步改为
  「否认/承认」格式（默认否认，承认后显示填写框，后端强制清洗）。

**打印系统重构**
- **「N 张一模一样的单据」根因修复**：预览层 position:fixed 在 Chrome 打印时
  于每一页重复渲染；页数由背后隐藏布局高度决定。新方案将应用其余部分
  display:none 彻底移出布局，页数 = 真实内容分页数。
- **右侧截条**：A5 内容宽度收敛至可打印区（128mm），
  @page 页边距统一 10mm，正文占满可打印宽度。
- **A5 固定纸张版式**（病历/处方笺/申请单/处置单）：四边 10mm 页边距一致；
  内容不足一页时底部保持空白（版心撑满整页）；内容超出自动纵向拆分为多页；
  表格行与处方行禁止跨页截断。付款凭条保持窄条随内容拉长的原有形态不变。
- **空名开单明细防御**：开单接口校验项目存在性，病历文本生成过滤空名明细。
- **模态框层叠**：栈式管理——诊断二级编辑弹窗不再关闭一级选择弹窗；
  Esc 单监听按栈深启停，多层时一次只关栈顶。
- **空的自动段隐藏**：未开检验/无处方/无处置时编辑器内不再显示多余破折号。

---

## [1.6.28] - 2026-08-22

### 修复

- **模态框层叠重构**：modal.js 由单实例改为栈式管理——诊断选择的二级编辑弹窗
  （部位/备注/是否疑似）打开时不再关闭一级选择弹窗，确认后返回原列表实时刷新。
- **既往史/是否留观空值选项**：下拉移除空占位项（既往史仅 否认/承认，默认否认；
  留观仅 否/是，默认否）。
- **病历节名重复**：节标签自带冒号（主诉：），删除编辑器引擎内的重复静态前缀。
- **病历排版**：各节改为横向流式排列（flex-wrap），到右侧边缘自动换行，
  不再出现纵向挤压换行（如「现病 史：」竖排断字）。
- **缩小模式侧边栏 LOGO 偏左**：隐藏空的名称容器使 LOGO 恢复居中。
- **病历页患者信息头常驻**：sticky 吸附在顶栏下方，不随内容滚动。

---

## [1.6.27] - 2026-08-22

### 新增

- **电子病历编辑器全面重构（Word 式结构化病历）**：
  - **[] 占位字段引擎**（新组件 emreditor.js）：可编辑部分以 [] 包裹并显示
    占位提示，点击字段内任意区域全选文字、输入即替换；静态标签不可选中/
    不可编辑；字段内禁止回车（Enter 自动跳下一字段）、空字段退格不破坏结构、
    粘贴自动转纯文本；保存时 [] 括号不保存、未填写的占位符视为空。
  - **十二大病历模块**：主诉（症状/时间/单位×2 段，单位下拉：分/小时/天/周/月/年，
    未填部分自动忽略，如「腰痛1天加重1小时」）、现病史（供史者六类下拉＋时间＋
    内容＋来院途径四类下拉）、既往史（否认/承认下拉——选否认隐藏且强制清空
    详细内容，后端双重校验防绕过；选承认显示详细输入框）、过敏史、主要症状
    （全身/呼吸道/消化道/皮疹/出血/神经系统六类下拉，全空整节不打印）、
    体格检查（九项，全空打印「-」）、初步诊断、辅助检查（已开项目自动带出 +
    手工结果 + 外院结果，全空打印「-」）、门诊处置（处方一行一条自动带出 +
    处置项含数量如「小清创x2」+ 自定义内容，全空打印「-」）、是否留观、嘱托；
    生命体征维持模态框输入不变。
  - **诊断选择模态框**：点击 [请添加初步诊断] 弹出——左侧搜索疾病名称/ICD10
    编码/拼音，右侧已选列表支持排序/删除；点击诊断弹二级模态框填写部位/备注/
    是否疑似（均选填），生成如「S60.22 左侧手挫伤（中指挫擦伤）?」，多诊断逗号
    分隔，点击已选诊断可再次修改并实时回显。
  - **跨就诊既往史/过敏史**：与患者身份关联，历史填写过自动预填；以最新一次
    保存为准同步至患者档案。
- **数据架构升级**：新增 patient_records 表（emr_data 结构化 JSON 唯一真理来源 +
  主症状/供史者/主诊断等投影统计字段 + 打印纯净文本快照 + 统计索引）；
  后端保存流程：校验清洗 → 投影提取 → 打印文本生成 → 事务写入 patient_records
  与旧 records 扁平镜像（兼容就诊历史/转科引用）→ 同步患者主表。
  打印模板按结构化规则渲染（占位符剔除、空节隐藏、「-」回退）。

---

## [1.6.26] - 2026-08-21

### 新增

- **医院作息时间设置**（系统设置页新增卡片与设置模态框）：
  - 常规作息四要素：上午上班/下班、下午上班/下班（HH:MM，校验先后关系）；
  - **夏令时作息**：可开启并设置生效日期范围（MM-DD，每年循环，支持跨年区间
    如 11-01 ~ 03-31）与夏令时专属作息四要素（留空项沿用常规作息）；
    命中日期范围时系统自动切换按夏令时作息执行；
  - 设置卡实时显示当前作息、夏令时状态与当前时段徽章（未上班/上午可挂号/
    午休/下午可挂号/已下班）。
- **门诊号源接入作息管控**（急诊 24 小时可挂，不受限）：
  - 号源接口返回当前时段状态与提示；非放号时段门诊科室标记不可挂；
  - 挂号页右侧「今日号源」顶部展示作息提示条（如「午休中：上午号源已截止，
    下午 14:00 开始放号」「今日已下班…」），科室行显示对应状态徽章；
  - 科室选择弹窗同样展示提示条，非放号时段的门诊科室置灰拦截；
  - 挂号接口服务端双重校验：非放号时段挂门诊直接拒绝（防绕过前端）。

---

## [1.6.25] - 2026-08-21

### 变更

- **叫号屏双名称两端对齐**：左上角医院第一名称/第二名称所在区域撑满 LOGO 与
  时钟之间，两行文字左右两端对齐（徽标式排版），与侧边栏风格统一。
- **登录页显示医院品牌**：原仅居中 LOGO，改为品牌区（LOGO + 医院第一名称大字/
  第二名称小字，两行左右两端对齐）；安装页在未设置医院信息时不显示品牌区。

---

## [1.6.24] - 2026-08-21

### 变更

- **职业分类更新**：患者职业字典按《中华人民共和国职业分类大典（2022年版）》
  调整为八大类（党的机关/国家机关/群众团体和社会组织/企事业单位负责人、
  专业技术人员、办事人员和有关人员、社会生产服务和生活服务人员、
  农林牧渔业生产及辅助人员、生产制造及有关人员、军人、不便分类的其他从业人员）。
  历史档案中已保存的旧职业值不受影响（原样展示）。
- **侧边栏双名称显示**：医院设置第二名称时，左上角同时显示——第一名称大字、
  第二名称小字，两行左右两端对齐（徽标式排版）；缩小模式与窄屏抽屉下自动隐藏/
  恢复。
- **挂号按钮启用规则修正**：由「姓名已填」改为三个必填项（姓名、性别、出生日期）
  全部有值才可点击；输入身份证后性别/出生日期自动生成视为已填写；
  提示文字动态列出缺失项；日历选择派发的 change 事件与程序赋值均已同步状态。

---

## [1.6.23] - 2026-08-21

### 新增

- **快速挂号（无名氏）**：危重症无家属/昏迷患者无法提供身份信息时的绿色通道：
  - 挂号按钮双状态：表单无任何输入时显示绿色【🚑 快速挂号（无名氏）】；
    一旦检测到身份证/姓名任意输入即切换为【挂号】按钮并置灰，
    姓名填写完成后才可点击（提示文字随状态联动）；
  - 快速挂号模态框：姓名系统自动生成（**无名氏 + 患者编号**，编号取自挂号
    流水同源序列，全局唯一、不与实名患者冲突，只读不可改）；性别可选；
    **年龄必填**（目测估算），出生日期选填——两者双向联动：填年龄自动推算
    出生日期（今天 − 年龄），手选出生日期自动反算年龄；
  - 仅可挂 **挂号费为 0 元** 的科室：科室弹窗内非 0 元科室置灰标记
    「需实名挂号」，点击拦截提示；后端双重校验；
  - 默认跳转 **急诊 Tab**（门诊 Tab 内 0 元科室仍可选）；实名有身份证挂号
    默认门诊 Tab（维持原有逻辑）。
- 新增测试用 0 元急诊科室（急诊绿色通道 / 抢救室）。

### 变更

- 挂号接口响应新增最终生成姓名字段，确认框以服务端生成为准
  （避免预览号与实际号在并发挂号时出现偏差）。
- 既往登记自动填充姓名后同步刷新挂号按钮状态（程序赋值不触发 input 事件）。

---

## [1.6.22] - 2026-08-21

### 修复

- **缴费退费页搜索列表年龄显示「0岁」**：v1.6.20 全量切换格式化年龄时遗漏了
  缴费退费页（paymanage）的搜索结果列表，婴幼儿患者按入库快照周岁显示为
  「0岁」（详情弹窗正常）。已改为与全站一致的 EMR 格式化年龄
  （Clinic.validate.formatAge，按出生日期实时计算）。

---

## [1.6.21] - 2026-08-21

### 变更

- **只读样式统一**：挂号页年龄字段改用 disabled 状态，与输入身份证后性别字段的
  只读样式完全一致（灰字、浅底、无光标）。
- **日期选择组件推广**：挂号管理右上角查询日期接入通用日历组件（原浏览器原生
  date 控件改为点击弹日历，拒绝手动输入）；后续新增日期字段统一复用。
- **管理员侧边栏优化**：
  - 菜单文案精简：「检验项目管理」→「检验管理」、「检查项目管理」→「检查管理」
    （工作台快捷入口同步更新）；
  - 展开宽度 232px → 208px；
  - 页脚版权文字缩小字号（12px → 11px）、增加行高与自动换行，窄栏下不再拥挤。

---

## [1.6.20] - 2026-08-21

### 新增

- **全年龄段医疗格式化年龄（EMR 规范）**：新增服务端 `age_format()` 与客户端
  `Clinic.validate.formatAge()`（规则一致，日历精确计算，自动处理大小月/平闰年）：
  - 出生 < 24小时 → X小时 / X小时Y分（不足1小时显示 Y分）
  - 1 ~ 28 天 → X天（新生儿期，不按周换算）
  - 未满 12 个月 → X月 / X月Y天（天数为0只显示X月；未满1月按天显示）
  - 1 ~ 5 岁 → X岁Y月（月数为0只显示X岁）
  - ≥ 6 岁 → X岁
  约束：不使用周/星期、严禁浮点数、目标时间早于出生时间时返回空（异常防御）。

### 变更

- **系统内所有年龄展示统一切换为格式化年龄**：医生工作站候诊列表、护士站、
  检验/影像/药房列表、挂号缴费详情、患者档案卡、病历页眉与病历文档患者信息区；
  涉及就诊的展示按「出生日期 + 就诊时间」精确计算（历史就诊不随当前时间变化），
  患者档案类展示按出生日期 + 当前时间计算。
- **打印单据年龄同步升级**：挂号凭条/申请单/处方/处置单/病历/证明等全部改用
  格式化年龄（优先出生日期+就诊时间，缺失时回退原快照周岁）。
- 挂号页年龄展示改为格式化格式；入库快照仍保存周岁数字（兼容统计与旧数据）。

---

## [1.6.19] - 2026-08-21

### 修复

- **挂号页频繁误报「网络请求失败」**：科室改为弹窗选择后，遗留的号源加载代码仍
  引用已删除的科室下拉框元素，回调内抛出异常被 AJAX 封装的 catch 捕获，
  被误报为网络错误（进入页面及每次输入身份证都会触发）。已彻底清理死代码；
  同类问题（确认框前回显已选科室的失效引用）一并修复。

### 变更

- **移除表单底部「挂号科室」区块**：选科功能已完全由弹窗承担，页面不再重复展示。
- **右侧「今日号源」改为纯静态总览**：始终展示全部科室（门诊 + 急诊）的余号状态
  与当前时段（上午/下午），不随身份证输入动态过滤（动态过滤仅在挂号弹窗内）；
  缴费成功后自动刷新。
- **年龄改为恒只读**：由出生日期自动计算周岁，不可手动输入。
- **姓名 / 性别 / 出生日期必填**：缺一不可（有身份证时由证件自动带出）。
- **出生日期拒绝手动输入**：新增通用日历选择组件（datepicker.js）——点击弹出
  日历弹层，支持年/月快速翻转、「今天」快捷、「清除」按钮、不可选未来日期、
  点击空白/Esc 关闭；年龄随选择联动刷新。

---

## [1.6.18] - 2026-08-21

### 新增

- **通用科室选择弹窗**（新组件 deptpicker.js）：参考医生站「切换科室」卡片式
  弹窗抽象为通用组件，急诊 / 门诊两个子 Tab 分类展示，一套 UI 三处复用：
  - 挂号选科室：卡片显示 **剩余号源 + 挂号金额**，满号科室置灰拦截；
    未填身份证时门诊 Tab 置灰（仅可挂急诊）；
  - 医生选择 / 切换科室：不显示挂号相关信息，当前科室标记「当前」；
  - 转科：不显示挂号相关信息，弹窗内排除当前科室。
- **挂号流程重构**：点击【挂号】→ 弹出科室选择弹窗 → 选定科室 → 确认框核对
  姓名 / 性别 / 费用类别 / ID号（身份证）/ 就诊序号 / 挂号费等信息 →
  缴费（模拟）→ 自动弹出挂号凭条并调用打印。

### 修复

- **转科防自转双保险**：服务端新增校验——目标科室与患者当前科室相同时拒绝转科
  （此前仅前端下拉排除当前科室，直接调用接口可绕过）。

### 变更

- 挂号页原科室下拉框移除，改为只读回显 + 弹窗选择；右侧「今日号源」概览保留。
- 医生工作站切换科室弹窗、病历书写页转科弹窗统一迁移至通用科室选择组件，
  转科选定科室后增加二次确认。

---

## [1.6.17] - 2026-08-21

### 新增

- **支持用户名 / 工号双方式登录**：登录框改为「用户名 / 工号」，输入任一标识
  均可登录（用户名优先精确匹配，工号回退匹配），错误提示同步更新为
  「用户名/工号或密码错误」。
- **用户名规则约束**：新建/编辑用户时，登录用户名必须以英文字母开头
  （不允许纯数字或数字开头，后端权威校验 + 表单即时提示），
  避免与工号登录并存时产生标识混淆。

---

## [1.6.16] - 2026-08-21

### 修复

- **医院 LOGO 在登录后的页面显示为错误占位符（404）**：根因是上传路径
  （`uploads/logo/…` 相对路径）被直接输出到 `src/href`，浏览器按当前页面层级解析——
  登录页（一级路径）恰好正确，而 `/admin/dashboard` 等二级路径页会请求
  `/admin/uploads/…` 导致 404。现统一经辅助函数规范化输出。
- **用户头像同类隐患一并修复**：顶栏头像、个人中心头像、叫号屏医生照片
  （call.js 动态拼接）均改为根绝对路径，多级路径页面不再失效。

### 安全

- **医院 LOGO 不再暴露文件 URL**：页面内（侧边栏品牌、登录页、设置页预览、
  叫号屏、favicon）一律改为 base64 Data URI 内联显示，HTML 中不出现 LOGO 地址；
  并在开发路由（router.php）与 Nginx 配置示例中封禁 `/uploads/logo/` 直链访问，
  防止通过 URL 探测/抓取 LOGO 文件。新增 `img_data()` 辅助函数含目录穿越防护。

---

## [1.6.15] - 2026-08-21

### 新增

- **侧边栏缩小模式（仅图标窄条）**：顶栏 ☰ 按钮由「展开/隐藏」改为「展开/缩小」
  两态——缩小后仅保留 64px 图标窄条，隐藏文字、分组标题与页脚，悬停菜单图标
  可通过原生 title 提示显示名称；切换带平滑过渡动画。
- **侧边栏偏好跟随用户保存**：展开/缩小选择持久化到用户记录（users.sidebar，
  用户库 v3 迁移自动增量升级，旧库无需手动处理），下次登录任意设备均保持；
  服务端按偏好直接渲染初始状态，刷新无闪烁。
- **病历书写页强制缩小侧边栏**：医生工作站病历书写页忽略用户偏好强制缩小
  侧边栏，为书写区提供足够空间（该页可临时展开，但不覆盖用户偏好设置）。

### 变更

- **侧边栏折叠记忆迁移**：原 localStorage 记忆的「折叠隐藏」状态废弃，
  统一改为服务器端按用户保存的「缩小」模式；窄屏（≤900px）抽屉式交互保持不变。

---

## [1.6.14] - 2026-08-18

### 新增

- **开单详情弹窗新增删除/毁方**：详情弹窗（流程）内新增 🗑️ 按钮——处方显示
  「毁方」、其余显示「删除」，仅未缴费或已退费可操作（未缴费毁方后药品库存
  自动恢复）；已进入执行流程的项目点击提示到收费处办理退费。

### 变更

- **病历处方直显简化**：病历正文（编辑页 + 打印页）的处方不再显示
  「剂量：/用法：/途径：/数量：」提示词，改为直接展示，如
  「尼美舒利胶囊　1片/0.1g　每日两次　口服　×1」。

---

## [1.6.13] - 2026-08-18

### 新增

- **病历正文同步显示已开项目（所见即所得）**：初步诊断下方新增「辅助检查」与
  「门诊处置」两栏，与病历打印版式一致：
  - 辅助检查：显示检验/检查项目名称（如 红细胞分析、颅脑CT检查），
    点击项目弹出对应开单流程弹窗；
  - 门诊处置：处置项目不换行显示名称×数量（如 大换药×1）；处方每行一个
    药品，显示 名称/剂量/用法/途径/数量，点击可查看流程；
  - 已退费/已取消的开单不计入病历内容；开单提交、删除后自动同步刷新。

---

## [1.6.12] - 2026-08-18

### 变更

- **申请单 / 处置单合计显示优化**：合计不再作为表格行显示（原实现多出一个
  单元格，导致合计行突出表格空间），改为在表格右下角以普通文本行显示
  「合计：¥xx」。
- **处方笺提示顺序调整**：「—————— 处方完毕 ——————」紧接药品表格下方
  （属于处方内容结尾），「请凭本处方单至药房取药」提示移至其后（不属于
  处方内容）。

---

## [1.6.11] - 2026-08-18

### 新增

- **检验 / 检查申请单底部提醒**：检验申请单新增「肝功能等抽血检验项目需空腹采血」
  温馨提示；检查申请单新增「X 线、CT 等检查请注意辐射防护；腹部超声等部分检查
  需空腹进行」温馨提示。处置申请单不显示专项提醒。

---

## [1.6.10] - 2026-08-18

### 变更

- **申请单 / 处置单 / 处方单患者信息精简为两行**：第一行 姓名/性别/出生日期/年龄，
  第二行 患者ID/流水号/单号（两端对齐），不再显示开单医生与开单时间。
- **开单医生移至正文右下角**：开单医生签名显示在开单项目正文右下方（类似病历
  签名位置）——申请单/处置单显示「开单医生：××」，处方单显示「医师签名：××」。
- **页脚重排**：去掉左下角原来的「医生签名：」空位，末尾横线下方改为
  左下角「开单时间」、右下角「打印时间」。
- **处方单专属版式**：处方内容左上角新增 ℞ 标志（大号斜体处方符号）；
  药品明细完毕后下方显示居中分隔「—————— 处方完毕 ——————」，
  随后右下角医师签名。

---

## [1.6.9] - 2026-08-18

### 修复

- **开单弹窗中间列过宽**：弹窗由 1080px 收窄回 860px，左列目录 240px、
  右列流程 140px，中间已选项目区宽度适中，不再显得违和。
- **删除开单时详情弹窗覆盖删除确认弹窗**：已开项目卡片整卡可点开详情，
  卡片内 ✕ 删除按钮点击会冒泡触发详情弹窗，导致删除确认被覆盖；
  已为 ✕ 按钮加 `stopPropagation`，点击只弹删除确认。
- **处置 / 处方重复选择逻辑修正**：处置与处方均为同一项目（药品）**仅可添加一次**，
  数量通过已选列表中的数量控件手动修改（如处置 ×2、药品 ×2）；
  移除此前「重复点击自动累加 / 处方可重复添加」的逻辑，与检验/检查规则统一。

### 变更

- **开单详情弹窗新增打印功能**：查看已开项目（流程弹窗）时可直接
  「🖨️ 打印检验单/检查单/处置单/处方单」。
- **申请单/处置单/处方单统一 A5 样式**：检验申请单、检查申请单、
  处置申请单、门诊处方笺（处方单更名）均按 A5 病历纸版式打印——
  宽度 148mm、医院名称/第二名称与电子病历完全一致，标题随单据类型切换；
  患者信息统一采用急诊病历两行样式（第一行 姓名/性别/出生日期/年龄，
  第二行 患者ID/流水号/单号/开单医生/开单时间）且两端对齐；
  医生开单、开单详情、打印中心、消息中心所有入口均同步生效。

---

## [1.6.8] - 2026-08-18

### 变更

- **开单弹窗改为三栏布局**：左侧（较窄）为项目目录与搜索框、中间（大块）为已选项目列表、
  右侧保留流程闭环追踪（开单-缴费-登记-执行-完成）与总费用、护士站选项；
  弹窗加宽至 1080px，目录与已选列表各自独立滚动。
- **开单互斥规则**：
  - 检验 / 检查：同一开单内不允许重复开具；
  - 检验组合与所含单项互斥（双向）：已开组合再开其包含的单项（或反之）直接拦截并提示；
  - 两个不同组合共享同一成员：不算重复、可正常开具，但会弹出共享成员提醒；
  - 处置：重复选择自动累加数量（如：大清创 ×2、×4），已选列表增加数量控件；
  - 处方：不受互斥限制，可重复添加。
- **检验既往开具二次确认**：开具前自动查询该患者历史检验开单记录（含未缴费），
  若同一项目曾开具过，在弹窗内提示「何时开具过、单号多少，是否再次开具」，
  确认后方可加入（支持复查场景）；后端新增 `order?action=prev_items` 接口
  （同一项目只保留最近一次，目录接口同步返回组合成员 ID 供互斥判断）。

---

## [1.6.7] - 2026-08-18

### 变更

- **急诊电子病历患者信息改为两端对齐**：两行字段（第一行 姓名/性别/出生日期/年龄，
  第二行 患者ID/就诊科室/就诊时间）每行撑满内容宽度——左侧文字贴左边缘、
  右侧文字贴右边缘（`justify-content: space-between`），编辑页与打印页同步生效。
- **患者信息修改入口调整**：病历文档内的患者信息区不再整块可点击（悬停高亮移除），
  病历编辑区更纯粹干净；改为点击**上方患者头像或患者姓名**弹出「修改患者信息」弹窗
  （头像悬停放大、姓名悬停变主题色提示可点击）；初复诊下拉框保留不受影响。

### 修复

- **修复病历页患者头像变小的问题**：头像改为可点击时丢掉了原来的 `font-size:30px`
  内联样式，表情按默认字号渲染导致变小；已在 `.emr-patient-avatar` 样式类中补回
  30px 字号（并加 `line-height:1` 防止行高撑高），头像恢复原有大小。

---

## [1.6.6] - 2026-08-18

### 变更

- **急诊电子病历患者信息改为两行排版**：编辑模式与打印预览模式一致——
  第一行「姓名 性别 出生日期 年龄」，第二行「患者ID 就诊科室 就诊时间」，
  不再使用两栏网格，字段一行排开、清晰紧凑；点击弹窗修改患者信息的
  交互与门诊一致。
- **输入框与下拉框高度统一**：`.input` / `.select` 固定 36px 高度（含边框），
  日期/时间选择器同步调整，消除同行控件「输入框比下拉框高」的观感；
  textarea 保持自动高度不受影响。

---

## [1.6.5] - 2026-08-18

### 变更

- **病历模板按钮移至病历文档页头左上角**：与医院名称顶部齐平、左侧与左边距齐平
  （不再悬浮在标题栏右侧），与右上角条形码左右对称，页面更协调。
- **患者信息区点击弹出「修改患者信息」弹窗**：复用挂号/医生站已有的修改弹窗
  （除姓名/性别/身份证外均可修改），鼠标悬停整块区域高亮提示可点击；
  初复诊下拉框除外（点击下拉不触发弹窗）。
- **生命体征区精简**：去掉「点击编辑，与护士站同步」提示语、右侧 ✏️ 编辑按钮
  与加深的灰色背景，整行展示数据（无数据显示 -），点击即弹出生命体征编辑弹窗，
  与护士站双向同步不变。
- **病历正文全部改为纵向排列**：主诉、现病史、既往史、过敏史、意识状态、
  体格检查、初步诊断、留观、嘱托每节独立一行，输入框接在标题后方
  （如「主诉：XXXX」），不同小节之间换行，与打印版式一致、不再横向混排。
- **生命体征位置调整**：移到过敏史下方、意识状态上方，与打印模板顺序一致。
- **病历正文右下角新增「医生：XX」签名**：位于正文末尾右对齐，页脚原有的
  「医生：医生（工号 0003）｜主任医师」与记录时间保留，互不影响。
- **留观改为下拉选择**：样式与意识状态一致，下拉选择「是 / 否」替代复选框。

### 新增

- **患者信息区新增「初复诊」下拉框**：默认初诊，可下拉切换为复诊；
  新增 `records.visit_type` 字段（默认「初诊」，旧库自动迁移），
  保存病历后随病历持久化，病历打印模板同步显示。

---

## [1.6.4] - 2026-08-18

### 变更

- **条形码位置微调（病历编辑页 + 打印页统一对齐）**：条形码不再紧贴文档边缘——
  上方与医院名称顶部齐平（`top` 对齐内容区顶部），右侧与右侧页边距齐平
  （`right` 对齐内容区右缘），条形码高度与医院名称字号一致（约 26px，
  比例不变、不影响扫码）；编辑页与打印预览页同一套定位逻辑，视觉协调；
  编辑页「病历模板」按钮左移避开条形码列，防止重叠；窄屏同步缩小条形码并调整间距。
- **病历打印页患者信息区上下各加一条横线**：患者信息区被两条分隔线夹在中间
  （上方横线 + 原有下方横线），与病历正文层次更清晰。
- **A5 病历纸排版紧凑化**：压缩医院名称、第二名称、标题、患者信息区内部的
  行距 / 段距（行高 1.8→1.55、标题边距、分隔线边距等整体收紧），
  把主要打印空间留给主诉、现病史等病历正文，避免 A5 窄纸上部拥挤。
- **病历页脚重排**：病历末尾新增一条横线，横线下方左下角显示「记录时间」、
  右下角显示「打印时间」；医生签名上移到横线上方、病历内容部分右下角显示，
  不再与时间信息混在页脚。

---

## [1.6.3] - 2026-08-18

### 修复

- **修复就诊历史「新增诊断证明」弹窗闪现后消失、页面所有按钮无响应的问题**：
  根因是 `modal.js` 的 `close()` 关闭弹窗时只安排了延时移除回调、却**没有立即
  解除全局遮罩引用**——当在就诊历史弹窗上再开新弹窗（新增诊断证明）时，
  旧弹窗的延时回调把新弹窗误删（表现为弹窗闪现后瞬间消失），同时旧遮罩残留在
  页面上挡住所有点击（页面像死掉一样，只能刷新）。已改为关闭时立即解除引用、
  只移除本次关闭的弹窗，动画结束后旧弹窗仅移除自身，新弹窗正常保留。
- **修复 ICD 诊断搜索「越精确越搜不到」的问题**：搜索下拉的 `setOptions()`
  此前只更新数据、不重绘已打开的面板，快速输入多个字（如「健康」）时，
  最后一次输入事件先于搜索结果返回，面板一直停留在「无匹配选项」；
  而单字（如「健」）因输入法提交事件晚于结果返回反而能显示。已让
  `setOptions()` 在面板打开时按当前输入值立即重新过滤渲染；同时为 ICD
  请求增加序号、丢弃过期响应（避免旧关键字后到覆盖新关键字结果）。
  后端名称 / ICD 编码 / 拼音模糊检索逻辑本身正常（已实测「健」「健康」均命中）。

### 变更

- **病历编辑页条形码移至病历文档右上角**：条形码不再显示在患者信息卡右上角，
  改为显示在病历文档（所见即所得文档）页头右上角，与门诊电子病历打印预览
  样式一致（门诊号 Code 128 + 数字），位置、风格与打印页统一。
- **病历打印页患者信息与病历正文之间增加分隔线**，避免两部分版式混淆；
  病历正文（主诉、现病史、既往史等）字体由 13px 加大一号至 14px，
  与患者信息区明显区分。
- **病历纸改为 A5 竖版**：病历打印预览与打印输出严格按 A5 纸张
  （148×210mm 竖版窄条）显示，宽度固定、内容可向下延伸至多页；
  医生端打印病历、就诊历史查看病历、医生工作站打印病历、打印中心
  「电子病历」补打均按 A5 版式输出，其余单据仍用默认纸张。
- **凭条打印宽度严格限制**：挂号凭条 / 缴费凭条保持固定小票宽度（300px），
  长内容（项目名称、证件号等）自动折行，可向下加长、不超出宽度。

---

## [1.6.2] - 2026-08-18

### 新增

- **病历页 / 打印页右上角条形码**：病历编辑页页头右上角与门诊电子病历
  打印页右上角均显示 Code 128 条形码（与挂号凭条一致，取门诊号
  flow_no），方便患者扫码缴费、打印报告等。

### 变更

- **病历编辑页标题与记录时间**：「门诊 / 急诊电子病历」标题改为居中
  显示（与打印预览一致）；记录时间移至文档左下角，默认不显示，
  仅保存成功后显示（不再出现光秃秃的「记录时间：」）。仅调整编辑页
  排版，不影响打印版式。
- **打印病历正文分段换行**：主诉、现病史、既往史等改为逐行显示
  （`主诉：xxx` 一行，`现病史：xxx` 换下一行），不再全部挤成一条；
  标签与内容仍保持同行。

---

## [1.6.1] - 2026-08-18

### 修复

- **病历文档患者信息显示 undefined**：病历编辑页患者信息网格误用了
  生命体征变量（`v`），姓名 / 性别 / 年龄等显示 undefined，
  已改用独立的就诊信息变量（`vv`），正常显示。

### 变更

- **门诊电子病历打印版式重排**：
  - 医院名称放大至 25px 居中加粗；
  - 「门诊/急诊电子病历」标题去掉上下黑色横线，改为清爽居中大字；
  - 患者信息两栏完整显示全部字段（姓名/性别/年龄/患者ID/证件号码/
    出生日期/民族/职业/婚姻/初复诊/科室/联系方式），空值显示 —，
    与病历编辑器字段集完全一致（急诊用急诊字段集）；
  - 病历正文改为行内流式排版：`主诉：xxx　现病史：xxx`，不再
    标签 / 内容各占一行，大幅节省打印空间；
  - 页脚增加记录时间（医生 / 记录时间 / 打印时间）。

---

## [1.6.0] - 2026-08-18

### 变更

- **电子病历页改为所见即所得文档版式**：病历不再使用表单式平铺，改为
  模拟纸质病历的文档样式——内含医院名称、第二名称（如有）、抬头
  （门诊/急诊电子病历）、标题栏（含记录时间）、患者信息两栏排列
  （门诊电子病历样式）、病历详细内容与底部医生签名；打印输出与屏幕
  版式一致（所见即所得）。
- **患者一栏去重**：页头右侧的「就诊医生（工号）｜ 职称 / 记录时间」
  已移除（就诊医生信息右上角已有展示），记录时间移至病历文档标题栏
  显示；独立的「患者信息（不可修改）」卡片移除，患者信息直接内嵌在
  病历文档中按两栏展示。
- **病历打印改用统一打印模板**：医生端「打印病历」与打印中心 / 就诊
  历史「查看病历」使用同一套 `pt_record` 模板（患者信息两栏），不再
  各自拼装，保证屏幕与打印版式一致。

### 修复

- **保存病历后无需刷新即可开单**：点击「保存病历」后页面本地缓存
  同步更新，开检验 / 开检查 / 开处置 / 开处方与打印病历立即生效，
  不再提示「请先完善病历」；标题栏记录时间同步刷新。

---

## [1.5.0] - 2026-08-18

### 变更

- **就诊历史「查看病历」改为病历预览 / 打印页**：不再跳转到病历编辑页，
  点击后直接打开该次就诊的电子病历打印预览（与打印中心补打同一模板），
  可再次打印。
- **病历未保存时给出提示**：就诊历史中某次就诊尚未保存病历（无主诉 /
  现病史 / 初步诊断）时，「查看病历」不再跳转，直接提示
  「该次就诊病历尚未保存，无法查看」。

### 新增

- **开单与打印病历前置校验（前后端双重拦截）**：开检验 / 开检查 /
  开处置 / 开处方 / 打印病历前，必须先在病历中完善主诉、现病史与
  初步诊断并保存；前端在开单弹窗与打印入口拦截并提示，后端
  `order.php submit` 与 `print.php record` 同步校验，无法绕过。

---

## [1.4.5] - 2026-08-18

### 变更

- **医生工作站当前科室信息去重**：页头完整显示「医生：姓名（工号）｜ 职称 ｜
  当前科室：XX（限号 / 不限号科室）」，【切换科室】按钮后的科室栏不再重复
  显示「当前科室：XX」整句，改为只显示科室性质徽章
  （限号科室 · 号源满时可加号 / 不限号科室），信息不丢且不再重复。
- **切换科室弹窗改为大按钮卡片式**：不再使用下拉菜单 + 确定按钮，
  改为科室卡片网格（自动多列），每张卡片显示科室名 + 门诊 / 急诊徽章 +
  限号 / 不限号徽章，当前科室高亮并带「当前」标记，点击卡片直接切换
  并关闭弹窗，首次登录选科室与随时切换均使用该样式。

---

## [1.4.4] - 2026-08-18

### 新增

- **右上角登录用户悬浮窗**：鼠标移动到顶栏右侧的用户名 / 头像区域，
  跟随显示信息卡片——头部为头像 + 姓名 + 角色（含职称），下方列出
  工号、姓名、角色，医务人员（医生 / 护士 / 检验技师 / 影像技师 /
  药剂师）额外显示职称（未设置职称时提示「未设置」）；
  底部提供【个人中心 ›】快捷入口。悬浮窗由 CSS hover 实现，
  带平滑过渡与指向箭头，不影响原有「点击进入个人中心」跳转。

---

## [1.4.3] - 2026-08-18

### 修复

- **修复挂号页门诊号源「短暂出现 1 秒又消失」的问题**：根因是前端异步竞态——
  身份证输入框为 `input` 事件，输入过程中会多次触发 `loadDepts()`，
  较早发出的「无身份证仅急诊」请求若后返回，会覆盖较晚的「含门诊」响应，
  表现为门诊号源闪现后又消失（后端判断本身正确，实测无身份证仅返回急诊、
  有身份证正常返回门诊 + 急诊）。已为 `loadDepts` 增加请求序号（丢弃过期响应）
  与 120ms 防抖，输入过程中只保留最后一次请求结果。

---

## [1.4.2] - 2026-08-18

### 修复

- **修复审核中心「一键全部通过」点击提示【未知操作】的问题**：`admin.php`
  的分发 switch 漏掉了 `case 'audit_all'`，导致按钮点击走到默认分支返回
  「未知操作」；已补全分发，空列表时正确提示「当前没有可一键通过的事项」。
- **没有待审核事项时隐藏「一键全部通过」按钮**：`audit_list` 接口新增返回
  `pending_count`（可一键通过的常规待审核数量，密码重置 / 报告撤回不计入），
  前端仅在有可一键通过事项时显示按钮，切到【已处理】页签时同样隐藏。

---

## [1.4.1] - 2026-08-18

### 变更

- **条形码生成独立为可复用文件**：`barcode128_svg()` 从打印模板中抽出，
  独立到 `app/core/barcode.php`（纯 PHP Code 128 / SVG，零第三方依赖，
  并内置 `e()` 兜底实现，可单独 require 使用）；由 `bootstrap.php` 全站加载，
  打印模板及其余任何页面 / 接口均可直接调用，便于后续在挂号单、
  检查指引单、药品标签等更多区域复用条形码。

---

## [1.4.0] - 2026-08-18

### 新增

- **纯 PHP 生成 Code 128 条形码（SVG，零第三方依赖）**：新增 `barcode128_svg()`
  函数，基于 Code 128 字符集 B（数字 / 大小写字母 / 常用符号），内置完整符号模式表、
  计算模 103 校验位，以 SVG `<rect>` 逐模块绘制条码（含静区与条码下方数字），
  无需 GD / imagick / 任何外部扩展，扫描枪可直接识别。

### 变更

- **挂号凭条改为竖向小票格式**：由横向表格改为竖向版式——顶部医院名称 /
  第二名称 / 「挂号凭条」居中标题，虚线分隔后依次展示患者姓名、患者ID、门诊号、
  性别、出生日期、年龄、挂号科室（急诊带标注）、就诊序号、就诊日期、挂号时间、
  费用类别；虚线分隔后为挂号费与支付状态；再下方为带边框的条码区（
  门诊号 Code 128 条形码 + 数字）、「请妥善保管，按时就诊。」提示与打印时间。
- **缴费凭条同步改为竖向小票格式**：与挂号凭条同款版式，含患者姓名、患者ID、
  门诊号、缴费时间、收费员、收费项目明细（含数量）、合计金额、门诊号条形码
  与提示语；补打 / 自动弹出打印均生效。

---

## [1.3.0] - 2026-08-18

### 新增

- **审核中心一键全部通过**：待审核页签顶部新增【一键全部通过】按钮，
  常规事项（检验 / 检查 / 药品 / 处置项目、病历模板）批量通过；
  密码重置申请与报告撤回涉及账号安全 / 报告作废，不纳入一键通过，保留逐条人工审核。
- **审核驳回理由 + 消息回填重提**：管理员驳回时必须填写驳回理由，
  提交者会在站内消息中收到驳回理由；点击消息自动跳回对应的添加页面
  （管理员 → 后台管理页，检验 / 影像 / 药房 → 各自工作站），
  表单自动回填本次提交的内容，修改后可直接再次提交（重新进入待审核）。
- **消息区分患者消息 / 系统消息**：`messages` 表新增 `msg_type` / `patient_name` /
  `visit_id` / `link_url` 字段（自动增量迁移）；开单、处置完成、医嘱执行、
  报告出具、发药完成等与患者相关的通知标记为【患者】消息并显示患者姓名，
  其余为【系统】消息；点击患者消息直接跳转到该次就诊的电子病历页，
  点击审核驳回消息回到添加页面回填修改。

### 变更

- **管理员添加的项目免审核直接生效**：系统管理员在后台添加 / 编辑的检验项目、
  检验组合、检查项目、药品、处置项目直接通过审核（可用）；
  管理员创建的全科 / 全院病历模板免审核；非管理员提交的仍走审核流程。
- **右上角消息面板美化**：铃铛下拉面板改为带标题栏 +「查看全部」入口的
  通知面板，消息行显示类型徽标（患者 / 系统）、患者姓名、标题、内容摘要与时间，
  未读消息高亮；消息中心页同步升级。

### 修复

- **修复检验 / 检查 / 处置开单误报「数量超过库存」**：`order.js` 提交开单时的
  库存上限校验原本对所有项目类型生效，而检验 / 检查 / 处置项目无库存概念（库存为 0），
  导致必然提示超库存；现库存校验仅对处方（药品）生效。
- **修复护士工作站登录后一直转圈、页签无反应**：`nurse/dashboard.php` 内联脚本中
  `editModal(\\'…\\')` 的反斜杠转义错误导致整个页面脚本解析失败，
  待处置列表无法加载、今日患者 / 待执行医嘱页签全部无响应；已修正转义。

---

## [1.2.0] - 2026-08-18

### 新增

- **就诊历史增加「查看病历」「查看 / 新增诊断证明」操作**：每次就诊记录下方新增两个按钮——
  【查看病历】直接打开该次就诊的电子病历页；诊断证明已开具时显示【查看诊断证明】
  （弹出打印预览，可再次打印），未开具时显示【新增诊断证明】（弹窗内引用该次就诊的
  主诉 / 现病史 / 初步诊断，填写医生建议后开具并打印，病历不完整时提示无法补开）。
- **病历页诊毕患者诊断证明入口**：已诊毕患者若已开具诊断证明，页头【已开诊断证明】变为
  可点击链接，弹出诊断证明打印预览（可再次打印）；未开具时提供【补开诊断证明】链接。

### 变更

- **电子病历生命体征改为紧凑显示 + 弹窗编辑**：
  - 生命体征一栏不再平铺 5 个输入框，改为紧凑展示：所有项目为空时显示「—」，
    但凡存在一项即逐项显示已有数据（如「血压 120/80mmHg ｜ 心率 76次/分」）；
  - 点击生命体征区域（无论「—」还是已有数据）弹出编辑弹窗，血压拆分为
    【收缩压 / 舒张压】两个输入框，共 6 项（收缩压 / 舒张压 / 心率 / 脉搏 / 血氧 / 呼吸），
    保存后与护士站双向同步；
  - 护士站生命体征弹窗同步拆分血压为收缩压 / 舒张压，与医生端表单完全一致。

---

## [1.1.9] - 2026-08-18

### 新增

- **叫号大屏科室完全跟随医生端选择（不再在大屏端切换科室）**：
  - `users` 表新增 `current_dept_id` 字段（自动增量迁移，兼容旧库）；
    医生在【医生工作站】切换科室时通过新接口 `/api/doctor?action=set_dept` 保存当前科室；
  - 叫号屏去掉顶部科室切换按钮，轮询 `call_queue` 时不再传科室参数，
    服务端按医生当前选择返回数据——医生端选什么科室，大屏就显示什么科室；
  - 就诊中 / 下一位姓名下方只显示【第 XXX 号】（去掉性别 / 年龄 / 科室 / 流水号），
    聚焦姓名与号次；
  - 预留【复诊】标记：`call_queue` 返回 `is_followup`（同一患者当日在本科室已有就诊记录），
    为真时号次旁显示「复诊」角标，供后续医生端标记复诊后直接使用。

### 变更

- **看诊操作工具栏移至电子病历页右侧并固定**：开检验 / 检查 / 处置 / 处方、保存病历、
  保存并诊毕、转科、诊断证明、打印病历、就诊历史、修改患者信息等按钮改为竖排在
  页面右侧、不随页面滚动（与左侧导航一致），病历内容区自动让位；
  窄屏自动退回普通横向工具条。
- **患者查询改为弹窗**：医生工作站【患者查询】由系统 `prompt()` 弹窗改为模态框内输入
  （支持回车触发查询），结果直接展示在弹窗中，不再阻断操作。
- **加号功能仅限号科室显示**：接口返回科室 `limited` 标记（门诊且上 / 下午号源数量 > 0），
  急诊与 0 号源的不限号科室隐藏【＋ 加号】按钮，后端 `add_slot` 同步拦截。
- **医生工作站科室提示完善**：页头与【切换科室】右侧的科室提示由「加载科室中…」
  更新为「当前科室：XX（限号科室 / 不限号科室）」。

### 修复

- **修复医生工作站「加载科室中…」始终不消失的问题**：页面两处科室提示共用了
  同一个 `id="deptDesc"`，JS 只更新第一处，导致切换科室右侧永远停留在加载中；
  已拆分为独立 id 并同步更新。
- **修复所有页面左上角 ☰（data-sidebar-toggle）无反应的问题**：原实现只在窄屏
  有抽屉样式、宽屏点击无任何可见变化；现支持宽屏折叠 / 展开侧边栏
  （偏好记忆在 localStorage，刷新保持），窄屏仍为抽屉式开关，
  并修复宽屏折叠后切窄屏抽屉无法打开的问题。

---

## [1.1.8] - 2026-08-17

### 修复

- **修复叫号大屏不显示医生姓名、职称的问题**：
  `/api/doctor?action=call_queue` 查询出诊医生的 SQL 的 SELECT 列表漏掉了
  `dept_ids` 字段，导致前端按科室匹配医生时永远失败，`doctors` 恒为空数组，
  叫号屏医生卡片一直显示「医生出诊中」、无姓名/职称/照片。
  已补上 `dept_ids` 字段（医生关联科室），实测两个科室均正常返回医生列表；
  前端渲染逻辑（姓名 + 工号、职称、个人介绍；有照片显示照片、
  无照片显示默认头像 👨‍⚕️）此前已就绪，无需改动。

---

## [1.1.7] - 2026-08-17

### 修复

- **修复用户编辑弹窗不显示可选科室的问题，并将编辑/新建页面完全统一**：
  此前列表「编辑」按钮走通用 `loadModal()`（仅绑定保存，不执行页面初始化逻辑），
  而「新增」按钮走页面专属 `openXxxForm()`（会执行 `onRoleChange()` 等初始化，
  显示职称下拉与医生科室多选框），导致编辑用户时科室/职称区域不显示。
  已将所有管理端列表的「编辑」按钮改为直接调用与「新增」相同的
  `openDeptForm(id)` / `openUserForm(id)` / `openItemForm(id)` /
  `openDrugForm(id)` / `openDisposalForm(id)` / `openGroupForm(id)`，
  编辑与新增完全复用同一表单、同一初始化逻辑、同一保存流程
  （编辑时自动回填已有数据并正确显示可选科室、职称等）。
  `loadModal()` 保留为通用组件（不再被管理端列表使用）。

---

## [1.1.6] - 2026-08-17

### 修复

- **修复管理端编辑弹窗内容空白的问题**：科室/用户/项目/药品/处置的编辑弹窗
  打开后不显示已有数据（与新建页无异）。根因：`loadModal` / `Clinic.modal.load`
  统一通过 **POST** 提交参数，而后端 `dept_form` / `user_form` / `item_form` /
  `drug_form` / `disposal_form` 用 `get('id')` 读取（只能读到 GET），导致永远拿到
  id=0 返回空白新建表单。已在 `helpers.php` 新增 `req()` 函数（GET/POST 兼容读取），
  所有表单类接口（含检验/影像 `result_form`）改用 `req()` 读取参数。

### 变更

- **检验项目与检查项目分开管理**：
  - 管理员菜单拆分为【检验项目管理】（`/admin/labitems`）与【检查项目管理】
    （`/admin/examitems`）两个独立页面，旧链接 `/admin/items` 自动指向检验项目管理；
  - 检验与检查的分类各自独立管理（新增检验项目时分类下拉只显示检验分类，
    检查分类不再混淆）；
  - 检查项目保持简单（名称/分类/价格/描述，无组合逻辑）。
- **检验支持「组合检验」（影响管理员页与医生开单页）**：
  - 单个检验项目（如红细胞 ¥3、白细胞 ¥3）可单独开单；
  - 新增【检验组合】（如「血细胞分析」组价 ¥5）：管理员先添加单个检验项目，
    再创建组合并勾选组内成员、设定组合价格，组合同样需审核通过后可用；
  - 医生开检验弹窗中组合项目带【组合】标签并显示组内成员，可单独开组内项目，
    也可直接开整个组合（按组价整体收费）；
  - 检验申请单打印显示组合包含的成员；检验科对组合登记后，结果录入弹窗
    按组内成员逐项填写，检验报告按成员逐行出具；
  - 删除组合时组内项目自动还原为独立项目。

---

## [1.1.5] - 2026-08-17

### 修复

- **修复管理端「编辑」按钮无响应的问题**：科室/用户/项目/药品/处置列表的编辑按钮调用
  `loadModal()`，但该全局函数从未定义（删除按钮正常因调用视图内函数）。
  已在 `ui.js` 中实现通用 `loadModal()`：AJAX 加载服务端表单 → 自动派生保存动作
  （xxx_form → xxx_save）→ 收集 `f_` 前缀字段（含复选框、医生多科室、照片上传）
  → 保存成功后自动刷新对应列表；药品编辑时保留「途径 → 需护士站处理」自动勾选。
- **修复医生工作站「转科」无响应的问题**：`emr.js` 的 `Clinic.emr` 导出对象遗漏了
  `openTransfer` 方法，全局 `openTransfer()` 调用 `Clinic.emr.openTransfer()` 时报
  undefined 函数。已补全导出。
- **修复叫号大屏一直显示「正在加载科室…」的问题**：页面默认 `dept_id=0` 时，
  `call.js` 的 `refresh()` 因无科室 ID 直接返回，且加载科室成功后从未自动选中默认科室。
  现改为：未指定或指定科室无效时自动选中第一个科室，刷新前先显示科室名，
  医生未关联科室时给出明确提示。
- **修复诊断预览下拉位置错位的问题**：`.dropdown-panel` 原为 `position: absolute`，
  而 `selector.js` 使用视口坐标（`getBoundingClientRect`）定位，页面滚动后下拉面板
  相对文档坐标错位（如预览窗跑到主诉下方）。已改为 `position: fixed` 与视口坐标匹配。
- **修复转科后病历页医生信息显示错误**：`/api/record` 的 `visit` 返回值补充 `status` 字段，
  供前端判断就诊状态。

### 变更

- **医生工作站科室选择改为弹窗模式**：医生登录后首先加载科室——只有一个科室权限时
  直接进入该科室患者列表；有多个科室权限时自动弹出【选择科室】弹窗，选定后进入；
  页面顶部提供【切换科室】按钮，点击后再次弹窗切换（本次会话选择会被记住，
  刷新页面自动恢复）。
- **诊毕病历只读**：患者诊毕后再次打开病历页，所有输入框/下拉/富文本编辑器置灰禁用
  （不可编辑样式），写操作按钮（开单/保存/诊毕/转科/诊断证明/模板）自动隐藏，
  仅保留打印病历、就诊历史、修改患者信息等查看类操作，避免误解。

---

## [1.1.4] - 2026-08-17

### 修复

- **修复核心缺陷：各页面列表区域无限转圈加载的问题（1.0.0 起一直存在）**。
  根因有二：
  1. 页面布局中，视图自带的内联 `<script>`（如 `loadDeptList()` / `loadUserList()` /
     医生站患者列表等）位于公共 JS 库（`ajax.js` 等）**之前**执行，
     内联脚本在页面解析时立即调用 `Clinic.get()`，此时 `Clinic` 尚未定义，
     抛出 TypeError 后列表区域永远停留在转圈状态且无任何提示。
     → 已调整 `app/includes/layout.php`：公共 JS 库改为在视图内容之前加载，
       保证内联脚本执行时 `Clinic` 已就绪（所有模块的列表加载一次修复）。
  2. PHP 8.x 环境下，`warning/deprecated` 提示默认以 HTML 形式输出到响应体，
     混入 JSON 之前导致前端 `res.json()` 解析失败，
     表现为「新增成功 + 同时弹出网络错误」或列表一直转圈。
     → 已修复：`app/config/bootstrap.php` 对 AJAX/API 请求关闭错误显示
       （错误仍写入日志），接口始终返回纯净 JSON；
       同时修复具体告警源：`admin_user.php` / `doctor.php` / `nurse.php` 中
       `dept_ids` 为空（NULL）时 `explode()` 的 PHP 8 弃用告警。
- **修复夜间模式下左上角医院名称显示为黑色、与背景无法区分的问题**：
  侧边栏文字颜色原先使用 `var(--text-invert)`，夜间模式下该变量为深色，
  与深色侧边栏背景融为一体。已改为固定浅色 `#e2e8f0`（明亮/夜间模式均清晰可见）。

---

## [1.1.3] - 2026-08-17

### 修复

- 首次安装密码校验报错优化：当密码少于 6 位时，报错信息显示**实际输入的长度**
  （如「当前输入 5 位」），并在密码框下方增加【请切换英文输入法】提示，
  帮助定位中文输入法吞字导致密码不完整的问题（系统后端校验逻辑本身无缺陷，已实测通过）。

---

## [1.1.2] - 2026-08-17

### 变更

- 页脚版权信息改为固定格式自动生成【© 年份 医院名称 版权所有】：
  移除安装页与管理端设置页的手动输入框及保存逻辑（install.php / admin_settings.php），
  后台布局与落地页页脚直接由医院名称自动拼装，无需再手动维护。
- 首次安装页的「网站时区」由只读输入框改为**下拉选择**：
  直接调用服务器 PHP 的 `DateTimeZone::listIdentifiers()` 时区列表并按区域分组（Asia / Europe / America 等），
  默认自动选中创建管理员时的浏览器时区（若浏览器时区不在服务器列表中则回退 Asia/Shanghai）。
- 登录页移除【首次使用请先完成 系统安装】提示（首次使用会强制跳转安装页，该提示冗余）；
  落地页同步移除顶部与 Hero 区的「首次安装」链接（已安装状态下点击只会跳回登录页，属误导链接）。

---

## [1.1.1] - 2026-08-17

### 修复

- 修复未启用 PHP `mbstring` 扩展时系统报致命错误（`Call to undefined function mb_strlen/mb_substr`）的问题：
  `app/core/helpers.php` 增加 UTF-8 兼容实现（基于 `preg`，仅在扩展缺失时生效），
  ICD10 拼音自动生成与项目描述截断在精简 PHP 7.x 环境（如未安装 php-mbstring 的 Docker/Nginx 镜像）下也能正常运行。

---

## [1.1.0] - 2026-08-17

### 新增

- 诊室门口叫号屏幕（`/doctor/call`）：全屏大字体显示，顶部医院 LOGO + 名称（第二名称并排）、右上角实时时钟，中上方科室名称，主区【就诊中 / 下一位】+ 候诊人数，下方医生介绍卡（照片/姓名/职称/介绍），底部温馨提示（保持安静、按序排队、拒绝医托等）；每 10 秒自动刷新，多科室医生可切换科室。
- 忘记密码流程：修改密码页新增【忘记密码？】按钮 → 提交申请并通知管理员 → 管理员在审核中心通过后密码重置为初始密码 → 用户站内消息收到【设置新密码】按钮，无需验证原密码直接设置新密码（需求25）。
- 管理员【诊断管理】页：ICD10 诊断码 / 诊断名称 / 拼音首字母维护（新增/编辑/删除/检索），拼音首字母自动生成，医生病历初步诊断联动直接使用该库（需求36）。
- 护士站增强：患者搜索（ID / 身份证 / 门诊流水号，可查看全部既往就诊）、就诊详情弹窗（患者信息 + 当日医嘱 + 生命体征 + 护理记录）、【待执行医嘱】页签（护士站执行处方：等待执行 → 执行完成并反馈医生工作站，需求21）。
- 检验科 / 影像科工作站可提交新增检验 / 检查项目（页面与管理员一致，需管理员审核后生效，需求19）。
- 药房工作站：发药队列增加【发药完成】页签（显示发药药师与发药时间）；可新增药品与药品分类（页面与管理员一致，需管理员审核，需求20）；护士站执行处方不再出现在药房待发药队列。
- 预留 HIS 对外只读接口（`/api/his`，密钥认证）：患者档案 / 就诊列表 / 就诊状态 / 开单明细，为未来住院 HIS 等系统对接提供数据支持（需求23），系统设置中可配置接口密钥。
- 医生工作站头部显示医生姓名（工号）与职称；病历页头部同步显示（需求18.2）。
- 退费后的处方 / 开单可重新显示【删除】按钮（删除后恢复库存；已退费的处方退费时已恢复，不会重复恢复，需求18.8）。

### 变更

- 管理端接口按功能拆分为 `app/api/parts/` 多个子文件（settings / dept / user / item / drug / disp / audit），`admin.php` 仅负责分发，便于单独维护（需求33/34）。
- 检验/检查项目表单与药品表单收敛到 `app/includes/forms.php`，管理端、检验科、影像科、药房共用一套表单（需求28）。

### 修复

- 修复无身份证（急诊）患者重复挂号时 `id_card` 空字符串触发唯一约束冲突的问题（改为存储 NULL）。
- 修复审核中心【已处理】页签因状态值不匹配（handled vs approved/rejected）显示为空的问题；密码重置申请使用后的状态显示为【已使用】。
- 修复无身份证患者信息修改弹窗保存无效的问题（改为按患者唯一ID更新）。
- 挂号页无身份证时开放性别 / 出生日期 / 年龄手动填写（有身份证时仍自动计算并锁定）。

---

## [1.0.0] - 2026-08-17

首个完整可运行版本：一套同时服务挂号收费处、医生工作站、护士站、检验科、影像科、药房的门诊一体化系统（PHP 7.x + SQLite + 原生 JS/CSS，无 Composer、无第三方框架）。

### 新增

#### 安装与系统设置
- 首次安装向导：未设置管理员时全站强制跳转安装页，默认用户名 `admin`，密码由安装者设置。
- 安装时可配置医院名称、医院第二名称（可选）、全站时区（默认取创建管理员时的浏览器时区）、上传医院 LOGO。
- 系统设置：医院名称 / 第二名称 / LOGO / 页脚版权信息 / 全站时区 / 修改管理员密码。
- favicon 默认使用医院 LOGO，未上传 LOGO 则不显示。

#### 认证、主题与个人中心
- 登录 / 退出、Session 会话管理；首次登录提醒修改默认密码。
- 明亮 / 夜间 / 自动三种主题模式，偏好跟随用户账号保存。
- 个人信息维护（姓名 / 头像 / 学历 / 学位 / 职称 / 职务 / 个人介绍）。

#### 挂号收费处
- 挂号：身份证 18 位规则校验；输入身份证自动计算并锁定出生日期 / 年龄 / 性别；凭身份证检索既往登记自动回填（可修改）。
- 未填写身份证时费用类别默认自费且锁定，且仅显示急诊科室号源；填写身份证后显示门诊科室号源及费用类别选项（自费 / 居民医保 / 职工医保 / 其他）。
- 患者唯一 ID（年月日 8 位 + 当日序号）、门诊流水号（年月日 10 位 + 当日序号）、门诊就诊序号（各门诊 3 位独立递增，转科 / 退费 / 取消不影响序号唯一性）。
- 当日号源实时展示，号源已满禁止挂号并提示联系医生加号；医生加号仅限该患者本人身份证使用。
- 同一天同一患者同一【首次挂号科室】仅可挂一次，退费后可重挂但序号继续递增。
- 挂号缴费（模拟）→ 缴费成功后自动弹出挂号凭条打印。
- 挂号管理：按天查询当天 / 任意一天挂号信息（含退费、取消记录），挂号科室始终显示首次挂号科室；支持补打凭条。
- 缴费与退费管理：按患者 ID / 门诊流水号 / 身份证查询，分组显示每次就诊的已缴 / 待缴明细（开单医生、开单时间）；支持单项目缴费、批量缴费、申请退费（仅限未使用项目：检验未登记、检查未登记、药房未发药、处置未执行）；缴费成功弹出缴费凭条（项目列表 / 数量 / 金额 / 收费员）。
- 患者信息编辑弹窗：可修改除姓名、性别、身份证号、出生年月外的信息（手机号 / 职业 / 单位 / 婚姻 / 民族等），用于挂号管理、医生站病历主页、护士站护理记录页。

#### 医生工作站
- 多科室医生登录后先选科室再进入患者列表；单科室医生直接进入。
- 患者列表分待就诊 / 就诊中 / 就诊完毕，显示就诊序号（首次挂号科室 XX 门诊 XXX 号）、患者 ID、流水号、姓名、年龄、挂号时间、状态。
- 所见即所得（WYSIWYG）电子病历：医院信息与患者个人信息区不可编辑；主诉、现病史、既往史、过敏史、生命体征（血压 / 心率 / 脉搏 / 血氧 / 呼吸，与护士站双向同步）、意识状态（公共字典下拉）、体格检查、初步诊断、是否留观、医嘱。
- 门诊与急诊病历抬头模板区分（医院名称 / 第二名称 / 门诊或急诊电子病历 + 相应患者信息字段）。
- ICD-10 诊断联动：输入关键字实时检索（疾病名称 / 编码 / 拼音首字母），选中后编码固定、文字可改，删空后恢复检索下拉。
- 病历模板：医生可创建命名模板（个人 / 全科 / 全院三种范围），需管理员审核后生效。
- 必填校验：主诉、现病史、诊断为必填，缺失时禁止保存 / 诊毕 / 开单 / 打印（允许转科）。
- 开检验 / 检查 / 处置 / 处方：搜索 + 多选 / 单选（同一次开单同一项目仅一次），显示总费用，提交后自动弹出申请单 / 处方打印；开单弹窗右侧纵向流程状态图（开单 → 缴费 → 登记 → 执行 → 完成，步骤高亮）。
- 处置支持【护士站处置】勾选：未勾选由医生执行（缴费即已执行），勾选后由护士执行并显示执行护士姓名。
- 处方：显示药品厂家简称 + 库存，开单数量不得超库存；剂量 / 频次 / 途径同步药品设置；支持静脉输液子处方；【护士站执行】按给药途径设置自动默认勾选（可取消）；开方自动减库存、删除恢复、缴费后不可删、退费后可删。
- 转科：弹窗选择非当前科室，就诊序号与首次挂号信息不变；有前医生病历时可一键引用。
- 诊断证明：自动获取主诉与现病史，医生建议可手填，单次就诊仅一次，开具后自动打印。
- 患者查询：按 ID / 身份证查看既往每次就诊历史（首次挂号科室、就诊科室、病历、开单、医生）；可修改患者信息。
- 医生加号功能（号源满时，需患者身份证 + 姓名）。

#### 护士站
- 护士站处置执行（处置项目标记【需护士站处理】时进入护士站队列，完成后记录执行护士）。
- 生命体征录入（血压 / 心率 / 脉搏 / 血氧 / 呼吸），与医生工作站双向同步。
- 护理记录页面支持修改患者信息。

#### 检验科 / 影像科
- 患者缴费后进入待检验 / 待检查列表 → 登记 → 采样 / 检查 → 填写结论（检验：化验数值；影像：影像所见 + 结论）→ 提交自动弹出报告打印 → 移入完成列表。
- 查看已写报告；支持申请撤回（管理员批准后方可重新编辑）。

#### 药房
- 处方发药队列，发药后自动扣减库存。
- 库存管理：入库 / 出库 + 库存流水 + 低库存预警；开方减库存、删除处方恢复库存、退费后可删处方并恢复库存。

#### 管理员后台
- 科室管理：科室名称、门诊 / 急诊类型、挂号费；门诊科室设置每日上午 / 下午号源数量，急诊无需。
- 用户管理：职工工号、姓名、默认密码、照片、学历、学位、职称（医生 / 护士 / 检验 / 影像有职称选项，其余无）、职务、个人介绍；医生可关联多个科室。
- 检验 / 检查项目：分类设置（如 CT、MR 等）、项目名称、所属分类、价格、描述；检验项目额外支持计量单位、正常范围值、危急值上 / 下限。
- 药品设置：分类（西药 / 中成药 / 中药）、包装单位、药品剂型、用药频次、给药途径（新增途径时可勾选【是否需要护士站处理】，如静脉输液）。
- 药品信息：药品名称、通用名称、企业名称、企业简称（开方与处方打印显示）、包装单位、规格 / 含量、剂型、单次剂量、频次、途径、数量；是否处方药、是否限制类药品、备注。
- 处置项目管理：处置名称、费用、描述备注。
- 审核中心：检验 / 检查项目添加审核、药品添加审核、病历模板审核、报告撤回申请审核。

#### 公共能力
- 分散式数据库：按模块独立 .db（core / user / dept / patient / order / drug / medical / nurse / lab / disp / icd10），统一 DatabaseManager 建库、建表、增量迁移（幂等，兼容旧库），预留 MySQL 切换接口。
- 公共字典统一存放：`app/config/options_data.php`（性别 / 民族 / 职业 / 费用类别 / 婚姻状况 / 职称 / 学历 / 学位 / 职务 / 意识状态 / 给药途径 / 用药频次等），全站按需调用，避免重复代码。
- ICD-10 独立数据库维护（疾病名称 / 编码 / 拼音首字母），数据量大但易于维护。
- 统一打印中心：挂号凭条、门急诊电子病历、处方单、检验申请单、检查申请单、处置单、检验报告、检查报告、诊断证明、缴费凭条；打印模板统一管理于 `app/includes/print_templates.php`。
- 站内消息通知 + 打印提醒。
- 框架式界面：AJAX 局部刷新 + 模态对话框，业务操作无需整页刷新或跳转。
- 安全：CSRF 令牌、PDO 预处理防注入、password_hash / password_verify、输出转义防 XSS、Session Cookie HttpOnly + SameSite、登录重置会话 ID、角色级页面与接口权限校验、上传类型 / 大小校验 + 随机文件名、`data/` 与 `app/` 位于 Web 根目录之外不可直接访问。

---

[1.0.0]: 首次发布
