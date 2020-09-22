<?php
namespace AliyunFC;

require_once 'auth.php';
require_once 'util.php';

class Client {
    private $version = '1.2.0';
    private $endpoint;
    private $host;
    private $apiVersion;
    private $userAgent;
    private $auth;
    private $timeout;

    public function __construct($options) {
        if (!(isset($options['endpoint']) && isset($options['accessKeyID']) && isset($options['accessKeySecret']))) {
            throw new \Exception('endpoint|AccessKeyID|accessKeySecret parameters must be specified to construct the Client');
        }

        $this->endpoint   = $this->normalizeEndpoint($options['endpoint']);
        $this->apiVersion = '2016-08-15';
        $this->userAgent  = sprintf('aliyun-fc-sdk-v%s.php-%s.%s-%s-%s', $this->version, phpversion(),
            php_uname("s"), php_uname("r"), php_uname("m"));

        $security_token = isset($options['securityToken']) ? $options['securityToken'] : '';
        $this->auth     = new Auth($options['accessKeyID'], $options['accessKeySecret'], $security_token);
        $this->timeout  = isset($options['timeout']) ? $options['timeout'] : 60;
        $this->host     = $this->getHost();
    }

    private function normalizeEndpoint($url) {
        if (!((substr($url, 0, 7) === 'http://') || (substr($url, 0, 8) === 'https://'))) {
            return 'https://' . $url;
        }
        return trim($url);
    }

    private function getHost() {
        if (substr($this->endpoint, 0, 7) === 'http://') {
            return substr($this->endpoint, 7);
        }
        if (substr($this->endpoint, 0, 8) === 'https://') {
            return substr($this->endpoint, 8);
        }
        return trim($this->endpoint);
    }

    private function buildCommonHeaders($method, $path, $customHeaders = [], $unescapedQueries = null) {
        $headers = array(
            'host'           => $this->host,
            'date'           => gmdate('D, d M Y H:i:s T'),
            'content-type'   => 'application/json',
            'content-length' => '0',
            'user-agent'     => $this->userAgent,
        );
        if ($this->auth->getSecurityToken() != '') {
            $headers['x-fc-security-token'] = $this->auth->getSecurityToken();
        }

        if (count($customHeaders) > 0) {
            $headers = array_merge($headers, $customHeaders);
        }

        //Sign the request and set the signature to headers.
        $headers['authorization'] = $this->auth->signRequest($method, $path, $headers, $unescapedQueries);

        return $headers;
    }

    private function doRequest($method, $path, $headers, $data = null, $query = []) {
        /*
        @param string $method
        @param string $path
        @param array $headers Extra headers to send with the request
        @param string in the body for POST requests
        @param array $query $data Data to send either as a query string for GET/HEAD requests
        @return array
         */
        $url    = $this->endpoint . $path;
        $client = new \GuzzleHttp\Client(["timeout" => $this->timeout]);

        $options = [];
        if ($headers) {
            $options['headers'] = $headers;
        }

        if ($data) {
            $options['body'] = $data;
        }

        if ($query) {
            $options['query'] = $query;
        }
        $res = $client->request($method, $url, $options);

        $respStatusCode = $res->getStatusCode();
        $respBody       = $res->getBody()->getContents();
        $rid            = $res->getHeaderLine('X-Fc-Request-Id');

        if ($respStatusCode < 400) {
            $body = json_decode($respBody, $assoc = true);
            if (is_null($body)) {
                $body = $respBody;
            }
            return array(
                "headers" => $res->getHeaders(),
                "data"    => $body,
            );
        } elseif ($respStatusCode >= 400 && $respStatusCode < 500) {
            throw new \Exception(
                sprintf('Client error, status_code = %s, requestId = %s, detail = %s', $respStatusCode, $rid, $respBody),
                $respStatusCode);

        } elseif ($respStatusCode >= 500 && $respStatusCode < 600) {
            throw new \Exception(
                sprintf('Server error, status_code = %s, requestId = %s, detail = %s', $respStatusCode, $rid, $respBody),
                $respStatusCode);

        } else {
            throw new \Exception(
                sprintf('Unknown error, status_code = %s, requestId = %s, detail = %s', $respStatusCode, $rid, $respBody),
                $respStatusCode);
        }
    }

