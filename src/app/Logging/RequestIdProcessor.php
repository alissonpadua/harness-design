<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Logging tap (spec 006): every record written during a request gains the
 * sanitized X-Request-Id (set by RequestId middleware) in extra context.
 * Registered via the `tap` key on real logging channels.
 */
final readonly class RequestIdProcessor
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            $id = app()->bound('request') ? app('request')->attributes->get('request_id') : null;

            if (is_string($id) && $id !== '') {
                $record->extra['request_id'] = $id;
            }

            return $record;
        });
    }
}
