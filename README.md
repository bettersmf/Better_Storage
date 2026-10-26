# Better Storage

Better Storage is a centralized storage backend for **Simple Machines Forum 2.1.x** and the BetterSMF MOD ecosystem.

It provides one shared storage layer with support for the local server and Amazon S3, while allowing each supported integration to choose its own backend independently.

## Stable release

Current stable release: **0.1.0**

Stable 0.1.0 was promoted from the live-tested `0.1.0-dev10` line.

## Highlights

- Local server and Amazon S3 storage backends.
- One central S3 connection with per-integration routing.
- Independent routes for Better Gallery, Better Downloads, forum attachments, PM attachments, and Better Chat.
- Private S3 objects with short-lived signed download URLs.
- Native SMF forum attachment processing, permissions, thumbnails, deletion, and download-count behavior preserved.
- Central object registry with primary vs. derived object reporting.
- Storage Overview dashboard with file/object and byte totals.
- BetterSMF admin icon integration.
- Changing a route affects new files only; existing files are not moved automatically.

## Per-integration routing

Better Storage keeps a single S3 credential/configuration set while allowing supported systems to route new files independently:

- Better Gallery
- Better Downloads
- Forum attachments
- PM attachments
- Better Chat

For example, Gallery and Downloads can use S3 while forum attachments and Chat remain on the local server.

Changing a route affects **new files only**. Existing files stay on their current backend until explicit migration tooling is used.

## Forum attachments

When forum attachments are routed to Amazon S3, Better Storage intentionally leaves SMF in charge of the normal attachment lifecycle.

SMF still performs validation, image inspection, thumbnail generation, moderation checks, permission checks, and normal attachment bookkeeping. Better Storage moves the original and generated thumbnail to S3 only after SMF has successfully processed the attachment.

If an S3 upload fails, the file remains local and the failure is logged rather than risking data loss.

S3-backed downloads use short-lived signed URLs only after SMF's normal permission checks have passed.

## Storage Overview

The Storage Overview separates:

- **Primary files** — user-owned/original files.
- **Derived objects** — generated thumbnails and previews.
- **Physical objects** — the actual objects stored on the configured backend.
- **Bytes used** — storage consumption by integration/backend.

This keeps user-facing file counts understandable while still showing the real physical storage footprint.

## Compatibility

- SMF 2.1.7–2.1.8

## Upgrade

Direct upgrade paths are included from Better Storage `0.1.0-dev1` through `0.1.0-dev10`.

## Uninstall behavior

Better Storage follows the BetterSMF preserve-data convention:

- Normal uninstall removes runtime hooks and package files while preserving Better Storage settings and data.
- Selecting SMF Package Manager's **Remove data** option performs destructive cleanup, including the per-integration routing settings.

## Source and releases

The `main` branch mirrors the audited stable source. Installable ZIP packages are published through GitHub Releases rather than stored as distribution binaries in the source tree.

**Keep SMF familiar. Make storage better.**
