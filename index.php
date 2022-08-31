<?php
require __DIR__ .'/vendor/autoload.php';

$arr= parse_ini_file('./.env');
$JS_IP = $arr['JS_IP'];

$user_id = $_REQUEST['id'];
if (!$user_id) {
    exit("请填写用户id, 网址后面加 ?id=整数");
}

$config = \App\WebSocket\Config::getInstance();
//假定这是数据库的查询结果
$user_all = $arr =  $config['socket']['user_all'];

$user_id=intval($user_id);
if ($user_id<1 || $user_id>5){
    exit("用户id不合法");
}

$user_name = isset( $arr[$user_id] )? $arr[$user_id] :'';
if (!$user_name){
    exit("请求错误");
}

$random = time();


$s= <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <title>swoole聊天室</title>
    <link rel="stylesheet" href="./css/reset.css"/>
    <link
            rel="stylesheet"
            href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css"
            integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T"
            crossorigin="anonymous"
    />
    <link rel="stylesheet" href="./css/custom.css"/>
    <link rel="stylesheet" href="./css/login.css"/>
    <link rel="stylesheet" href="./css/chat.css?{$random}"/>
    <script src="http://lib.sinaapp.com/js/jquery/1.8/jquery.min.js"></script>
    <script src="./js/login.js"></script>
    <script>
        function activate_chat_js() {
            
           
            // right side
            const me = 1;
            const chatter = 0;

            // 这是每个人自己发消息。子程序1。
            function update_chatWindow(incoming_message, from) {
                if (from === 1) {
                    var msg_html = my_message_html(incoming_message);
                } else if (from === 0) {
                    var msg_html = chatter_message_html(incoming_message);
                }
                $(".chat-window").append(msg_html);
            }
            
            // 这是自己发消息。子程序2。
            function my_message_html(incoming_message) {
                var newMessage = '<div class="me-wrapper">' +
                    '<div class="me-message container">' + incoming_message + '</div>' +
                    '<div class="me-avatar">' +
                    '我自己' +
                    '</div></div>';
                return newMessage;
            }

            // click btn to send message
            // 这也是每个人自己发消息。最开始点击。
            $("#btn-send-message").click(function () {
                var user_input_area = $("#user-input-value");
                if (user_input_area.text().length == 0) {

                } else {
                    var message=user_input_area.text();
                    update_chatWindow(user_input_area.text(), 1);
                    user_input_area.text("");
                    $('.chat-window').scrollTop($('.chat-window')[0].scrollHeight);
                    
                    // v4.0修改，把消息给服务器。
                    window.websocket.send("my_message|{$user_id}|"+ message);
                }
            });

            // ctrl+enter send message
            // 这也是每个人自己发消息，用回车键。
            $('#user-input-value').keydown(function (e) {
                if ((event.keyCode == 10 || event.keyCode == 13)) {
                    $('#btn-send-message').trigger("click");
                    event.cancelBubble = true;
                    event.preventDefault();
                }
            });
        }
        
        // 这是显示在中间的系统消息。由服务器推送。
        function system_chatWindow(incoming_message) {
            var msg_html = '<div class="system-message">' + incoming_message + '</div>' ;
            $(".chat-window").append(msg_html);
            $('.chat-window').scrollTop($('.chat-window')[0].scrollHeight);
        }
            
        // v4.0 ,这是被onmessage函数调用的方法，
        // 这是其他人发的消息，广播到每个机器。
        function update_chatWindow2(incoming_message, from) {
            var msg_html = '<div class="current-chatter-wrapper">' +
                '<div class="chatter-avatar">' +
                from +
                '</div>' +
                '<div class="chatter-message container">' + incoming_message + '</div>' +
                '</div>';
                
            $(".chat-window").append(msg_html);
            $('.chat-window').scrollTop($('.chat-window')[0].scrollHeight);
        }
            
    </script>
    <script src="./js//logout.js"></script>
    <script >
        window.onload = function(){
            activate_chat_js();

            var wsServer = 'ws://{$JS_IP}:9501';
            var websocket = new WebSocket(wsServer);
            window.websocket = websocket;
            websocket.onopen = function (evt) {
                console.log("Connected to WebSocket server.");
                heartCheck.reset().start();      //心跳检测重置
                websocket.send("my_id|{$user_id}");
            };

            websocket.onclose = function (evt) {
                console.log("Disconnected");
            };

            websocket.onmessage = function (evt) {
                console.log('从服务器接受的数据 ' + evt.data);
                // 心跳最前面检测
                if (evt.data=='pong'){
                    heartCheck.reset().start();      //心跳检测重置
                    return;
                }
                
                
                var user = JSON.parse(evt.data);
                if (user.type=='my_id'){
                   system_chatWindow(user.message);
                }
                // v4.0修改。其他人接受某人的消息广播。
                if (user.type=='my_message'){
                    update_chatWindow2(user.message, user.from_user_name );
                }
            
            };

            websocket.onerror = function (evt, e) {
                console.log('Error occured: ' + evt.data);
            };
            
            var heartCheck = {
                timeout: 5000,        //单位毫秒，5秒发一次心跳,改成61000，就是61秒就会被服务器自动断掉，
                                       // 说明，只需这个值大于 服务器的heartbeat_idle_time 与 heartbeat_check_interval 的和，就一定会自动停掉。
                timeoutObj: null,
                serverTimeoutObj: null,
                reset: function(){
                    clearTimeout(this.timeoutObj);
                    clearTimeout(this.serverTimeoutObj);
                    return this;
                },
                start: function(){
                    var self = this;
                    this.timeoutObj = setTimeout(function(){
                        //这里发送一个心跳，后端收到后，返回一个心跳消息，
                        //onmessage拿到返回的心跳就说明连接正常
                        websocket.send("ping");
                        console.log("ping")
                       
                    }, this.timeout)
                }
            };
            
        }
    </script>
</head>
<body>


<div class="app container" style="display:flex">
    <div class="left-side" style="visibility:hidden">
        <div class="user-wrapper"></div>
        <div class="other-users-wrapper"></div>
    </div>

    <div class="right-side">
        <div class="header">
            <div class="current-chatter"> <div class="div1">聊天室</div> </div>

        </div>
        <div class="chat-window container">

        </div>
        <div class="user-function" style="visibility:hidden">

        </div>


        <div class="user-input">
            <div
                    id="user-input-value"
                    class="user-text"
                    contenteditable="true"
            ></div>
            <div class="btn-send">
                <button
                        type="button"
                        id="btn-send-message"
                        class="btn btn-outline-success"
                >
                    Send
                </button>
            </div>
        </div>
    </div>
</div>

<!-- pop-up window -->


<script
        src="https://code.jquery.com/jquery-3.3.1.slim.min.js"
        integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo"
        crossorigin="anonymous"
></script>
<script
        src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"
        integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1"
        crossorigin="anonymous"
></script>
<script
        src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"
        integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM"
        crossorigin="anonymous"
></script>
</body>
</html>
HTML;

echo $s;