<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Nwidart\Modules\Facades\Module;
use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;

trait HasPermissionsController
{
    use HasPermissionsService;
    private ?string $generalModule = "--site--";

    /**
     * Refreshes the permissions for the specified guard.
     *
     * This method processes all application routes, determines the associated
     * permissions, and updates the database accordingly. It supports a dry-run
     * mode to preview changes without persisting them.
     *
     * @param Request $request
     * @return array A unique collection of permissions with their associated details.
     */
    public function modules(Request $request): array
    {
        $enabled = $request->get('enabled', null);
        return array_merge($this->service->getModules($enabled),[$this->generalModule]);
    }

    /**
     * Retrieve a collection of modules based on their enabled/disabled status.
     *
     * @param bool|null $type Determines the type of modules to retrieve:
     *                        - `true`: Only enabled modules.
     *                        - `false`: Only disabled modules.
     *                        - `null`: All modules (default).
     * @return array An array of modules filtered by the specified type.
     */
    public static function getModules(bool $type = null): array
    {
        $result = Module::toCollection();
        if ($type === true) {
            $result = $result->filter->isEnabled();
        }
        if ($type === false) {
            $result = $result->reject->isEnabled();
        }
        return $result->map->getName()->values()->all();
    }

    /**
     * POST /api/permissions/assign_roles
     * Body JSON:
     * {
     *   "roles": ["admin","editor"] | "admin,editor",
     *   "guard": "api",
     *   "mode": "ADD" | "SYNC" | "REVOKE",
     *   "dry_run": false,
     *   "no_create": false,
     *   "perms": ["users.read","users.create"],
     *   "prefix": "users.",
     *   "from": "permissions.json",
     *   "modules": ["{{security_module}}"],
     *   "entities": ["users","mod_sales.orders"]
     * }
     */
    public function assign_roles(BaseFormRequest $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->json()->all();

        $roles = is_array($validated['roles'])
            ? $validated['roles']
            : Str::of((string)$validated['roles'])->explode(',')->map(fn($r)=>trim($r))->filter()->values()->all();

        $options = [
            'guard'     => $validated['guard']     ?? 'api',
            'mode'      => $validated['mode']      ?? null,
            'sync'      => (bool)($validated['sync']      ?? false),
            'revoke'    => (bool)($validated['revoke']    ?? false),
            'dry_run'   => (bool)($validated['dry_run']   ?? false),
            'no_create' => (bool)($validated['no_create'] ?? false),
            'perms'     => $validated['perms']     ?? null,
            'prefix'    => $validated['prefix']    ?? null,
            'from'      => $validated['from']      ?? null,
            'modules'   => $validated['modules']   ?? null,
            'entities'  => $validated['entities']  ?? null,
            'by'  => $validated['by']  ?? 'name' // 'id'|'name' (how to identify perms in "perms" array),
        ];

        $result = $this->assignPermissionToRoles($roles, $options);

        return response()->json([
            'ok'       => true,
            'summary'  => $result['summary'],
            'per_role' => $result['per_role'],
        ], 200);
    }

    /**
     * POST /api/permissions/assign_users
     *
     * Body JSON example:
     * {
     *   "users": [10, 12, 15],       // or "10,12,15" | ["alice@x.com","bob@x.com"] depending on "by"
     *   "by": "id",                  // id|email|name
     *   "guard": "api",
     *   "mode": "ADD",               // ADD|SYNC|REVOKE  (or sync/revoke flags)
     *   "dry_run": false,
     *   "no_create": false,
     *   "perms": ["users.read","users.create"],
     *   "prefix": "users.",
     *   "from": "permissions.json",
     *   "modules": ["{{security_module}}"],
     *   "entities": ["users","mod_sales.orders"],
     *   "pivot": {"range":"global","team_id":42}
     * }
     */
    public function assign_users(BaseFormRequest $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->json()->all();

        $userInputs = is_array($validated['users'])
            ? $validated['users']
            : Str::of((string)$validated['users'])->explode(',')->map(fn($v)=>trim($v))->filter()->values()->all();

        $options = [
            'by'        => strtolower((string)($validated['by'] ?? 'id')),
            'guard'     => $validated['guard']     ?? 'api',
            'mode'      => $validated['mode']      ?? null,
            'sync'      => (bool)($validated['sync']      ?? false),
            'revoke'    => (bool)($validated['revoke']    ?? false),
            'dry_run'   => (bool)($validated['dry_run']   ?? false),
            'no_create' => (bool)($validated['no_create'] ?? false),
            'perms'     => $validated['perms']     ?? null,
            'prefix'    => $validated['prefix']    ?? null,
            'from'      => $validated['from']      ?? null,
            'modules'   => $validated['modules']   ?? null,
            'entities'  => $validated['entities']  ?? null,
            'pivot'     => $validated['pivot']     ?? [],
        ];

        $result = $this->assignPermissionToUsers($userInputs, $options);

        return response()->json([
            'ok'       => true,
            'summary'  => $result['summary'],
            'per_user' => $result['per_user'],
        ], 200);
    }

}