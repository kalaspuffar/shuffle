<?php
declare(strict_types=1);

namespace Shuffle\Core;

/**
 * S3-compatible storage client with AWS Signature V4 authentication.
 *
 * Supports path-style URLs (required for MinIO) and provides single-part
 * upload, multipart upload for large files, download, delete, and existence
 * check operations.
 *
 * Key naming convention: {board_id}/{card_id}/{uuid}_{original_filename}
 */
class S3Client
{
    private string $endpoint;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private bool $pathStyle;
    private string $service = 's3';

    /**
     * @param array $config S3 configuration with keys:
     *   endpoint, bucket, access_key, secret_key, region, path_style
     */
    public function __construct(array $config)
    {
        $this->endpoint  = rtrim($config['endpoint'] ?? '', '/');
        $this->bucket    = $config['bucket'] ?? '';
        $this->accessKey = $config['access_key'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->region    = $config['region'] ?? 'us-east-1';
        $this->pathStyle = (bool) ($config['path_style'] ?? true);
    }

    /**
     * Uploads a file using a single PUT request.
     *
     * Suitable for files that fit within the configured chunk size.
     * Reads the stream in full and sends as the PUT body.
     *
     * @param string   $key         S3 object key
     * @param resource $stream      Readable stream of file data
     * @param int      $size        File size in bytes
     * @param string   $contentType MIME type of the file
     * @throws \RuntimeException On upload failure
     */
    public function putObject(string $key, $stream, int $size, string $contentType): void
    {
        $body = stream_get_contents($stream);
        if ($body === false) {
            throw new \RuntimeException('Failed to read upload stream');
        }

        $headers = [
            'Content-Type'   => $contentType,
            'Content-Length' => (string) $size,
        ];

        $response = $this->sendRequest('PUT', $key, $headers, $body);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(
                'S3 putObject failed with status ' . $response['status'] . ': ' . $response['body']
            );
        }
    }

    /**
     * Downloads an object and returns a readable stream.
     *
     * The caller is responsible for closing the returned stream.
     *
     * @param string $key S3 object key
     * @return resource Readable stream of object data
     * @throws \RuntimeException On download failure
     */
    public function getObject(string $key)
    {
        $url = $this->buildUrl($key);
        $headers = [];
        $signedHeaders = $this->signRequest('GET', $key, $headers, '');

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $this->formatHeaders($signedHeaders),
                'timeout' => 300,
                'ignore_errors' => true,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('S3 getObject failed: could not open stream for key ' . $key);
        }

