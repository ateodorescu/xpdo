<?php
/**
 * This file is part of the xPDO package.
 *
 * Copyright (c) Jason Coward <jason@opengeek.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * This is the main xPDO class.
 *
 * @author Jason Coward <jason@opengeek.com>
 * @copyright Copyright (C) 2006-2015, Jason Coward
 * @license http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @package xpdo
 */
namespace xPDO;

use Composer\Autoload\ClassLoader;
use Psr\Container\ContainerInterface;
use xPDO\Om\xPDOCriteria;
use xPDO\Om\xPDOQuery;

if (!defined('XPDO_CORE_PATH')) {
    $xpdo_core_path= strtr(realpath(dirname(__FILE__)), '\\', '/') . '/';
    /**
     * @var string The full path to the xPDO root directory.
     */
    define('XPDO_CORE_PATH', $xpdo_core_path);
    unset($xpdo_core_path);
}
if (!defined('XPDO_CLI_MODE')) {
    /**
     * @var bool Indicates if the PHP_SAPI is cli.
     */
    define('XPDO_CLI_MODE', PHP_SAPI === 'cli');
}

/**
 * A wrapper for PDO that powers an object-relational data model.
 *
 * xPDO provides centralized data access via a simple object-oriented API, to
 * a defined data structure. It provides the de facto methods for connecting
 * to a data source, getting persistence metadata for any class extended from
 * the {@link xPDOObject} class (core or custom), loading data source managers
 * when needed to manage table structures, and retrieving instances (or rows) of
 * any object in the model.
 *
 * Through various extensions, you can also reverse and forward engineer classes
 * and metadata maps for xPDO, have classes, models, and properties maintain
 * their own containers (databases, tables, columns, etc.) or changes to them,
 * and much more.
 *
 * @package xpdo
 */
#[\AllowDynamicProperties]
class xPDO {
    /**#@+
     * Constants
     */
    const OPT_AUTO_CREATE_TABLES = 'auto_create_tables';
    const OPT_BASE_CLASSES = 'base_classes';
    const OPT_BASE_PACKAGES = 'base_packages';
    const OPT_CACHE_COMPRESS = 'cache_compress';
    const OPT_CACHE_DB = 'cache_db';
    const OPT_CACHE_DB_COLLECTIONS = 'cache_db_collections';
    const OPT_CACHE_DB_OBJECTS_BY_PK = 'cache_db_objects_by_pk';
    const OPT_CACHE_DB_EXPIRES = 'cache_db_expires';
    const OPT_CACHE_DB_HANDLER = 'cache_db_handler';
    const OPT_CACHE_DB_SIG_CLASS = 'cache_db_sig_class';
    const OPT_CACHE_DB_SIG_GRAPH = 'cache_db_sig_graph';
    const OPT_CACHE_EXPIRES = 'cache_expires';
    const OPT_CACHE_FORMAT = 'cache_format';
    const OPT_CACHE_HANDLER = 'cache_handler';
    const OPT_CACHE_KEY = 'cache_key';
    const OPT_CACHE_PATH = 'cache_path';
    const OPT_CACHE_PREFIX = 'cache_prefix';
    const OPT_CACHE_MULTIPLE_OBJECT_DELETE = 'multiple_object_delete';
    const OPT_CACHE_ATTEMPTS = 'cache_attempts';
    const OPT_CACHE_ATTEMPT_DELAY = 'cache_attempt_delay';
    const OPT_CALLBACK_ON_REMOVE = 'callback_on_remove';
    const OPT_CALLBACK_ON_SAVE = 'callback_on_save';
    const OPT_CONNECTIONS = 'connections';
    const OPT_CONN_INIT = 'connection_init';
    const OPT_CONN_MUTABLE = 'connection_mutable';
    const OPT_OVERRIDE_TABLE_TYPE = 'override_table';
    const OPT_HYDRATE_FIELDS = 'hydrate_fields';
    const OPT_HYDRATE_ADHOC_FIELDS = 'hydrate_adhoc_fields';
    const OPT_HYDRATE_RELATED_OBJECTS = 'hydrate_related_objects';
    const OPT_LOCKFILE_EXTENSION = 'lockfile_extension';
    const OPT_USE_FLOCK = 'use_flock';
    const OPT_ON_SET_STRIPSLASHES = 'on_set_stripslashes';
    const OPT_SETUP = 'setup';
    const OPT_TABLE_PREFIX = 'table_prefix';
    const OPT_VALIDATE_ON_SAVE = 'validate_on_save';
    const OPT_VALIDATOR_CLASS = 'validator_class';

    const LOG_LEVEL_FATAL = 0;
    const LOG_LEVEL_ERROR = 1;
    const LOG_LEVEL_WARN = 2;
    const LOG_LEVEL_INFO = 3;
    const LOG_LEVEL_DEBUG = 4;

    const SCHEMA_VERSION = '3.0';

    /**
     * @var array A static collection of xPDO instances.
     */
    protected static $instances = array();
    /**
     * @var object A PSR-0 autoloader singleton.
     */
    protected static $loader;

    /**
     * @var \PDO A reference to the PDO instance used by the current xPDOConnection.
     */
    public $pdo= null;
    /**
     * @var array Configuration options for the xPDO instance.
     */
    public $config= null;
    /**
     * @var Om\xPDODriver An xPDODriver instance for the xPDOConnection instances to use.
     */
    public $driver= null;
    /**
     * @var xPDOMap A map of data source meta data for all loaded classes.
     */
    public $map= null;
    /**
     * @var string A default package for specifying classes by name.
     */
    public $package= '';
    /**
     * @var array An array storing packages and package-specific information.
     */
    public $packages= array ();
    /**
     * @var Om\xPDOManager An on-demand data-source manager.
     */
    public $manager= null;
    /**
     * @var Cache\xPDOCacheManager The cache service provider registered for this instance.
     */
    public $cacheManager= null;
    /**
     * @var string A root path for file-based caching services to use.
     */
    private $cachePath= null;
    /**
     * @var ContainerInterface|array A container or array (deprecated) of supplemental service
     * classes for this xPDO instance.
     */
    public $services= null;
    /**
     * @var float Start time of the request, initialized when the constructor is
     * called.
     */
    public $startTime= 0;
    /**
     * @var int The number of direct DB queries executed during a request.
     */
    public $executedQueries= 0;
    /**
     * @var array The list of all successful queries executed during a request.
     */
    public $successfulQueries = array();
    /**
     * @var array The list of failed queries executed during a request.
     */
    public $failedQueries = array();
    /**
     * @var bool Indicates if the profiler is enabled on this instance
     */
    protected $profiler = false;
    /**
     * @var int The amount of request handling time spent with DB queries.
     */
    public $queryTime= 0;
    /**
     * @var array A map of table classes being managed by this instance.
     */
    public $classMap = array();
    /**
     * @var xPDOConnection The current xPDOConnection for this xPDO instance.
     */
    public $connection = null;
    /**
     * @var array PDO connections managed by this xPDO instance.
     */
    private $_connections = array();
    /**
     * @var integer The logging level for the xPDO instance.
     */
    protected $logLevel= self::LOG_LEVEL_FATAL;
    /**
     * @var string The default logging target for the xPDO instance.
     */
    protected $logTarget= 'ECHO';
    /**
     * @var boolean Indicates the debug state of this instance..
     */
    protected $_debug= false;
    /**
     * @var boolean A global cache flag that can be used to enable/disable all caching.
     */
    public $_cacheEnabled= false;
    /**
     * @var string Indicates the opening escape character used for a particular database engine.
     */
    public $_escapeCharOpen= '';
    /**
     * @var string Indicates the closing escape character used for a particular database engine.
     */
    public $_escapeCharClose= '';
    /**
     * @var string Represents the character used for quoting strings for a particular driver.
     */
    public $_quoteChar= "'";

    /**
     * Create, retrieve, or update specific xPDO instances.
     *
     * @param string|int|null $id An optional identifier for the instance. If not set
     * a uniqid will be generated and used as the key for the instance.
     * @param array|ContainerInterface|null $config An optional container or array of config data
     * for the instance.
     * @param bool $forceNew If true a new instance will be created even if an instance
     * with the provided $id already exists in xPDO::$instances.
     *
     * @throws xPDOException If a valid instance is not retrieved.
     * @return xPDO An instance of xPDO.
     */
    public static function getInstance($id = null, $config = null, $forceNew = false) {
        $instances =& self::$instances;
        if (is_null($id)) {
            if (!is_null($config) || $forceNew || empty($instances)) {
                $id = uniqid(__CLASS__);
            } else {
                $id = key($instances);
            }
        }
        if ($forceNew || !array_key_exists($id, $instances) || !($instances[$id] instanceof xPDO)) {
            $instances[$id] = new xPDO(null, null, null, $config);
        } elseif ($instances[$id] instanceof xPDO && is_array($config)) {
            $instances[$id]->config = array_merge($instances[$id]->config, $config);
        }
        if (!($instances[$id] instanceof xPDO)) {
            throw new xPDOException("Error getting " . __CLASS__ . " instance, id = {$id}");
        }
        return $instances[$id];
    }

    /**
     * Get the Composer autoloader being used by this library.
     *
     * @return ClassLoader The autoloader instance being used by all instances of xPDO.
     */
    public static function getLoader()
    {
        $loader =& self::$loader;
        if ($loader === null) {
            $loader = include __DIR__ . '/../bootstrap.php';
        }
        return $loader;
    }

    /**
     * The xPDO Constructor.
     *
     * This method is used to create a new xPDO object with a connection to a
     * specific database container.
     *
     * @param mixed                    $dsn           A valid DSN connection string.
     * @param string                   $username      The database username with proper permissions.
     * @param string                   $password      The password for the database user.
     * @param array|ContainerInterface $options       A dependency container or array of xPDO options.
     *                                                You should configure the xPDO options array within a container and pass a container for
     *                                                future compatibility.
     * @param array|null               $driverOptions Driver-specific PDO options.
     *
     * @throws xPDOException If an error occurs creating the instance.
     */
    public function __construct($dsn, $username= '', $password= '', $options= array(), $driverOptions= null) {
        try {
            $this->config = $this->initConfig($options);
            if ($this->services === null) {
                $this->services = new xPDOContainer();
            }
            $this->setLogLevel($this->getOption('log_level', null, xPDO::LOG_LEVEL_FATAL, true));
            $this->setLogTarget($this->getOption('log_target', null, php_sapi_name() === 'cli' ? 'ECHO' : 'HTML', true));
            if (!empty($dsn)) {
                $this->addConnection($dsn, $username, $password, $this->config, $driverOptions);
            }
            if (isset($this->config[xPDO::OPT_CONNECTIONS])) {
                $connections = $this->config[xPDO::OPT_CONNECTIONS];
                if (is_string($connections)) {
                    $connections = $this->fromJSON($connections);
                }
                if (is_array($connections)) {
                    foreach ($connections as $connection) {
                        $this->addConnection(
                            $connection['dsn'],
                            $connection['username'],
                            $connection['password'],
                            $connection['options'],
                            $connection['driverOptions']
                        );
                    }
                }
            }
            $initOptions = $this->getOption(xPDO::OPT_CONN_INIT, null, array());
            $this->config = array_merge($this->config, $this->getConnection($initOptions)->config);
            $this->getDriver();
            $this->map = new xPDOMap($this);
            $this->setPackage('Om', XPDO_CORE_PATH, $this->config[xPDO::OPT_TABLE_PREFIX]);
            if (isset($this->config[xPDO::OPT_BASE_PACKAGES]) && !empty($this->config[xPDO::OPT_BASE_PACKAGES])) {
                $basePackages= explode(',', $this->config[xPDO::OPT_BASE_PACKAGES]);
                foreach ($basePackages as $basePackage) {
                    $exploded= explode(':', $basePackage, 2);
                    if ($exploded) {
                        $path= $exploded[1];
                        $prefix= null;
                        if (strpos($path, ';')) {
                            $details= explode(';', $path);
                            if ($details && count($details) == 2) {
                                $path= $details[0];
                                $prefix = $details[1];
                            }
                        }
                        $this->addPackage($exploded[0], $path, $prefix);
                    }
                }
            }
            if (isset($this->config[xPDO::OPT_BASE_CLASSES])) {
                foreach (array_keys($this->config[xPDO::OPT_BASE_CLASSES]) as $baseClass) {
                    $this->loadClass($baseClass);
                }
            }
            if (isset($this->config[xPDO::OPT_CACHE_PATH])) {
                $this->cachePath = $this->config[xPDO::OPT_CACHE_PATH];
            }
        } catch (\Exception $e) {
            throw new xPDOException("Could not instantiate xPDO: " . $e->getMessage());
        }
    }

    /**
     * Initialize an xPDO config array.
     *
     * @param ContainerInterface|array $data The config input source. Currently accepts a dependency
     * container that has a 'config' entry containing the xPDO configuration array, or an array
     * containing the configuration directly (deprecated).
     *
     * @return array An array of xPDO config data.
     */
    protected function initConfig($data) {
        if ($data instanceof ContainerInterface) {
            $this->services = $data;
            if ($this->services->has('config')) {
                $data = $this->services->get('config');
            }
        }
        if (!is_array($data)) {
            $data = array(xPDO::OPT_TABLE_PREFIX => '');
        }

        return $data;
    }

