<?php

class Admin_System extends Admin
{

    public function rebuild(): void
    {
        $api = new AdminRebuild() ;
        $api->rebuild($this->data);
    }

    public function recache()
    {
        $api = new ApiCache();
        $this->db()->saveConfig(['last_cache' =>'2020-01-01']);
        $api->setup();
        $api->sync();
    }

    public function unqueue()
    {
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
        $did = $this->data->id ?? $this->data->did ?? 0 ;
        $on = $this->data->on ?? true ;
        $select = $this->dbCache()->selectCustom(sprintf("SELECT id FROM services WHERE device = %s AND status NOT IN (2,5,8) ",$did));
        if(empty($select)){ return ;}
        $ids = [];
        foreach ($select as $item) $ids[] = $item['id'];
        $api = new MtBatch();
        $api->set_queues($ids,$on);
    }
}