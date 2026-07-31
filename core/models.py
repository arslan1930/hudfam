from django.conf import settings
from django.contrib.auth.models import AbstractUser
from django.db import models


class User(AbstractUser):
    class Role(models.TextChoices):
        ADMIN = "admin", "Admin"
        TEAM = "team", "Team"

    role = models.CharField(
        max_length=20, choices=Role.choices, default=Role.TEAM, db_index=True
    )

    @property
    def is_hudfam_admin(self):
        return self.role == self.Role.ADMIN or self.is_superuser


class Project(models.Model):
    class Status(models.TextChoices):
        ACTIVE = "active", "Active"
        PAUSED = "paused", "Paused"
        ARCHIVED = "archived", "Archived"

    name = models.CharField(max_length=255, unique=True, db_index=True)
    client_name = models.CharField(max_length=255, blank=True)
    contact_email = models.EmailField(blank=True)
    status = models.CharField(
        max_length=20, choices=Status.choices, default=Status.ACTIVE, db_index=True
    )

    # Per-project requirements
    niche = models.CharField(max_length=255, blank=True, db_index=True)
    countries = models.CharField(
        max_length=500, blank=True, help_text="Comma-separated country codes/names"
    )
    region_focus = models.CharField(max_length=255, blank=True)
    budget = models.CharField(max_length=100, blank=True)
    price_min = models.DecimalField(
        max_digits=12, decimal_places=2, null=True, blank=True
    )
    price_max = models.DecimalField(
        max_digits=12, decimal_places=2, null=True, blank=True
    )
    currency = models.CharField(max_length=10, default="EUR")
    min_dr = models.PositiveIntegerField(null=True, blank=True)
    min_da = models.PositiveIntegerField(null=True, blank=True)
    min_traffic = models.PositiveIntegerField(null=True, blank=True)
    avoid_notes = models.TextField(
        blank=True, help_text="e.g. MFA, casino links, adult"
    )
    workflow_notes = models.TextField(blank=True)
    requirements_brief = models.TextField(blank=True)

    assigned_members = models.ManyToManyField(
        settings.AUTH_USER_MODEL,
        blank=True,
        related_name="assigned_projects",
        limit_choices_to={"role": User.Role.TEAM},
    )
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name="created_projects",
    )
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ["name"]

    def __str__(self):
        return self.name


class Site(models.Model):
    class Region(models.TextChoices):
        EUROPE = "europe", "Europe"
        NORTH_AMERICA = "north_america", "North America"
        ENGLISH = "english", "English"
        OTHER = "other", "Other"

    class Status(models.TextChoices):
        DRAFT = "draft", "Draft"
        NEGOTIATING = "negotiating", "Negotiating"
        AGREED = "agreed", "Agreed"
        SENT = "sent", "Sent"
        REJECTED = "rejected", "Rejected"
        PROCESSING = "processing", "Processing"
        COMPLETED = "completed", "Completed"
        BLOCKED = "blocked", "Blocked"

    domain = models.CharField(max_length=255, unique=True, db_index=True)
    url = models.URLField(blank=True)
    region = models.CharField(
        max_length=32, choices=Region.choices, blank=True, db_index=True
    )
    country = models.CharField(max_length=100, blank=True, db_index=True)
    niche = models.CharField(max_length=255, blank=True, db_index=True)
    language = models.CharField(max_length=50, blank=True)
    dr = models.PositiveIntegerField(null=True, blank=True, db_index=True)
    da = models.PositiveIntegerField(null=True, blank=True, db_index=True)
    traffic = models.PositiveIntegerField(null=True, blank=True, db_index=True)
    backlink_price = models.DecimalField(
        max_digits=12, decimal_places=2, null=True, blank=True, db_index=True
    )
    banner_price_yearly = models.DecimalField(
        max_digits=12, decimal_places=2, null=True, blank=True
    )
    currency = models.CharField(max_length=10, default="EUR")
    status = models.CharField(
        max_length=20, choices=Status.choices, default=Status.DRAFT, db_index=True
    )
    publisher_email = models.EmailField(blank=True)
    outreach_notes = models.TextField(blank=True)
    warning_flags = models.CharField(
        max_length=500,
        blank=True,
        help_text="Global caution tags e.g. MFA, weak, casino",
    )

    assigned_to = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name="assigned_sites",
    )
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name="created_sites",
    )
    # Optional: site submitted primarily for a project
    primary_project = models.ForeignKey(
        Project,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name="primary_sites",
    )
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ["-updated_at"]
        indexes = [
            models.Index(fields=["status", "country"]),
            models.Index(fields=["status", "niche"]),
            models.Index(fields=["assigned_to", "status"]),
        ]

    def __str__(self):
        return self.domain

    def can_team_edit(self):
        return self.status in {
            self.Status.DRAFT,
            self.Status.NEGOTIATING,
            self.Status.AGREED,
        }


