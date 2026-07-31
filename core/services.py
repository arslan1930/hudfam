from django.db import transaction
from django.utils import timezone

from .models import AuditLog, PitchItem, PublishedPlacement, Site


def log_action(user, action, object_type, object_id=None, detail=""):
    AuditLog.objects.create(
        user=user,
        action=action,
        object_type=object_type,
        object_id=object_id,
        detail=detail,
    )


@transaction.atomic
def sync_site_status_from_pitch_item(item: PitchItem):
    """
    Site inventory stays reusable. Current status mirrors latest pipeline
    activity for convenience, but history lives on PitchItem forever.
    """
    site = item.site
    mapping = {
        PitchItem.Status.SENT: Site.Status.SENT,
        PitchItem.Status.REJECTED: Site.Status.REJECTED,
        PitchItem.Status.PROCESSING: Site.Status.PROCESSING,
        PitchItem.Status.COMPLETED: Site.Status.COMPLETED,
    }
    new_status = mapping.get(item.item_status)
    if new_status and site.status != new_status:
        site.status = new_status
        # Accumulate warning flags from reject reasons
        if item.item_status == PitchItem.Status.REJECTED and item.reject_reason_code:
            flag = item.get_reject_reason_code_display()
            existing = [f.strip() for f in site.warning_flags.split(",") if f.strip()]
            if flag not in existing:
                existing.append(flag)
                site.warning_flags = ", ".join(existing)
        site.save(update_fields=["status", "warning_flags", "updated_at"])


@transaction.atomic
def apply_pitch_item_update(item: PitchItem, user):
    sync_site_status_from_pitch_item(item)

    if item.item_status == PitchItem.Status.COMPLETED:
        PublishedPlacement.objects.update_or_create(
            pitch_item=item,
            defaults={
                "project": item.pitch.project,
                "site": item.site,
                "live_link": item.live_link,
                "notes": item.client_notes,
                "created_by": user,
            },
        )
        # After completion, inventory can be re-offered later: reset to agreed
        # if Admin wants — keep completed for visibility; Admin can re-agree.
    log_action(
        user,
        "pitch_item_update",
        "PitchItem",
        item.pk,
        f"{item.site.domain} → {item.item_status}",
    )


@transaction.atomic
def mark_pitch_sent(pitch, user, site_ids, price_by_site=None):
    price_by_site = price_by_site or {}
    created_items = []
    for site_id in site_ids:
        site = Site.objects.select_for_update().get(pk=site_id)
        item, _ = PitchItem.objects.get_or_create(
            pitch=pitch,
            site=site,
            defaults={
                "offered_price": price_by_site.get(site_id, site.backlink_price),
                "item_status": PitchItem.Status.SENT,
                "updated_by": user,
            },
        )
        site.status = Site.Status.SENT
        site.save(update_fields=["status", "updated_at"])
        created_items.append(item)

    pitch.status = pitch.Status.SENT
    pitch.sent_at = timezone.now()
    pitch.save(update_fields=["status", "sent_at", "updated_at"])
    log_action(
        user,
        "pitch_sent",
        "Pitch",
        pitch.pk,
        f"{len(created_items)} sites to {pitch.project.name}",
    )
    return created_items


def project_history_for_site(site):
    return (
        PitchItem.objects.filter(site=site)
        .select_related("pitch", "pitch__project")
        .order_by("-updated_at")
    )
