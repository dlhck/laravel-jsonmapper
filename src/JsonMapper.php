<?php

namespace DavidHoeck\LaravelJsonMapper;

use DavidHoeck\LaravelJsonMapper\Exceptions\JsonMapperException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * Maps decoded JSON Object on to a class
 * Based on the Project from @cweiske on GitHub
 * @package  JsonMapper
 * @author   David Höck <david.hoeck@womensbest.com>
 * @license  OSL-3.0 http://opensource.org/licenses/osl-3.0
 * @link     http://github.com/wbdavidhoeck/laravel-jsonmapper
 */
class JsonMapper
{
    /**
     * PSR-3 Logger object
     * @var  object
     */
    protected $logger;

    /**
     * Throw an exception when JSON data contain a property
     * that is not defined in the PHP class
     *
     * @var boolean
     */
    public $exceptionWhenUndefinedProperty = false;

    /**
     * Throw an exception if the JSON data miss a property
     * that is marked with @required in the PHP class
     *
     * @var boolean
     */
    public $exceptionWhenMissingData = false;

    /**
     * If the types of map() parameters shall be checked
     * @var boolean
     */
    public $enforceMapType = true;

    /**
     * Throw an exception when an object is expected but the JSON contains
     * a non-object type.
     *
     * @var boolean
     */
    public $strictObjectTypeChecking = false;

    /**
     * Throw an exception, if null value is found
     * but the type of attribute does not allow nulls.
     *
     * @var bool
     */
    public $exceptionWhenNullType = true;

    /**
     * Mapping of private and protected properties.
     *
     * @var boolean
     */
    public $ignoreVisibility = false;

    /**
     * Override class names that JsonMapper uses to create objects.
     * Useful when your setter methods accept abstract classes or interfaces.
     *
     * @var array
     */
    public $overrideClassMap = array();

    /**
     * Callback used when an undefined property is found.
     *
     * @var callable
     */
    public $undefinedPropertyHandler = null;

    /**
     * Runtime cache for inspected classes. This is particularly effective if
     * mapArray() is called with a large number of objects
     *
     * @var array property inspection result cache
     */
    protected $inspectedClasses = array();

