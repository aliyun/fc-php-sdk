<?php

use AliyunFC\Client;
use PHPUnit\Framework\TestCase;

class VersioningTest extends TestCase {

    protected $fcClient;
    protected $opts;
    protected $codeBucket;
    protected $accoutId;
    protected $region;
    protected $invocationRoleOss;
    protected $invocationRoleSls;
    protected $logProject;
    protected $logstore;
    private $serviceName;

    public function setUp() {
        $this->serviceName = "Test-Php-SDK" . createUuid();
        $this->fcClient    = new Client([
            'accessKeyID'     => getenv('ACCESS_KEY_ID'),
            'accessKeySecret' => getenv('ACCESS_KEY_SECRET'),
            'endpoint'        => getenv('ENDPOINT'),
        ]);
        $this->clearAllTestRes();
        $this->initOpts();
        $this->codeBucket        = getenv('CODE_BUCKET');
        $this->accountId         = getenv('ACCOUNT_ID');
        $this->region            = getenv('REGION');
        $this->invocationRoleOss = getenv('INVOCATION_ROLE_OSS');
        $this->invocationRoleSls = getenv('INVOCATION_ROLE_SLS');
        $this->logProject        = getenv('LOG_PROJECT');
        $this->logStore          = getenv('LOG_STORE');
    }

    private function initOpts() {
        $role            = getenv('SERVICE_ROLE');
        $logProject      = getenv('LOG_PROJECT');
        $logStore        = getenv('LOG_STORE');
        $vpcId           = getenv('VPC_ID');
        $vSwitchIds      = getenv('VSWITCH_IDS');
        $securityGroupId = getenv('SECURITY_GROUP_ID');
        $vpcRole         = getenv('VPC_ROLE');
        $userId          = getenv('USER_ID');
        $groupId         = getenv('GROUP_ID');
        $nasServerAddr   = getenv('NAS_SERVER_ADDR');
        $nasMountDir     = getenv('NAS_MOUNT_DIR');

        $nasConfig = array(
            "userId"      => intval($userId),
            "groupId"     => intval($groupId),
            "mountPoints" => array(
                [
                    "serverAddr" => $nasServerAddr,
                    "mountDir"   => $nasMountDir,
                ],
            ),
        );

        $vpcConfig = array(
            'vpcId'           => $vpcId,
            'vSwitchIds'      => array($vSwitchIds),
            'securityGroupId' => $securityGroupId,
        );

        $this->opts = array(
            "logConfig"      => array(
                "project"  => $logProject,
                "logstore" => $logStore,
            ),
            "role"           => $role,
            "vpcConfig"      => $vpcConfig,
            //"nasConfig"      => $nasConfig,
            'internetAccess' => true,
        );
    }

    public function tearDown() {
        $this->clearAllTestRes();
    }

