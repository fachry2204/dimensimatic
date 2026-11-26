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
        canManageMappings: element.dataset.canManageMappings === '1',
        canViewReleases: element.dataset.canViewReleases === '1',
        mappingsUrl: element.dataset.mappingsUrl,
        syncMetadataUrl: element.dataset.syncMetadataUrl,
        notificationsUrl: element.dataset.notificationsUrl,
        appVersion: element.dataset.appVersion,
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

// Keep the same Livewire rows and controls; mobile cards only change presentation.
const initializedDisclosures = new WeakSet();
const disclosureQuery = window.matchMedia('(min-width:761px)');
function labelResponsiveTables() {
    document.querySelectorAll('[data-mobile-disclosure]').forEach((details) => {
        if (!initializedDisclosures.has(details)) {
            details.open = disclosureQuery.matches;
            initializedDisclosures.add(details);
        }
    });
    document.querySelectorAll('table[data-card-table]').forEach((table) => {
        const labels = [...table.querySelectorAll('thead th')].map((cell) => cell.textContent.trim());
        table.querySelectorAll('tbody tr').forEach((row) => {
            [...row.children].forEach((cell, index) => { cell.dataset.label = labels[index] || ''; });
        });
    });
}
labelResponsiveTables();
disclosureQuery.addEventListener('change', ({ matches }) => {
    document.querySelectorAll('[data-mobile-disclosure]').forEach((details) => { details.open = matches; });
});
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morphed', labelResponsiveTables);
});
document.addEventListener('click', (event) => {
    const sectionLink = event.target.closest('.section-links a[href^="#"]');
    if (sectionLink) {
        sectionLink.closest('.section-links').querySelectorAll('a').forEach((link) => {
            if (link === sectionLink) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }
    const toggle = event.target.closest('[data-password-toggle]');
    if (!toggle) return;
    const input = document.getElementById(toggle.dataset.passwordToggle);
    if (!input) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(visible));
    toggle.setAttribute('aria-label', visible ? 'Sembunyikan password' : 'Tampilkan password');
});