    /**
     * @param $json object
     * @param $classObject object
     * @return mixed
     * @throws JsonMapperException
     */
    public function map($json, $classObject)
    {
        //Check if $json is an object
        if ($this->enforceMapType && !is_object($json)) {
            throw new InvalidArgumentException(
                'JsonMapper::map() requires first argument to be an object' . ', ' . gettype($json) . ' given.'
            );
        }

        //If $json is a string, automatically decode it
        if( is_string( $json ) ){
            $json = json_decode( $json );
        }

        //Check if $className is a string
        if (! is_object( $classObject)) {
            throw new InvalidArgumentException(
                'Function map() requires second argument to be a string ' . ', ' . gettype($classObject) . ' given.'
            );
        }

        $object = $classObject;
        $strClassName = get_class($object);
        $rc = new ReflectionClass($object);
        $strNs = $rc->getNamespaceName();
        $providedProperties = array();


        foreach ($json as $jsonKey => $jsonValue) {
            $key = $this->getSafeName($jsonKey);
            $providedProperties[$jsonKey] = true;

            //Store inspected value
            if (!isset($this->inspectedClasses[$strClassName][$jsonKey])) {
                $this->inspectedClasses[$strClassName][$jsonKey]
                    = $this->inspectProperty($rc, $jsonKey);
            }

            list($hasProperty, $accessor, $type)
                = $this->inspectedClasses[$strClassName][$jsonKey];

            //Check if property in class exists
            //If not, throw exception
            if (!$hasProperty) {
                if ($this->exceptionWhenUndefinedProperty) {
                    throw new JsonMapperException(
                        'JSON property "' . $key . '" does not exist'
                        . ' in object of type ' . $strClassName
                    );
                } else if ($this->undefinedPropertyHandler !== null) {
                    call_user_func(
                        $this->undefinedPropertyHandler,
                        $object, $jsonKey, $jsonValue
                    );
                } else {
                    $this->log(
                        'info',
                        'Property {property} does not exist in {class}',
                        array('property' => $jsonKey, 'class' => $strClassName)
                    );
                }
                continue;
            }

            if ($accessor === null) {
                if ($this->exceptionWhenUndefinedProperty) {
                    throw new JsonMapperException(
                        'JSON property "' . $jsonKey . '" has no public setter method' . ' in object of type ' . $strClassName
                    );
                }
                $this->log(
                    'info',
                    'Property {property} has no public setter method in {class}',
                    array('property' => $jsonKey, 'class' => $strClassName)
                );
                continue;
            }

            if ($this->isNullable($type) || !$this->exceptionWhenNullType) {
                if ($jsonValue === null) {
                    $this->setProperty($object, $accessor, null);
                    continue;
                }
                $type = $this->removeNullable($type);
            } else if ($jsonValue === null) {
                throw new JsonMapperException(
                    'JSON property "' . $key . '" in class "' . $strClassName . '" must not be NULL'
                );
            }

            if ($type === null || $type === 'mixed') {
                //no given type - simply set the json data
                $this->setProperty($object, $accessor, $jsonValue);
                continue;
            } else if ($this->isObjectOfSameType($type, $jsonValue)) {
                $this->setProperty($object, $accessor, $jsonValue);
                continue;
            } else if ($this->isSimpleType($type)) {
                settype($jsonValue, $type);
                $this->setProperty($object, $accessor, $jsonValue);
                continue;
            }

            //Check if type exists, give detailled error message if not
            if ($type === '') {
                throw new JsonMapperException(
                    'Empty type at property "' . $strClassName . '::$' . $jsonKey . '"'
                );
            }

            $array = null;
            $subtype = null;

            if (substr($type, -2) == '[]') {
                //array
                $array = array();
                $subtype = substr($type, 0, -2);

            } else if (substr($type, -1) == ']') {

                list($proptype, $subtype) = explode('[', substr($type, 0, -1));

                if (!$this->isSimpleType($proptype)) {
                    $proptype = $this->getFullNamespace($proptype, $strNs);
                }

                if ($proptype == 'array') {
                    $array = array();
                } else {
                    $array = $this->createInstance($proptype);
                }
            } else if ($type == 'ArrayObject'
                || is_subclass_of($type, 'ArrayObject')
            ) {
                $array = $this->createInstance($type);
            }

            if ($array !== null) {
                if (!is_array($jsonValue) && $this->isFlatType(gettype($jsonValue))) {
                    throw new JsonMapperException(
                        'JSON property "' . $jsonKey . '" must be an array, '
                        . gettype($jsonValue) . ' given'
                    );
                }

                $cleanSubtype = $this->removeNullable($subtype);
                if (!$this->isSimpleType($cleanSubtype)) {
                    $subtype = $this->getFullNamespace($cleanSubtype, $strNs);
                }
                $child = $this->mapArray($jsonValue, $array, $subtype);
            } else if ($this->isFlatType(gettype($jsonValue))) {
                //use constructor parameter if we have a class
                // but only a flat type (i.e. string, int)
                if ($this->strictObjectTypeChecking) {
                    throw new JsonMapperException(
                        'JSON property "' . $jsonKey . '" must be an object, ' . gettype($jsonKey) . ' given'
                    );
                }
                $type = $this->getFullNamespace($type, $strNs);
                $child = $this->createInstance($type, true, $jsonValue);
            } else {
                $type = $this->getFullNamespace($type, $strNs);
                $child = $this->createInstance($type);
                $this->map($jsonValue, $child);
            }
            $this->setProperty($object, $accessor, $child);
        }

        if ($this->exceptionWhenMissingData) {
            $this->checkMissingData($providedProperties, $rc);
        }

        return $object;
    }

    /**
     * Convert a type name to a fully namespaced type name.
     *
     * @param string $type  Type name (simple type or class name)
     * @param string $strNs Base namespace that gets prepended to the type name
     *
     * @return string Fully-qualified type name with namespace
     */
    protected function getFullNamespace($type, $strNs)
    {
        if ($type !== '' && substr($type, 0, 1) != '\\') {
            //create a full qualified namespace
            if ($strNs != '') {
                $type = '\\' . $strNs . '\\' . $type;
            }
        }
        return $type;
    }

