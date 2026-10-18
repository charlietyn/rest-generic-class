<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Contracts\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Decides which fields of a model a user may not write.
 *
 * Consumed through BaseModel::getDeniedFieldsForUser() by FilterRequestByRole.
 */
interface FieldWriteAuthorizer
{
    /**
     * @param mixed $user The authenticated user (any object, or null).
     * @return list<string> Field names $user cannot write on $model.
     */
    public function deniedFields(Model $model, mixed $user): array;
}
