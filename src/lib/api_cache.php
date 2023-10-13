<?php
const MyCacheVersion = '1.8.11';

include_once 'api_trim.php';
//include_once '_web_ucrm.php';
include_once 'api_ucrm.php';

class ApiCache{

    private ?object $_conf = null;

    public function save($request,$type = 'service')
    { //update a single service
        $timer = new ApiTimer('cache update');
        $id = $request['id'] ?? 0 ;
        $siteId = $request['unmsClientSiteId'] ?? null ;
        if(key_exists('unmsClientSiteId',$request)){
            unset($request['unmsClientSiteId']); }
        $table = $type . 's';
        $batch[] = $request ;
        $this->batch($table,$batch);
        if($table == 'services'){
            $this->batch_network($batch);
            $this->save_site($id,$siteId);
        }
        $timer->stop();
    }

    private function save_site($id,$siteId){

        $device = $this->get_device($siteId);
        $did = null ;
        if(is_object($device)){
            $did = $device->identification->id ?? null ; }
        $post['id'] = $siteId ;
        $post['service'] = $id ;
        $post['device'] = $did;
        $this->dbCache()->insert($post,'sites',true);
    }

    public function sync($force = false)
    {
        if($force || $this->needs_sites()){
            MyLog()->Append("Syncing sites only");
            $this->populate('sites');
            $set['last_sites'] = $this->now() ;
            $this->db()->saveConfig($set);
        }
        if($force || $this->needs_update()){
            if(!$this->attrs()->check_config()
                || !$this->check_devices()){
                return ;
            }
            $timer = new ApiTimer('sync: ');
            MyLog()->Append('populating services,clients');
            foreach(['clients','services','sites'] as $table){
                $this->populate($table);
                MyLog()->Append('finished populating: '.$table);
            }
            $state = ['last_cache' => $this->now()];
            $this->db()->saveConfig($state);
            $timer->stop();
        }
    }

    public function setup(): void
    {
        $timer = new ApiTimer('cache setup: ');
        if($this->needs_db()){
            shell_exec('rm -f data/cache.db');
            $schema_file = 'includes/cache.sql';
            $schema = file_get_contents($schema_file);
            if($this->dbCache()->exec($schema)){//reset cache time
                $state = ['cache_version' => MyCacheVersion,
                    'last_cache' => '2020-01-01','last_net' => '2020-01-01'];
                $this->db()->saveConfig($state);
            }
        }
        $timer->stop();
    }

    public function populate($table)
    {
        $data = ['starter'];
        $limit = 50 ;
        $offset = 0 ;
        while($data){
            $method = 'get_' . $table ;
            $data = $this->$method($offset,$limit);
            if(empty($data)) continue ;
            $request = [];
            foreach($data as $item){
                $trim = $this->trimmer()->trim($table,$item)['entity'] ?? null;
                if(!$trim){ continue ; }
                $request[] = $trim ;
            }
            $this->batch($table,$request);
            if(in_array($table,['service','services'])){
                $this->batch_network($request);
            }
            if(in_array($table,['site','sites'])){ break; }
            $offset += $limit ;
        }
    }

    public function get_sites($offset,$limit)
    {
        $opts = ['type' => 'endpoint'];
        $sites = $this->get_data('sites',$opts,true);
        $devices = $this->get_data('devices',[],true);
        $site_map = [];
        foreach ($sites as $site){ $site_map[$site->id] = $site; }
        foreach($devices as $device){
            $site_id = $device->identification->site->id ?? null ;
            $name = $device->identification->name ?? null ;
            if($site_id && preg_match("/^RosP_/",$name)){
                $site = $site_map[$site_id] ?? null ;
                if(is_object($site)){
                    $site->device = $device->identification->id ?? null ;
                    $site_map[$site_id] = $site ;
                }
            }
        }
        return $site_map ;
    }

    private function get_device($siteId): ?object
    {
        $devices = $this->ucrm(true)->get('devices',['siteId' => $siteId]);
        if(!is_array($devices)){ return null ;}
        foreach ($devices as $device){
            $name = $device->identification->name ?? null ;
            if(preg_match("/^RosP_/",$name)){ return $device; }
        }
        return null ;
    }

    private function get_clients($offset,$limit = 500)
    {
        $opts = ['offset' => $offset, 'limit' => $limit];
        return $this->get_data('clients',$opts) ;
    }

    private function get_services($offset,$limit = 500): array
    {
        $opts = ['offset' => $offset,'limit' => $limit,'statuses' => [0,1,3,4,6,7]];
        return $this->get_data('clients/services',$opts);
    }

    private function get_data($path,$opts,$unms = false): array
    {
        return $this->ucrm($unms)->get($path,$opts) ?? [];
    }

    public function batch($table, $request)
    {
        $values = [];
        foreach ($request as $item){
            $values[] = array_diff_key($item,['network' => 1,'unmsClientSiteId' => 1]);
        }
        $this->dbCache()->insert($values,$table,true);
    }

    private function batch_network($request)
    {
        $deleted = [];
        $values = [];
        foreach($request as $item){
            $net = $item['network'] ?? null;
            if(!$net){ continue; }
            $net['id'] = $item['id'];
            if(in_array($item['status'],[2,5,8])) { $deleted[] = $item['id']; }
            else {
                $values[] = $net ;
            }
        }
        if(!empty($deleted)){
            $sql = sprintf("delete from network where id in (%s)",
                implode(',',$deleted));
            MyLog()->Append('cache: network delete sql: '.$sql);
            $this->dbCache()->exec($sql); //clear inactive addresses
        }
        if(!empty($values)){
            MyLog()->Append('sending cache network data to sqlite');
            $this->dbCache()->insert($values,'network',true);
        }
    }

    private function path($table): ?string
    {
        return match ($table) {
            'clients' => 'clients',
            'services' => 'clients/services',
            default => null,
        };
    }

    private function check_devices(): bool
    {
        $devices = $this->db()->selectAllFromTable('devices');
        if(empty($devices)) {
            MyLog()->Append('devices not configured sync delayed');
            return false ;
        }
        MyLog()->Append('cache devices found: '. json_encode($devices));
        return true ;
    }

    private function needs_update(): bool
    {
        $last = $this->conf()->last_cache ?? '2020-01-01';
        $cycle = DateInterval::createFromDateString('7 day');
        $sync = new DateTime($last);
        $now = new DateTime();
        return date_add($sync,$cycle) < $now ;
    }

    private function needs_db(): bool
    {
        $file = 'data/cache.db';
        if(!is_file($file)) return true;
        if(!$this->dbCache()->has_tables(['clients','services','network','sites'])){
            return true ;}
        $version = $this->conf()->cache_version ?? '0.0.0';
        return $version != MyCacheVersion ;
    }

    private function opts($table = 'services'): array
    {
        $opts = ['limit' => 500,'offset' => 0,'statuses' => [0,1,3,4,6,7]];
        if($table == 'services') return $opts ;
        else return array_diff_key($opts,['statuses' => null]);
    }

    private function attrs(): ApiAttributes { return myAttr(); }

    private function trimmer(): ApiTrim
    {
        return myTrimmer() ;
    }

    private function ucrm(): ApiUcrm { return new ApiUcrm(); }

    private function db(): ApiSqlite { return mySqlite(); }

    private function now(): string { return (new DateTime())->format('c'); }

    private function conf(): ?object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf;
    }

    private function dbCache(): ApiSqlite
    {
        return myCache() ;
    }

   }

function cache_sync() { $api = new ApiCache(); $api->sync();}

function cache_setup(){ $cache = new ApiCache(); $cache->setup();}
