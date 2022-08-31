<?php
/**
 * Created by PhpStorm.
 * User: 小粽子
 * Date: 2018/11/5
 * Time: 20:24
 */

namespace App\WebSocket;

/**
 * 简单的聊天室
 *
 * Class WebSocketServer
 * @package App\WebSocket
 */
class WebSocketServer
{
    private $config;
    private $table;
    private $server;

    private $user_all;

    public function __construct()
    {

        // 实例化配置
        // 内存表 实现进程间共享数据，也可以使用redis替代
        $this->createTable();
        $this->config = Config::getInstance();
        $this->user_all = $this->config['socket']['user_all'];
    }

    public function run()
    {
        $this->server = new \swoole_websocket_server(
            $this->config['socket']['host'],
            $this->config['socket']['port']
        );

        $this->server->on('open', [$this, 'open']);
        $this->server->on('message', [$this, 'message']);
        $this->server->on('close', [$this, 'close']);

        $this->server->start();
    }

    public function open(\swoole_websocket_server $server, \swoole_http_request $request)
    {
        echo "有人上线\n";

    }



    public function message(\swoole_websocket_server $server, \swoole_websocket_frame $frame)
    {

        $data = $frame->data;
        //这里用户发来的信息已经分类型了。my_id开头，说明是系统自动从客户端发送的信息，用于识别身份。




        if (preg_match( '#my_id|#', $data )) {

            $user_id= explode("|",$data)[1] ;
            $arr=$this->user_all;


            $user_name = isset( $arr[$user_id] )? $arr[$user_id] :'';
            if (!$user_name){
                //如果用户不存在，则w我直接关闭连接。
                echo "非法连接。用户id:".$user_id;
                $server->close($frame->fd);


               return;
            }

            $user = [
                'fd' => $frame->fd,
                'user_name' => $user_name,
                'uesr_id' => $user_id,
            ];
            echo "有个人刚上线，数据：".json_encode( $user, JSON_UNESCAPED_UNICODE );
            // 放入内存表
            $this->table->set($frame->fd, $user);

            $server->push($frame->fd, json_encode([
                'type' => 'my_id',
                'message' => '欢迎您，'.$user_name,
            ]));
        }


    }



    /**
     * 客户端关闭的时候
     *
     * @param \swoole_websocket_server $server
     * @param int $fd
     */
    public function close(\swoole_websocket_server $server, int $fd)
    {
        $user = $this->table->get($fd);

        echo "有人下线，数据：".json_encode( $user, JSON_UNESCAPED_UNICODE );

       // $this->pushMessage($server, "{$user['name']}离开聊天室", 'close', $fd);
        $this->table->del($fd);
    }

    /**
     * 推送消息
     *
     * @param \swoole_websocket_server $server
     * @param string $message
     * @param string $type
     * @param int $fd
     */
    private function pushMessage(\swoole_websocket_server $server, string $message, string $type, int $fd)
    {
        $message = htmlspecialchars($message);
        $datetime = date('Y-m-d H:i:s', time());
        $user = $this->table->get($fd);

        foreach ($this->table as $item) {
            // 自己不用发送
            if ($item['fd'] == $fd) {
                continue;
            }

            $server->push($item['fd'], json_encode([
                'type' => $type,
                'message' => $message,
                'datetime' => $datetime,
                'user' => $user
            ]));
        }
    }


    /**
     * 创建内存表
     */
    private function createTable()
    {
        $this->table = new \swoole_table(1024);
        $this->table->column('fd', \swoole_table::TYPE_INT);

        $this->table->column('user_name', \swoole_table::TYPE_STRING, 255);
        $this->table->column('user_id', \swoole_table::TYPE_INT, 255);

        $this->table->create();
    }

    private function allUser()
    {
        $users = [];
        foreach ($this->table as $row) {
            $users[] = $row;
        }
        return $users;
    }


}