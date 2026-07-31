from django.contrib import messages
from django.contrib.auth import authenticate, login, logout
from django.contrib.auth.decorators import login_required
from django.core.paginator import Paginator
from django.db.models import Count, Q
from django.http import HttpResponseForbidden
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.views.decorators.http import require_POST

from .decorators import admin_required, team_required
from .excel_utils import (
    export_sites_queryset,
    import_sites_from_dataframe,
    read_sites_dataframe,
)
from .filters import filter_sites
from .forms import (
    ExcelImportForm,
    PitchForm,
    PitchItemUpdateForm,
    ProjectForm,
    SiteFilterForm,
    SiteForm,
    TeamUserForm,
)
from .models import Pitch, PitchItem, Project, PublishedPlacement, Site, User
from .services import apply_pitch_item_update, mark_pitch_sent, project_history_for_site


def login_view(request):
    if request.user.is_authenticated:
        return redirect("home")
    error = None
    if request.method == "POST":
        username = request.POST.get("username", "").strip()
        password = request.POST.get("password", "")
        user = authenticate(request, username=username, password=password)
        if user is not None:
            login(request, user)
            return redirect("home")
        error = "Invalid username or password."
    return render(request, "registration/login.html", {"error": error})


def logout_view(request):
    logout(request)
    return redirect("login")


@login_required
def home(request):
    if request.user.is_hudfam_admin:
        return redirect("admin_dashboard")
    return redirect("team_dashboard")


# --------------- Admin ---------------


@admin_required
def admin_dashboard(request):
    site_counts = (
        Site.objects.values("status").annotate(c=Count("id")).order_by("status")
    )
    counts = {row["status"]: row["c"] for row in site_counts}
    context = {
        "counts": counts,
        "project_count": Project.objects.count(),
        "active_projects": Project.objects.filter(status=Project.Status.ACTIVE).count(),
        "agreed_pool": Site.objects.filter(status=Site.Status.AGREED).count(),
        "processing": Site.objects.filter(status=Site.Status.PROCESSING).count(),
        "recent_rejects": PitchItem.objects.filter(item_status=PitchItem.Status.REJECTED)
        .select_related("site", "pitch__project")[:8],
        "recent_projects": Project.objects.all()[:8],
    }
    return render(request, "admin_panel/dashboard.html", context)


@admin_required
def admin_projects(request):
    projects = Project.objects.annotate(
        site_count=Count("primary_sites"),
        member_count=Count("assigned_members"),
    )
    q = request.GET.get("q", "").strip()
    if q:
        projects = projects.filter(
            Q(name__icontains=q)
            | Q(client_name__icontains=q)
            | Q(niche__icontains=q)
        )
    return render(
        request,
        "admin_panel/projects.html",
        {"projects": projects, "q": q},
    )


@admin_required
def admin_project_create(request):
    form = ProjectForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        project = form.save(commit=False)
        project.created_by = request.user
        project.save()
        form.save_m2m()
        messages.success(request, f"Project {project.name} created.")
        return redirect("admin_project_detail", pk=project.pk)
    return render(
        request, "admin_panel/project_form.html", {"form": form, "title": "New project"}
    )


@admin_required
def admin_project_edit(request, pk):
    project = get_object_or_404(Project, pk=pk)
    form = ProjectForm(request.POST or None, instance=project)
    if request.method == "POST" and form.is_valid():
        form.save()
        messages.success(request, "Project updated.")
        return redirect("admin_project_detail", pk=project.pk)
    return render(
        request,
        "admin_panel/project_form.html",
        {"form": form, "title": f"Edit {project.name}", "project": project},
    )


@admin_required
def admin_project_detail(request, pk):
    project = get_object_or_404(Project, pk=pk)
    tab = request.GET.get("tab", "brief")
    pitches = project.pitches.prefetch_related("items").all()
    items = PitchItem.objects.filter(pitch__project=project).select_related(
        "site", "pitch"
    )
    published = project.published_placements.select_related("site")
    primary_sites = project.primary_sites.select_related("assigned_to")

    context = {
        "project": project,
        "tab": tab,
        "pitches": pitches,
        "items_sent": items.filter(item_status=PitchItem.Status.SENT),
        "items_rejected": items.filter(item_status=PitchItem.Status.REJECTED),
        "items_processing": items.filter(item_status=PitchItem.Status.PROCESSING),
        "items_completed": items.filter(item_status=PitchItem.Status.COMPLETED),
        "published": published,
        "primary_sites": primary_sites[:100],
        "agreed_candidates": Site.objects.filter(status=Site.Status.AGREED)[:200],
    }
    return render(request, "admin_panel/project_detail.html", context)


