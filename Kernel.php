<?php

namespace App\ZohoModels;

use zcrmsdk\crm\exception\ZCRMException;
use zcrmsdk\crm\crud\ZCRMRecord;
use zcrmsdk\crm\setup\restclient\ZCRMRestClient;
use Log;

abstract class Kernel
{
    abstract protected function getConfig();
    abstract protected function getMap();
    abstract protected function getEnums();
    abstract protected function getLookups();

    public static function initialize()
    {
        $configuration = [
            'access_type' => 'offline',
            'accounts_url' => config('zoho.account_url'),
            'apiBaseUrl' => config('zoho.api_base_url'),
            'apiVersion' => 'v2',
            'client_id' => config('zoho.client_id'),
            'client_secret' => config('zoho.client_secret'),
            'currentUserEmail' => config('zoho.email'),
            'persistence_handler_class' => 'ZohoOAuthPersistenceHandler',
            'redirect_uri' => config('zoho.redirect_uri'),
            'sandbox' => config('zoho.use_sandbox'),
            'token_persistence_path' => realpath(base_path() . '/'),
        ];

        ZCRMRestClient::initialize($configuration);
    }

    private function getConfigs()
    {
        return [
            $map = $this->getMap(),
            $enums = $this->getEnums(),
            $lookups = $this->getLookups(),
        ];
    }

    protected function parseFromCrm($recordData)
    {
        list($map, $enums, $lookups) = $this->getConfigs();
        $data = $recordData->getData();

        $parsed = [
            'zoho_id' => $recordData->getEntityId()
        ];

        foreach ($map as $portalKey => $zohoKey) {
            if (array_key_exists($zohoKey, $data)) {
                $parsed[$portalKey] = $data[$zohoKey];
            } else {
                $parsed[$portalKey] = null;
            }
        }

        foreach ($enums['crmEqual'] as $portalKey => $enum) {
            if (array_key_exists($enum['zohoKey'], $data) && !is_null($data[$enum['zohoKey']])) {
                $parsed[$portalKey] = $enum['enumClass']::getValue($data[$enum['zohoKey']]);
            } else {
                $parsed[$portalKey] = null;
            }
        }

        foreach ($enums['crmDifferent'] as $portalKey => $enum) {
            if (array_key_exists($enum['zohoKey'], $data) && !is_null($data[$enum['zohoKey']])) {
                $parsed[$portalKey] = $enum['enumClass']::parseCrmValue($data[$enum['zohoKey']]);
            } else {
                $parsed[$portalKey] = null;
            }
        }

        foreach ($lookups as $portalKey => $lookup) {
            if (array_key_exists('model', $lookup)) {
                $zohoRecord = $data[$lookup['zohoKeyName']];
                $relatedModel = null;

                if (!is_null($lookup['model']) && !is_null($zohoRecord)) {
                    $relatedModel = $lookup['model']::where('zoho_id', $zohoRecord->getEntityId())->first();
                }

                if (is_null($relatedModel)) {
                    $parsed[$portalKey] = is_null($zohoRecord) ? null : $zohoRecord->getEntityId();
                } else {
                    $parsed[$portalKey] = $relatedModel->id;
                }
            } else {
                foreach ($lookup as $polymorphLookup) {
                    $zohoRecord = $data[$polymorphLookup['zohoKeyName']];

                    if (!is_null($polymorphLookup['zohoKeyName']) && !is_null($zohoRecord)) {
                        $relatedModel = $polymorphLookup['model']::where('zoho_id', $zohoRecord->getEntityId())->first();

                        if (is_null($relatedModel)) {
                            $parsed[$portalKey] = $zohoRecord->getEntityId();
                        } else {
                            $parsed[$portalKey] = $relatedModel->id;
                        }
                    }
                }
            }
        }

        return $parsed;
    }

    private function getValueByKey($data, $key)
    {
        if (is_array($data)) {
            return isset($data[$key]) ? $data[$key] : null;
        } else {
            return $data->$key;
        }
    }

