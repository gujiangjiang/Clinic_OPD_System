# 影像检查工作流与标准映射（Accession / Study / Report）

> 本文约定门诊一体化（Clinic）与 PACS（含内置模拟服务器）之间的影像检查
> 数据关系与标准映射，作为联调与对接外部 HIS/RIS/PACS 的唯一依据。
> 版本：随 `APP_VERSION` 同步演进。

## 1. 业务模型（A2：一申请单 N Study，报告合并）

```
申请单（检查号 order_no = AccessionNumber）       1 个
├─ 检查项目（procedure，ServiceRequest/Task）      1..N 个   ← 工作项 / MWL SPS 粒度
│    └─ 检查（Study，StudyInstanceUID）            1 个      ← 摄片产生
└─ 报告（DiagnosticReport，report_no）             1 个      ← 与申请单 1:1
```

- **报告单位 = 申请单**：一个检查号 ↔ 一个报告号（严格 1:1）。
- **工作项单位 = 检查项目**：每个检查项目一条 `ServiceRequest` + 一条 `Task`（对齐标准 MWL 的 SPS）。
- **检查单位 = Study**：每个检查项目摄片后产生一个 `StudyInstanceUID`；同一申请单下的多个
  Study **共享同一个 `AccessionNumber`**（= 申请单号）。
- **报告引用**：`DiagnosticReport.imagingStudy` 引用该申请单下的 **1..N** 个 Study。

## 2. 标准映射表

| 业务概念 | DICOM | HL7 / FHIR |
| --- | --- | --- |
| 申请单（检查号） | `AccessionNumber (0008,0050)`；`RequestAttributesSequence (0040,0275)` 中的 Placer Order Number | `ServiceRequest.requisition` / `Task.groupIdentifier` |
| 检查项目（procedure） | MWL Scheduled Procedure Step；`ProcedureCodeSequence (0008,1032)` | `ServiceRequest` + `Task` |
| 检查（Study） | `StudyInstanceUID (0020,000D)`；`StudyDescription (0008,1030)` | `ImagingStudy`（每项目 1 个） |
| 序列 | `SeriesInstanceUID (0020,000E)`；`SeriesDescription (0008,103E)` | `ImagingStudy.series` |
| 报告 | — | `DiagnosticReport`（按申请单 1 份，`imagingStudy` 0..*） |
| 检索 / 取像 | DICOMweb QIDO-RS / WADO-RS（Study 级） | — |
| 阅片直链 | — | `studyUID`（单 Study，标准）/ `accessionNumber`（IHE IID，申请单级） |

## 3. 关键约定

1. **StudyDescription 单值**：一个申请单多个检查项目时，`StudyDescription` 只描述该 Study
   对应的**单个检查项目**；"申请单包含多个项目"由多条 `ServiceRequest`/`Task` 与该申请单
   下的多个 Study 表达，不再塞进一个字段。
2. **DICOMweb 检索保持 Study 级**（标准）：`GET /studies?AccessionNumber=` 可能返回多个 Study；
   门诊报告层按申请单聚合展示。**不得**为聚合而改造 DICOMweb 语义。
3. **报告号 1:1**：报告修订**沿用同一报告号**（版本化，保留历史版本），不新开报告号。
4. **登记 / 摄片按申请单**：一次登记整张申请单；一次摄片完成该单全部检查项目
   （各生成一个 Study，共享 Accession）。
5. **归组兜底**：对接真实 PACS 时，优先以 `AccessionNumber` 归组；若对端按 Study 分配了
   不同 Accession，则回退 `RequestAttributesSequence`（Placer Order Number）/患者+时间段归组。
6. **影像引用（imaging_refs）按 Study 存储**，归属到申请单（`order_id`）；一张申请单可有多条引用。

## 4. 状态机（Task，对齐 MWL/MPPS）

`requested`（待登记）→ `accepted`（已登记待摄片）→ `in-progress`（摄片中）→ `completed`（已摄片）；
`cancelled`（退费/取消）。摄片回写 `completed` 时，门诊按检查号从区域 PACS 解析并登记影像引用。
