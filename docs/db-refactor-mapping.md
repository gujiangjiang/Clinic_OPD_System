# 数据库重构：字段与表结构映射总表

## 概述

本系统原采用**分散式 SQLite 存储**：每个业务模块一个独立 `.db` 文件，由 `DatabaseManager::pdo($key)` 按 key 连接。本次重构将：

1. **合并所有业务库**为统一主库 `clinic_main`（SQLite 文件 `data/db/clinic_main.db` 或 MySQL 库 `his_main`）
2. **ICD-10 保持独立**只读 SQLite 库 `icd10.sqlite`
3. **双驱动**：`DB_DRIVER=sqlite|mysql` 一键切换
4. **字段全盘规范化**：时间 `_at`/`_date`、布尔 `is_`/`has_`、EMR 核心字段、体征 `vital_*`

---

## 一、库映射（旧分散库 → 新主库）

| 旧库 Key | 旧文件 | 新表归宿 | 说明 |
|----------|--------|----------|------|
| `core` | `core.db` | `clinic_main` | 系统设置、消息、审核 |
| `user` | `user.db` | `clinic_main` | 系统用户 |
| `dept` | `dept.db` | `clinic_main` | 科室、加号记录 |
| `patient` | `patient.db` | `clinic_main` | 患者档案、挂号记录 |
| `order` | `order.db` | `clinic_main` | 开单、缴费、退费、库存 |
| `drug` | `drug.db` | `clinic_main` | 药品设置、药品信息 |
| `medical` | `medical.db` | `clinic_main` | 病历、结构化病历、模板、诊断证明、会诊 |
| `nurse` | `nurse.db` | `clinic_main` | 生命体征、护理记录 |
| `lab` | `lab.db` | `clinic_main` | 检验/检查项目、分类、结果、报告 |
| `disp` | `disp.db` | `clinic_main` | 处置项目 |
| `emr_templates` | `emr_templates.db` | `clinic_main` | 病历模板 |
| `clinic_rooms` | `clinic_rooms.db` | `clinic_main` | 诊室/叫号大屏 |
| `consultation` | `consultation.db` | `clinic_main` | 会诊记录 |
| `icd10` | `icd10.db` | **独立 icd10.sqlite** | ICD-10 诊断字典（只读） |

---

## 二、全表字段映射（旧字段 → 新规范化字段）

### 2.1 `settings` 系统设置

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| skey | `skey` | TEXT PK | 设置键名（保留，避免 MySQL 保留字 key） |
| svalue | `svalue` | TEXT | 设置值 |

### 2.2 `messages` 站内消息

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| from_name | `from_name` | TEXT | 发送者姓名 |
| from_user_id | `from_user_id` | INTEGER | 发送者用户ID |
| to_role | `to_role` | TEXT | 目标角色 |
| to_user_id | `to_user_id` | INTEGER | 目标用户ID |
| title | `title` | TEXT | 消息标题 |
| content | `content` | TEXT | 消息内容 |
| print_type | `print_type` | TEXT | 打印类型（通知前端打印用） |
| print_url | `print_url` | TEXT | 打印 URL |
| is_read | `is_read` | INTEGER | 是否已读（布尔 is_） |
| msg_type | `msg_type` | TEXT | 消息类型 system/patient |
| patient_name | `patient_name` | TEXT | 患者姓名 |
| visit_id | `visit_id` | INTEGER | 关联就诊ID |
| link_url | `link_url` | TEXT | 跳转链接 |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.3 `sent_messages` 已发送消息日志

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| sender_id | `sender_id` | INTEGER | 发送者ID |
| sender_name | `sender_name` | TEXT | 发送者姓名 |
| title | `title` | TEXT | 标题 |
| content | `content` | TEXT | 内容 |
| recipients | `recipients` | TEXT | 收件人列表 |
| recipient_count | `recipient_count` | INTEGER | 收件人数 |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.4 `audits` 审核记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| type | `type` | TEXT | 审核类型 |
| ref_id | `ref_id` | INTEGER | 关联实体ID |
| title | `title` | TEXT | 列表标题 |
| content | `content` | TEXT | 详情描述 |
| data | `data` | TEXT | 提交数据（JSON） |
| status | `status` | TEXT | 状态 pending/approved/rejected |
| proposer | `proposer` | TEXT | 提交人 |
| proposer_id | `proposer_id` | INTEGER | 提交人ID |
| created_at | `created_at` | TEXT | 创建时间 |
| handled_by | `handled_by` | TEXT | 处理人 |
| handled_at | `handled_at` | TEXT | 处理时间 |
| note | `note` | TEXT | 审核备注 |
| creation_source | `creation_source` | TEXT | 创建来源上下文 |

