<?php

namespace Hatimeria\ExtJSBundle\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
use \DateTime;
use Hatimeria\ExtJSBundle\Exception\ExtJSException;

/**
 * Collection or Pager to Array conversion
 *
 * @author Michal Wujas
 */
class Dumper
{

    private $em;

    /**
     * Signed user is an admin ?
     *
     * @var bool
     */
    private $isAdmin;

    /**
     * Camelizer
     *
     * @var Camelizer
     */
    private $camelizer;

    /**
     * Reflection class objects
     *
     * @var array of \ReflectionClass Objects
     */
    private $reflections;

    /**
     * Map of access methods for object and property (getter, isser or property)
     *
     * @var array
     */
    private $accessMethods;

    /**
     * Configured mappings for classes
     *
     * @var array
     */
    private $mappings;

    public function __construct($em, $security, $camelizer, $mappings)
    {
        $this->isAdmin = $security->getToken() ? $security->isGranted('ROLE_ADMIN') : false;
        $this->em = $em;
        $this->camelizer = $camelizer;
        $this->mappings = $mappings;
    }

    private function hasMapping($entityName)
    {
        return isset($this->mappings[$entityName]);
    }

    private function getMappingFields($entityName)
    {
        return $this->mappings[$entityName]['fields']['default'];
    }
    
    public function getObjectMappingFields($object)
    {
        $class = $this->getClass($entity);

        if (!$this->hasMapping($class)) {
            throw new ExtJSException(sprintf("No dumper method for: %s", $class));
        }

        return $this->getMappingFields($class);
    }    

    /**
     * Convert array or array collection to ext js array used for store source
     *
     * @param array Array collection or array of entities $entities
     * @param int $count
     * @param int $limit
     *
     * @return array
     */
    public function dumpPager(Pager $pager)
    {
        if ($pager->hasToStoreFunction()) {
            return $this->dump($pager->getEntities(), $pager->getCount(), $pager->getLimit(), $pager->getToStoreFunction());
        } elseif ($pager->hasFields() || $this->hasMapping($pager->getEntityName())) {
            $fields = $pager->getFields();
            $records = array();

            foreach ($pager->getEntities() as $entity) {
                $records[] = $this->getValues($entity, $fields);
            }

            return $this->getResult($records, $pager->getCount(), $pager->getLimit());
        }
        
        throw new ExtJSException(sprintf(
                        "No toStoreFunction given or mappings configured for entity %s", $pager->getEntityName()));
    }

    /**
     * Dumps collection without limit
     *
     * @param array $entities
     * @param Closure $toStoreFunction
     * @return array
     */
    public function dumpCollection($entities, $toStoreFunction = null)
    {
        return $this->dump($entities, count($entities), 0, $toStoreFunction);
    }

    /**
     * Dumps collection with limit
     *
     * @param array $entities
     * @param int $count
     * @param int $limit
     * @param Closure $toStoreFunction
     * 
     * @return array
     */
    private function dump($entities, $count = null, $limit = null, $toStoreFunction = null)
    {
        $records = array();

        if (!empty($entities)) {

            foreach ($entities as $entity) {
                $records[] = $toStoreFunction($entity);
            }
        }

        return $this->getResult($records, $count, $limit);
    }

    /**
     * Pager dump result in ExtJS format
     *
     * @param array $records
     * @param int $count
     * @param int $limit
     * @return array 
     */
    private function getResult($records, $count, $limit)
    {
        return array(
            'records' => $records,
            'success' => true,
            'total' => $count,
            'start' => 0,
            'limit' => $limit
        );
    }

    /**
     * How is accessed object property? by getter, isser or public property
     * Code from Symfony Form Component used - class PropertyPath
     *
     * @param Object $object
     * @param string $name
     * @return mixed array(methodName) or propertyName
     */
    private function getPropertyAccessMethod($object, $name)
    {
        $class = get_class($object);
        if (!isset($this->reflections[$class])) {
            $this->reflections[$class] = new \ReflectionClass($object);
        }

        $reflClass = $this->reflections[$class];
        $camelProp = $this->camelizer->camelize($name);
        $property = $camelProp;
        $getter = 'get' . $camelProp;
        $isser = 'is' . $camelProp;

        if ($reflClass->hasMethod($getter)) {
            if (!$reflClass->getMethod($getter)->isPublic()) {
                throw new ExtJSException(sprintf('Method "%s()" is not public in class "%s"', $getter, $reflClass->getName()));
            }

            return array($getter);
        } else if ($reflClass->hasMethod($isser)) {
            if (!$reflClass->getMethod($isser)->isPublic()) {
                throw new ExtJSException(sprintf('Method "%s()" is not public in class "%s"', $isser, $reflClass->getName()));
            }

            return array($isser);
        } else if ($reflClass->hasMethod('__get')) {
            // needed to support magic method __get
            return $object->$property;
        } else if ($reflClass->hasProperty($property)) {
            if (!$reflClass->getProperty($property)->isPublic()) {
                throw new ExtJSException(sprintf('Property "%s" is not public in class "%s". Maybe you should create the method "%s()" or "%s()"?', $property, $reflClass->getName(), $getter, $isser));
            }

            return $property;
        } else if (property_exists($object, $property)) {
            // needed to support \stdClass instances
            return $property;
        }

        throw new ExtJSException(sprintf('Neither property "%s" nor method "%s()" nor method "%s()" exists in class "%s"', $property, $getter, $isser, $reflClass->getName()));
    }

    /**
     * Property value for given property name
     *
     * @example getPropertyValue($user, name)
     *  
     * @param Object $object
     * @param string $name
     * @return mixed 
     */
    private function getPropertyValue($object, $name)
    {
        $key = get_class($object) . $name;

        if (!isset($this->accessMethods[$key])) {
            $this->accessMethods[$key] = $this->getPropertyAccessMethod($object, $name);
        }

        $method = $this->accessMethods[$key];

        if (is_array($method)) {
            return $object->$method[0]();
        } else {
            return $object->$method;
        }
    }

    /**
     * Object value for given path 
     *
     * @param Object $object 
     * @param string $path
     * @return mixed
     */
    private function getPathValue($object, $path)
    {
        if (strpos($path, '.')) {
            $names = explode('.', $path);
            $property = array_shift($names);
            $value = $this->getPropertyValue($object, $property);

            return $this->getPathValue($value, implode('.', $names));
        }

        return $this->getPropertyValue($object, $path);
    }

    private function getClass($entity)
    {
        if ($entity instanceof \Doctrine\ORM\Proxy\Proxy) {
            return get_parent_class($entity);
        } else {
            return get_class($entity);
        }
    }

    /**
     * Object values for list of properties (fields)
     *
     * @param Object $entity
     * @param array $fields
     * @return array
     */
    public function getValues($entity, $fields = array())
    {
        $values = array();

        if (count($fields) == 0) {
            $fields = $this->getObjectMappingFields($entity);
        }

        foreach ($fields as $path) {
            $value = $this->getPathValue($entity, $path);

            if (is_object($value)) {
                if ($value instanceof DateTime) {
                    $value = $value->format('Y-m-d');
                } else if ($value instanceof Doctrine\Common\Collections\ArrayCollection) {
                    $records = array();

                    foreach ($value as $entity) {
                        $records[] = $this->getValues($value);
                    }

                    $value = $records;
                } else {
                    $value = $this->getValues($value);
                }
            }

            $values[$path] = $value;
        }

        return $values;
    }

}