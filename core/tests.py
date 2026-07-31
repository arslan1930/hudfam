from django.test import TestCase
from django.urls import reverse

from .models import PitchItem, Project, PublishedPlacement, Site, User
from .services import apply_pitch_item_update, mark_pitch_sent


class WorkflowTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_user(
            username="admin", password="x", role=User.Role.ADMIN
        )
        self.team = User.objects.create_user(
            username="team", password="x", role=User.Role.TEAM
        )
        self.project = Project.objects.create(
            name="rexbo.de", niche="Casino", created_by=self.admin
        )
        self.project.assigned_members.add(self.team)
        self.site = Site.objects.create(
            domain="example-site.com",
            status=Site.Status.AGREED,
            backlink_price=100,
            assigned_to=self.team,
            primary_project=self.project,
            created_by=self.team,
        )

    def test_role_routing(self):
        self.client.login(username="admin", password="x")
        self.assertEqual(self.client.get(reverse("home")).url, reverse("admin_dashboard"))
        self.client.logout()
        self.client.login(username="team", password="x")
        self.assertEqual(self.client.get(reverse("home")).url, reverse("team_dashboard"))
        self.assertEqual(self.client.get(reverse("admin_dashboard")).status_code, 403)

    def test_team_project_folder_access(self):
        self.client.login(username="team", password="x")
        r = self.client.get(reverse("team_project_detail", args=[self.project.pk]))
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "rexbo.de")

    def test_pitch_reject_keeps_history_and_allows_reuse(self):
        from .models import Pitch

        pitch = Pitch.objects.create(
            project=self.project, title="P1", created_by=self.admin
        )
        mark_pitch_sent(pitch, self.admin, [self.site.pk])
        item = PitchItem.objects.get(pitch=pitch, site=self.site)
        item.item_status = PitchItem.Status.REJECTED
        item.reject_reason_code = PitchItem.RejectReason.MFA
        item.reject_comment = "MFA"
        item.save()
        apply_pitch_item_update(item, self.admin)
        self.site.refresh_from_db()
        self.assertEqual(self.site.status, Site.Status.REJECTED)
        self.assertIn("MFA", self.site.warning_flags)

        # Reset for re-offer
        self.site.status = Site.Status.AGREED
        self.site.save()
        pitch2 = Pitch.objects.create(
            project=self.project, title="P2", created_by=self.admin
        )
        mark_pitch_sent(pitch2, self.admin, [self.site.pk])
        self.assertEqual(
            PitchItem.objects.filter(site=self.site).count(),
            2,
        )

    def test_completed_creates_published(self):
        from .models import Pitch

        pitch = Pitch.objects.create(
            project=self.project, title="P3", created_by=self.admin
        )
        mark_pitch_sent(pitch, self.admin, [self.site.pk])
        item = PitchItem.objects.get(pitch=pitch, site=self.site)
        item.item_status = PitchItem.Status.COMPLETED
        item.live_link = "https://example-site.com/post"
        item.save()
        apply_pitch_item_update(item, self.admin)
        self.assertTrue(
            PublishedPlacement.objects.filter(
                site=self.site, project=self.project
            ).exists()
        )
