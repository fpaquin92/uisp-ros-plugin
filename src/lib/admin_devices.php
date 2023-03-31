<?php
include_once 'lib/admin_cache.php';
class Devices extends Admin
{
    public function disable()
    { // disables/enables plan limits on device
        $id = $this->data->id ;
        $enable = $this->data->enable ?? false ;
        $data = (object)[
            'device_id' => $this->data->id,
            'path' => '/ppp/profile'
        ];
        $profiles = (new MT_Profile($data))->get();
        $plans = $this->get_plans();
        foreach($profiles as $profile)
        {
            if(isset($plans[$profile['name']])){
                $this->set_profile_limit($id,$profile,$plans[$profile['name']],$enable);
            }
        }
        $this->save_router($id,$enable);
        $this->reset_pppoe($id);
    }

    public function delete(): bool
    {

        $db = $this->connect();
        if (!$db->delete($this->data->id, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been deleted');
        return true;
    }

    public function insert(): bool
    {

        $db = $this->connect();
        unset($this->data->id);
        $this->trim_prefix();
        if (!$db->insert($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been added');
        return true;
    }

    public function edit(): bool
    {

        $db = $this->connect();
        $this->trim_prefix();
        if (!$db->edit($this->data, 'devices')) {
            $this->set_error('database error');
            return false;
        }
        $this->set_message('device has been updated');
        return true;
    }

    public function getAllServices()
    {
        $this->result =
            $this->db()->selectServices();
        return (bool) $this->result;
    }

    public function get(): bool
    {
        if(!$this->read()){ //it's not an error if no devices
            $this->result = [];
            return true ;
        }
        $this->setStatus();
        $this->setUsers();
        $this->result = $this->read;
        $this->set_message('devices retrieved');
        return true;
    }

    private function reset_pppoe($id)
    {
        $data = (object)[
            'device_id' => $id,
            'path' => '/interface/pppoe-server/server'
        ];
        $servers = (new MT($data))->get();
        foreach ($servers as $server)
        {
            $edit = (object)[
                'device_id'=> $id,
                'path' => '/interface/pppoe-server/server',
                'action' => 'disable',
                'data' => (object) ['.id' => $server['.id'],],];
            (new MT($edit))->set();
            $edit->action = 'enable';
            (new MT($edit))->set();
        }

    }

    private function save_router($id,$enable=false)
    {
        $list = json_decode($this->conf()->disabled_routers,true) ?? [];
        if($enable){
            unset($list[$id]);
        }else{
            $list[$id] = 1;
        }
        $data['disabled_routers'] = json_encode($list) ?? [];
        return $this->db()->saveConfig($data);
    }

    private function set_profile_limit($id,$profile,$plan,$enable=false)
    {
        $rate = $enable
            ? $plan['uploadSpeed']. 'M/'.$plan['downloadSpeed'] .'M'
            : null ;
        $parent = $enable
            ? 'servicePlan-'.$plan['id'].'-parent'
            : 'none';
        $data = (object)[
            'device_id' => $id,
            'action' => 'set',
            'path' => '/ppp/profile',
            'data' => (object)[
                '.id' => $profile['.id'],
                'rate-limit' => $rate,
                'parent-queue' => $parent,
            ],
        ];
        return (new MT($data))->set();
    }

    private function connect(): ApiSqlite
    {
        return new ApiSqlite();
    }

    public function services()
    {
        $this->result = $this->get_services();
    }

    private function get_services()
    {
        $cached = $this->dbCache()->selectCustom($this->cache_sql()) ?? [];
        $plans = $this->ucrm()->get('service-plans') ?? [];
        $plans = json_decode(json_encode($plans),true);
        $addressMap = [];
        $planMap = [];
        foreach ($plans as $plan)$planMap[$plan['id']] = $plan ;
        foreach ($cached as $item) $addressMap[$item['id']] = $item ;
        $ret = [];
        foreach ($cached as $item) {
            $item['plan'] = $planMap[$item['planId']]['name'] ?? null ;
            $ret[$item['id']] = $item ;
        }
        $ret[] = $this->get_count();
        return $ret ;
    }

    private function get_count()
    {
        $id = $this->data->id ?? 0 ;
        $count = $this->dbCache()->countServicesByDeviceId($id) ?? 0;
        return ['count' => $count] ;
    }

    private function cache_sql(){
        $sql = "SELECT services.*,network.address,network.prefix6,clients.company,".
            "clients.firstName,clients.lastName FROM services LEFT JOIN clients ON ".
            "services.clientId=clients.id LEFT JOIN network ON services.id=network.id ";
        $device = $this->data->id ?? null ;
        $sql .= sprintf("WHERE services.device = %s ",$device);
        $sql .= sprintf("AND services.status IN (1,3) ");
        $query = $this->data->query ?? null ;
        if($query){
            if(is_numeric($query)){
                $sql .= sprintf("AND (services.id=%s OR services.clientId=%s) ",$query,$query);
            }
            else{
                $sql .= sprintf("AND (clients.firstName LIKE '%%%s%%' OR clients.lastName LIKE '%%%s%%' ".
                    "OR clients.company LIKE '%%%s%%' OR services.username LIKE '%%%s%%' OR services.mac LIKE '%%%s%%') ",
                    $query,$query,$query,$query,$query);
            }
        }
        $sql .= 'ORDER BY services.id DESC LIMIT 300';
        MyLog()->Append("services sql: ".$sql);
        return $sql;
    }

//    public function cache_update(): void
//    {
//        if(!function_exists('fastcgi_finish_request')){
//            shell_exec('php lib/shell.php cache > /dev/null 2>&1 &');
//            return;
//        }else{
//            $this->status->status = 'ok';
//            $this->status->data = $this->result ;
//            header('content-type: application/json');
//            echo json_encode($this->status);
//            fastcgi_finish_request();
//        }
//        set_time_limit(300);
//        (new Admin_Cache())->create();
//    }
//
//    private function cache(): ?array
//    {
//        $file = 'data/cache.json';
//        if(!file_exists($file))return null ;
//        if($this->cache_is_valid()) return [1];
//        $id = $this->data->id ?? 0;
//        $cache = json_decode(file_get_contents($file),true) ;
//        $ret = $cache[$id] ?? null;
//        if($ret) $ret['date'] = filemtime('data/cache.json');
//        return $ret;
//    }
//
//    private function cache_is_valid(): bool
//    { // if last cache is still valid
//        $last = $this->data->lastCache ?? 0 ;
//        $current = filemtime('data/cache.json');
//        return $last == $current ;
//    }

    private function read(): bool
    {
        $this->read = $this->db()->selectAllFromTable('devices');
        return !empty($this->read) ;
    }

    private function trim_prefix(): void
    {
        $pfx = $this->data->pfxLength ?? null ;
        if($pfx) $this->data->pfxLength = trim($pfx,"/");
    }

    private function setStatus(): void
    {
        foreach ($this->read as &$device) {
            try{
                $conn = @fsockopen($device['ip'],
                $this->default_port($device['type']),
                $code, $err, 0.3);
                if (!is_resource($conn)) {
                    $device['status'] = false;
                    continue;
                }
                $device['status'] = true;
                fclose($conn);
            }
            catch (Exception $err){
                $device['status'] = false ;
            }
        }
    }

    private function default_port($type): int
    {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
        );
        return $ports[$type];
    }

    private function setUsers(): void
    {
        $db = new ApiSqlite();
        foreach ($this->read as &$device) {
            $device['users'] = $db->countServicesByDeviceId($device['id']);
        }
    }

}
