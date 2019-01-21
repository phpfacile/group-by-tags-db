<?php
namespace PHPFacile\Group\ByTags\Db\Service;

use PHPFacile\Group\ByTags\Model\GroupItem;
use PHPFacile\Group\ByTags\Service\GroupService as DefaultGroupService;

use PHPFacile\DataPackage\TableSchema\Db\Service\DataPackageService;

use Zend\Db\Adapter\Adapter;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Where;

class GroupService extends DefaultGroupService
{
    /**
     * Database adapter
     *
     * @var Adapter $adapter
     */
    protected $adapter;

    /**
     * Service configuration
     *
     * @var array $config
     */
    protected $config;

    /**
     * Json data package/table schema representation of the database
     *
     * @var array $dataPackage
     */
    protected $dataPackage;

    /**
     * Constructor
     *
     * @param Adapter $adapter Database adapter
     *
     * @return GroupService
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->config  = [
            'group_items' => [
                'resource' => 'group_items',
                'fields'   => [
                    'id' => 'id',
                ],
                'tags'     => [],
            ],
        ];

        // Cf. http://frictionlessdata.io/specs/data-package/
        $this->dataPackage = [
            'resources' => [
                // Cf. https://frictionlessdata.io/specs/table-schema/
                [
                    'name'   => 'group_items',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'id',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->dataPackageService = new DataPackageService();
    }

    /**
     * Defines both the service configuration and the database scheme
     *
     * @param array          $config      Service configuration
     * @param StdClass|array $dataPackage Database scheme
     *
     * @return void
     */
    public function setConfig($config, $dataPackage)
    {
        $this->config = $config;
        if (false === is_object($dataPackage) && (true === is_array($dataPackage))) {
            $dataPackage = json_decode(json_encode($dataPackage));
        }

        $this->dataPackage = $dataPackage;
    }

    /**
     * Returns an item of a group thanks to it's identifier
     *
     * @param int|string $groupItemId Id of the item
     *
     * @return object Item of a group
     */
    public function getGroupItemById($groupItemId)
    {
        $groupItemFactory = $this->getGroupItemFactory();
        if (null === $groupItemFactory) {
            throw new \Exception('No group item factory configured');
        }

        return $groupItemFactory->getById($groupItemId);
    }

    /**
     * Returns all the item of a group
     *
     * @return object[]
     */
    public function getAllGroupItems()
    {
        $this->logger->info(__METHOD__.'() [Enter]');

        $tagsInTable        = [];
        $tagsInLinkedTables = [];
        foreach ($this->config['group_items']['tags'] as $tagId => $groupTagSchema) {
            if ($this->config['group_items']['resource'] === $groupTagSchema['resource']) {
                $tagsInTable[$tagId] = $groupTagSchema['field'];
            } else {
                $tagsInLinkedTables[$groupTagSchema['resource']][$tagId] = $groupTagSchema['field'];
            }
        }

        // $moreThanOneLinkedTable = true;
        $sql   = new Sql($this->adapter);
        $query = $sql->select($this->config['group_items']['resource']);

        /*
            if (!$moreThanOneLinkedTable) {
                foreach ($tagsInLinkedTables as $tableName => $tagsInLinkedTable) {
                    // FIXME list fields to be returned
                    // FIXME Use of fieldname aliases might be required
                    // REM If there are several multivalued fields the SQL query may
                    // return
                    // GroupId | Field1 | Field2
                    // 1      |     1  |     1
                    // 1      |     1  |     2
                    // 1      |     2  |     1
                    // 1      |     2  |     2
                    $query->join($tableName,
                               $this->config['group_items']['resource'].'.'.$this->config[$tableName]['links'][0].'='.$tableName.'.'.$this->config[$tableName]['links'][1],
                               '*',
                               'left');
                }
            }
        */

        $stmt = $sql->prepareStatementForSqlObject($query);
        $rows = $stmt->execute();

        $groups = [];
        foreach ($rows as $row) {
            $groupItemId = $row[$this->config['group_items']['fields']['id']];
            if (false === array_key_exists($groupItemId, $groups)) {
                $group = $this->getGroupItemById($groupItemId);
                foreach ($tagsInTable as $tagId => $tagInTable) {
                    $group->setTagValue($tagId, $row[$tagInTable]);
                    $this->logger->debug(__METHOD__.'() group(#'.$groupItemId.')->setTagValue('.$tagId.','.$row[$tagInTable].')');
                }
            } else {
                // In case of join with a table for a multival field
                $group = $groups[$groupItemId];
            }

            /*
                // FIXME Use of fieldname aliases might be required
                // FIXME These could lead to having several times the same tag value
                // Indeed if there are several multivalued fields the SQL query may
                // return
                // GroupId | Field1 | Field2
                // 1      |     1  |     1
                // 1      |     1  |     2
                // 1      |     2  |     1
                // 1      |     2  |     2
                if (!$moreThanOneLinkedTable) {
                    foreach ($tagsInLinkedTables as $tableName => $tagsInLinkedTable) {
                        foreach ($tagsInLinkedTable as $tagId => $tagInTable) {
                            $group->addTagValue($tagId, $row[$tagInTable]);
                        }
                    }
                }
            */

            $groups[$groupItemId] = $group;
        }

        // if ($moreThanOneLinkedTable) {
        $this->loadGroupLinkedTags($groups, true);
        // }
        return $groups;
    }

