<?php
const MyCacheVersion = '1.0.1b';
const MAX_BACKUPS = 6;
const MY_VERSION = '1.8.9';

function myErrorHandler($errno, $errstr, $errfile, $errline){
    throw new Exception(sprintf('error no: %s error: %s',$errno,$errstr));
}

function run_setup(){ $setup = new ApiSetup(); $setup->run(); }

function respond($msg,$err = false,$data = [])
{
    $status = $err ? 'failed' : 'ok';
    $response = [
        'status' => $status,
        'error' => $err,
        'message' => $msg,
        'data' => $data,
    ];
    header('content-type: application/json');
    if ($err) { // failed header
        header('X-API-Response: 202', true, 202);
    }
    echo json_encode($response,JSON_PRETTY_PRINT);
}

function user_not_ok(): bool
{
    return false ;
    $security = \Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create();
    $user = $security->getUser();
    return !$user || $user->isClient ;
}

function rebuild(): bool
{
    global $current ;
    if($current < '1.8.2c'){
        $disable['disable_contention'] = true;
        $enable['disable_contention'] = false;
        return (new Settings($disable))->edit()
            && (new Settings($enable))->edit();
    }
    return true ;
}