    /**
     * Add an xPDOConnection instance to the xPDO connection pool.
     *
     * @param string $dsn A PDO DSN representing the connection details.
     * @param string $username The username credentials for the connection.
     * @param string $password The password credentials for the connection.
     * @param array $options An array of options for the connection.
     * @param null $driverOptions An array of PDO driver options for the connection.
     * @return boolean True if a valid connection was added.
     */
    public function addConnection($dsn, $username= '', $password= '', array $options= array(), $driverOptions= null) {
        $added = false;
        $connection= new xPDOConnection($this, $dsn, $username, $password, $options, $driverOptions);
        if ($connection instanceof xPDOConnection) {
            $this->_connections[]= $connection;
            $added= true;
        }
        return $added;
    }

    /**
     * Get an xPDOConnection from the xPDO connection pool.
     *
     * @param array $options An array of options for getting the connection.
     * @return xPDOConnection|null An xPDOConnection instance or null if no connection could be retrieved.
     */
    public function getConnection(array $options = array()) {
        $conn =& $this->connection;
        $mutable = $this->getOption(xPDO::OPT_CONN_MUTABLE, $options, null);
        if (!($conn instanceof xPDOConnection) || ($mutable !== null && (($mutable == true && !$conn->isMutable()) || ($mutable == false && $conn->isMutable())))) {
            if (!empty($this->_connections)) {
                shuffle($this->_connections);
                $conn = reset($this->_connections);
                while ($conn) {
                    if ($mutable !== null && (($mutable == true && !$conn->isMutable()) || ($mutable == false && $conn->isMutable()))) {
                        $conn = next($this->_connections);
                        continue;
                    }
                    $this->connection =& $conn;
                    break;
                }
            } else {
                $this->log(xPDO::LOG_LEVEL_ERROR, "Could not get a valid xPDOConnection", '', __METHOD__, __FILE__, __LINE__);
            }
        }
        return $this->connection;
    }

    /**
     * Get or create a PDO connection to a database specified in the configuration.
     *
     * @param array $driverOptions An optional array of driver options to use
     * when creating the connection.
     * @param array $options An array of xPDO options for the connection.
     * @return boolean Returns true if the PDO connection was created successfully.
     */
    public function connect($driverOptions= array (), array $options= array()) {
        $connected = false;
        $this->getConnection($options);
        if ($this->connection instanceof xPDOConnection) {
            $connected = $this->connection->connect($driverOptions);
            if ($connected) {
                $this->pdo =& $this->connection->pdo;
            }
        }
        return $connected;
    }

    /**
     * Sets a specific model package to use when looking up classes.
     *
     * This package is of the form package.subpackage.subsubpackage and will be
     * added to the beginning of every xPDOObject class that is referenced in
     * xPDO methods such as {@link xPDO::loadClass()}, {@link xPDO::getObject()},
     * {@link xPDO::getCollection()}, {@link xPDOObject::getOne()}, {@link
     * xPDOObject::addOne()}, etc.
     *
     * @param string $pkg A package name to use when looking up classes in xPDO.
     * @param string $path The root path for looking up classes in this package.
     * @param string|null $prefix Provide a string to define a package-specific table_prefix.
     * @param string|null $namespacePrefix An optional namespace prefix for working with PSR-4.
     * @return bool
     */
    public function setPackage($pkg= '', $path= '', $prefix= null, $namespacePrefix= null) {
        if (empty($path) && isset($this->packages[$pkg])) {
            $path= $this->packages[$pkg]['path'];
            $prefix= !is_string($prefix) && array_key_exists('prefix', $this->packages[$pkg]) ? $this->packages[$pkg]['prefix'] : $prefix;
        }
        $set= $this->addPackage($pkg, $path, $prefix, $namespacePrefix);
        $this->package= $set == true ? $pkg : $this->package;
        if ($set && is_string($prefix)) $this->config[xPDO::OPT_TABLE_PREFIX]= $prefix;
        return $set;
    }

    /**
     * Adds a model package and base class path for including classes and/or maps from.
     *
     * @param string $pkg A package name to use when looking up classes/maps in xPDO.
     * @param string $path The root path for looking up classes in this package.
     * @param string|null $prefix Provide a string to define a package-specific table_prefix.
     * @param string|null $namespacePrefix An optional namespace prefix for working with PSR-4.
     * @return bool
     */
    public function addPackage($pkg= '', $path= '', $prefix= null, $namespacePrefix= null) {
        $added= false;
        if (is_string($pkg) && !empty($pkg)) {
            if (!is_string($path) || empty($path)) {
                $this->log(xPDO::LOG_LEVEL_ERROR, "Invalid path specified for package: {$pkg}; using default xpdo model path: " . XPDO_CORE_PATH . 'Om/');
                $path= XPDO_CORE_PATH . 'Om/';
            }
            if (!is_dir($path)) {
                $this->log(xPDO::LOG_LEVEL_ERROR, "Path specified for package {$pkg} is not a valid or accessible directory: {$path}");
            } else {
                $prefix= !is_string($prefix) ? $this->config[xPDO::OPT_TABLE_PREFIX] : $prefix;
                if (!array_key_exists($pkg, $this->packages) || $this->packages[$pkg]['path'] !== $path || $this->packages[$pkg]['prefix'] !== $prefix) {
                    $this->packages[$pkg]= array('path' => $path, 'prefix' => $prefix);
                    $this->setPackageMeta($pkg, $path, $namespacePrefix);
                }
                $added= true;
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, 'addPackage called with an invalid package name.');
        }
        return $added;
    }

    /**
     * Adds metadata information about a package and loads the xPDO::$classMap.
     *
     * @param string $pkg A package name to use when looking up classes/maps in xPDO.
     * @param string $path The root path for looking up classes in this package.
     * @param string|null $namespacePrefix An optional namespace prefix for working with PSR-4.
     * @return bool
     */
    public function setPackageMeta($pkg, $path = '', $namespacePrefix= null) {
        $set = false;
        if (is_string($pkg) && !empty($pkg)) {
            $pkgPath = str_replace(array('.', '\\'), array('/', '/'), $pkg);
            $namespacePrefixPath = !empty($namespacePrefix) ? str_replace('\\', '/', $namespacePrefix) : '';
            if (!empty($namespacePrefixPath) && strpos($pkgPath, $namespacePrefixPath) === 0) {
                $pkgPath = substr($pkgPath, strlen($namespacePrefixPath));
            }
            $mapFile = $path . $pkgPath . '/metadata.' . $this->config['dbtype'] . '.php';
            if (file_exists($mapFile)) {
                $xpdo_meta_map = array();
                include $mapFile;
                if (!empty($xpdo_meta_map)) {
                    if (isset($xpdo_meta_map['version'])) {
                        if (version_compare($xpdo_meta_map['version'], '3.0', '>=')) {
                            $namespacePrefix = isset($xpdo_meta_map['namespacePrefix']) && !empty($xpdo_meta_map['namespacePrefix'])
                                ? $xpdo_meta_map['namespacePrefix'] . '\\'
                                : '';
                            self::getLoader()->addPsr4($namespacePrefix, $path);
                            $xpdo_meta_map = $xpdo_meta_map['class_map'];
                        }
                    }
                    foreach ($xpdo_meta_map as $className => $extends) {
                        if (!isset($this->classMap[$className])) {
                            $this->classMap[$className] = array();
                        }
                        $this->classMap[$className] = array_unique(array_merge($this->classMap[$className],$extends));
                    }
                    $set = true;
                }
            } else {
                $this->log(xPDO::LOG_LEVEL_WARN, "Could not load package metadata for package {$pkg}. Upgrade your model.");
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, 'setPackageMeta called with an invalid package name.');
        }
        return $set;
    }

    /**
     * Gets a list of derivative classes for the specified className.
     *
     * The specified className must be xPDOObject or a derivative class.
     *
     * @param string $className The name of the class to retrieve derivatives for.
     * @return array An array of derivative classes or an empty array.
     */
    public function getDescendants($className) {
        $descendants = array();
        if (isset($this->classMap[$className])) {
            $descendants = $this->classMap[$className];
            if ($descendants) {
                foreach ($descendants as $descendant) {
                    $descendants = array_merge($descendants, $this->getDescendants($descendant));
                }
            }
        }
        return $descendants;
    }

    public function getDriverClass($class) {
        if (strpos($class, '\\') !== false) {
            $paths = explode('\\', $class);
            $base = array_pop($paths);
            array_push($paths, $this->getOption('dbtype'), $base);
        } else {
            $paths = array($this->getOption('dbtype'), $class);
        }
        $driverClass = implode('\\', $paths);
        return class_exists($driverClass) ? $driverClass : false;
    }

    public function getPlatformClass($domainClass) {
        if (strpos($domainClass, '\\') !== false) {
            $exploded = explode('\\', ltrim($domainClass, '\\'));
            $slice = array_slice($exploded, -1);
            $class = $slice[0];
            $namespace = implode('\\', array_slice($exploded, 0, -1));
            if (!empty($namespace)) $namespace .= '\\';
            return "\\{$namespace}{$this->getOption('dbtype')}\\{$class}";
        } else {
            return "\\{$domainClass}_{$this->getOption('dbtype')}";
        }
    }

    /**
     * Load a class by fully qualified name.
     *
     * The $fqn should in the format:
     *
     *    dir_a.dir_b.dir_c.classname
     *
     * which will translate to:
     *
     *    XPDO_CORE_PATH/Om/dir_a/dir_b/dir_c/dbtype/classname.class.php
     *
     * As of xPDO 3.0, the use of loadClass is only necessary to support BC
     * with older xPDO models. Auto-loading in models built with xPDO 3.0 or
     * later makes the use of this method obsolete.
     *
     * @param string $fqn The fully-qualified name of the class to load.
     * @param string $path An optional path to start the search from.
     * @param bool $ignorePkg True if currently loaded packages should be ignored.
     * @param bool $transient True if the class is not a persistent table class.
     *
     * @return string|boolean The actual classname if successful, or false if
     * not.
     * @deprecated since 3.0
     */
    public function loadClass($fqn, $path= '', $ignorePkg= false, $transient= false) {
        if (empty($fqn)) {
            $this->log(xPDO::LOG_LEVEL_ERROR, "No class specified for loadClass");
            return false;
        }
        $pos= strrpos($fqn, '.');
        if ($pos === false && empty($path) && !$ignorePkg && !$transient) {
            $driverClass = $this->getDriverClass($fqn);
            if ($driverClass !== false) {
                return $fqn;
            }
        } elseif (strpos($fqn, '\\') !== false && class_exists($fqn)) {
            return $fqn;
        }
        if (!$transient) {
            $typePos= strrpos($fqn, '_' . $this->config['dbtype']);
            if ($typePos !== false) {
                $fqn= substr($fqn, 0, $typePos);
            }
        }
        if ($pos === false) {
            $class= $fqn;
            if ($transient) {
                $fqn= strtolower($class);
            } else {
                $fqn= $this->config['dbtype'] . '.' . strtolower($class);
            }
        } else {
            $class= substr($fqn, $pos +1);
            if ($transient) {
                $fqn= substr($fqn, 0, $pos) . '.' . strtolower($class);
            } else {
                $fqn= substr($fqn, 0, $pos) . '.' . $this->config['dbtype'] . '.' . strtolower($class);
            }
        }
        // check if class exists
        if (!$transient && isset ($this->map[$class])) return $class;
        $included= class_exists($class, false);
        if ($included) {
            if ($transient || (!$transient && isset ($this->map[$class]))) {
                return $class;
            }
        }
        $classname= $class;
        if (!empty($path) || $ignorePkg) {
            $class= $this->_loadClass($class, $fqn, $included, $path, $transient);
        } elseif (isset ($this->packages[$this->package])) {
            $pqn= $this->package . '.' . $fqn;
            if (!$pkgClass= $this->_loadClass($class, $pqn, $included, $this->packages[$this->package]['path'], $transient)) {
                foreach ($this->packages as $pkg => $pkgDef) {
                    if ($pkg === $this->package) continue;
                    $pqn= $pkg . '.' . $fqn;
                    if ($pkgClass= $this->_loadClass($class, $pqn, $included, $pkgDef['path'], $transient)) {
                        break;
                    }
                }
            }
            $class= $pkgClass;
        } else {
            $class= false;
        }
        if ($class === false) {
            $this->log(xPDO::LOG_LEVEL_ERROR, "Could not load class: {$classname} from {$fqn}");
        }
        return $class;
    }

    protected function _loadClass($class, $fqn, $included= false, $path= '', $transient= false) {
        if (empty($path)) $path= XPDO_CORE_PATH;
        if (!$included) {
            /* turn to filesystem path and enforce all lower-case paths and filenames */
            $fqcn= str_replace('.', '/', $fqn) . '.class.php';
            /* include class */
            if (!file_exists($path . $fqcn)) return false;
            if (!$rt= include_once ($path . $fqcn)) {
                $this->log(xPDO::LOG_LEVEL_WARN, "Could not load class: {$class} from {$path}{$fqcn}");
                $class= false;
            }
        }
        if ($class && !$transient && !isset ($this->map[$class])) {
            $mapfile= strtr($fqn, '.', '/') . '.map.inc.php';
            if (file_exists($path . $mapfile)) {
                $xpdo_meta_map= array();
                $rt= include ($path . $mapfile);
                if (!$rt || !isset($xpdo_meta_map[$class])) {
                    $this->log(xPDO::LOG_LEVEL_WARN, "Could not load metadata map {$mapfile} for class {$class} from {$fqn}");
                } else {
                    if (!array_key_exists('fieldAliases', $xpdo_meta_map[$class])) {
                        $xpdo_meta_map[$class]['fieldAliases'] = array();
                    }
                    $this->map[$class] = $xpdo_meta_map[$class];
                }
            }
        }
        return $class;
    }

