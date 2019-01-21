<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\DbUnit\TestCaseTrait;

use PHPFacile\Group\ByTags\Db\Service\GroupService;
use PHPFacile\Group\ByTags\Model\GroupItem;

use PHPFacile\DataPackage\TableSchema\Db\Service\DataPackageService;

use Zend\Log\Writer\Noop;
use Zend\Log\Logger;
use Zend\Log\PsrLoggerAdapter;

final class GroupServiceMultiSelectCaseTest extends TestCase
{
    use TestCaseTrait {
        TestCaseTrait::setUp as parentSetUp;
    }

    protected $groupService;
    protected $dbName;
    protected $sqlRefDataBaseName = 'ref_database_multiselect.sqlite';

    public function getDataSet()
    {
        return $this->createMySQLXMLDataSet(__DIR__.'/db-init_training.xml');
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
            $this->dbName = '/tmp/group-by-tags-db_test_'.date('YmdHid').'.sqlite';
            copy(__DIR__.'/'.$this->sqlRefDataBaseName, $this->dbName);
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
            $this->dbName = '/tmp/group-by-tags-db_test_'.date('YmdHid').'.sqlite';
            copy(__DIR__.'/'.$this->sqlRefDataBaseName, $this->dbName);
        }
        $config = [
            'driver' => 'Pdo_Sqlite',
            'database' => $this->dbName,
        ];
        $adapter = new Zend\Db\Adapter\Adapter($config);

        $groupService = new GroupService($adapter);
        $groupService->setLogger($logger);

        $dataPackage = json_decode(json_encode([ // Cf. http://frictionlessdata.io/specs/data-package/
            'resources' => [
                // Cf. https://frictionlessdata.io/specs/table-schema/
                [
                    'name' => 'courses',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'id',
                            ],
                            [
                                'name' => 'subject_id',
                            ]
                        ]
                    ]
                ],
                [
                    'name' => 'course_sets',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'id',
                            ],
                            [
                                'name' => 'label',
                            ]
                        ]
                    ]
                ],
                [
                    'name' => 'course_set_courses',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'course_set_id',
                            ],
                            [
                                'name' => 'course_id',
                            ],
                        ]
                    ],
                    'foreignKeys' => [
                        [
                            'fields' => 'course_id',
                            'reference' => [
                                'resource' => 'courses',
                                'fields' => 'id'
                            ]
                        ],
                        [ // Faire le lien avec le même bloc dans training_course_sets
                            'fields' => 'course_set_id',
                            'reference' => [
                                'resource' => 'course_sets',
                                'fields' => 'id'
                            ]
                        ],
                    ]
                ],
                [
                    'name' => 'training_course_sets',
                    'schema' => [
                        'fields' => [
                            [
                                'name' => 'training_id',
                            ],
                            [
                                'name' => 'course_set_id',
                            ],
                            [
                                'name' => 'min_selections'
                            ],
                            [
                                'name' => 'max_selections'
                            ]
                        ]
                    ],
                    'foreignKeys' => [
                        [
                            'fields' => 'training_id',
                            'reference' => [
                                'resource' => 'trainings',
                                'fields' => 'id'
                            ]
                        ],
                        [
                            'fields' => 'course_set_id',
                            'reference' => [
                                'resource' => 'course_sets',
                                'fields' => 'id'
                            ]
                        ],
                    ]
                ]
            ]
        ]));

        $groupItemsCfg = [
            'group_items' => [
                'resource' => 'courses',
                'fields' => [
                    'id' => 'id',
                ],
                'tags' => [
                    'training_id' => [
                        'resource' => 'training_course_sets',
                        'field' => 'training_id'
                    ],
                    'course_set_id' => [
                        'resource' => 'course_set_courses',
                        'field' => 'course_set_id'
                    ],
                    'subject_id' => [
                        'resource' => 'courses',
                        'field' => 'subject_id'
                    ]
                ],
                'min-max' => [
                    'by_course_set' => [
                        'resource' => 'training_course_sets',
                        'id' => 'course_set_id',
                        'min' => 'min_selections',
                        'max' => 'max_selections'
                    ]
                ]
            ]
        ];
        $groupService->setConfig($groupItemsCfg, $dataPackage);
        $groupService->setGroupItemFactory(new GroupItemFactory2());
        $this->groupService = $groupService;

