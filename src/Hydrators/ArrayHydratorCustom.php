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

use PDO;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use App\Helpers\Utilitarios\FactoryUtilitarios;

class ArrayHydratorCustom extends AbstractHydrator
{
    /**
     * @var array
     */
    private $_rootAliases = [];

    /**
     * @var bool
     */
    private $_isSimpleQuery = false;

    /**
     * @var array
     */
    private $_identifierMap = [];

    /**
     * @var array
     */
    private $_resultPointers = [];

    /**
     * @var array
     */
    private $_idTemplate = [];

    /**
     * @var int
     */
    private $_resultCounter = 0;

    /**
     * {@inheritdoc}
     */
    protected function prepare()
    {
        $this->_isSimpleQuery = count($this->_rsm->aliasMap) <= 1;

        foreach ($this->_rsm->aliasMap as $dqlAlias => $className) {
            $this->_identifierMap[$dqlAlias] = [];
            $this->_resultPointers[$dqlAlias] = [];
            $this->_idTemplate[$dqlAlias] = '';
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateAllData()
    {
        $result = array();

        while ($data = $this->_stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = $this->hydrateRowData($data);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateRowData(array $row)
    {
        $result = array();
        // 1) Initialize
        $id = $this->_idTemplate; // initialize the id-memory
        $nonemptyComponents = [];
        $rowData = $this->gatherRowData($row, $id, $nonemptyComponents);
        $resultTree = array();
        $factoryJson = FactoryUtilitarios::create('zaazjson');

        // 2) Now hydrate the data found in the current row.
        foreach ($rowData['data'] as $dqlAlias => $data) {
            $index = false;

            if (isset($this->_rsm->parentAliasMap[$dqlAlias])) {
                // It's a joined result

                $parent = $this->_rsm->parentAliasMap[$dqlAlias];
                $path = $parent . '.' . $dqlAlias;

                // missing parent data, skipping as RIGHT JOIN hydration is not supported.
                if (!isset($nonemptyComponents[$parent])) {
                    continue;
                }

                // Get a reference to the right element in the result tree.
                // This element will get the associated element attached.
                if ($this->_rsm->isMixed && isset($this->_rootAliases[$parent])) {
                    $first = key($this->_resultPointers[$parent]);
                    // TODO: Exception if $key === null ?
                    $baseElement = &$this->_resultPointers[$parent][$first];
                    $resultTree = &$result[$first];
                } elseif (isset($this->_resultPointers[$parent])) {
                    $baseElement = &$this->_resultPointers[$parent];
                    if ($resultTree[$this->_rsm->relationMap[$parent]]) {
                        $resultTree = &$resultTree[$this->_rsm->relationMap[$parent]];
                    }
                } else {
                    unset($this->_resultPointers[$dqlAlias]); // Ticket #1228

                    continue;
                }

                $relationAlias = $this->_rsm->relationMap[$dqlAlias];
                $parentClass = $this->_metadataCache[$this->_rsm->aliasMap[$parent]];
                $relation = $parentClass->associationMappings[$relationAlias];

                // Check the type of the relation (many or single-valued)
                if (!($relation['type'] & ClassMetadata::TO_ONE)) {
                    $oneToOne = false;

                    if (!isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = [];
                    }

                    if (isset($nonemptyComponents[$dqlAlias])) {
                        $indexExists = isset($this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]]);
                        $index = $indexExists ? $this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]] : false;
                        $indexIsValid = $index !== false ? isset($baseElement[$relationAlias][$index]) : false;

                        if (!$indexExists || !$indexIsValid) {
                            $element = $data;

                            if (isset($this->_rsm->indexByMap[$dqlAlias])) {
                                $baseElement[$relationAlias][$row[$this->_rsm->indexByMap[$dqlAlias]]] = $element;
                            } else {
                                $baseElement[$relationAlias][] = $element;
                            }

                            end($baseElement[$relationAlias]);

                            $this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]] = key($baseElement[$relationAlias]);
                        }
                    }
                } else {
                    $oneToOne = true;

                    if (
                        (!isset($nonemptyComponents[$dqlAlias]))
                        && (!isset($baseElement[$relationAlias]))
                    ) {
                        $baseElement[$relationAlias] = null;
                    } elseif (!isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = $data;
                    }
                }

                $coll = &$baseElement[$relationAlias];

                $resultTree[$relationAlias] = $coll;

                if (is_array($coll)) {
                    $this->updateResultPointer($coll, $index, $dqlAlias, $oneToOne);
                }
            } else {
                // It's a root result element

                $this->_rootAliases[$dqlAlias] = true; // Mark as root
                $entityKey = $this->_rsm->entityMappings[$dqlAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if (!isset($nonemptyComponents[$dqlAlias])) {
                    $this->_rsm->isMixed ? $result[$entityKey] = null : $result[] = null;

                    $resultKey = $this->_resultCounter;
                    ++$this->_resultCounter;

                    continue;
                }

                // Check for an existing element
                // if ($this->_isSimpleQuery || !isset($this->_identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                $element = $this->_rsm->isMixed
                    ? [$entityKey => $data]
                    : $data;

                if (isset($this->_rsm->indexByMap[$dqlAlias])) {
                    $resultKey = $row[$this->_rsm->indexByMap[$dqlAlias]];
                    $result[$resultKey] = $element;
                } else {
                    $resultKey = $this->_resultCounter;
                    $result = array_merge($result, $element);

                    ++$this->_resultCounter;
                }

                $this->_identifierMap[$dqlAlias][$id[$dqlAlias]] = $resultKey;
                // } else {
                //     $index = $this->_identifierMap[$dqlAlias][$id[$dqlAlias]];
                //     $resultKey = $index;
                // }

                $this->updateResultPointer($result, $index, $dqlAlias, false);
            }
        }

