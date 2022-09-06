<?php
/**
 * Created by PhpStorm.
 * User: 小粽子
 * Date: 2018/11/5
 * Time: 20:33
 */

namespace App\WebSocket;
use Medoo\Medoo;

class DbHelp
{

    public function get_user_name($pdo, $user_id)
    {
        $database = new Medoo([
            // Initialized and connected PDO object.
            'pdo' => $pdo,

            // [optional] Medoo will have a different handle method according to different database types.
            'type' => 'mysql'
        ]);
        $datas = $database->select("users", [
            "id",

        ], [
            "id[=]" => $user_id
        ]);

        if (empty($datas)){
            return false;
        }
        return $datas[0]['user_name'];

    }




}
