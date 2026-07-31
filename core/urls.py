from django.urls import path

from . import views

urlpatterns = [
    path("", views.home, name="home"),
    path("login/", views.login_view, name="login"),
    path("logout/", views.logout_view, name="logout"),
    # Admin panel
    path("admin-panel/", views.admin_dashboard, name="admin_dashboard"),
    path("admin-panel/projects/", views.admin_projects, name="admin_projects"),
    path(
        "admin-panel/projects/new/",
        views.admin_project_create,
        name="admin_project_create",
    ),
    path(
        "admin-panel/projects/<int:pk>/",
        views.admin_project_detail,
        name="admin_project_detail",
    ),
    path(
        "admin-panel/projects/<int:pk>/edit/",
        views.admin_project_edit,
        name="admin_project_edit",
    ),
    path(
        "admin-panel/projects/<int:project_pk>/pitch/new/",
        views.admin_pitch_create,
        name="admin_pitch_create",
    ),
    path(
        "admin-panel/pitch-items/<int:pk>/",
        views.admin_pitch_item_update,
        name="admin_pitch_item_update",
    ),
    path("admin-panel/sites/", views.admin_sites, name="admin_sites"),
    path("admin-panel/sites/new/", views.admin_site_create, name="admin_site_create"),
    path(
        "admin-panel/sites/<int:pk>/",
        views.admin_site_detail,
        name="admin_site_detail",
    ),
    path(
        "admin-panel/sites/<int:pk>/reset-agreed/",
        views.admin_reset_site_to_agreed,
        name="admin_reset_site_to_agreed",
    ),
    path("admin-panel/import/", views.admin_import, name="admin_import"),
    path("admin-panel/published/", views.admin_published, name="admin_published"),
    path("admin-panel/users/", views.admin_users, name="admin_users"),
    path("admin-panel/users/new/", views.admin_user_create, name="admin_user_create"),
    path(
        "admin-panel/users/<int:pk>/",
        views.admin_user_edit,
        name="admin_user_edit",
    ),
    # Team panel
    path("team/", views.team_dashboard, name="team_dashboard"),
    path("team/projects/", views.team_projects, name="team_projects"),
    path(
        "team/projects/<int:pk>/",
        views.team_project_detail,
        name="team_project_detail",
    ),
    path("team/sites/", views.team_sites, name="team_sites"),
    path("team/sites/new/", views.team_site_create, name="team_site_create"),
    path("team/sites/<int:pk>/", views.team_site_detail, name="team_site_detail"),
    path("team/results/", views.team_results, name="team_results"),
]
