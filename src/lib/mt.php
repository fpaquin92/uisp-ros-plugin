<?php

include_once 'routeros_api.class.php';
include_once 'device.php';

class MT extends Device
{

    protected ?stdClass $device;
    protected ?array $batch ;
    protected ?stdClass $batch_device ;
    protected ?array $batch_failed ;
    protected ?array $batch_success;
    protected ?RouterosAPI $api ;

    protected function write($post)
    {
        $opened = $this->xor_connect();
        $timer = new ApiTimer('single write');
        //check and prepare
        $id = $this->find_id($post);
        $action = $id ? 'set' : 'add';
        $wants = $post['action'] ?? null;
        if ($wants == 'remove') {
            if ($this->find_deps($post)) return null;
            else $action = 'remove';
        }
        $path = $post['path'];
        $data = $this->prep_data($post);
        if ($action != 'add') $data['.id'] = $id;
        //begin write
        $this->api->write(sprintf("/%s/%s",
            trim($path, '/'), $action), false);
        foreach (array_keys($data) as $key) {
            $this->api->write('=' . $key . '=' . $data[$key], false);
        }
        $this->api->write(';');
        $timer->stop();
        $read = $this->api->read() ;
        if($opened) $this->api_disconnect();
        return $read ;
    }

    protected function xor_connect(): bool
    {// connect if not already connected
        if(!$this->api){
            $this->api = $this->api_connect() ;
            if($this->api) return true;
        }
        return false ;
    }

    protected function write_batch(): int
    {
        $api = $this->api_connect();
        if (!$api) {
            MyLog()->Append("mt failed to connect sending batch to queue");
            $this->send_to_queue('device connect failed');
            return 0;
        }
        $this->api = $api ;
        $writes = 0;
        foreach ($this->batch as $post) {
            $result = $this->write($post);
            if ($this->find_error($result)) {
                $id = $post['batch'] ?? null ;
                if($id)$this->batch_failed[$id] = json_encode($result);
                MyLog()->Append('mt write error: ' . json_encode([$post, $result]), 6);
            } else {
                $id = $post['batch'] ?? null ;
                if($id) $this->batch_success[$id] = 1;
                $writes++;
            }
        }
        $this->batch = null;
        $this->api = null ;
        $api->disconnect();
        return $writes;
    }

    protected function send_to_queue($error): void
    {
        foreach ($this->batch as $item) {
            $id = $item['batch'] ?? null ;
            if($id) $this->batch_failed[$id] = $error;
        }
        $this->batch = null ;
    }

    protected function find_error($result): bool
    {
        if(is_array($result) && !empty($result)){
            $error = $result['!trap'][0]['message'] ?? null ;
            if($error){
                MyLog()->Append('mt write error: '.$error);
                $this->setErr($error);
                return true ;
            }
        }
        return false;
    }

    protected function read($path,$filter = null): ?array
    {  //implements mikrotik print
        $opened = $this->xor_connect() ;
        if(!$this->api) return  null ;
        $this->api->write(sprintf('/%s/print',trim($path,'/')), false);
        if ($filter) {
            foreach($this->format_filter($filter) as $item){
                $this->api->write($item,false);
            }
        }
        $this->api->write(";");
        $read = $this->api->read() ;
        if($opened) $this->api_disconnect();
        return $this->find_error($read) ? null : $read;
    }

    protected function api_connect(): ?RouterosAPI
    {
        if(!$this->get_device()){
            MyLog()->Append('mt: failed to get device information');
            return null ;
        }
        $api = new Routerosapi();
        $api->timeout = 1;
        $api->attempts = 1;
        //$api->debug = true;
        if (!$api->connect($this->device->ip,
            $this->device->user, $this->device->password)) {
            return null ;
        }
        return $api;
    }

    protected function api_disconnect(): void
    {
        if($this->api) $this->api->disconnect();
        $this->api = null ;
    }

    protected function find_deps($data): bool
    {
        $action = $data['action'];
        if($action != 'remove') return false ;
        $path = trim($data['path'],'/') ;
        if(!$this->needs_dep_check($path)) return false;
        $name = $data['name'] ?? null ;
        if(!$name) return false ; //we dont delete unless we are sure
        foreach($this->dep_paths($path) as $dep_path)
        {
            $path = $dep_path;
            $filter = sprintf('?%s=%s',$this->dep_filter_key($dep_path),$name);
            $read = $this->read($path,$filter);
            if(!empty($read)){
                return true ;
            }
        }
        return false ;
    }

