<?php

class MT_Queue extends MT {

    private $pq; //parent queue object
    private $parent;

    public function __construct(&$data) {
        parent::__construct($data);
        $this->path = '/queue/simple/';
        $this->pq = new MT_Parent_Queue($data);
    }

    public function insert() {
        if (!$this->pq->set(1)) {
            $this->set_error($this->pq->error());
            return false;
        }
        if ($this->write($this->data(), 'add')) {
            $this->insertId = $this->read;
            return true;
        }
        return false;
    }

    public function delete() {
        $del = (object) array(
                    '.id' => $this->savedId(),
        );
        if ($this->write($del, 'remove')) {
            if (!$this->pq->set(-1)) {  // edit or delete parent
                $this->set_error($this->pq->error());
                return false;
            }
            return true;
        }
        return false;
    }

    public function edit() {
        // figure we don't touch the parent
        $data = $this->data();
        unset($data->parent); // since we are not editing parent
        $data->{'.id'} = $this->savedId();
        return $this->write($data, 'set');
    }

    protected function data() {
        $rate = $this->rate();
        return (object) array(
                    'name' => $this->name(),
                    'target' => $this->data->ip,
                    'max-limit' => $rate,
                    'limit-at' => $rate,
                    'parent' => $this->pq->name(),
                    'comment' => $this->comment(),
        );
    }

    private function name() {
        return $this->{$this->data->actionObj}->clientId
                . "-" . $this->data->clientName
                . '-' . $this->{$this->data->actionObj}->id;
    }

    protected function savedId() {
        $id = $this->{$this->data->actionObj}->id;
        $db = new CS_SQLite();
        $savedId = $db->selectQueueMikrotikIdByServiceId($id);
        if ($savedId) {
            return $savedId;
        }
        if ($this->exists()) { // for old installations
            $saveId = $this->search[0]['.id'];
            $db->updateColumnById('queueId', $saveId, $id);
            return $savedId;
        }
    }

    /*public function read() {
        return $this->read;
    }*/


}
