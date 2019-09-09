Aliyun FunctionCompute Php SDK
=================================

[![Latest Stable Version](https://img.shields.io/packagist/v/aliyunfc/fc-php-sdk.svg)](https://packagist.org/packages/aliyunfc/fc-php-sdk)
[![Build Status](https://travis-ci.org/aliyun/fc-php-sdk.svg?branch=master)](https://travis-ci.org/aliyun/fc-php-sdk)
[![Coverage Status](https://coveralls.io/repos/github/aliyun/fc-php-sdk/badge.svg?branch=master)](https://coveralls.io/github/aliyun/fc-php-sdk?branch=master)


Overview
--------

The SDK of this version is dependent on the third-party HTTP library [guzzlehttp/guzzle](https://github.com/guzzle/guzzle).


Running environment
-------------------

- PHP 5.6+.
- cURL extension.


Installation
-------------------

The recommended way to install fc-php-sdk is through Composer.

  - install composer: https://getcomposer.org/doc/00-intro.md
  - install fc-php-sdk

```bash
$ composer require aliyunfc/fc-php-sdk
```

You can also declare the dependency on Alibaba Cloud FC SDK for PHP in the composer.json file.

```json
 "require": {
      "aliyunfc/fc-php-sdk": "~1.2"
  }
```

Then run `composer install --no-dev` to install the dependency. After the Composer Dependency Manager is installed, import the dependency in your PHP code:

```php
 require_once __DIR__ . '/vendor/autoload.php';
```

Getting started
-------------------

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
use AliyunFC\Client;

// To know the endpoint and access key id/secret info, please refer to:
// https://help.aliyun.com/document_detail/52984.html
$fcClient = new Client([
    "endpoint" => '<Your Endpoint>',
    "accessKeyID" =>'<Your AccessKeyID>',
    "accessKeySecret" =>'<Your AccessKeySecret>'
]);

// Create service.
$fcClient->createService('service_name');
 
/*
Create function.
the current directory has a main.zip file (main.php which has a function of my_handler)
set environment variables {'testKey': 'testValue'}
*/
$fcClient->createFunction(
    'service_name',
    array(
        'functionName' => $functionName,
        'handler' => 'index.handler',
        'runtime' => 'php7.2',
        'memorySize' => 128,
        'code' => array(
            'zipFile' => base64_encode(file_get_contents(__DIR__ . '/main.zip')),
        ),
       'description' => "test function",
       'environmentVariables' => ['testKey' => 'testValue'],
			)
		);

//Invoke function synchronously.
$fcClient->invokeFunction('service_name', 'function_name');

/*
Create function with initializer.
the current directory has a main.zip file (main.php which hava functions of my_handler and my_initializer)
set environment variables {'testKey': 'testValue'}
*/
$fcClient->createFunction(
    'service_name_with_initializer',
    array(
        'functionName' => $functionName,
        'handler' => 'index.handler',
        'initializer' => 'index.initializer',
        'runtime' => 'php7.2',
        'memorySize' => 128,
        'code' => array(
        'zipFile' => base64_encode(file_get_contents(__DIR__ . '/main.zip')),
        ),
        'description' => "test function with initializer",
        'environmentVariables' => ['testKey' => 'testValue'],
            )
         );

//Invoke function synchronously.
$fcClient->invokeFunction('service_name_with_initializer', 'function_name');

//Create trigger, for example: oss trigger
$prefix = 'pre';
$suffix = 'suf';
$triggerConfig = [
    'events' => ['oss:ObjectCreated:*'],
    'filter' => [
        'key' => [
            'prefix' => $prefix,
            'suffix' => $suffix,
        ],
    ],
];
$sourceArn = 'acs:oss:cn-shanghai:12345678:bucketName';
$invocationRole = 'acs:ram::12345678:role/aliyunosseventnotificationrole';
$ret = $fcClient->createTrigger(
    'service_name',
    'function_name',
    [
        'triggerName' => 'trigger_name',
        'triggerType' => 'oss',
        'invocationRole' => $invocationRole,
        'sourceArn' => $sourceArn,
        'triggerConfig' => $triggerConfig,
    ]
);


//Invoke a function with a input parameter.
$fcClient->invokeFunction('service_name', 'function_name', $payload='hello_world');

    
// Invoke function asynchronously.
$fcClient->invokeFunction('service_name', 'function_name', 'hello world', ['x-fc-invocation-type' => 'Async']);

// List services.
$fcClient->listServices();

//List functions with prefix and limit.
$fcClient->listFunctions('service_name', ['prefix' => 'hello', "limit" => 2]);

//List triggers
$fcClient->listTriggers('service_name', 'function_name');

//Delete trigger
$fcClient->deleteTrigger('service_name', 'function_name', 'trigger_name');
    
//Delete function
$fcClient->deleteFunction('service_name', 'function_name');
    
//Delete service.
$fcClient->deleteService('service_name');

```

Testing
-------

To run the tests, please set the access key id/secret, endpoint as environment variables.
Take the Linux system for example:

```bash
$ export ENDPOINT=<endpoint>
$ export ACCESS_KEY_ID=<AccessKeyId>
$ export ACCESS_KEY_SECRET=<AccessKeySecret>
$ export ACCOUNT_ID=<AccountId>
...
```
For details, refer to `client_test.php`

Run the test in the following method:

```bash
$ phpunit
```

More resources
--------------
- [Aliyun FunctionCompute docs](https://help.aliyun.com/product/50980.html)

Contacting us
-------------
- [Links](https://help.aliyun.com/document_detail/53087.html)

License
-------
- [MIT](https://github.com/aliyun/fc-python-sdk/blob/master/LICENSE)

