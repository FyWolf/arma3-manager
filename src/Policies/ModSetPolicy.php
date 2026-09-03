<?php

namespace FyWolf\Arma3Manager\Policies;

use App\Policies\DefaultAdminPolicies;

class ModSetPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'a3_mod_set';
}
