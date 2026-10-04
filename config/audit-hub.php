<?php

use App\Application\Reports\ReportRegistry;

// The audit navigation, grouped by section. Derived from the tool registry in config/reports.php;
// add or move tools there, not here.

return ReportRegistry::fromConfigFile()->navigation();
