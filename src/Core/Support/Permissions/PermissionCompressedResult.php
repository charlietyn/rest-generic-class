<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

final class PermissionCompressedResult
{
    public array $compressed;

    public array $individual;

    public array $stats;

    public ?array $expanded;

    public function __construct(
        array $compressed,
        array $individual,
        array $stats,
        ?array $expanded = null
    ) {
        $this->compressed = $compressed;
        $this->individual = $individual;
        $this->stats = $stats;
        $this->expanded = $expanded;
    }

    public function all(): array
    {
        return array_values(array_merge($this->compressed, $this->individual));
    }

    public function toArray(): array
    {
        $result = [
            'permissions' => $this->all(),
            'stats' => $this->stats,
        ];

        if ($this->expanded !== null) {
            $result['expanded'] = $this->expanded;
        }

        return $result;
    }
}
