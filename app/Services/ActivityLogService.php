<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogService
{
    /**
     * Record a structured security / business event.
     */
    public function log(
        string $event,
        ?string $description = null,
        ?int $userId = null,
        ?Request $request = null,
        array $metadata = [],
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $userId ?? auth()->id(),
            'event' => $event,
            'description' => $description,
            'ip_address' => $request?->ip(),
            'device_name' => $this->deviceName($request),
            'metadata' => $metadata ?: null,
        ]);
    }

    /**
     * Best-effort device detection from the User-Agent header.
     */
    protected function deviceName(?Request $request): ?string
    {
        $ua = $request?->userAgent();

        return $ua ? substr($ua, 0, 150) : null;
    }
}
