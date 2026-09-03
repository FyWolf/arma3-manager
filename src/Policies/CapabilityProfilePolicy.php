<?php

namespace FyWolf\Arma3Manager\Policies;

use App\Policies\DefaultAdminPolicies;

class CapabilityProfilePolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'a3_capability_profile';
}