### 2.5 `users` 系统用户

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| emp_no | `emp_no` | TEXT | 工号（保留） |
| username | `username` | TEXT UNIQUE | 登录用户名 |
| password | `password` | TEXT | 密码哈希 |
| name | `name` | TEXT | 姓名 |
| role | `role` | TEXT | 角色 |
| dept_ids | `dept_ids` | TEXT | 关联科室ID（逗号分隔） |
| photo | `photo` | TEXT | 照片路径 |
| education | `education` | TEXT | 学历 |
| degree | `degree` | TEXT | 学位 |
| title | `title` | TEXT | 职称 |
| position | `position` | TEXT | 职务 |
| intro | `intro` | TEXT | 简介 |
| theme | `theme` | TEXT | 主题 auto/light/dark |
| sidebar | `sidebar` | TEXT | 侧边栏 expand/mini |
| pwd_changed | `pwd_changed` | INTEGER | 密码是否已改（保留） |
| status | `status` | INTEGER | 状态 1=正常 |
| created_at | `created_at` | TEXT | 创建时间 |
| last_login | `last_login` | TEXT | 最后登录时间 |
| current_dept_id | `current_dept_id` | INTEGER | 当前看诊科室 |
| print_auto | `print_auto` | INTEGER | 自动打印偏好（保留） |
| queue_days | `queue_days` | INTEGER | 候诊显示天数 |
| login_fail_count | `login_fail_count` | INTEGER | 登录失败次数 |
| login_locked_until | `login_locked_until` | TEXT | 锁定截止时间 |