class Pitch(models.Model):
    class Status(models.TextChoices):
        DRAFT = "draft", "Draft"
        SENT = "sent", "Sent"
        CLOSED = "closed", "Closed"

    project = models.ForeignKey(
        Project, on_delete=models.CASCADE, related_name="pitches"
    )
    title = models.CharField(max_length=255)
    status = models.CharField(
        max_length=20, choices=Status.choices, default=Status.DRAFT, db_index=True
    )
    notes = models.TextField(blank=True)
    sent_at = models.DateTimeField(null=True, blank=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name="created_pitches",
    )
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ["-created_at"]

    def __str__(self):
        return f"{self.project.name} — {self.title}"


class PitchItem(models.Model):
    class Status(models.TextChoices):
        SENT = "sent", "Sent"
        REJECTED = "rejected", "Rejected"
        PROCESSING = "processing", "Processing"
        COMPLETED = "completed", "Completed"

    class RejectReason(models.TextChoices):
        ALREADY_USED = "already_used", "Already used"
        CASINO_LINKS = "casino_links", "Casino links"
        WEAK_SITE = "weak_site", "Weak site"
        MFA = "mfa", "MFA (Made for advertising)"
        BAD_NICHE = "bad_niche", "Bad niche fit"
        PRICE_HIGH = "price_high", "Price too high"
        LOW_METRICS = "low_metrics", "Low traffic / metrics"
        OTHER = "other", "Other"

    pitch = models.ForeignKey(Pitch, on_delete=models.CASCADE, related_name="items")
    site = models.ForeignKey(Site, on_delete=models.CASCADE, related_name="pitch_items")
    offered_price = models.DecimalField(
        max_digits=12, decimal_places=2, null=True, blank=True
    )
    item_status = models.CharField(
        max_length=20, choices=Status.choices, default=Status.SENT, db_index=True
    )
    reject_reason_code = models.CharField(
        max_length=40, choices=RejectReason.choices, blank=True
    )
    reject_comment = models.TextField(blank=True)
    client_notes = models.TextField(blank=True)
    live_link = models.URLField(blank=True)
    updated_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name="updated_pitch_items",
    )
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        unique_together = ("pitch", "site")
        ordering = ["-updated_at"]
        indexes = [
            models.Index(fields=["item_status", "reject_reason_code"]),
            models.Index(fields=["site", "item_status"]),
        ]

    def __str__(self):
        return f"{self.site.domain} @ {self.pitch}"


class PublishedPlacement(models.Model):
    """Permanent record that a link was published for a project."""

    project = models.ForeignKey(
        Project, on_delete=models.CASCADE, related_name="published_placements"
    )
    site = models.ForeignKey(
        Site, on_delete=models.CASCADE, related_name="published_placements"
    )
    pitch_item = models.OneToOneField(
        PitchItem,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name="published_placement",
    )
    live_link = models.URLField(blank=True)
    notes = models.TextField(blank=True)
    published_at = models.DateTimeField(auto_now_add=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
    )

    class Meta:
        ordering = ["-published_at"]
        indexes = [
            models.Index(fields=["project", "site"]),
        ]

    def __str__(self):
        return f"{self.site.domain} → {self.project.name}"


class AuditLog(models.Model):
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
    )
    action = models.CharField(max_length=100)
    object_type = models.CharField(max_length=50)
    object_id = models.PositiveIntegerField(null=True, blank=True)
    detail = models.TextField(blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["-created_at"]

    def __str__(self):
        return f"{self.action} {self.object_type}"