    public function doHttpRequest($method, $serviceName, $functionName, $path, $headers = [], $unescapedQueries = [], $data = null) {
        /*
        use for http trigger, http invoke
        @param string $method
        @param string $path
        @param array $headers Extra headers to send with the request
        @param string in the body for POST requests
        @param array|null $query $data Data to send either as a query string for GET/HEAD requests
        @return array
         */
        if ($unescapedQueries) {
            assert(is_array($unescapedQueries));
        }
        $path    = $path ?: "/";
        $path    = sprintf('/%s/proxy/%s/%s%s', $this->apiVersion, $serviceName, $functionName, $path);
        $url     = $this->endpoint . $path;
        $headers = $this->buildCommonHeaders($method, unescape($path), $headers, $unescapedQueries);

        $client  = new \GuzzleHttp\Client(["timeout" => $this->timeout]);
        $options = [];
        if ($headers) {
            $options['headers'] = $headers;
        }

        if ($data) {
            $options['body'] = $data;
        }

        $options['query'] = $unescapedQueries;

        $res = $client->request($method, $url, $options);
        return $res;
    }

    public function createService($serviceName, $description = "", $options = [], $headers = []) {
        /*
        Create a service. see: https://help.aliyun.com/document_detail/52877.html#createservice
        @param serviceName: name of the service.
        @param description: (optional, string), detail description of the service.
        @param options: (optional, array) see: https://help.aliyun.com/document_detail/52877.html#service
        @param headers: (optional, array) 'x-fc-trace-id': string (a uuid to do the request tracing), etc
        @return: array
         */
        $method  = 'POST';
        $path    = sprintf('/%s/services', $this->apiVersion);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        $payload = array(
            'serviceName' => $serviceName,
            'description' => $description,
        );

        if (count($options) > 0) {
            $payload = array_merge($payload, $options);
        }

        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function deleteService($serviceName, $headers = []) {
        /*
        Delete the specified service. see: https://help.aliyun.com/document_detail/52877.html#deleteservice
        @param service_name: name of the service.
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string (delete the service only when matched the given etag.)
        3, user define key value
        @return: array
         */
        $method  = 'DELETE';
        $path    = sprintf('/%s/services/%s', $this->apiVersion, $serviceName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers);
    }

    public function updateService($serviceName, $description = "", $options = [], $headers = []) {
        /*
        Update a service. see: https://help.aliyun.com/document_detail/52877.html#updateservice
        @param serviceName: name of the service.
        @param description: (optional, string), detail description of the service.
        @param options: (optional, array) see:https://help.aliyun.com/document_detail/52877.html#serviceupdatefields
        @param headers: (optional, array) 'x-fc-trace-id': string (a uuid to do the request tracing), etc
        @return: array
         */
        $method  = 'PUT';
        $path    = sprintf('/%s/services/%s', $this->apiVersion, $serviceName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        $payload = array(
            'description' => $description,
        );
        if (count($options) > 0) {
            $payload = array_merge($payload, $options);
        }
        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function getService($serviceName, $headers = [], $qualifier = null) {
        /*
        get the specified service. see: https://help.aliyun.com/document_detail/52877.html#getservice
        @param service_name: name of the service.
        @param headers, optional
        @param qualifier: (optional, string) qualifier of service.
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array
         */
        $method = 'GET';
        if ($qualifier != null && $qualifier != "") {
            $serviceName = sprintf("%s.%s", $serviceName, $qualifier);
        }
        $path    = sprintf('/%s/services/%s', $this->apiVersion, $serviceName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers);
    }

    public function listServices($options = [], $headers = []) {
        /*
        list services. see: https://help.aliyun.com/document_detail/52877.html#listservices
        @param options: optional
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array
         */
        $method  = 'GET';
        $path    = sprintf('/%s/services', $this->apiVersion);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        
        if(array_key_exists("tags", $options)){
            $tags = $options["tags"];
            unset($options["tags"]);
            foreach ($tags as $key => $value) {
                $options["tag_".$key] = $value;
            }
        }
    
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    private function normalizeParams(&$opts) {
        if (!(isset($opts['functionName']) && isset($opts['runtime']) && isset($opts['handler']) && isset($opts['code']))) {
            throw new \Exception('functionName|handler|runtime|code parameters must be specified');
        }
        $opts['functionName']          = strval($opts['functionName']);
        $opts['runtime']               = strval($opts['runtime']);
        $opts['handler']               = strval($opts['handler']);
        $opts['initializer']           = isset($opts['initializer']) ? strval($opts['initializer']) : null;
        $opts['memorySize']            = isset($opts['memorySize']) ? intval($opts['memorySize']) : 256;
        $opts['timeout']               = isset($opts['timeout']) ? intval($opts['timeout']) : 60;
        $opts['initializationTimeout'] = isset($opts['initializationTimeout']) ? intval($opts['initializationTimeout']) : 30;
    }

    public function createFunction($serviceName, $functionPayload, $headers = []) {
        /*
        createFunction. see: https://help.aliyun.com/document_detail/52877.html#createfunction
        @param serviceName : require
        @param functionPayload: function ,see: https://help.aliyun.com/document_detail/52877.html#function
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array
         */
        $this->normalizeParams($functionPayload);
        $method                    = 'POST';
        $path                      = sprintf('/%s/services/%s/functions', $this->apiVersion, $serviceName);
        $headers                   = $this->buildCommonHeaders($method, $path, $headers);
        $content                   = json_encode($functionPayload);
        $headers['content-length'] = strlen($content);

        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function updateFunction($serviceName, $functionName, $options = [], $headers = []) {
        /*
        updateFunction. see: https://help.aliyun.com/document_detail/52877.html#updatefunction
        @param serviceName : require
        @param functionName: require
        @param options: require
        @param headers, optional, see: https://help.aliyun.com/document_detail/52877.html#functionupdatefields
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array

         */

        $method                  = 'PUT';
        $path                    = sprintf('/%s/services/%s/functions/%s', $this->apiVersion, $serviceName, $functionName);
        $headers                 = $this->buildCommonHeaders($method, $path, $headers);
        $options['functionName'] = $functionName;
        $this->normalizeParams($options);
        unset($options['functionName']);
        $content                   = json_encode($options);
        $headers['content-length'] = strlen($content);

        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function deleteFunction($serviceName, $functionName, $headers = []) {
        /*
        createFunction. see: https://help.aliyun.com/document_detail/52877.html#deletefunction
        @param serviceName : require
        @param functionName: require
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array
         */
        $method  = 'DELETE';
        $path    = sprintf('/%s/services/%s/functions/%s', $this->apiVersion, $serviceName, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers);
    }

    public function getFunction($serviceName, $functionName, $headers = [], $qualifier = null) {
        /*
        getFunction. see: https://help.aliyun.com/document_detail/52877.html#getfunction
        @param serviceName : require
        @param functionName: require
        @param headers, optional
        @param qualifier: (optional, string) qualifier of service.
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array
         */
        $method = 'GET';
        if ($qualifier != null && $qualifier != "") {
            $serviceName = sprintf("%s.%s", $serviceName, $qualifier);
        }
        $path    = sprintf('/%s/services/%s/functions/%s', $this->apiVersion, $serviceName, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers);
    }

    public function getFunctionCode($serviceName, $functionName, $headers = [], $qualifier = null) {
        /*
        getFunctionCode. see: https://help.aliyun.com/document_detail/52877.html#getfunctioncode
        @param serviceName : require
        @param functionName: require
        @param headers, optional
        @param qualifier: (optional, string) qualifier of service.
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array
         */
        $method = 'GET';
        if ($qualifier != null && $qualifier != "") {
            $serviceName = sprintf("%s.%s", $serviceName, $qualifier);
        }
        $path    = sprintf('/%s/services/%s/functions/%s/code', $this->apiVersion, $serviceName, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers);
    }

    public function listFunctions($serviceName, $options = [], $headers = []) {
        /*
        listFunctions. see: https://help.aliyun.com/document_detail/52877.html#listfunctions
        @param serviceName : require
        @param options, optional
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array
         */
        $method = 'GET';
        if (isset($options['qualifier'])) {
            $qualifier   = $options['qualifier'];
            $serviceName = sprintf("%s.%s", $serviceName, $qualifier);
        }
        $path    = sprintf('/%s/services/%s/functions', $this->apiVersion, $serviceName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    public function invokeFunction($serviceName, $functionName, $payload = '', $headers = [], $qualifier = null) {
        /*
        invokeFunction. see: https://help.aliyun.com/document_detail/52877.html#invokefunction
        @param serviceName : require
        @param functionName: require
        @param payload: (optional, bytes or seekable file-like object): the input of the function.
        @param headers: (optional, array) user-defined request header.
        @param qualifier: (optional, string) qualifier of service.
        'x-fc-invocation-type' : require, 'Sync'/'Async' ,only two choice
        'x-fc-trace-id' : option (a uuid to do the request tracing)
        other can add user define header
        @return: array
         */
        $method = 'POST';
        if ($qualifier != null && $qualifier != "") {
            $serviceName = sprintf("%s.%s", $serviceName, $qualifier);
        }
        $path    = sprintf('/%s/services/%s/functions/%s/invocations', $this->apiVersion, $serviceName, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers, $data = $payload);
    }

    public function createTrigger($serviceName, $functionName, $trigger = [], $headers = []) {
        /*
        createTrigger. see: https://help.aliyun.com/document_detail/52877.html#createtrigger
        @param serviceName : require
        @param functionName: require
        @param trigger: required, see: https://help.aliyun.com/document_detail/52877.html#trigger
        @param headers: (optional, array) user-defined request header.
        'x-fc-trace-id' : option, (a uuid to do the request tracing)
        @return: array
         */
        $method                    = 'POST';
        $path                      = sprintf('/%s/services/%s/functions/%s/triggers', $this->apiVersion, $serviceName, $functionName);
        $headers                   = $this->buildCommonHeaders($method, $path, $headers);
        $content                   = json_encode($trigger);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function deleteTrigger($serviceName, $functionName, $triggerName, $headers = []) {
        /*
        deleteTrigger. see: https://help.aliyun.com/document_detail/52877.html#deletetrigger
        @param serviceName : require
        @param functionName: require
        @param triggerName: required
        @param headers: (optional, array) user-defined request header.
        'x-fc-trace-id' : option, (a uuid to do the request tracing)
        'if-match': string
        @return: array
         */
        $method  = 'DELETE';
        $path    = sprintf('/%s/services/%s/functions/%s/triggers/%s', $this->apiVersion, $serviceName, $functionName, $triggerName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers);
    }

    public function updateTrigger($serviceName, $functionName, $triggerName, $triggerUpdateFields, $headers = []) {
        /*
        updateTrigger. see: https://help.aliyun.com/document_detail/52877.html#updatetrigger
        @param serviceName : require
        @param functionName: require
        @param triggerName: require
        @param triggerUpdateFields: required, see: https://help.aliyun.com/document_detail/52877.html#triggerupdatefields
        @param headers: (optional, array) user-defined request header.
        'x-fc-trace-id' : option, (a uuid to do the request tracing)
        'if-match': string
        @return: array
         */
        $method  = 'PUT';
        $path    = sprintf('/%s/services/%s/functions/%s/triggers/%s', $this->apiVersion, $serviceName, $functionName, $triggerName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        $content                   = json_encode($triggerUpdateFields);
        $headers['content-length'] = strlen($content);

        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function getTrigger($serviceName, $functionName, $triggerName, $headers = []) {
        /*
        getTrigger. see: https://help.aliyun.com/document_detail/52877.html#gettrigger
        @param serviceName : require
        @param functionName: require
        @param triggerName: required
        @param headers: (optional, array) user-defined request header.
        'x-fc-trace-id' : option, (a uuid to do the request tracing)
        'if-match': string
        @return: array
         */
        $method  = 'GET';
        $path    = sprintf('/%s/services/%s/functions/%s/triggers/%s', $this->apiVersion, $serviceName, $functionName, $triggerName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers);
    }

    public function listTriggers($serviceName, $functionName, $options = [], $headers = []) {
        /*
        listTriggers. see: https://help.aliyun.com/document_detail/52877.html#listtriggers
        @param serviceName : require
        @param functionName: require
        @param triggerName: required
        @param options, optional
        @param headers: (optional, array) user-defined request header.
        'x-fc-trace-id' : option, (a uuid to do the request tracing)
        'if-match': string
        @return: array
         */
        $method  = 'GET';
        $path    = sprintf('/%s/services/%s/functions/%s/triggers', $this->apiVersion, $serviceName, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    public function publishVersion($serviceName, $description = "", $headers = []) {
        /*
        Publish a version.
        @param serviceName: (required, string), name of the service.
        @param description: (optional, string) the readable description of the version.
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string (publish the version only when matched the given etag.)
        3, user define key value
        @return: array
        [
        headers => array(),
        //array of the version attributes.
        data =>
        [
        'versionId' => 'string',
        'description' => 'string',
        'createdTime' =>  'string',
        'lastModifiedTime' => 'string',
        ]
        ]
         */

        $method  = 'POST';
        $path    = sprintf('/%s/services/%s/versions', $this->apiVersion, $serviceName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        $payload = array(
            'description' => $description,
        );

        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function listVersions($serviceName, $options = [], $headers = []) {
        /*
        List the versions of the current service.
        @param serviceName: (required, string), name of the service.
        @param options, optional
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, user define key value
        @return: arrray
        [
        headers => array(),
        // array, including all function information.
        data =>
        [
        'versions' =>
        [
        [
        'versionId' => 'string',
        'description' => 'string',
        'createdTime' => 'string',
        'lastModifiedTime' => 'string',
        ],
        ...
        ],
        'nextToken' => 'string'
        ]
        ]
         */
        $method  = 'GET';
        $path    = sprintf('/%s/services/%s/versions', $this->apiVersion, $serviceName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    public function deleteVersion($serviceName, $versionId, $headers = []) {
        /*
        Delete a version.
        @param serviceName: (required, string), name of the service.
        @param versionId: (required, string), Id of the version.
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, user define key value
        :return: array
         */
        $method  = 'DELETE';
        $path    = sprintf('/%s/services/%s/versions/%s', $this->apiVersion, $serviceName, $versionId);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers);
    }

    public function createAlias($serviceName, $payload = [], $headers = []) {
        /*
        Create an alias.
        @param serviceName: (required, string), name of the service.
        @param payload ['aliasName' => aliasName, 'versionId'=> versionId, ...]
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, user define key value
        @return: array
        [
        'headers' => array(),
        //array of the version attributes.
        'data' =>
        [
        'aliasName' => 'string'
        'versionId' => 'string',
        'description' => 'string',
        'additionalVersionWeight' => 'array',
        'createdTime' => 'string',
        'lastModifiedTime' => 'string',
        ]
        ]
         */
        $method                    = 'POST';
        $path                      = sprintf('/%s/services/%s/aliases', $this->apiVersion, $serviceName);
        $headers                   = $this->buildCommonHeaders($method, $path, $headers);
        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function getAlias($serviceName, $aliasName, $headers = []) {
        /*
        Get the alias.
        @param serviceName: (required, string) name of the service.
        @param aliasName: (required, string) name of the alias.
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, user define key value
        @return: array
        [
        'headers' => array(),
        //array alias attributes.
        'data' =>
        [
        'aliasName' => 'string'
        'versionId' => 'string',
        'description' => 'string',
        'additionalVersionWeight' => 'array',
        'createdTime' => 'string',
        'lastModifiedTime' => 'string',
        ]
        ]
         */
        $method  = 'GET';
        $path    = sprintf('/%s/services/%s/aliases/%s', $this->apiVersion, $serviceName, $aliasName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers);
    }

    public function updateAlias($serviceName, $aliasName, $payload = [], $headers = []) {
        /*
        Update an alias.
        @param serviceName: (required, string), name of the service.
        @param aliasName: (required, string) name of the alias.
        @payload ['versionId'=> versionId, 'additionalVersionWeight' => additionalVersionWeight ...]
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string (update the alias only when matched the given etag.)
        3, user define key value
        @return: array
        headers: dict
        data: dict of the version attributes.
        {
        'aliasName': 'string'
        'versionId': 'string',
        'description': 'string',
        'additionalVersionWeight': 'dict',
        'createdTime': 'string',
        'lastModifiedTime': 'string',
        }
         */
        $method  = 'PUT';
        $path    = sprintf('/%s/services/%s/aliases/%s', $this->apiVersion, $serviceName, $aliasName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);

        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function listAliases($serviceName, $options = [], $headers = []) {
        /*
        List the aliases in the current service.
        @param serviceName: (required, string), name of the service.
        @param options: (optional, array)
        [
        "limit" => "int",
        "nextToken" => "string",
        "prefix" => "string",
        "startKey" => "string",
        ]
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, user define key value
        @return: array
        [
        headers => array(),
        //array, including all aliase information.
        data =>
        [
        'aliases'=>
        [
        [
        'aliasName'=> 'string'
        'versionId'=> 'string',
        'description'=> 'string',
        'additionalVersionWeight'=> 'array',
        'createdTime'=> 'string',
        'lastModifiedTime'=> 'string',
        ],
        ...
        ],
        'nextToken'=> 'string'
        ]
        ]
         */

        $method  = 'GET';
        $path    = sprintf('/%s/services/%s/aliases', $this->apiVersion, $serviceName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    public function deleteAlias($serviceName, $aliasName, $headers = []) {
        /*
        Delete an aliase.
        @param serviceName: (required, string), name of the service.
        @param aliasName: (required, string), name of the alias.
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string (delete the alias only when matched the given etag.)
        3, user define key value
        :return: array
         */
        $method  = 'DELETE';
        $path    = sprintf('/%s/services/%s/aliases/%s', $this->apiVersion, $serviceName, $aliasName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers);
    }

    public function tagResource($payload,  $headers=[]){
        /*
        Tag on a resource, Currently only services are supported
        :param payload
            resourceArn: (required string), Resource ARN. Either full ARN or partial ARN
            tags:(required array), A list of tag keys. At least 1 tag is required. At most 20. Tag key is required, but tag value is optional
        :param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        return: array
        */

        $method = 'POST';
        $path    = sprintf('/%s/tag', $this->apiVersion);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }
    
    public function untagResource($payload,  $headers=[]) {
        /*
        unTag on a resource, Currently only services are supported
        :param payload
            resourceArn: (required string), Resource ARN. Either full ARN or partial ARN
            tagKeys:(optinal array), A list of tag keys.
            deletaAll: (optinal bool), when tagKeys is empty and deleteAll be True can take effect.
        :param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return: array
        */
        $method = 'DELETE';
        $path    = sprintf('/%s/tag', $this->apiVersion);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function getResourceTags($options, $headers=[]){
        /*
        get a resource's tags, Currently only services are supported
        :param $options
            resourceArn: (required string), Resource ARN. Either full ARN or partial ARN
        :param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return: array
        */
        $method  = 'GET';
        $path    = sprintf('/%s/tag', $this->apiVersion);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }
    
    public function listReservedCapacities($options = [], $headers = []) {
        /*
        @param options: optional
        @param headers, optional
        1, 'x-fc-trace-id': string (a uuid to do the request tracing)
        2, 'if-match': string
        3, user define key value
        @return: array 
        */
        $method  = 'GET';
        $path    = sprintf('/%s/reservedCapacities', $this->apiVersion);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    public function putProvisionConfig($serviceName, $qualifier, $functionName, $payload, $headers=[]){
        /*
        put provision config
        @param service_name: name of the service.
        @param qualifier: name of the service's alias.
        @param functionName: name of the funtion.
        @param payload: body of provision request
        @param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, 'if-match': string (delete the service only when matched the given etag.)
            3, user define key value
        :return array
        */
        $method = 'PUT';
        $path    = sprintf('/%s/services/%s.%s/functions/%s/provision-config', 
            $this->apiVersion, $serviceName, $qualifier, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }
    
    public function getProvisionConfig($serviceName, $qualifier, $functionName, $headers=[]){
        /*
        put provision config
        @param service_name: name of the service.
        @param qualifier: name of the service's alias.
        @param functionName: name of the funtion.
        @param target: number of provision
        @param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return array
        */
        $method  = 'GET';
        $path    = sprintf('/%s/services/%s.%s/functions/%s/provision-config', 
            $this->apiVersion, $serviceName, $qualifier, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers, $data = null, $query = null);
    }
    
    public function listProvisionConfigs($serviceName, $qualifier, $options=[], $headers=[]){
        /*
        List the provision configin the current service.
        @param serviceName: (optional, string), name of the service.
        @param qualifier: (optional, string) name of the service's alias.
        @param limit: (optional, integer) the total number of the returned aliases.
        @param nextToken: (optional, string) continue listing the aliase from the previous point.
        @param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return: array
        */
        if (!empty($qualifier) && empty($serviceName)){
          throw new \Exception("serviceName is required when qualifier is not empty");
        }
        $method  = 'GET';
        $path    = sprintf('/%s/provision-configs', $this->apiVersion);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        $options['serviceName']= $serviceName;
        $options['qualifier']= $qualifier;
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    public function putFunctionAsyncConfig($serviceName, $qualifier, $functionName, $payload, $headers=[]){
        /*
        put async config
        @param service_name: name of the service.
        @param qualifier: name of the service's alias.
        @param functionName: name of the funtion.
        @param payload: body of async config request
        @param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return array
        */
        $method = 'PUT';
        $path    = sprintf('/%s/services/%s.%s/functions/%s/async-invoke-config',
            $this->apiVersion, $serviceName, $qualifier, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        $content                   = json_encode($payload);
        $headers['content-length'] = strlen($content);
        return $this->doRequest($method, $path, $headers, $data = $content);
    }

    public function getFunctionAsyncConfig($serviceName, $qualifier, $functionName, $headers=[]){
        /*
        get async config
        @param service_name: name of the service.
        @param qualifier: name of the service's alias.
        @param functionName: name of the funtion.
        @param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return array
        */
        $method  = 'GET';
        $path    = sprintf('/%s/services/%s.%s/functions/%s/async-invoke-config',
            $this->apiVersion, $serviceName, $qualifier, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers, $data = null, $query = null);
    }

    public function listFunctionAsyncConfigs($serviceName, $functionName, $options=[], $headers=[]){
        /*
        List the async config in the current function.
        @param serviceName: name of the service.
        @param functionName: name of the function.
        @param limit: (optional, integer) the total number of the returned aliases.
        @param nextToken: (optional, string) continue listing the configs from the previous point.
        @param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return: array
        */
        if (!empty($qualifier) && empty($serviceName)){
            throw new \Exception("serviceName is required when qualifier is not empty");
        }
        $method  = 'GET';
        $path    = sprintf('/%s/services/%s/functions/%s/async-invoke-configs',
            $this->apiVersion, $serviceName, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);
        return $this->doRequest($method, $path, $headers, $data = null, $query = $options);
    }

    public function deleteFunctionAsyncConfig($serviceName, $qualifier, $functionName, $headers=[]){
        /*
        delete async config
        @param service_name: name of the service.
        @param qualifier: name of the service's alias.
        @param functionName: name of the function.
        @param headers, optional
            1, 'x-fc-trace-id': string (a uuid to do the request tracing)
            2, user define key value
        :return array
        */
        $method  = 'DELETE';
        $path    = sprintf('/%s/services/%s.%s/functions/%s/async-invoke-config',
            $this->apiVersion, $serviceName, $qualifier, $functionName);
        $headers = $this->buildCommonHeaders($method, $path, $headers);

        return $this->doRequest($method, $path, $headers, $data = null, $query = null);
    }
}