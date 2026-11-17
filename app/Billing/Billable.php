<?php

namespace App\Billing;

use App\Billing\Concerns\ManagesCustomer;
use App\Billing\Concerns\ManagesSubscriptions;

trait Billable
{
    use ManagesCustomer;
    use ManagesSubscriptions;
}
