from functools import wraps

from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied


def admin_required(view_func):
    @login_required
    @wraps(view_func)
    def _wrapped(request, *args, **kwargs):
        if not request.user.is_hudfam_admin:
            raise PermissionDenied("Admin access required.")
        return view_func(request, *args, **kwargs)

    return _wrapped


def team_required(view_func):
    @login_required
    @wraps(view_func)
    def _wrapped(request, *args, **kwargs):
        if not (request.user.is_hudfam_admin or request.user.role == request.user.Role.TEAM):
            raise PermissionDenied("Team access required.")
        return view_func(request, *args, **kwargs)

    return _wrapped
