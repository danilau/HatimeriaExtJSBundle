<?php

namespace Hatimeria\ExtJSBundle\Doctrine;

use DoctrineExtensions\Paginate\Paginate;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Common\Collections\ArrayCollection;
use Hatimeria\ExtJSBundle\Parameter\ParameterBag;
use Closure;

class Pager
{

    /**
     * Constructor.
     *
     * @param EntityManager           $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * Paginated resultset in ext direct format
     *
     * @param Query $query
     *
     * @return array data in ext direct format
     */
    public function getResults($entity, ParameterBag $params = null, array $mapping = array(), $filter = null, $toStore = null)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->add('select', 'e');
        $qb->add('from', $entity . ' e');

        if ($filter != null) {
            $filter($qb);
        }

        if ($params->has('sort')) {
            $sort = $params['sort'][0];

            // change birthday_at to birthdayAt
            // @todo move to util class
            $column = lcfirst(preg_replace('/(^|_|-)+(.)/e', "strtoupper('\\2')", $sort['property']));

            if (isset($mapping[$column])) {
                $column = $mapping[$column];
            }

            $qb->add('orderBy', 'e.' . $column . ' ' . $sort['direction']);
        }

        $query = $qb->getQuery();
        $limit = $params->getInt('limit', 10);

        if ($params->has('page')) {
            $offset = ($params['page'] - 1) * $limit;
        } else {
            $offset = 0;
        }

        $count = Paginate::getTotalQueryResults($query);
        $paginateQuery = Paginate::getPaginateQuery($query, $offset, $limit);
        $entities = $paginateQuery->getResult();

        return $this->collectionToArray($entities, $count, $limit, $toStore);
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
    public function collectionToArray($entities, $count = null, $limit = null, $toStore = null)
    {
        $records = array();

        foreach ($entities as $entity) {
            if (null !== $toStore) {
                $records[] = $toStore($entity);
            } else {
                $records[] = $entity->toStoreArray();
            }
        }

        if ($count == null) {
            $count = count($records);
        }

        return array(
            'records' => $records,
            'success' => true,
            'total' => $count,
            'start' => 0,
            'limit' => $limit ? $limit : 0
        );
    }

}