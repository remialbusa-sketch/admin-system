<?php

namespace App\Support\Dashboard;

/**
 * Thrown for any malformed, unsafe, or non-evaluable dashboard expression.
 * Widget factories let this bubble; DashboardLayoutEngine catches it and
 * renders a per-widget error card, so one bad formula can never take down
 * the whole dashboard.
 */
final class ExpressionSyntaxError extends \RuntimeException {}
