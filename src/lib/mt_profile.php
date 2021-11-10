<?php

class MT_Profile extends MT
{

    private $pq; //parent queue object

    public function set(): bool
    {
        if ($this->svc->plan->contention < 0 && !$this->children()) {
            return $this->delete();
        }
        return $this->exec();
    }

    private function children()
    {
        $this->path = '/ppp/secret/';
        $read = $this->read('?profile=' . $this->name()) ?? [];
        $disabled = $this->entity_disabled();
        $this->path = '/ppp/profile/';
        $count = sizeof($read) ?? 0;
        $count += $disabled ? 0 : -1; // do not deduct if account is disabled
        return (bool)max($count, 0);
    }

    private function entity_disabled(): bool
    {
        // $this->path = '/ppp/secret/'; path is already set by prev call
        $read = $this->read('?comment');
        $id = (string)$this->svc->id();
        foreach ($read as $item) {
            if (substr($item['comment'], 0, strlen($id)) == $id) {
                return $item['profile'] == $this->conf->disabled_profile;
            }
        }
        return false;
    }

    private function delete(): bool
    {
        if($this->exists) {
            $id['.id'] = $this->data()->name;
            return $this->pq->set()
                && $this->write((object)$id, 'remove');
        }
        return true;
    }

    protected function data(): object
    {
        return (object)[
            'name' => $this->name(),
            'local-address' => $this->local_addr(),
            'rate-limit' => $this->rate()->text,
            'parent-queue' => $this->pq->name(),
            'address-list' => $this->conf->active_list,
            '.id' => $this->insertId ?? $this->name(),
        ];
    }

    private function local_addr()
    { // get one address for profile local address
        $savedPath = $this->path;
        $this->path = '/ip/address/';
        if ($this->read()) {
            foreach ($this->read as $prefix) {
                if ($prefix['dynamic'] == 'true') {
                    continue;
                }
                [$addr] = explode('/', $prefix['address']);
                $this->path = $savedPath;
                return $addr;
            }
        }
        $this->path = $savedPath;
        return false;
    }

    protected function rate(): object
    {
        $rate = parent::rate();
        $r = $this->conf->disabled_rate;
        $disabled = (object)[
            'text' => $r . 'M/' . $r . 'M',
            'upload' => $r,
            'download' => $r,
        ];
        return $this->svc->disabled ? $disabled : $rate;
    }

    protected function findErr()
    {
        if ($this->pq->status()->error) {
            $this->status = $this->pq->status();
        }
    }

    private function exec(): bool
    {
        $action = $this->exists ? 'set' : 'add';
        $orphanId = $this->orphaned();
        $pq = $orphanId
            ? $this->pq->reset($orphanId)
            : $this->pq->set();
        return $pq && $this->write($this->data(),$action);
    }

    private function orphaned(): ?string
    {
        if (!$this->exists()) {
            return false;
        }
        $profile = $this->entity;
        return substr($profile['parent-queue'], 0, 1) == '*'
            ? $profile['parent-queue'] : null;
    }

    protected function init(): void
    {
        parent::init();
        $this->path = '/ppp/profile/';
        $this->exists = $this->exists();
        $this->pq = new MT_Parent_Queue($this->svc);
    }

    protected function filter(): string
    {
        return '?name=' . $this->name();
    }

    protected function name()
    {
        return $this->svc->disabled
            ? $this->conf->disabled_profile
            : $this->svc->plan->name();
    }

}