@admin_required
def admin_sites(request):
    form = SiteFilterForm(request.GET or None)
    qs = Site.objects.select_related("assigned_to", "primary_project")
    if form.is_valid():
        qs = filter_sites(qs, form.cleaned_data)
    if request.GET.get("export") == "1":
        return export_sites_queryset(qs)
    paginator = Paginator(qs, 50)
    page = paginator.get_page(request.GET.get("page"))
    return render(
        request,
        "admin_panel/sites.html",
        {"form": form, "page": page, "total": paginator.count},
    )


@admin_required
def admin_site_create(request):
    form = SiteForm(request.POST or None, user=request.user)
    if request.method == "POST" and form.is_valid():
        site = form.save(commit=False)
        site.created_by = request.user
        site.domain = site.domain.strip().lower()
        site.save()
        messages.success(request, f"Site {site.domain} created.")
        return redirect("admin_site_detail", pk=site.pk)
    return render(
        request, "admin_panel/site_form.html", {"form": form, "title": "Add site"}
    )


@admin_required
def admin_site_detail(request, pk):
    site = get_object_or_404(
        Site.objects.select_related("assigned_to", "primary_project"), pk=pk
    )
    form = SiteForm(request.POST or None, instance=site, user=request.user)
    if request.method == "POST" and form.is_valid():
        site = form.save(commit=False)
        site.domain = site.domain.strip().lower()
        site.save()
        messages.success(request, "Site updated.")
        return redirect("admin_site_detail", pk=site.pk)
    history = project_history_for_site(site)
    return render(
        request,
        "admin_panel/site_detail.html",
        {"form": form, "site": site, "history": history},
    )


@admin_required
def admin_import(request):
    form = ExcelImportForm(request.POST or None, request.FILES or None)
    result = None
    if request.method == "POST" and form.is_valid():
        try:
            df = read_sites_dataframe(form.cleaned_data["file"])
            result = import_sites_from_dataframe(
                df,
                user=request.user,
                default_status=form.cleaned_data["default_status"],
                assigned_to=form.cleaned_data.get("assigned_to"),
                primary_project=form.cleaned_data.get("primary_project"),
            )
            messages.success(
                request,
                f"Import done: {result['created']} created, {result['updated']} updated.",
            )
        except Exception as exc:  # noqa: BLE001
            messages.error(request, f"Import failed: {exc}")
    return render(
        request, "admin_panel/import.html", {"form": form, "result": result}
    )


@admin_required
def admin_users(request):
    users = User.objects.order_by("role", "username")
    return render(request, "admin_panel/users.html", {"users": users})


@admin_required
def admin_user_create(request):
    form = TeamUserForm(request.POST or None, initial={"role": User.Role.TEAM})
    if request.method == "POST" and form.is_valid():
        form.save()
        messages.success(request, "User created.")
        return redirect("admin_users")
    return render(
        request, "admin_panel/user_form.html", {"form": form, "title": "New user"}
    )


@admin_required
def admin_user_edit(request, pk):
    user = get_object_or_404(User, pk=pk)
    form = TeamUserForm(request.POST or None, instance=user)
    if request.method == "POST" and form.is_valid():
        form.save()
        messages.success(request, "User updated.")
        return redirect("admin_users")
    return render(
        request,
        "admin_panel/user_form.html",
        {"form": form, "title": f"Edit {user.username}"},
    )


