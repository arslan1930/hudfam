from django.contrib import admin
from django.contrib.auth.admin import UserAdmin as DjangoUserAdmin

from .models import (
    AuditLog,
    Pitch,
    PitchItem,
    Project,
    PublishedPlacement,
    Site,
    User,
)


@admin.register(User)
class UserAdmin(DjangoUserAdmin):
    list_display = ("username", "email", "role", "is_active", "is_staff")
    list_filter = ("role", "is_active", "is_staff")
    fieldsets = DjangoUserAdmin.fieldsets + (
        ("Hudfam", {"fields": ("role",)}),
    )
    add_fieldsets = DjangoUserAdmin.add_fieldsets + (
        ("Hudfam", {"fields": ("role",)}),
    )


@admin.register(Project)
class ProjectAdmin(admin.ModelAdmin):
    list_display = ("name", "client_name", "niche", "status", "budget")
    list_filter = ("status", "niche")
    search_fields = ("name", "client_name", "niche")
    filter_horizontal = ("assigned_members",)


@admin.register(Site)
class SiteAdmin(admin.ModelAdmin):
    list_display = (
        "domain",
        "country",
        "niche",
        "dr",
        "da",
        "backlink_price",
        "status",
        "assigned_to",
    )
    list_filter = ("status", "region", "country", "niche")
    search_fields = ("domain", "url", "niche", "publisher_email")


class PitchItemInline(admin.TabularInline):
    model = PitchItem
    extra = 0


@admin.register(Pitch)
class PitchAdmin(admin.ModelAdmin):
    list_display = ("title", "project", "status", "sent_at")
    list_filter = ("status",)
    inlines = [PitchItemInline]


@admin.register(PitchItem)
class PitchItemAdmin(admin.ModelAdmin):
    list_display = (
        "site",
        "pitch",
        "item_status",
        "reject_reason_code",
        "offered_price",
    )
    list_filter = ("item_status", "reject_reason_code")
    search_fields = ("site__domain", "pitch__title")


@admin.register(PublishedPlacement)
class PublishedPlacementAdmin(admin.ModelAdmin):
    list_display = ("site", "project", "live_link", "published_at")
    search_fields = ("site__domain", "project__name", "live_link")


@admin.register(AuditLog)
class AuditLogAdmin(admin.ModelAdmin):
    list_display = ("action", "object_type", "object_id", "user", "created_at")
    list_filter = ("action", "object_type")