    /**
     * Check if required property exists in data
     * @param $providedProperties
     * @param ReflectionClass $rc
     * @throws JsonMapperException
     */
    protected function checkMissingData($providedProperties, ReflectionClass $rc)
    {
        foreach ($rc->getProperties() as $property) {
            $rprop = $rc->getProperty($property->name);
            $docblock = $rprop->getDocComment();
            $annotations = $this->parseAnnotations($docblock);
            if (isset($annotations['required'])
                && !isset($providedProperties[$property->name])
            ) {
                throw new JsonMapperException(
                    'Required property "' . $property->name . '" of class '
                    . $rc->getName()
                    . ' is missing in JSON data'
                );
            }
        }
    }

    /**
     * Map an Array to an Object
     * @param $json array Decoded JSON array
     * @param $array array Empty Array
     * @param null $class string Object to map
     * @return mixed
     * @throws JsonMapperException
     */
    public function mapArray($json, $array, $class = null){
        foreach ($json as $jsonKey => $jsonValue) {
            $jsonKey = $this->getSafeName($jsonKey);
            if ($class === null) {
                $array[$jsonKey] = $jsonValue;
            } else if ($this->isFlatType(gettype($jsonValue))) {
                //use constructor parameter if we have a class
                // but only a flat type (i.e. string, int)
                if ($jsonValue === null) {
                    $array[$jsonKey] = null;
                } else {
                    if ($this->isSimpleType($class)) {
                        settype($jsonValue, $class);
                        $array[$jsonKey] = $jsonValue;
                    } else {
                        $array[$jsonKey] = $this->createInstance(
                            $class, true, $jsonValue
                        );
                    }
                }
            } else {
                $array[$jsonKey] = $this->map(
                    $jsonValue, $this->createInstance($class)
                );
            }
        }
        return $array;
    }

    /**
     * Check if property exists
     * @param ReflectionClass $rc object
     * @param $name string
     * @return array
     */
    protected function inspectProperty(ReflectionClass $rc, $name)
    {
        //try setter method first
        $setter = 'set' . $this->getCamelCaseName($name);

        if ($rc->hasMethod($setter)) {
            $rmeth = $rc->getMethod($setter);
            if ($rmeth->isPublic() || $this->ignoreVisibility) {
                $rparams = $rmeth->getParameters();
                if (count($rparams) > 0) {
                    $pclass = $rparams[0]->getClass();
                    $nullability = '';
                    if ($rparams[0]->isOptional()) {
                        if ($rparams[0]->getDefaultValue() === null) {
                            $nullability = '|null';
                        }
                    }
                    if ($pclass !== null) {
                        return array(
                            true, $rmeth,
                            '\\' . $pclass->getName() . $nullability
                        );
                    }
                }

                $docblock    = $rmeth->getDocComment();
                $annotations = $this->parseAnnotations($docblock);

                if (!isset($annotations['param'][0])) {
                    return array(true, $rmeth, null);
                }
                list($type) = explode(' ', trim($annotations['param'][0]));
                return array(true, $rmeth, $type);
            }
        }

        //now try to set the property directly
        if ($rc->hasProperty($name)) {
            $rprop = $rc->getProperty($name);
        } else {
            //case-insensitive property matching
            $rprop = null;
            foreach ($rc->getProperties() as $p) {
                if ((strcasecmp($p->name, $name) === 0)) {
                    $rprop = $p;
                    break;
                }
            }
        }
        if ($rprop !== null) {
            if ($rprop->isPublic() || $this->ignoreVisibility) {
                $docblock    = $rprop->getDocComment();
                $annotations = $this->parseAnnotations($docblock);

                if (!isset($annotations['var'][0])) {
                    return array(true, $rprop, null);
                }

                //support "@var type description"
                list($type) = explode(' ', $annotations['var'][0]);

                return array(true, $rprop, $type);
            } else {
                //no setter, private property
                return array(true, null, null);
            }
        }

        //no setter, no property
        return array(false, null, null);
    }

    /**
     * Removes - and _ and makes the next letter uppercase
     *
     * @param string $name Property name
     *
     * @return string CamelCasedVariableName
     */
    protected function getCamelCaseName($name)
    {
        return str_replace(
            ' ', '', ucwords(str_replace(array('_', '-'), ' ', $name))
        );
    }

    /**
     * Since hyphens cannot be used in variables we have to uppercase them.
     *
     * Technically you may use them, but they are awkward to access.
     *
     * @param string $name Property name
     *
     * @return string Name without hyphen
     */
    protected function getSafeName($name)
    {
        if (strpos($name, '-') !== false) {
            $name = $this->getCamelCaseName($name);
        }

        return $name;
    }

