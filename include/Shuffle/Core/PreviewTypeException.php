<?php
declare(strict_types=1);

namespace Shuffle\Core;

/**
 * The requested attachment is not in the previewable type set (§5.23,
 * FILE-06/07). Deliberately extends \Exception (NOT \RuntimeException) so
 * the controller's `catch (\RuntimeException)` (mapping to 404) cannot
 * swallow it — the controller maps this to 415.
 *
 * The client contract is unchanged by this gate: `GET /v1/attachments/{id}
 * /download` still serves the file — only `/preview` refuses it.
 */
class PreviewTypeException extends \Exception
{
    public function __construct(string $mimeType = '')
    {
        parent::__construct($mimeType !== ''
            ? 'MIME type ' . $mimeType . ' is not previewable'
            : 'Attachment type is not previewable');
    }
}
