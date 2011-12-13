<?php
/**
 * @package modx
 */
abstract class modDistribution {
    /** @var xPDO $xpdo */
    public $xpdo;

    public $config = array();
    public $debugTimeStart = 0;
    public $packageDirectory = '';
    /** @var xPDOTransport $package */
    public $package;
    /** @var string $name The name of the distribution */
    public $name = '';
    /** @var SimpleXMLElement $data */
    public $data;

    function __construct(array $config = array()) {
        $this->config = array_merge(array(
            'buildImage' => 'wc',
        ),$config);
    }

    public function startDebug() {
        $mtime = microtime();
        $mtime = explode(" ", $mtime);
        $mtime = $mtime[1] + $mtime[0];
        $this->debugTimeStart = $mtime;
        unset($mtime);
        /* get rid of time limit */
        set_time_limit(0);
        error_reporting(E_ALL | E_STRICT); ini_set('display_errors',true);
    }

    /**
     * @param string|array $key
     * @param null|array $options
     * @param mixed $default
     * @param boolean $skipEmpty
     * @return array|null
     */
    public function getOption($key, $options = null, $default = null, $skipEmpty = false) {
        $option= $default;
        if (is_array($key)) {
            if (!is_array($option)) {
                $default= $option;
                $option= array();
            }
            foreach ($key as $k) {
                $option[$k]= $this->getOption($k, $options, $default);
            }
        } elseif (is_string($key) && !empty($key)) {
            if (is_array($options) && !empty($options) && array_key_exists($key, $options) && (!$skipEmpty || ($skipEmpty && $options[$key] !== ''))) {
                $option= $options[$key];
            } elseif (is_array($this->config) && !empty($this->config) && array_key_exists($key, $this->config) && (!$skipEmpty || ($skipEmpty && $this->config[$key] !== ''))) {
                $option= $this->config[$key];
            }
        }
        return $option;
    }

    protected function _loadConfig() {
        /* buildImage can be defined for running against a specific build image
            wc default means it is run against working copy */
        $buildImage = $this->getOption('buildImage',null,'wc');

        /* if buildImage is other than wc or blank, try to load a config file for
            distributions that uses the buildImage variable (see build.distrib.config.sample.php) */
        $buildConfig = empty($buildImage) || $buildImage === 'wc'
                ? (dirname(__FILE__) . '/build.config.php')
                : (dirname(__FILE__) . '/build.distrib.config.php');
        $buildConfig = realpath($this->getOption('buildConfig',null,$buildConfig));

        /* override with your own defines here (see build.config.sample.php) */
        $included = false;
        if (file_exists($buildConfig)) {
            $included = @include $buildConfig;
        }
        if (!$included)
            die($buildConfig . ' was not found. Please make sure you have created one using the template of build.config.sample.php.');

        unset($included);
    }

    protected function _loadDefines() {

        if (!defined('MODX_CORE_PATH'))
            define('MODX_CORE_PATH', dirname(dirname(__FILE__)) . '/core/');

        require_once MODX_CORE_PATH . 'xpdo/xpdo.class.php';

        /* define the MODX path constants necessary for core installation */
        if (!defined('MODX_BASE_PATH'))
            define('MODX_BASE_PATH', dirname(MODX_CORE_PATH) . '/');
        if (!defined('MODX_MANAGER_PATH'))
            define('MODX_MANAGER_PATH', MODX_BASE_PATH . 'manager/');
        if (!defined('MODX_CONNECTORS_PATH'))
            define('MODX_CONNECTORS_PATH', MODX_BASE_PATH . 'connectors/');
        if (!defined('MODX_ASSETS_PATH'))
            define('MODX_ASSETS_PATH', MODX_BASE_PATH . 'assets/');

        /* define the connection variables */
        if (!defined('XPDO_DSN'))
            define('XPDO_DSN', 'mysql:host=localhost;dbname=modx;charset=utf8');
        if (!defined('XPDO_DB_USER'))
            define('XPDO_DB_USER', 'root');
        if (!defined('XPDO_DB_PASS'))
            define('XPDO_DB_PASS', '');
        if (!defined('XPDO_TABLE_PREFIX'))
            define('XPDO_TABLE_PREFIX', 'modx_');

        /* define the actual _build location for including build assets */
        if (!defined('MODX_BUILD_DIR'))
            define('MODX_BUILD_DIR', MODX_BASE_PATH . '_build/');
    }

    protected function _loadBuildProperties() {
        /* get properties */
        $properties = array();
        $f = dirname(__FILE__) . '/build.properties.php';
        $included = false;
        if (file_exists($f)) {
            $included = @include $f;
        }
        if (!$included)
            die('build.properties.php was not found. Please make sure you have created one using the template of build.properties.sample.php.');

        $this->config = array_merge($properties,$this->config);
        unset($f, $included);
    }

