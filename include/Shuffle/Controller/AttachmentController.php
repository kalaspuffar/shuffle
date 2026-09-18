<?php
declare(strict_types=1);

namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\AttachmentService;
use Shuffle\Service\CardService;

/**
 * Attachment management API controller.
 *
 * Handles file upload (raw binary body), listing, download proxy,
 * and deletion. Access is enforced via Auth::canAccessBoard.
 */
class AttachmentController
{
    private Auth $auth;
    private AttachmentService $attachmentService;
    private CardService $cardService;

    /**
     * @param Auth              $auth              Auth service
     * @param AttachmentService $attachmentService Attachment business logic
     * @param CardService       $cardService       Card service (for board access checks)
     */
    public function __construct(Auth $auth, AttachmentService $attachmentService, CardService $cardService)
    {
        $this->auth = $auth;
        $this->attachmentService = $attachmentService;
        $this->cardService = $cardService;
    }

    /**
     * POST /v1/cards/{cardId}/attachments
     *
     * Uploads a file attachment. The request body is raw binary data.
     * Metadata is provided via headers:
     *   X-File-Name: URL-encoded original filename
     *   X-File-Size: File size in bytes
     *   Content-Type: MIME type of the file
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function create(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $cardId = (int) ($params['cardId'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $fileName = $request->getHeader('X-File-Name');
        $fileSize = $request->getHeader('X-File-Size');
        $mimeType = $request->getHeader('Content-Type', 'application/octet-stream');

        if ($fileName === null || $fileName === '') {
            $response->error('X-File-Name header is required', 400);
            return;
        }

        // URL-decode the filename
        $fileName = urldecode($fileName);

        if ($fileSize === null || !ctype_digit($fileSize)) {
            $response->error('X-File-Size header is required and must be a positive integer', 400);
            return;
        }

        $fileSizeInt = (int) $fileSize;
        if ($fileSizeInt < 1) {
            $response->error('File size must be greater than zero', 400);
            return;
        }

        $inputStream = $request->getInputStream();

        try {
            $attachment = $this->attachmentService->upload(
                $cardId,
                (int) $currentUser['id'],
                $fileName,
                $fileSizeInt,
                $mimeType,
                $inputStream
            );

            $response->json(['attachment' => $attachment], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 500);
        } finally {
            if (is_resource($inputStream)) {
                fclose($inputStream);
            }
        }
    }

    /**
     * GET /v1/cards/{cardId}/attachments
     *
     * Lists all attachments for a card.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function index(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $cardId = (int) ($params['cardId'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $attachments = $this->attachmentService->getAttachmentsForCard($cardId);
        $response->json(['attachments' => $attachments]);
    }

    /**
     * GET /v1/attachments/{id}/download
     *
     * Proxies a file download from S3 to the client. Streams the file
     * through PHP to keep the S3 endpoint hidden from browsers.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function download(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->attachmentService->getBoardIdForAttachment($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Attachment not found', 404);
            return;
        }

        try {
            $result = $this->attachmentService->download($id);
            $attachment = $result['attachment'];

            $response->stream(
                $result['stream'],
                $attachment['mime_type'],
                (int) $attachment['file_size'],
                $attachment['file_name']
            );
        } catch (\RuntimeException $e) {
            $response->error('Download failed', 500);
        }
    }

    /**
     * GET /v1/attachments/{id}/preview (FILE-06/07, §5.23)
     *
     * Serves a PREVIEWABLE attachment inline (browser viewable), with
     * optional byte-range support (206) for the PDF viewer. Types outside
     * the preview set (Attachment::PREVIEWABLE_MIME, spec §5.23) → 415;
     * `/download` keeps working unchanged for all types.
     *
     * Range contract (spec §5.23):
     * - No Range header          → 200 full object
     * - Range: bytes=S-E in-bounds → 206 Partial Content, Content-Range S-E/total
     * - Malformed / out-of-bounds → 416 + Accept-Ranges: bytes + a
     *                               Content-Range header in the unsatisfiable
     *                               form (bytes with a star-total value)
     * - Only closed `bytes=S-E` is supported; open-ended forms (bytes=N- or
     *   bytes=-N) and `*` are rejected as 416 (v1 contract).
     *
     * Error semantics (spec §5.23):
     * - unknown id / inaccessible board → 404
     * - type not in the preview set    → 415 (PreviewTypeException)
     * - unsatisfiable range            → 416 (RangeException)
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function preview(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->attachmentService->getBoardIdForAttachment($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Attachment not found', 404);
            return;
        }

        // -- Parse Range (only closed ranges v1) ---------------------------
        $rangeHeader = $request->getHeader('Range');
        $start = null;
        $end   = null;
        if ($rangeHeader !== null && $rangeHeader !== '') {
            // Accept only: bytes={start}-{end}  (closed, both-numeric).
            // Anything else (open ranges, suffix, `*`, malformed) is NOT
            // in the v1 contract and maps to 416 (client math or shape
            // doesn't fit what we serve — same semantics as a valid
            // range the object is too small for).
            if (!preg_match('/^bytes=(\d+)-(\d+)$/', trim($rangeHeader), $m)) {
                http_response_code(416);
                header('Accept-Ranges: bytes');
                header('Content-Range: bytes */0');
                header('Cache-Control: private, no-store');
                echo json_encode(['error' => 'Range not satisfiable']) . "\n";
                return;
            }
            $start = (int) $m[1];
            $end   = (int) $m[2];
        }

        try {
            $result = $this->attachmentService->preview($id, $start, $end);
            $attachment = $result['attachment'];

            $size = $result['range'] !== null
                ? ((int) $result['range']['end'] - (int) $result['range']['start'] + 1)
                : (int) $attachment['file_size'];

            $response->streamInline(
                $result['stream'],
                (string) $attachment['mime_type'],
                $size,
                (string) $attachment['file_name'],
                $result['range'] !== null
                    ? [
                        'start' => (int) $result['range']['start'],
                        'end'   => (int) $result['range']['end'],
                        'total' => (int) $result['range']['total'],
                    ]
                    : null
            );
        } catch (\Shuffle\Core\PreviewTypeException $e) {
            http_response_code(415);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache');
            echo json_encode(['error' => 'This file type is not previewable']) . "\n";
        } catch (\Shuffle\Core\RangeException $e) {
            http_response_code(416);
            header('Accept-Ranges: bytes');
            header('Content-Range: bytes */0');
            header('Content-Type: application/json');
            header('Cache-Control: private, no-store');
            echo json_encode(['error' => 'Range not satisfiable']) . "\n";
        } catch (\RuntimeException $e) {
            $response->error('Preview not available', 404);
        }
    }

    /**
     * DELETE /v1/attachments/{id}
     *
     * Deletes an attachment. Only the uploader or admin may delete.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->attachmentService->getBoardIdForAttachment($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Attachment not found', 404);
            return;
        }

        // Enforce uploader-or-admin rule: only the uploader or an admin may delete
        $attachment = $this->attachmentService->getAttachment($id);
        if ($currentUser['role'] !== 'admin' && (int) $attachment['user_id'] !== (int) $currentUser['id']) {
            $response->error('You do not have permission to delete this attachment', 403);
            return;
        }

        try {
            $this->attachmentService->deleteAttachment($id, $currentUser);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }
}
