<?php

class Plans extends Admin
{

    private $plans; // imported service plans
    private $ids; // array of imported service plan ids
    private $uisp; //uisp http query object
    private $devices;

    public function __construct(&$data)
    {
        parent::__construct($data);
        $this->uisp = new API_Unms();
        $this->ids = [];
    }

    public function get()
    {
        $this->readCache();
        $this->updateCache();
    }

    private function readCache()
    {
        $db = new API_SQLite();
        $plans = $db->selectAllFromTable('plans') ?? [];
        $this->result = [];
        foreach ($plans as $plan) {
            $this->result[$plan['id']] = $plan;
        }
    }

    private function updateCache()
    { //updating cache with uisp import
        if (!$this->readUisp()) {
            $this->set_message('failed to read plans from uisp.using cached entries');
            return false;
        }
        $this->mergeCache();
        $this->pruneCache();
        return true;
    }

    private function readUisp()
    {
        $this->plans = (array)$this->uisp->request('/service-plans') ?? [];
        return $this->plans ? true : false;
    }

    private function mergeCache()
    { // merge import into cache
        $cachedKeys = array_keys($this->result);
        $relevantKeys = ['id', 'name', 'downloadSpeed', 'uploadSpeed',
            'downloadBurst', 'uploadBurst', 'dataUsageLimit'];
        $db = new API_SQLite();
        foreach ($this->plans as $plan) {
            $isNew = false;
            $this->ids[] = $plan->id; // save for removing orphans
            if (!in_array($plan->id, $cachedKeys)) { // new entry
                $isNew = true;
                $this->result[$plan->id] = [];
                $this->result[$plan->id]['ratio'] = 1; //set default contention ratio
            }
            foreach ($relevantKeys as $key) {
                $this->result[$plan->id][$key] = $plan->{$key} ?? 0;
            }
            if ($isNew) {
                $db->insert((object)$this->result[$plan->id], 'plans');
            }
        }
    }

    private function pruneCache()
    { //remove orphans from cache
        $keys = array_keys($this->result);
        $db = new API_SQLite();
        foreach ($keys as $key) {
            if (!in_array($key, $this->ids)) {
                unset($this->result[$key]);
                $db->delete($key, 'plans');
            }
        }
    }

    public function list()
    {
        $this->readCache();
        $this->updateCache();
        return $this->result ?? [];
    }

    public function edit()
    {
        $id = $this->data->id;
        if (!$this->db()->edit($this->data, 'plans')) {
            $this->set_error('failed to update contention ratio for service plan');
            return false;
        }
        $blank = $this->service_blank();
        $data = new Service($blank);
        (new MT_Parent_Queue($data))->update($id);
        $this->set_message('Contention ratio has been updated and applied');
        return true;
    }

}