    protected function _loadXPDO() {
        /* instantiate xpdo instance */
        $this->xpdo = new xPDO(XPDO_DSN, XPDO_DB_USER, XPDO_DB_PASS,
            array (
                xPDO::OPT_TABLE_PREFIX => XPDO_TABLE_PREFIX,
                xPDO::OPT_CACHE_PATH => MODX_CORE_PATH . 'cache/',
            ),
            array (
                PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING,
            )
        );
        $this->xpdo->getCacheManager();
        $this->xpdo->setLogLevel(xPDO::LOG_LEVEL_INFO);
        $this->xpdo->setLogTarget(XPDO_CLI_MODE ? 'ECHO' : 'HTML');

        $this->xpdo->loadClass('transport.xPDOTransport', XPDO_CORE_PATH, true, true);
    }

    public function log($level,$message = '') {
        $this->xpdo->log($level,$message);
        flush();
    }

    public function setPackageDirectory() {
        $this->packageDirectory = MODX_CORE_PATH . 'packages/';
    }

    /**
     * Remove pre-existing package files and directory
     */
    public function cleanTransportFiles() {
        if (file_exists($this->packageDirectory . 'core.transport.zip')) {
            @unlink($this->packageDirectory . 'core.transport.zip');
        }
        if (file_exists($this->packageDirectory . 'core') && is_dir($this->packageDirectory . 'core')) {
            $this->xpdo->cacheManager->deleteTree($this->packageDirectory . 'core',array(
                'deleteTop' => true,
                'skipDirs' => false,
                'extensions' => '*',
            ));
        }
        if (!file_exists($this->packageDirectory . 'core') && !file_exists($this->packageDirectory . 'core.transport.zip')) {
            $this->log(xPDO::LOG_LEVEL_INFO,'Removed pre-existing core/ and core.transport.zip.');
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR,'Could not remove core/ and core.transport.zip before starting build.');
        }
    }

    public function createTransport() {
        $this->package = new xPDOTransport($this->xpdo, 'core', $this->packageDirectory);
        unset($packageDirectory);

        $this->xpdo->setPackage('modx', MODX_CORE_PATH . 'model/');
        $this->xpdo->loadClass('modAccess');
        $this->xpdo->loadClass('modAccessibleObject');
        $this->xpdo->loadClass('modAccessibleSimpleObject');
        $this->xpdo->loadClass('modPrincipal');
        $this->log(xPDO::LOG_LEVEL_INFO,'Core transport package created.');
    }


    public function initialize() {
        $this->startDebug();
        $this->_loadConfig();
        $this->_loadDefines();
        $this->_loadBuildProperties();
        $this->_loadXPDO();

        $this->log(xPDO::LOG_LEVEL_INFO,'Beginning build script processes...');
        $this->setPackageDirectory();
        $this->cleanTransportFiles();
        $this->createTransport();
    }

    public function build() {
        $this->xpdo->log(xPDO::LOG_LEVEL_INFO,'Adding in Vehicles...');
        if ($this->_loadDistributionFile()) {
            $this->gather($this->data->children());
            $this->pack();
        }
        $this->endDebug();
    }

    protected function _loadDistributionFile() {
        if (empty($this->name)) return false;

        $file = $this->getDistributionFileLocation();
        if (!file_exists($file)) return false;
        $xml = file_get_contents($file);
        /** @TODO Refactor this and $this->data to a reader class to properly allow different types of data storage */
        $this->data = simplexml_load_string($xml);
        return $this->data;
    }

    protected function getDistributionFileLocation() {
        if (empty($this->config['distributionFile'])) {
            $this->config['distributionFile'] = (dirname(__FILE__).'/distributions/'.$this->name.'.distribution.xml');
        }
        return realpath($this->config['distributionFile']);
    }

    public function gather($children) {
        /** @var SimpleXMLElement $child */
        foreach ($children as $child) {
            $child = new modDistributionNode($child);
            switch ($child->getName()) {
                case 'external':
                    $data = $this->loadExternal($child);
                    $data = simplexml_load_string($data);
                    $this->gather($data->children());
                    break;
                case 'vehicle':
                    $this->addVehicle($child);
                    break;
                case 'vehicleCollection':
                default:
                    $this->addVehicleCollection($child);
                    break;
            }
        }
    }

    public function loadExternal(modDistributionNode $child) {
        $file = $child->getValue();
        $file = str_replace('{build_dir}',MODX_BUILD_DIR,$file);
        if (!file_exists($file)) {
            $file = dirname($this->getDistributionFileLocation()).'/'.$this->name.'/'.$file;
        }
        if (!file_exists($file)) return false;
        $data = file_get_contents($file);
        return $data;
    }

    public function addVehicleCollection(modDistributionNode $vehicleCollection) {
        $attributes = $this->parseVehicleAttributes($vehicleCollection);

        /** @var SimpleXMLElement $vehicleData */
        $vehicleClasses = array();
        $data = $vehicleCollection->children();
        foreach ($data as $vehicleData) {
            $vehicleData = new modDistributionNode($vehicleData);
            if ($vehicleData->getName() == 'relatedobjectattribute') continue;
            $vehicle = $this->addVehicle($vehicleData,$attributes);
            if ($vehicle) {
                $vehicleClass = get_class($vehicle);
                if (empty($vehicleClasses[$vehicleClass])) $vehicleClasses[$vehicleClass] = 0;
                $vehicleClasses[$vehicleClass]++;
            }
        }

        foreach ($vehicleClasses as $vehicleClass => $count) {
            $this->log(xPDO::LOG_LEVEL_INFO,'Added in '.$count.' '.str_replace(array('_mysql','_sqlsrv'),'',$vehicleClass).' ');
        }
    }

    public function addVehicle(modDistributionNode $vehicleData,array $attributes = array()) {
        $vehicle = false;
        $vehicleAttributes = $this->parseVehicleAttributes($vehicleData);
        $attributes = array_merge($attributes,$vehicleAttributes);
        $class = $vehicleData->getAttribute('class','xPDOObjectVehicle');
        switch ($class) {
            case 'xPDOObjectVehicle':
                $this->addObjectVehicle($vehicleData,$attributes);
                break;
            case 'xPDOFileVehicle':
                $this->addFileVehicle($vehicleData,$attributes);
                break;
        }
        return $vehicle;
    }

    public function addFileVehicle(modDistributionNode $vehicleData,array $attributes = array()) {
        $fileAttributes = $vehicleData->children();
        unset($attributes['class']);
        $attributes['vehicle_class'] = 'xPDOFileVehicle';
        $file = array();

        foreach ($fileAttributes as $fileAttribute) {
            /** @var SimpleXMLElement $field */
            $fileNode = new modDistributionNode($fileAttribute);
            if ($fileNode->getName() == 'xpdo_resolver') {
                $attributes['resolve'][] = $this->addResolver($fileNode);
            } else if ($fileNode->getName() == 'xpdo_validator') {
                $attributes['validate'][] = $this->addResolver($fileNode);
            } else {
                $file[$fileNode->getName()] = $fileNode->getValue();
            }
        }
        $this->package->put($file,$attributes);
        return $file;
    }

    public function addObjectVehicle(modDistributionNode $vehicleData,array $attributes = array()) {
        $objectClass = $vehicleData->getAttribute('object_class');
        if (empty($objectClass)) return false;

        /** @var xPDOObject $vehicle */
        $vehicle = $this->xpdo->newObject($objectClass);
        if (empty($vehicle)) return false;

        $fields = $vehicleData->children();
        /** @var SimpleXMLElement $field */
        foreach ($fields as $field) {
            $field = new modDistributionNode($field);
            if ($field->getName() == 'xpdo_related_object') {
                $this->getRelatedObject($vehicle,$field);
            } else if ($field->getName() == 'xpdo_resolver') {
                $attributes['resolve'][] = $this->addResolver($field);
            } else if ($field->getName() == 'xpdo_validator') {
                $attributes['validate'][] = $this->addResolver($field);
            } else {
                $vehicle->set($field->getName(),$field->getValue());
            }
        }
        $this->package->put($vehicle,$attributes);
        return $vehicle;
    }

    public function addResolver(modDistributionNode $node) {
        $resolver = array();
        $children = $node->children();
        foreach ($children as $item) {
            $item = new modDistributionNode($item);
            $resolver[$item->getName()] = $item->getValue();
        }
        return $resolver;
    }

    public function addValidator(modDistributionNode $node) {
        $validator = array();
        $children = $node->children();
        foreach ($children as $item) {
            $item = new modDistributionNode($item);
            $validator[$item->getName()] = $item->getValue();
        }
        return $validator;
    }

    public function getRelatedObject(xPDOObject &$object,modDistributionNode $data) {
        $objectClass = $data->getAttribute('object_class');
        if (empty($objectClass)) return;
        /** @var xPDOObject $relatedObject */
        $relatedObject = $this->xpdo->newObject($objectClass);
        $fields = $data->children();
        /** @var SimpleXMLElement $field */
        foreach ($fields as $field) {
            $field = new modDistributionNode($field);
            if ($field->getName() == 'xpdo_related_object') {
                $this->getRelatedObject($relatedObject,$field);
            } else {
                $relatedObject->set($field->getName(),$field->getValue());
            }
        }

        $alias = $data->getAttribute('alias','');
        $cardinality = $data->getAttribute('cardinality');
        if ($cardinality == 'many') {
            $object->addMany($relatedObject,$alias);
        } else {
            $object->addOne($relatedObject,$alias);
        }
    }

    /**
     * @param modDistributionNode $vehicle
     * @return array
     */
    public function parseVehicleAttributes(modDistributionNode $vehicle) {
        $attributes = array();
        $attributesData = $vehicle->attributes();
        /** @var SimpleXMLElement $attribute */
        foreach ($attributesData as $attribute) {
            $attribute = new modDistributionNode($attribute);
            $attributes[$attribute->getName()] = $attribute->getValue();
        }

        if (!empty($attributes['related_objects'])) {
            $attributes[xPDOTransport::RELATED_OBJECT_ATTRIBUTES] = $this->getRelatedObjectAttributes($vehicle);
        }
        return $attributes;
    }

    /**
     * @param modDistributionNode $vehicle
     * @return array
     */
    public function getRelatedObjectAttributes(modDistributionNode $vehicle) {
        $attributes = array();
        $children = $vehicle->children();
        /** @var SimpleXMLElement $child */
        foreach ($children as $child) {
            $child = new modDistributionNode($child);
            if ($child->getName() == 'relatedobjectattribute') {
                $relAttr = $this->parseVehicleAttributes($child);
                $attributes[$relAttr['alias']] = $relAttr;
            }
        }

        return $attributes;
    }

    /**
     * Zip up package
     * @return boolean
     */
    public function pack() {
        $this->xpdo->log(xPDO::LOG_LEVEL_INFO,'Beginning to zip up transport package...');
        $packed = $this->package->pack();
        if ($packed) {
            $this->log(xPDO::LOG_LEVEL_INFO,'Transport zip created. Build script finished.');
        } else {
            $this->log(xPDO::LOG_LEVEL_INFO,'Error creating transport zip!');
        }
        return $packed;
    }

    public function endDebug() {
        $mtime = microtime();
        $mtime = explode(" ", $mtime);
        $mtime = $mtime[1] + $mtime[0];
        $tend = $mtime;
        $totalTime = ($tend - $this->debugTimeStart);
        $totalTime = sprintf("%2.4f s", $totalTime);

        echo "\nExecution time: {$totalTime}\n"; flush();
    }
}
class modCoreDistribution extends modDistribution {
    public $name = 'core';
}


