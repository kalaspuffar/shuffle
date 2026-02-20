<?php
namespace Shuffle\Controller;

use Shuffle\Core\Database;
use Shuffle\Core\Request;
use Shuffle\Core\Response;

/**
 * Handles setup-wizard API endpoints.
 *
 * These endpoints are unauthenticated but are guarded by a check that returns
 * 403 Forbidden if any admin user already exists in the database. This removes
 * the endpoint from the attack surface the moment setup completes.
 */
class SetupController
{
    private Database $db;

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * POST /v1/setup/test-smtp
     *
     * Accepts SMTP settings as JSON, attempts a TCP connection and AUTH
     * handshake (no email is sent), and returns the result.
     *
     * Returns 403 if any admin user already exists.
     * Returns {"ok": true} on success.
     * Returns {"ok": false, "error": "..."} on failure.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function testSmtp(Request $request, Response $response): void
    {
        // Guard: endpoint is only usable before any admin account exists
        $adminExists = $this->db->fetch(
            "SELECT id FROM users WHERE role = 'admin' LIMIT 1"
        );
        if ($adminExists !== null) {
            $response->error('Forbidden', 403);
            return;
        }

        $data = $request->getBody();

        $host       = trim($data['host'] ?? '');
        $port       = (int) ($data['port'] ?? 587);
        $encryption = $data['encryption'] ?? 'tls';
        $username   = $data['username'] ?? '';
        $password   = $data['password'] ?? '';

        if ($host === '') {
            $response->json(['ok' => false, 'error' => 'SMTP host is required']);
            return;
        }

        try {
            $this->attemptSmtpConnection($host, $port, $encryption, $username, $password);
            $response->json(['ok' => true]);
        } catch (\RuntimeException $e) {
            $response->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Attempts a TCP connection, STARTTLS/SSL negotiation, and AUTH LOGIN
     * handshake against the given SMTP server. No email is sent.
     *
     * @param string $host       SMTP host
     * @param int    $port       SMTP port
     * @param string $encryption 'tls', 'ssl', or 'none'
     * @param string $username   AUTH username (empty to skip AUTH)
     * @param string $password   AUTH password
     * @throws \RuntimeException If the connection or handshake fails
     */
    private function attemptSmtpConnection(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password
    ): void {
        $protocol = ($encryption === 'ssl') ? 'ssl' : 'tcp';
        $address  = $protocol . '://' . $host . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException(
                "Cannot connect to {$host}:{$port} — [{$errno}] {$errstr}"
            );
        }

        stream_set_timeout($socket, 15);

        try {
            $this->readSmtpResponse($socket, 220);
            $this->sendSmtpCommand($socket, 'EHLO ' . gethostname(), 250);

            if ($encryption === 'tls') {
                $this->sendSmtpCommand($socket, 'STARTTLS', 220);
                $upgraded = stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if ($upgraded === false) {
                    throw new \RuntimeException('TLS upgrade failed');
                }
                // Re-issue EHLO after TLS handshake as required by RFC 3207
                $this->sendSmtpCommand($socket, 'EHLO ' . gethostname(), 250);
            }

            if ($username !== '' && $password !== '') {
                $this->sendSmtpCommand($socket, 'AUTH LOGIN', 334);
                $this->sendSmtpCommand($socket, base64_encode($username), 334);
                $this->sendSmtpCommand($socket, base64_encode($password), 235);
            }

            // Graceful exit — no email sent
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * Sends an SMTP command and validates the server's response code.
     *
     * @param resource $socket       Socket stream
     * @param string   $command      SMTP command text
     * @param int      $expectedCode Expected 3-digit response code
     * @throws \RuntimeException If the response code does not match
     */
    private function sendSmtpCommand($socket, string $command, int $expectedCode): void
    {
        fwrite($socket, $command . "\r\n");
        $this->readSmtpResponse($socket, $expectedCode);
    }

    /**
     * Reads lines from the SMTP server until the last line of a response is
     * received, then validates the status code.
     *
     * @param resource $socket       Socket stream
     * @param int      $expectedCode Expected 3-digit response code
     * @throws \RuntimeException If reading fails or the code does not match
     */
    private function readSmtpResponse($socket, int $expectedCode): void
    {
        $response = '';

        while (true) {
            $line = fgets($socket, 512);
            if ($line === false) {
                throw new \RuntimeException('Lost connection to SMTP server');
            }
            $response .= $line;

            // Multi-line responses have a dash after the code; last line has a space
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            $detail = trim(substr($response, 4));
            throw new \RuntimeException("SMTP error {$code}: {$detail}");
        }
    }
}
