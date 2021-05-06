<?php
namespace AliyunFC;

class Auth {
    private $access_key_id;
    private $access_key_secret;
    private $security_token;

    public function __construct($ak_id, $ak_secret, $ak_secret_token) {
        $this->access_key_id     = trim($ak_id);
        $this->access_key_secret = trim($ak_secret);
        $this->security_token    = trim($ak_secret_token);
    }

    public function getSecurityToken() {
        return $this->security_token;
    }

    private function base64UrlEncode($str) {
        $find    = array('+', '/');
        $replace = array('-', '_');
        return str_replace($find, $replace, base64_encode($str));
    }

    public function signRequest($method, $unescaped_path, $headers, $unescaped_queries = null) {
        /*
        Sign the request. See the spec for reference.
        https://help.aliyun.com/document_detail/52877.html
        @param $method: method of the http request.
        @param $headers: headers of the http request.
        @param $unescaped_path: unescaped path without queries of the http request.
        @return: the signature string.
         */

        $content_md5        = isset($headers['content-md5']) ? $headers['content-md5'] : '';
        $content_type       = isset($headers['content-type']) ? $headers['content-type'] : '';
        $date               = isset($headers['date']) ? $headers['date'] : '';
        $canonical_headers  = $this->buildCanonicalHeaders($headers);
        $canonical_resource = $unescaped_path;

        if (is_array($unescaped_queries)) {
            $canonical_resource = $this->getSignResource($unescaped_path, $unescaped_queries);
        }

        $string_to_sign = implode("\n",
            [strtoupper($method), $content_md5, $content_type, $date, $canonical_headers . $canonical_resource]);

        //echo 'string to sign: ' . $string_to_sign . PHP_EOL;

        $h = hash_hmac('sha256', $string_to_sign, $this->access_key_secret, true);

        $signature = "FC " . $this->access_key_id . ":" . base64_encode($h);

        return $signature;
    }

    private function buildCanonicalHeaders($headers) {
        /*
        @param $headers: array
        @return: $Canonicalized header string.
        @return: String
         */
        $canonical_headers = [];
        foreach ($headers as $k => $v) {
            $lower_key = trim(strtolower($k));
            if (substr($lower_key, 0, 5) === 'x-fc-') {
                $canonical_headers[$lower_key] = $v;
            }
        }
        ksort($canonical_headers);
        $canonical = '';
        foreach ($canonical_headers as $k => $v) {
            $canonical = $canonical . $k . ':' . $v . "\n";
        }
        return $canonical;
    }

    private function getSignResource($unescaped_path, $unescaped_queries) {
        if (!is_array($unescaped_queries)) {
            throw new \Exception("`array` type required for queries");
        }

        $params = [];
        foreach ($unescaped_queries as $key => $values) {
            if (is_string($values)) {
                $params[] = sprintf('%s=%s', $key, $values);
                continue;
            }
            if (count($values) > 0) {
                foreach ($values as $value) {
                    $params[] = sprintf('%s=%s', $key, $value);
                }
            } else {
                $params[] = strval($key);
            }
        }
        ksort($params);

        $resource = $unescaped_path . "\n" . implode("\n", $params);

        return $resource;
    }
}
