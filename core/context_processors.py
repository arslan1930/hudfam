def role_flags(request):
    user = getattr(request, "user", None)
    is_admin = bool(user and user.is_authenticated and user.is_hudfam_admin)
    is_team = bool(
        user and user.is_authenticated and not is_admin and user.role == user.Role.TEAM
    )
    return {
        "is_hudfam_admin": is_admin,
        "is_hudfam_team": is_team,
    }
