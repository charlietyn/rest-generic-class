<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

/**
 * Names the abilities that grant an eager load.
 *
 * Default: `<resource>.<relation path up to the hop>.<suffix>` for each suffix of
 * authorization.relation_abilities (read, manage). Rebind this class in the container
 * to use another convention.
 */
class RelationAbilityNamer
{
    /**
     * @param list<string> $hops Relation path segments up to the checked hop.
     * @return list<string> Alternatives: holding any of them grants the hop.
     */
    public function abilities(string $resource, array $hops): array
    {
        $prefix = $resource . '.' . implode('.', $hops);
        return array_map(static fn (string $suffix) => "{$prefix}.{$suffix}", AuthorizationConfig::relationAbilities());
    }
}
