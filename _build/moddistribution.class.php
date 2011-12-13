<?php
/**
 * @package modx
 * @subpackage build
 */
class modDistribution {
    /** @var xPDO $xpdo */
    public $xpdo;

    public $config = array();
    public $debugTimeStart = 0;
    public $packageDirectory = '';
    /** @var modDistributionParser $parser */
    public $parser;
    /** @var xPDOTransport $package */
    public $package;
    /** @var string $name The name of the distribution */
    public $name = 'core';
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
        if ($this->getDistribution()) {
            if ($this->loadParser()) {
                $this->parser->gather($this->data->children());
                $this->pack();
            }
        }
        $this->endDebug();
    }

    public function loadParser() {
        $class = !empty($this->config['parser']) ? $this->config['parser'] : 'modDistributionXmlParser';
        if (class_exists($class)) {
            $this->parser = new $class($this);
        }
        return $this->parser;
    }

    /**
     * Eventually enable different way of configuring this besides static xml-based files
     * @return boolean
     */
    public function getDistribution() {
        $this->config['distribution'] = array_key_exists('distribution',$this->config) && !empty($this->config['distribution']) ? $this->config['distribution'] : 'core';
        return $this->_loadDistributionFile();
    }

    protected function _loadDistributionFile() {
        if (empty($this->config['distribution'])) return false;

        $file = $this->getDistributionFileLocation();
        if (!file_exists($file)) return false;
        $xml = file_get_contents($file);
        /** @TODO Eventually refactor this to properly allow different types of data storage besides just xml for the distribution file */
        $this->data = simplexml_load_string($xml);
        $this->name = !empty($this->data['name']) ? (string)$this->data['name'] : 'core';
        $attributes = $this->data->attributes();
        foreach ($attributes as $attr) {
            if (empty($this->config[$attr->getName()])) {
                $this->config[$attr->getName()] = (string)$attr;
            }
        }
        return true;
    }

    public function getDistributionFileLocation() {
        if (empty($this->config['distributionFile'])) {
            $this->config['distributionFile'] = (dirname(__FILE__).'/distributions/'.$this->config['distribution'].'.distribution.xml');
        }
        return realpath($this->config['distributionFile']);
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

    /**
     * End the debug timing
     */
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

/**
 * Abstract class for parsing distribution files
 *
 * @package modx
 * @subpackage build
 */
abstract class modDistributionParser {
    /** @var xPDO $xpdo */
    public $xpdo;
    /** @var modDistribution $distribution */
    public $distribution;

    function __construct(modDistribution &$distribution) {
        $this->distribution =& $distribution;
        $this->xpdo =& $distribution->xpdo;
    }

    /**
     * @param $children
     */
    abstract public function gather($children);
}

/**
 * For parsing XML distribution files
 * @package modx
 * @subpackage build
 */
class modDistributionXmlParser extends modDistributionParser {
    /**
     * @param $children
     */
    public function gather($children) {
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

    /**
     * @param modDistributionNode $child
     * @return mixed
     */
    public function loadExternal(modDistributionNode $child) {
        $file = $child->getValue();
        $file = str_replace('{build_dir}',MODX_BUILD_DIR,$file);
        if (!file_exists($file)) {
            $file = dirname($this->distribution->getDistributionFileLocation()).'/'.$this->distribution->name.'/'.$file;
        }
        if (!file_exists($file)) return false;
        $data = file_get_contents($file);
        return $data;
    }

    /**
     * @param modDistributionNode $vehicleCollection
     */
    public function addVehicleCollection(modDistributionNode $vehicleCollection) {
        $attributes = $this->parseVehicleAttributes($vehicleCollection);

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
            $this->distribution->log(xPDO::LOG_LEVEL_INFO,'Added in '.$count.' '.str_replace(array('_mysql','_sqlsrv'),'',$vehicleClass).' ');
        }
    }

    /**
     * @param modDistributionNode $vehicleData
     * @param array $attributes
     * @return xPDOObject|boolean
     */
    public function addVehicle(modDistributionNode $vehicleData,array $attributes = array()) {
        $vehicle = false;
        $vehicleAttributes = $this->parseVehicleAttributes($vehicleData);
        $attributes = array_merge($attributes,$vehicleAttributes);
        $class = $vehicleData->getAttribute('class','xPDOObjectVehicle');
        switch ($class) {
            case 'xPDOObjectVehicle':
                $vehicle = $this->addObjectVehicle($vehicleData,$attributes);
                break;
            case 'xPDOFileVehicle':
                $vehicle = $this->addFileVehicle($vehicleData,$attributes);
                break;
        }
        return $vehicle;
    }

    /**
     * @param modDistributionNode $vehicleData
     * @param array $attributes
     * @return array|boolean
     */
    public function addFileVehicle(modDistributionNode $vehicleData,array $attributes = array()) {
        $fileAttributes = $vehicleData->children();
        unset($attributes['class']);
        $attributes['vehicle_class'] = 'xPDOFileVehicle';
        $file = array();

        foreach ($fileAttributes as $fileAttribute) {
            $fileNode = new modDistributionNode($fileAttribute);
            if ($fileNode->getName() == 'xpdo_resolver') {
                $attributes['resolve'][] = $this->addResolver($fileNode);
            } else if ($fileNode->getName() == 'xpdo_validator') {
                $attributes['validate'][] = $this->addResolver($fileNode);
            } else {
                $file[$fileNode->getName()] = $fileNode->getValue();
            }
        }
        $this->distribution->package->put($file,$attributes);
        return $file;
    }

    /**
     * @param modDistributionNode $vehicleData
     * @param array $attributes
     * @return xPDOObject|boolean
     */
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
        $this->distribution->package->put($vehicle,$attributes);
        return $vehicle;
    }

    /**
     * @param modDistributionNode $node
     * @return array
     */
    public function addResolver(modDistributionNode $node) {
        $resolver = array();
        $children = $node->children();
        foreach ($children as $item) {
            $item = new modDistributionNode($item);
            $resolver[$item->getName()] = $item->getValue();
        }
        return $resolver;
    }

    /**
     * @param modDistributionNode $node
     * @return array
     */
    public function addValidator(modDistributionNode $node) {
        $validator = array();
        $children = $node->children();
        foreach ($children as $item) {
            $item = new modDistributionNode($item);
            $validator[$item->getName()] = $item->getValue();
        }
        return $validator;
    }

    /**
     * @param xPDOObject $object
     * @param modDistributionNode $data
     * @return mixed
     */
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

}