//        $quotaService = new DummyQuotaService2();
//        $this->groupService->setQuotaService($quotaService);
    }

    /**
     * @testdox The library must be able to return the full list of pools
     */
     public function testItMustBePossibleToGetFullListOfPools()
     {
         $groupItems = $this->groupService->getAllGroupItems();
         $this->assertEquals(12, count($groupItems));
     }

    /**
     * @testdox The library must be able to return the list of pools having a given tag (complex tag links)
     */
    public function testItMustBePossibleToGetListOfPoolsWithAGivenTagWhereTagLinkIsComplex()
    {
        //copy($this->dbName, '/tmp/testdb.sqlite');
        $groupItems = $this->groupService->getGroupItems(['tags' => ['training_id' => 1]]);
        $this->assertEquals(10, count($groupItems));

        $groupItems = $this->groupService->getGroupItems(['tags' => ['training_id' => 2]]);
        $this->assertEquals(8, count($groupItems));

        $groupItems = $this->groupService->getGroupItems(['tags' => ['training_id' => 9999]]);
        $this->assertEquals(0, count($groupItems));
    }

    public function testA1()
    {
        $this->groupService->setTagsSelectionOrder(['training_id', 'course_set_id']);
        $trainingValues = $this->groupService->getNextOptionValues();
        $this->assertEquals(['training_id'], array_keys($trainingValues));
        $this->assertEquals([1, 2], array_values($trainingValues['training_id']));
    }

    public function testA2()
    {
        /*
          Hummmmm... Who is wrong? The implementation or the test ???
        */

        $cfg = ['storeNodeValuesAsArrayKeys' => true];

        $poolTree = $this->groupService->getGroupItemsTree(['training_id'], null, null, $cfg);
        $this->assertEquals([1, 2], array_keys($poolTree));
        $this->assertEquals(10, count($poolTree[1]));
        $this->assertEquals(8, count($poolTree[2]));

        $poolTree = $this->groupService->getGroupItemsTree(['training_id', 'course_set_id'], null, null, $cfg);
        $this->assertEquals([1, 2], array_keys($poolTree));
        $this->assertEquals([1, 2, 4], array_keys($poolTree[1]));
        $this->assertEquals(2, count($poolTree[1][1]));
        $this->assertEquals(4, count($poolTree[1][2]));
        //$this->assertEquals(2, count($poolTree[1][3]));
        $this->assertEquals(4, count($poolTree[1][4]));
        $this->assertEquals([1, 3, 4], array_keys($poolTree[2]));
        $this->assertEquals(2, count($poolTree[2][1]));
        $this->assertEquals(2, count($poolTree[2][3]));
        //$this->assertEquals(2, count($poolTree[1][3]));
        $this->assertEquals(4, count($poolTree[2][4]));

        $poolTree = $this->groupService->getGroupItemsTree(['training_id', 'course_set_id', 'subject_id'], null, null, $cfg);
        $this->assertEquals([1, 2], array_keys($poolTree));
        $this->assertEquals([1, 2, 4], array_keys($poolTree[1]));
        $this->assertEquals(['null'], array_keys($poolTree[1][1]));
        $this->assertEquals([1, 2], array_keys($poolTree[1][2]));
        $this->assertEquals(['null'], array_keys($poolTree[1][4]));
        $this->assertEquals(2, count($poolTree[1][2][1]));
        $this->assertEquals(2, count($poolTree[1][2][2]));
        $this->assertEquals([1, 3, 4], array_keys($poolTree[2]));
        $this->assertEquals(['null'], array_keys($poolTree[2][1]));
        $this->assertEquals([3], array_keys($poolTree[2][3]));
        $this->assertEquals(['null'], array_keys($poolTree[2][4]));

        $poolTree = $this->groupService->getGroupItemsTree(['course_set_id'], ['tags' => ['training_id' => 1]], null, $cfg);
        $this->assertEquals([1, 2, 4], array_keys($poolTree));
        $this->assertEquals(2, count($poolTree[1]));
        $this->assertEquals(4, count($poolTree[2]));
        $this->assertEquals(4, count($poolTree[4]));


        $poolTree = $this->groupService->getGroupItemsTree(['course_set_id', 'subject_id'], ['tags' => ['training_id' => 1]], null, $cfg);
        $this->assertEquals([1, 2, 4], array_keys($poolTree));
        $this->assertEquals(1, count($poolTree[1]));
        $this->assertEquals(2, count($poolTree[2]));
        $this->assertEquals(1, count($poolTree[4]));
    }

    public function testMinMaxConstraints()
    {
        $selectedPoolIds = [1, 2, 3, 5, 10];
        $errorMsgs = $this->groupService->getValidationErrorMsgsForNbOfSelectionsByMinMax(
            $selectedPoolIds,
            'by_course_set',
            ['tags' => ['training_id' => 1]],
            255
        );
        $this->assertEquals(0, count($errorMsgs), var_export($errorMsgs, true));

        // No one pool selected in set 1 (where all are mandatory)
        $selectedPoolIds = [3, 5, 10];
        $errorMsgs = $this->groupService->getValidationErrorMsgsForNbOfSelectionsByMinMax(
            $selectedPoolIds,
            'by_course_set',
            ['tags' => ['training_id' => 1]],
            255
        );
        $this->assertEquals(1, count($errorMsgs), var_export($errorMsgs, true));

        // No one pool selected in set 2 (where at least one - in fact 2 - must be selected)
        $selectedPoolIds = [1, 2, 10];
        $errorMsgs = $this->groupService->getValidationErrorMsgsForNbOfSelectionsByMinMax(
            $selectedPoolIds,
            'by_course_set',
            ['tags' => ['training_id' => 1]],
            255
        );
        $this->assertEquals(1, count($errorMsgs), var_export($errorMsgs, true));

        // At least one selection in all sets... but not enough in some
        $selectedPoolIds = [1, 3, 9, 10];
        $errorMsgs = $this->groupService->getValidationErrorMsgsForNbOfSelectionsByMinMax(
            $selectedPoolIds,
            'by_course_set',
            ['tags' => ['training_id' => 1]],
            255
        );
        $this->assertEquals(3, count($errorMsgs), var_export($errorMsgs, true));
    }

    /**
     * @testdox The library must be able to return the list of values available for a given tag in a list of matching pools
     *//*
    public function testItMustBePossibleToGetListOfValuesAvailableForAGivenTagInMatchingPools()
    {
        $tagValues = $this->groupService->getValuesOfTagInMatchingPools('level_id');
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
    }*/
}

class GroupItemFactory2
{
    public function getById($groupItemId)
    {
        $groupItem = new GroupItem();
        $groupItem->setId($groupItemId);
        return $groupItem;
    }
}
