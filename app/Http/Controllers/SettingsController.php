<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * SettingsController
 *
 * Admin-only platform settings API. Routes live inside the `role:admin`
 * middleware group, so normal users / organizers / guests can never read or
 * modify platform settings.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/settings/public
     * Return the public-facing settings the customer-facing UI needs (branding,
     * booking constraints, maintenance state). No authentication required and
     * never contains sensitive values.
     */
    public function publicSettings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $this->settings->publicValues(),
            ],
        ]);
    }

    /**
     * GET /api/admin/settings
     * Return all settings (stored values resolved against the catalog) plus
     * the catalog the frontend uses to render each field.
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('manage_settings')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view settings.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $this->settings->all(),
                'catalog' => $this->catalogForClient(),
                'groups' => $this->settings->valuesByGroup(),
            ],
        ]);
    }

    /**
     * PUT/PATCH /api/admin/settings
     * Persist a subset of settings and return the updated resolved values.
     *
     * Payload: { "settings": { "general.platform_name": "EventHub", ... } }
     */
    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('manage_settings')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update settings.',
            ], 403);
        }

        $values = $request->input('settings', []);

        if (! is_array($values) || $values === []) {
            return response()->json([
                'success' => false,
                'message' => 'No settings were provided to update.',
            ], 422);
        }

        $updated = $this->settings->update($values, $request->user()->id);

        $this->activityLog->log(
            'settings.update',
            'Platform settings updated',
            $request->user()->id,
            $request,
            ['keys' => array_keys($values)],
        );

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'data' => [
                'settings' => $updated,
                'groups' => $this->settings->valuesByGroup(),
            ],
        ]);
    }

    /**
     * POST /api/admin/settings/logo
     * Upload the website logo used by the storefront theme (navbar, footer,
     * admin sidebar). Stores the image on the public disk and persists its
     * path as `appearance.logo`.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('manage_settings')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update settings.',
            ], 403);
        }

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        // Remove the previous logo from the public disk before replacing it,
        // so orphaned files never pile up.
        $previous = (string) $this->settings->get('appearance.logo', '');
        if ($previous !== '' && Storage::disk('public')->exists($previous)) {
            Storage::disk('public')->delete($previous);
        }

        $path = $validated['image']->store('logos', 'public');

        $updated = $this->settings->update(
            ['appearance.logo' => $path],
            $request->user()->id,
        );

        $this->activityLog->log(
            'settings.update',
            'Website logo updated',
            $request->user()->id,
            $request,
            ['keys' => ['appearance.logo']],
        );

        return response()->json([
            'success' => true,
            'message' => 'Logo uploaded successfully.',
            'data' => [
                'path' => $path,
                'url' => asset('storage/'.$path),
                'settings' => $updated,
            ],
        ]);
    }

    /**
     * Shape the catalog for the frontend: grouped, with only the metadata the
     * settings UI needs (key, label, desc, type, options, default).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function catalogForClient(): array
    {
        $grouped = [];

        foreach ($this->settings->catalog() as $key => $definition) {
            $group = $definition['group'] ?? 'general';

            $grouped[$group][] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'desc' => $definition['desc'] ?? null,
                'type' => $definition['type'] ?? 'string',
                'options' => $definition['options'] ?? null,
                'default' => $definition['default'] instanceof \Closure
                    ? $definition['default']()
                    : ($definition['default'] ?? null),
            ];
        }

        return $grouped;
    }
}