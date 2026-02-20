<?php

class User {
    public static function isAuthorized() {
        return defined('USERID') && USERID > 0;
    }

    public static function isAdmin() {
        return self::isAuthorized() && ADMIN > 0;
    }

    public static function is($str) {
        $mode = $GLOBALS['session']['mode'];
        $flag = false;
        for ($i = 0; $i < strlen($str); $i++) {
            if ($mode[$i] == $str[$i]) {
                $flag = true;
                break;
            }
        }
        return $flag;
    }

    public static function get($id = null) {
        if ($id === null && self::isAuthorized()) {
            return self::get(USERID);
        }

        $res = db_query("SELECT NAME FROM ".PREFIX."ROWS WHERE ID=" . $id);
        if ($row = mysql_fetch_array($res)) {
            list($name, $login, $phone, $email) = explode("|", $row['NAME']);

            return [
                'id' => $id,
                'name' => $name,
                'login' => $login,
                'phone' => $phone,
                'email' => $email
            ];
        }

        return null;
    }
}