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

    public function __construct()
    {

        // 实例化配置
        $this->config = Config::getInstance();
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
        echo "有人上线";

    }



    public function message(\swoole_websocket_server $server, \swoole_websocket_frame $frame)
    {

    }



    /**
     * 客户端关闭的时候
     *
     * @param \swoole_websocket_server $server
     * @param int $fd
     */
    public function close(\swoole_websocket_server $server, int $fd)
    {

    }


}