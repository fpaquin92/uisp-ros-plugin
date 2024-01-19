<?php

class ApiRebuild{

    private function db(): ApiSqlite { return mySqlite(); }

    private function cache(): ApiSqlite { return myCache(); }

    private function clear($type,$ids = [])
    {
        if($type == 'all'){
            $this->db()->deleteAll();
            $this->db()->deleteAll('network');
        }
        else{
            $this->db()->exec(sprintf("DELETE FROM 'services' WHERE id IN (%s)",
                implode(',',$ids) ));
            $this->db()->exec(sprintf("DELETE FROM 'network' WHERE id IN (%s)",
                implode(',',$ids)));
        }
    }

    public function rebuild($data)
    {
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
        set_time_limit(7200);
        $type = $data->type ?? 'all';
        $typeId = $data->id ?? $data->did ?? $data->sid ?? null;
        $clear = $data->clear ?? false ;
        $select = [];
        $timer = new ApiTimer($type . ' rebuild: '.json_encode($data));
        if($type == 'all'){
            $select = $this->cache()->selectCustom('SELECT id FROM services WHERE status NOT IN (2,5,8)');
        }
        if($type == 'service'){
            $select = $this->cache()->selectCustom(sprintf("SELECT id from services WHERE planId = %s AND status NOT IN (2,5,8) ",$typeId));
        }
        if($type == 'device'){
            $select = $this->cache()->selectCustom(sprintf("SELECT id FROM services WHERE device = %s AND status NOT IN (2,5,8) ",$typeId));
        }
        $ids = [];
        if(empty($select)){
            MyLog()->Append("no items found to rebuild");
            return ;
        }
        foreach ($select as $item) $ids[] = $item['id'];
        if($clear) { $this->clear($type,$ids); }
        $batch = new Batch();
        $batch->set_accounts($ids);
        $timer->stop();
    }

}

function rebuild(){ $api = new ApiRebuild(); $api->rebuild([]);}