    /**
     * Get an xPDO configuration option value by key.
     *
     * @param string $key The option key.
     * @param array|null $options A set of options to override those from xPDO.
     * @param mixed|null $default An optional default value to return if no value is found.
     * @param bool $skipEmpty True if empty string values should be ignored.
     * @return mixed The configuration option value.
     */
    public function getOption($key, $options = null, $default = null, $skipEmpty = false) {
        $option = null;
        if (is_string($key) && !empty($key)) {
            $found = false;
            if (isset($options[$key])) {
                $found = true;
                $option = $options[$key];
            }

            if ((!$found || ($skipEmpty && $option === '')) && isset($this->config[$key])) {
                $found = true;
                $option = $this->config[$key];
            }

            if (!$found || ($skipEmpty && $option === ''))
                $option = $default;
        }
        else if (is_array($key)) {
            if (!is_array($option)) {
                $default = $option;
                $option = array();
            }
            foreach($key as $k) {
                $option[$k] = $this->getOption($k, $options, $default);
            }
        }
        else
            $option = $default;

        return $option;
    }

    /**
     * Sets an xPDO configuration option value.
     *
     * @param string $key The option key.
     * @param mixed $value A value to set for the given option key.
     */
    public function setOption($key, $value) {
        $this->config[$key]= $value;
    }

    /**
     * Call a static method from a valid package class with arguments.
     *
     * Will always search for database-specific class files first.
     *
     * @param string $class The name of a class to to get the static method from.
     * @param string $method The name of the method you want to call.
     * @param array $args An array of arguments for the method.
     * @param boolean $transient Indicates if the class has dbtype derivatives. Set to true if you
     * want to use on classes not derived from xPDOObject.
     * @return mixed|null The callback method's return value or null if no valid method is found.
     */
    public function call($class, $method, array $args = array(), $transient = false) {
        $return = null;
        $callback = '';
        if ($transient) {
            $className = $this->loadClass($class, '', false, true);
            if ($className) {
                $callback = array($className, $method);
            }
        } else {
            $className = $this->loadClass($class);
            if ($className) {
                $className = $this->getPlatformClass($className);
                $callback = array($className, $method);
            }
        }
        if (!empty($callback) && is_callable($callback)) {
            try {
                $return = $className::$method(...$args);
            } catch (\Exception $e) {
                $this->log(xPDO::LOG_LEVEL_ERROR, "An exception occurred calling {$className}::{$method}() - " . $e->getMessage());
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, "{$class}::{$method}() is not a valid static method.");
        }
        return $return;
    }

    /**
     * Creates a new instance of a specified class.
     *
     * All new objects created with this method are transient until {@link
     * xPDOObject::save()} is called the first time and is reflected by the
     * {@link Om\xPDOObject::$_new} property.
     *
     * @template T of Om\xPDOObject
     * @param class-string<T> $className Name of the class to get a new instance of.
     * @param array $fields An associated array of field names/values to
     * populate the object with.
     * @return T|null A new instance of the specified class, or null if a
     * new object could not be instantiated.
     */
    public function newObject($className, $fields= array ()) {
        $instance= null;
        if ($className = $this->loadClass($className)) {
            $className = self::getPlatformClass($className);
            /** @var Om\xPDOObject $instance */
            if ($instance = new $className($this)) {
                if (is_array($fields) && !empty($fields)) {
                    $instance->fromArray($fields);
                }
            }
        }
        return $instance;
    }

    /**
     * Retrieves a single object instance by the specified criteria.
     *
     * The criteria can be a primary key value, and array of primary key values
     * (for multiple primary key objects) or an {@link xPDOCriteria} object. If
     * no $criteria parameter is specified, no class is found, or an object
     * cannot be located by the supplied criteria, null is returned.
     *
     * @uses xPDOObject::load()
     * @template T of Om\xPDOObject
     * @param class-string<T> $className Name of the class to get an instance of.
     * @param mixed $criteria Primary key of the record or a xPDOCriteria object.
     * @param mixed $cacheFlag If an integer value is provided, this specifies
     * the time to live in the object cache; if cacheFlag === false, caching is
     * ignored for the object and if cacheFlag === true, the object will live in
     * cache indefinitely.
     * @return T|null An instance of the class, or null if it could not be
     * instantiated.
    */
    public function getObject($className, $criteria= null, $cacheFlag= true) {
        $instance= null;
        $this->sanitizePKCriteria($className, $criteria);
        if ($criteria !== null) {
            $instance = $this->call($className, 'load', array(& $this, $className, $criteria, $cacheFlag));
        }
        return $instance;
    }

    /**
     * Retrieves a collection of xPDOObjects by the specified xPDOCriteria.
     *
     * @uses xPDOObject::loadCollection()
     * @template T of Om\xPDOObject
     * @param class-string<T> $className Name of the class to search for instances of.
     * @param object|array|string $criteria An xPDOCriteria object or an array
     * search expression.
     * @param mixed $cacheFlag If an integer value is provided, this specifies
     * the time to live in the result set cache; if cacheFlag === false, caching
     * is ignored for the collection and if cacheFlag === true, the objects will
     * live in cache until flushed by another process.
     * @return array<int, T> An array of class instances retrieved.
    */
    public function getCollection($className, $criteria= null, $cacheFlag= true) {
        return $this->call($className, 'loadCollection', array(& $this, $className, $criteria, $cacheFlag));
    }

    /**
     * Retrieves an iterable representation of a collection of xPDOObjects.
     *
     * @param string $className Name of the class to search for instances of.
     * @param mixed $criteria An xPDOCriteria object or representation.
     * @param bool $cacheFlag If an integer value is provided, this specifies
     * the time to live in the result set cache; if cacheFlag === false, caching
     * is ignored for the collection and if cacheFlag === true, the objects will
     * live in cache until flushed by another process.
     * @return xPDOIterator An iterable representation of a collection.
     */
    public function getIterator($className, $criteria= null, $cacheFlag= true) {
        return new xPDOIterator($this, array('class' => $className, 'criteria' => $criteria, 'cacheFlag' => $cacheFlag));
    }

    /**
     * Update field values across a collection of xPDOObjects.
     *
     * @param string $className Name of the class to update fields of.
     * @param array $set An associative array of field/value pairs representing the updates to make.
     * @param mixed $criteria An xPDOCriteria object or representation.
     * @return bool|int The number of instances affected by the update or false on failure.
     */
    public function updateCollection($className, array $set, $criteria= null) {
        $affected = false;
        if ($this->getConnection(array(xPDO::OPT_CONN_MUTABLE => true))) {
            $query = $this->newQuery($className);
            if ($query && !empty($set)) {
                $query->command('UPDATE');
                $query->set($set);
                if (!empty($criteria)) $query->where($criteria);
                if ($query->prepare()) {
                    $affected = $this->exec($query->toSQL());
                    if ($affected === false) {
                        $this->log(xPDO::LOG_LEVEL_ERROR, "Error updating {$className} instances using query " . $query->toSQL(), '', __METHOD__, __FILE__, __LINE__);
                    } else {
                        if ($this->getOption(xPDO::OPT_CACHE_DB)) {
                            $relatedClasses = array($query->getTableClass());
                            $related = array_merge($this->getAggregates($className), $this->getComposites($className));
                            foreach ($related as $relatedAlias => $relatedMeta) {
                                $relatedClasses[] = $relatedMeta['class'];
                            }
                            $relatedClasses = array_unique($relatedClasses);
                            foreach ($relatedClasses as $relatedClass) {
                                $this->cacheManager->delete($relatedClass, array(
                                    xPDO::OPT_CACHE_KEY => $this->getOption('cache_db_key', null, 'db'),
                                    xPDO::OPT_CACHE_HANDLER => $this->getOption(xPDO::OPT_CACHE_DB_HANDLER, null, $this->getOption(xPDO::OPT_CACHE_HANDLER, null, 'xPDO\\Cache\\xPDOFileCache')),
                                    xPDO::OPT_CACHE_FORMAT => (integer) $this->getOption('cache_db_format', null, $this->getOption(xPDO::OPT_CACHE_FORMAT, null, Cache\xPDOCacheManager::CACHE_PHP)),
                                    xPDO::OPT_CACHE_PREFIX => $this->getOption('cache_db_prefix', null, Cache\xPDOCacheManager::CACHE_DIR),
                                    xPDO::OPT_CACHE_MULTIPLE_OBJECT_DELETE => true
                                ));
                            }
                        }
                        $callback = $this->getOption(xPDO::OPT_CALLBACK_ON_SAVE);
                        if ($callback && is_callable($callback)) {
                            call_user_func($callback, array('className' => $className, 'criteria' => $query, 'object' => null));
                        }
                    }
                }
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, "Could not get connection for writing data", '', __METHOD__, __FILE__, __LINE__);
        }
        return $affected;
    }

    /**
     * Remove an instance of the specified className by a supplied criteria.
     *
     * @param string $className The name of the class to remove an instance of.
     * @param mixed $criteria Valid xPDO criteria for selecting an instance.
     * @return boolean True if the instance is successfully removed.
     */
    public function removeObject($className, $criteria) {
        $removed= false;
        if ($this->getConnection(array(xPDO::OPT_CONN_MUTABLE => true))) {
            if ($this->getCount($className, $criteria) === 1) {
                if ($query= $this->newQuery($className)) {
                    $query->command('DELETE');
                    $query->where($criteria);
                    if ($query->prepare()) {
                        if ($this->exec($query->toSQL()) !== 1) {
                            $this->log(xPDO::LOG_LEVEL_ERROR, "xPDO->removeObject - Error deleting {$className} instance using query " . $query->toSQL());
                        } else {
                            $removed= true;
                            if ($this->getOption(xPDO::OPT_CACHE_DB)) {
                                $this->cacheManager->delete(Cache\xPDOCacheManager::CACHE_DIR . $query->getAlias(), array(xPDO::OPT_CACHE_MULTIPLE_OBJECT_DELETE => true));
                            }
                            $callback = $this->getOption(xPDO::OPT_CALLBACK_ON_REMOVE);
                            if ($callback && is_callable($callback)) {
                                call_user_func($callback, array('className' => $className, 'criteria' => $query));
                            }
                        }
                    }
                }
            } else {
                $this->log(xPDO::LOG_LEVEL_WARN, "xPDO->removeObject - {$className} instance to remove not found!");
                if ($this->getDebug() === true) $this->log(xPDO::LOG_LEVEL_DEBUG, "xPDO->removeObject - {$className} instance to remove not found using criteria " . print_r($criteria, true));
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, "Could not get connection for writing data", '', __METHOD__, __FILE__, __LINE__);
        }
        return $removed;
    }

    /**
     * Remove a collection of instances by the supplied className and criteria.
     *
     * @param string $className The name of the class to remove a collection of.
     * @param mixed $criteria Valid xPDO criteria for selecting a collection.
     * @return boolean|integer False if the remove encounters an error, otherwise an integer value
     * representing the number of rows that were removed.
     */
    public function removeCollection($className, $criteria) {
        $removed= false;
        if ($this->getConnection(array(xPDO::OPT_CONN_MUTABLE => true))) {
            if ($query= $this->newQuery($className)) {
                $query->command('DELETE');
                $query->where($criteria);
                if ($query->prepare()) {
                    $removed= $this->exec($query->toSQL());
                    if ($removed === false) {
                        $this->log(xPDO::LOG_LEVEL_ERROR, "xPDO->removeCollection - Error deleting {$className} instances using query " . $query->toSQL());
                    } else {
                        if ($this->getOption(xPDO::OPT_CACHE_DB)) {
                            $this->cacheManager->delete(Cache\xPDOCacheManager::CACHE_DIR . $query->getAlias(), array(xPDO::OPT_CACHE_MULTIPLE_OBJECT_DELETE => true));
                        }
                        $callback = $this->getOption(xPDO::OPT_CALLBACK_ON_REMOVE);
                        if ($callback && is_callable($callback)) {
                            call_user_func($callback, array('className' => $className, 'criteria' => $query));
                        }
                    }
                } else {
                    $this->log(xPDO::LOG_LEVEL_ERROR, "xPDO->removeCollection - Error preparing statement to delete {$className} instances using query: {$query->toSQL()}");
                }
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, "Could not get connection for writing data", '', __METHOD__, __FILE__, __LINE__);
        }
        return $removed;
    }

