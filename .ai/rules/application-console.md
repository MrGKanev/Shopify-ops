---
paths:
  - 'app/{Application,Console}/**'
---

# Application Console

## Send report/audit notifications through ReportNotifier
Whether and where a finished report or audit is announced (Slack, Discord, immediate email, digest recipients/thresholds) is decided only in App\Application\Notifications\ReportNotifier. Do not re-implement rule checks (enabled, min rows, include_zero, webhook URL configured) in actions, jobs or commands; call scanFinished / auditFinished / emailImmediately, or emailRuleMatches / emailRecipient.
