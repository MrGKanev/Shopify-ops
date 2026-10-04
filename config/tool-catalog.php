<?php

use App\Application\Reports\ReportRegistry;

// Every report tool that records a run_log and can be configured in the notification rules, keyed
// by the exact `tool` string stored in run_logs / report_runs. Lets Email Rules render every tool
// before a store has any run_logs yet. Derived from the tool registry in config/reports.php; add
// tools there, not here.

return ReportRegistry::fromConfigFile()->catalog();