    private function clearAllTestRes() {
        try {
            $serviceName = $this->serviceName;
            $r           = $this->fcClient->listFunctions($serviceName);
            $functions   = $r['data']['functions'];
            foreach ($functions as $func) {
                $functionName = $func['functionName'];
                $tr           = $this->fcClient->listTriggers($serviceName, $functionName);
                $triggers     = $tr['data']['triggers'];
                foreach ($triggers as $t) {
                    $triggerName = $t['triggerName'];
                    $this->fcClient->deleteTrigger($serviceName, $functionName, $triggerName);
                }
                $this->fcClient->deleteFunction($serviceName, $functionName);
            }

            // clear all versions and alias
            $data      = $this->fcClient->listVersions($serviceName)['data'];
            $versions  = $data['versions'];
            $nextToken = isset($data['nextToken']) ? $data['nextToken'] : null;
            while ($nextToken != null || $nextToken != "") {
                $data      = $this->fcClient->listVersions($serviceName, ["nextToken" => nextToken])['data'];
                $versions  = array_merge($data['versions'], $versions);
                $nextToken = isset($data['nextToken']) ? $data['nextToken'] : null;

            }

            foreach ($versions as $v) {
                $this->fcClient->deleteVersion($serviceName, $v['versionId']);
            }

            $data      = $this->fcClient->listAliases($serviceName)['data'];
            $aliases   = $data['aliases'];
            $nextToken = isset($data['nextToken']) ? $data['nextToken'] : null;
            while ($nextToken != null || $nextToken != "") {
                $data      = $this->fcClient->listAliases($serviceName, ["nextToken" => nextToken])['data'];
                $aliases   = array_merge($data['aliases'], $aliases);
                $nextToken = isset($data['nextToken']) ? $data['nextToken'] : null;

            }

            foreach ($aliases as $a) {
                $this->fcClient->deleteAlias($serviceName, $a['aliasName']);
            }

            $this->fcClient->deleteService($serviceName);

            $data = $this->fcClient->listFunctionAsyncConfigs($serviceName, $functionName, ["limit"=>2])['data'];
            foreach ($data['configs'] as $c) {
                $this->fcClient->deleteFunctionAsyncConfig($c['service'], $c['qualifier'], $c['function']);

            }
        } catch (Exception $e) {
        }
    }