@admin_required
def admin_pitch_create(request, project_pk):
    project = get_object_or_404(Project, pk=project_pk)
    form = PitchForm(request.POST or None)
    agreed = Site.objects.filter(status=Site.Status.AGREED).order_by("domain")

    # Past history for this project — shown as warnings, not hard blocks
    prior = (
        PitchItem.objects.filter(pitch__project=project)
        .select_related("site")
        .order_by("-updated_at")
    )
    history_by_site = {}
    for row in prior:
        history_by_site.setdefault(row.site_id, []).append(row)

    if request.method == "POST" and form.is_valid():
        site_ids = [int(x) for x in request.POST.getlist("sites") if x.isdigit()]
        if not site_ids:
            messages.error(request, "Select at least one agreed site.")
        else:
            pitch = form.save(commit=False)
            pitch.project = project
            pitch.created_by = request.user
            pitch.save()
            mark_pitch_sent(pitch, request.user, site_ids)
            messages.success(
                request, f"Pitch sent with {len(site_ids)} site(s) for {project.name}."
            )
            return redirect(
                reverse("admin_project_detail", args=[project.pk]) + "?tab=sent"
            )

    agreed_rows = []
    for site in agreed:
        agreed_rows.append(
            {
                "site": site,
                "history": history_by_site.get(site.pk, [])[:3],
            }
        )

    return render(
        request,
        "admin_panel/pitch_create.html",
        {
            "form": form,
            "project": project,
            "agreed_rows": agreed_rows,
        },
    )


@admin_required
def admin_pitch_item_update(request, pk):
    item = get_object_or_404(
        PitchItem.objects.select_related("site", "pitch__project"), pk=pk
    )
    form = PitchItemUpdateForm(request.POST or None, instance=item)
    if request.method == "POST" and form.is_valid():
        item = form.save(commit=False)
        item.updated_by = request.user
        item.save()
        apply_pitch_item_update(item, request.user)
        messages.success(request, f"Updated {item.site.domain}.")
        return redirect(
            reverse("admin_project_detail", args=[item.pitch.project_id])
            + f"?tab={item.item_status}"
        )
    return render(
        request,
        "admin_panel/pitch_item_form.html",
        {"form": form, "item": item},
    )


@admin_required
def admin_published(request):
    qs = PublishedPlacement.objects.select_related("site", "project")
    q = request.GET.get("q", "").strip()
    if q:
        qs = qs.filter(
            Q(site__domain__icontains=q)
            | Q(project__name__icontains=q)
            | Q(live_link__icontains=q)
        )
    paginator = Paginator(qs, 50)
    page = paginator.get_page(request.GET.get("page"))
    return render(
        request, "admin_panel/published.html", {"page": page, "q": q}
    )


@admin_required
@require_POST
def admin_reset_site_to_agreed(request, pk):
    """Allow re-offering a site later while keeping history."""
    site = get_object_or_404(Site, pk=pk)
    if site.backlink_price is None:
        messages.error(request, "Set an agreed price before resetting to Agreed.")
        return redirect("admin_site_detail", pk=pk)
    site.status = Site.Status.AGREED
    site.save(update_fields=["status", "updated_at"])
    messages.success(request, f"{site.domain} is Agreed again (history kept).")
    return redirect("admin_site_detail", pk=pk)


# --------------- Team ---------------


def _team_projects(user):
    if user.is_hudfam_admin:
        return Project.objects.filter(status=Project.Status.ACTIVE)
    return user.assigned_projects.filter(status=Project.Status.ACTIVE)


@team_required
def team_dashboard(request):
    projects = _team_projects(request.user)
    my_sites = Site.objects.filter(assigned_to=request.user)
    results = (
        PitchItem.objects.filter(
            Q(site__assigned_to=request.user)
            | Q(pitch__project__in=projects)
        )
        .exclude(item_status=PitchItem.Status.SENT)
        .select_related("site", "pitch__project")
        .distinct()[:15]
    )
    context = {
        "projects": projects,
        "my_counts": {
            "draft": my_sites.filter(status=Site.Status.DRAFT).count(),
            "negotiating": my_sites.filter(status=Site.Status.NEGOTIATING).count(),
            "agreed": my_sites.filter(status=Site.Status.AGREED).count(),
            "sent": my_sites.filter(status=Site.Status.SENT).count(),
            "rejected": my_sites.filter(status=Site.Status.REJECTED).count(),
            "processing": my_sites.filter(status=Site.Status.PROCESSING).count(),
            "completed": my_sites.filter(status=Site.Status.COMPLETED).count(),
        },
        "results": results,
    }
    return render(request, "team_panel/dashboard.html", context)


@team_required
def team_projects(request):
    projects = _team_projects(request.user).annotate(
        agreed_count=Count(
            "primary_sites",
            filter=Q(primary_sites__status=Site.Status.AGREED),
        )
    )
    return render(request, "team_panel/projects.html", {"projects": projects})