### 2.6 `departments` 科室

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| name | `name` | TEXT | 科室名称 |
| type | `type` | TEXT | 类型 clinic/tech/other |
| fee | `fee` | REAL | 挂号费 |
| am_quota | `am_quota` | INTEGER | 上午号源 |
| pm_quota | `pm_quota` | INTEGER | 下午号源 |
| sort | `sort` | INTEGER | 排序 |
| status | `status` | INTEGER | 状态 |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.7 `extra_slots` 加号记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| dept_id | `dept_id` | INTEGER | 科室ID |
| reg_date | `reg_date` | TEXT | 挂号日期 |
| id_card | `id_card` | TEXT | 身份证号 |
| name | `name` | TEXT | 患者姓名 |
| doctor_id | `doctor_id` | INTEGER | 加号医生ID |
| doctor_name | `doctor_name` | TEXT | 加号医生姓名 |
| used | `used` | INTEGER | 是否已使用（保留） |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.8 `patients` 患者档案

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| patient_no | `patient_no` | TEXT UNIQUE | 患者编号 |
| id_card | `id_card` | TEXT UNIQUE | 身份证号 |
| name | `name` | TEXT | 姓名 |
| gender | `gender` | TEXT | 性别 |
| birth_date | `birth_date` | TEXT | 出生日期（日期字段用 _date） |
| age | `age` | INTEGER | 年龄 |
| ethnicity | `ethnicity` | TEXT | 民族 |
| marital | `marital` | TEXT | 婚姻状况 |
| occupation | `occupation` | TEXT | 职业 |
| work_unit | `work_unit` | TEXT | 工作单位 |
| address | `address` | TEXT | 地址 |
| phone | `phone` | TEXT | 电话 |
| past_history_type | `has_past_history` | TEXT | 是否有既往史（布尔→has_） |
| past_history_detail | `past_history` | TEXT | 既往史详情 |
| allergies | `allergy_history` | TEXT | 过敏史（EMR 规范 allerge_history） |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.9 `registrations` 挂号记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT UNIQUE | 门诊流水号 |
| visit_seq | `visit_seq` | INTEGER | 就诊序号 |
| first_dept_id | `first_dept_id` | INTEGER | 首挂科室ID |
| first_dept_name | `first_dept_name` | TEXT | 首挂科室名称 |
| current_dept_id | `current_dept_id` | INTEGER | 当前科室ID |
| current_dept_name | `current_dept_name` | TEXT | 当前科室名称 |
| session | `session` | TEXT | 号源时段 am/pm/all |
| fee_type | `fee_type` | TEXT | 费别 |
| fee | `fee` | REAL | 挂号费 |
| status | `status` | TEXT | 状态 |
| payment_time | `paid_at` | TEXT | 缴费时间（时间戳→_at） |
| cashier_id | `cashier_id` | INTEGER | 收费员ID |
| cashier_name | `cashier_name` | TEXT | 收费员姓名 |
| register_time | `registered_at` | TEXT | 挂号时间（时间戳→_at） |
| cancel_reason | `cancel_reason` | TEXT | 取消原因 |
| is_extra | `is_extra` | INTEGER | 是否加号（布尔 is_） |
| disposition | `disposition` | TEXT | 离院方式 |
| disposition_detail | `disposition_detail` | TEXT | 离院补充信息 |
| finish_time | `finished_at` | TEXT | 诊毕时间（时间戳→_at） |

### 2.10 `orders` 开单主表

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| order_type | `order_type` | TEXT | 开单类型 |
| order_no | `order_no` | TEXT | 申请单号 |
| doctor_id | `doctor_id` | INTEGER | 开单医生ID |
| doctor_name | `doctor_name` | TEXT | 开单医生姓名 |
| record_id | `record_id` | INTEGER | 关联病历ID |
| dept_id | `dept_id` | INTEGER | 开单科室ID |
| dept_name | `dept_name` | TEXT | 开单科室名称 |
| total_amount | `total_amount` | REAL | 总金额 |
| status | `status` | TEXT | 状态 |
| created_at | `created_at` | TEXT | 创建时间 |
| paid_at | `paid_at` | TEXT | 缴费时间 |
| refunded_at | `refunded_at` | TEXT | 退费时间 |
| done_by | `done_by` | TEXT | 完成人 |
| cat_name | `category_name` | TEXT | 检查分类名称（语义化） |
| source_order_id | `source_order_id` | INTEGER | 来源处方ID |

