<?php

/**
 * This file is part of the Laravel Doctrine Repository package.
 *
 * @see http://github.com/fernandozueet/laravel-doctrine-repository
 *
 * @copyright 2018
 * @license MIT License
 * @author Fernando Zueet <fernandozueet@hotmail.com>
 */

namespace Ldr\Core;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/**
 * Doctrine Repository.
 */
abstract class DoctrineBaseRepository
{
    public function __construct($conn = 'default')
    {
        //get connection active
        $this->conn = app('registry')->getManager($conn);
        $this->nameConn = $conn;

        //serializer
        $this->instanceSerializer();

        //config
        $this->getConfigLdr();
    }

    /*-------------------------------------------------------------------------------------
     * GENERAL
     *-------------------------------------------------------------------------------------*/

    /**
     * Connecting to the data base.
     *
     * @var mixed
     */
    private $conn;

    /**
     * EntityManager.
     *
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * Query builder.
     *
     * @var \Doctrine\ORM\QueryBuilder
     */
    protected $queryBuilder;

    /**
     * Hint null.
     *
     * @var bool
     */
    private $hintNull = false;

    /**
     * Database used.
     *
     * @var string
     */
    private $databaseUsed = 'mysql';

    /**
     * Main entity namespace.
     *
     * @var string
     */
    protected $entityMain = '';

    /**
     * Entities alias.
     *
     * @var array
     */
    private $mainAlias = '';

    /**
     * Name of the connection to the configuration file database.
     *
     * @var string
     */
    private $nameConn = '';

    /**
     * Entities namespaces.
     *
     * @var object
     */
    protected $ent;

    /**
     * Foreign key entities names.
     *
     * @var array
     */
    private $fkNames = [];

    /**
     * Main name class.
     *
     * @var string
     */
    protected $mainNameEntity = '';

    /**
     * Configs.
     *
     * @var array
     */
    private $configLdr = [];

    /**
     * Return 
     *
     * @var string
     */
    protected $return = 'array';

    /**
     * Set return object
     *
     * @param string $return array | stdClass | doctrine
     * @return void
     */
    protected function setReturn(string $return)
    {
        $perm = ['array', 'stdClass', 'doctrine'];
        if (!in_array($return, $perm)) {
            $return = 'doctrine';
        }
        $this->return = $return;
    }

    /**
     * Set hint null.
     *
     * @param bool $hintNull
     */
    protected function setHintNull(bool $hintNull = true)
    {
        $this->hintNull = $hintNull;

        return $this;
    }

    /**
     * Execute.
     */
    protected function execute()
    {
        return $this->getQuery()->execute();
    }

    /**
     * Get configuration.
     */
    private function getConfiguration()
    {
        return $this->conn->getConfiguration();
    }

    /**
     * Get connection.
     */
    private function getConnection()
    {
        return $this->conn->getConnection();
    }

    /**
     * Control namespace and entities.
     *
     * @param array  $fkEntities
     * @param string $aliasDefault
     * @param string $classMain
     */
    protected function main(array $fkEntities, string $aliasDefault, string $classMain)
    {
        //Get main namespace entities
        if (!isset($this->configLdr['namespaceEntities'])) {
            throw new \Exception('The namespace declaration was not found in the config / doctrine.php file'); //not found namespace
        } else {
            $namespaceEntity = $this->configLdr['namespaceEntities']; //get main namespace
        }

        //Main entity class
        $entityClassMainExp = explode('\\', str_replace($this->configLdr['complementClassName'], '', $classMain)); //get entity name
        $this->mainNameEntity = end($entityClassMainExp); //get last position array
        $entityMain = "$namespaceEntity\\$this->mainNameEntity"; //mount complete namespace
        $this->entityMain = $entityMain; //add string main entity
        $this->ent[$this->mainNameEntity] = $entityMain; //add array entities
        $this->fkNames = $fkEntities; //add array kf entities

        //Foreign key entity class
        foreach ($fkEntities as $key => $value) {
            $this->ent[$value] = "$namespaceEntity\\$value"; //add fk array entities
        }

        //Turn into object
        $this->ent = (object) $this->ent;

        //Database used
        $this->databaseUsed = config('doctrine.managers')[$this->nameConn]['connection'];

        //Set default alias
        $this->mainAlias = $aliasDefault;
    }

    /**
     * Get config ldr.
     */
    private function getConfigLdr()
    {
        //namespace entitty
        if (isset(config('doctrine.managers')[$this->nameConn]['LdrConfig']['namespaceEntities'])) {
            $this->configLdr['namespaceEntities'] = config('doctrine.managers')[$this->nameConn]['LdrConfig']['namespaceEntities'];
        } else {
            throw new \Exception("Namespace configuration not found in config/doctrine.php file, declare in array entity manager: 'LdrConfig' => [ 'namespaceEntities' => 'App\Entities']");
        }

        //general configs
        if (isset(config('doctrine')['LdrConfig'])) {
            $this->configLdr = array_merge($this->configLdr, config('doctrine')['LdrConfig']);
        } else {
            throw new \Exception("Configuration not found in config/doctrine.php file, declare in array: 'LdrConfig' => [
                'complementClassName' => 'DocRepository',
                'createdAtFieldName' => 'createdAt',
                'updatedAtFieldName' => 'updatedAt',
                'indexNameResultsArray' => 'data',
                'indexNamePaginationArray' => 'meta',
                'return' => 'array'
            ]");
        }

        //set return default
        $this->setReturn(config('doctrine.LdrConfig.return')); 
    }

