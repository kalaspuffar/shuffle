<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\S3Client;
use Shuffle\Model\Attachment;
use Shuffle\Model\Board;
use Shuffle\Model\Card;

/**
 * Attachment business logic service.
 *
 * Handles file uploads to S3 (single-part and multipart), downloads
 * via S3 streaming proxy, and deletion of both S3 objects and DB records.
 */
class AttachmentService
{
    private Attachment $attachmentModel;
    private Card $cardModel;
    private Board $boardModel;
    private S3Client $s3;
    private int $chunkSize;
    private int $maxFileSize;

    /** MIME types allowed for upload */
    private const ALLOWED_MIME_PREFIXES = [
        'image/', 'application/pdf', 'text/', 'application/zip',
        'application/x-zip', 'application/gzip', 'application/json',
        'application/xml', 'application/msword', 'application/vnd.',
        'audio/', 'video/',
    ];

    /**
     * @param Attachment $attachmentModel Attachment data access
     * @param Card       $cardModel       Card data access (for board ID lookup)
     * @param Board      $boardModel      Board data access (for version bumping)
     * @param S3Client   $s3              S3 storage client
     * @param int        $chunkSize       Multipart chunk size in bytes (default 5 MB)
     * @param int        $maxFileSize     Maximum allowed upload size in bytes (default 100 MB)
     */
    public function __construct(
        Attachment $attachmentModel,
        Card $cardModel,
        Board $boardModel,
        S3Client $s3,
        int $chunkSize = 5242880,
        int $maxFileSize = 104857600
    ) {
        $this->attachmentModel = $attachmentModel;
        $this->cardModel       = $cardModel;
        $this->boardModel      = $boardModel;
        $this->s3              = $s3;
        $this->chunkSize       = $chunkSize;
        $this->maxFileSize     = $maxFileSize;
    }

    /**
     * Uploads a file from a stream to S3 and creates an attachment record.
     *
     * Uses single PUT for small files (<= chunk_size) or multipart upload
     * for larger files, streaming from php://input to minimize memory usage.
     *
     * @param int      $cardId      Card ID to attach the file to
     * @param int      $userId      Uploading user's ID
     * @param string   $fileName    Original filename
     * @param int      $fileSize    File size in bytes
     * @param string   $mimeType    MIME type
     * @param resource $inputStream Readable stream (typically php://input)
     * @return array The created attachment record
     * @throws \InvalidArgumentException On validation failure
     * @throws \RuntimeException On upload failure
     */
    public function upload(
        int $cardId,
        int $userId,
        string $fileName,
        int $fileSize,
        string $mimeType,
        $inputStream
    ): array {
        $this->validateUpload($fileName, $fileSize, $mimeType);

        $boardId = $this->cardModel->getBoardId($cardId);
        if ($boardId === null) {
            throw new \InvalidArgumentException('Card not found');
        }

        // Generate unique S3 key: {board_id}/{card_id}/{uuid}_{filename}
        $uuid = bin2hex(random_bytes(16));
        $safeFileName = $this->sanitizeFileName($fileName);
        $s3Key = $boardId . '/' . $cardId . '/' . $uuid . '_' . $safeFileName;

        try {
            if ($fileSize <= $this->chunkSize) {
                $this->uploadSinglePart($s3Key, $inputStream, $fileSize, $mimeType);
            } else {
                $this->uploadMultipart($s3Key, $inputStream, $fileSize, $mimeType);
            }
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('File upload failed: ' . $e->getMessage());
        }

        // Store metadata in database
        $attachmentId = $this->attachmentModel->create([
            'card_id'   => $cardId,
            'user_id'   => $userId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            's3_key'    => $s3Key,
            'mime_type' => $mimeType,
        ]);

        $this->boardModel->incrementVersion($boardId);

        return $this->attachmentModel->findById($attachmentId);
    }

    /**
     * Returns a readable stream for downloading an attachment.
     *
     * @param int $attachmentId Attachment ID
     * @return array ['stream' => resource, 'attachment' => array]
     * @throws \RuntimeException If attachment not found or download fails
     */
    public function download(int $attachmentId): array
    {
        $attachment = $this->attachmentModel->findById($attachmentId);
        if ($attachment === null) {
            throw new \RuntimeException('Attachment not found');
        }

        $stream = $this->s3->getObject($attachment['s3_key']);

        return [
            'stream'     => $stream,
            'attachment' => $attachment,
        ];
    }

