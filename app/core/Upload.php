<?php
/**
 * ============================================================
 * Upload.php v1.0.0 — 文件上传
 * ============================================================
 * 说明：
 * 1. 上传目录：public/uploads/logo（医院 LOGO）、
 *    public/uploads/user/{角色}（用户照片）
 * 2. 严格校验：文件类型白名单 + 大小上限 + 随机文件名（防路径穿越）
 * 3. 返回相对 public 的路径（如 uploads/user/doctor/xxx.png）
 * ============================================================ */
class Upload {

    /**
     * 保存上传文件
     * @param string $field    表单字段名
     * @param string $subdir   子目录（如 logo、user/doctor）
     * @param array  $allowed  允许的扩展名
     * @param int    $max      大小上限（字节）
     * @return array ['ok'=>true,'path'=>'uploads/...'] 或 ['error'=>'提示']
     */
    public static function save($field, $subdir, $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp'), $max = 2097152) {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return array('error' => '未选择文件或上传失败');
        }
        $f = $_FILES[$field];
        if ($f['size'] > $max) {
            return array('error' => '文件大小不能超过 ' . round($max / 1048576) . 'MB');
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return array('error' => '文件类型不允许（仅支持 ' . implode('/', $allowed) . '）');
        }
        // 图片二次校验
        $info = @getimagesize($f['tmp_name']);
        if ($info === false) {
            return array('error' => '文件不是有效图片');
        }
        $dir = UPLOAD_DIR . '/' . $subdir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // 随机文件名，防止路径穿越与重名覆盖
        $name = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            return array('error' => '文件保存失败，请检查目录权限');
        }
        return array('ok' => true, 'path' => 'uploads/' . $subdir . '/' . $name);
    }
}
