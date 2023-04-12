<?php
const MyCacheVersion = '1.8.8.11';

include_once 'api_trim.php';
include_once 'api_ucrm.php';
include_once '_web_ucrm.php';

class ApiCache{

    public function save($request,$type = 'service')
    { //update a single service
        $timer = new ApiTimer('cache update');
        $table = $type . 's';
        $batch[] = $request ;
        $this->batch($table,$batch);
        if($table == 'services')$this->batch_network($batch);
        $timer->stop();
    }

    public function sync()
    {
        if($this->needs_update()){
            if(!$this->attributes()->check_config()
                || !$this->check_devices()){
                return ;
            }
            $timer = new ApiTimer('sync: ');
            MyLog()->Append('populating services and clients');
            foreach(['clients','services'] as $table){
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

    private function populate($table)
    {
        $data = ['starter'];
        $opts = $this->opts($table);
        $path = $this->path($table);
        while($data){
            $data = $this->ucrm()->get($path,$opts);
            if(empty($data)) continue ;
            $request = [];
            foreach($data as $item){
                $trim = $this->trimmer()->trim($table,$item)['entity'] ?? null;
                if(!$trim){ continue ; }
                $request[] = $trim ;
            }
            $this->batch($table,$request);
            $opts['offset'] += 500 ;
        }
    }

    public function batch($table, $request)
    {
        $values = [];
        $fields = null ;
        foreach ($request as $item){
            $item = array_diff_key($item,['network' => null]);
            $values[] = $this->to_sql(array_values($item));
            $fields = implode(',',array_keys($item));
        }
        $sql = sprintf('INSERT OR REPLACE INTO %s (%s) VALUES ',$table,$fields);
        $sql .= implode(',',$values);
        MyLog()->Append("cache update sql: ".$sql);
        $this->dbCache()->exec($sql);
    }

    private function batch_network($request)
    {
        $deleted = [];
        $values = [];
        $fields = null ;
        foreach($request as $item){
            $net = $item['network'] ?? [];
            $net['id'] = $item['id'];
            if(in_array($item['status'],[2,5,8])) { $deleted[] = $item['id']; }
            else {
                $values[] = $this->to_sql(array_values($net));
                $fields = implode(',',array_keys($net));
            }
        }
        if(!empty($deleted)){
            $sql = sprintf("delete from network where id in (%s)",
                implode(',',$deleted));
            MyLog()->Append('cache: network delete sql: '.$sql);
            $this->dbCache()->exec($sql); //clear inactive addresses
        }
        if(!empty($values)){
            $sql = sprintf("insert or replace into network (%s) values %s ",$fields,
                implode(',',$values));
            MyLog()->Append("cache: network sql: ".$sql);
            $this->dbCache()->exec($sql);
        }
    }

    private function path($table): ?string
    {
        switch ($table){
            case 'clients': return 'clients';
            case 'services': return 'clients/services';
        }
        return null ;
    }

    private function to_sql($array): string
    {
        $values = [];
        foreach ($array as $item){
            if(empty($item)) $values[] = 'null';
            elseif (is_array($item) || is_object($item)) $values[] = sprintf("'%s'",json_encode($item));
            elseif(is_numeric($item)) $values[] = $item ;
            else $values[] = sprintf("'%s'",$item);
        }
        return sprintf("(%s)",implode(',',$values));
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

    private function needs_net(): bool
    {
        $last = $this->conf()->last_net ?? '2020-01-01';
        $cycle = DateInterval::createFromDateString('30 minute');
        $sync = new DateTime($last);
        $now = new DateTime();
        return date_add($sync,$cycle) < $now ;
    }

    private function needs_db(): bool
    {
        $file = 'data/cache.db';
        if(!file_exists($file)) return true;
        if(!$this->dbCache()->has_tables(['clients','services','network'])){
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

    private function attributes() { return new ApiAttributes(); }

    private function trimmer(){ return new ApiTrim(); }

    private function ucrm(){ return new ApiUcrm(); }

    private function db(){ return new ApiSqlite(); }

    private function now() { return (new DateTime())->format('Y-m-d H:i:s'); }

    private function conf() {return $this->db()->readConfig(); }

    private function dbCache(){ return new ApiSqlite('data/cache.db'); }

   }

function cache_sync() { $api = new ApiCache(); $api->sync();}

function cache_setup(){ $cache = new ApiCache(); $cache->setup();}

//function net_sync(){ $cache = new ApiCache(); $cache->net_sync();}