### 2.11 `order_items` 开单明细

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| order_id | `order_id` | INTEGER | 所属订单ID |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| item_type | `item_type` | TEXT | 项目类型 |
| item_id | `item_id` | INTEGER | 项目ID |
| item_name | `item_name` | TEXT | 项目名称 |
| spec | `spec` | TEXT | 规格 |
| unit_name | `unit` | TEXT | 单位（语义化） |
| company_short | `company_short` | TEXT | 厂商简称 |
| price | `price` | REAL | 单价 |
| quantity | `quantity` | INTEGER | 数量 |
| single_dose | `single_dose` | TEXT | 单次剂量 |
| frequency_name | `frequency` | TEXT | 频次（语义化） |
| route_name | `route` | TEXT | 途径（语义化） |
| need_nurse | `is_nurse` | INTEGER | 需护士站处理（布尔 is_） |
| sub_of | `sub_of` | INTEGER | 所属条目ID |
| group_no | `group_no` | INTEGER | 组号 |
| is_parent | `is_parent` | INTEGER | 是否主药（布尔 is_） |
| parent_item_id | `parent_item_id` | INTEGER | 主药条目ID |
| status | `status` | TEXT | 流程状态 |
| doctor_id | `doctor_id` | INTEGER | 开单医生ID |
| doctor_name | `doctor_name` | TEXT | 开单医生姓名 |
| executed_by | `executed_by` | TEXT | 执行人 |
| executed_at | `executed_at` | TEXT | 执行时间 |
| result_id | `result_id` | INTEGER | 关联结果ID |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.12 `payments` 缴费记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| order_id | `order_id` | INTEGER | 订单ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| kind | `kind` | TEXT | 缴费类型 visit/order |
| total | `total` | REAL | 总金额 |
| item_count | `item_count` | INTEGER | 项目数 |
| cashier_id | `cashier_id` | INTEGER | 收费员ID |
| cashier_name | `cashier_name` | TEXT | 收费员姓名 |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.13 `refunds` 退费记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| order_id | `order_id` | INTEGER | 订单ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| total | `total` | REAL | 退费金额 |
| reason | `reason` | TEXT | 退费原因 |
| cashier_id | `cashier_id` | INTEGER | 收费员ID |
| cashier_name | `cashier_name` | TEXT | 收费员姓名 |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.14 `inventory_trans` 库存流水

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| drug_id | `drug_id` | INTEGER | 药品ID |
| qty_change | `qty_change` | INTEGER | 数量变化 |
| type | `type` | TEXT | 类型 in/out |
| ref | `ref` | TEXT | 参考来源 |
| operator | `operator` | TEXT | 操作人 |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.15 `drug_settings` 药品设置

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| stype | `stype` | TEXT | 设置类型 |
| name | `name` | TEXT | 名称 |
| need_nurse | `is_nurse` | INTEGER | 需护士处理（布尔 is_） |
| bind_disposal_item_id | `bind_disposal_item_id` | INTEGER | 绑定处置项目ID |
| sort | `sort` | INTEGER | 排序 |

### 2.16 `drugs` 药品信息

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| name | `name` | TEXT | 药品名称 |
| generic_name | `generic_name` | TEXT | 通用名 |
| category | `category` | TEXT | 分类 |
| vendor | `vendor` | TEXT | 生产商 |
| vendor_short | `vendor_short` | TEXT | 生产商简称 |
| package_unit | `package_unit` | TEXT | 包装单位 |
| spec | `spec` | TEXT | 规格 |
| form | `form` | TEXT | 剂型 |
| single_dose | `single_dose` | TEXT | 单次剂量 |
| frequency_name | `frequency` | TEXT | 频次（语义化） |
| route_name | `route` | TEXT | 途径（语义化） |
| price | `price` | REAL | 价格 |
| qty | `qty` | INTEGER | 库存数量 |
| is_rx | `is_rx` | INTEGER | 是否处方药（布尔 is_） |
| is_limited | `is_limited` | INTEGER | 是否限制用药（布尔 is_） |
| note | `note` | TEXT | 备注 |
| need_nurse | `is_nurse` | INTEGER | 需护士处理（布尔 is_） |
| need_skin_test | `is_skin_test` | INTEGER | 需皮试（布尔 is_→is_） |
| skin_test_item_id | `skin_test_item_id` | INTEGER | 皮试处置项目ID |
| status | `status` | TEXT | 状态 |
| created_at | `created_at` | TEXT | 创建时间 |
| spec_dose | `spec_dose` | REAL | 规格剂量 |
| spec_dose_unit | `spec_dose_unit` | TEXT | 剂量单位 |
| spec_pack_qty | `spec_pack_qty` | INTEGER | 包装数量 |
| spec_pack_unit | `spec_pack_unit` | TEXT | 包装单位 |
| single_use_qty | `single_use_qty` | REAL | 单次使用数量 |