    /**
     * Set default namespace if not find.
     *
     * @param string $value
     *
     * @return string
     */
    private function defaultEntityNamespace(string $value = ''): string
    {
        if ($value) {
            if ($this->searchEnt($value)) {
                $value = $this->searchEnt($value);
            }
        } else {
            $value = $this->ent->{$this->mainNameEntity};
        }

        return $value;
    }

    /**
     * Get value from array ent.
     *
     * @param string $class
     */
    protected function searchEnt(string $class)
    {
        if (isset($this->ent->{$class})) {
            return $this->ent->{$class};
        }

        return '';
    }

    /**
     * Concatenate main alias with field.
     *
     * @param string $field
     * @param bool   $aliasMain
     *
     * @return string
     */
    private function aliasMainConcat(string $field, bool $aliasMain = true): string
    {
        if ($aliasMain) {
            return "$this->mainAlias.$field";
        }

        return $field;
    }

    /**
     * Validate mysql function.
     */
    private function validFunctionMysql()
    {
        $bla = $this->databaseUsed;
        if ($this->databaseUsed != 'mysql') {
            throw new \Exception('This function works only with mysql database.');
        }
    }

    /*-------------------------------------------------------------------------------------
     * TRANSACTION
     *-------------------------------------------------------------------------------------*/

    /**
     * Set transaction.
     *
     * @param [type] $conn
     */
    public function setTransaction($conn)
    {
        $this->conn = $conn;
    }

    /**
     * Begin transaction.
     */
    public function beginTransaction()
    {
        $this->getConnection()->beginTransaction();

        return $this->conn;
    }

    /**
     * Commit transaction.
     */
    public function commitTransaction()
    {
        $this->getConnection()->commit();
    }

    /**
     * Roll back transaction.
     */
    public function rollBackTransaction()
    {
        $this->getConnection()->rollBack();
    }

    /*-------------------------------------------------------------------------------------
     * NATIVE SQL
     *-------------------------------------------------------------------------------------*/

    /**
     * Native query.
     *
     * @param string $query
     */
    protected function createNativeQuery(string $query)
    {
        $this->em = $this->getConnection()->prepare($query);
    }

    /**
     * Set parameter native query.
     *
     * @param array $params
     */
    protected function setParameterNativeQuery(array $params)
    {
        $this->em->execute($params);
    }

    /**
     * Function that returns the output of nativeQquery.
     */
    protected function getResultNativeQuery()
    {
        $result = $this->em->fetchAll();
        $this->em = null;

        return $result;
    }

    /*-------------------------------------------------------------------------------------
     * DQL FUNCTIONS
     *-------------------------------------------------------------------------------------*/

    /**
     * Create Query Dql.
     *
     * @param string $value
     */
    protected function createQueryDql(string $value)
    {
        $this->setParamEm($this->conn->createQuery($value));
    }

    /**
     * Paginator Dql.
     *
     * @param int $firstResult
     * @param int $maxResults
     */
    protected function paginatorDql(int $firstResult, int $maxResults)
    {
        $this->setParamEm($this->em->setFirstResult($maxResults * $firstResult));
        $this->setParamEm($this->em->setMaxResults($maxResults));
    }

    /**
     * Limit amount of results.
     *
     * @param int $maxResults
     */
    protected function setMaxResultsDql(int $maxResults)
    {
        $this->setParamEm($this->em->setMaxResults($maxResults));
    }

    /**
     * Set parameter Dql.
     *
     * @param mixed $value1
     * @param mixed $value2
     * @param mixed $value3
     */
    protected function setParameterDql($value1, $value2, $value3 = null)
    {
        $this->setParamEm($this->em->setParameter($value1, $value2, $value3));
    }

    /**
     * Get result dql.
     *
     * @param string $typeTreat   excluded | included
     * @param array  $treatObject
     *
     * @return object
     */
    protected function getResultDql(string $typeTreat = '', array $treatObject = []): object
    {
        $em = $this->em;

        //set hint
        if (!$this->hintNull) {
            $em = $em->setHint(Query::HINT_FORCE_PARTIAL_LOAD, 1);
        }

        //query execute
        $response = $em->{$this->queryResultFormat}($this->resultHydration ?? Query::HYDRATE_OBJECT);
        if (count($response) > 0) {
            $result[$this->configLdr['indexNameResultsArray']] = $response;
        } else {
            return (object) [];
        }

        //add pagination
        if ($em->getFirstResult() !== null && count($result[$this->configLdr['indexNameResultsArray']]) > 0) {
            $result = $this->responsePaginator($result, $em);
        }

        //treat object
        if ($treatObject) {
            return $this->treatObject($result, $typeTreat, $treatObject);
        }

        //clear hydration
        $this->resultHydration = null;

        //return result without treat object
        return json_decode($this->serializer->serialize($result, 'json'));
    }

    /**
     * Get Dql.
     */
    protected function getDql()
    {
        return $this->getQuery()->getDql();
    }

    /*-------------------------------------------------------------------------------------
     * ENTITY
     *-------------------------------------------------------------------------------------*/