    /**
     * Retrieve for all the given pools, the tag values when tag values are
     * stored in a linked table (instead of being in the main "pools" table)
     * This is useally the case when the tag are multivalued.
     *
     * @param array   $groupItems         Associative array of pools where key = pool id and value = pool data
     * @param boolean $isAssociativeArray (not used)
     *
     * @return void
     */
    protected function loadGroupLinkedTags($groupItems, $isAssociativeArray = true)
    {
        $this->logger->info(__METHOD__.'(..., ...) [Enter]');
        $groupItemIds = array_keys($groupItems);
        if (0 === count($groupItemIds)) {
            throw new \Exception('Oups... no pool id found in '.var_export($groupItems, true));
        }

        $sql = new Sql($this->adapter);
        foreach ($this->config['group_items']['tags'] as $tagId => $groupTagSchema) {
            // Tag value has to be retrieved from table $groupTagSchema['resource']
            // which may be different from table $this->config['group_items']['resource']
            if ($this->config['group_items']['resource'] !== $groupTagSchema['resource']) {
                /*
                    // We want tag_value for all pools
                    //
                    // [1] Here we assume that we have something like
                    //   pools: id, ...
                    //   pool_external_data: pool_id, data
                    // or even
                    //   pools: id, ...
                    //   pivot_data: pool_id, external_data_id
                    //   pool_external_data: id, data
                    // and even more pivot tables.
                    // [2] We can also have
                    //  pools: id, external_data_id ($this->config['group_items']['resource'] table)
                    //   pool_external_data: id, tag_value ($groupTagSchema['resource'] table)
                    // or even
                    //   pools: id, pivot_data_id ($this->config['group_items']['resource'] table)
                    //   pivot_data: id, external_data_id
                    //   pool_external_data: id, tag_value ($groupTagSchema['resource'] table)
                    // and even more pivot tables.
                    // We can also have combinaisons of the 2 previous schemes
                    //
                    // We first have to get the list of join "clause" required to
                    // make the link between a table containing the "pool_id" and
                    // the table containing the tag value
                */

                $joins = $this->dataPackageService->getJoinsFromTo(
                    $this->dataPackage,
                    $this->config['group_items']['resource'],
                    $groupTagSchema['resource']
                );
                if (null === $joins) {
                    throw new \Exception('Oups... Unable to get joins from table ['.$this->config['group_items']['resource'].'] to ['.$groupTagSchema['resource'].']');
                }

                // FIXME How to know if we are in case 1 or not
                $case = 'any';

                $unMatchedGroupItemIds = $groupItemIds;
                $where = new Where();

                // Now the question is...
                // In which table can we find the pool id
                // Under which field name
                // And if it's basically the "id" field of table "pools"
                // they we might have problems while retrieve the values
                // because in the $row table we will probably get "id" of an
                // other field if we don't use an alias such as "pool_id"
                if ('1' === $case) {
                    // getJoinsFromTo() returns the 'group_items' table whereas it is not
                    // needed in case [1]. Indeed if there are joined tables we can
                    // check "group_item_id" (instead of "group_items.id") directly in the
                    // second table of the $joins array.
                    // REM: In that cas we have to be able to get actual name for
                    // "group_item_id". Cf. $groupItemIdFieldInLinkedTable =
                    // $this->dataPackageService->getFKFieldNameInLinkedResource(
                    $tableNames = array_keys($joins);
                    $mainTable  = array_shift($tableNames);
                    array_shift($joins);

                    // There is a link with field "id" in table "pools" through field
                    // $groupItemIdFieldInLinkedTable in $mainTable
                    $groupItemIdFieldName = $this->dataPackageService->getFKFieldNameInLinkedResource(
                        $this->dataPackage,
                        $this->config['group_items']['resource'],
                        $this->config['group_items']['fields']['id'],
                        $mainTable
                    );
                    if (0 === strlen($groupItemIdFieldName)) {
                        throw new \Exception('groupItemIdFieldName not found ['.$this->config['group_items']['resource'].']['.$this->config['group_items']['fields']['id'].'] ['.$mainTable.']');
                    }

                    $where->in($mainTable.'.'.$groupItemIdFieldName, $groupItemIds);
                    $groupItemIdFieldAlias = $groupItemIdFieldName;
                    $joinColumnAliases     = null;
                    $query = $sql
                        ->select($mainTable)
                        ->where($where);
                } else {
                    // 'any' === $case (not optimal for case 1)
                    $mainTable = $this->config['group_items']['resource'];

                    $groupItemIdFieldName  = $this->config['group_items']['fields']['id'];
                    $groupItemIdFieldAlias = 'group_item_id_'.uniqId();
                    $where->in($mainTable.'.'.$groupItemIdFieldName, $groupItemIds);

                    $joinColumnAliases = [
                        $mainTable => [
                            $this->config['group_items']['fields']['id'] =>
                                $groupItemIdFieldAlias,
                        ],
                        $groupTagSchema['resource'] => [
                            $groupTagSchema['field'] => $groupTagSchema['field'],
                        ],
                    ];

                    $query = $sql
                        ->select($mainTable)
                        ->columns(
                            [
                                $groupItemIdFieldAlias => $this->config['group_items']['fields']['id'],
                            ]
                        )
                        ->where($where);
                }

                self::completeQueryWithJoin($query, $joins, $joinColumnAliases);
                // $this->logger->debug(__METHOD__.'() '.$sql->buildSqlString($query).')');
                $stmt = $sql->prepareStatementForSqlObject($query);
                $rows = $stmt->execute();
                foreach ($rows as $row) {
                    $groupItemId = $row[$groupItemIdFieldAlias];
                    $groupItem   = $groupItems[$groupItemId];
                    if (false === array_key_exists($groupTagSchema['field'], $row)) {
                        throw new \Exception('Oups... ['.$groupTagSchema['field'].'] is not a valid tag (not found in database query response).');
                    }

                    $groupItem->addTagValue($tagId, $row[$groupTagSchema['field']]);
                    $this->logger->debug(__METHOD__.'() groupItem(#'.$groupItemId.')->addTagValue('.$tagId.','.$row[$groupTagSchema['field']].') i.e. groupItem belongs to groupe where tagId=value');
                    unset($unMatchedGroupItemIds[array_search($groupItemId, $unMatchedGroupItemIds)]);
                }

                foreach ($unMatchedGroupItemIds as $unMatchedGroupItemId) {
                    $groupItem = $groupItems[$unMatchedGroupItemId];
                    $groupItem->setTagValue($tagId, []);
                }
            }
        }
    }