    /**
     * Deletes an attachment from S3 and the database.
     *
     * S3 deletion failures are logged but the DB record is still removed
     * to avoid blocking the user. Orphaned S3 objects can be cleaned up
     * by maintenance.
     *
     * @param int $attachmentId Attachment ID
     * @throws \RuntimeException If attachment not found
     */
    public function deleteAttachment(int $attachmentId): void
    {
        $attachment = $this->attachmentModel->findById($attachmentId);
        if ($attachment === null) {
            throw new \RuntimeException('Attachment not found');
        }

        $boardId = $this->cardModel->getBoardId((int) $attachment['card_id']);

        // Delete from S3 (best-effort)
        try {
            $this->s3->deleteObject($attachment['s3_key']);
        } catch (\RuntimeException $e) {
            error_log('AttachmentService: S3 delete failed for key ' . $attachment['s3_key'] . ': ' . $e->getMessage());
        }

        // Always remove DB record
        $this->attachmentModel->delete($attachmentId);

        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
    }

    /**
     * Returns all attachments for a card.
     *
     * @param int $cardId Card ID
     * @return array Array of attachment records with user_name
     */
    public function getAttachmentsForCard(int $cardId): array
    {
        return $this->attachmentModel->findByCard($cardId);
    }

    /**
     * Returns a single attachment record by ID.
     *
     * @param int $attachmentId Attachment ID
     * @return array|null Attachment row or null if not found
     */
    public function getAttachment(int $attachmentId): ?array
    {
        return $this->attachmentModel->findById($attachmentId);
    }

    /**
     * Returns the board ID for an attachment (for access control checks).
     *
     * Resolved via a single JOIN query (attachments → cards → lanes) to
     * avoid two database round-trips on every download and delete call.
     *
     * @param int $attachmentId Attachment ID
     * @return int|null Board ID or null
     */
    public function getBoardIdForAttachment(int $attachmentId): ?int
    {
        return $this->attachmentModel->getBoardId($attachmentId);
    }

    /**
     * Uploads a file using a single PUT request.
     *
     * @param string   $s3Key       S3 object key
     * @param resource $stream      Input stream
     * @param int      $size        File size
     * @param string   $contentType MIME type
     */
    private function uploadSinglePart(string $s3Key, $stream, int $size, string $contentType): void
    {
        $this->s3->putObject($s3Key, $stream, $size, $contentType);
    }

    /**
     * Uploads a file using multipart upload, reading the stream in chunks.
     *
     * @param string   $s3Key       S3 object key
     * @param resource $stream      Input stream
     * @param int      $size        File size
     * @param string   $contentType MIME type
     */
    private function uploadMultipart(string $s3Key, $stream, int $size, string $contentType): void
    {
        $uploadId = $this->s3->createMultipartUpload($s3Key, $contentType);

        try {
            $parts = [];
            $partNumber = 1;

            while (!feof($stream)) {
                $chunk = fread($stream, $this->chunkSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $etag = $this->s3->uploadPart($s3Key, $uploadId, $partNumber, $chunk);
                $parts[] = [
                    'partNumber' => $partNumber,
                    'etag'       => $etag,
                ];
                $partNumber++;
            }

            $this->s3->completeMultipartUpload($s3Key, $uploadId, $parts);
        } catch (\RuntimeException $e) {
            $this->s3->abortMultipartUpload($s3Key, $uploadId);
            throw $e;
        }
    }

    /**
     * Validates upload parameters.
     *
     * @param string $fileName Original filename
     * @param int    $fileSize File size in bytes
     * @param string $mimeType MIME type
     * @throws \InvalidArgumentException On validation failure
     */
    private function validateUpload(string $fileName, int $fileSize, string $mimeType): void
    {
        if (trim($fileName) === '') {
            throw new \InvalidArgumentException('File name is required');
        }

        if ($fileSize > $this->maxFileSize) {
            throw new \InvalidArgumentException(
                'File size exceeds the maximum allowed size of ' . $this->maxFileSize . ' bytes'
            );
        }

        if (mb_strlen($fileName, 'UTF-8') > 255) {
            throw new \InvalidArgumentException('File name must be no more than 255 characters');
        }

        // Check for directory traversal attempts
        $baseName = basename($fileName);
        if ($baseName !== $fileName || str_contains($fileName, '..')) {
            throw new \InvalidArgumentException('Invalid file name');
        }

        // Validate MIME type against allowlist
        $allowed = false;
        foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new \InvalidArgumentException('File type not allowed: ' . $mimeType);
        }
    }

    /**
     * Sanitizes a filename for use in S3 keys.
     *
     * Removes path separators and other problematic characters,
     * keeping only alphanumeric, dots, hyphens, and underscores.
     *
     * @param string $fileName Original filename
     * @return string Sanitized filename
     */
    private function sanitizeFileName(string $fileName): string
    {
        // Use only the basename
        $name = basename($fileName);
        // Replace non-safe characters with underscores
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        // Collapse multiple underscores
        $name = preg_replace('/_+/', '_', $name);
        // Trim underscores from edges
        $name = trim($name, '_');
        // Guard against symbol-only filenames (e.g. "!!!" → "") that would produce an
        // empty S3 key segment
        if ($name === '') {
            $name = 'file';
        }
        return $name;
    }
}