/**
 * Used to represent a node in the distribution XML to properly handle various forms of casting
 * @package modx
 * @subpackage build
 */
class modDistributionNode {
    /** @var SimpleXMLElement $node */
    public $node;
    /** @var array $attributes */
    public $attributes = array();

    public function __construct ($input = null,$flags = 0,$iterator_class='ArrayIterator') {
        $this->node = $input;
    }

    /**
     * @return string
     */
    public function getName() {
        return $this->node->getName();
    }

    /**
     * @param string $ns
     * @param boolean $isPrefix
     * @return SimpleXMLElement
     */
    public function children($ns = '',$isPrefix = false) {
        return $this->node->children($ns,$isPrefix);
    }
    /**
     * @param string $ns
     * @param null $isPrefix
     * @return SimpleXMLElement
     */
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

    /**
     * Get an attribute for the node
     *
     * @param string $key
     * @param mixed $default
     * @return nixed
     */
    public function getAttribute($key,$default = null) {
        $this->collectAttributes();
        return isset($this->attributes[$key]) ? (string)$this->attributes[$key] : $default;
    }

    /**
     * Collect and store the attributes
     * @return array
     */
    public function collectAttributes() {
        if (!empty($this->attributes)) return $this->attributes;
        $attrs = $this->node->attributes();
        /** @var SimpleXMLElement $attribute */
        foreach ($attrs as $attribute) {
            $this->attributes[$attribute->getName()] = (string)$attribute;
        }
        return $this->attributes;
    }

    /**
     * @return string
     */
    public function __toString() {
        return $this->node->__toString();
    }
}