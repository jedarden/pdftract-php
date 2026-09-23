<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when the pdftract server rejects the request or its document
 *
 * The serve API's request-validation statuses — 400 and 413 for a
 * request-shape rejection (BAD_REQUEST, MISSING_FIELD, REQUEST_TOO_LARGE)
 * and 422 for a document it cannot process (ENCRYPTED, WRONG_PASSWORD,
 * CORRUPT_PDF, EXTRACTION_ERROR, DECOMPRESSION_LIMIT). The server's error
 * code travels on {@see PdftractException::getErrorCode()} and its hint, when
 * it sent one, on {@see PdftractException::getHint()} — an encrypted
 * document arrives with the hint to pass a password. Retrying the same
 * request repeats the failure; the request or the document has to change.
 */
class ValidationException extends PdftractException
{
}
