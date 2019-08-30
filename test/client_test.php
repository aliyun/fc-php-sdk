<?php

use AliyunFC\Client;
use PHPUnit\Framework\TestCase;

function createUuid($prefix = "") {
    $str  = md5(uniqid(mt_rand(), true));
    $uuid = substr($str, 0, 8) . '-';
    $uuid .= substr($str, 8, 4) . '-';
    $uuid .= substr($str, 12, 4) . '-';
    $uuid .= substr($str, 16, 4) . '-';
    $uuid .= substr($str, 20, 12);
    return $prefix . $uuid;
}

class ClientTest extends TestCase {

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
            "nasConfig"      => $nasConfig,
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
            $this->fcClient->deleteService($serviceName);
        } catch (Exception $e) {
        }

        try {
            $this->fcClient->deleteService($this->serviceName . "abc");
        } catch (Exception $e) {
        }

        try {
            $this->fcClient->deleteService($this->serviceName . "abd");
        } catch (Exception $e) {
        }

        try {
            $this->fcClient->deleteService($this->serviceName . "ade");
        } catch (Exception $e) {
        }

        try {
            $this->fcClient->deleteService($this->serviceName . "bcd");
        } catch (Exception $e) {
        }

        try {
            $this->fcClient->deleteService($this->serviceName . "bde");
        } catch (Exception $e) {
        }

        try {
            $this->fcClient->deleteService("Test-Php-SDK-zzz");
        } catch (Exception $e) {
        }
    }

    public function testServiceCRUD() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $ret         = $this->fcClient->createService(
            $serviceName,
            $serviceDesc,
            $options = $this->opts
        );

        $service = $ret['data'];
        $etag    = $ret['headers']['Etag'][0];

        $this->assertEquals($service['serviceName'], $serviceName);
        $this->assertEquals($service['description'], $serviceDesc);
        $this->assertTrue(isset($service['lastModifiedTime']));
        $this->assertTrue(isset($service['serviceId']));
        $this->assertEquals($service['logConfig'], $this->opts['logConfig']);
        $this->assertEquals($service['role'], $this->opts['role']);
        $this->assertEquals($service['vpcConfig'], $this->opts['vpcConfig']);
        $this->assertEquals($service['nasConfig'], $this->opts['nasConfig']);
        $this->assertEquals($service['internetAccess'], true);
        $this->assertTrue($etag != '');

        $ret     = $this->fcClient->getService($serviceName, $headers = ['x-fc-trace-id' => createUuid()]);
        $service = $ret['data'];
        $this->assertEquals($service['serviceName'], $serviceName);
        $this->assertEquals($service['description'], $serviceDesc);

        $serviceDesc                 = "测试的service";
        $opts["nasConfig"]["userId"] = -1;
        $ret                         = $this->fcClient->updateService($serviceName, $serviceDesc, $opts);
        $service                     = $ret['data'];
        $this->assertEquals($service['description'], $serviceDesc);
        $this->assertEquals($service['nasConfig']["userId"], -1);
        $etag = $ret['headers']['Etag'][0];

        $ret     = $this->fcClient->getService($serviceName);
        $service = $ret['data'];
        $this->assertEquals($service['description'], $serviceDesc);
        $this->assertEquals($service['nasConfig']["userId"], -1);

        $err = '';
        try {
            $this->fcClient->deleteService($serviceName, $headers = ['if-match' => 'invalid etag']);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertContains('"ErrorMessage":"the resource being modified has been changed"', $err);

        $this->fcClient->deleteService($serviceName, $headers = ['if-match' => $etag]);

        $this->subTestListServices();
        $this->clearAllTestRes();
    }

    private function subTestListServices() {
        $prefix = $this->serviceName;
        $this->fcClient->createService($prefix . "abc");
        $this->fcClient->createService($prefix . "abd");
        $this->fcClient->createService($prefix . "ade");
        $this->fcClient->createService($prefix . "bcd");
        $this->fcClient->createService($prefix . "bde");
        $this->fcClient->createService($prefix . "zzz");

        $r         = $this->fcClient->listServices(['startKey' => $prefix . 'b', "limit" => 2]);
        $r         = $r['data'];
        $services  = $r['services'];
        $nextToken = $r['nextToken'];
        $this->assertEquals(count($services), 2);
        $this->assertTrue(in_array($services[0]['serviceName'], [$prefix . 'bcd', $prefix . 'bde']));
        $this->assertTrue(in_array($services[1]['serviceName'], [$prefix . 'bcd', $prefix . 'bde']));

        $r        = $this->fcClient->listServices(['startKey' => $prefix . 'b', "limit" => 1, 'nextToken' => $nextToken]);
        $r        = $r['data'];
        $services = $r['services'];
        $this->assertEquals(count($services), 1);
        $this->assertEquals($services[0]['serviceName'], $prefix . 'zzz');

        // It's ok to omit the startKey and only provide continuationToken.
        // As long as the continuationToken is provided, the startKey is not considered.
        $r        = $this->fcClient->listServices(["limit" => 1, 'nextToken' => $nextToken]);
        $r        = $r['data'];
        $services = $r['services'];
        $this->assertEquals(count($services), 1);
        $this->assertEquals($services[0]['serviceName'], $prefix . 'zzz');

        $r        = $this->fcClient->listServices(['prefix' => $prefix . 'x', "limit" => 2]);
        $r        = $r['data'];
        $services = $r['services'];
        $this->assertEquals(count($services), 0);

        $r        = $this->fcClient->listServices(['prefix' => $prefix . 'a', "limit" => 2]);
        $r        = $r['data'];
        $services = $r['services'];
        $this->assertEquals(count($services), 2);
        $this->assertEquals($services[0]['serviceName'], $prefix . 'abc');
        $this->assertEquals($services[1]['serviceName'], $prefix . 'abd');

        $r        = $this->fcClient->listServices(['prefix' => $prefix . 'ab', "limit" => 2, 'startKey' => $prefix . 'abd']);
        $r        = $r['data'];
        $services = $r['services'];
        $this->assertEquals(count($services), 1);
        $this->assertEquals($services[0]['serviceName'], $prefix . 'abd');
    }

    private function checkFunction($function, $functionName, $desc, $runtime = 'php7.2',
        $handler = 'index.handler', $envs = ['TestKey' => 'TestValue'], $initializer = null) {
        $etag = $function['headers']['Etag'][0];
        $this->assertTrue($etag != '');
        $function = $function['data'];
        $this->assertEquals($function['functionName'], $functionName);
        $this->assertEquals($function['runtime'], $runtime);
        $this->assertEquals($function['handler'], $handler);
        $this->assertEquals($function['initializer'], $initializer);
        $this->assertEquals($function['description'], $desc);
        $this->assertEquals($function['environmentVariables'], $envs);
        $this->assertTrue(isset($function['codeChecksum']));
        $this->assertTrue(isset($function['codeSize']));
        $this->assertTrue(isset($function['createdTime']));
        $this->assertTrue(isset($function['lastModifiedTime']));
        $this->assertTrue(isset($function['functionId']));
        $this->assertTrue(isset($function['memorySize']));
        $this->assertTrue(isset($function['timeout']));
        $this->assertTrue(isset($function['initializationTimeout']));

        $serviceName = $this->serviceName;
        $checksum    = $function['codeChecksum'];
        $function    = $this->fcClient->getFunction($serviceName, $functionName, $headers = ['x-fc-trace-id' => createUuid()]);
        $function    = $function['data'];
        $this->assertEquals($function['functionName'], $functionName);
        $this->assertEquals($function['runtime'], $runtime);
        $this->assertEquals($function['handler'], $handler);
        $this->assertEquals($function['initializer'], $initializer);
        $this->assertEquals($function['description'], $desc);

        $code = $this->fcClient->getFunctionCode($serviceName, $functionName);
        $code = $code['data'];
        $this->assertEquals($code['checksum'], $checksum);
        $this->assertTrue($code['url'] != '');

        $err = '';
        try {
            $this->fcClient->deleteFunction($serviceName, $functionName, $headers = ['if-match' => 'invalid etag']);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertContains('"ErrorMessage":"the resource being modified has been changed"', $err);
    }

    public function testFunctionCRUDInitialize() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService($serviceName, $serviceDesc);
        $functionName = "test_initialize";

        $ret = $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName'         => $functionName,
                'handler'              => 'counter.handler',
                'initializer'          => 'counter.initializer',
                'runtime'              => 'php7.2',
                'memorySize'           => 128,
                'code'                 => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/counter.zip')),
                ),
                'description'          => "test function",
                'environmentVariables' => ['TestKey' => 'TestValue'],
            )
        );
        $this->checkFunction($ret, $functionName, 'test function', $runtime = 'php7.2', $handler = 'counter.handler', $envs = ['TestKey' => 'TestValue'], $initializer = 'counter.initializer');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName);
        $this->assertEquals($invkRet['data'], '2');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName, '', ['x-fc-invocation-type' => 'Async']);
        $this->assertEquals($invkRet['data'], '');
        $this->assertTrue(isset($invkRet['headers']['X-Fc-Request-Id']));

        $ret = $this->fcClient->updateFunction(
            $serviceName,
            $functionName,
            array(
                'functionName'         => $functionName,
                'handler'              => 'counter.handler',
                'initializer'          => 'counter.initializer',
                'runtime'              => 'php7.2',
                'memorySize'           => 128,
                'code'                 => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/counter.zip')),
                ),
                'description'          => "test update function",
                'environmentVariables' => ['newTestKey' => 'newTestValue'],
            )
        );
        $this->checkFunction($ret, $functionName, 'test update function', $runtime = 'php7.2', $handler = 'counter.handler', $envs = ['newTestKey' => 'newTestValue'], $initializer = 'counter.initializer');
        $etag = $ret['headers']['Etag'][0];
        # now success with valid etag.
        $this->fcClient->deleteFunction($serviceName, $functionName, $headers = ['if-match' => $etag]);

        $err = '';
        try {
            $this->fcClient->getFunction($serviceName, $functionName);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != '');

        $this->subTestListFunctions($serviceName);

        $this->clearAllTestRes();
    }

    public function testFunctionCRUDInvoke() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService($serviceName, $serviceDesc);
        $functionName = "test_invoke";

        $ret = $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName'         => $functionName,
                'handler'              => 'index.handler',
                'runtime'              => 'php7.2',
                'memorySize'           => 128,
                'code'                 => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index.zip')),
                ),
                'description'          => "test function",
                'environmentVariables' => ['TestKey' => 'TestValue'],
            )
        );
        $this->checkFunction($ret, $functionName, 'test function', $runtime = 'php7.2');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName, $payload = "hello world");
        $this->assertEquals($invkRet['data'], 'hello world');

        $invkRet = $this->fcClient->invokeFunction($serviceName, $functionName, '', ['x-fc-invocation-type' => 'Async']);
        $this->assertEquals($invkRet['data'], '');
        $this->assertTrue(isset($invkRet['headers']['X-Fc-Request-Id']));

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
                'description'          => "test update function",
                'environmentVariables' => ['newTestKey' => 'newTestValue'],
            )
        );
        $this->checkFunction($ret, $functionName, 'test update function', 'nodejs6', 'hello_world.handler', ['newTestKey' => 'newTestValue']);
        $etag = $ret['headers']['Etag'][0];
        # now success with valid etag.
        $this->fcClient->deleteFunction($serviceName, $functionName, $headers = ['if-match' => $etag]);

        $err = '';
        try {
            $this->fcClient->getFunction($serviceName, $functionName);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != '');

        $this->subTestListFunctions($serviceName);

        $this->clearAllTestRes();
    }

    private function subTestListFunctions($serviceName) {
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
        $f($serviceName, $prefix . "bcd");
        $f($serviceName, $prefix . "bde");
        $f($serviceName, $prefix . "zzz");

        $r         = $this->fcClient->listFunctions($serviceName, ['startKey' => $prefix . 'b', "limit" => 2]);
        $r         = $r['data'];
        $functions = $r['functions'];
        $nextToken = $r['nextToken'];
        $this->assertEquals(count($functions), 2);
        $this->assertEquals($functions[0]['functionName'], $prefix . 'bcd');
        $this->assertEquals($functions[1]['functionName'], $prefix . 'bde');

        $r         = $this->fcClient->listFunctions($serviceName, ['startKey' => $prefix . 'b', "limit" => 2, 'nextToken' => $nextToken]);
        $r         = $r['data'];
        $functions = $r['functions'];
        $this->assertEquals(count($functions), 1);
        $this->assertEquals($functions[0]['functionName'], $prefix . 'zzz');

        $r         = $this->fcClient->listFunctions($serviceName, ["limit" => 1, 'nextToken' => $nextToken]);
        $r         = $r['data'];
        $functions = $r['functions'];
        $this->assertEquals(count($functions), 1);
        $this->assertEquals($functions[0]['functionName'], $prefix . 'zzz');

        $r         = $this->fcClient->listFunctions($serviceName, ['prefix' => $prefix . 'x', "limit" => 2, 'nextToken' => $nextToken]);
        $r         = $r['data'];
        $functions = $r['functions'];
        $this->assertEquals(count($functions), 0);

        $r         = $this->fcClient->listFunctions($serviceName, ['prefix' => $prefix . 'a', "limit" => 2]);
        $r         = $r['data'];
        $functions = $r['functions'];
        $this->assertEquals(count($functions), 2);
        $this->assertEquals($functions[0]['functionName'], $prefix . 'abc');
        $this->assertEquals($functions[1]['functionName'], $prefix . 'abd');

        $r         = $this->fcClient->listFunctions($serviceName, ['prefix' => $prefix . 'ab', "limit" => 2, 'startKey' => $prefix . 'abd']);
        $r         = $r['data'];
        $functions = $r['functions'];
        $this->assertEquals(count($functions), 1);
        $this->assertEquals($functions[0]['functionName'], $prefix . 'abd');
    }

    public function testTriggerCRUD() {
        $serviceName = $this->serviceName;
        $serviceDesc = "测试的service, php sdk 创建";
        $this->fcClient->createService($serviceName, $serviceDesc);
        $functionName =  $this->serviceName . "test";
        $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName' => $functionName,
                'handler'      => 'index.handler',
                'runtime'      => 'php7.2',
                'memorySize'   => 128,
                'code'         => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index.zip')),
                ),
                'description'  => "test function",
            )
        );
        $httpFunctionName = $this->serviceName . "test-http" ;
        $this->fcClient->createFunction(
            $serviceName,
            array(
                'functionName' => $httpFunctionName,
                'handler'      => 'index_http.handler',
                'runtime'      => 'php7.2',
                'memorySize'   => 128,
                'code'         => array(
                    'zipFile' => base64_encode(file_get_contents(__DIR__ . '/index_http.zip')),
                ),
                'description'  => "test http function",
            )
        );
        $this->subTestOssTrigger($serviceName, $functionName);
        $this->subTestLogTrigger($serviceName, $functionName);
        $this->subTestHttpTrigger($serviceName, $httpFunctionName);
    }

    private function checkTriggerResponse($resp, $triggerName, $description, $triggerType, $triggerConfig, $sourceArn, $invocationRole) {
        $this->assertEquals($resp['triggerName'], $triggerName);
        $this->assertEquals($resp['description'], $description);
        $this->assertEquals($resp['triggerType'], $triggerType);
        $this->assertEquals($resp['sourceArn'], $sourceArn);
        $this->assertEquals($resp['invocationRole'], $invocationRole);
        $this->assertTrue(isset($resp['createdTime']));
        $this->assertTrue(isset($resp['lastModifiedTime']));
        $this->assertEquals($resp['triggerConfig'], $triggerConfig);
    }

    private function subTestOssTrigger($serviceName, $functionName) {
        $triggerType       = 'oss';
        $triggerName       = 'test-trigger-oss';
        $createTriggerDesc = 'create oss trigger';
        $sourceArn         = sprintf("acs:oss:%s:%s:%s", $this->region, $this->accountId, $this->codeBucket);
        $prefix            = 'pre' . createUuid();
        $suffix            = 'suf' . createUuid();
        $triggerConfig     = [
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
                'description'    => $createTriggerDesc,
                'triggerType'    => $triggerType,
                'invocationRole' => $this->invocationRoleOss,
                'sourceArn'      => $sourceArn,
                'triggerConfig'  => $triggerConfig,
            )
        );

        $triggerData = $ret['data'];
        $this->checkTriggerResponse($triggerData, $triggerName, $createTriggerDesc, $triggerType, $triggerConfig, $sourceArn, $this->invocationRoleOss);

        $err = '';
        try {
            $this->fcClient->createTrigger(
                $serviceName,
                $functionName,
                array(
                    'triggerName'    => $triggerName,
                    'triggerType'    => $triggerType,
                    'invocationRole' => $this->invocationRoleOss,
                    'sourceArn'      => $sourceArn,
                    'triggerConfig'  => $triggerConfig,
                )
            );
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != '');

        $getTriggerData = $this->fcClient->getTrigger($serviceName, $functionName, $triggerName)['data'];
        $this->checkTriggerResponse($getTriggerData, $triggerName, $createTriggerDesc, $triggerType, $triggerConfig, $sourceArn, $this->invocationRoleOss);

        $prefixUpdate        = $prefix . 'update';
        $suffixUpdate        = $suffix . 'update';
        $updateTriggerDesc   = 'update oss trigger';
        $triggerConfigUpdate = [
            'events'      => ['oss:ObjectCreated:*'],
            'filter'      => [
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
                'description' => $updateTriggerDesc,
            )
        );

        $updateTriggerData = $ret['data'];
        $this->checkTriggerResponse($updateTriggerData, $triggerName, $updateTriggerDesc, $triggerType, $triggerConfigUpdate, $sourceArn, $this->invocationRoleOss);
        $this->fcClient->deleteTrigger($serviceName, $functionName, $triggerName);

        for ($i = 1; $i < 6; $i++) {
            $triggerConfig = [
                'events' => ['oss:ObjectCreated:*'],
                'filter' => [
                    'key' => [
                        'prefix' => $prefixUpdate . strval($i),
                        'suffix' => $suffixUpdate . strval($i),
                    ],
                ],
            ];
            $this->fcClient->createTrigger(
                $serviceName,
                $functionName,
                array(
                    'triggerName'    => $triggerName . strval($i),
                    'triggerType'    => $triggerType,
                    'invocationRole' => $this->invocationRoleOss,
                    'sourceArn'      => $sourceArn,
                    'triggerConfig'  => $triggerConfig,
                )
            );
        }
        $listTriggerResp = $this->fcClient->listTriggers($serviceName, $functionName)['data'];
        $this->assertEquals(count($listTriggerResp['triggers']), 5);
        $listTriggerResp = $this->fcClient->listTriggers($serviceName, $functionName, ["limit" => 2])['data'];
        $numCalled       = 1;
        while (isset($listTriggerResp['nextToken'])) {
            $listTriggerResp = $this->fcClient->listTriggers($serviceName, $functionName,
                ["limit" => 2, "nextToken" => $listTriggerResp['nextToken']])['data'];
            $numCalled += 1;
        }
        $this->assertEquals($numCalled, 3);

        for ($i = 1; $i < 6; $i++) {
            $this->fcClient->deleteTrigger($serviceName, $functionName, $triggerName . strval($i));
        }
    }

    private function subTestLogTrigger($serviceName, $functionName) {
        $triggerType       = 'log';
        $triggerName       = 'test-trigger-sls';
        $createTriggerDesc = 'create log trigger';
        $sourceArn         = sprintf('acs:log:%s:%s:project/%s', $this->region, $this->accountId, $this->logProject);

        $triggerConfig = [
            'sourceConfig'      => [
                'logstore' => $this->logStore . '_source',
            ],
            'jobConfig'         => [
                'triggerInterval' => 60,
                'maxRetryTime'    => 10,
            ],
            'functionParameter' => ["a" => "b"],
            'logConfig'         => [
                'project'  => $this->logProject,
                'logstore' => $this->logStore,
            ],
            'enable'            => false,
        ];

        $ret = $this->fcClient->createTrigger(
            $serviceName,
            $functionName,
            array(
                'triggerName'    => $triggerName,
                'description'    => $createTriggerDesc,
                'triggerType'    => $triggerType,
                'invocationRole' => $this->invocationRoleSls,
                'sourceArn'      => $sourceArn,
                'triggerConfig'  => $triggerConfig,
            )
        );

        $triggerData = $ret['data'];
        $this->checkTriggerResponse($triggerData, $triggerName, $createTriggerDesc, $triggerType, $triggerConfig, $sourceArn, $this->invocationRoleSls);

        $err = '';
        try {
            $this->fcClient->createTrigger(
                $serviceName,
                $functionName,
                array(
                    'triggerName'    => $triggerName,
                    'triggerType'    => $triggerType,
                    'invocationRole' => $this->invocationRoleSls,
                    'sourceArn'      => $sourceArn,
                    'triggerConfig'  => $triggerConfig,
                )
            );
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != '');

        $getTriggerData = $this->fcClient->getTrigger($serviceName, $functionName, $triggerName)['data'];
        $this->checkTriggerResponse($getTriggerData, $triggerName, $createTriggerDesc, $triggerType, $triggerConfig, $sourceArn, $this->invocationRoleSls);
        
        $updateTriggerDesc   = 'update log trigger';
        $triggerConfigUpdate = [
            'sourceConfig'      => [
                'logstore' => $this->logStore . '_source',
            ],
            'jobConfig'         => [
                'triggerInterval' => 5,
                'maxRetryTime'    => 80,
            ],
            'functionParameter' => ["a" => "b"],
            'logConfig'         => [
                'project'  => $this->logProject,
                'logstore' => $this->logStore,
            ],
            'enable'            => false,
        ];

        $ret = $this->fcClient->updateTrigger(
            $serviceName,
            $functionName,
            $triggerName,
            array(
                'description'    => $updateTriggerDesc,
                'invocationRole' => $this->invocationRoleSls,
                'triggerConfig'  => $triggerConfigUpdate,
            )
        );

        $updateTriggerData = $ret['data'];
        $this->checkTriggerResponse($updateTriggerData, $triggerName, $updateTriggerDesc, $triggerType, $triggerConfigUpdate, $sourceArn, $this->invocationRoleSls);
        $this->assertEquals($updateTriggerData['triggerConfig']['jobConfig']['triggerInterval'], 5);
        $this->assertEquals($updateTriggerData['triggerConfig']['jobConfig']['maxRetryTime'], 80);
        $this->fcClient->deleteTrigger($serviceName, $functionName, $triggerName);
    }

    private function subTestHttpTrigger($serviceName, $functionName) {
        $triggerType    = 'http';
        $triggerName    = 'test-trigger-http';
        $sourceArn      = 'dummy_arn';
        $description    = 'create http trigger';
        $invocationRole = '';

        $triggerConfig = [
            'authType' => 'anonymous',
            'methods'  => ['GET', 'POST', 'PUT'],
        ];

        $ret = $this->fcClient->createTrigger(
            $serviceName,
            $functionName,
            array(
                'triggerName'    => $triggerName,
                'description'    => $description,
                'triggerType'    => $triggerType,
                'invocationRole' => $invocationRole,
                'sourceArn'      => $sourceArn,
                'triggerConfig'  => $triggerConfig,
            )
        );
        $triggerData = $ret['data'];
        $this->checkTriggerResponse($triggerData, $triggerName, $description, $triggerType, $triggerConfig, null, $invocationRole);

        $getTriggerData = $this->fcClient->getTrigger($serviceName, $functionName, $triggerName)['data'];
        $this->checkTriggerResponse($getTriggerData, $triggerName, $description, $triggerType, $triggerConfig, null, $invocationRole);

        $triggerConfigUpdate = [
            'authType' => 'function',
            'methods'  => ['GET', 'POST'],
        ];

        $updateTriggerDesc = 'update http trigger';
        $ret               = $this->fcClient->updateTrigger(
            $serviceName,
            $functionName,
            $triggerName,
            array(
                'description'    => $updateTriggerDesc,
                'invocationRole' => $invocationRole,
                'triggerConfig'  => $triggerConfigUpdate,
            )
        );

        $updateTriggerData = $ret['data'];
        $this->checkTriggerResponse($updateTriggerData, $triggerName, $updateTriggerDesc, $triggerType, $triggerConfigUpdate, null, $invocationRole);
        $this->assertEquals($updateTriggerData['triggerConfig']['authType'], 'function');

        $headers = [
            'Foo' => 'Bar',
        ];
        $query = [
            'key with space' => 'value with space',
            'key'            => 'value',
        ];
        $res = $this->fcClient->doHttpRequest('POST', $serviceName, $functionName, '/action%20with%20space', $headers, $query, 'hello world');
        $this->assertEquals($res->getStatusCode(), 202);
        $this->assertEquals($res->getBody()->getContents(), 'hello world');

        $res = $this->fcClient->doHttpRequest('POST', $serviceName, $functionName, '/');
        $this->assertEquals($res->getStatusCode(), 202);
        $this->assertEquals($res->getBody()->getContents(), '');

        $err = '';
        try {
            $this->fcClient->doHttpRequest('POST', $serviceName, $functionName, '/action%20with%20space', [], 123, 'hello world');
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != '');

        $err = '';
        try {
            $this->fcClient->updateTrigger(
                $serviceName . 'invalid',
                $functionName,
                $triggerName,
                array(
                    'invocationRole' => $invocationRole,
                    'triggerConfig'  => $triggerConfigUpdate,
                )
            );
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != '');

        $listTriggerResp = $this->fcClient->listTriggers($serviceName, $functionName)['data'];
        $this->assertEquals(count($listTriggerResp['triggers']), 1);
        $this->fcClient->deleteTrigger($serviceName, $functionName, $triggerName);
    }

    public function testListReservedCapacities() {
        $r    = $this->fcClient->listReservedCapacities(["limit" => 5]);
        $r    = $r['data'];
        $rcs  = $r['reservedCapacities'];
        $this->assertLessThanOrEqual(5, count($rcs));
        
        for ($i=0; $i<count($rcs); $i++){
            $this->assertEquals(strlen($rcs[i]['instanceId']), 22);
            $this->assertGreaterThan(0, $rcs[i]['cu']);
            $this->assertGreaterThan($rcs[i]['createdTime'], $rcs[i]['deadline']);
            $this->assertNotNull($rcs[i]['lastModifiedTime']);
            $this->assertNotNull($rcs[i]['isRefunded']);
        }
    }

    public function testException() {
        $err = '';
        try {
            $fcClient = new Client([
                'accessKeyID'     => 'ACCESS_KEY_ID',
                'accessKeySecret' => 'ACCESS_KEY_SECRET',
            ]);
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        $this->assertTrue($err != '');
    }

    public function testTag() {
        $prefix = $this->serviceName . "test_php_tag_";
        try {
            for ($i = 0; $i <= 3; $i++) {
                $this->fcClient->createService($prefix . $i);
                $resourceArn = sprintf("acs:fc:%s:%s:services/%s", $this->region, $this->accountId, $prefix . $i);
                $this->fcClient->tagResource([
                    "resourceArn" => $resourceArn,
                    "tags"        => ["k3" => "v3"],
                ]);
                if ($i % 2 == 0) {
                    $this->fcClient->tagResource([
                        "resourceArn" => $resourceArn,
                        "tags"        => ["k1" => "v1"],
                    ]);
                } else {
                    $this->fcClient->tagResource([
                        "resourceArn" => $resourceArn,
                        "tags"        => ["k2" => "v2"],
                    ]);
                }
            }

            $services = $this->fcClient->listServices(['prefix' => $prefix])['data']['services'];
            $this->assertEquals(count($services), 4);
            $services = $this->fcClient->listServices(['prefix' => $prefix, 'tags' => ["k1" => "v1"]])['data']['services'];
            $this->assertEquals(count($services), 2);
            $services = $this->fcClient->listServices(['prefix' => $prefix, 'tags' => ["k2" => "v2"]])['data']['services'];
            $this->assertEquals(count($services), 2);
            $services = $this->fcClient->listServices(['prefix' => $prefix, 'tags' => ["k3" => "v3"]])['data']['services'];
            $this->assertEquals(count($services), 4);
            $services = $this->fcClient->listServices(['prefix' => $prefix, 'tags' => ["k1" => "v1", "k2" => "v2"]])['data']['services'];
            $this->assertEquals(count($services), 0);

            for ($i = 0; $i <= 3; $i++) {
                $resourceArn = sprintf("acs:fc:%s:%s:services/%s", $this->region, $this->accountId, $prefix . $i);
                $resp        = $this->fcClient->getResourceTags(["resourceArn" => $resourceArn])['data'];
                // var_export($resp);
                $this->assertEquals($resourceArn, $resp['resourceArn']);
                if ($i % 2 == 0) {
                    $this->assertEquals(0, count(array_diff($resp['tags'], ["k1" => "v1", "k3" => "v3"])));
                } else {
                    $this->assertEquals(0, count(array_diff($resp['tags'], ["k2" => "v2", "k3" => "v3"])));
                }
                $this->fcClient->untagResource([
                    "resourceArn" => $resourceArn,
                    "tagKeys"     => ["k3"],
                    "all"         => false,
                ]
                );
                $resp = $this->fcClient->getResourceTags(["resourceArn" => $resourceArn])['data'];
                $this->assertEquals($resourceArn, $resp['resourceArn']);
                if ($i % 2 == 0) {
                    $this->assertEquals(0, count(array_diff($resp['tags'], ["k1" => "v1"])));
                } else {
                    $this->assertEquals(0, count(array_diff($resp['tags'], ["k2" => "v2"])));
                }
                $this->fcClient->untagResource(["resourceArn" => $resourceArn, "tagKeys" => [], "all" => true]);
                $resp = $this->fcClient->getResourceTags(["resourceArn" => $resourceArn])['data'];
                $this->assertEquals($resourceArn, $resp['resourceArn']);
                $this->assertEquals(0, count($resp['tags']));
            }
        } catch (Exception $e) {

        } finally {
            for ($x = 0; $x <= 3; $x++) {
                try {
                    $resourceArn = sprintf("acs:fc:%s:%s:services/%s", $this->region, $this->accountId, $prefix . $i);
                    $this->fcClient->untagResource(["resourceArn" => $resourceArn, "tagKeys" => [], "all" => true]);
                    $this->fcClient->deleteService($prefix . $x);
                } catch (Exception $e) {}
            }
        }
    }
}