    /**
     * Retrieves a count of xPDOObjects by the specified xPDOCriteria.
     *
     * @param string $className Class of xPDOObject to count instances of.
     * @param mixed $criteria Any valid xPDOCriteria object or expression.
     * @return integer The number of instances found by the criteria.
     */
    public function getCount($className, $criteria = null) {
        $count = 0;
        if ($query = $this->newQuery($className, $criteria)) {
            $stmt = null;
            $expr = '*';
            if ($pk = $this->getPK($className)) {
                if (!is_array($pk)) {
                    $pk = array($pk);
                }
                $expr = $this->getSelectColumns($className, $query->getAlias(), '', $pk);
            }
            if (isset($query->query['columns'])) {
                $query->query['columns'] = array();
            }
            if (!empty($query->query['groupby']) || !empty($query->query['having'])) {
                $query->select($expr);
                if ($query->prepare()) {
                    $countQuery = new xPDOCriteria($this, "SELECT COUNT(*) FROM ({$query->toSQL(false)}) cq", $query->bindings, $query->cacheFlag);
                    $stmt = $countQuery->prepare();
                }
            } else {
                $query->select(array("COUNT(DISTINCT {$expr})"));
                $stmt = $query->prepare();
            }
            if ($stmt && $stmt->execute()) {
                $count = intval($stmt->fetchColumn());
            }
        }
        return $count;
    }

    /**
     * Retrieves an xPDOObject instance with specified related objects.
     *
     * @uses xPDO::getCollectionGraph()
     * @template T of Om\xPDOObject
     * @param class-string<T> $className The name of the class to return an instance of.
     * @param string|array $graph A related object graph in array or JSON
     * format, e.g. array('relationAlias'=>array('subRelationAlias'=>array()))
     * or {"relationAlias":{"subRelationAlias":{}}}.  Note that the empty arrays
     * are necessary in order for the relation to be recognized.
     * @param mixed $criteria A valid xPDOCriteria instance or expression.
     * @param boolean|integer $cacheFlag Indicates if the result set should be
     * cached, and optionally for how many seconds.
     * @return T|null The object instance with related objects from the graph
     * hydrated, or null if no instance can be located by the criteria.
     */
    public function getObjectGraph($className, $graph, $criteria= null, $cacheFlag= true) {
        $object= null;
        $this->sanitizePKCriteria($className, $criteria);
        if ($collection= $this->getCollectionGraph($className, $graph, $criteria, $cacheFlag)) {
            if (!count($collection) === 1) {
                $this->log(xPDO::LOG_LEVEL_WARN, 'getObjectGraph criteria returned more than one instance.');
            }
            $object= reset($collection);
        }
        return $object;
    }

    /**
     * Retrieves a collection of xPDOObject instances with related objects.
     *
     * @uses xPDOQuery::bindGraph()
     * @template T of Om\xPDOObject
     * @param class-string<T> $className The name of the class to return a collection of.
     * @param string|array $graph A related object graph in array or JSON
     * format, e.g. array('relationAlias'=>array('subRelationAlias'=>array()))
     * or {"relationAlias":{"subRelationAlias":{}}}.  Note that the empty arrays
     * are necessary in order for the relation to be recognized.
     * @param mixed $criteria A valid xPDOCriteria instance or condition string.
     * @param boolean $cacheFlag Indicates if the result set should be cached.
     * @return array<int, T> An array of instances matching the criteria with related
     * objects from the graph hydrated.  An empty array is returned when no
     * matches are found.
     */
    public function getCollectionGraph($className, $graph, $criteria= null, $cacheFlag= true) {
        return $this->call($className, 'loadCollectionGraph', array(& $this, $className, $graph, $criteria, $cacheFlag));
    }

    /**
     * Execute a PDOStatement and get a single column value from the first row of the result set.
     *
     * @param \PDOStatement $stmt A prepared PDOStatement object ready to be executed.
     * @param null|integer $column 0-indexed number of the column you wish to retrieve from the row. If
     * null or no value is supplied, it fetches the first column.
     * @return mixed The value of the specified column from the first row of the result set, or null.
     */
    public function getValue($stmt, $column= null) {
        $value = null;
        if (is_object($stmt) && $stmt instanceof \PDOStatement) {
            $tstart = microtime(true);
            if ($stmt->execute()) {
                $this->logSuccessfulQuery($stmt->queryString, null,microtime(true) - $tstart);
                $value= $stmt->fetchColumn((int)$column);
                $stmt->closeCursor();
            } else {
                $this->logFailedQuery($stmt->queryString, null,microtime(true) - $tstart, $stmt->errorCode(), $stmt->errorInfo());
                $this->log(xPDO::LOG_LEVEL_ERROR, "Error " . $stmt->errorCode() . " executing statement: \n" . print_r($stmt->errorInfo(), true), '', __METHOD__, __FILE__, __LINE__);
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, "No valid PDOStatement provided to getValue", '', __METHOD__, __FILE__, __LINE__);
        }
        return $value;
    }

    /**
     * Convert any valid criteria into an xPDOQuery instance.
     *
     * @todo Get criteria pre-defined in an {@link xPDOObject} class metadata
     * definition by name.
     *
     * @todo Define callback functions as an alternative to retreiving criteria
     * sql and/or bindings from the metadata.
     *
     * @param string $className The class to get predefined criteria for.
     * @param string $type The type of criteria to get (you can define any
     * type you want, but 'object' and 'collection' are the typical criteria
     * for retrieving single and multiple instances of an object).
     * @param boolean|integer $cacheFlag Indicates if the result is cached and
     * optionally for how many seconds.
     * @return Om\xPDOCriteria A criteria object or null if not found.
     */
    public function getCriteria($className, $type= null, $cacheFlag= true) {
        return $this->newQuery($className, $type, $cacheFlag);
    }

    /**
     * Validate and return the type of a specified criteria variable.
     *
     * @param mixed $criteria An xPDOCriteria instance or any valid criteria variable.
     * @return string|null The type of valid criteria passed, or null if the criteria is not valid.
     */
    public function getCriteriaType($criteria) {
        $type = gettype($criteria);
        if ($type === 'object') {
            $type = get_class($criteria);
            if (!$criteria instanceof Om\xPDOCriteria) {
                $this->log(xPDO::LOG_LEVEL_WARN, "Invalid criteria object of class {$type} encountered.", '', __METHOD__, __FILE__, __LINE__);
                $type = null;
            } elseif ($criteria instanceof Om\xPDOQuery) {
                $type = 'xPDOQuery';
            } else {
                $type = 'xPDOCriteria';
            }
        }
        return $type;
    }

    /**
     * Add criteria when requesting a derivative class row automatically.
     *
     * This applies class_key filtering for single-table inheritance queries and may
     * provide a convenient location for similar features in the future.
     *
     * @param string $className A valid xPDOObject derivative table class.
     * @param Om\xPDOQuery $criteria A valid xPDOQuery instance.
     * @return Om\xPDOQuery The xPDOQuery instance with derivative criteria added.
     */
    public function addDerivativeCriteria($className, $criteria) {
        if ($criteria instanceof Om\xPDOQuery && ($className = $this->loadClass($className)) && !isset($this->map[$className]['table'])) {
            if (isset($this->map[$className]['fields']['class_key']) && !empty($this->map[$className]['fields']['class_key'])) {
                $criteria->where(array('class_key' => $this->map[$className]['fields']['class_key']));
                if ($this->getDebug() === true) {
                    $this->log(xPDO::LOG_LEVEL_DEBUG, "#1: Automatically adding class_key criteria for derivative query of class {$className}");
                }
            } else {
                foreach ($this->getAncestry($className, false) as $ancestor) {
                    if (isset($this->map[$ancestor]['table']) && isset($this->map[$ancestor]['fields']['class_key'])) {
                        $criteria->where(array('class_key' => $className));
                        if ($this->getDebug() === true) {
                            $this->log(xPDO::LOG_LEVEL_DEBUG, "#2: Automatically adding class_key criteria for derivative query of class {$className} from base table class {$ancestor}");
                        }
                        break;
                    }
                }
            }
        }
        return $criteria;
    }

    /**
     * Gets the package name from a specified class name.
     *
     * @param string $className The name of the class to lookup the package for.
     * @return string The package the class belongs to.
     */
    public function getPackage($className) {
        $package= '';
        if ($className= $this->loadClass($className)) {
            if (isset($this->map[$className]['package'])) {
                $package= $this->map[$className]['package'];
            }
            if (!$package && $ancestry= $this->getAncestry($className, false)) {
                foreach ($ancestry as $ancestor) {
                    if (isset ($this->map[$ancestor]['package']) && ($package= $this->map[$ancestor]['package'])) {
                        break;
                    }
                }
            }
        }
        return $package;
    }

    /**
     * Get an alias for the specified model class to be used in SQL queries.
     *
     * @param string $className The fully-qualified class name to get an alias for.
     *
     * @return string The alias for the specified class.
     */
    public function getAlias($className) {
        $alias = $className;
        if (strpos($alias, '\\') !== false) {
            $namespace = explode('\\', $alias);
            $alias = array_pop($namespace);
        }

        return $alias;
    }

    /**
     * Load and return a named service class instance.
     *
     * @deprecated Use the service/DI container to access services. Will be removed in 3.1.
     *
     * @param string $name The variable name of the instance.
     * @param string $class The service class name.
     * @param string $path An optional root path to search for the class.
     * @param array $params An array of optional params to pass to the service
     * class constructor.
     * @return object|null A reference to the service class instance or null if
     * it could not be loaded.
     */
    public function getService($name, $class= '', $path= '', $params= array ()) {
        $service= null;
        if (!$this->services->has($name) || !is_object($this->services->get($name))) {
            if (empty ($class) && isset ($this->config[$name . '.class'])) {
                $class= $this->config[$name . '.class'];
            } elseif (empty ($class)) {
                $class= $name;
            }
            $className= $this->loadClass($class, $path, false, true);
            if (!empty($className)) {
                $service = new $className($this, $params);
                if ($service) {
                    $this->services->add($name, $service);
                    $this->$name= $this->services->get($name);
                }
            }
        }
        if ($this->services->has($name)) {
            $service = $this->services->get($name);
        } else {
            if ($this->getDebug() === true) {
                $this->log(xPDO::LOG_LEVEL_DEBUG, "Problem getting service {$name}, instance of class {$class}, from path {$path}, with params " . print_r($params, true));
            } else {
                $this->log(xPDO::LOG_LEVEL_ERROR, "Problem getting service {$name}, instance of class {$class}, from path {$path}");
            }
        }
        return $service;
    }

