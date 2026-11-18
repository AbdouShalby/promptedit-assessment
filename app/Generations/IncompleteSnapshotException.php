<?php

namespace App\Generations;

use RuntimeException;

/** A "succeeded" snapshot without usage or rates: we cannot price it, so the caller must retry later. */
class IncompleteSnapshotException extends RuntimeException {}
