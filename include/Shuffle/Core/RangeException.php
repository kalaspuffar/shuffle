<?php
declare(strict_types=1);

namespace Shuffle\Core;

/**
 * A byte range against a stored object is unsatisfiable (§5.23, FILE-06/07):
 * either the client sent a malformed / rejected `Range` header, or the stored
 * object is shorter than the requested range (S3 answered 416).
 *
 * Deliberately extends \Exception (NOT \RuntimeException) so the
 * controller's `catch (\RuntimeException)` (mapping to 404) cannot swallow
 * it — the controller maps this to 416 with an `Accept-Ranges` header
 * plus the unsatisfiable `Content-Range` form.
 */
class RangeException extends \Exception
{
    public function __construct(string $message = 'Range not satisfiable')
    {
        parent::__construct($message);
    }
}
