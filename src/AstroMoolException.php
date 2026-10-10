<?php
declare(strict_types=1);

namespace AstroMool;

/**
 * $errorCode: stable error code, see https://developers.astromool.com/errors
 * (SDK-side codes: network_error, timeout, report_failed, webhook_signature_invalid). $status (= getCode()) 0 = no answer.
 */
final class AstroMoolException extends \RuntimeException
{
    /** @var int */ public $status;
    /** @var string */ public $errorCode;
    /** @var string|null */ public $title;
    /** @var string|null */ public $detail;
    /** @var string|null */ public $requestId;
    /** @var string|null */ public $field;
    /** @var array|null */ public $errors;
    /** @var int|null */ public $retryAfter;
    /** @var array|null */ public $body;

    public function __construct(int $status, ?array $body = null, ?string $requestId = null)
    {
        $b = $body ?? [];
        $this->status = $status;
        $this->errorCode = $b['code'] ?? ($status ? 'http_' . $status : 'network_error');
        $this->title = $b['title'] ?? null;
        $this->detail = $b['detail'] ?? null;
        $this->requestId = $b['request_id'] ?? $requestId;
        $this->field = $b['field'] ?? null;
        $this->errors = $b['errors'] ?? null;
        $this->retryAfter = isset($b['retry_after']) ? (int)$b['retry_after'] : null;
        $this->body = $body;
        parent::__construct(sprintf('%s (%d): %s', $this->errorCode, $status, $this->detail ?? $this->title ?? ''), $status);
    }
}
