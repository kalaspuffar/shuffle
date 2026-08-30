<?php
namespace Shuffle\Core;

/**
 * HTTP response helper.
 *
 * Provides convenience methods for sending JSON responses, errors,
 * no-content responses, and file streams.
 */
class Response
{
    /**
     * Sends a JSON response.
     *
     * @param array $data   Response data
     * @param int   $status HTTP status code
     */
    public function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Sends a JSON error response.
     *
     * @param string $message Error message
     * @param int    $status  HTTP status code
     */
    public function error(string $message, int $status): void
    {
        $this->json(['error' => $message], $status);
    }

    /**
     * Sends a 204 No Content response.
     */
    public function noContent(): void
    {
        http_response_code(204);
        header('Content-Length: 0');
    }

    /**
     * Sends a 304 Not Modified response.
     */
    public function notModified(): void
    {
        http_response_code(304);
    }

    /**
     * Sends a plain-text response (e.g. text/markdown digests).
     *
     * @param string $body        Response body
     * @param string $contentType MIME type
     * @param int    $status      HTTP status code
     */
    public function text(string $body, string $contentType, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Cache-Control: no-cache');
        echo $body;
    }

    /**
     * Streams a file to the client.
     *
     * @param resource $stream      Readable stream resource
     * @param string   $contentType MIME type
     * @param int      $size        File size in bytes
     * @param string   $filename    Suggested filename for download
     */
    public function stream($stream, string $contentType, int $size, string $filename): void
    {
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Cache-Control: no-cache');

        fpassthru($stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