    /**
     * Set a property on a given object to a given value.
     *
     * Checks if the setter or the property are public are made before
     * calling this method.
     *
     * @param object $object   Object to set property on
     * @param object $accessor ReflectionMethod or ReflectionProperty
     * @param mixed  $value    Value of property
     *
     * @return void
     */
    protected function setProperty( $object, $accessor, $value) {
        if (!$accessor->isPublic() && $this->ignoreVisibility) {
            $accessor->setAccessible(true);
        }
        if ($accessor instanceof ReflectionProperty) {
            $accessor->setValue($object, $value);
        } else {
            //setter method
            $accessor->invoke($object, $value);
        }
    }

    /**
     * Create new instance of $class name
     *
     * @param $class
     * @param bool $useParameter
     * @param null $parameter
     * @return mixed
     */
    public function createInstance( $class, $useParameter = false, $parameter = null) {
        if (isset($this->overrideClassMap[$class])) {
            $class = $this->overrideClassMap[$class];
        }
        if ($useParameter) {
            return new $class($parameter);
        } else {
            return new $class();
        }
    }

    /**
     * Checks if the given type is a "simple type"
     *
     * @param string $type type name from gettype()
     *
     * @return boolean True if it is a simple PHP type
     *
     * @see isFlatType()
     */
    protected function isSimpleType($type){
        return $type == 'string'
        || $type == 'boolean' || $type == 'bool'
        || $type == 'integer' || $type == 'int'
        || $type == 'double' || $type == 'float'
        || $type == 'array' || $type == 'object';
    }

    /**
     * Checks if the object is of this type or has this type as one of its parents
     *
     * @param string $type  class name of type being required
     * @param mixed  $value Some PHP value to be tested
     *
     * @return boolean True if $object has type of $type
     */
    protected function isObjectOfSameType($type, $value)
    {
        if (false === is_object($value)) {
            return false;
        }

        return is_a($value, $type);
    }

    /**
     * Checks if the given type is a type that is not nested
     * (simple type except array and object)
     *
     * @param string $type type name from gettype()
     *
     * @return boolean True if it is a non-nested PHP type
     *
     * @see isSimpleType()
     */
    protected function isFlatType($type)
    {
        return $type == 'NULL'
        || $type == 'string'
        || $type == 'boolean' || $type == 'bool'
        || $type == 'integer' || $type == 'int'
        || $type == 'double' || $type == 'float';
    }

    /**
     * Checks if the given type is nullable
     *
     * @param string $type type name from the phpdoc param
     *
     * @return boolean True if it is nullable
     */
    protected function isNullable($type)
    {
        return stripos('|' . $type . '|', '|null|') !== false;
    }

    /**
     * Remove the 'null' section of a type
     *
     * @param string $type type name from the phpdoc param
     *
     * @return string The new type value
     */
    protected function removeNullable($type)
    {
        if ($type === null) {
            return null;
        }
        return substr(
            str_ireplace('|null|', '|', '|' . $type . '|'),
            1, -1
        );
    }

    /**
     * Copied from PHPUnit 3.7.29, Util/Test.php
     *
     * @param string $docblock Full method docblock
     *
     * @return array
     */
    protected static function parseAnnotations($docblock){
        $annotations = array();
        // Strip away the docblock header and footer
        // to ease parsing of one line annotations
        $docblock = substr($docblock, 3, -2);

        $re = '/@(?P<name>[A-Za-z_-]+)(?:[ \t]+(?P<value>.*?))?[ \t]*\r?$/m';
        if (preg_match_all($re, $docblock, $matches)) {
            $numMatches = count($matches[0]);

            for ($i = 0; $i < $numMatches; ++$i) {
                $annotations[$matches['name'][$i]][] = $matches['value'][$i];
            }
        }

        return $annotations;
    }

    /**
     * Log a message to the $logger object
     *
     * @param string $level   Logging level
     * @param string $message Text to log
     * @param array  $context Additional information
     *
     * @return null
     */
    protected function log($level, $message, array $context = array())
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Sets a logger instance on the object
     *
     * @param LoggerInterface $logger PSR-3 compatible logger object
     *
     * @return null
     */
    public function setLogger($logger){
        $this->logger = $logger;
    }
}
?>