<?php


chdir(__DIR__);
include_once 'includes/cors.php';
require_once 'vendor/autoload.php';
include_once 'lib/api_logger.php';
include_once 'lib/api_common.php';

if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options
    exit();
}

$json = file_get_contents('php://input') ?? null;

try
{
    include_once 'lib/api_cache.php';
    include_once 'lib/api_setup.php';

    set_error_handler('myErrorHandler');
    MyLog()->Append('public: checking databases');
    run_setup();
    cache_setup();
    MyLog()->Append('public: checking cache sync');
    cache_sync();
    MyLog()->Append('public: setup completed');

    include_once 'lib/api_router.php';

    if(isset($_FILES) && !empty($_FILES))
    {//backup restore
        $bak = $_FILES['backup'] ?? null ;
        if($bak){ backup_restore(); }
        header('content-type: application/json');
        echo '{"status":"ok","error":false,"message":"ok","data":[]}';
        exit();
    }


    if(!$json)
    {
        MyLog()->Append('public: begin page load param '. json_encode($_GET ?? '{}'));
        if (isset($_GET['page']) && $_GET['page'] == 'panel')
        { //load panel
            include 'public/panel/index.php';
        }
        exit();
    }


    MyLog()->Append('public: begin api request: '.$json);
    $data = json_decode($json);
    $api = new API_Router($data);
    $api->route();
    $api->http_response();
    MyLog()->Append('api: finished without error : ' . json_encode($api->status()));
}
catch (NoActionException $noError){ respond($noError->getMessage()); }
catch ( \Exception | Error $error)
{
    MyLog()->Append(sprintf("Global exception message: %s \nTrace: %s",
        $error->getMessage(),$error->getTraceAsString()));
    respond($error->getMessage(),true);
}



