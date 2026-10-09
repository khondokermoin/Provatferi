<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * The PDF engine could not guarantee a correctly shaped document. It is thrown INSTEAD of producing a document with broken
 * Bengali (see PdfRenderer): an official receipt or application copy with split vowel signs and unformed conjuncts is worse
 * than an error the operator can see in the log and retry.
 */
final class PdfEngineException extends RuntimeException
{
}
