from io import BytesIO

import pandas as pd
from django.db import transaction
from django.http import HttpResponse

from .models import Site

COLUMN_MAP = {
    "domain": "domain",
    "url": "url",
    "region": "region",
    "country": "country",
    "niche": "niche",
    "language": "language",
    "dr": "dr",
    "da": "da",
    "traffic": "traffic",
    "backlink price": "backlink_price",
    "backlink_price": "backlink_price",
    "banner price yearly": "banner_price_yearly",
    "banner_price_yearly": "banner_price_yearly",
    "currency": "currency",
    "status": "status",
    "publisher email": "publisher_email",
    "publisher_email": "publisher_email",
    "notes": "outreach_notes",
    "outreach_notes": "outreach_notes",
    "warning_flags": "warning_flags",
}


def _normalize_columns(df: pd.DataFrame) -> pd.DataFrame:
    rename = {}
    for col in df.columns:
        key = str(col).strip().lower()
        if key in COLUMN_MAP:
            rename[col] = COLUMN_MAP[key]
    return df.rename(columns=rename)


def read_sites_dataframe(uploaded_file) -> pd.DataFrame:
    name = uploaded_file.name.lower()
    if name.endswith(".csv"):
        df = pd.read_csv(uploaded_file)
    else:
        df = pd.read_excel(uploaded_file)
    return _normalize_columns(df)


@transaction.atomic
def import_sites_from_dataframe(
    df: pd.DataFrame,
    *,
    user,
    default_status=Site.Status.DRAFT,
    assigned_to=None,
    primary_project=None,
):
    if "domain" not in df.columns:
        raise ValueError("Spreadsheet must include a Domain column.")

    created = 0
    updated = 0
    skipped = 0
    errors = []

    valid_statuses = {c[0] for c in Site.Status.choices}
    valid_regions = {c[0] for c in Site.Region.choices}

    for idx, row in df.iterrows():
        domain = str(row.get("domain", "")).strip().lower()
        if not domain or domain == "nan":
            skipped += 1
            continue

        defaults = {
            "status": default_status,
            "created_by": user,
        }
        for field in [
            "url",
            "region",
            "country",
            "niche",
            "language",
            "currency",
            "publisher_email",
            "outreach_notes",
            "warning_flags",
        ]:
            if field in df.columns and pd.notna(row.get(field)):
                defaults[field] = str(row.get(field)).strip()

        for field in ["dr", "da", "traffic"]:
            if field in df.columns and pd.notna(row.get(field)):
                try:
                    defaults[field] = int(float(row.get(field)))
                except (TypeError, ValueError):
                    errors.append(f"Row {idx + 2}: invalid {field}")

        for field in ["backlink_price", "banner_price_yearly"]:
            if field in df.columns and pd.notna(row.get(field)):
                try:
                    defaults[field] = float(row.get(field))
                except (TypeError, ValueError):
                    errors.append(f"Row {idx + 2}: invalid {field}")

        if "status" in df.columns and pd.notna(row.get("status")):
            st = str(row.get("status")).strip().lower()
            if st in valid_statuses:
                defaults["status"] = st

        if "region" in defaults:
            reg = defaults["region"].lower().replace(" ", "_")
            if reg in valid_regions:
                defaults["region"] = reg
            else:
                defaults.pop("region", None)

        if assigned_to:
            defaults["assigned_to"] = assigned_to
        if primary_project:
            defaults["primary_project"] = primary_project

        # Team cannot force post-agreed statuses via import
        if defaults.get("status") == Site.Status.AGREED and not defaults.get(
            "backlink_price"
        ):
            defaults["status"] = Site.Status.DRAFT

        obj, was_created = Site.objects.update_or_create(
            domain=domain, defaults=defaults
        )
        if was_created:
            created += 1
        else:
            updated += 1

    return {
        "created": created,
        "updated": updated,
        "skipped": skipped,
        "errors": errors[:50],
    }


def export_sites_queryset(queryset) -> HttpResponse:
    rows = []
    for s in queryset.iterator(chunk_size=2000):
        rows.append(
            {
                "Domain": s.domain,
                "URL": s.url,
                "Region": s.region,
                "Country": s.country,
                "Niche": s.niche,
                "Language": s.language,
                "DR": s.dr,
                "DA": s.da,
                "Traffic": s.traffic,
                "Backlink Price": s.backlink_price,
                "Banner Price Yearly": s.banner_price_yearly,
                "Currency": s.currency,
                "Status": s.status,
                "Assigned To": s.assigned_to.username if s.assigned_to else "",
                "Publisher Email": s.publisher_email,
                "Notes": s.outreach_notes,
                "Warning Flags": s.warning_flags,
            }
        )
    df = pd.DataFrame(rows)
    buffer = BytesIO()
    with pd.ExcelWriter(buffer, engine="openpyxl") as writer:
        df.to_excel(writer, index=False, sheet_name="Sites")
    buffer.seek(0)
    response = HttpResponse(
        buffer.getvalue(),
        content_type=(
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        ),
    )
    response["Content-Disposition"] = 'attachment; filename="hudfam_sites.xlsx"'
    return response
