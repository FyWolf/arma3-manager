<?php

namespace FyWolf\Arma3Manager\Http\Requests;

use App\Http\Requests\Api\Application\ApplicationApiRequest;
use App\Services\Acl\Api\AdminAcl;
use FyWolf\Arma3Manager\Providers\Arma3ManagerProvider;

/**
 * Base request for every endpoint the billing service calls.
 *
 * Gated on this plugin's own `arma3` resource rather than the bridge's
 * `billing` one, for the same reason minecraft-manager registers `minecraft`
 * and vcenter-vps registers `vps`: handing out mod sets is a different decision
 * from provisioning servers, and a key should be able to hold one without the
 * other.
 *
 * Never issue the billing service a root-admin `pacc_` key; those bypass the
 * application ACL entirely.
 */
abstract class ModSetApiRequest extends ApplicationApiRequest
{
    protected ?string $resource = Arma3ManagerProvider::RESOURCE_NAME;

    protected int $permission = AdminAcl::WRITE;
}
