from django import forms
from django.contrib.auth import get_user_model

from .models import Pitch, PitchItem, Project, Site

User = get_user_model()


class LoginForm(forms.Form):
    username = forms.CharField()
    password = forms.CharField(widget=forms.PasswordInput)


class ProjectForm(forms.ModelForm):
    class Meta:
        model = Project
        fields = [
            "name",
            "client_name",
            "contact_email",
            "status",
            "niche",
            "countries",
            "region_focus",
            "budget",
            "price_min",
            "price_max",
            "currency",
            "min_dr",
            "min_da",
            "min_traffic",
            "avoid_notes",
            "workflow_notes",
            "requirements_brief",
            "assigned_members",
        ]
        widgets = {
            "assigned_members": forms.CheckboxSelectMultiple,
            "avoid_notes": forms.Textarea(attrs={"rows": 3}),
            "workflow_notes": forms.Textarea(attrs={"rows": 3}),
            "requirements_brief": forms.Textarea(attrs={"rows": 4}),
        }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["assigned_members"].queryset = User.objects.filter(
            role=User.Role.TEAM, is_active=True
        )


class SiteForm(forms.ModelForm):
    class Meta:
        model = Site
        fields = [
            "domain",
            "url",
            "region",
            "country",
            "niche",
            "language",
            "dr",
            "da",
            "traffic",
            "backlink_price",
            "banner_price_yearly",
            "currency",
            "status",
            "publisher_email",
            "outreach_notes",
            "warning_flags",
            "assigned_to",
            "primary_project",
        ]
        widgets = {
            "outreach_notes": forms.Textarea(attrs={"rows": 3}),
        }

    def __init__(self, *args, user=None, team_mode=False, **kwargs):
        super().__init__(*args, **kwargs)
        self.user = user
        self.team_mode = team_mode
        self.fields["assigned_to"].queryset = User.objects.filter(
            role=User.Role.TEAM, is_active=True
        )
        self.fields["primary_project"].queryset = Project.objects.filter(
            status=Project.Status.ACTIVE
        )
        if team_mode:
            # Team can only set early statuses
            self.fields["status"].choices = [
                c
                for c in Site.Status.choices
                if c[0]
                in {
                    Site.Status.DRAFT,
                    Site.Status.NEGOTIATING,
                    Site.Status.AGREED,
                }
            ]
            if user and not user.is_hudfam_admin:
                self.fields["assigned_to"].queryset = User.objects.filter(pk=user.pk)
                self.fields["primary_project"].queryset = user.assigned_projects.filter(
                    status=Project.Status.ACTIVE
                )
                self.fields["assigned_to"].initial = user

    def clean(self):
        cleaned = super().clean()
        status = cleaned.get("status")
        price = cleaned.get("backlink_price")
        if status == Site.Status.AGREED and price is None:
            self.add_error(
                "backlink_price", "Agreed price is required before status Agreed."
            )
        return cleaned


class SiteFilterForm(forms.Form):
    q = forms.CharField(required=False, label="Search")
    region = forms.ChoiceField(
        required=False, choices=[("", "All regions")] + list(Site.Region.choices)
    )
    country = forms.CharField(required=False)
    niche = forms.CharField(required=False)
    status = forms.ChoiceField(
        required=False, choices=[("", "All statuses")] + list(Site.Status.choices)
    )
    min_dr = forms.IntegerField(required=False, min_value=0)
    max_dr = forms.IntegerField(required=False, min_value=0)
    min_da = forms.IntegerField(required=False, min_value=0)
    max_da = forms.IntegerField(required=False, min_value=0)
    min_traffic = forms.IntegerField(required=False, min_value=0)
    max_traffic = forms.IntegerField(required=False, min_value=0)
    min_price = forms.DecimalField(required=False, min_value=0)
    max_price = forms.DecimalField(required=False, min_value=0)
    assigned_to = forms.ModelChoiceField(
        required=False, queryset=User.objects.none(), empty_label="All members"
    )

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["assigned_to"].queryset = User.objects.filter(
            role=User.Role.TEAM, is_active=True
        )


class PitchForm(forms.ModelForm):
    class Meta:
        model = Pitch
        fields = ["title", "notes"]
        widgets = {"notes": forms.Textarea(attrs={"rows": 3})}


class PitchItemUpdateForm(forms.ModelForm):
    class Meta:
        model = PitchItem
        fields = [
            "item_status",
            "reject_reason_code",
            "reject_comment",
            "client_notes",
            "live_link",
            "offered_price",
        ]
        widgets = {
            "reject_comment": forms.Textarea(attrs={"rows": 2}),
            "client_notes": forms.Textarea(attrs={"rows": 2}),
        }

    def clean(self):
        cleaned = super().clean()
        status = cleaned.get("item_status")
        if status == PitchItem.Status.REJECTED and not cleaned.get("reject_reason_code"):
            self.add_error("reject_reason_code", "Pick a rejection reason.")
        if status == PitchItem.Status.COMPLETED and not cleaned.get("live_link"):
            self.add_error("live_link", "Live link is required when completing.")
        return cleaned


class ExcelImportForm(forms.Form):
    file = forms.FileField(help_text="Upload .xlsx or .csv")
    default_status = forms.ChoiceField(
        choices=[
            (Site.Status.DRAFT, "Draft"),
            (Site.Status.AGREED, "Agreed"),
        ],
        initial=Site.Status.DRAFT,
    )
    assigned_to = forms.ModelChoiceField(
        required=False, queryset=User.objects.none(), empty_label="Keep / none"
    )
    primary_project = forms.ModelChoiceField(
        required=False, queryset=Project.objects.none(), empty_label="None"
    )

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["assigned_to"].queryset = User.objects.filter(
            role=User.Role.TEAM, is_active=True
        )
        self.fields["primary_project"].queryset = Project.objects.filter(
            status=Project.Status.ACTIVE
        )


class TeamUserForm(forms.ModelForm):
    password = forms.CharField(widget=forms.PasswordInput, required=False)

    class Meta:
        model = User
        fields = ["username", "first_name", "last_name", "email", "is_active", "role"]

    def save(self, commit=True):
        user = super().save(commit=False)
        password = self.cleaned_data.get("password")
        if password:
            user.set_password(password)
        elif not user.pk:
            user.set_password(User.objects.make_random_password())
        if commit:
            user.save()
        return user