### 2.17 `records` 病历镜像表（扁平文本）

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| dept_id | `dept_id` | INTEGER | 科室ID |
| doctor_id | `doctor_id` | INTEGER | 医生ID |
| doctor_name | `doctor_name` | TEXT | 医生姓名 |
| chief_complaint | `chief_complaint` | TEXT | 主诉（EMR 规范） |
| present_illness | `present_illness` | TEXT | 现病史（EMR 规范） |
| past_history | `past_history` | TEXT | 既往史（EMR 规范） |
| allergy_history | `allergy_history` | TEXT | 过敏史（EMR 规范） |
| physical_exam | `physical_exam` | TEXT | 体格检查（EMR 规范） |
| consciousness | `consciousness` | TEXT | 意识状态（保留） |
| initial_diagnosis | `preliminary_diagnosis` | TEXT | 初步诊断（EMR 规范） |
| diagnosis_code | `icd10_code` | TEXT | ICD-10 编码 |
| is_observation | `is_observation` | INTEGER | 是否留观（布尔 is_） |
| visit_type | `visit_type` | TEXT | 初复诊类型 |
| advice | `doctor_advice` | TEXT | 医生建议/诊疗意见（EMR 规范） |
| status | `status` | TEXT | 状态 |
| created_at | `created_at` | TEXT | 创建时间 |
| updated_at | `updated_at` | TEXT | 更新时间 |
| patient_record_id | `patient_record_id` | INTEGER | 关联结构化病历ID |

### 2.18 `patient_records` 结构化病历（真理来源）

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| dept_id | `dept_id` | INTEGER | 科室ID |
| doctor_id | `doctor_id` | INTEGER | 医生ID |
| doctor_name | `doctor_name` | TEXT | 医生姓名 |
| record_type | `record_type` | TEXT | 病历类型 initial/progress |
| parent_record_id | `parent_record_id` | INTEGER | 前序病历ID |
| main_symptom | `chief_complaint` | TEXT | 主诉（EMR 规范） |
| symptom_duration | `symptom_duration` | TEXT | 症状持续时间 |
| symptom_unit | `symptom_unit` | TEXT | 时间单位 |
| informant | `informant` | TEXT | 病史陈述者 |
| arrival_way | `arrival_way` | TEXT | 就诊方式 |
| has_past_history | `has_past_history` | TEXT | 有无既往史 |
| allergies | `allergy_history` | TEXT | 过敏史（EMR 规范） |
| is_leave_hospital | `is_leave_hospital` | TEXT | 是否离院（布尔 is_） |
| primary_icd10 | `icd10_code` | TEXT | 主要诊断ICD-10编码 |
| primary_diagnosis | `diagnosis_name` | TEXT | 主要诊断名称 |
| emr_data | `emr_data` | TEXT | 完整结构化JSON |
| emr_print_text | `emr_print_text` | TEXT | 打印纯净文书 |
| status | `status` | TEXT | 状态 |
| created_at | `created_at` | TEXT | 创建时间 |
| updated_at | `updated_at` | TEXT | 更新时间 |
| consultation_id | `consultation_id` | INTEGER | 关联会诊ID |

### 2.19 `templates` 病历模板（旧模板表）

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名（兼容旧模板） |

### 2.20 `certificates` 诊断证明

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| doctor_id | `doctor_id` | INTEGER | 医生ID |
| doctor_name | `doctor_name` | TEXT | 医生姓名 |
| content | `content` | TEXT | 证明内容 |
| created_at | `created_at` | TEXT | 创建时间 |
| cert_no | `cert_no` | TEXT | 证明编号 |
| chief_complaint | `chief_complaint` | TEXT | 主诉快照 |
| present_illness | `present_illness` | TEXT | 现病史快照 |
| initial_diagnosis | `preliminary_diagnosis` | TEXT | 初步诊断快照 |

