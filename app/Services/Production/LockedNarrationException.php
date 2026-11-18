<?php

namespace App\Services\Production;

use RuntimeException;

/** Raised when anything would alter, or has altered, the approved narration package. */
class LockedNarrationException extends RuntimeException {}
