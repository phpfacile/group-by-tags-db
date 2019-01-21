<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\DbUnit\TestCaseTrait;

use PHPFacile\Group\ByTags\Db\Service\GroupService;
use PHPFacile\Group\ByTags\Model\GroupItem;

use Zend\Log\Writer\Noop;
use Zend\Log\Logger;
use Zend\Log\PsrLoggerAdapter;

final class GroupServiceTest extends TestCase
{
    use TestCaseTrait {
        TestCaseTrait::setUp as parentSetUp;
    }

    protected $groupService;
    protected $dbName;

    public function getDataSet()
    {
        return $this->createMySQLXMLDataSet(__DIR__.'/db-init.xml');
    }

/*
    public function getConnection()
    {
        putenv('APP_ENV=development');
        // Inclure APRES avoir positionné la variable d'environnement
        $configArray = include __DIR__ . '/../../config/autoload/local.php';
        $params = $configArray['db']['adapters']['PHPFacile\Booking'];
        $pdo = new PDO('mysql:dbname='.$params['dbname'],
                       $params['username'],
                       $params['password'],
                       $params['driver_options']);
        return $this->createDefaultDBConnection($pdo, $params['dbname']);
    }

    protected function setUp()
    {
        //parent::setUp(); // Required so as to rebuild the database (thanks to getDataSet()) but doesn't work like this in case of use of Trait
        $this->parentSetUp(); // Replacement for parent::setUp() in case of use of Trait
        putenv('APP_ENV=development');
        // Inclure APRES avoir positionné la variable d'environnement
        $configArray = include __DIR__ . '/../../config/autoload/local.php';
        $this->adapter = new Zend\Db\Adapter\Adapter($configArray['db']['adapters']['PHPFacile\Booking']);
    }
*/

    public function getConnection()
    {
        /*if (null === $this->connection) {
            if (null === $this->adapter) {
                if (null === $this->dbName) {
                    $this->dbName = '/tmp/parser_storage_test_'.date('YmdHid').'.sqlite';
                    copy(__DIR__.'/ref_database.sqlite', $this->dbName);
                }
                $config = [
                    'driver' => 'Pdo_Sqlite',
                    'database' => $this->dbName,
                ];
                $this->adapter = new Zend\Db\Adapter\Adapter($config);
            }
            $this->connection = $this->adapter->getDriver()->getConnection();
        }
        return $this->connection;*/
        if (null === $this->dbName) {
            $this->dbName = '/tmp/group-by-tags_test_'.date('YmdHid').'.sqlite';
            copy(__DIR__.'/ref_database.sqlite', $this->dbName);
        }
        $pdo = new PDO('sqlite:'.$this->dbName);
        return $this->createDefaultDBConnection($pdo, $this->dbName);
    }

