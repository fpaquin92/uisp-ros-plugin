<?php
include_once 'api_attributes.php';

class ApiTrim
{// trim request to min and map to db fields

    private $_attrs ;

    public function trim($type, $request): ?array
    {
        switch (strtolower($type)){
            case 'service':
            case 'services': return $this->trim_service($request);
            case 'client':
            case 'clients': return $this->trim_client($request);
        }
        return null ;
    }

    private function trim_client($request): array
    {
        $item = $request->extraData->entity ?? $request ;
        $array = json_decode(json_encode($item),true);
        $entity = array_fill_keys(['id','firstName','lastName'],null);
        $entity = array_intersect_key($array,$entity);
        $entity['company'] = $item->companyName ?? null ;
        return ['entity' => $entity];
    }

    private function trim_service($request): ?array
    {
        $entity = $request->extraData->entity ?? $request;
        $previous = $request->extraData->entityBeforeEdit ?? null ;
        $return['action'] = $request->changeType ?? 'insert' ;
        $return['entity'] = $this->extract($entity);
        if($previous){
            $return['previous'] = $this->extract($previous);
        }
        MyLog()->Append($return);
        return $return;
    }

    private function extract($item): array
    {
        $entity = $this->attributes()->extract($item->attributes ?? []);
        $fields = "id,clientId,status,uploadSpeed,".
            "downloadSpeed,price,totalPrice,currencyCode";
        $array = json_decode(json_encode($item),true);
        $trim = array_intersect_key($array,
            array_fill_keys(explode(',',$fields),null));
        $entity = array_replace($entity,$trim);
        $entity['planId'] = $item->servicePlanId ?? null;
        return $entity ;
    }

    private function attributes(): ApiAttributes
    {
        if(empty($this->_attrs)){
            $this->_attrs = new ApiAttributes() ;
        }
        return $this->_attrs ;
    }

}
