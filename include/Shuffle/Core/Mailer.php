<?php
namespace Shuffle\Core;

/**
 * SMTP mailer for sending transactional emails.
 *
 * Implements raw SMTP commands over a socket connection with optional
 * STARTTLS encryption and AUTH LOGIN authentication. Sends multipart
 * MIME messages with both HTML and plain-text bodies.
 */
class Mailer
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    /**
     * @param array $config SMTP configuration array with keys:
     *                      host, port, encryption, username, password, from_email, from_name
     */
    public function __construct(array $config)
    {
        $this->host       = $config['host'] ?? '127.0.0.1';
        $this->port       = (int) ($config['port'] ?? 587);
        $this->encryption = $config['encryption'] ?? 'tls';
        $this->username   = $config['username'] ?? '';
        $this->password   = $config['password'] ?? '';
        $this->fromEmail  = $config['from_email'] ?? 'noreply@localhost';
        $this->fromName   = $config['from_name'] ?? 'Shuffle';
    }

    /**
     * Sends an email via SMTP.
     *
     * @param string $to       Recipient email address
     * @param string $subject  Email subject line
     * @param string $htmlBody HTML body content
     * @param string $textBody Plain-text body content
     * @throws \RuntimeException If the SMTP transaction fails
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody): void
    {
        $socket = $this->connect();

        try {
            $this->readResponse($socket, 220);
            $this->sendCommand($socket, 'EHLO ' . gethostname(), 250);

            // STARTTLS if configured
            if ($this->encryption === 'tls') {
                $this->sendCommand($socket, 'STARTTLS', 220);
                $cryptoEnabled = stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if ($cryptoEnabled === false) {
                    throw new \RuntimeException('Failed to enable TLS encryption');
                }
                // Re-issue EHLO after TLS handshake
                $this->sendCommand($socket, 'EHLO ' . gethostname(), 250);
            }

            // Authenticate if credentials are provided
            if ($this->username !== '' && $this->password !== '') {
                $this->sendCommand($socket, 'AUTH LOGIN', 334);
                $this->sendCommand($socket, base64_encode($this->username), 334);
                $this->sendCommand($socket, base64_encode($this->password), 235);
            }

            // Envelope
            $this->sendCommand($socket, 'MAIL FROM:<' . $this->fromEmail . '>', 250);
            $this->sendCommand($socket, 'RCPT TO:<' . $to . '>', 250);

            // Data
            $this->sendCommand($socket, 'DATA', 354);

            $message = $this->buildMessage($to, $subject, $htmlBody, $textBody);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->readResponse($socket, 250);

            $this->sendCommand($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Opens a socket connection to the SMTP server.
     *
     * @return resource The socket stream
     * @throws \RuntimeException If connection fails
     */
    private function connect()
    {
        $protocol = ($this->encryption === 'ssl') ? 'ssl' : 'tcp';
        $address = $protocol . '://' . $this->host . ':' . $this->port;

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
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException(
                "Failed to connect to SMTP server {$address}: [{$errno}] {$errstr}"
            );
        }

        stream_set_timeout($socket, 30);
        return $socket;
    }

    /**
     * Sends an SMTP command and validates the response code.
     *
     * @param resource $socket       The socket stream
     * @param string   $command      SMTP command to send
     * @param int      $expectedCode Expected response status code
     * @throws \RuntimeException If the response code does not match
     */
    private function sendCommand($socket, string $command, int $expectedCode): void
    {
        fwrite($socket, $command . "\r\n");
        $this->readResponse($socket, $expectedCode);
    }

    /**
     * Reads the SMTP response and validates the status code.
     *
     * @param resource $socket       The socket stream
     * @param int      $expectedCode Expected response status code
     * @return string The full response text
     * @throws \RuntimeException If the response code does not match
     */
    private function readResponse($socket, int $expectedCode): string
    {
        $response = '';
        while (true) {
            $line = fgets($socket, 512);
            if ($line === false) {
                throw new \RuntimeException('Failed to read SMTP response');
            }
            $response .= $line;

            // Multi-line responses have a dash after the code; last line has a space
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException(
                "SMTP error: expected {$expectedCode}, got {$code}. Response: {$response}"
            );
        }

        return $response;
    }

    /**
     * Builds a multipart MIME email message.
     *
     * @param string $to       Recipient address
     * @param string $subject  Email subject
     * @param string $htmlBody HTML body
     * @param string $textBody Plain-text body
     * @return string The complete MIME message
     */
    private function buildMessage(string $to, string $subject, string $htmlBody, string $textBody): string
    {
        $boundary = '----=_Part_' . bin2hex(random_bytes(16));
        $date = date('r');
        $fromEncoded = '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->fromEmail . '>';

        $headers = [
            'Date: ' . $date,
            'From: ' . $fromEncoded,
            'To: ' . $to,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        $body .= '--' . $boundary . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}
