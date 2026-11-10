import './bootstrap';
import { createApp } from 'vue';
import AppNavigation from './components/AppNavigation.vue';

document.querySelectorAll('[data-vue-navigation]').forEach((element) => {
    createApp(AppNavigation, {
        currentPath: window.location.pathname,
        systemName: element.dataset.systemName,
        systemFavicon: element.dataset.systemFavicon,
        userName: element.dataset.userName,
        userRole: element.dataset.userRole,
        canViewDashboard: element.dataset.canViewDashboard === '1',
        canManageSessions: element.dataset.canManageSessions === '1',
        canManageUsers: element.dataset.canManageUsers === '1',
        dashboardUrl: element.dataset.dashboardUrl,
        releaseStatusUrl: element.dataset.releaseStatusUrl,
        reviewsUrl: element.dataset.reviewsUrl,
        sessionsUrl: element.dataset.sessionsUrl,
        updateUrl: element.dataset.updateUrl,
        usersUrl: element.dataset.usersUrl,
        loginUrl: element.dataset.loginUrl,
        logoutUrl: element.dataset.logoutUrl,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,
    }).mount(element);
});

function notifyDesktopBranding(name, favicon) {
    if (window.chrome?.webview) {
        window.chrome.webview.postMessage({ type: 'dimensimatic-branding-updated', name, favicon });
    }
}
window.addEventListener('system-branding-updated', ({ detail }) => {
    document.title = `${detail.name} Automation`;
    document.querySelector('meta[name="application-name"]')?.setAttribute('content', detail.name);
    document.querySelector('#system-favicon')?.setAttribute('href', detail.favicon);
    notifyDesktopBranding(detail.name, detail.favicon);
});
notifyDesktopBranding(document.querySelector('meta[name="application-name"]')?.content,
    document.querySelector('#system-favicon')?.getAttribute('href'));
