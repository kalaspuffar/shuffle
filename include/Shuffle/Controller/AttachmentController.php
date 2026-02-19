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
            $this->attachmentService->deleteAttachment($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }
}
