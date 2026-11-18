<?php

namespace App\Billing;

use RuntimeException;

/** Thrown when usage x rates is not a whole number of nanodollars. We never round a provider cost. */
class InexactCostException extends RuntimeException {}