    protected function dep_filter_key($path): string
    {
        switch (trim($path,'/'))
        {
            case 'ppp/secret':
            case 'ip/hotspot/user': return 'profile';
            case 'queue/simple': return 'parent';
            case 'ipv6/dhcp-server/binding': return 'prefix-pool';
            default: return 'parent-queue';
        }
    }

    protected function dep_paths($path): array
    {
        switch (trim($path,'/'))
        {
            case 'ppp/profile': return ['/ppp/secret'];
            case 'ip/hotspot/user/profile': return ['/ip/hotspot/user'];
            case 'ipv6/pool': return ['/ipv6/dhcp-server/binding'];
            default: return ['/ppp/profile','/ip/hotspot/user/profile','/queue/simple'];
        }
    }

    protected function needs_dep_check($path): bool
    {
        return in_array($path,[
            'ppp/profile',
            'ip/hotspot/user/profile',
            'queue/simple',
            'ipv6/pool',
        ]);
    }

    protected function find_id($data): ?string
    {
        $name = $data['name'] ?? null ;
        $mac = $data['mac-address'] ?? null ;
        $duid = $data['duid'] ?? null;
        $filter = [] ;
        if($name) $filter['name'] = $name;
        if($mac) $filter['mac-address'] = $mac ;
        if($duid) $filter['duid'] = $duid ;
        if($filter){
            $path = $data['path'];
            $read = $this->read($path,$filter);
            $item  = $read[0] ?? [];
            $id = $item['.id'] ?? null ;
            if($id && is_string($id)){
                if(preg_match("/(binding)|(lease)/",$path)){ //convert dynamic lease
                    MyLog()->Append('THIS IS A DHCP LEASE');
                    $dynamic = $item['dynamic'] ?? 'false' ;
                    if($dynamic == 'true'){ $this->make_static_lease($id,$path);}
                }
                return $id ;
            }
        }
        return null ;
    }

    protected function find_local(): ?string
    {
        $path = '/ip/address/';
        $filter = '?disabled=false,?dynamic=false,?invalid=false';
        $list = $this->read($path,$filter);
        $prefix = $list[0]['address'] ?? null ;
        $address = explode('/',$prefix)[0] ?? null ;
        return $address ?? (new ApiIP())->local();
    }

    protected  function make_static_lease($id,$path): void
    {
        $command = sprintf('/%s/make-static',trim($path,'/'));
        $this->api->write($command,false);
        $this->api->write('=.id=' . $id);
        $this->api->read();
    }

    protected function prep_data($data): array
    {
        $clean = [];
        $action = $data['action'] ?? null;
        if($action == 'remove') { return $clean ;} // return blank for remove
        $diff = ['.id' => null,'action' => null,'path' => null,'batch' => null] ;
        $clean = array_diff_key($data,$diff) ;
        if(key_exists('local-address',$data))
            $clean['local-address'] = $this->find_local();
        return $clean ;
    }

    private function get_data($property)
    { // check and return data object property
        return $this->data->$property ?? null;
    }

    protected function get_device(): bool
    {
        $this->device = null ;
        if($this->batch_device){
            $this->device = $this->batch_device ;
        }
        elseif ($this->svc) {
            $this->device = $this->svc->device();
        }
        elseif ($id = $this->get_data('device_id')) {
            $this->device = $this->db()->selectDeviceById($id);
        }
        elseif ($dev = $this->get_data('device')) {
            $this->device = $this->db()->selectDeviceByDeviceName($dev);
        }
        return (bool)$this->device ;
    }

    protected function format_filter($filter): array
    {
        $return = [];
        if(is_string($filter)){
            foreach(explode(',',$filter . ',') as $item){
                if(!empty($item)) $return[] = $item ;
            }
        }
        if(is_array($filter)){
            foreach(array_keys($filter) as $key){
                $return[] = sprintf('?%s=%s',$key,$filter[$key]);
            }
        }
        return $return ;
    }

    protected function to_pair($array, $mbps = true): ?string
    {
        $str = [];
        foreach($array as $value){
            $unit = $mbps ? 'M' : null;
            if(!$value){ $value = 0; $unit = null; }
            $str[] = $value . $unit ;
        }
        return implode('/',$str);
    }

}