@team_required
def team_project_detail(request, pk):
    project = get_object_or_404(Project, pk=pk)
    if (
        not request.user.is_hudfam_admin
        and not project.assigned_members.filter(pk=request.user.pk).exists()
    ):
        return HttpResponseForbidden("You are not assigned to this project.")

    tab = request.GET.get("tab", "brief")
    my_sites = Site.objects.filter(
        Q(primary_project=project) | Q(assigned_to=request.user, primary_project=project)
    ).distinct()
    # Also show sites assigned to me tagged for this project
    project_sites = Site.objects.filter(primary_project=project).select_related(
        "assigned_to"
    )
    items = PitchItem.objects.filter(pitch__project=project).select_related("site")

    return render(
        request,
        "team_panel/project_detail.html",
        {
            "project": project,
            "tab": tab,
            "project_sites": project_sites,
            "items_rejected": items.filter(item_status=PitchItem.Status.REJECTED),
            "items_processing": items.filter(item_status=PitchItem.Status.PROCESSING),
            "items_completed": items.filter(item_status=PitchItem.Status.COMPLETED),
            "items_sent": items.filter(item_status=PitchItem.Status.SENT),
            "published": project.published_placements.select_related("site"),
        },
    )


@team_required
def team_sites(request):
    form = SiteFilterForm(request.GET or None)
    qs = Site.objects.select_related("assigned_to", "primary_project")
    if not request.user.is_hudfam_admin:
        # Team sees own sites + agreed pool (read source)
        qs = qs.filter(
            Q(assigned_to=request.user) | Q(status=Site.Status.AGREED)
        )
    if form.is_valid():
        qs = filter_sites(qs, form.cleaned_data)
    paginator = Paginator(qs.distinct(), 50)
    page = paginator.get_page(request.GET.get("page"))
    return render(
        request,
        "team_panel/sites.html",
        {"form": form, "page": page, "total": paginator.count},
    )


@team_required
def team_site_create(request):
    initial = {"assigned_to": request.user, "status": Site.Status.DRAFT}
    project_id = request.GET.get("project")
    if project_id:
        initial["primary_project"] = project_id
    form = SiteForm(
        request.POST or None,
        user=request.user,
        team_mode=True,
        initial=initial,
    )
    if request.method == "POST" and form.is_valid():
        site = form.save(commit=False)
        site.created_by = request.user
        site.domain = site.domain.strip().lower()
        if not request.user.is_hudfam_admin:
            site.assigned_to = request.user
            if site.status not in {
                Site.Status.DRAFT,
                Site.Status.NEGOTIATING,
                Site.Status.AGREED,
            }:
                site.status = Site.Status.DRAFT
        site.save()
        messages.success(request, f"Site {site.domain} added.")
        if site.primary_project_id:
            return redirect("team_project_detail", pk=site.primary_project_id)
        return redirect("team_site_detail", pk=site.pk)
    return render(
        request, "team_panel/site_form.html", {"form": form, "title": "Add site"}
    )


@team_required
def team_site_detail(request, pk):
    site = get_object_or_404(Site, pk=pk)
    can_edit = request.user.is_hudfam_admin or (
        site.assigned_to_id == request.user.id and site.can_team_edit()
    )
    form = None
    if can_edit:
        form = SiteForm(
            request.POST or None,
            instance=site,
            user=request.user,
            team_mode=True,
        )
        if request.method == "POST" and form.is_valid():
            site = form.save(commit=False)
            site.domain = site.domain.strip().lower()
            if not request.user.is_hudfam_admin:
                site.assigned_to = request.user
                if site.status not in {
                    Site.Status.DRAFT,
                    Site.Status.NEGOTIATING,
                    Site.Status.AGREED,
                }:
                    site.status = Site.Status.DRAFT
            site.save()
            messages.success(request, "Site updated.")
            return redirect("team_site_detail", pk=site.pk)
    history = project_history_for_site(site)
    return render(
        request,
        "team_panel/site_detail.html",
        {
            "site": site,
            "form": form,
            "can_edit": can_edit,
            "history": history,
        },
    )


@team_required
def team_results(request):
    projects = _team_projects(request.user)
    qs = (
        PitchItem.objects.filter(pitch__project__in=projects)
        .select_related("site", "pitch__project")
        .order_by("-updated_at")
    )
    status = request.GET.get("status")
    if status:
        qs = qs.filter(item_status=status)
    paginator = Paginator(qs, 50)
    page = paginator.get_page(request.GET.get("page"))
    return render(
        request,
        "team_panel/results.html",
        {"page": page, "status": status},
    )