class modDistributionNode {
    /** @var SimpleXMLElement $node */
    public $node;
    public $attributes = array();

    public function __construct ($input = null,$flags = 0,$iterator_class='ArrayIterator') {
        $this->node = $input;
    }

    public function getName() {
        return $this->node->getName();
    }
    public function children($ns = '',$isPrefix = false) {
        return $this->node->children($ns,$isPrefix);
    }
    public function attributes($ns = '',$isPrefix = null) {
        return $this->node->attributes($ns,$isPrefix);
    }
    /**
     * @return mixed
     */
    public function getValue() {
        $attributes = $this->collectAttributes();
        $value = null;
        switch ($this->getName()) {
            case 'unique_key':
                $value = explode(',',(string)$this->node);
                break;
            default:
                if (!empty($attributes['type'])) {
                    switch ($attributes['type']) {
                        case 'boolean':
                        case 'bool':
                            $value = (boolean)$this->node;
                            break;
                        case 'integer':
                        case 'int':
                            $value = (integer)$this->node;
                            break;
                        case 'array':
                            $value = explode(',',(string)$this->node);
                            break;
                        case 'json':
                            $value = json_decode((string)$this->node);
                            break;
                        case 'now':
                            $value = strftime('%Y-%m-%d %H:%M:%S');
                            break;
                        case 'html':
                            $value = (string)$this->node;
                            $value = html_entity_decode($value);
                            break;
                        case 'path':
                            $value = (string)$this->node;
                            $value = str_replace(array(
                                '{{MODX_BUILD_DIR}}',
                                '{{MODX_BASE_PATH}}',
                                '{{MODX_CORE_PATH}}',
                            ),array(
                                MODX_BUILD_DIR,
                                MODX_BASE_PATH,
                                MODX_CORE_PATH,
                            ),$value);
                            break;
                        default:
                            $value = (string)$this->node;
                    }
                } else {
                    $value = (string)$this->node;
                }
                break;
        }
        return $value;
    }

    public function getAttribute($key,$default = null) {
        $this->collectAttributes();
        return isset($this->attributes[$key]) ? (string)$this->attributes[$key] : $default;
    }

    public function collectAttributes() {
        if (!empty($this->attributes)) return $this->attributes;
        $attrs = $this->node->attributes();
        /** @var SimpleXMLElement $attribute */
        foreach ($attrs as $attribute) {
            $this->attributes[$attribute->getName()] = (string)$attribute;
        }
        return $this->attributes;
    }

    public function __toString() {
        return $this->node->__toString();
    }
}