    public function testVersion() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );

        $data = $this->fcClient->publishVersion($serviceName, "test service v1")['data'];
        $this->assertTrue(isset($data['versionId']));
        $this->assertEquals($data['description'], 'test service v1');
        $this->assertTrue(isset($data['createdTime']));
        $this->assertTrue(isset($data['lastModifiedTime']));
        $v1 = $data['versionId'];

        $err = '';
        try {
            $this->fcClient->publishVersion($serviceName, "test service v2");
        } catch (Exception $e) {
            $err = $e->getMessage();
        }

        $this->assertTrue($err != "");

        $err = '';
        try {
            $this->fcClient->deleteService($serviceName);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != "");

        $this->fcClient->deleteVersion($serviceName, $v1);

        $data     = $this->fcClient->listVersions($serviceName, ['limit' => 2])['data'];
        $versions = $data['versions'];
        $this->assertEquals(count($versions), 0);

        $num = 6;
        for ($i = 0; $i < $num; $i++) {
            $desc    = 'service description' . strval($i);
            $service = $this->fcClient->updateService($serviceName, $desc);
            $this->assertEquals($service['data']['description'], $desc);
            $version = strVAL($i + 2);
            $r       = $this->fcClient->publishVersion($serviceName, "test service v" . $version);
            $data    = $r['data'];

            $this->assertTrue(isset($data['versionId']));
            $this->assertEquals($data['description'], "test service v" . $version);
            $this->assertTrue(isset($data['createdTime']));
            $this->assertTrue(isset($data['lastModifiedTime']));
        }

        $data      = $this->fcClient->listVersions($serviceName, ['limit' => 2])['data'];
        $versions  = $data['versions'];
        $nextToken = $data['nextToken'];
        $this->assertEquals(count($versions), 2);
        for ($i = 0; $i < 2; $i++) {
            $data = $versions[$i];
            $this->assertTrue(isset($data['versionId']));
            $this->assertTrue(isset($data['description']));
            $this->assertTrue(isset($data['createdTime']));
            $this->assertTrue(isset($data['lastModifiedTime']));
        }

        $this->assertTrue($nextToken != null);
        $versions_len = 2;
        while ($nextToken != null || $nextToken != "") {
            $data      = $this->fcClient->listVersions($serviceName, ["nextToken" => $nextToken])["data"];
            $versions  = $data['versions'];
            $nextToken = isset($data['nextToken']) ? $data['nextToken'] : null;
            $versions_len += count($versions);
        }

        $this->assertEquals($versions_len, 6);

    }

    public function testAlias() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );

        $data = $this->fcClient->publishVersion($serviceName, "test service v1")['data'];
        $this->assertTrue(isset($data['versionId']));
        $this->assertEquals($data['description'], 'test service v1');
        $this->assertTrue(isset($data['createdTime']));
        $this->assertTrue(isset($data['lastModifiedTime']));
        $v1 = $data['versionId'];

        $r_data = $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'test',
                'versionId'               => $v1,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.9],
            ])['data'];

        $this->assertEquals($r_data['aliasName'], "test");
        $this->assertEquals($r_data['versionId'], $v1);
        $this->assertEquals($r_data['description'], "test alias");
        $this->assertEquals($r_data['additionalVersionWeight'], ["1" => 0.9]);
        $this->assertTrue(isset($r_data['createdTime']));
        $this->assertTrue(isset($r_data['lastModifiedTime']));

        $r_data = $this->fcClient->getAlias($serviceName, "test")['data'];
        $this->assertEquals($r_data['aliasName'], "test");
        $this->assertEquals($r_data['versionId'], $v1);
        $this->assertEquals($r_data['description'], "test alias");
        $this->assertEquals($r_data['additionalVersionWeight'], ["1" => 0.9]);
        $this->assertTrue(isset($r_data['createdTime']));
        $this->assertTrue(isset($r_data['lastModifiedTime']));

        $r_data = $this->fcClient->updateAlias($serviceName, "test",
            ['versionId'              => $v1,
                'description'             => "test alias_update",
                'additionalVersionWeight' => ["1" => 0.8],
            ])['data'];
        $this->assertEquals($r_data['aliasName'], "test");
        $this->assertEquals($r_data['versionId'], $v1);
        $this->assertEquals($r_data['description'], "test alias_update");
        $this->assertEquals($r_data['additionalVersionWeight'], ["1" => 0.8]);
        $this->assertTrue(isset($r_data['createdTime']));
        $this->assertTrue(isset($r_data['lastModifiedTime']));

        $this->fcClient->deleteAlias($serviceName, "test");

        $err = '';
        try {
            $this->fcClient->getAlias($serviceName, "test")['data'];
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != "");

        $num = 6;
        for ($i = 0; $i < $num; $i++) {
            $desc    = 'service description' . strval($i);
            $service = $this->fcClient->updateService($serviceName, $desc);
            $this->assertEquals($service['data']['description'], $desc);
            $version = strval($i + 2);
            $this->fcClient->publishVersion($serviceName, "test service v" . $version);

            $this->fcClient->createAlias($serviceName,
                ['aliasName'              => "test" . strval($version),
                    'versionId'               => $v1,
                    'description'             => "test alias" . strval($version),
                    'additionalVersionWeight' => ["1" => 0.9],
                ]);
        }

        $data      = $this->fcClient->listAliases($serviceName, ['limit' => 2])['data'];
        $aliases   = $data['aliases'];
        $nextToken = $data['nextToken'];
        $this->assertEquals(count($aliases), 2);
        for ($i = 0; $i < 2; $i++) {
            $data = $aliases[$i];
            $this->assertTrue(isset($data['aliasName']));
            $this->assertTrue(isset($data['versionId']));
            $this->assertTrue(isset($data['description']));
            $this->assertTrue(isset($data['createdTime']));
            $this->assertTrue(isset($data['lastModifiedTime']));
        }

        $this->assertTrue($nextToken != null);

        $versions_len = 2;
        while ($nextToken != null || $nextToken != "") {
            $data      = $this->fcClient->listAliases($serviceName, ["nextToken" => $nextToken])["data"];
            $aliases   = $data['aliases'];
            $nextToken = isset($data['nextToken']) ? $data['nextToken'] : null;
            $versions_len += count($aliases);
        }

        $this->assertEquals($versions_len, 6);
    }

    public function testGetService() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );

        $data = $this->fcClient->publishVersion($serviceName, "test service v1 desc")['data'];
        $this->assertTrue(isset($data['versionId']));
        $this->assertEquals($data['description'], 'test service v1 desc');
        $this->assertTrue(isset($data['createdTime']));
        $this->assertTrue(isset($data['lastModifiedTime']));
        $v1 = $data['versionId'];

        $service = $this->fcClient->getService($serviceName, [], $v1)['data'];
        $this->assertEquals($service['serviceName'], $serviceName);
        $this->assertEquals($service['description'], "test service v1 desc");

        $service = $this->fcClient->getService($serviceName)['data'];
        $this->assertEquals($service['serviceName'], $serviceName);
        $this->assertEquals($service['description'], $serviceDesc);

        $service = $this->fcClient->updateService($serviceName, "update test service v2 desc");
        $data    = $this->fcClient->publishVersion($serviceName, "test service v2 desc")['data'];
        $v2      = $data['versionId'];

        $service = $this->fcClient->getService($serviceName, [], $v2)['data'];
        $this->assertEquals($service['serviceName'], $serviceName);
        $this->assertEquals($service['description'], "test service v2 desc");

        $service = $this->fcClient->getService($serviceName)['data'];
        $this->assertEquals($service['serviceName'], $serviceName);
        $this->assertEquals($service['description'], "update test service v2 desc");

        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'test',
                'versionId'               => $v1,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.9],
            ]);

        $service = $this->fcClient->getService($serviceName, [], "test")['data'];
        $this->assertEquals($service['serviceName'], $serviceName);
        $this->assertEquals($service['description'], "test service v1 desc");
    }

    public function testGetFunction() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );

        $functionName = 'test_function';
        $desc         = '这是测试function';

        $function = $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName' => $functionName,
                'handler'      => 'index.handler',
                'runtime'      => 'php7.2',
                'memorySize'   => 128,
                'code'         => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index.zip')),
                ),
                'description'  => $desc,
            )
        );

        $function = $function['data'];
        $checksum = $function['codeChecksum'];
        $this->checkFunction($functionName, $desc, $checksum, 'php7.2', 'index.handler');

        $data = $this->fcClient->publishVersion($serviceName, "test service v1 desc")['data'];
        $v1   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'test',
                'versionId'               => $v1,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.9],
            ]);

        $this->checkFunction($functionName, $desc, $checksum, 'php7.2', 'index.handler', $v1);
        $this->checkFunction($functionName, $desc, $checksum, 'php7.2', 'index.handler', "test");

        $data = $this->fcClient->putProvisionConfig($serviceName, "test", $functionName, ["target"=>10])['data'];
        $this->assertEquals(10, $data['target']);
        $this->assertEquals($this->accountId."#". $serviceName . "#test#". $functionName, $data['resource']);

        $data = $this->fcClient->getProvisionConfig($serviceName, "test", $functionName)['data'];
        $this->assertEquals(10, $data['target']);
        $this->assertEquals($this->accountId."#". $serviceName . "#test#". $functionName, $data['resource']);
        $this->assertTrue($data['current']>=0);

        $data = $this->fcClient->listProvisionConfigs($serviceName, "test")['data'];
        $this->assertEquals(1, count($data['provisionConfigs']));
        $data = $data['provisionConfigs'][0];
        $this->assertEquals(10, $data['target']);
        $this->assertEquals($this->accountId."#". $serviceName . "#test#". $functionName, $data['resource']);
        $this->assertTrue($data['current']>=0);

        $err = '';
        try {
           $this->fcClient->listProvisionConfigs($serviceName, "test", ["limit"=>0])['data'];
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != "");

        $this->fcClient->putProvisionConfig($serviceName, "test", $functionName, ["target"=>0])['data'];

        $ret = $this->fcClient->updateFunction(
            $serviceName,
            $functionName,
            array(
                'handler'              => 'hello_world.handler',
                'runtime'              => 'nodejs6',
                'memorySize'           => 256,
                'code'                 => array(
                    'ossBucketName' => $this->codeBucket,
                    'ossObjectName' => "hello_world_nodejs.zip",
                ),
                'description'          => "update function desc",
                'environmentVariables' => ['newTestKey' => 'newTestValue'],
            )
        );
        $function  = $ret['data'];
        $checksum2 = $function['codeChecksum'];
        $this->assertTrue($checksum != $checksum2);
        $this->checkFunction($functionName, "update function desc", $checksum2, 'nodejs6', 'hello_world.handler');
        $data = $this->fcClient->publishVersion($serviceName, "test service v2 desc")['data'];
        $v2   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'prod',
                'versionId'               => $v2,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.8],
            ]);
        $this->checkFunction($functionName, "update function desc", $checksum2, 'nodejs6', 'hello_world.handler', $v2);
        $this->checkFunction($functionName, "update function desc", $checksum2, 'nodejs6', 'hello_world.handler', "prod");

    }

    private function checkFunction($functionName, $desc, $checksum, $runtime = 'python2.7', $handler = 'index.handler', $qualifier = null) {
        $serviceName = $this->serviceName;
        $function    = $this->fcClient->getFunction($serviceName, $functionName, [], $qualifier)['data'];
        $this->assertEquals($function['functionName'], $functionName);
        $this->assertEquals($function['runtime'], $runtime);
        $this->assertEquals($function['handler'], $handler);
        $this->assertEquals($function['description'], $desc);

        $code = $this->fcClient->getFunctionCode($serviceName, $functionName, [], $qualifier)['data'];
        $this->assertEquals($code['checksum'], $checksum);
        $this->assertTrue($code['url'] != '');
    }

    public function testListFunctions() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );
        $prefix = 'test_list_';

        $f = function ($serviceName, $functionName) {
            $this->fcClient->createFunction(
                $serviceName,
                array(
                    'functionName' => $functionName,
                    'handler'      => 'index.handler',
                    'runtime'      => 'php7.2',
                    'code'         => array(
                        'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index.zip')),
                    ),
                )
            );
        };

        $f($serviceName, $prefix . "abc");
        $f($serviceName, $prefix . "abd");
        $f($serviceName, $prefix . "ade");

        $data = $this->fcClient->publishVersion($serviceName, "test service v1 desc")['data'];
        $v1   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'test',
                'versionId'               => $v1,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.9],
            ]);

        $f($serviceName, $prefix . "bcd");
        $f($serviceName, $prefix . "bde");
        $f($serviceName, $prefix . "zzz");

        $data = $this->fcClient->publishVersion($serviceName, "test service v2 desc")['data'];
        $v2   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'prod',
                'versionId'               => $v2,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.8],
            ]);

        $r         = $this->fcClient->listFunctions($serviceName)['data'];
        $functions = $r['functions'];
        $this->assertEquals(count($functions), 6);

        $r1 = $this->fcClient->listFunctions($serviceName, ["qualifier" => $v1])['data'];
        $r2 = $this->fcClient->listFunctions($serviceName, ["qualifier" => "test"])['data'];
        $this->assertEquals($r1, $r2);
        $functions = $r1['functions'];
        $this->assertEquals(count($functions), 3);

        $r3 = $this->fcClient->listFunctions($serviceName, ["qualifier" => $v2])['data'];
        $r4 = $this->fcClient->listFunctions($serviceName, ["qualifier" => "prod"])['data'];
        $this->assertEquals($r3, $r4);
        $functions = $r3['functions'];
        $this->assertEquals(count($functions), 6);
    }

    public function testAsyncConfig() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );

        $functionName = 'test_function';
        $desc         = '这是测试function';

        $function = $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName' => $functionName,
                'handler'      => 'index.handler',
                'runtime'      => 'php7.2',
                'memorySize'   => 128,
                'code'         => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index.zip')),
                ),
                'description'  => $desc,
            )
        );

        $function = $function['data'];
        $checksum = $function['codeChecksum'];
        $this->checkFunction($functionName, $desc, $checksum, 'php7.2', 'index.handler');

        $data = $this->fcClient->publishVersion($serviceName, "test service v1 desc")['data'];
        $v1   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'test',
                'versionId'               => $v1,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.9],
            ]);

        $this->checkFunction($functionName, $desc, $checksum, 'php7.2', 'index.handler', $v1);
        $this->checkFunction($functionName, $desc, $checksum, 'php7.2', 'index.handler', "test");

        $destination = sprintf('acs:fc:%s:%s:services/%s/functions/fc2', $this->region, $this->accoutId, $serviceName);
        $asyncConfig = [
            'destinationConfig' => [
                'onSuccess' => ['destination'=>$destination],
            ],
            'maxAsyncEventAgeInSeconds' => 100,
            'maxAsyncRetryAttempts'  => 1,
        ];
        $data = $this->fcClient->putFunctionAsyncConfig($serviceName, "test", $functionName, $asyncConfig)['data'];

        $this->assertEquals($serviceName, $data['service']);
        $this->assertEquals("test", $data['qualifier']);
        $this->assertEquals($functionName, $data['function']);
        $this->assertEquals($destination, $data['destinationConfig']['onSuccess']['destination']);
        $this->assertNotEmpty($data['lastModifiedTime']);
        $this->assertNotEmpty($data['createdTime']);
        $this->assertEquals(100, $data['maxAsyncEventAgeInSeconds']);
        $this->assertEquals(1, $data['maxAsyncRetryAttempts']);

        $data = $this->fcClient->getFunctionAsyncConfig($serviceName, "test", $functionName)['data'];
        $this->assertEquals($serviceName, $data['service']);
        $this->assertEquals("test", $data['qualifier']);
        $this->assertEquals($functionName, $data['function']);

        $data = $this->fcClient->listFunctionAsyncConfigs($serviceName, $functionName, ["limit"=>2])['data'];
        $this->assertEquals(1, count($data['configs']));

        $this->fcClient->deleteFunctionAsyncConfig($serviceName, "test", $functionName);

        $data2 = $this->fcClient->putFunctionAsyncConfig($serviceName, "LATEST", $functionName, $asyncConfig)['data'];
        $this->assertEquals("LATEST", $data2['qualifier']);

        $this->fcClient->deleteFunctionAsyncConfig($serviceName, "LATEST", $functionName);
    }

    public function testInvokeFunciton() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );
        $functionName = 'test_function';
        $desc         = '这是测试function';

        $function = $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName' => $functionName,
                'handler'      => 'index.handler',
                'runtime'      => 'php7.2',
                'memorySize'   => 128,
                'code'         => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index.zip')),
                ),
                'description'  => $desc,
            )
        );
        $data = $this->fcClient->publishVersion($serviceName, "test service v1 desc")['data'];
        $v1   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'test',
                'versionId'               => $v1,
                'description'             => 'test alias',
                'additionalVersionWeight' => [$v1 => 1],
            ]);

        $ret = $this->fcClient->updateFunction(
            $serviceName,
            $functionName,
            array(
                'handler'     => 'index.handler',
                'runtime'     => 'php7.2',
                'memorySize'  => 128,
                'code'        => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/new_index.zip')),
                ),
                'description' => "update function desc",
            )
        );

        $data = $this->fcClient->publishVersion($serviceName, "test service v2 desc")['data'];
        $v2   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'prod',
                'versionId'               => $v2,
                'description'             => 'test alias',
                'additionalVersionWeight' => [$v2 => 1],
            ]);

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName);
        $this->assertEquals($invkRet['data'], 'new hello world');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName, '', [], $v1);
        $this->assertEquals($invkRet['data'], 'hello world');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName, '', [], "test");
        $this->assertEquals($invkRet['data'], 'hello world');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName, '', [], $v2);
        $this->assertEquals($invkRet['data'], 'new hello world');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName, '', [], "prod");
        $this->assertEquals($invkRet['data'], 'new hello world');

    }

    public function disTrigger() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );
        $functionName = 'test_function';
        $desc         = '这是测试function';

        $function = $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName' => $functionName,
                'handler'      => 'index.handler',
                'runtime'      => 'php7.2',
                'memorySize'   => 128,
                'code'         => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index.zip')),
                ),
                'description'  => $desc,
            )
        );

        $data = $this->fcClient->publishVersion($serviceName, "test service v1 desc")['data'];
        $v1   = $data['versionId'];
        $this->fcClient->createAlias($serviceName,
            ['aliasName'              => 'test',
                'versionId'               => $v1,
                'description'             => 'test alias',
                'additionalVersionWeight' => ["1" => 0.9],
            ]);

        $triggerType   = 'oss';
        $triggerName   = 'test-trigger-oss';
        $sourceArn     = sprintf("acs:oss:%s:%s:%s", $this->region, $this->accountId, $this->codeBucket);
        $prefix        = 'pre' . createUuid();
        $suffix        = 'suf' . createUuid();
        $triggerConfig = [
            'events' => ['oss:ObjectCreated:*'],
            'filter' => [
                'key' => [
                    'prefix' => $prefix,
                    'suffix' => $suffix,
                ],
            ],
        ];
        $ret = $this->fcClient->createTrigger(
            $serviceName,
            $functionName,
            array(
                'triggerName'    => $triggerName,
                'triggerType'    => $triggerType,
                'invocationRole' => $this->invocationRoleOss,
                'sourceArn'      => $sourceArn,
                'triggerConfig'  => $triggerConfig,
                'qualifier'      => $v1,
            )
        );
        $triggerData = $ret['data'];
        $this->checkTriggerResponse($triggerData, $triggerName, $triggerType, $triggerConfig, $sourceArn, $this->invocationRoleOss, $v1);

        $prefixUpdate        = $prefix . 'update';
        $suffixUpdate        = $suffix . 'update';
        $triggerConfigUpdate = [
            'events' => ['oss:ObjectCreated:*'],
            'filter' => [
                'key' => [
                    'prefix' => $prefixUpdate,
                    'suffix' => $suffixUpdate,
                ],
            ],
        ];

        $ret = $this->fcClient->updateTrigger(
            $serviceName,
            $functionName,
            $triggerName,
            array(
                'invocationRole' => $this->invocationRoleOss,
                'triggerConfig'  => $triggerConfigUpdate,
                'qualifier'      => $v1,
            )
        );

        $updateTriggerData = $ret['data'];
        $this->checkTriggerResponse($updateTriggerData, $triggerName, $triggerType, $triggerConfigUpdate, $sourceArn, $this->invocationRoleOss, $v1);

        $ret = $this->fcClient->updateTrigger(
            $serviceName,
            $functionName,
            $triggerName,
            array(
                'invocationRole' => $this->invocationRoleOss,
                'triggerConfig'  => $triggerConfigUpdate,
                'qualifier'      => "test",
            )
        );

        $updateTriggerData = $ret['data'];
        $this->checkTriggerResponse($updateTriggerData, $triggerName, $triggerType, $triggerConfigUpdate, $sourceArn, $this->invocationRoleOss, "test");

    }

    private function checkTriggerResponse($resp, $triggerName, $triggerType, $triggerConfig, $sourceArn, $invocationRole, $qualifier) {
        $this->assertEquals($resp['triggerName'], $triggerName);
        $this->assertEquals($resp['triggerType'], $triggerType);
        $this->assertEquals($resp['sourceArn'], $sourceArn);
        $this->assertEquals($resp['invocationRole'], $invocationRole);
        $this->assertTrue(isset($resp['createdTime']));
        $this->assertTrue(isset($resp['lastModifiedTime']));
        $this->assertEquals($resp['triggerConfig'], $triggerConfig);
        $this->assertEquals($resp['qualifier'], $qualifier);
    }

}