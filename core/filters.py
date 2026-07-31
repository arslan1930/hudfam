from django.db.models import Q

from .models import Site


def filter_sites(queryset, cleaned):
    q = (cleaned.get("q") or "").strip()
    if q:
        queryset = queryset.filter(
            Q(domain__icontains=q)
            | Q(url__icontains=q)
            | Q(niche__icontains=q)
            | Q(country__icontains=q)
            | Q(outreach_notes__icontains=q)
            | Q(warning_flags__icontains=q)
        )
    for key in ("region", "country", "niche", "status"):
        val = cleaned.get(key)
        if val:
            if key in ("country", "niche"):
                queryset = queryset.filter(**{f"{key}__icontains": val})
            else:
                queryset = queryset.filter(**{key: val})

    if cleaned.get("min_dr") is not None:
        queryset = queryset.filter(dr__gte=cleaned["min_dr"])
    if cleaned.get("max_dr") is not None:
        queryset = queryset.filter(dr__lte=cleaned["max_dr"])
    if cleaned.get("min_da") is not None:
        queryset = queryset.filter(da__gte=cleaned["min_da"])
    if cleaned.get("max_da") is not None:
        queryset = queryset.filter(da__lte=cleaned["max_da"])
    if cleaned.get("min_traffic") is not None:
        queryset = queryset.filter(traffic__gte=cleaned["min_traffic"])
    if cleaned.get("max_traffic") is not None:
        queryset = queryset.filter(traffic__lte=cleaned["max_traffic"])
    if cleaned.get("min_price") is not None:
        queryset = queryset.filter(backlink_price__gte=cleaned["min_price"])
    if cleaned.get("max_price") is not None:
        queryset = queryset.filter(backlink_price__lte=cleaned["max_price"])
    if cleaned.get("assigned_to"):
        queryset = queryset.filter(assigned_to=cleaned["assigned_to"])
    return queryset


def apply_project_requirements(queryset, project):
    """Soft guidance filters from project brief."""
    if project.niche:
        # Don't hard-filter niche (team may still explore); prefer match later in UI
        pass
    if project.min_dr is not None:
        queryset = queryset.filter(Q(dr__gte=project.min_dr) | Q(dr__isnull=True))
    if project.min_da is not None:
        queryset = queryset.filter(Q(da__gte=project.min_da) | Q(da__isnull=True))
    if project.min_traffic is not None:
        queryset = queryset.filter(
            Q(traffic__gte=project.min_traffic) | Q(traffic__isnull=True)
        )
    if project.price_max is not None:
        queryset = queryset.filter(
            Q(backlink_price__lte=project.price_max) | Q(backlink_price__isnull=True)
        )
    return queryset
