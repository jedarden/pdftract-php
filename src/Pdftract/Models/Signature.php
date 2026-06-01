<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a digital signature
 *
 * Represents a signature extracted from a PDF signature field,
 * including signer identity, timestamp, and coverage information.
 */
class Signature
{
    /**
     * The absolute (dot-joined) field name from the AcroForm
     * Example: "employer_signature" or "form.employee_sig"
     */
    public string $field_name;

    /**
     * The signer's name from the /Name entry in the signature dictionary
     *
     * Empty string if /Name is absent.
     */
    public string $signer_name;

    /**
     * The signing date as an ISO 8601 string (RFC 3339 format)
     *
     * Parsed from the PDF /M date string. Null if the date is missing,
     * malformed, or the field is unsigned.
     *
     * Format: "YYYY-MM-DDTHH:MM:SS+HH:MM" or "YYYY-MM-DDTHH:MM:SSZ"
     */
    public ?string $signing_date = null;

    /**
     * The reason for signing from the /Reason entry
     *
     * Null if /Reason is absent.
     */
    public ?string $reason = null;

    /**
     * The location of signing from the /Location entry
     *
     * Null if /Location is absent.
     */
    public ?string $location = null;

    /**
     * The signature format / filter from the /SubFilter entry
     *
     * Indicates the signature format: "adbe.pkcs7.detached", "adbe.x509.rsa.sha1", etc.
     * Null if /SubFilter is absent.
     */
    public ?string $sub_filter = null;

    /**
     * The /ByteRange array defining which bytes of the file are signed
     *
     * Format: array of 4 integers [offset, length, offset, length] defining two byte ranges.
     * Null if /ByteRange is missing or malformed.
     *
     * @var array<int>|null
     */
    public ?array $byte_range = null;

    /**
     * Fraction of the file covered by the signature (0.0 to 1.0)
     *
     * Computed as `(byte_range[1] + byte_range[3]) / file_size`.
     * Null if /ByteRange is missing, malformed, or file_size is unknown.
     *
     * Values < 1.0 indicate partial signatures (a common red flag for tampered docs).
     */
    public ?float $coverage_fraction = null;

    /**
     * Validation status — always "not_checked" in v1
     *
     * Future versions may add "valid", "invalid", "indeterminate" as cryptographic
     * validation is implemented. This is a string enum for schema stability.
     */
    public string $validation_status;

    /**
     * Create Signature from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $signature = new self();
        $signature->field_name = $data['field_name'];
        $signature->signer_name = $data['signer_name'];
        $signature->signing_date = $data['signing_date'] ?? null;
        $signature->reason = $data['reason'] ?? null;
        $signature->location = $data['location'] ?? null;
        $signature->sub_filter = $data['sub_filter'] ?? null;
        $signature->byte_range = $data['byte_range'] ?? null;
        $signature->coverage_fraction = $data['coverage_fraction'] ?? null;
        $signature->validation_status = $data['validation_status'] ?? 'not_checked';

        return $signature;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'field_name' => $this->field_name,
            'signer_name' => $this->signer_name,
            'validation_status' => $this->validation_status,
        ];

        if ($this->signing_date !== null) {
            $data['signing_date'] = $this->signing_date;
        }

        if ($this->reason !== null) {
            $data['reason'] = $this->reason;
        }

        if ($this->location !== null) {
            $data['location'] = $this->location;
        }

        if ($this->sub_filter !== null) {
            $data['sub_filter'] = $this->sub_filter;
        }

        if ($this->byte_range !== null) {
            $data['byte_range'] = $this->byte_range;
        }

        if ($this->coverage_fraction !== null) {
            $data['coverage_fraction'] = $this->coverage_fraction;
        }

        return $data;
    }
}