    private function parseFromPortal($rawData)
    {
        if (is_null($rawData)) {
            Log::error('No data passed to insert update method');
            throw new \Exception('No data passed to insert update method');
        }

        list($map, $enums, $lookups) = $this->getConfigs();
        $parsed = [];

        foreach ($map as $portalKey => $zohoKey) {
            $value = $this->getValueByKey($rawData, $portalKey);
            $arrayDataValue = $this->getValueByKey($rawData, 'data');

            if (!is_null($value)) {
                $parsed[$zohoKey] = $value;
            } elseif (
                !is_null($arrayDataValue) &&
                is_array($arrayDataValue) &&
                array_key_exists($portalKey, $arrayDataValue)
            ) {
                $parsed[$zohoKey] = $arrayDataValue[$portalKey];
            }
        }

        foreach ($enums['crmEqual'] as $portalKey => $enum) {
            $value = $this->getValueByKey($rawData, $portalKey);
            $arrayDataValue = $this->getValueByKey($rawData, 'data');

            if (!is_null($value)) {
                $parsed[$enum['zohoKey']] = $enum['enumClass']::getKey($value);
            } elseif (
                !is_null($arrayDataValue) &&
                is_array($arrayDataValue) &&
                array_key_exists($portalKey, $arrayDataValue)
            ) {
                $parsed[$enum['zohoKey']] = $enum['enumClass']::getKey((int)$arrayDataValue[$portalKey]);
            } else {
                if (array_key_exists($portalKey, $rawData)) {
                    $parsed[$enum['zohoKey']] = null;
                }
            }
        }

        foreach ($enums['crmDifferent'] as $portalKey => $enum) {
            $value = $this->getValueByKey($rawData, $portalKey);
            $arrayDataValue = $this->getValueByKey($rawData, 'data');

            if (!is_null($value)) {
                $parsed[$enum['zohoKey']] = $enum['enumClass']::getCrmValue($value);
            } elseif (
                !is_null($arrayDataValue) &&
                is_array($arrayDataValue) &&
                array_key_exists($portalKey, $arrayDataValue)
            ) {
                $parsed[$enum['zohoKey']] = $enum['enumClass']::getCrmValue($arrayDataValue[$portalKey]);
            } else {
                if (array_key_exists($portalKey, $rawData)) {
                    $parsed[$enum['zohoKey']] = null;
                }
            }
        }

        foreach ($lookups as $portalKey => $lookup) {
            if (!array_key_exists('model', $lookup)) {
                foreach ($lookup as $polymorphPortalKey => $polymorphLookup) {
                    $value = $this->getValueByKey($rawData, $polymorphPortalKey);

                    if (!is_null($value)) {
                        $parsedZohoId = $this->parseLookup([
                            'portalKey' => $polymorphPortalKey,
                            'rawData' => $rawData,
                            'lookup' => $polymorphLookup,
                            'value' => $value->id,
                            'arrayDataValue' => $arrayDataValue,
                        ]);

                        $parsed[$polymorphLookup['zohoKeyName']] = $parsedZohoId;
                    }
                }
            } else {
                $value = $this->getValueByKey($rawData, $portalKey);

                if (!is_null($value)) {
                    $parsedZohoId = $this->parseLookup([
                        'portalKey' => $portalKey,
                        'rawData' => $rawData,
                        'lookup' => $lookup,
                        'value' => $value,
                        'arrayDataValue' => $arrayDataValue,
                    ]);

                    $parsed[$lookup['zohoKeyName']] = $parsedZohoId;
                }
            }
        }

        return $parsed;
    }

    private function parseLookup($data)
    {
        $rawData = $data['rawData'];
        $lookup = $data['lookup'];
        $value = $data['value'];
        $arrayDataValue = $data['arrayDataValue'];
        $portalKey = $data['portalKey'];
        $parsedByRelation = false;

        if (!is_array($rawData) && count($rawData->getRelations()) > 0) {
            foreach ($rawData->getRelations() as $relatedModel) {
                if ($relatedModel instanceof $lookup['model']) {
                    if (!is_null($value) && $relatedModel->id === $value) {
                        return $relatedModel->zoho_id;
                    } elseif (
                        !is_null($arrayDataValue) &&
                        is_array($arrayDataValue) &&
                        array_key_exists($portalKey, $arrayDataValue) &&
                        $relatedModel->id === $arrayDataValue[$portalKey]
                    ) {
                        return $relatedModel->zoho_id;
                    }
                }
            }
        }

        if (!$parsedByRelation) {
            if (is_null($lookup['model'])) {
                return $value;
            }

            if (!is_null($value)) {
                $relatedModelId = $value;
            } elseif (
                !is_null($arrayDataValue) &&
                is_array($arrayDataValue) &&
                array_key_exists($portalKey, $arrayDataValue)
            ) {
                $relatedModelId = $arrayDataValue[$portalKey];
            } else {
                if (array_key_exists($portalKey, $rawData)) {
                    return null;
                }

                return null;
            }

            $relatedModel = $lookup['model']::find($relatedModelId);

            if (!is_null($relatedModel)) {
                return $relatedModel->zoho_id;
            } else {
                if (array_key_exists($portalKey, $rawData)) {
                    return null;
                }
            }
        }
    }

    private function getClient()
    {
        return new ZohoCRMClient($this->getConfig()['module'], config('zoho.api_key'), 'com', 5, config('zoho.use_sandbox'));
    }