    /**
     * Gets the actual run-time table name from a specified class name.
     *
     * @param string $className The name of the class to lookup a table name
     * for.
     * @param boolean $includeDb Qualify the table name with the database name.
     * @return string The table name for the class, or null if unsuccessful.
     */
    public function getTableName($className, $includeDb= false) {
        $table= null;
        if ($className= $this->loadClass($className)) {
            if (isset ($this->map[$className]['table'])) {
                $table= $this->map[$className]['table'];
                if (isset($this->map[$className]['package']) && isset($this->packages[$this->map[$className]['package']]['prefix'])) {
                    $table= $this->packages[$this->map[$className]['package']]['prefix'] . $table;
                } else {
                    $table= $this->getOption(xPDO::OPT_TABLE_PREFIX, null, '') . $table;
                }
            }
            if (!$table && $ancestry= $this->getAncestry($className, false)) {
                foreach ($ancestry as $ancestor) {
                    if (isset ($this->map[$ancestor]['table']) && $table= $this->map[$ancestor]['table']) {
                        if (isset($this->map[$ancestor]['package']) && isset($this->packages[$this->map[$ancestor]['package']]['prefix'])) {
                            $table= $this->packages[$this->map[$ancestor]['package']]['prefix'] . $table;
                        } else {
                            $table= $this->getOption(xPDO::OPT_TABLE_PREFIX, null, '') . $table;
                        }
                        break;
                    }
                }
            }
        }
        if ($table) {
            $table= $this->_getFullTableName($table, $includeDb);
            if ($this->getDebug() === true) $this->log(xPDO::LOG_LEVEL_DEBUG, 'Returning table name: ' . $table . ' for class: ' . $className);
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, 'Could not get table name for class: ' . $className);
        }
        return $table;
    }

    /**
     * Get the class which defines the table for a specified className.
     *
     * @param string $className The name of a class to determine the table class from.
     * @return null|string The name of a class defining the table for the specified className; null if not found.
     */
    public function getTableClass($className) {
        $tableClass= null;
        if ($className= $this->loadClass($className)) {
            if (isset ($this->map[$className]['table'])) {
                $tableClass= $className;
            }
            if (!$tableClass && $ancestry= $this->getAncestry($className, false)) {
                foreach ($ancestry as $ancestor) {
                    if (isset ($this->map[$ancestor]['table'])) {
                        $tableClass= $ancestor;
                        break;
                    }
                }
            }
        }
        if ($tableClass) {
            if ($this->getDebug() === true) {
                $this->log(xPDO::LOG_LEVEL_DEBUG, 'Returning table class: ' . $tableClass . ' for class: ' . $className);
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, 'Could not get table class for class: ' . $className);
        }
        return $tableClass;
    }

    /**
     * Gets the actual run-time table metadata from a specified class name.
     *
     * @param string $className The name of the class to lookup a table name
     * for.
     * @return string The table meta data for the class, or null if
     * unsuccessful.
     */
    public function getTableMeta($className) {
        $tableMeta= null;
        if ($className= $this->loadClass($className)) {
            if (isset ($this->map[$className]['tableMeta'])) {
                $tableMeta= $this->map[$className]['tableMeta'];
            }
            if (!$tableMeta && $ancestry= $this->getAncestry($className)) {
                foreach ($ancestry as $ancestor) {
                    if (isset ($this->map[$ancestor]['tableMeta'])) {
                        if ($tableMeta= $this->map[$ancestor]['tableMeta']) {
                            break;
                        }
                    }
                }
            }
        }
        return $tableMeta;
    }

    /**
     * Indicates the inheritance model for the xPDOObject class specified.
     *
     * @param string $className The class to determine the table inherit type from.
     * @return string single, multiple, or none
     */
    public function getInherit($className) {
        $inherit= false;
        if ($className= $this->loadClass($className)) {
            if (isset ($this->map[$className]['inherit'])) {
                $inherit= $this->map[$className]['inherit'];
            }
            if (!$inherit && $ancestry= $this->getAncestry($className, false)) {
                foreach ($ancestry as $ancestor) {
                    if (isset ($this->map[$ancestor]['inherit'])) {
                        $inherit= $this->map[$ancestor]['inherit'];
                        break;
                    }
                }
            }
        }
        if (!empty($inherit)) {
            if ($this->getDebug() === true) {
                $this->log(xPDO::LOG_LEVEL_DEBUG, 'Returning inherit: ' . $inherit . ' for class: ' . $className);
            }
        } else {
            $inherit= 'none';
        }
        return $inherit;
    }

    /**
     * Gets a list of fields (or columns) for an object by class name.
     *
     * This includes default values for each field and is used by the objects
     * themselves to build their initial attributes based on class inheritence.
     *
     * @param string $className The name of the class to lookup fields for.
     * @return array An array featuring field names as the array keys, and
     * default field values as the array values; empty array is returned if
     * unsuccessful.
     */
    public function getFields($className) {
        $fields= array ();
        if ($className= $this->loadClass($className)) {
            if ($ancestry= $this->getAncestry($className)) {
                for ($i= count($ancestry) - 1; $i >= 0; $i--) {
                    if (isset ($this->map[$ancestry[$i]]['fields'])) {
                        $fields= array_merge($fields, $this->map[$ancestry[$i]]['fields']);
                    }
                }
            }
            if ($this->getInherit($className) === 'single') {
                $descendants= $this->getDescendants($className);
                if ($descendants) {
                    foreach ($descendants as $descendant) {
                        $descendantClass= $this->loadClass($descendant);
                        if ($descendantClass && isset($this->map[$descendantClass]['fields'])) {
                            $fields= array_merge($fields, array_diff_key($this->map[$descendantClass]['fields'], $fields));
                        }
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * Gets a list of field (or column) definitions for an object by class name.
     *
     * These definitions are used by the objects themselves to build their
     * own meta data based on class inheritance.
     *
     * @param string $className The name of the class to lookup fields meta data
     * for.
     * @param boolean $includeExtended If true, include meta from all derivative
     * classes in loaded packages.
     * @return array An array featuring field names as the array keys, and
     * arrays of metadata information as the array values; empty array is
     * returned if unsuccessful.
     */
    public function getFieldMeta($className, $includeExtended = false) {
        $fieldMeta= array ();
        if ($className= $this->loadClass($className)) {
            if ($ancestry= $this->getAncestry($className)) {
                for ($i= count($ancestry) - 1; $i >= 0; $i--) {
                    if (isset ($this->map[$ancestry[$i]]['fieldMeta'])) {
                        $fieldMeta= array_merge($fieldMeta, $this->map[$ancestry[$i]]['fieldMeta']);
                    }
                }
            }
            if ($includeExtended && $this->getInherit($className) === 'single') {
                $descendants= $this->getDescendants($className);
                if ($descendants) {
                    foreach ($descendants as $descendant) {
                        $descendantClass= $this->loadClass($descendant);
                        if ($descendantClass && isset($this->map[$descendantClass]['fieldMeta'])) {
                            $fieldMeta= array_merge($fieldMeta, array_diff_key($this->map[$descendantClass]['fieldMeta'], $fieldMeta));
                        }
                    }
                }
            }
        }
        return $fieldMeta;
    }

    /**
     * Gets a collection of field aliases for an object by class name.
     *
     * @param string $className The name of the class to lookup field aliases for.
     * @return array An array of field aliases with aliases as keys and actual field names as values.
     */
    public function getFieldAliases($className) {
        $fieldAliases= array ();
        if ($className= $this->loadClass($className)) {
            if ($ancestry= $this->getAncestry($className)) {
                for ($i= count($ancestry) - 1; $i >= 0; $i--) {
                    if (isset ($this->map[$ancestry[$i]]['fieldAliases'])) {
                        $fieldAliases= array_merge($fieldAliases, $this->map[$ancestry[$i]]['fieldAliases']);
                    }
                }
            }
            if ($this->getInherit($className) === 'single') {
                $descendants= $this->getDescendants($className);
                if ($descendants) {
                    foreach ($descendants as $descendant) {
                        $descendantClass= $this->loadClass($descendant);
                        if ($descendantClass && isset($this->map[$descendantClass]['fieldAliases'])) {
                            $fieldAliases= array_merge($fieldAliases, array_diff_key($this->map[$descendantClass]['fieldAliases'], $fieldAliases));
                        }
                    }
                }
            }
        }
        return $fieldAliases;
    }

    /**
     * Gets a set of validation rules defined for an object by class name.
     *
     * @param string $className The name of the class to lookup validation rules
     * for.
     * @return array An array featuring field names as the array keys, and
     * arrays of validation rule information as the array values; empty array is
     * returned if unsuccessful.
     */
    public function getValidationRules($className) {
        $rules= array();
        if ($className= $this->loadClass($className)) {
            if ($ancestry= $this->getAncestry($className)) {
                for ($i= count($ancestry) - 1; $i >= 0; $i--) {
                    if (isset($this->map[$ancestry[$i]]['validation']['rules'])) {
                        $rules= array_merge($rules, $this->map[$ancestry[$i]]['validation']['rules']);
                    }
                }
            }
            if ($this->getInherit($className) === 'single') {
                $descendants= $this->getDescendants($className);
                if ($descendants) {
                    foreach ($descendants as $descendant) {
                        $descendantClass= $this->loadClass($descendant);
                        if ($descendantClass && isset($this->map[$descendantClass]['validation']['rules'])) {
                            $rules= array_merge($rules, array_diff_key($this->map[$descendantClass]['validation']['rules'], $rules));
                        }
                    }
                }
            }
            if ($this->getDebug() === true) {
                $this->log(xPDO::LOG_LEVEL_DEBUG, "Returning validation rules: " . print_r($rules, true));
            }
        }
        return $rules;
    }

    /**
     * Get indices defined for a table class.
     *
     * @param string $className The name of the class to lookup indices for.
     * @return array An array of indices and their details for the specified class.
     */
    public function getIndexMeta($className) {
        $indices= array();
        if ($className= $this->loadClass($className)) {
            if ($ancestry= $this->getAncestry($className)) {
                for ($i= count($ancestry) -1; $i >= 0; $i--) {
                    if (isset($this->map[$ancestry[$i]]['indexes'])) {
                        $indices= array_merge($indices, $this->map[$ancestry[$i]]['indexes']);
                    }
                }
                if ($this->getInherit($className) === 'single') {
                    $descendants= $this->getDescendants($className);
                    if ($descendants) {
                        foreach ($descendants as $descendant) {
                            $descendantClass= $this->loadClass($descendant);
                            if ($descendantClass && isset($this->map[$descendantClass]['indexes'])) {
                                $indices= array_merge($indices, array_diff_key($this->map[$descendantClass]['indexes'], $indices));
                            }
                        }
                    }
                }
                if ($this->getDebug() === true) {
                    $this->log(xPDO::LOG_LEVEL_DEBUG, "Returning indices: " . print_r($indices, true));
                }
            }
        }
        return $indices;
    }

    /**
     * Gets the primary key field(s) for a class.
     *
     * @param string $className The name of the class to lookup the primary key
     * for.
     * @return mixed The name of the field representing a class instance primary
     * key, an array of key names for compound primary keys, or null if no
     * primary key is found or defined for the class.
     */
    public function getPK($className) {
        $pk= null;
        if (strcasecmp($className, 'xPDOObject') !== 0) {
            if ($actualClassName= $this->loadClass($className)) {
                if (isset ($this->map[$actualClassName]['indexes'])) {
                    foreach ($this->map[$actualClassName]['indexes'] as $k => $v) {
                        if (isset($v['primary']) && ($v['primary'] == true) && isset($v['columns'])) {
                            foreach ($v['columns'] as $field => $column) {
                                if (isset ($this->map[$actualClassName]['fieldMeta'][$field]['phptype'])) {
                                    $pk[$field] = $field;
                                }
                            }
                        }
                    }
                }
                if (isset ($this->map[$actualClassName]['fieldMeta'])) {
                    foreach ($this->map[$actualClassName]['fieldMeta'] as $k => $v) {
                        if (isset ($v['index']) && isset ($v['phptype']) && $v['index'] == 'pk') {
                            $pk[$k]= $k;
                        }
                    }
                }
                if ($ancestry= $this->getAncestry($actualClassName)) {
                    foreach ($ancestry as $ancestor) {
                        if ($ancestorClassName= $this->loadClass($ancestor)) {
                            if (isset ($this->map[$ancestorClassName]['indexes'])) {
                                foreach ($this->map[$ancestorClassName]['indexes'] as $k => $v) {
                                    if (isset ($this->map[$ancestorClassName]['fieldMeta'][$k]['phptype'])) {
                                        if (isset ($v['primary']) && $v['primary'] == true) {
                                            $pk[$k]= $k;
                                        }
                                    }
                                }
                            }
                            if (isset ($this->map[$ancestorClassName]['fieldMeta'])) {
                                foreach ($this->map[$ancestorClassName]['fieldMeta'] as $k => $v) {
                                    if (isset ($v['index']) && isset ($v['phptype']) && $v['index'] == 'pk') {
                                        $pk[$k]= $k;
                                    }
                                }
                            }
                        }
                    }
                }
                if ($pk && count($pk) === 1) {
                    $pk= current($pk);
                }
            } else {
                $this->log(xPDO::LOG_LEVEL_ERROR, "Could not load class {$className}");
            }
        }
        return $pk;
    }

    /**
     * Gets the type of primary key field for a class.
     *
     * @param string $className The name of the class to lookup the primary key
     * type for.
     * @param mixed $pk Optional specific PK column or columns to get type(s) for.
     * @return string The type of the field representing a class instance primary
     * key, or null if no primary key is found or defined for the class.
     */
    public function getPKType($className, $pk= false) {
        $pktype= null;
        if ($actualClassName= $this->loadClass($className)) {
            if (!$pk)
                $pk= $this->getPK($actualClassName);
            if (!is_array($pk))
                $pk= array($pk);
            $ancestry= $this->getAncestry($actualClassName, true);
            foreach ($pk as $_pk) {
                foreach ($ancestry as $parentClass) {
                    if (isset ($this->map[$parentClass]['fieldMeta'][$_pk]['phptype'])) {
                        $pktype[$_pk]= $this->map[$parentClass]['fieldMeta'][$_pk]['phptype'];
                        break;
                    }
                }
            }
            if (is_array($pktype) && count($pktype) == 1) {
                $pktype= reset($pktype);
            }
            elseif (empty($pktype)) {
                $pktype= null;
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, "Could not load class {$className}!");
        }
        return $pktype;
    }

    /**
     * Gets a collection of aggregate foreign key relationship definitions.
     *
     * @param string $className The fully-qualified name of the class.
     * @return array An array of aggregate foreign key relationship definitions.
     */
    public function getAggregates($className) {
        $aggregates= array ();
        if ($className= $this->loadClass($className)) {
            if ($ancestry= $this->getAncestry($className)) {
                for ($i= count($ancestry) - 1; $i >= 0; $i--) {
                    if (isset ($this->map[$ancestry[$i]]['aggregates'])) {
                        $aggregates= array_merge($aggregates, $this->map[$ancestry[$i]]['aggregates']);
                    }
                }
            }
            if ($this->getInherit($className) === 'single') {
                $descendants= $this->getDescendants($className);
                if ($descendants) {
                    foreach ($descendants as $descendant) {
                        $descendantClass= $this->loadClass($descendant);
                        if ($descendantClass && isset($this->map[$descendantClass]['aggregates'])) {
                            $aggregates= array_merge($aggregates, array_diff_key($this->map[$descendantClass]['aggregates'], $aggregates));
                        }
                    }
                }
            }
        }
        return $aggregates;
    }

    /**
     * Gets a collection of composite foreign key relationship definitions.
     *
     * @param string $className The fully-qualified name of the class.
     * @return array An array of composite foreign key relationship definitions.
     */
    public function getComposites($className) {
        $composites= array ();
        if ($className= $this->loadClass($className)) {
            if ($ancestry= $this->getAncestry($className)) {
                for ($i= count($ancestry) - 1; $i >= 0; $i--) {
                    if (isset ($this->map[$ancestry[$i]]['composites'])) {
                        $composites= array_merge($composites, $this->map[$ancestry[$i]]['composites']);
                    }
                }
            }
            if ($this->getInherit($className) === 'single') {
                $descendants= $this->getDescendants($className);
                if ($descendants) {
                    foreach ($descendants as $descendant) {
                        $descendantClass= $this->loadClass($descendant);
                        if ($descendantClass && isset($this->map[$descendantClass]['composites'])) {
                            $composites= array_merge($composites, array_diff_key($this->map[$descendantClass]['composites'], $composites));
                        }
                    }
                }
            }
        }
        return $composites;
    }

    /**
     * Get a complete relation graph for an xPDOObject class.
     *
     * @param string $className A fully-qualified xPDOObject class name.
     * @param int $depth The depth to retrieve relations for the graph, defaults to 3.
     * @param array &$parents An array of parent classes to avoid traversing circular dependencies.
     * @param array &$visited An array of already visited classes to avoid traversing circular dependencies.
     * @return array An xPDOObject relation graph, or an empty array if no graph can be constructed.
     */
    public function getGraph($className, $depth= 3, &$parents = array(), &$visited = array()) {
        $graph = array();
        $className = $this->loadClass($className);
        if ($className && $depth > 0) {
            $depth--;
            $parents = array_merge($parents, $this->getAncestry($className));
            $parentsNested = array_unique($parents);
            $visitNested = array_merge($visited, array($className));
            $relations = array_merge($this->getAggregates($className), $this->getComposites($className));
            foreach ($relations as $alias => $relation) {
                if (in_array($relation['class'], $visited)) {
                    continue;
                }
                $childGraph = array();
                if ($depth > 0 && !in_array($relation['class'], $parents)) {
                    $childGraph = $this->getGraph($relation['class'], $depth, $parentsNested, $visitNested);
                }
                $graph[$alias] = $childGraph;
            }
            $visited[] = $className;
        }
        return $graph;
    }

    /**
     * Retrieves the complete ancestry for a class.
     *
     * @param string $className The name of the class.
     * @param bool $includeSelf Determines if the specified class should be
     * included in the resulting array.
     * @return array An array of string class names representing the class
     * hierarchy, or an empty array if unsuccessful.
     */
    public function getAncestry($className, $includeSelf= true) {
        $ancestry= array ();
        if ($actualClassName= $this->loadClass($className)) {
            $ancestor= $actualClassName;
            if ($includeSelf) {
                $ancestry[]= $actualClassName;
            }
            while ($ancestor= get_parent_class($ancestor)) {
                $ancestry[]= $ancestor;
            }
            if ($this->getDebug() === true) {
                $this->log(xPDO::LOG_LEVEL_DEBUG, "Returning ancestry for {$className}: " . print_r($ancestry, 1));
            }
        }
        return $ancestry;
    }

    /**
     * Gets select columns from a specific class for building a query.
     *
     * @uses xPDOObject::getSelectColumns()
     * @param string $className The name of the class to build the column list
     * from.
     * @param string $tableAlias An optional alias for the class table, to be
     * used in complex queries with multiple tables.
     * @param string $columnPrefix An optional string with which to prefix the
     * columns returned, to avoid name collisions in return columns.
     * @param array $columns An optional array of columns to include.
     * @param boolean $exclude If true, will exclude columns in the previous
     * parameter, instead of including them.
     * @return string A valid SQL string of column names for a SELECT statement.
     */
    public function getSelectColumns($className, $tableAlias= '', $columnPrefix= '', $columns= array (), $exclude= false) {
        return $this->call($className, 'getSelectColumns', array(&$this, $className, $tableAlias, $columnPrefix, $columns, $exclude));
    }

    /**
     * Gets an aggregate or composite relation definition from a class.
     *
     * @param string $parentClass The class from which the relation is defined.
     * @param string $alias The alias identifying the related class.
     * @return array The aggregate or composite definition details in an array
     * or null if no definition is found.
     */
    function getFKDefinition($parentClass, $alias) {
        $def= null;
        $parentClass= $this->loadClass($parentClass);
        if ($parentClass && $alias) {
            if ($aggregates= $this->getAggregates($parentClass)) {
                if (isset ($aggregates[$alias])) {
                    $def= $aggregates[$alias];
                    $def['type']= 'aggregate';
                }
            }
            if ($composites= $this->getComposites($parentClass)) {
                if (isset ($composites[$alias])) {
                    $def= $composites[$alias];
                    $def['type']= 'composite';
                }
            }
        }
        if ($def === null) {
            $this->log(xPDO::LOG_LEVEL_ERROR, 'No foreign key definition for parentClass: ' . $parentClass . ' using relation alias: ' . $alias);
        }
        return $def;
    }

    /**
     * Gets the version string of the schema the specified class was generated from.
     *
     * @param string $className The name of the class to get the model version from.
     * @return string The version string for the schema model the class was generated from.
     */
    public function getModelVersion($className) {
        $version = '1.0';
        $className= $this->loadClass($className);
        if ($className && isset($this->map[$className]['version'])) {
            $version= $this->map[$className]['version'];
        }
        return $version;
    }

    /**
     * Gets the manager class for this xPDO connection.
     *
     * The manager class can perform operations such as creating or altering
     * table structures, creating data containers, generating custom persistence
     * classes, and other advanced operations that do not need to be loaded
     * frequently.
     *
     * @return Om\xPDOManager|null An xPDOManager instance for the xPDO connection, or null
     * if a manager class can not be instantiated.
     */
    public function getManager() {
        if ($this->manager === null || !$this->manager instanceof Om\xPDOManager) {
            $managerClass = '\\xPDO\\Om\\' . $this->config['dbtype'] . '\\xPDOManager';
            $this->manager= new $managerClass($this);
            if (!$this->manager) {
                $this->log(xPDO::LOG_LEVEL_ERROR, "Could not load xPDOManager class.");
            }
        }
        return $this->manager;
    }

    /**
     * Gets the driver class for this xPDO connection.
     *
     * The driver class provides baseline data and operations for a specific database driver.
     *
     * @return Om\xPDODriver|null An xPDODriver instance for the xPDO connection, or null
     * if a driver class can not be instantiated.
     */
    public function getDriver() {
        if ($this->driver === null || !$this->driver instanceof Om\xPDODriver) {
            $driverClass = '\\xPDO\\Om\\' . $this->config['dbtype'] . '\\xPDODriver';
            $this->driver= new $driverClass($this);
            if (!$this->driver) {
                $this->log(xPDO::LOG_LEVEL_ERROR, "Could not load xPDODriver class for the {$this->config['dbtype']} PDO driver.");
            }
        }
        return $this->driver;
    }

    /**
     * Gets the absolute path to the cache directory.
     *
     * @return string The full cache directory path.
     */
    public function getCachePath() {
        if (!$this->cachePath) {
            if ($this->getCacheManager()) {
                $this->cachePath= $this->cacheManager->getCachePath();
            }
        }
        return $this->cachePath;
    }

    /**
     * Gets an xPDOCacheManager instance.
     *
     * This class is responsible for handling all types of caching operations for the xPDO core.
     *
     * @param string $class Optional name of a derivative xPDOCacheManager class.
     * @param array $options An array of options for the cache manager instance; valid options include:
     *  - path = Optional root path for looking up the $class.
     *  - ignorePkg = If false and you do not specify a path, you can look up custom xPDOCacheManager
     *      derivatives in declared packages.
     * @return Cache\xPDOCacheManager The xPDOCacheManager for this xPDO instance.
     */
    public function getCacheManager($class= 'xPDO\\Cache\\xPDOCacheManager', $options = array('path' => XPDO_CORE_PATH, 'ignorePkg' => true)) {
        if ($this->cacheManager === null || !is_object($this->cacheManager) || !($this->cacheManager instanceof $class)) {
            if ($this->cacheManager= new $class($this, $options)) {
                $this->_cacheEnabled= true;
            }
        }
        return $this->cacheManager;
    }

    /**
     * Returns the debug state for the xPDO instance.
     *
     * @return boolean|integer The current debug state for the instance, true for on,
     * false for off.
     */
    public function getDebug() {
        return $this->_debug;
    }

    /**
     * Sets the debug state for the xPDO instance.
     *
     * @param boolean|integer $v The debug status, true for on, false for off, or a valid
     * error_reporting level for PHP.
     */
    public function setDebug($v= true) {
        $this->_debug= $v;
    }

    /**
     * Sets the logging level state for the xPDO instance.
     *
     * @param integer $level The logging level to switch to.
     * @return integer The previous log level.
     */
    public function setLogLevel($level= xPDO::LOG_LEVEL_FATAL) {
        $oldLevel = $this->logLevel;
        $this->logLevel= intval($level);
        return $oldLevel;
    }

    /**
     * @return integer The current log level.
     */
    public function getLogLevel() {
        return $this->logLevel;
    }

    /**
     * Sets the log target for xPDO::_log() calls.
     *
     * Valid target values include:
     * <ul>
     * <li>'ECHO': Returns output to the STDOUT.</li>
     * <li>'HTML': Returns output to the STDOUT with HTML formatting.</li>
     * <li>'FILE': Sends output to a log file.</li>
     * <li>An array with at least one element with key 'target' matching
     * one of the valid log targets listed above. For 'target' => 'FILE'
     * you can specify a second element with key 'options' with another
     * associative array with one or both of the elements 'filename' and
     * 'filepath'</li>
     * </ul>
     *
     * @param string $target An identifier indicating the target of the logging.
     * @return mixed The previous log target.
     */
    public function setLogTarget($target= 'ECHO') {
        $oldTarget = $this->logTarget;
        $this->logTarget= $target;
        return $oldTarget;
    }

    /**
     * @return integer The current log level.
     */
    public function getLogTarget() {
        return $this->logTarget;
    }

    /**
     * Log a message with details about where and when an event occurs.
     *
     * @param integer $level The level of the logged message.
     * @param string $msg The message to log.
     * @param string $target The logging target.
     * @param string $def The name of a defining structure (such as a class) to
     * help identify the message source.
     * @param string $file A filename in which the log event occurred.
     * @param string $line A line number to help locate the source of the event
     * within the indicated file.
     */
    public function log($level, $msg, $target= '', $def= '', $file= '', $line= '') {
        $this->_log($level, $msg, $target, $def, $file, $line);
    }

    /**
     * Log a message as appropriate for the level and target.
     *
     * @param integer $level The level of the logged message.
     * @param string $msg The message to log.
     * @param string $target The logging target.
     * @param string $def The name of a defining structure (such as a class) to
     * help identify the log event source.
     * @param string $file A filename in which the log event occurred.
     * @param string $line A line number to help locate the source of the event
     * within the indicated file.
     */
    protected function _log($level, $msg, $target= '', $def= '', $file= '', $line= '') {
        if ($level !== xPDO::LOG_LEVEL_FATAL && $level > $this->logLevel && $this->_debug !== true) {
            return;
        }
        if (empty ($target)) {
            $target = $this->logTarget;
        }
        $targetOptions = array();
        if (is_array($target)) {
            if (isset($target['options'])) $targetOptions =& $target['options'];
            $target = isset($target['target']) ? $target['target'] : 'ECHO';
        }
        if (empty($file)) {
            if (version_compare(phpversion(), '5.4.0', '>=')) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            } elseif (version_compare(phpversion(), '5.3.6', '>=')) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            } else {
                $backtrace = debug_backtrace();
            }
            if ($backtrace && isset($backtrace[2])) {
                $file = $backtrace[2]['file'];
                $line = $backtrace[2]['line'];
            }
        }
        if (empty($file) && isset($_SERVER['SCRIPT_NAME'])) {
            $file = $_SERVER['SCRIPT_NAME'];
        }
        if ($level === xPDO::LOG_LEVEL_FATAL) {
            while (ob_get_level() && @ob_end_flush()) {}
            exit ('[' . date('Y-m-d H:i:s') . '] (' . $this->_getLogLevel($level) . $def . $file . $line . ') ' . $msg . "\n" . ($this->getDebug() === true ? '<pre>' . "\n" . print_r(debug_backtrace(), true) . "\n" . '</pre>' : ''));
        }
        if ($this->_debug === true || $level <= $this->logLevel) {
            @ob_start();
            if (!empty ($def)) {
                $def= " in {$def}";
            }
            if (!empty ($file)) {
                $file= " @ {$file}";
            }
            if (!empty ($line)) {
                $line= " : {$line}";
            }
            switch ($target) {
                case 'HTML' :
                    echo '<h5>[' . date('Y-m-d H:i:s') . '] (' . $this->_getLogLevel($level) . $def . $file . $line . ')</h5><pre>' . $msg . '</pre>' . "\n";
                    break;
                default :
                    echo '[' . date('Y-m-d H:i:s') . '] (' . $this->_getLogLevel($level) . $def . $file . $line . ') ' . $msg . "\n";
            }
            $content= @ob_get_contents();
            @ob_end_clean();
            if ($target=='FILE' && $this->getCacheManager()) {
                $filename = isset($targetOptions['filename']) ? $targetOptions['filename'] : 'error.log';
                $filepath = isset($targetOptions['filepath']) ? $targetOptions['filepath'] : $this->getCachePath() . Cache\xPDOCacheManager::LOG_DIR;
                $this->cacheManager->writeFile($filepath . $filename, $content, 'a');
            } elseif (
                $target === 'ARRAY' &&
                isset($targetOptions['var']) &&
                (is_array($targetOptions['var']) || $targetOptions['var'] instanceof \ArrayAccess)
            ) {
                $targetOptions['var'][] = $content;
            } elseif (
                $target === 'ARRAY_EXTENDED' &&
                isset($targetOptions['var']) &&
                (is_array($targetOptions['var']) || $targetOptions['var'] instanceof \ArrayAccess)
            ) {
                $targetOptions['var'][] = array(
                    'content' => $content,
                    'level' => $this->_getLogLevel($level),
                    'msg' => $msg,
                    'def' => $def,
                    'file' => $file,
                    'line' => $line
                );
            } else {
                echo $content;
            }
        }
    }

    /**
     * Returns an abbreviated backtrace of debugging information.
     *
     * This function returns just the fields returned via xPDOObject::toArray()
     * on xPDOObject instances, and simply the class name for other objects, to
     * reduce the amount of unnecessary information returned.
     *
     * @return array The abbreviated backtrace.
     */
    public function getDebugBacktrace() {
        $backtrace= array ();
        foreach (debug_backtrace() as $levelKey => $levelElement) {
            foreach ($levelElement as $traceKey => $traceElement) {
                if ($traceKey == 'object' && $traceElement instanceof Om\xPDOObject) {
                    $backtrace[$levelKey][$traceKey]= $traceElement->toArray('', true);
                } elseif ($traceKey == 'object') {
                    $backtrace[$levelKey][$traceKey]= get_class($traceElement);
                } else {
                    $backtrace[$levelKey][$traceKey]= $traceElement;
                }
            }
        }
        return $backtrace;
    }

    /**
     * Gets a logging level as a string representation.
     *
     * @param integer $level The logging level to retrieve a string for.
     * @return string The string representation of a valid logging level.
     */
    protected function _getLogLevel($level) {
        switch ($level) {
            case xPDO::LOG_LEVEL_DEBUG :
                $levelText= 'DEBUG';
                break;
            case xPDO::LOG_LEVEL_INFO :
                $levelText= 'INFO';
                break;
            case xPDO::LOG_LEVEL_WARN :
                $levelText= 'WARN';
                break;
            case xPDO::LOG_LEVEL_ERROR :
                $levelText= 'ERROR';
                break;
            default :
                $levelText= 'FATAL';
        }
        return $levelText;
    }

    /**
     * Escapes the provided string using the platform-specific escape character.
     *
     * Different database engines escape string literals in SQL using different characters. For example, this is used to
     * escape column names that might match a reserved string for that SQL interpreter. To write database agnostic
     * queries with xPDO, it is highly recommend to escape any database or column names in any native SQL strings used.
     *
     * @param string $string A string to escape using the platform-specific escape characters.
     * @return string The string escaped with the platform-specific escape characters.
     */
    public function escape($string) {
        $string = trim($string, $this->_escapeCharOpen . $this->_escapeCharClose);
        return $this->_escapeCharOpen . $string . $this->_escapeCharClose;
    }

    /**
     * Use to insert a literal string into a SQL query without escaping or quoting.
     *
     * @param string $string A string to return as a literal, unescaped and unquoted.
     * @return string The string with any escape or quote characters trimmed.
     */
    public function literal($string) {
        $string = trim($string, $this->_escapeCharOpen . $this->_escapeCharClose . $this->_quoteChar);
        return $string;
    }

    /**
     * Adds the table prefix, and optionally database name, to a given table.
     *
     * @param string $baseTableName The table name as specified in the object
     * model.
     * @param boolean $includeDb Qualify the table name with the database name.
     * @return string The fully-qualified and quoted table name for the
     */
    private function _getFullTableName($baseTableName, $includeDb= false) {
        $fqn= '';
        if (!empty ($baseTableName)) {
            if ($includeDb) {
                $fqn .= $this->escape($this->config['dbname']) . '.';
            }
            $fqn .= $this->escape($baseTableName);
        }
        return $fqn;
    }

    /**
     * Parses a DSN and returns an array of the connection details.
     *
     * @static
     * @param string $string The DSN to parse.
     * @return array An array of connection details from the DSN.
     * @todo Have this method handle all methods of DSN specification as handled
     * by latest native PDO implementation.
     */
    public static function parseDSN($string) {
        $result= array ();
        $pos= strpos($string, ':');
        $result['dbtype']= strtolower(substr($string, 0, $pos));
        $parameters= explode(';', substr($string, ($pos +1)));
        for ($a= 0, $b= count($parameters); $a < $b; $a++) {
            $tmp= explode('=', $parameters[$a]);
            if (count($tmp) == 2) {
                $result[strtolower(trim($tmp[0]))]= trim($tmp[1]);
            } else {
                $result['dbname']= trim($parameters[$a]);
            }
        }
        if (!isset($result['dbname']) && isset($result['database'])) {
            $result['dbname'] = $result['database'];
        }
        if (!isset($result['host']) && isset($result['server'])) {
            $result['host'] = $result['server'];
        }
        return $result;
    }

    /**
     * Retrieves a result array from the object cache.
     *
     * @param string|Om\xPDOCriteria $signature A unique string or xPDOCriteria object
     * that represents the query identifying the result set.
     * @param string $class An optional classname the result represents.
     * @param array $options Various cache options.
     * @return array|string|null A PHP array or JSON object representing the
     * result set, or null if no cache representation is found.
     */
    public function fromCache($signature, $class= '', $options= array()) {
        $result= null;
        if ($this->getOption(xPDO::OPT_CACHE_DB, $options)) {
            if ($signature && $this->getCacheManager()) {
                $sig= '';
                $sigKey= array();
                $sigHash= '';
                $sigClass= empty($class) || !is_string($class) ? '' : $class;
                if (is_object($signature)) {
                    if ($signature instanceof Om\xPDOCriteria) {
                        if ($signature instanceof Om\xPDOQuery) {
                            $signature->construct();
                            if (empty($sigClass)) $sigClass= $signature->getTableClass();
                        }
                        $sigKey= array ($signature->sql, $signature->bindings);
                    }
                }
                elseif (is_string($signature)) {
                    if ($exploded= explode('_', $signature)) {
                        $class= reset($exploded);
                        if (empty($sigClass) || $sigClass !== $class) {
                            $sigClass= $class;
                        }
                        if (empty($sigKey)) {
                            while ($key= next($exploded)) {
                                $sigKey[]= $key;
                            }
                        }
                    }
                }
                if (empty($sigClass)) $sigClass= '__sqlResult';
                if ($sigClass && $sigKey) {
                    $sigHash= md5($this->toJSON($sigKey));
                    $sig= implode('/', array ($sigClass, $sigHash));
                }
                if (is_string($sig) && !empty($sig)) {
                    $result= $this->cacheManager->get($sig, array(
                        xPDO::OPT_CACHE_KEY => $this->getOption('cache_db_key', $options, 'db'),
                        xPDO::OPT_CACHE_HANDLER => $this->getOption(xPDO::OPT_CACHE_DB_HANDLER, $options, $this->getOption(xPDO::OPT_CACHE_HANDLER, $options, 'xPDO\\Cache\\xPDOFileCache')),
                        xPDO::OPT_CACHE_FORMAT => (integer) $this->getOption('cache_db_format', null, $this->getOption(xPDO::OPT_CACHE_FORMAT, null, Cache\xPDOCacheManager::CACHE_PHP)),
                        'cache_prefix' => $this->getOption('cache_db_prefix', $options, Cache\xPDOCacheManager::CACHE_DIR),
                    ));
                    if ($this->getDebug() === true) {
                        if (!$result) {
                            $this->log(xPDO::LOG_LEVEL_DEBUG, 'No cache item found for class ' . $sigClass . ' with signature ' . Cache\xPDOCacheManager::CACHE_DIR . $sig);
                        } else {
                            $this->log(xPDO::LOG_LEVEL_DEBUG, 'Loaded cache item for class ' . $sigClass . ' with signature ' . Cache\xPDOCacheManager::CACHE_DIR . $sig);
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Places a result set in the object cache.
     *
     * @param string|Om\xPDOCriteria $signature A unique string or xPDOCriteria object
     * representing the object.
     * @param object $object An object to place a representation of in the cache.
     * @param integer $lifetime An optional number of seconds the cached result
     * will remain valid, with 0 meaning it will remain valid until replaced or
     * removed.
     * @param array $options Various cache options.
     * @return boolean Indicates if the object was successfully cached.
     */
    public function toCache($signature, $object, $lifetime= 0, $options = array()) {
        $result= false;
        if ($this->getCacheManager()) {
            if ($this->getOption(xPDO::OPT_CACHE_DB, $options)) {
                if ($lifetime === true) {
                    $lifetime = 0;
                }
                elseif (!$lifetime && $this->getOption(xPDO::OPT_CACHE_DB_EXPIRES, $options, 0)) {
                    $lifetime= intval($this->getOption(xPDO::OPT_CACHE_DB_EXPIRES, $options, 0));
                }
                $sigKey= array();
                $sigClass= '';
                $sigGraph= $this->getOption(xPDO::OPT_CACHE_DB_SIG_GRAPH, $options, array());
                if (is_object($signature)) {
                    if ($signature instanceof Om\xPDOCriteria) {
                        if ($signature instanceof Om\xPDOQuery) {
                            $signature->construct();
                            if (empty($sigClass)) $sigClass = $signature->getTableClass();
                        }
                        $sigKey= array($signature->sql, $signature->bindings);
                    }
                }
                elseif (is_string($signature)) {
                    $exploded= explode('_', $signature);
                    if ($exploded && count($exploded) >= 2) {
                        $class= reset($exploded);
                        if (empty($sigClass) || $sigClass !== $class) {
                            $sigClass= $class;
                        }
                        if (empty($sigKey)) {
                            while ($key= next($exploded)) {
                                $sigKey[]= $key;
                            }
                        }
                    }
                }
                if (empty($sigClass)) {
                    if ($object instanceof Om\xPDOObject) {
                        $sigClass= $object->_class;
                    } else {
                        $sigClass= $this->getOption(xPDO::OPT_CACHE_DB_SIG_CLASS, $options, '__sqlResult');
                    }
                }
                if (empty($sigKey) && is_string($signature)) $sigKey= $signature;
                if (empty($sigKey) && $object instanceof Om\xPDOObject) $sigKey= $object->getPrimaryKey();
                if ($sigClass && $sigKey) {
                    $sigHash= md5($this->toJSON(is_array($sigKey) ? $sigKey : array($sigKey)));
                    $sig= implode('/', array ($sigClass, $sigHash));
                    if (is_string($sig)) {
                        if ($this->getOption('modified', $options, false)) {
                            if (empty($sigGraph) && $object instanceof Om\xPDOObject) {
                                $sigGraph = array_merge(array($object->_class => array('class' => $object->_class)), $object->_aggregates, $object->_composites);
                            }
                            if (!empty($sigGraph)) {
                                foreach ($sigGraph as $gAlias => $gMeta) {
                                    $gClass = $gMeta['class'];
                                    $removed= $this->cacheManager->delete($gClass, array_merge($options, array(
                                        xPDO::OPT_CACHE_KEY => $this->getOption('cache_db_key', $options, 'db'),
                                        xPDO::OPT_CACHE_HANDLER => $this->getOption(xPDO::OPT_CACHE_DB_HANDLER, $options, $this->getOption(xPDO::OPT_CACHE_HANDLER, $options, 'xPDO\\Cache\\xPDOFileCache')),
                                        xPDO::OPT_CACHE_FORMAT => (integer) $this->getOption('cache_db_format', $options, $this->getOption(xPDO::OPT_CACHE_FORMAT, $options, Cache\xPDOCacheManager::CACHE_PHP)),
                                        xPDO::OPT_CACHE_EXPIRES => (integer) $this->getOption(xPDO::OPT_CACHE_DB_EXPIRES, null, $this->getOption(xPDO::OPT_CACHE_EXPIRES, null, 0)),
                                        xPDO::OPT_CACHE_PREFIX => $this->getOption('cache_db_prefix', $options, Cache\xPDOCacheManager::CACHE_DIR),
                                        xPDO::OPT_CACHE_MULTIPLE_OBJECT_DELETE => true
                                    )));
                                    if ($this->getDebug() === true) {
                                        $this->log(xPDO::LOG_LEVEL_DEBUG, "Removing all cache objects of class {$gClass}: " . ($removed ? 'successful' : 'failed'));
                                    }
                                }
                            }
                        }
                        $cacheOptions = array_merge($options, array(
                            xPDO::OPT_CACHE_KEY => $this->getOption('cache_db_key', $options, 'db'),
                            xPDO::OPT_CACHE_HANDLER => $this->getOption(xPDO::OPT_CACHE_DB_HANDLER, $options, $this->getOption(xPDO::OPT_CACHE_HANDLER, $options, 'xPDO\\Cache\\xPDOFileCache')),
                            xPDO::OPT_CACHE_FORMAT => (integer) $this->getOption('cache_db_format', $options, $this->getOption(xPDO::OPT_CACHE_FORMAT, $options, Cache\xPDOCacheManager::CACHE_PHP)),
                            xPDO::OPT_CACHE_EXPIRES => (integer) $this->getOption(xPDO::OPT_CACHE_DB_EXPIRES, null, $this->getOption(xPDO::OPT_CACHE_EXPIRES, null, 0)),
                            xPDO::OPT_CACHE_PREFIX => $this->getOption('cache_db_prefix', $options, Cache\xPDOCacheManager::CACHE_DIR)
                        ));
                        $result= $this->cacheManager->set($sig, $object, $lifetime, $cacheOptions);
                        if ($result && $object instanceof Om\xPDOObject) {
                            if ($this->getDebug() === true) {
                                $this->log(xPDO::LOG_LEVEL_DEBUG, "xPDO->toCache() successfully cached object with signature " . Cache\xPDOCacheManager::CACHE_DIR . $sig);
                            }
                        }
                        if (!$result) {
                            $this->log(xPDO::LOG_LEVEL_WARN, "xPDO->toCache() could not cache object with signature " . Cache\xPDOCacheManager::CACHE_DIR . $sig);
                        }
                    }
                } else {
                    $this->log(xPDO::LOG_LEVEL_ERROR, "Object sent toCache() has an invalid signature.");
                }
            }
        } else {
            $this->log(xPDO::LOG_LEVEL_ERROR, "Attempt to send a non-object to toCache().");
        }
        return $result;
    }

    /**
     * Converts a PHP array into a JSON encoded string.
     *
     * @param array $array The PHP array to convert.
     *
     * @throws xPDOException If json_encode is not available.
     * @return string The JSON representation of the source array.
     */
    public function toJSON($array) {
        $encoded= '';
        if (is_array ($array)) {
            if (!function_exists('json_encode')) {
                throw new xPDOException();
            } else {
                $encoded= json_encode($array);
            }
        }
        return $encoded;
    }

    /**
     * Converts a JSON source string into an equivalent PHP representation.
     *
     * @param string $src A JSON source string.
     * @param boolean $asArray Indicates if the result should treat objects as
     * associative arrays; since all JSON associative arrays are objects, the default
     * is true.  Set to false to have JSON objects returned as PHP objects.
     *
     * @throws xPDOException If json_decode is not available.
     * @return mixed The PHP representation of the JSON source.
     */
    public function fromJSON($src, $asArray= true) {
        $decoded= '';
        if ($src) {
            if (!function_exists('json_decode')) {
                throw new xPDOException();
            } else {
                $decoded= json_decode($src, $asArray);
            }
        }
        return $decoded;
    }

    /**
     * @see http://php.net/manual/en/function.pdo-begintransaction.php
     */
    public function beginTransaction() {
        if (!$this->connect(null, array(xPDO::OPT_CONN_MUTABLE => true))) {
            return false;
        }
        return $this->pdo->beginTransaction();
    }

    /**
     * @see http://php.net/manual/en/function.pdo-commit.php
     */
    public function commit() {
        if (!$this->connect(null, array(xPDO::OPT_CONN_MUTABLE => true))) {
            return false;
        }
        return $this->pdo->commit();
    }

    /**
     * @see http://php.net/manual/en/function.pdo-exec.php
     */
    public function exec($query) {
        if (!$this->connect(null, array(xPDO::OPT_CONN_MUTABLE => true))) {
            return false;
        }
        $tstart= microtime(true);
        $return= $this->pdo->exec($query);

        if ($return === false) {
            $this->logFailedQuery($query, null,microtime(true) - $tstart, $this->pdo->errorCode(), $this->pdo->errorInfo());
            $this->log(xPDO::LOG_LEVEL_ERROR, "Error " . $this->pdo->errorCode() . " executing statement: \n" . print_r($this->pdo->errorInfo(), true), '', __METHOD__, __FILE__, __LINE__);
        } else {
            $this->logSuccessfulQuery($query, null,microtime(true) - $tstart);
        }

        return $return;
    }

    /**
     * @see http://php.net/manual/en/function.pdo-errorcode.php
     */
    public function errorCode() {
        if (!$this->connect()) {
            return false;
        }
        return $this->pdo->errorCode();
    }

    /**
     * @see http://php.net/manual/en/function.pdo-errorinfo.php
     */
    public function errorInfo() {
        if (!$this->connect()) {
            return false;
        }
        return $this->pdo->errorInfo();
    }

    /**
     * @see http://php.net/manual/en/function.pdo-getattribute.php
     */
    public function getAttribute($attribute) {
        if (!$this->connect()) {
            return false;
        }
        return $this->pdo->getAttribute($attribute);
    }

    /**
     * @see http://php.net/manual/en/function.pdo-lastinsertid.php
     */
    public function lastInsertId() {
        if (!$this->connect()) {
            return false;
        }
        return $this->pdo->lastInsertId();
    }

    /**
     * @see http://php.net/manual/en/function.pdo-prepare.php
     */
    public function prepare($statement, $driver_options= array ()) {
        if (!$this->connect()) {
            return false;
        }
        return $this->pdo->prepare($statement, $driver_options);
    }

    /**
     * @see http://php.net/manual/en/function.pdo-query.php
     */
    public function query($query) {
        if (!$this->connect()) {
            return false;
        }
        $tstart= microtime(true);
        $return= $this->pdo->query($query);

        if ($return === false) {
            $this->logFailedQuery($query, null,microtime(true) - $tstart, $this->pdo->errorCode(), $this->pdo->errorInfo());
            $this->log(xPDO::LOG_LEVEL_ERROR, "Error " . $this->pdo->errorCode() . " executing statement: \n" . print_r($this->pdo->errorInfo(), true), '', __METHOD__, __FILE__, __LINE__);
        } else {
            $this->logSuccessfulQuery($query, null,microtime(true) - $tstart);
        }

        return $return;
    }

    /**
     * @see http://php.net/manual/en/function.pdo-quote.php
     */
    public function quote($string, $parameter_type= \PDO::PARAM_STR) {
        if (!$this->connect()) {
            return false;
        }
        $quoted = $this->pdo->quote($string, $parameter_type);
        switch ($parameter_type) {
            case \PDO::PARAM_STR:
                $quoted = trim($quoted);
                break;
            case \PDO::PARAM_INT:
                $quoted = trim($quoted);
                $quoted = (integer) trim($quoted, "'");
                break;
            default:
                break;
        }
        return $quoted;
    }

    /**
     * @see http://php.net/manual/en/function.pdo-rollback.php
     */
    public function rollBack() {
        if (!$this->connect(null, array(xPDO::OPT_CONN_MUTABLE => true))) {
            return false;
        }
        return $this->pdo->rollBack();
    }

    /**
     * @see http://php.net/manual/en/function.pdo-setattribute.php
     */
    public function setAttribute($attribute, $value) {
        if (!$this->connect()) {
            return false;
        }
        return $this->pdo->setAttribute($attribute, $value);
    }

    /**
     * Creates an new xPDOQuery for a specified xPDOObject class.
     *
     * @param string $class The class to create the xPDOQuery for.
     * @param mixed $criteria Any valid xPDO criteria expression.
     * @param boolean|integer $cacheFlag Indicates if the result should be cached
     * and optionally for how many seconds (if passed an integer greater than 0).
     * @return Om\xPDOQuery The resulting xPDOQuery instance or false if unsuccessful.
     */
    public function newQuery($class, $criteria= null, $cacheFlag= true) {
        $xpdoQueryClass= '\\xPDO\\Om\\' . $this->config['dbtype'] . '\\xPDOQuery';
        if ($query= new $xpdoQueryClass($this, $class, $criteria)) {
            $query->cacheFlag= $cacheFlag;
        }
        return $query;
    }

    /**
     * Splits a string on a specified character, ignoring escaped content.
     *
     * @static
     * @param string $char A character to split the tag content on.
     * @param string $str The string to operate on.
     * @param string $escToken A character used to surround escaped content; all
     * content within a pair of these tokens will be ignored by the split
     * operation.
     * @param integer $limit Limit the number of results. Default is 0 which is
     * no limit. Note that setting the limit to 1 will only return the content
     * up to the first instance of the split character and will discard the
     * remainder of the string.
     * @return array An array of results from the split operation, or an empty
     * array.
     */
    public static function escSplit($char, $str, $escToken = '`', $limit = 0) {
        $split= array();
        $charPos = strpos($str, $char);
        if ($charPos !== false) {
            if ($charPos === 0) {
                $searchPos = 1;
                $startPos = 1;
            } else {
                $searchPos = 0;
                $startPos = 0;
            }
            $escOpen = false;
            $strlen = strlen($str);
            for ($i = $startPos; $i <= $strlen; $i++) {
                if ($i == $strlen) {
                    $tmp= trim(substr($str, $searchPos));
                    if (!empty($tmp)) $split[]= $tmp;
                    break;
                }
                if ($str[$i] == $escToken) {
                    $escOpen = $escOpen == true ? false : true;
                    continue;
                }
                if (!$escOpen && $str[$i] == $char) {
                    $tmp= trim(substr($str, $searchPos, $i - $searchPos));
                    if (!empty($tmp)) {
                        $split[]= $tmp;
                        if ($limit > 0 && count($split) >= $limit) {
                            break;
                        }
                    }
                    $searchPos = $i + 1;
                }
            }
        } else {
            $split[]= trim($str);
        }
        return $split;
    }

    /**
     * Parses parameter bindings in SQL prepared statements.
     *
     * @param string $sql A SQL prepared statement to parse bindings in.
     * @param array $bindings An array of parameter bindings to use for the replacements.
     * @return string The SQL with the binding placeholders replaced.
     */
    public function parseBindings($sql, $bindings) {
        if (!empty($sql) && !empty($bindings)) {
            $bound = array();
            foreach ($bindings as $k => $param) {
                if (!is_array($param)) {
                    $v= $param;
                    $type= $this->getPDOType($param);
                    $bindings[$k]= array(
                        'value' => $v,
                        'type' => $type
                    );
                } else {
                    $v= $param['value'];
                    $type= $param['type'];
                }
                if (!$v) {
                    switch ($type) {
                        case \PDO::PARAM_INT:
                            $v= '0';
                            break;
                        case \PDO::PARAM_BOOL:
                            $v= '0';
                            break;
                        default:
                            break;
                    }
                }
                if ($type > 0) {
                    $v= $this->quote($v, $type);
                } else {
                    $v= 'NULL';
                }
                if (!is_int($k) || substr($k, 0, 1) === ':') {
                    $pattern= '/' . $k . '\b/';
                    $bound[$pattern] = str_replace(array('\\', '$'), array('\\\\', '\$'), $v);
                } else {
                    $pattern = '/(\?)(\b)?/';
                    $sql = preg_replace($pattern, ':' . $k . '$2', $sql, 1);
                    $bound['/:' . $k . '\b/'] = str_replace(array('\\', '$'), array('\\\\', '\$'), $v);
                }
            }
            if ($this->getDebug() === true) {
                $this->log(xPDO::LOG_LEVEL_DEBUG, "{$sql}\n" . print_r($bound, true));
            }
            if (!empty($bound)) {
                $sql= preg_replace(array_keys($bound), array_values($bound), $sql);
            }
        }
        return $sql;
    }

    /**
     * Get the appropriate PDO::PARAM_ type constant from a PHP value.
     *
     * @param mixed $value Any PHP scalar or null value
     * @return int|null
     */
    public function getPDOType($value) {
        $type= null;
        if (is_null($value)) $type= \PDO::PARAM_NULL;
        elseif (is_scalar($value)) {
            if (is_int($value)) $type= \PDO::PARAM_INT;
            else $type= \PDO::PARAM_STR;
        }
        return $type;
    }

    /**
     * Sanitize criteria expected to represent primary key values.
     *
     * @param string $className The name of the class.
     * @param mixed  &$criteria A reference to the criteria being used.
     */
    protected function sanitizePKCriteria($className, &$criteria) {
        if (is_scalar($criteria)) {
            $pkType = $this->getPKType($className);
            if (is_string($pkType)) {
                $pk = $this->getPK($className);
                switch ($pkType) {
                    case 'int':
                    case 'integer':
                        if (!is_int($criteria) && (string)(int)$criteria !== (string)$criteria) {
                            $criteria = [$pk => null];
                            break;
                        }
                        $criteria = [$pk => (int)$criteria];
                        break;
                    case 'string':
                        $criteria = [$pk => (string)$criteria];
                        break;
                }
            } elseif (is_array($pkType)) {
                $criteria = null;
            }
        }
    }

    public function logSuccessfulQuery($sql, $params, $time)
    {
        $this->executedQueries++;
        $this->queryTime += $time;

        if ($this->getProfiler()) {
            $this->successfulQueries[] = array(
                'sql' => $sql,
                'params' => $this->extractBindings($params),
                'time' => $time
            );
        }
    }

    public function logFailedQuery($sql, $params, $time, $errorCode, $errorInfo)
    {
        $this->executedQueries++;
        $this->queryTime += $time;

        if ($this->getProfiler()) {
            $this->failedQueries[] = array(
                'sql' => $sql,
                'params' => $this->extractBindings($params),
                'time' => $time,
                'errorCode' => $errorCode,
                'errorInfo' => $errorInfo
            );
        }
    }

    private function extractBindings($params)
    {
        $return = [];

        if (!$params) {
            $params = array();
        }

        foreach($params as $key => $value) {
            $return[] = is_array($value) ? $value['value'] : $value;
        }

        return $return;
    }

    /**
     * @return bool
     */
    public function getProfiler(): bool
    {
        return $this->profiler;
    }

    /**
     * Sets the profiler state for the xPDO instance.
     *
     * @param bool $profiler
     */
    public function setProfiler(bool $profiler): void
    {
        $this->profiler = $profiler;
    }
}
