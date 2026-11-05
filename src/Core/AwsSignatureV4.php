<?php

namespace P8P\Core;

/** Shared S3/Lambda SigV4; the caller supplies the encoded request path and query. */
class AwsSignatureV4
{
    public static function authorization(string $method, string $path, string $query, array $headers, string $payloadHash, string $region, array $credentials, string $service = 's3', bool $doubleEncodePath = false): string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        unset($headers['authorization']);
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . preg_replace('/[ \t]+/', ' ', trim($value)) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $uri = $doubleEncodePath ? implode('/', array_map('rawurlencode', explode('/', $path))) : $path;
        $canonical = $method . "\n" . $uri . "\n" . $query . "\n" . $canonicalHeaders . "\n" . $signed . "\n" . $payloadHash;
        $date = substr($headers['x-amz-date'], 0, 8);
        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $toSign = "AWS4-HMAC-SHA256\n" . $headers['x-amz-date'] . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $key = hash_hmac('sha256', $date, 'AWS4' . $credentials['secret_access_key'], true);
        foreach ([$region, $service, 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        return 'AWS4-HMAC-SHA256 Credential=' . $credentials['access_key_id'] . '/' . $scope . ',SignedHeaders=' . $signed . ',Signature=' . hash_hmac('sha256', $toSign, $key);
    }
}