    /**
     * Check whether the selected elements once sorted by groups match
     * the group rule (in this case min/max nb of selected items within the group)
     *
     * @param array  $selectedGroupItemIds Ids of the selected elements
     * @param string $minMaxCfgId          Id of the 'min-max' configuration to be applied
     *                                     The configuration includes
     *                                     - a resource: The name of the table where the min/max values per group are stored
     *                                     - an id: The name of the field containing the group id
     *                                     - min/max: The name of the fields containing the min and max values
     * @param array  $filter               Rule to apply to exclude unexpected items
     * @param int    $infinite             Value to be regarded as infinite in the configuration (is it -1, 0, 255, 99999, etc. ?)
     *
     * @return array of array with keys
     *   - 'msg'
     *   - 'setId'
     *   - etc....
     */
    public function getValidationErrorMsgsForNbOfSelectionsByMinMax($selectedGroupItemIds, $minMaxCfgId, $filter = null, $infinite = -1)
    {
        $requiredTableNames = [];

        if ((null !== $filter) && (true === array_key_exists('tags', $filter))) {
            foreach ($filter['tags'] as $tag => $tagValue) {
                $requiredTableNames[] = $this->config['group_items']['tags'][$tag]['resource'];
            }
        }

        // Get join clauses between pool table and table containing min/max by pool
        $joins = $this->dataPackageService->getJoinsFromTo(
            $this->dataPackage,
            $this->config['group_items']['resource'],
            $this->config['group_items']['min-max'][$minMaxCfgId]['resource'],
            $requiredTableNames
        );

        if (null === $joins) {
            throw new \Exception('Oups... Unable to get joins from table ['.$this->config['group_items']['resource'].'] to ['.$this->config['group_items']['min-max'][$minMaxCfgId]['resource'].']');
        }

        $joinColumnAliases = [
            $this->config['group_items']['min-max'][$minMaxCfgId]['resource'] => [
                $this->config['group_items']['min-max'][$minMaxCfgId]['id'] =>
                    $this->config['group_items']['min-max'][$minMaxCfgId]['resource'].'_id_'.uniqId(),
                $this->config['group_items']['min-max'][$minMaxCfgId]['min'] =>
                    $this->config['group_items']['min-max'][$minMaxCfgId]['resource'].'_min_'.uniqId(),
                $this->config['group_items']['min-max'][$minMaxCfgId]['max'] =>
                    $this->config['group_items']['min-max'][$minMaxCfgId]['resource'].'_max_'.uniqId(),
            ]
        ];

        $setIdFieldAlias = $joinColumnAliases[$this->config['group_items']['min-max'][$minMaxCfgId]['resource']][$this->config['group_items']['min-max'][$minMaxCfgId]['id']];
        $minFieldAlias   = $joinColumnAliases[$this->config['group_items']['min-max'][$minMaxCfgId]['resource']][$this->config['group_items']['min-max'][$minMaxCfgId]['min']];
        $maxFieldAlias   = $joinColumnAliases[$this->config['group_items']['min-max'][$minMaxCfgId]['resource']][$this->config['group_items']['min-max'][$minMaxCfgId]['max']];

        // Get min/max for all sets of pools matching $filter
        // Required field are
        // - group item id
        // - set id
        // - min
        // - max
        $sql = new Sql($this->adapter);
        // $groupItemIdFieldAlias required as "id" can be ambiguous
        $groupItemIdFieldAlias = 'group_item_id_'.uniqId();
        $where = new Where();

        // FIXME where construction based on $filter should be in a dedicated method
        if ((null !== $filter) && (true === array_key_exists('tags', $filter))) {
            foreach ($filter['tags'] as $tag => $tagValue) {
                if ((false === array_key_exists($this->config['group_items']['tags'][$tag]['resource'], $joinColumnAliases))
                    ||(false === array_key_exists($this->config['group_items']['tags'][$tag]['field'], $joinColumnAliases[$this->config['group_items']['tags'][$tag]['resource']]))
                ) {
                    $joinColumnAliases[$this->config['group_items']['tags'][$tag]['resource']][$this->config['group_items']['tags'][$tag]['field']] = $this->config['group_items']['tags'][$tag]['resource'].'_'.$this->config['group_items']['tags'][$tag]['field'].'_'.uniqId();
                }

                $where->equalTo(
                    // $joinColumnAliases[$this->config['group_items']['tags'][$tag]['resource']][$this->config['group_items']['tags'][$tag]['field']],
                    $this->config['group_items']['tags'][$tag]['resource'].'.'.$this->config['group_items']['tags'][$tag]['field'],
                    $tagValue
                );
            }
        }

        $query = $sql
            ->select($this->config['group_items']['resource'])
            ->columns(
                [
                    $groupItemIdFieldAlias => $this->config['group_items']['fields']['id'],
                    // More field might be required in some cases ???
                ]
            )
            ->where($where);
        self::completeQueryWithJoin($query, $joins, $joinColumnAliases);

        $stmt = $sql->prepareStatementForSqlObject($query);
        $rows = $stmt->execute();

        $rules = [];
        foreach ($rows as $row) {
            $setId = $row[$setIdFieldAlias];
            if (false === array_key_exists($setId, $rules)) {
                $rules[$setId] = [
                    'groupItemIds' => [],
                    'min'          => $row[$minFieldAlias],
                    'max'          => $row[$maxFieldAlias],
                ];
            }

            $rules[$setId]['groupItemIds'][] = $row[$groupItemIdFieldAlias];
        }

        $errs = [];
        foreach ($rules as $setId => $rule) {
            $matchingGroupIds = array_intersect($selectedGroupItemIds, $rule['groupItemIds']);
            if ($rule['min'] == $infinite) {
                if (count($matchingGroupIds) !== count($rule['groupItemIds'])) {
                    $errs[] = [
                        'setId' => $setId,
                        'msg'   => 'All pools of set #'.$setId.' are required',
                    ];
                }
            } else if (count($matchingGroupIds) < $rule['min']) {
                $errs[] = [
                    'setId' => $setId,
                    'count' => count($matchingGroupIds),
                    'min'   => $rule['min'],
                    'max'   => $rule['max'],
                    'msg'   => 'Nb of selection ('.count($matchingGroupIds).') below min limit ('.$rule['min'].')',
                ];
            } else if (count($matchingGroupIds) > $rule['max']) {
                $errs[] = [
                    'setId' => $setId,
                    'count' => count($matchingGroupIds),
                    'min'   => $rule['min'],
                    'max'   => $rule['max'],
                    'msg'   => 'Nb of selection ('.count($matchingGroupIds).') above max limit ('.$rule['max'].')',
                ];
            }

            $selectedGroupItemIds = array_diff($selectedGroupItemIds, $matchingGroupIds);
        }

        if (count($selectedGroupItemIds) > 0) {
            $errs[] = [
                'msg' => implode(',', $selectedGroupItemIds).' are not allowed pool ids',
            ];
        }

        return $errs;
    }