    /**
     * Automatically insert.
     *
     * @param array  $rule
     * @param array  $params
     * @param bool   $createdAt
     * @param string $typeTreat   excluded | included
     * @param array  $treatObject
     *
     * @return object
     */
    protected function setCreateArray(array $rule, array $params, $createdAt = true, string $typeTreat = '', array $treatObject = []): object
    {
        //instance main entity
        $entity = $this->searchEnt($this->mainNameEntity);
        $entity = new $entity();

        //set values entity
        $entity = $this->setEntityValuesArray($entity, $rule, $params, $createdAt, false);

        //execute
        $this->persist($entity);
        $this->flush();

        //treat object
        if ($treatObject) {
            return $this->treatObject($entity, $typeTreat, $treatObject);
        }

        return json_decode($this->serializer->serialize($entity, 'json'));
    }

    /**
     * Automatically edit.
     *
     * @param array  $rule
     * @param array  $params
     * @param [type] $entity
     * @param bool   $updateAt
     * @param string $typeTreat   excluded | included
     * @param array  $treatObject
     *
     * @return object
     */
    protected function setUpdateArray(array $rule, array $params, int $id, $updateAt = true, string $typeTreat = '', array $treatObject = []): object
    {
        //get entity
        $entity = $this->getRepository()->find($id);

        //entity null
        if (!isset($entity)) {
            return (object) [];
        }

        //set values entity
        $entity = $this->setEntityValuesArray($entity, $rule, $params, false, $updateAt);

        //execute
        $this->flush();

        //treat object
        if ($treatObject) {
            return $this->treatObject($entity, $typeTreat, $treatObject);
        }

        return json_decode($this->serializer->serialize($entity, 'json'));
    }

    /**
     * Set entity values array.
     *
     * @param [type] $entity
     * @param array  $rule
     * @param array  $params
     * @param bool   $createdAt
     * @param bool   $updatedAt
     */
    private function setEntityValuesArray($entity, array $rule, array $params, $createdAt = true, $updatedAt = true)
    {
        $set = true;
        $fk = false;
        $fk2 = false;
        if ($createdAt) {
            $setcreatedAt = ucfirst($this->configLdr['createdAtFieldName']);
            $setcreatedAt = "set$setcreatedAt";
            $entity->{$setcreatedAt}(new \DateTime('now'));
        }
        if ($updatedAt) {
            $setupdatedAt = ucfirst($this->configLdr['updatedAtFieldName']);
            $setupdatedAt = "set$setupdatedAt";
            $entity->{$setupdatedAt}(new \DateTime('now'));
        }
        foreach ($rule as $key => $value) {
            $exp = explode('|', $value);
            $fkP = explode(':', $exp[0]);

            $field = $exp[0];

            //foreign key
            if (isset($fkP[1])) {
                $fkPExp = explode('=', $fkP[1]);
                $fk2 = true;
                $field = $fkP[0];
                if (array_search(ucfirst($fkPExp[1]), $this->fkNames) === false) {
                    throw new \Exception('Incorrect foreign key name');
                }
            }

            //foreign key
            if (count($fkP) == 2 && $fkP[1] == 'fk') {
                $fk = true;
                $field = $fkP[0];
                if (array_search(ucfirst($fkP[0]), $this->fkNames) === false) {
                    throw new \Exception('Incorrect foreign key name');
                }
            }

            //mount method set
            $nameSet = ucfirst($field);
            $nameSet = "set{$nameSet}";
            if (isset($params[$field])) {
                $set = true;
            } else {
                $set = false;
            }

            //set values
            if ($set) {
                $value = $params[$field];
                if ($fk) {
                    $value = $this->getReference($this->ent->{ucfirst($fkP[0])}, $value);
                }
                if ($fk2) {
                    $value = $this->getReference($this->ent->{ucfirst($fkPExp[1])}, $value);
                }
                $entity->{$nameSet}($value);
                $fk = false;
                $fk2 = false;
            }
        }

        return $entity;
    }

    /**
     * Get repository.
     *
     * @param string $value
     */
    private function getRepository(string $value = '')
    {
        $value = $this->defaultEntityNamespace($value);

        return $this->setParamEm($this->conn->getRepository($value));
    }

    /**
     * Get find.
     *
     * @param string $class
     * @param int    $id
     */
    private function findEntity(string $class, int $id)
    {
        $class = $this->defaultEntityNamespace($class);

        return $this->conn->find($class, $id);
    }

    /**
     * Entity find.
     *
     * @param int    $id
     * @param string $typeTreat   excluded | included
     * @param array  $treatObject
     *
     * @return object
     */
    public function find(int $id, string $typeTreat = '', array $treatObject = []): object
    {
        $result = $this->findEntity('', $id);
        if (empty($result)) {
            return (object) [];
        }

        //treat object
        if ($treatObject) {
            return $this->treatObject($result, $typeTreat, $treatObject);
        }

        //return result without treat object
        return json_decode($this->serializer->serialize($result, 'json'));
    }

    /**
     * Get reference.
     *
     * @param string $class
     * @param [type] $id
     */
    protected function getReference(string $class, $id)
    {
        $class = $this->defaultEntityNamespace($class);

        return $this->conn->getReference($class, $id);
    }

    /**
     * Persist database.
     *
     * @param [type] $class
     */
    protected function persist($class)
    {
        return $this->conn->persist($class);
    }

    /**
     * Flush.
     */
    protected function flush()
    {
        return $this->conn->flush();
    }