        if (!isset($resultKey)) {
            ++$this->_resultCounter;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (!isset($resultKey)) {
                // this only ever happens when no object is fetched (scalar result only)
                if (isset($this->_rsm->indexByMap['scalars'])) {
                    $resultKey = $row[$this->_rsm->indexByMap['scalars']];
                } else {
                    $resultKey = $this->resultCounter - 1;
                }
            }

            foreach ($rowData['scalars'] as $name => $value) {
                if (sizeof(explode('},{', $value)) > 1 && substr($value, 0, 1) != '[') {
                    $value = '[' . $value . ']';
                }
                // trantando json 
                if (!$value) $value = "";
                $value = $factoryJson->formataJson($value);

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
                $resultKey = $this->_resultCounter - 1;
            }

            $scalarCount = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $class = $newObject['class'];
                $args = $newObject['args'];
                $obj = $class->newInstanceArgs($args);

                if (count($args) == $scalarCount || ($scalarCount == 0 && count($rowData['newObjects']) == 1)) {
                    $result[$resultKey] = $obj;

                    continue;
                }

                $result[$resultKey][$objIndex] = $obj;
            }
        }

        return $result;
    }

    /**
     * Updates the result pointer for an Entity. The result pointers point to the
     * last seen instance of each Entity type. This is used for graph construction.
     *
     * @param array    $coll     the element
     * @param bool|int $index    index of the element in the collection
     * @param string   $dqlAlias
     * @param bool     $oneToOne whether it is a single-valued association or not
     */
    private function updateResultPointer(array &$coll, $index, $dqlAlias, $oneToOne)
    {
        if ($coll === null) {
            unset($this->_resultPointers[$dqlAlias]); // Ticket #1228

            return;
        }

        if ($oneToOne) {
            $this->_resultPointers[$dqlAlias] = &$coll;

            return;
        }

        if ($index !== false) {
            $this->_resultPointers[$dqlAlias] = &$coll[$index];

            return;
        }

        if (!$coll) {
            return;
        }

        end($coll);
        $this->_resultPointers[$dqlAlias] = $coll;

        return;
    }
}