    protected function setUp()
    {
        $writer = new Noop();
        $logger = new Logger();
        $logger->addWriter($writer);
        $logger = new PsrLoggerAdapter($logger);

        //parent::setUp(); // Required so as to rebuild the database (thanks to getDataSet()) but doesn't work like this in case of use of Trait
        $this->parentSetUp(); // Replacement for parent::setUp() in case of use of Trait
        if (null === $this->dbName) {
            $this->dbName = '/tmp/group-by-tags_test_'.date('YmdHid').'.sqlite';
            copy(__DIR__.'/ref_database.sqlite', $this->dbName);
        }
        $config = [
            'driver' => 'Pdo_Sqlite',
            'database' => $this->dbName,
        ];
        $adapter = new Zend\Db\Adapter\Adapter($config);

        $groupService = new GroupService($adapter);
        $groupService->setLogger($logger);

        $dataPackage = [ // Cf. http://frictionlessdata.io/specs/data-package/
            'resources' => [
                // Cf. https://frictionlessdata.io/specs/table-schema/
                [
                    'name' => 'pools',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'id',
                            ],
                            [
                                'name' => 'course_id',
                            ]
                        ]
                    ]
                ],
                [
                    'name' => 'pool_levels',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'pool_id',
                            ],
                            [
                                'name' => 'level_id',
                            ],
                        ]
                    ],
                    'foreignKeys' => [
                        [
                            'fields' => 'pool_id',
                            'reference' => [
                                'resource' => 'pools',
                                'fields' => 'id'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $groupCfg = [
            'group_items' => [
                'resource' => 'pools',
                'fields' => [
                    'id' => 'id',
                ],
                'tags' => [
                    'course_id' => [
                        'resource' => 'pools',
                        'field' => 'course_id'
                    ],
                    'level_id' => [
                        'resource' => 'pool_levels',
                        'field' => 'level_id',
                    ]
                ],
            ]
        ];
        $groupService->setConfig($groupCfg, $dataPackage);
        $groupService->setGroupItemFactory(new GroupItemFactory());
        $this->groupService = $groupService;



        $groupService = new GroupService($adapter);
        $groupService->setLogger($logger);

        $dataPackage = [ // Cf. http://frictionlessdata.io/specs/data-package/
            'resources' => [
                // Cf. https://frictionlessdata.io/specs/table-schema/
                [
                    'name' => 'lakes',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'id',
                            ]
                        ]
                    ]
                ],
                [
                    'name' => 'lake2lake_group',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'lake_id',
                            ],
                            [
                                'name' => 'lake_group_id',
                            ],
                        ]
                    ],
                    'foreignKeys' => [
                        [
                            'fields' => 'lake_id',
                            'reference' => [
                                'resource' => 'lakes',
                                'fields' => 'id'
                            ]
                        ],
                        [
                            'fields' => 'lake_group_id',
                            'reference' => [
                                'resource' => 'lake_groups',
                                'fields' => 'id'
                            ]
                        ],
                    ]
                ],
                [
                    'name' => 'lake_groups',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'id',
                            ]
                        ]
                    ]
                ],
                [
                    'name' => 'lake_group2country',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'lake_group_id',
                            ],
                            [
                                'name' => 'country_id',
                            ],
                        ]
                    ],
                    'foreignKeys' => [
                        [
                            'fields' => 'lake_group_id',
                            'reference' => [
                                'resource' => 'lake_groups',
                                'fields' => 'id'
                            ]
                        ],/*
                        [
                            'fields' => 'country_id',
                            'reference' => [
                                'resource' => 'countries',
                                'fields' => 'id'
                            ]
                        ]*/
                    ]
                ]
            ]
        ];

        $groupCfg = [
            'group_items' => [
                'resource' => 'lakes',
                'fields' => [
                    'id' => 'id',
                ],
                'tags' => [
                    'lake_group_id' => [
                        'resource' => 'lake2lake_group',
                        'field' => 'lake_group_id'
                    ],
                    'country_id' => [
                        'resource' => 'lake_group2country',
                        'field' => 'country_id',
                    ]
                ],
            ]
        ];
        $groupService->setConfig($groupCfg, $dataPackage);
        $groupService->setGroupItemFactory(new GroupItemFactory());
        $this->lakeGroupService = $groupService;
    }

    /**
     * @testdox The library must be able to return the full list of pools
     */
     public function testItMustBePossibleToGetFullListOfGroups()
     {
         $groupItems = $this->groupService->getAllGroupItems();
         $this->assertEquals(3, count($groupItems));
     }

     /**
      * @testdox The library must be able to return the list of pools having a given tag
      */
    public function testItMustBePossibleToGetListOfGroupsWithAGivenTagWhereTagIsInGroupTable()
    {
        $groupItems = $this->groupService->getGroupItems(['tags' => ['course_id' => 1]]);
        $this->assertEquals(2, count($groupItems));

        $groupItems = $this->groupService->getGroupItems(['tags' => ['course_id' => 2]]);
        $this->assertEquals(1, count($groupItems));

        $groupItems = $this->groupService->getGroupItems(['tags' => ['course_id' => 9999]]);
        $this->assertEquals(0, count($groupItems));
    }

    /**
     * @testdox The library must be able to return the list of pools having a given tag (multi value field stored in table linked to "pools")
     */
    public function testItMustBePossibleToGetListOfGroupsWithAGivenTagWhereTagIsMultiValuedAndStoredInATableLinkedToGroupTable()
    {
        $groupItems = $this->groupService->getGroupItems(['tags' => ['level_id' => 'beginner']]);
        $this->assertEquals(2, count($groupItems));

        $groupItems = $this->groupService->getGroupItems(['tags' => ['level_id' => 'medium']]);
        $this->assertEquals(1, count($groupItems));

        $groupItems = $this->groupService->getGroupItems(['tags' => ['level_id' => 'expert']]);
        $this->assertEquals(0, count($groupItems));

        $groupItems = $this->groupService->getGroupItems(['tags' => ['level_id' => 'master']]);
        $this->assertEquals(0, count($groupItems));
    }

    public function testItMustBePossibleBlablaWhenAGivenGroupItemBelongsToDifferentGroupsBlabla()
    {
        // Get lakes of Peru
        $groupItems = $this->lakeGroupService->getGroupItems(['tags' => ['country_id' => '1']]);
        $this->assertEquals(1, count($groupItems));
        $this->assertEquals('1', $groupItems[0]->getId()); // Id
    }

    public function testGetGroupTree()
    {
        // Get items of Peru (ex: lakes) sort be group of lakes (ex: lakes of peru)
        $tree = $this->lakeGroupService->getGroupItemsTree(
            ['lake_group_id'],
            ['tags' => ['country_id' => '1']],
            null,
            null);
        // FIXME It should not return 'lakes of Bolivia' (or only if explicitly asked)
        $this->assertEquals(1, count($tree->children));
    }


    /**
     * @testdox The library must be able to return the list of values available for a given tag in a list of matching pools
     */
    public function testItMustBePossibleToGetListOfValuesAvailableForAGivenTagInMatchingGroups()
    {
        $tagValues = $this->groupService->getValuesOfTagInMatchingGroups('level_id');
        $this->assertEquals(['beginner', 'medium'], array_values($tagValues));
    }



    public function testTBD()
    {
        $this->groupService->setTagsSelectionOrder(['course_id', 'level_id']);
        $courseValues = $this->groupService->getNextOptionValues();
        $this->assertEquals(['course_id'], array_keys($courseValues));
        // REM: $courseValues['course_id'] is an associative array but is not required to be so
        $this->assertEquals([1, 2], array_values($courseValues['course_id']));

        $levelValues = $this->groupService->getNextOptionValues(['course_id' => 1]);
        $this->assertEquals(['level_id'], array_keys($levelValues));
        // REM: $levelValues['level_id'] is an associative array but is not required to be so
        $this->assertEquals(['beginner', 'medium'], array_values($levelValues['level_id']));

        // Actually the next step is to select the pool
        $noMoreValue = $this->groupService->getNextOptionValues(['course_id' => 1, 'level_id' => 'beginner']);
        $this->assertEquals([], array_values($noMoreValue));
    }
}


class GroupItemFactory
{
    public function getById($poolId)
    {
        $pool = new GroupItem();
        $pool->setId($poolId);
        return $pool;
    }
}
