<?php

namespace App\Support;

/** A business-rule error shown to the user as-is (rendered as HTTP 422 {"error": "..."}). */
class PlanException extends \RuntimeException {}
