<?php

//const HOST = "rm-j6c4mv17m1eht97yg818.mysql.rds.aliyuncs.com";
const HOST="192.168.21.99";
const USERNAME = "root";
const PASSWD = "iServer123";
const DBNAME = "upmngr";

const WSHOST = "0.0.0.0";
const WSPORT = "9502";

const ENCRYPTKEY="iServerBoard";

$mysqli = mysqli_init();
if (!$mysqli){
    die("mysqli_init failed");
}
if(!$mysqli->options(MYSQLI_INIT_COMMAND,"use upmngr")){
    die("setting MYSQLI_INIT_COMMAND");
}
if(!$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT,5)){
    die("setting MYSQLI_OPT_CONNECT_TIMEOUT");
}
if(!$mysqli->real_connect(HOST,USERNAME,PASSWD,DBNAME)){
    die("Connect Error no:".mysqli_connect_errno($mysqli).",Error Reason :".mysqli_connect_error());
}
// 服务器协程风格的ws服务器
Co\run(function () use ($mysqli){
    //完全协程化的 WebSocket 服务器实现，继承自 Co\Http\Server，底层提供了对 WebSocket 协议的支持，在此不再赘述，只说差异。
    $server = new Co\Http\Server(WSHOST, WSPORT, false);
    $server->set([
        "worker_num"=>8,
        "socket_connect_timeout"=>3.0,
        "log_level"=>SWOOLE_LOG_TRACE,
        "daemonize"=>1,
        "log_file"=>"/var/log/swoole_ws.log",
    ]);
    //服务器端
    $server->handle('/uuid/api', function ($request, $ws_response)use ($mysqli) {
        // 向客户端发送websocket握手信息
        $ws_response->upgrade();
        //循环接受和发送信息
        while (true) {
            //接受消息zhen
            $frame = $ws_response->recv();
            var_dump($frame);
            if ($frame === false) {
                $swoole_error_code = swoole_last_error();
                echo "error : " . swoole_strerror($swoole_error_code,9) . "\n";
                break;
            } else if ($frame == '') {
                break;
            } else {
                if ($frame->data == "close") {
                    $ws_response->close();
                    return;
                }
                //推送消息
                $data = $frame->data;
                //用户传递的数据:
                if(empty($data)){
                    ResponseReturn($ws_response,-1,"not found data");
                    return ;
                }
                parse_str($data,$query_arr);
                if(empty($query_arr)){
                    ResponseReturn($ws_response,-2,"data format error;");
                    return ;
                }
                //判断传递的数据
                if(isset($query_arr['uuid'])&&!empty($query_arr['uuid'])&&isset($query_arr['oem_name'])&&!empty($query_arr['oem_name'])){
                    $uuid = trim($query_arr['uuid']);
                    $oem_name = trim($query_arr['oem_name']);
                    $extenion_str = isset($query_arr["extenion_str"])&&!empty($query_arr["extenion_str"])?$query_arr["extenion_str"]:"null";
                    $query_sql = "select * from oem_board_card_burn where uuid = '$uuid' and oem_name = '$oem_name';";
                    echo "Query Sql :".$query_sql.PHP_EOL;

                    //$query_sql = "select * from oem_board_card_burn;";
                    $smt = $mysqli->prepare($query_sql);
                    if(!$smt){
                        printf("prepare sql error :".$query_sql);
                        return ;
                    }
                    $smt->execute();
                    $smt->bind_result($id_db,$uuid_db,$oem_name_db,$uuid_key_db,$add_time_db,$create_time_db,$extension_dir_db);
                    if($smt->fetch()){//查询到数据
                        //查询到数据
                        if(empty($uuid_key)){
                            $uuid_key = encryptString($uuid,ENCRYPTKEY);
                            $update_sql = "update oem_board_card_burn set uuid_key = '$uuid_key' where id = $id_db";
                            echo "Update SQL:".$update_sql.PHP_EOL;
                            $smt->prepare($update_sql);
                            if($smt->execute()){
                                ResponseReturn($ws_response,0,"success",array("Key"=>$uuid_key));
                            }
                        }else{
                            ResponseReturn($ws_response,0,"success",array("Key"=>$uuid_key));
                        }
                    }else{
                        //没有查询到数据
                        //将数据插入，生成Secrety Key;
                        $uuid_key = encryptString($uuid,ENCRYPTKEY);
                        $insert_sql = "insert into oem_board_card_burn values (null,'$uuid','$oem_name','$uuid_key'".",".time().",".time().",".$extenion_str.")";
                        echo $insert_sql.PHP_EOL;
                        $smt = $mysqli->prepare($insert_sql);
                        if($smt->execute()){
                            ResponseReturn($ws_response,0,"success",array("Key"=>$uuid_key));
                        }else{
                            //执行SQL语句失败
                            $error = $smt->error;
                            $errorno = $smt->errno;
                            ResponseReturn($ws_response,-4,"error :".$error.",errorno:".$errorno);
                        }
                    }
                    $smt->close();
                    //mysqli链接不能关闭;
                    //$mysqli->close();
                    return ;
                }else{
                    ResponseReturn($ws_response,-3,"data empty");
                    return ;
                }
            }
        }
    });
    $server->start();
});
//别的接口
function other_emtpy($request ,$ws_response){
    $ws_response->upgrade(); //101http握手
}
//uuid加密
function encryptString($uuid,$key=""){
    $encrypt_methods = openssl_get_cipher_methods();
    $cipher = "aes-128-ecb";
    if(!in_array($cipher,$encrypt_methods)){
        return false;
    }
    //$iv = openssl_random_pseudo_bytes($ivlength);
    //以指定的方式和 key 加密数据，返回原始或 base64 编码后的字符串。
    $cipertext_raw = openssl_encrypt($uuid,$cipher,$key,0);
    return $cipertext_raw;
}
//返回数据
function ResponseReturn($ws_response,$code = -1 ,$message = "",$data = array()){
    $result= array();

    if($code <0){
        $result=array(
            "code"=>$code,
            "msg"=>$message
        );
    }else{
        $result=array(
            "code"=>$code,
            "msg"=>$message,
            "data"=>$data,
        );
    }
    echo "Return String is :".json_encode($result).PHP_EOL;

    $ws_response->push(json_encode($result));
    return ;
}

/**
 * 生成随机的Key值
 */
function GenerateKey($uuid,$oem_name){
    return strtoupper(sha1($uuid,$oem_name));
}

