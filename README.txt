Better Storage 0.1.0 — Stable Release
======================================

Better Storage is the centralized storage backend for SMF 2.1.x and the Better SMF MOD ecosystem.

Stable 0.1.0 is promoted from the live-tested 0.1.0-dev10 line.

Highlights
----------
- Local server and Amazon S3 storage backends.
- One central S3 connection with per-integration routing.
- Independent routes for Better Gallery, Better Downloads, forum attachments, PM attachments, and Better Chat.
- Private S3 objects with short-lived signed download URLs.
- Native SMF forum attachment processing, permissions, thumbnails, deletion, and download-count behavior preserved.
- Central object registry with primary vs derived object reporting.
- Storage Overview dashboard with file/object and byte totals.
- Better SMF admin icon integration.
- Changing a route affects new files only; existing files are not moved automatically.
- Normal uninstall removes runtime hooks/files while preserving Better Storage data and settings.
- SMF Package Manager Remove data performs destructive cleanup, including per-integration routing settings.

Compatibility
-------------
SMF 2.1.7 - 2.1.8

Upgrade
-------
Direct upgrade paths are included from Better Storage 0.1.0-dev1 through 0.1.0-dev10.

Better Storage 0.1.0-dev10 — Admin Icon Integration

- Adds a matching Better Storage icon to SMF's Configuration area.
- Adds the matching compact icon to the admin dropdown/menu.
- Uses native SMF admin icon registration; no theme CSS hacks are required.
- No storage routing, S3, registry, migration, or attachment behavior changed.

Better Storage 0.1.0-dev9 — Storage Overview Polish

- Refines Storage Overview summary cards into a cleaner visual metric layout.
- Compacts per-integration Primary / Derived / Objects reporting.
- Reduces repetitive explanatory text without changing registry or storage behavior.
- Reporting/UI only; no file migration or backend changes.

Better Storage 0.1.0-dev8
=========================

Better Storage is a centralized storage backend for SMF 2.1.x and the Better SMF MOD ecosystem.

Current capabilities
--------------------
- Local server and Amazon S3 storage backends
- One central AWS/S3 connection for all supported integrations
- Per-integration routing for Better Gallery, Better Downloads, forum attachments, PM attachments, and Better Chat
- AWS Signature Version 4 connection testing
- Persistent S3 credentials with secret-key preservation
- Private S3 objects and short-lived signed URLs
- Shared source prefixes and a central object registry
- Storage Overview dashboard with primary-file, derived-object, physical-object, and byte totals
- Better Gallery integration
- Better Downloads integration
- Native SMF forum attachment integration (new in dev7)

Forum attachments in dev7
-------------------------
When Forum attachments is routed to Amazon S3 in Better Storage:
- Existing attachments remain where they are.
- New attachments are first processed normally by SMF so validation, image inspection, thumbnails, moderation, and permissions remain native.
- When the attachment is assigned to a post, Better Storage uploads the original and any generated thumbnail to S3.
- The local copies are removed only after successful S3 upload.
- SMF still performs its normal board and attachment permission checks before an S3-backed attachment can be served.
- S3-backed attachment downloads use a short-lived signed URL.
- Deleting an attachment removes its S3 object and registry entry.
- If an S3 upload fails, the attachment remains local and the failure is logged instead of losing the file.

If Forum attachments is routed to Local server, SMF attachment storage behaves normally.

Changing a route affects new files only. Existing files are not moved automatically yet; migration/recalculation tools are planned for a later pass.

SMF compatibility: 2.1.7 - 2.1.8

0.1.0-dev7
- Adds native SMF forum attachment storage routing.
- Adds attachment storage metadata columns without replacing SMF's attachment table.
- Uploads newly assigned forum attachments and generated thumbnails to S3 when that route is selected.
- Preserves normal SMF permission checks before issuing signed S3 URLs.
- Removes S3 objects when SMF deletes attachments.
- Adds reference IDs to the Better Storage registry for native subsystem cleanup.
- Existing forum attachments remain local until migration tools are added.
- Newly assigned local forum attachments are also registered so the Storage Overview can count local vs S3 usage.

0.1.0-dev6
- Adds per-integration storage routing.
- Better Gallery, Better Downloads, forum attachments, PM attachments, and Better Chat can each choose Local server or Amazon S3 independently.
- Keeps one central S3 credential set while preserving separate source prefixes.
- Changing a route affects new files only.


0.1.0-dev8
- Distinguishes primary user files from derived thumbnails/previews in the object registry and dashboard.
- Existing obvious thumbnail/preview rows are classified during upgrade without moving any files.
- Total physical object counts remain visible so S3 billing/storage reality is still clear.
- Forum attachment thumbnails are registered explicitly as derived objects.