    /**
     * Clear conn.
     */
    protected function clear()
    {
        $this->conn->clear();
    }

    /**
     * Set param entity manager.
     *
     * @param [type] $param
     */
    private function setParamEm($param)
    {
        $this->em = $param;

        return $this->em;
    }

    /*-------------------------------------------------------------------------------------
     * GENERAL QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Counter for params bind pdo.
     *
     * @var int
     */
    private $boundCounter = 0;

    /**
     * Set automatic params query builder.
     *
     * @param [type] $value
     * @param [type] $type
     * @param [type] $placeHolder
     */
    protected function createNamedParameter($value, $type = \PDO::PARAM_STR, $placeHolder = null)
    {
        if ($placeHolder === null) {
            $this->boundCounter = sizeof($this->queryBuilder->getParameters());
            ++$this->boundCounter;
            $placeHolder = ':dcValue'.$this->boundCounter;
        }
        $test = substr($placeHolder, 1);
        $this->queryBuilder->setParameter($test, $value, $type);

        return $placeHolder;
    }

    /**
     * Create query - select, update and delete.
     *
     * @param bool $setOldParams
     */
    public function createQuery()
    {
        return $this->setParamQp($this->conn->createQueryBuilder());
    }

    /**
     * Set query builder.
     *
     * @param [type] $query
     */
    public function setQuery($query)
    {
        $this->queryBuilder = $query;
    }

    /**
     * Get query.
     */
    public function getQuery()
    {
        return $this->queryBuilder->getQuery();
    }

    /**
     * Get DQL parts.
     */
    protected function getDQLParts()
    {
        return $this->queryBuilder->getDQLParts();
    }

    /**
     * Set params query builder.
     *
     * @param [type] $param
     *
     * @return QueryBuilder
     */
    private function setParamQp($param): QueryBuilder
    {
        $this->queryBuilder = $param;

        return $this->queryBuilder;
    }

    /*-------------------------------------------------------------------------------------
     * RESULT QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Method of return of results.
     *
     * @var string getResult | getArrayResult | getSingleResult | getOneOrNullResult | getScalarResult | getSingleScalarResult
     */
    private $queryResultFormat = 'getResult';

    /**
     * Function that brings the results of a select query.
     *
     * @param string $typeTreat   - excluded | included
     * @param array  $treatObject
     *
     * @return object
     */
    public function readQuery(string $typeTreat = '', array $treatObject = []): object
    {
        //get query
        $query = $this->getQuery();

        //set hint
        if (!$this->hintNull) {
            $query = $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, 1);
        }

        //query execute
        $response = $query->{$this->queryResultFormat}($this->resultHydration ?? Query::HYDRATE_OBJECT);
        if (count($response) > 0) {
            $result[$this->configLdr['indexNameResultsArray']] = $response;
        } else {
            return (object) [];
        }

        //add pagination
        if ($query->getFirstResult() !== null && count($result[$this->configLdr['indexNameResultsArray']]) > 0) {
            $result = $this->responsePaginator($result);
        }

        //treat object
        if ($treatObject) {
            return $this->treatObject($result, $typeTreat, $treatObject);
        }

        //clear hydration
        $this->resultHydration = null;