### 2.21 `referrals` 转科记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.22 `diag_orders` 诊断排序

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.23 `consents` 知情同意书

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.24 `vitals` 生命体征

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| visit_id | `visit_id` | INTEGER | 就诊ID |
| patient_no | `patient_no` | TEXT | 患者编号 |
| flow_no | `flow_no` | TEXT | 流水号 |
| bp_systolic | `vital_sbp` | INTEGER | 收缩压（体征 vital_ 前缀） |
| bp_diastolic | `vital_dbp` | INTEGER | 舒张压（体征 vital_ 前缀） |
| heart_rate | `vital_heart_rate` | TEXT | 心率（体征 vital_ 前缀） |
| pulse | `vital_pulse` | TEXT | 脉搏（体征 vital_ 前缀） |
| spo2 | `vital_spo2` | TEXT | 血氧饱和度（体征 vital_ 前缀） |
| respiration | `vital_respiration` | TEXT | 呼吸（体征 vital_ 前缀） |
| operator | `operator` | TEXT | 操作人 |
| created_at | `created_at` | TEXT | 创建时间 |
| record_id | `record_id` | INTEGER | 关联病历ID |

### 2.25 `nursing_records` 护理记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.26 `item_categories` 项目分类

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.27 `lab_items` 检验项目

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.28 `exam_items` 检查项目

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.29 `lab_group_members` 检验组合成员

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.30 `results` 检验/检查结果

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.31 `reports` 检验/检查报告

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.32 `disposal_items` 处置项目

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| id | `id` | INTEGER PK | 自增主键 |
| name | `name` | TEXT | 名称 |
| fee | `fee` | REAL | 费用 |
| description | `description` | TEXT | 描述 |
| status | `status` | TEXT | 状态 |
| need_nurse | `is_nurse` | INTEGER | 需护士处理（布尔 is_） |
| created_at | `created_at` | TEXT | 创建时间 |

### 2.33 `emr_templates` 新病历模板

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.34 `emr_template_depts` 模板科室关联

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.35 `clinic_rooms` 诊室/叫号大屏

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.36 `consultations` 会诊记录

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 保留原字段名 |

### 2.37 `icd10` ICD-10 诊断字典（独立只读库）

| 旧字段 | 新字段 | 类型 | 说明 |
|--------|--------|------|------|
| 全部 | 保持不变 | — | 独立 SQLite 只读库，不参与主库合并 |

---

## 三、字段重命名汇总清单

### 3.1 时间字段 `_time` → `_at`

| 表 | 旧字段 | 新字段 |
|----|--------|--------|
| registrations | `register_time` | `registered_at` |
| registrations | `payment_time` | `paid_at` |
| registrations | `finish_time` | `finished_at` |

### 3.2 生命体征字段 `*` → `vital_*`

| 表 | 旧字段 | 新字段 |
|----|--------|--------|
| vitals | `bp_systolic` | `vital_sbp` |
| vitals | `bp_diastolic` | `vital_dbp` |
| vitals | `heart_rate` | `vital_heart_rate` |
| vitals | `pulse` | `vital_pulse` |
| vitals | `spo2` | `vital_spo2` |
| vitals | `respiration` | `vital_respiration` |

### 3.3 EMR 核心字段标准化

| 表 | 旧字段 | 新字段 | 规范标准 |
|----|--------|--------|----------|
| records | `initial_diagnosis` | `preliminary_diagnosis` | 初步诊断 |
| records | `diagnosis_code` | `icd10_code` | ICD-10 编码 |
| records | `advice` | `doctor_advice` | 诊疗意见 |
| patient_records | `main_symptom` | `chief_complaint` | 主诉 |
| patient_records | `allergies` | `allergy_history` | 过敏史 |
| patient_records | `primary_icd10` | `icd10_code` | 主要诊断编码 |
| patient_records | `primary_diagnosis` | `diagnosis_name` | 主要诊断名称 |
| patients | `allergies` | `allergy_history` | 过敏史 |
| patients | `past_history_type` | `has_past_history` | 有无既往史 |
| patients | `past_history_detail` | `past_history` | 既往史详情 |
| certificates | `initial_diagnosis` | `preliminary_diagnosis` | 初步诊断快照 |

