<?php

include_once 'service_base.php';

class Service_Plan extends Service_Base
{

    public $contention;
    public $id = 0;
    protected $plan;

    protected function init(): void
    {
        parent::init();
        $this->contention = $this->exists() ? 0 :1;
    }

    public function name(): string
    {
        $entity = $this->mode ? 'before' : 'entity';
        return $this->$entity->servicePlanName;
    }

    public function id(): int
    {
        $entity = $this->mode ? 'before' : 'entity';
        return $this->$entity->servicePlanId;
    }

    public function total(): stdClass
    {
        $shares = max($this->shares(), 1);
        $u = $this->rate()->upload * $shares;
        $d = $this->rate()->download * $shares;
        return (object)[
            'text' => $u . 'M/' . $d . 'M',
            'upload' => $u,
            'download' => $d,
        ];
    }

    public function target(): array
    {
        $entity = $this->mode ? 'before' : 'entity';
        $dev = $this->get_attribute_value($this->conf->device_name_attr,$entity);
        $devId = $this->db()->selectDeviceIdByDeviceName($dev);
        $hosts = $this->db()->selectTargets($this->id(),$devId) ?? [];
        return $hosts ;
    }

    protected function shares():int
    { // calculates the number of contention shares
        $ratio = $this->get()['ratio'];
        $children = $this->children();
        $shares = intdiv($children, $ratio);
        return ($children % $ratio) > 0 ? ++$shares : $shares; // go figure :-)
    }

    protected function device(): ?stdClass
    {
        $entity = $this->mode ? 'before' : 'entity';
        $name = $this->get_attribute_value($this->conf->device_name_attr,$entity);
        $dev = $this->db()->selectDeviceByDeviceName($name)
            or $this->setErr('the specified device was not found');
        return $dev ;
    }

    protected function get(): array
    {
        $entity = $this->mode ? 'before' : 'entity';
        $planId = $this->$entity->servicePlanId;
        $this->plan = (new Plans($planId))->list()[$planId] ?? [];
        return $this->plan;
    }

    public function children(): int
    {
        $entity = $this->mode ? 'before' : 'entity';
        $device = $this->get_attribute_value($this->conf->device_name_attr,$entity);
        $planId = $this->$entity->servicePlanId;
        $deviceId = $this->db()->selectDeviceIdByDeviceName($device);
        $children = $this->db()->countDeviceServicesByPlanId($planId, $deviceId);
        $children += $this->contention;
        return max($children, 0);
    }

    public function rate(): stdClass
    {
        $d = $this->entity->downloadSpeed;
        $u = $this->entity->uploadSpeed;
        return (object)[
            'text' => $u . 'M/' . $d . "M",
            'upload' => $u,
            'download' => $d,
        ];
    }


}
