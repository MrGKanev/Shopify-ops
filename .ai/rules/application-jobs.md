---
paths:
  - 'app/{Application,Jobs}/**'
---

# Application Jobs

## Record operational issues only through RaiseOperationalIssue
Create, refresh, reopen or auto-resolve OperationalIssue rows only via App\Application\Operations\RaiseOperationalIssue (handle / resolveStale / resolve). Never call operationalIssues()->firstOrNew() directly. Build fingerprints with RaiseOperationalIssue::fingerprint($sourceTool, $reference) so existing rows keep matching. Differences between sources are explicit flags (reopenIgnored, countOncePerDay), not copied logic.