### 3.4 布尔字段 `need_*` → `is_*`

| 表 | 旧字段 | 新字段 |
|----|--------|--------|
| order_items | `need_nurse` | `is_nurse` |
| drug_settings | `need_nurse` | `is_nurse` |
| drugs | `need_nurse` | `is_nurse` |
| disposal_items | `need_nurse` | `is_nurse` |
| drugs | `need_skin_test` | `is_skin_test` |

### 3.5 语义化字段名

| 表 | 旧字段 | 新字段 | 说明 |
|----|--------|--------|------|
| order_items | `unit_name` | `unit` | 单位 |
| order_items | `frequency_name` | `frequency` | 频次 |
| order_items | `route_name` | `route` | 途径 |
| drugs | `frequency_name` | `frequency` | 频次 |
| drugs | `route_name` | `route` | 途径 |
| orders | `cat_name` | `category_name` | 分类名称 |

---

## 四、数据库连接 API 变更

| 旧调用 | 新调用 | 说明 |
|--------|--------|------|
| `DB::pdo('core')` | `DatabaseManager::getMain()` | 主库连接 |
| `DB::pdo('patient')` | `DatabaseManager::getMain()` | 主库连接 |
| `DB::pdo('icd10')` | `DatabaseManager::getIcd10()` | ICD-10 只读库 |
| `DB::q('core', ...)` | `DB::q(...)` | 主库查询（默认 getMain） |
| `DB::q('patient', ...)` | `DB::q(...)` | 主库查询（默认 getMain） |
| `DB::one('medical', ...)` | `DB::one(...)` | 主库查询 |
| `DB::val('user', ...)` | `DB::val(...)` | 主库查询 |
| `DB::exec('patient', ...)` | `DB::exec(...)` | 主库写操作 |
| `DB::insert('patient', ...)` | `DB::insert(...)` | 主库插入 |

> 注意：`DB::pdo('icd10')` 替换为 `DatabaseManager::getIcd10()`，ICD-10 库不参与事务。

---

## 五、SQL 方言兼容（SQLite ↔ MySQL）

| 特性 | SQLite | MySQL | 兼容写法 |
|------|--------|-------|----------|
| 自增主键 | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INTEGER PRIMARY KEY AUTO_INCREMENT` | 由 `dialectAutoIncrement()` 处理 |
| 最后插入ID | `lastInsertId()` | `lastInsertId()` | 统一 PDO 方法 |
| 布尔值 | `INTEGER` 0/1 | `TINYINT(1)` 0/1 | 统一 INTEGER |
| 事务 | `BEGIN/COMMIT/ROLLBACK` | `BEGIN/COMMIT/ROLLBACK` | 统一 PDO 方法 |
| 外键 | `PRAGMA foreign_keys = ON` | 默认开启 | 由 `dialectPragmas()` 处理 |
| 字符串连接 | `\|\|` | `CONCAT()` | 统一用 PHP 拼接 |
| 日期函数 | `datetime('now','localtime')` | `NOW()` | 由 `dialectNow()` 处理 |
| 子串 | `substr()` | `SUBSTRING()` | 统一用 PHP 处理 |
| IFNULL | `IFNULL()` | `IFNULL()` | 兼容 |
| LIMIT | `LIMIT n` | `LIMIT n` | 兼容 |
| 列存在检查 | `PRAGMA table_info` | `SHOW COLUMNS` | 由 `columnExists()` 处理 |
| REPLACE | `INSERT OR REPLACE` | `REPLACE INTO` | 由 `dialectReplace()` 处理 |
| 索引创建 | `CREATE INDEX IF NOT EXISTS` | `CREATE INDEX IF NOT EXISTS` | 兼容 |