        //return result without treat object
        return json_decode($this->serializer->serialize($result, 'json'));
    }

    /**
     * Set result method.
     *
     * @param string $format getResult | getArrayResult | getSingleResult | getOneOrNullResult | getScalarResult | getSingleScalarResult
     */
    protected function setQueryResultFormat(string $format)
    {
        $perm = ['getResult', 'getArrayResult', 'getSingleResult', 'getOneOrNullResult', 'getScalarResult', 'getSingleScalarResult'];
        if (!in_array($format, $perm)) {
            $perm = implode(' ', $perm);
            throw new \Exception("Invalid result format!. Accepted values: $perm");
        }
        $this->queryResultFormat = $format;
    }

    /*-------------------------------------------------------------------------------------
     * PAGINATOR QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Add paging data in the results matrix.
     *
     * @param array  $result
     * @param string $em
     *
     * @return array
     */
    private function responsePaginator(array $result, $em = ''): array
    {
        if ($em) {
            $query = $em;
            $queryCount = $em;
        } else {
            $query = $this->getQuery();
            $queryCount = $this->queryBuilder;
        }
        $perPage = $query->getMaxResults();
        $page = $query->getFirstResult();
        $paginator = new Paginator($queryCount, $fetchJoinCollection = true);
        $paginator->setUseOutputWalkers(false);
        $totalCount = count($paginator);
        $result[$this->configLdr['indexNamePaginationArray']]['page'] = $page / $perPage; //current page
        $result[$this->configLdr['indexNamePaginationArray']]['perPage'] = $perPage; //number of records per page
        $result[$this->configLdr['indexNamePaginationArray']]['pageCount'] = (int) ceil($totalCount / $perPage); //total pages
        $result[$this->configLdr['indexNamePaginationArray']]['totalCount'] = $totalCount; //total records

        return $result;
    }

    /**
     * Paginator query builder.
     *
     * @param int $firstResult
     * @param int $maxResults
     */
    public function paginator(int $firstResult, int $limit)
    {
        $this->setFirstResult($limit * $firstResult);
        $this->setMaxResults($limit);
    }

    /**
     * Set first result query builder.
     *
     * @param int $firstResult
     */
    private function setFirstResult(int $firstResult)
    {
        $this->setParamQp($this->queryBuilder->setFirstResult($firstResult));
    }

    /**
     * Set max results query builder.
     *
     * @param int $maxResults
     */
    public function setMaxResults(int $limit)
    {
        $this->setParamQp($this->queryBuilder->setMaxResults($limit));
    }

    /*-------------------------------------------------------------------------------------
     * GROUP BY QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * GroupBy.
     *
     * @param string $field
     * @param bool   $aliasMain
     */
    protected function groupBy(string $field, bool $aliasMain = true)
    {
        $field = $this->aliasMainConcat($field, $aliasMain);
        $this->setParamQp($this->queryBuilder->groupBy($field));
    }

    /**
     * Add new groupby.
     *
     * @param string $field
     * @param bool   $aliasMain
     */
    protected function addGroupBy(string $field, bool $aliasMain = true)
    {
        $field = $this->aliasMainConcat($field, $aliasMain);
        $this->setParamQp($this->queryBuilder->addGroupBy($field));
    }

    /*-------------------------------------------------------------------------------------
     * ORDER BY QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * OrderBy.
     *
     * @param string $field
     * @param string $order
     * @param bool   $aliasMain
     */
    protected function orderBy(string $field, string $order = '', bool $aliasMain = true)
    {
        $this->validateOrderBy($order);
        $field = $this->aliasMainConcat($field, $aliasMain);
        $this->setParamQp($this->queryBuilder->orderBy($field, $order));
    }

    /**
     * Add orderBy.
     *
     * @param string $field
     * @param string $order
     * @param bool   $aliasMain
     */
    protected function addOrderBy(string $field, string $order = '', bool $aliasMain = true)
    {
        $this->validateOrderBy($order);
        $field = $this->aliasMainConcat($field, $aliasMain);
        $this->setParamQp($this->queryBuilder->addOrderBy($field, $order));
    }

    /**
     * Add order by rand.
     */
    public function orderByRand()
    {
        $this->addOrderBy($this->addRand(), '', false);
    }

    /**
     * Validate orderBy.
     *
     * @param string $order
     */
    private function validateOrderBy(string $order)
    {
        $valuesOrder = ['DESC', 'ASC', ''];
        if (!in_array($order, $valuesOrder)) {
            $valuesOrder = implode(' ', $valuesOrder);
            throw new \Exception("Incorrect value {$order}. Expected: {$valuesOrder}");
        }
    }

    /*-------------------------------------------------------------------------------------
     * SELECT QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Select.
     *
     * @param string $param
     */
    protected function select(string $param)
    {
        $this->setParamQp($this->queryBuilder->select($param));
    }

    /**
     * Add select.
     *
     * @param string $param
     */
    protected function addSelect(string $param)
    {
        $this->setParamQp($this->queryBuilder->addSelect($param));
    }

    /**
     * From.
     *
     * @param string $from
     * @param string $alias
     * @param [type] $indexBy
     */
    protected function from(string $from = '', string $alias = '', $indexBy = null)
    {
        if (!$from && !$alias && !$indexBy) {
            $from = $this->searchEnt($this->mainNameEntity);
            $alias = $this->mainAlias;
        } else {
            $from = $this->defaultEntityNamespace($from);
        }
        $this->setParamQp($this->queryBuilder->from($from, $alias, $indexBy));
    }

    /*-------------------------------------------------------------------------------------
     * JOIN QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Innerjoin.
     *
     * @param string $join
     * @param string $alias
     * @param mixed  $conditionType
     * @param mixed  $condition
     * @param mixed  $indexBy
     */
    protected function innerJoin(string $join, string $alias, $conditionType = null, $condition = null, $indexBy = null)
    {
        if ($conditionType) {
            $join = $this->defaultEntityNamespace($join);
        }
        $this->setParamQp($this->queryBuilder->innerJoin($join, $alias, $conditionType, $condition, $indexBy));
    }

    /**
     * Left join.
     *
     * @param string $join
     * @param string $alias
     * @param mixed  $conditionType
     * @param mixed  $condition
     * @param mixed  $indexBy
     */
    protected function leftJoin(string $join, string $alias, $conditionType = null, $condition = null, $indexBy = null)
    {
        if ($conditionType) {
            $join = $this->defaultEntityNamespace($join);
        }
        $this->setParamQp($this->queryBuilder->leftJoin($join, $alias, $conditionType, $condition, $indexBy));
    }

    /**
     * Join.
     *
     * @param string $join
     * @param string $alias
     * @param [type] $conditionType
     * @param [type] $condition
     * @param [type] $indexBy
     */
    protected function join(string $join, string $alias, $conditionType = null, $condition = null, $indexBy = null)
    {
        if ($conditionType) {
            $join = $this->defaultEntityNamespace($join);
        }
        $this->setParamQp($this->queryBuilder->join($join, $alias, $conditionType, $condition, $indexBy));
    }

    /*-------------------------------------------------------------------------------------
     * WHERE QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Where conditional array.
     *
     * @var array
     */
    private $whereCond = [];

    /**
     * Where allowed expressions.
     *
     * @var array
     */
    private $expPermWhere = ['*', '-', '+', '/', 'IS NOT NULL', 'IS NULL', '>=', '>', '<=', '<', '<>', '=', 'NOT', 'IN', 'NOT IN', 'LIKE', 'NOT LIKE'];

    /**
     * Where amount expression.
     *
     * @param string $field
     * @param string $cond
     * @param mixed  $value        - string | array
     * @param bool   $aliasDefault
     * @param bool   $createdPar
     *
     * @return string
     */
    protected function expr(string $field, string $cond, $value, bool $aliasDefault = true, bool $createdPar = true): string
    {
        $nalias = '';

        if (!in_array($cond, $this->expPermWhere)) {
            $expPerm = implode(' ', $this->expPermWhere);
            throw new \Exception("Expected not value. Values expected: $expPerm ");
        }

        if ($aliasDefault) {
            $nalias = "$this->mainAlias.";
        }

        if (is_array($value)) {
            if ($createdPar) {
                $newArrayValues = [];
                foreach ($value as $key => $value2) {
                    $newArrayValues[] = $this->createNamedParameter($value2);
                }
            }
            $implode = implode(',', $newArrayValues);
            $value = "( $implode )";
        } else {
            if ($createdPar) {
                $value = $this->createNamedParameter($value);
            }
        }

        return "{$nalias}{$field} {$cond} {$value}";
    }

    /**
     * Between expression.
     *
     * @param [type] $value1
     * @param [type] $value2
     *
     * @return string
     */
    protected function exprBetween($value1, $value2): string
    {
        return " BETWEEN {$this->createNamedParameter($value1)} AND {$this->createNamedParameter($value2)} ";
    }

    /**
     * Set expr.
     *
     * @param [type] $param
     */
    public function setWhere($param)
    {
        $this->whereCond[] = $param;
    }

    /**
     * Set expr and where.
     *
     * @param [type] $param
     */
    public function setAndWhere($param)
    {
        $this->whereCond[] = " AND {$param}";
    }

    /**
     * Set expr or where.
     *
     * @param [type] $param
     */
    public function setOrWhere($param)
    {
        $this->whereCond[] = " OR {$param}";
    }

    /**
     * Set expr or.
     */
    public function setCondOrWhere()
    {
        $this->whereCond[] = ' OR ';
    }

    /**
     * Set expr and.
     */
    public function setCondAndWhere()
    {
        $this->whereCond[] = ' AND ';
    }

    /**
     * Set expr not.
     */
    public function setCondNotWhere()
    {
        $this->whereCond[] = ' NOT ';
    }

    /**
     * Set expr (.
     */
    public function setParentStartWhere()
    {
        $this->whereCond[] = ' ( ';
    }

    /**
     * Set expr ).
     */
    public function setParentEndWhere()
    {
        $this->whereCond[] = ' ) ';
    }

    /**
     * Mount where expression.
     *
     * @param [type] $function
     */
    public function whereExpr($function)
    {
        $this->whereCond = [];
        $function();
        $this->whereMount();
    }

    /**
     * Mount where string.
     */
    private function whereMount()
    {
        if (!empty($this->whereCond)) {
            $this->where(implode(' ', $this->whereCond));
            $this->whereCond = [];
        }
    }

    /**
     * Where query builder.
     *
     * @param string $param
     */
    protected function where(string $param)
    {
        $this->setParamQp($this->queryBuilder->where($param));
    }

    /*-------------------------------------------------------------------------------------
     * HAVING QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Having conditional array.
     *
     * @var array
     */
    private $havingCond = [];

    /**
     * Set expr.
     *
     * @param [type] $param
     */
    public function setHaving($param)
    {
        $this->havingCond[] = $param;
    }

    /**
     * Set expr and having.
     *
     * @param [type] $param
     */
    public function setAndHaving($param)
    {
        $this->havingCond[] = " AND {$param}";
    }

    /**
     * Set expr or having.
     *
     * @param [type] $param
     */
    public function setOrHaving($param)
    {
        $this->havingCond[] = " OR {$param}";
    }

    /**
     * Set expr or.
     */
    public function setCondOrHaving()
    {
        $this->havingCond[] = ' OR ';
    }

    /**
     * Set expr and.
     */
    public function setCondAndHaving()
    {
        $this->havingCond[] = ' AND ';
    }

    /**
     * Set expr not.
     */
    public function setCondNotHaving()
    {
        $this->havingCond[] = ' NOT ';
    }

    /**
     * Set expr (.
     */
    public function setParentStartHaving()
    {
        $this->havingCond[] = ' ( ';
    }

    /**
     * Set expr ).
     */
    public function setParentEndHaving()
    {
        $this->havingCond[] = ' ) ';
    }

    /**
     * Mount having expression.
     *
     * @param [type] $function
     */
    public function havingExpr($function)
    {
        $this->havingCond = [];
        $function();
        $this->havingMount();
    }

    /**
     * Mount having string.
     */
    private function havingMount()
    {
        if (!empty($this->havingCond)) {
            $this->having(implode(' ', $this->havingCond));
            $this->havingCond = [];
        }
    }

    /**
     * Having query builder.
     *
     * @param string $value
     */
    protected function having(string $value)
    {
        $this->setParamQp($this->queryBuilder->having($value));
    }

    /*-------------------------------------------------------------------------------------
     * UPDATE QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Update rows.
     *
     * @param string $value
     * @param string $value2
     */
    private function edit(string $value = '', string $value2 = '')
    {
        if (!$value && !$value2) {
            $value = $this->searchEnt($this->mainNameEntity);
            $value2 = $this->mainAlias;
        }
        $this->setParamQp($this->queryBuilder->update($value, $value2));
    }

    /**
     * Set.
     *
     * @param string $value
     * @param string $value2
     * @param bool   $aliasMain
     * @param bool   $nameParam
     */
    protected function set(string $field, $value, bool $aliasMain = true, bool $nameParam = true)
    {
        $field = $this->aliasMainConcat($field, $aliasMain);
        if (isset($value)) {
            if ($nameParam) {
                $value = $this->createNamedParameter($value);
            }
            $this->setParamQp($this->queryBuilder->set($field, $value));
        }
    }

    /**
     * Set json replace.
     *
     * @param string $field1
     * @param string $fieldReplace
     * @param string $fieldJson
     * @param string $value2
     * @param bool   $aliasMain
     */
    protected function setJsonReplace(string $field, string $fieldJson, $valueJson, bool $aliasMain = true)
    {
        $field = $this->aliasMainConcat($field, $aliasMain);
        if (isset($valueJson)) {
            if (is_array($valueJson)) {
                $valueJson = $this->addCast($this->createNamedParameter(json_encode($valueJson)));
            } else {
                $valueJson = $this->createNamedParameter($valueJson);
            }
            $this->set($field, $this->jsonReplace($field, $fieldJson, $valueJson), false, false);
        }
    }

    /**
     * Set json set.
     *
     * @param string $field1
     * @param string $fieldReplace
     * @param string $fieldJson
     * @param string $value2
     * @param bool   $aliasMain
     */
    protected function setJsonSet(string $field, string $fieldJson, $valueJson, bool $aliasMain = true)
    {
        $field = $this->aliasMainConcat($field, $aliasMain);
        if (isset($valueJson)) {
            if (is_array($valueJson)) {
                $valueJson = $this->addCast($this->createNamedParameter(json_encode($valueJson)));
            } else {
                $valueJson = $this->createNamedParameter($valueJson);
            }
            $this->set($field, $this->jsonSet($field, $fieldJson, $valueJson), false, false);
        }
    }

    /**
     * Set json insert.
     *
     * @param string $field1
     * @param string $fieldReplace
     * @param string $fieldJson
     * @param string $value2
     * @param bool   $aliasMain
     */
    protected function setJsonInsert(string $field, string $fieldJson, $valueJson, bool $aliasMain = true)
    {
        $field = $this->aliasMainConcat($field, $aliasMain);
        if (isset($valueJson)) {
            if (is_array($valueJson)) {
                $valueJson = $this->addCast($this->createNamedParameter(json_encode($valueJson)));
            } else {
                $valueJson = $this->createNamedParameter($valueJson);
            }
            $this->set($field, $this->jsonInsert($field, $fieldJson, $valueJson), false, false);
        }
    }

    /**
     * Set automatically params edit.
     *
     * @param array $params
     * @param array $rule
     */
    protected function setUpdateArrayQuery(array $rule, array $params, bool $updatedAt = true)
    {
        $set = true;
        if ($updatedAt) {
            $this->set($this->configLdr['updatedAtFieldName'], date('Y-m-d H:i:s')); //set updatedAt
        }
        foreach ($rule as $key => $value) {
            $exp = explode('|', $value);

            if (isset($params[$exp[0]])) {
                $set = true;

                //valid is array
                if (is_array($params[$exp[0]])) {
                    throw new \Exception("The value of field ({$exp[0]}) can not be an array.");
                }
            } else {
                $set = false;
            }

            if ($set) {
                $this->set($exp[0], $params[$exp[0]]); //set
            }
        }
    }

    /**
     * Main editing function.
     *
     * @param [type] $function
     */
    protected function mainUpdateQuery($function): int
    {
        if (!$this->getDQLParts()['where']) {
            throw new \Exception('Enter a where condition to run the query');
        }

        $this->edit(); //edit
        $function(); //execute sets

        return $this->execute(); //execute
    }

    /*-------------------------------------------------------------------------------------
     * DELETE QUERY BUILDER
     *-------------------------------------------------------------------------------------*/

    /**
     * Delete rows.
     *
     * @param string $value
     * @param string $value2
     * @param bool   $createQuery
     */
    private function exclude(string $value = '', string $value2 = '')
    {
        if (!$value && !$value2) {
            $value = $this->searchEnt($this->mainNameEntity);
            $value2 = $this->mainAlias;
        }
        $this->setParamQp($this->queryBuilder->delete($value, $value2));
    }

    /**
     * Delete row where.
     *
     * @return bool
     */
    public function deleteQuery(): bool
    {
        if (!$this->getDQLParts()['where']) {
            throw new \Exception('Enter a where condition to run the query');
        }
        $this->exclude(); //exclude
        return (bool) $this->execute(); //execute
    }

    /*-------------------------------------------------------------------------------------
     * HYDRATE
     *-------------------------------------------------------------------------------------*/

    /**
     * Result hydration.
     *
     * @var mixed
     */
    private $resultHydration = null;

    /**
     * Add result hydrate object.
     */
    protected function addHydrateObject()
    {
        $this->resultHydration = Query::HYDRATE_OBJECT;
    }

    /**
     * Add result hydrate array.
     */
    protected function addHydrateArray()
    {
        $this->resultHydration = Query::HYDRATE_ARRAY;
    }

    /**
     * Add result hydrate scalar.
     */
    protected function addHydrateScalar()
    {
        $this->resultHydration = Query::HYDRATE_SCALAR;
    }

    /**
     * Add result hydrate single scalar.
     */
    protected function addHydrateSingleScalar()
    {
        $this->resultHydration = Query::HYDRATE_SINGLE_SCALAR;
    }

    /**
     * Add hydration configuration.
     *
     * @param string $pos
     */
    protected function addCustomHydrationMode(string $pos)
    {
        $this->resultHydration = $pos;
    }

    /*-------------------------------------------------------------------------------------
     * EXTENSIONS FUNCTIONS
     *-------------------------------------------------------------------------------------*/

    /**
     * Add function cast.
     *
     * @param string $value
     * @param string $param
     *
     * @return string
     */
    private function addCast(string $value, $param = 'AS JSON'): string
    {
        $this->validFunctionMysql();

        return "CAST({$value} AS JSON)";
    }

    /**
     * Add function rand.
     *
     * @return string
     */
    private function addRand(): string
    {
        $this->validFunctionMysql();

        return 'RAND()';
    }

    /**
     * Field json.
     *
     * @param string $field
     * @param string $field2
     * @param bool   $aliasMain
     *
     * @return string
     */
    protected function fieldJson(string $field, string $field2, bool $aliasMain = true): string
    {
        return $this->jsonExtract($this->aliasMainConcat($field, $aliasMain), $field2);
    }

    /**
     * Json replace.
     *
     * @param string $field1
     * @param string $field2
     * @param string $field3
     *
     * @return string
     */
    private function jsonReplace(string $field1, string $field2, string $field3): string
    {
        $this->validFunctionMysql();

        return "JSON_REPLACE({$field1}, '$.{$field2}', {$field3})";
    }

    /**
     * Json set.
     *
     * @param string $field1
     * @param string $field2
     * @param string $field3
     *
     * @return string
     */
    private function jsonSet(string $field1, string $field2, string $field3): string
    {
        $this->validFunctionMysql();

        return "JSON_SET({$field1}, '$.{$field2}', {$field3})";
    }

    /**
     * Json insert.
     *
     * @param string $field1
     * @param string $field2
     * @param string $field3
     *
     * @return string
     */
    private function jsonInsert(string $field1, string $field2, string $field3): string
    {
        $this->validFunctionMysql();

        return "JSON_INSERT({$field1}, '$.{$field2}', {$field3})";
    }

    /**
     * Json extract.
     *
     * @return string
     */
    private function jsonExtract(string $field1, string $field2, $field3 = ''): string
    {
        $this->validFunctionMysql();

        if ($field3) {
            $field3 = ", $field3";
        }

        return "JSON_EXTRACT({$field1}, '$.{$field2}' {$field3})";
    }

    /*-------------------------------------------------------------------------------------
     * SERIALIZER
     *-------------------------------------------------------------------------------------*/

    /**
     * Serializer.
     *
     * @var \Symfony\Component\Serializer\Serializer
     */
    protected $serializer;

    /**
     * Instance serializer.
     *
     * @param [type] $objectNormalizer
     */
    private function instanceSerializer($objectNormalizer = null)
    {
        $encoders = array(new JsonEncoder());
        $normalizers = array(new DateTimeNormalizer('m-d-Y H:i:s'), $objectNormalizer ?? new ObjectNormalizer());
        $this->serializer = new Serializer($normalizers, $encoders);
    }

    /**
     * Included attributes object.
     *
     * @param [type] $obj
     * @param array  $atributes
     *
     * @return object
     */
    private function serializerNormalize($obj, array $atributes): object
    {
        if ($this->return == 'array') {
            return json_decode(json_encode($this->serializer->normalize($obj, null, [
                'attributes' => $atributes,
            ])), true);
        }
        if ($this->return == 'stdClass') {
            return json_decode(json_encode($this->serializer->normalize($obj, null, [
                'attributes' => $atributes,
            ])));
        }
    }

    /**
     * Ignore attributes object.
     *
     * @param [type] $obj
     * @param array  $values
     *
     * @return object
     */
    private function serializerIgnoreAttributes($obj, array $values): object
    {
        $normalizer = new ObjectNormalizer();
        $normalizer = $normalizer->setIgnoredAttributes($values);
        $this->instanceSerializer($normalizer);

        if ($this->return == 'array') {
            return json_decode($this->serializer->serialize($obj, 'json'), true);
        }
        if ($this->return == 'stdClass') {
            return json_decode($this->serializer->serialize($obj, 'json'));
        }
    }

    /**
     * Treat object.
     *
     * @param [type] $obj
     * @param string $type   included | excluded
     * @param array  $values
     *
     * @return object
     */
    protected function treatObject($obj, string $type, array $values): object
    {
        $perm = ['included', 'excluded'];
        if ($values && in_array($type, $perm)) {
            if ($type == 'included') {
                return $this->serializerNormalize($obj, $values);
            }
            if ($type == 'excluded') {
                return $this->serializerIgnoreAttributes($obj, $values);
            }
        } else {
            if ($this->return == 'array') {
                return json_decode($this->serializer->serialize($obj, 'json'), true);
            }
            if ($this->return == 'stdClass') {
                return json_decode($this->serializer->serialize($obj, 'json'));
            }
            if ($this->return == 'doctrine') {
                return $obj;
            }
        }
    }
}
