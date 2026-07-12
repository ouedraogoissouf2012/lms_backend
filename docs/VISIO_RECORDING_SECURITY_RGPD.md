# Visio Recording Security And RGPD Contract

Issue: #413

## Authorization

- Start/stop recording is reserved to the teacher who owns the seance.
- Recording status/read access is limited to authorized staff, the owning
  teacher, students in the seance class, or participants already linked through
  attendance.
- Ready recording URLs must stay protected or signed by the storage/provider.
  They must not be public predictable URLs.
- Download is disabled by contract: `can_download=false`.

## Front Consent Contract

`GET /api/lms/seances/{seanceId}/recording` always returns enough data for the
front to display a consent banner:

- `is_recording`: true only while capture is running.
- `consent_required`: true while the recording is active or processing.
- `consent_message`: text to show before/during participation.
- `retention_days`: default retention window currently exposed as 365 days.
- `can_download`: false unless a future explicit policy changes it.

The front should display the banner when `consent_required=true`, and may show
the same message before joining a seance that exposes recording controls.

## Audit

The backend writes audit entries for sensitive recording actions:

- `visio_recording_start`
- `visio_recording_stop`
- `visio_recording_read`

Audit logs include actor, institution, IP/user-agent when available, and target
`SeanceRecording`. Audit write failures are fail-safe and must not break the
business action.

## Retention And Deletion

- Recording metadata is persisted in `seance_recordings`.
- Heavy video objects must live outside local public disk, preferably on an
  S3-compatible protected disk or an external provider.
- Default retention communicated to the front is 365 days.
- Deletion must remove or revoke the provider/storage object first, then mark or
  remove metadata through a dedicated retention/deletion workflow.
- Audit logs follow the existing `audit:purge` retention policy and are not
  deleted by recording cleanup.

## cPanel Constraint

The implementation must remain compatible with shared cPanel hosting: no Jibri,
no supervisor-only permanent worker, and no local public storage dependency for
large video files.
