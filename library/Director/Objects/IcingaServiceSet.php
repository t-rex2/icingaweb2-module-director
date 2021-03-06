<?php

namespace Icinga\Module\Director\Objects;

use Exception;
use Icinga\Data\Filter\Filter;
use Icinga\Module\Director\Exception\DuplicateKeyException;
use Icinga\Module\Director\IcingaConfig\IcingaConfig;
use InvalidArgumentException;

class IcingaServiceSet extends IcingaObject
{
    protected $table = 'icinga_service_set';

    protected $defaultProperties = array(
        'id'                    => null,
        'host_id'               => null,
        'object_name'           => null,
        'object_type'           => null,
        'description'           => null,
        'assign_filter'         => null,
    );

    protected $keyName = array('host_id', 'object_name');

    protected $supportsImports = true;

    protected $supportsCustomVars = true;

    protected $supportsApplyRules = true;

    protected $supportedInLegacy = true;

    protected $relations = array(
        'host' => 'IcingaHost',
    );

    public function isDisabled()
    {
        return false;
    }

    public function supportsAssignments()
    {
        return true;
    }

    protected function setKey($key)
    {
        if (is_int($key)) {
            $this->id = $key;
        } elseif (is_string($key)) {
            $keyComponents = preg_split('~!~', $key);
            if (count($keyComponents) === 1) {
                $this->set('object_name', $keyComponents[0]);
                $this->set('object_type', 'template');
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Can not parse key: %s',
                    $key
                ));
            }
        } else {
            return parent::setKey($key);
        }

        return $this;
    }

    /**
     * @return IcingaService[]
     */
    public function getServiceObjects()
    {
        if ($this->get('host_id')) {
            $imports = $this->imports()->getObjects();
            if (empty($imports)) {
                return array();
            }
            return $this->getServiceObjectsForSet(array_shift($imports));
        } else {
            return $this->getServiceObjectsForSet($this);
        }
    }

    protected function getServiceObjectsForSet(IcingaServiceSet $set)
    {
        if ($set->get('id') === null) {
            return array();
        }

        $connection = $this->getConnection();
        $db = $this->getDb();
        $ids = $db->fetchCol(
            $db->select()->from('icinga_service', 'id')
                ->where('service_set_id = ?', $set->get('id'))
        );

        $services = array();
        foreach ($ids as $id) {
            $service = IcingaService::load(array(
                'id' => $id,
                'object_type' => 'template'
            ), $connection);
            $service->set('service_set', null);

            $services[$service->getObjectName()] = $service;
        }

        return $services;
    }

    public function onDelete()
    {
        $hostId = $this->get('host_id');
        if ($hostId) {
            $deleteIds = [];
            foreach ($this->getServiceObjects() as $service) {
                $deleteIds[] = (int) $service->get('id');
            }

            if (! empty($deleteIds)) {
                $db = $this->getDb();
                $db->delete(
                    'icinga_host_service_blacklist',
                    $db->quoteInto(
                        sprintf('host_id = %s AND service_id IN (?)', $hostId),
                        $deleteIds
                    )
                );
            }
        }

        parent::onDelete();
    }

    public function renderToConfig(IcingaConfig $config)
    {
        if ($this->get('assign_filter') === null && $this->isTemplate()) {
            return;
        }

        if ($config->isLegacy()) {
            $this->renderToLegacyConfig($config);
            return;
        }

        $services = $this->getServiceObjects();
        if (empty($services)) {
            return;
        }
        $file = $this->getConfigFileWithHeader($config);

        // Loop over all services belonging to this set
        // add our assign rules and then add the service to the config
        // eventually clone them beforehand to not get into trouble with caches
        // figure out whether we might need a zone property
        foreach ($services as $service) {
            if ($filter = $this->get('assign_filter')) {
                $service->set('object_type', 'apply');
                $service->set('assign_filter', $filter);
            } elseif ($hostId = $this->get('host_id')) {
                $service->set('object_type', 'object');
                $service->set('use_var_overrides', 'y');
                $service->set('host_id', $this->get('host_id'));
            } else {
                // Service set template without assign filter or host
                continue;
            }

            $this->copyVarsToService($service);
            $file->addObject($service);
        }
    }

    protected function getConfigFileWithHeader(IcingaConfig $config)
    {
        $file = $config->configFile(
            'zones.d/' . $this->getRenderingZone($config) . '/servicesets'
        );

        $file->addContent($this->getConfigHeaderComment($config));
        return $file;
    }

    protected function getConfigHeaderComment(IcingaConfig $config)
    {
        if ($config->isLegacy()) {
            if ($this->get('assign_filter')) {
                $comment = "## applied Service Set '%s'\n\n";
            } else {
                $comment = "## Service Set '%s' on this host\n\n";
            }
        } else {
            $comment = "/** Service Set '%s' **/\n\n";
        }

        return sprintf($comment, $this->getObjectName());
    }

    public function copyVarsToService(IcingaService $service)
    {
        $serviceVars = $service->vars();

        foreach ($this->vars() as $k => $var) {
            $serviceVars->$k = $var;
        }

        return $this;
    }

    public function renderToLegacyConfig(IcingaConfig $config)
    {
        if ($this->get('assign_filter') === null && $this->isTemplate()) {
            return;
        }

        // evaluate my assign rules once, get related hosts
        // Loop over all services belonging to this set
        // generate every service with host_name host1,host2... -> not yet. And Zones?

        $conn = $this->getConnection();

        // Delegating this to the service would look, but this way it's faster
        if ($filter = $this->get('assign_filter')) {
            $filter = Filter::fromQueryString($filter);

            $hostnames = HostApplyMatches::forFilter($filter, $conn);
        } else {
            $hostnames = array($this->getRelated('host')->object_name);
        }

        foreach ($this->mapHostsToZones($hostnames) as $zone => $names) {
            $file = $config->configFile('director/' . $zone . '/servicesets', '.cfg');
            $file->addContent($this->getConfigHeaderComment($config));

            foreach ($this->getServiceObjects() as $service) {
                $service->set('object_type', 'object');
                $service->set('host_id', $names);

                $this->copyVarsToService($service);

                $file->addLegacyObject($service);
            }
        }
    }

    public function getRenderingZone(IcingaConfig $config = null)
    {
        if ($this->get('host_id') === null) {
            return $this->connection->getDefaultGlobalZoneName();
        } else {
            $host = $this->getRelatedObject('host', $this->get('host_id'));
            return $host->getRenderingZone($config);
        }
    }

    public function createWhere()
    {
        $where = parent::createWhere();
        if (! $this->hasBeenLoadedFromDb()) {
            if (null === $this->get('host_id') && null === $this->get('id')) {
                $where .= " AND object_type = 'template'";
            }
        }

        return $where;
    }

    /**
     * @return IcingaService[]
     */
    public function fetchServices()
    {
        $connection = $this->getConnection();
        $db = $connection->getDbAdapter();

        /** @var IcingaService[] $services */
        $services = IcingaService::loadAll(
            $connection,
            $db->select()->from('icinga_service')
                ->where('service_set_id = ?', $this->get('id'))
        );

        return $services;
    }

    /**
     * Fetch IcingaServiceSet that are based on this set and added to hosts directly
     *
     * @return IcingaServiceSet[]
     */
    public function fetchHostSets()
    {
        if ($this->id === null) {
            return [];
        }

        $query = $this->db->select()
            ->from(
                ['o' => $this->table]
            )->join(
                ['ssi' => $this->table . '_inheritance'],
                'ssi.service_set_id = o.id',
                []
            )->where(
                'ssi.parent_service_set_id = ?',
                $this->id
            );

        return static::loadAll($this->connection, $query);
    }

    protected function beforeStore()
    {
        parent::beforeStore();

        $name = $this->getObjectName();

        if ($this->isObject() && $this->get('host_id') === null) {
            throw new InvalidArgumentException(
                'A Service Set cannot be an object with no related host'
            );
        }
        // checking if template object_name is unique
        // TODO: Move to IcingaObject
        if (! $this->hasBeenLoadedFromDb() && $this->isTemplate() && static::exists($name, $this->connection)) {
            throw new DuplicateKeyException(
                '%s template "%s" already existing in database!',
                $this->getType(),
                $name
            );
        }
    }

    public function toSingleIcingaConfig()
    {
        $config = parent::toSingleIcingaConfig();

        try {
            foreach ($this->fetchHostSets() as $set) {
                $set->renderToConfig($config);
            }
        } catch (Exception $e) {
            $config->configFile(
                'failed-to-render'
            )->prepend(
                "/** Failed to render this object **/\n"
                . '/*  ' . $e->getMessage() . ' */'
            );
        }

        return $config;
    }
}
