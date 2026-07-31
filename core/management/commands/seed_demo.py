from copy import deepcopy

from django.core.management.base import BaseCommand
from django.db import transaction

from core.models import Pitch, PitchItem, Project, Site, User
from core.services import apply_pitch_item_update, mark_pitch_sent


class Command(BaseCommand):
    help = "Seed demo admin, team users, projects, and sample sites."

    @transaction.atomic
    def handle(self, *args, **options):
        admin, created = User.objects.get_or_create(
            username="admin",
            defaults={
                "email": "admin@hudfam.local",
                "role": User.Role.ADMIN,
                "is_staff": True,
                "is_superuser": True,
            },
        )
        if created or not admin.has_usable_password():
            admin.set_password("admin123")
            admin.role = User.Role.ADMIN
            admin.is_staff = True
            admin.is_superuser = True
            admin.save()

        team, created = User.objects.get_or_create(
            username="teammate",
            defaults={
                "email": "team@hudfam.local",
                "role": User.Role.TEAM,
                "first_name": "Alex",
            },
        )
        if created:
            team.set_password("team123")
            team.save()

        team2, created = User.objects.get_or_create(
            username="teammate2",
            defaults={
                "email": "team2@hudfam.local",
                "role": User.Role.TEAM,
                "first_name": "Sam",
            },
        )
        if created:
            team2.set_password("team123")
            team2.save()

        rexbo, _ = Project.objects.update_or_create(
            name="rexbo.de",
            defaults={
                "client_name": "Rexbo",
                "niche": "Gambling / Casino",
                "countries": "DE, AT, CH",
                "region_focus": "Europe",
                "budget": "€5,000 / month",
                "price_min": 50,
                "price_max": 150,
                "currency": "EUR",
                "min_dr": 30,
                "min_da": 25,
                "min_traffic": 5000,
                "avoid_notes": "MFA, weak sites, already-used casino placements",
                "workflow_notes": "Prefer guest posts on DE sites.",
                "requirements_brief": (
                    "Build a clean DE/AT/CH casino-friendly link pack. "
                    "Avoid MFA and thin content sites."
                ),
                "created_by": admin,
                "status": Project.Status.ACTIVE,
            },
        )
        rexbo.assigned_members.set([team])

        xyw, _ = Project.objects.update_or_create(
            name="xyw.com",
            defaults={
                "client_name": "XYW",
                "niche": "Finance",
                "countries": "US, UK, CA",
                "region_focus": "North America + English",
                "budget": "$8,000 / month",
                "price_min": 80,
                "price_max": 200,
                "currency": "USD",
                "min_dr": 40,
                "min_da": 35,
                "min_traffic": 20000,
                "avoid_notes": "Adult, spam, MFA",
                "workflow_notes": "Prefer homepage + banner options.",
                "requirements_brief": (
                    "Finance niche, English markets, stronger metrics. "
                    "Banner yearly pricing welcome."
                ),
                "created_by": admin,
                "status": Project.Status.ACTIVE,
            },
        )
        xyw.assigned_members.set([team, team2])

        samples = [
            {
                "domain": "de-finance-news.example",
                "country": "DE",
                "region": Site.Region.EUROPE,
                "niche": "Finance",
                "dr": 42,
                "da": 38,
                "traffic": 22000,
                "backlink_price": 120,
                "status": Site.Status.AGREED,
                "assigned_to": team,
                "primary_project": rexbo,
            },
            {
                "domain": "berlin-biz-daily.example",
                "country": "DE",
                "region": Site.Region.EUROPE,
                "niche": "Business",
                "dr": 35,
                "da": 30,
                "traffic": 9000,
                "backlink_price": 90,
                "status": Site.Status.AGREED,
                "assigned_to": team,
                "primary_project": rexbo,
            },
            {
                "domain": "us-money-wire.example",
                "country": "US",
                "region": Site.Region.NORTH_AMERICA,
                "niche": "Finance",
                "dr": 55,
                "da": 48,
                "traffic": 80000,
                "backlink_price": 180,
                "banner_price_yearly": 900,
                "currency": "USD",
                "status": Site.Status.AGREED,
                "assigned_to": team2,
                "primary_project": xyw,
            },
            {
                "domain": "uk-invest-today.example",
                "country": "UK",
                "region": Site.Region.ENGLISH,
                "niche": "Finance",
                "dr": 48,
                "da": 44,
                "traffic": 41000,
                "backlink_price": 150,
                "currency": "USD",
                "status": Site.Status.NEGOTIATING,
                "assigned_to": team2,
                "primary_project": xyw,
            },
            {
                "domain": "thin-mfa-blog.example",
                "country": "US",
                "region": Site.Region.ENGLISH,
                "niche": "General",
                "dr": 22,
                "da": 18,
                "traffic": 1200,
                "backlink_price": 40,
                "status": Site.Status.AGREED,
                "assigned_to": team,
                "primary_project": rexbo,
            },
        ]

        site_objs = {}
        for raw in samples:
            data = deepcopy(raw)
            domain = data.pop("domain")
            assigned = data.get("assigned_to")
            obj, _ = Site.objects.update_or_create(
                domain=domain,
                defaults={**data, "created_by": assigned or admin},
            )
            site_objs[domain] = obj

        pitch, _ = Pitch.objects.get_or_create(
            project=rexbo,
            title="Rexbo pack #1",
            defaults={"created_by": admin, "status": Pitch.Status.SENT},
        )
        if not pitch.items.exists():
            mark_pitch_sent(
                pitch,
                admin,
                [
                    site_objs["de-finance-news.example"].pk,
                    site_objs["thin-mfa-blog.example"].pk,
                ],
            )
            mfa_item = PitchItem.objects.get(
                pitch=pitch, site=site_objs["thin-mfa-blog.example"]
            )
            mfa_item.item_status = PitchItem.Status.REJECTED
            mfa_item.reject_reason_code = PitchItem.RejectReason.MFA
            mfa_item.reject_comment = "MFA (Made for advertising)"
            mfa_item.updated_by = admin
            mfa_item.save()
            apply_pitch_item_update(mfa_item, admin)

            ok_item = PitchItem.objects.get(
                pitch=pitch, site=site_objs["de-finance-news.example"]
            )
            ok_item.item_status = PitchItem.Status.PROCESSING
            ok_item.client_notes = "Client selected — waiting for live link"
            ok_item.updated_by = admin
            ok_item.save()
            apply_pitch_item_update(ok_item, admin)

        self.stdout.write(self.style.SUCCESS("Demo data ready."))
        self.stdout.write("Admin login: admin / admin123")
        self.stdout.write("Team login:  teammate / team123")
