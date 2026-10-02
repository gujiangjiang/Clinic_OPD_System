<?php
/**
 * ============================================================
 * services/fhir/adapters/OrganizationAdapter.php — 机构（医院）适配器
 * ============================================================
 * 标准 FHIR R4 Organization：对外暴露本院机构名称，供 PACS 浏览器等
 * 外部系统获取并展示（系统/关于/影像预览医院显示；DICOM InstitutionName
 * 与之对应）。数据源：系统设置 hospital_name（留空回退系统标识）。
 * id 规则：organization-main（本院）。
 * 搜索参数：_id、name。
 * ============================================================ */
class OrganizationAdapter extends FhirAdapter {

    public static function resourceType() { return 'Organization'; }

    /** 本院机构名称 */
    private static function mainName() {
        $n = trim((string)setting('hospital_name', ''));
        return $n !== '' ? $n : 'Clinic OPD System';
    }

    private static function mainResource() {
        return array(
            'resourceType' => 'Organization',
            'id' => 'organization-main',
            'active' => true,
            'name' => self::mainName(),
        );
    }

    protected static function findRowByBareId($bareId) {
        $bareId = (string)$bareId;
        if ($bareId === 'main' || $bareId === 'organization-main' || $bareId === 'organization') {
            return array('id' => 'main');
        }
        return null;
    }

    public static function toResource($row) {
        return self::mainResource();
    }

    public static function search($params) {
        $match = true;
        if (isset($params['_id']) && trim((string)$params['_id']) !== '') {
            $ok = false;
            foreach (explode(',', (string)$params['_id']) as $v) {
                $v = trim($v);
                if ($v === 'main' || $v === 'organization-main' || $v === 'organization') { $ok = true; break; }
            }
            $match = $ok;
        }
        if ($match && isset($params['name']) && trim((string)$params['name']) !== '') {
            $match = (stripos(self::mainName(), trim((string)$params['name'])) !== false);
        }
        $entries = $match ? array(self::mainResource()) : array();
        return array('total' => count($entries), 'entries' => $entries, 'patientRefs' => array());
    }

    public static function searchParams() {
        return array(
            array('name' => '_id', 'type' => 'token'),
            array('name' => 'name', 'type' => 'string'),
        );
    }
}
