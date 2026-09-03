<?php

namespace FyWolf\Arma3Manager\Http\Requests;

use App\Services\Acl\Api\AdminAcl;

/**
 * Reading the catalogue, or what one server has been granted.
 *
 * Narrowed to READ rather than inheriting WRITE. A billing service that only
 * ever displays the catalogue on a checkout page should be able to hold a key
 * that cannot grant anything — and the narrowing has to be here, because the
 * base class is what the write endpoints rely on.
 */
class ReadModSetRequest extends ModSetApiRequest
{
    protected int $permission = AdminAcl::READ;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
