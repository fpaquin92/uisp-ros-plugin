<?php
include_once 'api_sqlite.php';

class Service_Base
{

    public $ready;
    public $mode = 0;
    public $queued;
    public $type = 'service';
    protected $status;
    protected $data;
    protected $entity;
    protected $before;
    protected $conf;

    public function __construct($data)
    {
        $this->data = $this->toObject($data);
        $this->init();
    }

    private function toObject($data)
    {
        if (is_array($data) || is_object($data)) {
            return is_object($data) ? $data
                : json_decode(json_encode((object)$data));
        }
        return null;
    }

    protected function init(): void
    {
        $this->ready = true;
        $this->status = (object)[];
        $this->status->ready = &$this->ready;
        $this->status->error = false;
        $this->status->message = 'ok';
        $this->get_config();
        $this->set_shortcuts();
    }

    public function exists(): bool
    {
        return (bool)$this->db()
            ->ifServiceIdExists($this->entity->id);
    }

    public function mode($mode = null): ?int
    {//switches between edit and before edit objects
        // or returns mode is parameter is null
        $edit = $this->data->extraData->entityBeforeEdit ?? null;
        if (is_int($mode)) {
            $mode = $edit ? $mode : 0;
            $mode = $mode > 0 ? 1 : 0;
            $this->mode = $mode;
            $this->plan->mode = $mode;
            $this->client->mode = $mode;
            return null;
        }
        return $this->mode;
    }

    protected function set_shortcuts()
    {
        $this->entity = $this->data->extraData->entity ?? (object)[];
        $this->before = $this->data->extraData->entityBeforeEdit ?? (object)[];
    }

    public function queue_job($status = []): void
    {
        if ($this->queued) { //already queued
            return;
        }
        $file = 'data/queue.json';
        $q = [];
        if (file_exists($file)) {
            $f = file_get_contents($file) ?? "[]";
            $q = json_decode($f, true);
        }
        $id = $this->data->entityId ?? 0;
        if ($id) {
            $q[$id] = [
                'data' => $this->data,
                'status' => $status,
            ];
            file_put_contents($file, json_encode($q));
        }
    }

    protected function get_config()
    {
        $this->conf = $this->db()->readConfig();
        if (!(array)$this->conf) {
            $this->setErr('failed to read plugin configuration');
        }
    }

    protected function get_attribute_value($key, $entity = 'entity'): ?string
    { //returns an attribute value
        $attributes = $this->$entity->attributes ?? [];
        foreach ($attributes as $attribute) {
            if ($key == $attribute->key) {
                return $attribute->value;
            }
        }
        return null;
    }

    protected function set_attribute($attribute, $value): bool
    {
        $attribute = $this->list_attribute($attribute);
        $data = ['attributes' => [['customAttributeId' => $attribute->id, 'value' => $value]]];
        $id = $this->entity->id;
        return (bool)(new API_Unms())->request('clients/services/' . $id, 'PATCH', $data);
    }

    protected function list_attribute($attribute): ?stdClass
    {
        $list = (new API_Unms())->request('custom-attributes');
        foreach ($list as $item) {
            if ($item->key == $attribute) {
                return $item;
            }
        }
        return null;
    }

    protected function db(): ?API_SQLite
    {
        try {
            return new API_SQLite();
        } catch (Exception $e) {
            $this->setErr($e->getMessage());
            return null;
        }
    }

    protected function setErr($err)
    {
        $this->ready = false;
        $this->status->error = true;
        $this->status->message = $err;
        return null;
    }

    public function status()
    {
        return $this->status;
    }

    public function error()
    {
        return $this->status->message;
    }

    public function entity()
    {
        return $this->data->extraData->entity;
    }

}