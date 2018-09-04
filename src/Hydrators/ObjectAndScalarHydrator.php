<?php

/**
 * This file is part of the Laravel Doctrine Repository package.
 *
 * This hyrator was not made by me. I do not know the source if someone knows just let me know
 *
 * @see http://github.com/fernandozueet/laravel-doctrine-repository
 *
 * @copyright 2018
 * @license MIT License
 * @author Unknown
 */

namespace Ldr\Hydrators;

use Doctrine\ORM\UnitOfWork;
use PDO;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;

class ObjectAndScalarHydrator extends AbstractHydrator
{
    /**
     * @var array
     */
    private $identifierMap = array();

    /**
     * @var array
     */
    private $resultPointers = array();

    /**
     * @var array
     */
    private $idTemplate = array();

    /**
     * @var int
     */
    private $resultCounter = 0;

    /**
     * @var array
     */
    private $rootAliases = array();

    /**
     * @var array
     */
    private $initializedCollections = array();

    /**
     * @var array
     */
    private $existingCollections = array();

    /**
     * {@inheritdoc}
     */
    protected function prepare()
    {
        if (!isset($this->_hints[UnitOfWork::HINT_DEFEREAGERLOAD])) {
            $this->_hints[UnitOfWork::HINT_DEFEREAGERLOAD] = true;
        }
        foreach ($this->_rsm->aliasMap as $dqlAlias => $className) {
            $this->identifierMap[$dqlAlias] = array();
            $this->idTemplate[$dqlAlias] = '';
            // Remember which associations are "fetch joined", so that we know where to inject
            // collection stubs or proxies and where not.
            if (!isset($this->_rsm->relationMap[$dqlAlias])) {
                continue;
            }
            $parent = $this->_rsm->parentAliasMap[$dqlAlias];
            if (!isset($this->_rsm->aliasMap[$parent])) {
                throw HydrationException::parentObjectOfRelationNotFound($dqlAlias, $parent);
            }
            $sourceClassName = $this->_rsm->aliasMap[$parent];
            $sourceClass = $this->getClassMetadata($sourceClassName);
            $assoc = $sourceClass->associationMappings[$this->_rsm->relationMap[$dqlAlias]];
            $this->_hints['fetched'][$parent][$assoc['fieldName']] = true;
            if ($assoc['type'] === ClassMetadata::MANY_TO_MANY) {
                continue;
            }
            // Mark any non-collection opposite sides as fetched, too.
            if ($assoc['mappedBy']) {
                $this->_hints['fetched'][$dqlAlias][$assoc['mappedBy']] = true;
                continue;
            }
            // handle fetch-joined owning side bi-directional one-to-one associations
            if ($assoc['inversedBy']) {
                $class = $this->getClassMetadata($className);
                $inverseAssoc = $class->associationMappings[$assoc['inversedBy']];
                if (!($inverseAssoc['type'] & ClassMetadata::TO_ONE)) {
                    continue;
                }
                $this->_hints['fetched'][$dqlAlias][$inverseAssoc['fieldName']] = true;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function cleanup()
    {
        $eagerLoad = (isset($this->_hints[UnitOfWork::HINT_DEFEREAGERLOAD])) && $this->_hints[UnitOfWork::HINT_DEFEREAGERLOAD] == true;
        parent::cleanup();
        $this->identifierMap =
        $this->initializedCollections =
        $this->existingCollections =
        $this->resultPointers = array();
        if ($eagerLoad) {
            $this->_uow->triggerEagerLoads();
        }
        $this->_uow->hydrationComplete();
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateAllData()
    {
        $result = array();
        while ($row = $this->_stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateRowDatas($row);
        }
        // Take snapshots from all newly initialized collections
        foreach ($this->initializedCollections as $coll) {
            $coll->takeSnapshot();
        }

        return $result;
    }

    /**
     * Initializes a related collection.
     *
     * @param object        $entity         the entity to which the collection belongs
     * @param ClassMetadata $class
     * @param string        $fieldName      the name of the field on the entity that holds the collection
     * @param string        $parentDqlAlias alias of the parent fetch joining this collection
     *
     * @return \Doctrine\ORM\PersistentCollection
     */
    private function initRelatedCollection($entity, $class, $fieldName, $parentDqlAlias)
    {
        $oid = spl_object_hash($entity);
        $relation = $class->associationMappings[$fieldName];
        $value = $class->reflFields[$fieldName]->getValue($entity);
        if ($value === null) {
            $value = new ArrayCollection();
        }
        if (!$value instanceof PersistentCollection) {
            $value = new PersistentCollection(
                $this->_em, $this->_metadataCache[$relation['targetEntity']], $value
            );
            $value->setOwner($entity, $relation);
            $class->reflFields[$fieldName]->setValue($entity, $value);
            $this->_uow->setOriginalEntityProperty($oid, $fieldName, $value);
            $this->initializedCollections[$oid.$fieldName] = $value;
        } elseif (
            isset($this->_hints[Query::HINT_REFRESH]) ||
            isset($this->_hints['fetched'][$parentDqlAlias][$fieldName]) &&
             !$value->isInitialized()
        ) {
            // Is already PersistentCollection, but either REFRESH or FETCH-JOIN and UNINITIALIZED!
            $value->setDirty(false);
            $value->setInitialized(true);
            $value->unwrap()->clear();
            $this->initializedCollections[$oid.$fieldName] = $value;
        } else {
            // Is already PersistentCollection, and DON'T REFRESH or FETCH-JOIN!
            $this->existingCollections[$oid.$fieldName] = $value;
        }

        return $value;
    }

    /**
     * Gets an entity instance.
     *
     * @param array  $data     the instance data
     * @param string $dqlAlias the DQL alias of the entity's class
     *
     * @return object the entity
     *
     * @throws HydrationException
     */
    private function getEntity(array $data, $dqlAlias)
    {
        $className = $this->_rsm->aliasMap[$dqlAlias];
        if (isset($this->_rsm->discriminatorColumns[$dqlAlias])) {
            $fieldName = $this->_rsm->discriminatorColumns[$dqlAlias];
            if (!isset($this->_rsm->metaMappings[$fieldName])) {
                throw HydrationException::missingDiscriminatorMetaMappingColumn($className, $fieldName, $dqlAlias);
            }
            $discrColumn = $this->_rsm->metaMappings[$fieldName];
            if (!isset($data[$discrColumn])) {
                throw HydrationException::missingDiscriminatorColumn($className, $discrColumn, $dqlAlias);
            }
            if ($data[$discrColumn] === '') {
                throw HydrationException::emptyDiscriminatorValue($dqlAlias);
            }
            $discrMap = $this->_metadataCache[$className]->discriminatorMap;
            if (!isset($discrMap[$data[$discrColumn]])) {
                throw HydrationException::invalidDiscriminatorValue($data[$discrColumn], array_keys($discrMap));
            }
            $className = $discrMap[$data[$discrColumn]];
            unset($data[$discrColumn]);
        }
        if (isset($this->_hints[Query::HINT_REFRESH_ENTITY]) && isset($this->rootAliases[$dqlAlias])) {
            $this->registerManaged($this->_metadataCache[$className], $this->_hints[Query::HINT_REFRESH_ENTITY], $data);
        }
        $this->_hints['fetchAlias'] = $dqlAlias;

        return $this->_uow->createEntity($className, $data, $this->_hints);
    }

    /**
     * @param string $className
     * @param array  $data
     *
     * @return mixed
     */
    private function getEntityFromIdentityMap($className, array $data)
    {
        // TODO: Abstract this code and UnitOfWork::createEntity() equivalent?
        $class = $this->_metadataCache[$className];
        /* @var $class ClassMetadata */
        if ($class->isIdentifierComposite) {
            $idHash = '';
            foreach ($class->identifier as $fieldName) {
                $idHash .= ' '.(isset($class->associationMappings[$fieldName])
                    ? $data[$class->associationMappings[$fieldName]['joinColumns'][0]['name']]
                    : $data[$fieldName]);
            }

            return $this->_uow->tryGetByIdHash(ltrim($idHash), $class->rootEntityName);
        } elseif (isset($class->associationMappings[$class->identifier[0]])) {
            return $this->_uow->tryGetByIdHash($data[$class->associationMappings[$class->identifier[0]]['joinColumns'][0]['name']], $class->rootEntityName);
        }

        return $this->_uow->tryGetByIdHash($data[$class->identifier[0]], $class->rootEntityName);
    }

    /**
     * Hydrates a single row in an SQL result set.
     *
     * @internal
     * First, the data of the row is split into chunks where each chunk contains data
     * that belongs to a particular component/class. Afterwards, all these chunks
     * are processed, one after the other. For each chunk of class data only one of the
     * following code paths is executed:
     *
     * Path A: The data chunk belongs to a joined/associated object and the association
     *         is collection-valued.
     * Path B: The data chunk belongs to a joined/associated object and the association
     *         is single-valued.
     * Path C: The data chunk belongs to a root result element/object that appears in the topmost
     *         level of the hydrated result. A typical example are the objects of the type
     *         specified by the FROM clause in a DQL query.
     *
     * @param array $row    the data of the row to process
     * @param array $result the result array to fill
     */
    protected function hydrateRowDatas(array $row)
    {
        $result = array();
        // Initialize
        $id = $this->idTemplate; // initialize the id-memory
        $nonemptyComponents = array();
        // Split the row data into chunks of class data.
        $rowData = $this->gatherRowData($row, $id, $nonemptyComponents);
        // Hydrate the data chunks
        foreach ($rowData['data'] as $dqlAlias => $data) {
            $entityName = $this->_rsm->aliasMap[$dqlAlias];
            if (isset($this->_rsm->parentAliasMap[$dqlAlias])) {
                // It's a joined result
                $parentAlias = $this->_rsm->parentAliasMap[$dqlAlias];
                // we need the $path to save into the identifier map which entities were already
                // seen for this parent-child relationship
                $path = $parentAlias.'.'.$dqlAlias;
                // We have a RIGHT JOIN result here. Doctrine cannot hydrate RIGHT JOIN Object-Graphs
                if (!isset($nonemptyComponents[$parentAlias])) {
                    // TODO: Add special case code where we hydrate the right join objects into identity map at least
                    continue;
                }
                $parentClass = $this->_metadataCache[$this->_rsm->aliasMap[$parentAlias]];
                $relationField = $this->_rsm->relationMap[$dqlAlias];
                $relation = $parentClass->associationMappings[$relationField];
                $reflField = $parentClass->reflFields[$relationField];
                // Get a reference to the parent object to which the joined element belongs.
                // if ($this->_rsm->isMixed && isset($this->rootAliases[$parentAlias])) {
                //     $first = reset($this->resultPointers);
                //     $parentObject = $first[key($first)];
                // } else
                if (isset($this->resultPointers[$parentAlias])) {
                    $parentObject = $this->resultPointers[$parentAlias];
                } else {
                    // Parent object of relation not found, mark as not-fetched again
                    $element = $this->getEntity($data, $dqlAlias);
                    // Update result pointer and provide initial fetch data for parent
                    $this->resultPointers[$dqlAlias] = $element;
                    $rowData['data'][$parentAlias][$relationField] = $element;
                    // Mark as not-fetched again
                    unset($this->_hints['fetched'][$parentAlias][$relationField]);
                    continue;
                }
                $oid = spl_object_hash($parentObject);
                // Check the type of the relation (many or single-valued)
                if (!($relation['type'] & ClassMetadata::TO_ONE)) {
                    // PATH A: Collection-valued association
                    $reflFieldValue = $reflField->getValue($parentObject);
                    if (isset($nonemptyComponents[$dqlAlias])) {
                        $collKey = $oid.$relationField;
                        if (isset($this->initializedCollections[$collKey])) {
                            $reflFieldValue = $this->initializedCollections[$collKey];
                        } elseif (!isset($this->existingCollections[$collKey])) {
                            $reflFieldValue = $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                        }
                        $indexExists = isset($this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]]);
                        $index = $indexExists ? $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] : false;
                        $indexIsValid = $index !== false ? isset($reflFieldValue[$index]) : false;
                        if (!$indexExists || !$indexIsValid) {
                            if (isset($this->existingCollections[$collKey])) {
                                // Collection exists, only look for the element in the identity map.
                                if ($element = $this->getEntityFromIdentityMap($entityName, $data)) {
                                    $this->resultPointers[$dqlAlias] = $element;
                                } else {
                                    unset($this->resultPointers[$dqlAlias]);
                                }
                            } else {
                                $element = $this->getEntity($data, $dqlAlias);
                                if (isset($this->_rsm->indexByMap[$dqlAlias])) {
                                    $indexValue = $row[$this->_rsm->indexByMap[$dqlAlias]];
                                    $reflFieldValue->hydrateSet($indexValue, $element);
                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] = $indexValue;
                                } else {
                                    $reflFieldValue->hydrateAdd($element);
                                    $reflFieldValue->last();
                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] = $reflFieldValue->key();
                                }
                                // Update result pointer
                                $this->resultPointers[$dqlAlias] = $element;
                            }
                        } else {
                            // Update result pointer
                            $this->resultPointers[$dqlAlias] = $reflFieldValue[$index];
                        }
                    } elseif (!$reflFieldValue) {
                        $reflFieldValue = $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                    } elseif ($reflFieldValue instanceof PersistentCollection && $reflFieldValue->isInitialized() === false) {
                        $reflFieldValue->setInitialized(true);
                    }
                } else {
                    // PATH B: Single-valued association
                    $reflFieldValue = $reflField->getValue($parentObject);
                    if (!$reflFieldValue || isset($this->_hints[Query::HINT_REFRESH]) || ($reflFieldValue instanceof Proxy && !$reflFieldValue->__isInitialized__)) {
                        // we only need to take action if this value is null,
                        // we refresh the entity or its an unitialized proxy.
                        if (isset($nonemptyComponents[$dqlAlias])) {
                            $element = $this->getEntity($data, $dqlAlias);
                            $reflField->setValue($parentObject, $element);
                            $this->_uow->setOriginalEntityProperty($oid, $relationField, $element);
                            $targetClass = $this->_metadataCache[$relation['targetEntity']];
                            if ($relation['isOwningSide']) {
                                // TODO: Just check hints['fetched'] here?
                                // If there is an inverse mapping on the target class its bidirectional
                                if ($relation['inversedBy']) {
                                    $inverseAssoc = $targetClass->associationMappings[$relation['inversedBy']];
                                    if ($inverseAssoc['type'] & ClassMetadata::TO_ONE) {
                                        $targetClass->reflFields[$inverseAssoc['fieldName']]->setValue($element, $parentObject);
                                        $this->_uow->setOriginalEntityProperty(spl_object_hash($element), $inverseAssoc['fieldName'], $parentObject);
                                    }
                                } elseif ($parentClass === $targetClass && $relation['mappedBy']) {
                                    // Special case: bi-directional self-referencing one-one on the same class
                                    $targetClass->reflFields[$relationField]->setValue($element, $parentObject);
                                }
                            } else {
                                // For sure bidirectional, as there is no inverse side in unidirectional mappings
                                $targetClass->reflFields[$relation['mappedBy']]->setValue($element, $parentObject);
                                $this->_uow->setOriginalEntityProperty(spl_object_hash($element), $relation['mappedBy'], $parentObject);
                            }
                            // Update result pointer
                            $this->resultPointers[$dqlAlias] = $element;
                        } else {
                            $this->_uow->setOriginalEntityProperty($oid, $relationField, null);
                            $reflField->setValue($parentObject, null);
                        }
                        // else leave $reflFieldValue null for single-valued associations
                    } else {
                        // Update result pointer
                        $this->resultPointers[$dqlAlias] = $reflFieldValue;
                    }
                }
            } else {
                // PATH C: Its a root result element
                $this->rootAliases[$dqlAlias] = true; // Mark as root alias
                $entityKey = $this->_rsm->entityMappings[$dqlAlias] ?: 0;
                // if this row has a NULL value for the root result id then make it a null result.
                if (!isset($nonemptyComponents[$dqlAlias])) {
                    // if ($this->_rsm->isMixed) {
                    //     $result[] = array($entityKey => null);
                    // } else {
                    $result[] = null;
                    // }
                    ++$this->resultCounter;
                    $resultKey = $this->resultCounter;
                    continue;
                }
                // check for existing result from the iterations before
                // if (!isset($this->identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                $element = $this->getEntity($data, $dqlAlias);
                // if ($this->_rsm->isMixed) {
                //     $element = array($entityKey => $element);
                // }
                if (isset($this->_rsm->indexByMap[$dqlAlias])) {
                    $resultKey = $row[$this->_rsm->indexByMap[$dqlAlias]];
                    if (isset($this->_hints['collection'])) {
                        $this->_hints['collection']->hydrateSet($resultKey, $element);
                    }
                    $result[$resultKey] = $element;
                } else {
                    ++$this->resultCounter;
                    $resultKey = $this->resultCounter;
                    if (isset($this->_hints['collection'])) {
                        $this->_hints['collection']->hydrateAdd($element);
                    }
                    if ($entityKey) {
                        $result[$entityKey] = $element;
                    } else {
                        $result[] = $element;
                    }
                }
                $this->identifierMap[$dqlAlias][$id[$dqlAlias]] = $resultKey;
                // Update result pointer
                $this->resultPointers[$dqlAlias] = $element;
                // } else {
                //     // Update result pointer
                //     $index = $this->identifierMap[$dqlAlias][$id[$dqlAlias]];
                //     $this->resultPointers[$dqlAlias] = $result[$index];
                //     $resultKey = $index;
                // }
            }
            if (isset($this->_hints[Query::HINT_INTERNAL_ITERATION]) && $this->_hints[Query::HINT_INTERNAL_ITERATION]) {
                $this->_uow->hydrationComplete();
            }
        }
        if (!isset($resultKey)) {
            ++$this->resultCounter;
        } else {
            if (isset($result[$resultKey]) && $resultKey < $this->resultCounter) {
                $resultKey = $this->resultCounter;
            }
        }
        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (!isset($resultKey)) {
                if (isset($this->_rsm->indexByMap['scalars'])) {
                    $resultKey = $row[$this->_rsm->indexByMap['scalars']];
                } else {
                    $resultKey = $this->resultCounter - 1;
                }
            }
            foreach ($rowData['scalars'] as $name => $value) {
                if (json_decode($value, true)) {
                    $result[$name] = json_decode($value, true);
                } else {
                    $result[$name] = $value;
                }
            }
            ++$this->resultCounter;
        }
        // Append new object to mixed result sets
        if (isset($rowData['newObjects'])) {
            if (!isset($resultKey)) {
                $resultKey = $this->resultCounter - 1;
            }
            $scalarCount = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);
            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $class = $newObject['class'];
                $args = $newObject['args'];
                $obj = $class->newInstanceArgs($args);
                if ($scalarCount == 0 && count($rowData['newObjects']) == 1) {
                    $result[$resultKey] = $obj;
                    continue;
                }
                $result[$resultKey][$objIndex] = $obj;
            }
        }

        return $result;
    }

    /**
     * When executed in a hydrate() loop we may have to clear internal state to
     * decrease memory consumption.
     *
     * @param mixed $eventArgs
     */
    public function onClear($eventArgs)
    {
        parent::onClear($eventArgs);
        $aliases = array_keys($this->identifierMap);
        $this->identifierMap = array();
        foreach ($aliases as $alias) {
            $this->identifierMap[$alias] = array();
        }
    }
}