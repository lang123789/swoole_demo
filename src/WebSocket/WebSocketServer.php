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
    private $mysql_config;

    private $table;// 这个是swoole专用的table，非数据库。
    private $server;

    private $db;// 这是带连接池的数据库对象。

    public function __construct()
    {

        // 实例化配置11
        // 内存表 实现进程间共享数据，也可以使用redis替代
        $this->createTable();
        $this->config = Config::getInstance();
        $this->mysql_config = Config::getInstance();

        // v6.0
        $this->create_mysql_pool();

    }

    private function create_mysql_pool()
    {

        $maxOpen = 50;        // 最大开启连接数
        $maxIdle = 20;        // 最大闲置连接数
        $maxLifetime = 3600;  // 连接的最长生命周期
        $waitTimeout = 0.0;   // 从池获取连接等待的时间, 0为一直等待
        $config = $this->mysql_config['mysql']; // 读取配置文件。
        $this->db = new \Mix\Database\Database('mysql:host='. $config['host'] .';port='. $config['port']
            .';charset='. $config['charset'] .';dbname='.$config['db_name'], $config['username'], $config['password']);

        $this->db->startPool($maxOpen, $maxIdle, $maxLifetime, $waitTimeout);
        \Swoole\Runtime::enableCoroutine(); // 必须放到最后，防止触发协程调度导致异常

    }



    public function run()
    {
        // 创建 server对象，是swoole专用的。
        $this->server = new \swoole_websocket_server(
            $this->config['socket']['host'],
            $this->config['socket']['port']
        );

        //v5.0 设置心跳检测
        $this->server->set(array(
            'heartbeat_idle_time' => 30, // 表示一个连接如果60秒内未向服务器发送任何数据，此连接将被强制关闭
            'heartbeat_check_interval' => 25,  // 表示每25秒遍历一次
        ));

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
        //echo "有消息：" . $data . "\n";
        //这里用户发来的信息已经分类型了。my_id开头，说明是系统自动从客户端发送的信息，用于识别身份。

        if (preg_match('#^ping#', $data)) {
           // echo "心跳来了 " . date("Y-m-d H:i:s") . "\n";
            $server->push($frame->fd, 'pong');// 返回一个消息，过会他会再次传来。

        } elseif (preg_match('#^my_id#', $data)) {
            $user_id = explode("|", $data)[1];
            $datas = $this->db->table('users')->where('id = ?', $user_id)->first();

            if (!$datas) {
                //如果用户不存在，则w我直接关闭连接。
                echo "非法连接。用户id:" . $user_id;
                $server->close($frame->fd);
                return;
            }
            $user_name = $datas->user_name;
            $user = [
                'fd' => $frame->fd,
                'user_name' => $user_name,
                'user_id' => strval($user_id),
            ];
            echo "有个人刚上线，数据：" . json_encode($user, JSON_UNESCAPED_UNICODE);

            echo "连接池当前状态：".json_encode( $this->db->poolStats() );

            echo "\n";
            // 放入内存表
            $this->table->set($frame->fd, $user);

            //给本人看 欢迎您。
            $server->push($frame->fd, json_encode([
                'type' => 'my_id',
                'message' => '欢迎您，' . $user_name,
            ]));
            // 通知其他人 某某 上线了。
            foreach ($this->table as $row) {
                if ($row['fd'] != $frame->fd) {
                    $server->push($row['fd'], json_encode([
                        'type' => 'my_id',
                        'message' => $user_name . ' 上线了',
                    ]));
                }
            }
        } elseif (preg_match('#^my_message#', $data)) {
            $user_id = explode("|", $data)[1];
            $message = explode("|", $data)[2];

            $datas = $this->db->table('users')->where('id = ?', $user_id)->first();

            if (!$datas) {
                //如果用户不存在，则w我直接关闭连接。
                echo "非法连接。用户id:" . $user_id;
                $server->close($frame->fd);
                return;
            }
            $user_name = $datas->user_name;

            $data = [
                'user_id' => $user_id,
                'content' => $message,
            ];
            $this->db->insert('messages', $data);

            echo "有人发消息，内容：" . $message;
            echo "连接池当前状态：".json_encode( $this->db->poolStats() );
            echo "\n";

            // 通知其他人 ，进行广播
            foreach ($this->table as $row) {
                if ($row['fd'] != $frame->fd) {
                    echo "推送消息：" . $message . "给fd：" . $row['fd'];
                    $server->push($row['fd'], json_encode([
                        'type' => 'my_message',
                        'message' => $message,
                        'from_user_name' => $user_name,

                    ]));
                }
            }
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
        $user_name = $user['user_name'];

        echo "有人下线，数据：" . json_encode($user, JSON_UNESCAPED_UNICODE);

        $this->table->del($fd);
        // 通知其他人 某某 下线了。
        foreach ($this->table as $row) {
            $server->push($row['fd'], json_encode([
                'type' => 'my_id',
                'message' => $user_name . ' 下线了',
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
        $this->table->column('user_id', \swoole_table::TYPE_STRING, 255);
        $this->table->create();
    }

}