<div
    wire:ignore
    data-vue-navigation
    data-system-name="{{ $systemName }}"
    data-system-favicon="{{ $systemFavicon ?: $systemDefaultFavicon }}"
    data-user-name="{{ auth()->user()->name }}"
    data-user-role="{{ auth()->user()->getRoleNames()->first() ?? 'operator' }}"
    data-can-view-dashboard="{{ auth()->user()->can('automation.view') ? '1' : '0' }}"
    data-can-manage-sessions="{{ auth()->user()->can('sessions.manage') ? '1' : '0' }}"
    data-can-manage-users="{{ auth()->user()->can('users.manage') ? '1' : '0' }}"
    data-dashboard-url="{{ route('automation.dashboard') }}"
    data-release-status-url="{{ route('automation.release-status') }}"
    data-reviews-url="{{ route('automation.reviews') }}"
    data-sessions-url="{{ route('automation.sessions') }}"
    data-update-url="{{ route('automation.update') }}"
    data-users-url="{{ route('automation.users') }}"
    data-login-url="{{ route('login') }}"
    data-logout-url="{{ route('logout') }}"
></div>
