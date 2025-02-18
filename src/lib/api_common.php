<?php
include_once 'api_logger.php';

function myErrorHandler($errno, $errstr, $errfile, $errline){
    MyLog()->Append(sprintf('error no: %s error: %s file %s line: %s',
        $errno,$errstr,$errfile,$errline),7);
}

function dbUpdateHandler($errno, $errstr, $errfile, $errline){
    MyLog()->Append('Update chunk rejected - this is normal');
}


function backup_restore(){
    try{
        $sec = \Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create();
        $user = $sec->getUser();
        if(!$user || $user->isClient){ return; }
        $bu = new Admin_Backup();
        if($bu->run()){
            $ul = $_FILES['backup']['tmp_name'] ?? null;
            if($ul){ copy($ul,'data/data.db'); }
        }
    }
    catch (\Exception $e){
        $msg = sprintf('backup restore error: %s, trace: %s',
            $e->getMessage(),$e->getTraceAsString());
        MyLog()->Append($msg,6);
        echo sprintf('{"status":"failed","error":true,"message":%s,"data":[]}',$msg);
    }
}

function respond($msg,$err = false,$data = [])
{
    $status = $err ? 'failed' : 'ok';
    $response = [
        'status' => $status,
        'error' => $err,
        'message' => $msg,
        'data' => $data,
    ];
    if(!headers_sent()) header('content-type: application/json');
    if ($err && !headers_sent()) { // failed header
        header('X-API-Response: 202', true, 202);
    }
    echo json_encode($response,JSON_PRETTY_PRINT);
}