    /**
     * Complete a Zend query taking into account joined tables given as array
     *
     * @param Sql   $query             A Zend query
     * @param array $joins             Associative array where keys are the name of the pivot table and the value an array of join parameters (mainly the "on" clause as a string)
     * @param array $joinColumnAliases Associative array where keys are the table names and the values an array of column name mapping
     *
     * @return void
     */
    protected function completeQueryWithJoin($query, $joins, $joinColumnAliases = null)
    {
        foreach ($joins as $joinTable => $joinParams) {
            // FIXME Do not return unused field values
            // FIXME Really? We can have several join for the same table??
            foreach ($joinParams['on'] as $on) {
                // Alias substitution in returned columns
                if (null === $joinColumnAliases) {
                    $columns = '*';
                } else if (true === array_key_exists($joinTable, $joinColumnAliases)) {
                    $columns = array_flip($joinColumnAliases[$joinTable]);
                } else {
                    $columns = [];
                }

                /*
                    // Alias substitution in "on" clause
                    $pieces = explode('=', $on);
                    for ($i=0; $i<2; $i++) {
                        $subPieces = explode('.', trim($pieces[$i]));
                        if (  (null !== $joinColumnAliases)
                            &&(array_key_exists($subPieces[0], $joinColumnAliases)) // TableName
                            &&(array_key_exists($subPieces[1], $joinColumnAliases[$subPieces[0]]))) { // ColumnName
                            $pieces[$i] = $joinColumnAliases[$subPieces[0]][$subPieces[1]];
                        }
                    }
                    $on = implode('=', $pieces);
                */

                $query->join($joinTable, $on, $columns);
            }
        }
    }
}