    protected function insertRecord($model, bool $withWorkflow) {
        self::initialize();

        $trigger = [];

        if ($withWorkflow) {
            $trigger[] = 'workflow';
        }

        $moduleIns = ZCRMRestClient::getInstance()->getModuleInstance($this->getConfig()['module']);
        $recordIns = ZCRMRecord::getInstance($this->getConfig()['module'], null);

        $data = $this->parseFromPortal($model);
        $data['Territory'] = config('zoho.territory');

        foreach ($data as $fieldName => $value) {
            if ($value === 'true') {
                $value = true;
            } elseif ($value === 'false') {
                $value = false;
            }

            $recordIns->setFieldValue($fieldName, $value);
        }

        try {
            $responseIns = $moduleIns->createRecords([$recordIns], $trigger);
        } catch (ZCRMException $e) {
            $errMsg = 'Create request error for: ' . $this->getConfig()['module'] . " with error: " . $e->getMessage();
            Log::debug($errMsg);
            throw new \Exception($errMsg);
        }

        $responseData = $responseIns->getEntityResponses()[0];

        if ($responseData->getStatus() === 'success') {
            return $responseData->getData()->getEntityId();
        } else {
            $errMsg = $responseData->getMessage() . ' on record create. For module: ' . $this->getConfig()['module']  . ' data: ' . json_encode($responseData->getDetails());
            Log::debug($errMsg);
            throw new \Exception($errMsg);
        }
    }

    protected function updateRecord($rawData, bool $withWorkflow) {
        self::initialize();

        $trigger = [];

        if ($withWorkflow) {
            $trigger[] = 'workflow';
        }

        $data = $this->parseFromPortal($rawData);

        $data['Territory'] = config('zoho.territory');

        if (is_array($rawData)) {
            $zohoId = isset($rawData['zoho_id']) ? $rawData['zoho_id'] : null;
        } else {
            $zohoId = $rawData->zoho_id;
        }

        $moduleIns = ZCRMRestClient::getInstance()->getModuleInstance($this->getConfig()['module']);
        $recordIns = ZCRMRecord::getInstance($this->getConfig()['module'], $zohoId);

        foreach ($data as $fieldName => $value) {
            if ($value === 'true') {
                $value = true;
            } elseif ($value === 'false') {
                $value = false;
            }

            $recordIns->setFieldValue($fieldName, $value);
        }

        try {
            $responseIns = $moduleIns->updateRecords([$recordIns], $trigger);
        } catch (ZCRMException $e) {
            $errMsg = 'Update request error for: ' . $this->getConfig()['module'] . " with id: {$zohoId} error: " . $e->getMessage();
            Log::debug($errMsg);
            throw new \Exception($errMsg);
        }

        $responseData = $responseIns->getEntityResponses()[0];

        if ($responseData->getStatus() === 'success') {
            return $responseData->getData()->getEntityId();
        } else {
            $errMsg = $responseData->getMessage() . ' on record update. For module: ' . $this->getConfig()['module'] . ' data: ' . json_encode($responseData->getDetails());
            Log::debug($errMsg);
            throw new \Exception($errMsg);
        }
    }

    protected function getRecordData($zohoRecordId)
    {
        self::initialize();

        try {
            $moduleIns = ZCRMRestClient::getInstance()->getModuleInstance($this->getConfig()['module']);
            $dataResp = $moduleIns->getRecord($zohoRecordId)->getData();
        } catch (ZCRMException $e) {
            $errMsg = 'Request error for: ' . $this->getConfig()['module'] . " with zoho id {$zohoRecordId}. and error: " . $e->getMessage();
            Log::debug($errMsg);
            throw new \Exception($errMsg);
        }

        return $this->parseFromCrm($dataResp);
    }

    protected function getAllRecordsData()
    {
        self::initialize();

        $moduleIns = ZCRMRestClient::getInstance()->getModuleInstance($this->getConfig()['module']);

        try {
            $response = $moduleIns->getRecords(null, null, null, 1, 200, null);
        } catch (ZCRMException $e) {
            $errMsg = 'All records request error for: ' . $this->getConfig()['module'] . " with error: " . $e->getMessage();
            Log::debug($errMsg);
            throw new \Exception($errMsg);
        }

        $records = $response->getData();
        $parsed = [];

        foreach ($records as $record) {
            $parsed[] = $this->parseFromCrm($record);
        }

        return $parsed;
    }

    protected function searchRecordsData($parentModuleName, $parentModuleId)
    {
        self::initialize();

        $criteria = "({$parentModuleName}:equals:{$parentModuleId})";
        $moduleIns = ZCRMRestClient::getInstance()->getModuleInstance($this->getConfig()['module']);

        try {
            $response = $moduleIns->searchRecordsByCriteria($criteria, 1, 200);
        } catch (ZCRMException $e) {
            $errMsg = 'Search records request error for: ' . $this->getConfig()['module'] . " with error: " . $e->getMessage();
            Log::debug($errMsg);
            throw new \Exception($errMsg);
        }

        $records = $response->getData();

        $parsed = [];

        foreach ($records as $record) {
            $parsed[] = $this->parseFromCrm($record);
        }

        return $parsed;
    }

    protected function uploadAttachment($attachToId, $file)
    {
        self::initialize();
        $record = ZCRMRestClient::getInstance()->getRecordInstance($this->getConfig()['module'], $attachToId);
        $responseIns = $record->uploadAttachment($file);

        return $responseIns;
    }
}
