<?php
/** app/Repositories/UserRepository.php — 本工具账号仓库 */
class PvUserRepository {

    public static function all() {
        return PvDatabase::q("SELECT id,username,display_name,role,status,created_at FROM users ORDER BY id ASC");
    }
    public static function find($id) {
        return PvDatabase::one("SELECT * FROM users WHERE id=?", array((int)$id));
    }
    public static function findByUsername($username) {
        return PvDatabase::one("SELECT * FROM users WHERE username=? LIMIT 1", array((string)$username));
    }
    public static function create($username, $password, $displayName, $role) {
        if ($username === '' || $password === '') throw new RuntimeException('用户名与密码不能为空');
        if (self::findByUsername($username)) throw new RuntimeException('用户名已存在');
        $role = $role === 'admin' ? 'admin' : 'user';
        return PvDatabase::insert(
            "INSERT INTO users(username,password_hash,display_name,role,status,created_at) VALUES(?,?,?,?,1,?)",
            array($username, password_hash($password, PASSWORD_DEFAULT), $displayName, $role, date('Y-m-d H:i:s'))
        );
    }
    public static function setStatus($id, $status) {
        return PvDatabase::exec("UPDATE users SET status=? WHERE id=?", array($status ? 1 : 0, (int)$id));
    }
    public static function setPassword($id, $password) {
        if ($password === '') throw new RuntimeException('密码不能为空');
        return PvDatabase::exec("UPDATE users SET password_hash=? WHERE id=?", array(password_hash($password, PASSWORD_DEFAULT), (int)$id));
    }
    public static function countAdmins() {
        return (int)PvDatabase::val("SELECT COUNT(*) FROM users WHERE role='admin' AND status=1");
    }
}
