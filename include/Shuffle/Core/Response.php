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

    /**
     * Streams a file as an INLINE viewable document (FILE-06/07, §5.23).
     *
     * Same fpassthru streaming as stream(), but:
     *   - Content-Disposition: inline; filename="..." (RFC 5987 filename*
     *     percent-encoding for non-ASCII names, ASCII fallback kept)
     *   - Accept-Ranges: bytes (the preview endpoint supports ranged reads)
     *   - Cache-Control: private, no-store (auth-gated proxy — must not be
     *     shared-cached across identities; download uses no-cache)
     *   - optional $range: ['start' => int, 'end' => int, 'total' => int]
     *     → 206 Partial Content + Content-Range: bytes start-end/total.
     *     Omitted → 200 full object.
     *
     * @param resource        $stream      Readable stream (closed here)
     * @param string          $contentType MIME type served
     * @param int             $size        Body length actually served (range length for 206)
     * @param string          $filename    Suggested name for Save-As
     * @param array|null      $range       start/end/total when serving a 206 range
     */
    public function streamInline($stream, string $contentType, int $size, string $filename, ?array $range = null): void
    {
        // RFC 5987: keep an ASCII-only fallback filename, add filename* for the real one
        $asciiName = preg_match('/^[\x20-\x7E]*$/', $filename) ? $filename : 'download';
        $disposition = 'inline; filename="' . addslashes($asciiName) . '"';
        if ($asciiName !== $filename) {
            $disposition .= "; filename*=UTF-8''" . rawurlencode($filename);
        }

        if ($range !== null) {
            http_response_code(206);
            header(sprintf('Content-Range: bytes %d-%d/%d',
                (int) $range['start'], (int) $range['end'], (int) $range['total']));
        } else {
            http_response_code(200);
        }

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $size);
        header('Content-Disposition: ' . $disposition);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');

        fpassthru($stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