        $meta = stream_get_meta_data($stream);
        $statusLine = $meta['wrapper_data'][0] ?? '';
        if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $statusLine, $matches)) {
            $statusCode = (int) $matches[1];
            if ($statusCode >= 400) {
                fclose($stream);
                throw new \RuntimeException('S3 getObject failed with status ' . $statusCode);
            }
        }

        return $stream;
    }

    /**
     * Deletes an object from S3.
     *
     * @param string $key S3 object key
     * @throws \RuntimeException On deletion failure
     */
    public function deleteObject(string $key): void
    {
        $response = $this->sendRequest('DELETE', $key, [], '');

        if ($response['status'] !== 204 && $response['status'] !== 200) {
            throw new \RuntimeException(
                'S3 deleteObject failed with status ' . $response['status'] . ': ' . $response['body']
            );
        }
    }

    /**
     * Checks whether an object exists using a HEAD request.
     *
     * @param string $key S3 object key
     * @return bool True if the object exists
     */
    public function objectExists(string $key): bool
    {
        $response = $this->sendRequest('HEAD', $key, [], '');
        return $response['status'] === 200;
    }

    /**
     * Initiates a multipart upload.
     *
     * @param string $key         S3 object key
     * @param string $contentType MIME type
     * @return string Upload ID for subsequent part uploads
     * @throws \RuntimeException On failure
     */
    public function createMultipartUpload(string $key, string $contentType): string
    {
        $headers = [
            'Content-Type' => $contentType,
        ];

        $response = $this->sendRequest('POST', $key . '?uploads', $headers, '');

        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                'S3 createMultipartUpload failed with status ' . $response['status'] . ': ' . $response['body']
            );
        }

        // Parse UploadId from XML response
        if (preg_match('/<UploadId>(.+?)<\/UploadId>/', $response['body'], $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException('S3 createMultipartUpload: UploadId not found in response');
    }

    /**
     * Uploads a single part of a multipart upload.
     *
     * @param string $key        S3 object key
     * @param string $uploadId   Upload ID from createMultipartUpload()
     * @param int    $partNumber 1-based part number
     * @param string $body       Part data
     * @return string ETag of the uploaded part (needed for completion)
     * @throws \RuntimeException On failure
     */
    public function uploadPart(string $key, string $uploadId, int $partNumber, string $body): string
    {
        $queryString = 'partNumber=' . $partNumber . '&uploadId=' . urlencode($uploadId);
        $headers = [
            'Content-Length' => (string) strlen($body),
        ];

        $response = $this->sendRequest('PUT', $key . '?' . $queryString, $headers, $body);

        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                'S3 uploadPart failed with status ' . $response['status'] . ': ' . $response['body']
            );
        }

        // Extract ETag from response headers
        foreach ($response['headers'] as $header) {
            if (preg_match('/^etag:\s*(.+)$/i', $header, $matches)) {
                return trim($matches[1], " \t\n\r\"");
            }
        }

        throw new \RuntimeException('S3 uploadPart: ETag not found in response headers');
    }

    /**
     * Completes a multipart upload with the list of part ETags.
     *
     * @param string $key      S3 object key
     * @param string $uploadId Upload ID
     * @param array  $parts    Array of ['partNumber' => int, 'etag' => string]
     * @throws \RuntimeException On failure
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $part) {
            $xml .= '<Part>';
            $xml .= '<PartNumber>' . (int) $part['partNumber'] . '</PartNumber>';
            $xml .= '<ETag>"' . htmlspecialchars($part['etag'], ENT_XML1, 'UTF-8') . '"</ETag>';
            $xml .= '</Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $queryString = 'uploadId=' . urlencode($uploadId);
        $headers = [
            'Content-Type'   => 'application/xml',
            'Content-Length' => (string) strlen($xml),
        ];

        $response = $this->sendRequest('POST', $key . '?' . $queryString, $headers, $xml);

        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                'S3 completeMultipartUpload failed with status ' . $response['status'] . ': ' . $response['body']
            );
        }
    }

    /**
     * Aborts a multipart upload, cleaning up any uploaded parts.
     *
     * @param string $key      S3 object key
     * @param string $uploadId Upload ID
     */
    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $queryString = 'uploadId=' . urlencode($uploadId);

        try {
            $this->sendRequest('DELETE', $key . '?' . $queryString, [], '');
        } catch (\RuntimeException $e) {
            // Log but don't rethrow — abort is best-effort cleanup
            error_log('S3 abortMultipartUpload warning: ' . $e->getMessage());
        }
    }

    /**
     * Sends a signed HTTP request to S3 and returns the response.
     *
     * @param string $method  HTTP method (GET, PUT, POST, DELETE, HEAD)
     * @param string $keyPath Object key, possibly including query string
     * @param array  $headers Additional headers (Content-Type, Content-Length, etc.)
     * @param string $body    Request body
     * @return array ['status' => int, 'headers' => string[], 'body' => string]
     */
    private function sendRequest(string $method, string $keyPath, array $headers, string $body): array
    {
        $url = $this->buildUrl($keyPath);
        $signedHeaders = $this->signRequest($method, $keyPath, $headers, $body);

        $headerLines = $this->formatHeaders($signedHeaders);

        $options = [
            'http' => [
                'method'         => $method,
                'header'         => $headerLines,
                'content'        => $body,
                'timeout'        => 300,
                'ignore_errors'  => true,
            ],
        ];

        $context = stream_context_create($options);
        $responseBody = @file_get_contents($url, false, $context);

        $statusCode = 0;
        $responseHeaders = [];

        if (isset($http_response_header) && is_array($http_response_header)) {
            $responseHeaders = $http_response_header;
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $statusLine, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        return [
            'status'  => $statusCode,
            'headers' => $responseHeaders,
            'body'    => $responseBody !== false ? $responseBody : '',
        ];
    }

    /**
     * Signs a request using AWS Signature V4 (HMAC-SHA256).
     *
     * Constructs the canonical request, string-to-sign, signing key,
     * and produces the Authorization header value per the AWS specification.
     *
     * @param string $method  HTTP method
     * @param string $keyPath Object key (may include query string after '?')
     * @param array  $headers Request headers to include in signing
     * @param string $body    Request body (hashed for signature)
     * @return array Complete set of headers including Authorization, x-amz-date, x-amz-content-sha256
     */
    private function signRequest(string $method, string $keyPath, array $headers, string $body): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $dateStamp = $now->format('Ymd');
        $amzDate   = $now->format('Ymd\THis\Z');

        // Separate key from query string
        $queryString = '';
        $cleanKey = $keyPath;
        if (($qPos = strpos($keyPath, '?')) !== false) {
            $queryString = substr($keyPath, $qPos + 1);
            $cleanKey = substr($keyPath, 0, $qPos);
        }

        // Build canonical URI (path-style: /{bucket}/{key})
        $canonicalUri = '/' . $this->bucket . '/' . $this->uriEncodePath($cleanKey);

        // Parse and sort query string parameters
        $canonicalQueryString = $this->buildCanonicalQueryString($queryString);

        // Compute payload hash
        $payloadHash = hash('sha256', $body);

        // Build host header
        $parsedEndpoint = parse_url($this->endpoint);
        $host = $parsedEndpoint['host'] ?? 'localhost';
        if (isset($parsedEndpoint['port'])) {
            $defaultPort = ($parsedEndpoint['scheme'] ?? 'http') === 'https' ? 443 : 80;
            if ((int) $parsedEndpoint['port'] !== $defaultPort) {
                $host .= ':' . $parsedEndpoint['port'];
            }
        }

        // Merge required headers
        $headers['Host']                 = $host;
        $headers['x-amz-date']           = $amzDate;
        $headers['x-amz-content-sha256'] = $payloadHash;

        // Build canonical headers (lowercase, sorted)
        $canonicalHeaders = '';
        $signedHeaderNames = [];
        $sortedHeaders = [];
        foreach ($headers as $name => $value) {
            $sortedHeaders[strtolower($name)] = trim($value);
        }
        ksort($sortedHeaders);

        foreach ($sortedHeaders as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
            $signedHeaderNames[] = $name;
        }
        $signedHeadersString = implode(';', $signedHeaderNames);

        // Canonical request
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeadersString,
            $payloadHash,
        ]);

        // Credential scope
        $credentialScope = $dateStamp . '/' . $this->region . '/' . $this->service . '/aws4_request';

        // String to sign
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        // Signing key (derived from secret + date + region + service)
        $signingKey = $this->deriveSigningKey($dateStamp);

        // Signature
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        // Authorization header
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $credentialScope,
            $signedHeadersString,
            $signature
        );

        $headers['Authorization'] = $authorization;

        return $headers;
    }

    /**
     * Derives the signing key using HMAC-SHA256 chain.
     *
     * SigningKey = HMAC(HMAC(HMAC(HMAC("AWS4"+secret, date), region), service), "aws4_request")
     *
     * @param string $dateStamp Date in Ymd format
     * @return string Binary signing key
     */
    private function deriveSigningKey(string $dateStamp): string
    {
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * Builds the full URL for an S3 request using path-style addressing.
     *
     * @param string $keyPath Object key, possibly with query string
     * @return string Full URL
     */
    private function buildUrl(string $keyPath): string
    {
        $queryString = '';
        $cleanKey = $keyPath;
        if (($qPos = strpos($keyPath, '?')) !== false) {
            $queryString = '?' . substr($keyPath, $qPos + 1);
            $cleanKey = substr($keyPath, 0, $qPos);
        }

        return $this->endpoint . '/' . $this->bucket . '/' . $this->uriEncodePath($cleanKey) . $queryString;
    }

    /**
     * URI-encodes a path, preserving forward slashes.
     *
     * @param string $path Path to encode
     * @return string URI-encoded path
     */
    private function uriEncodePath(string $path): string
    {
        $segments = explode('/', $path);
        $encoded = array_map(function (string $segment): string {
            return rawurlencode($segment);
        }, $segments);
        return implode('/', $encoded);
    }

    /**
     * Builds a canonical query string from a raw query string.
     *
     * Parameters are sorted by name, then by value.
     *
     * @param string $queryString Raw query string (without leading '?')
     * @return string Canonical query string
     */
    private function buildCanonicalQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        $params = [];
        parse_str($queryString, $parsed);

        foreach ($parsed as $key => $value) {
            $params[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
        }

        sort($params);
        return implode('&', $params);
    }

    /**
     * Formats headers array into HTTP header lines for stream context.
     *
     * @param array $headers Associative array of header name => value
     * @return string Header lines separated by \r\n
     */
    private function formatHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return implode("\r\n", $lines);
    }
}
