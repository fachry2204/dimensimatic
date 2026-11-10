<script setup>
import { computed, ref, onUnmounted } from 'vue';

const props = defineProps({
    currentPath: String,
    systemName: String,
    systemFavicon: String,
    userName: String,
    userRole: String,
    canViewDashboard: Boolean,
    canManageSessions: Boolean,
    canManageUsers: Boolean,
    dashboardUrl: String,
    releaseStatusUrl: String,
    reviewsUrl: String,
    sessionsUrl: String,
    updateUrl: String,
    usersUrl: String,
    loginUrl: String,
    logoutUrl: String,
    csrfToken: String,
});

const brandName = ref(props.systemName);
const brandFavicon = ref(props.systemFavicon);
const updateBranding = (event) => {
    brandName.value = event.detail.name;
    brandFavicon.value = event.detail.favicon;
};
window.addEventListener('system-branding-updated', updateBranding);
onUnmounted(() => window.removeEventListener('system-branding-updated', updateBranding));
const open = ref(false);
const items = computed(() => [
    { label: 'Dashboard', href: props.dashboardUrl, icon: 'grid', show: props.canViewDashboard },
    { label: 'Cek Status Rilis', href: props.releaseStatusUrl, icon: 'status', show: true },
    { label: 'Publish Rilis', href: props.reviewsUrl, icon: 'status', show: true },
    { label: 'Kelola User', href: props.usersUrl, icon: 'users', show: props.canManageUsers },
    { label: 'Pengaturan Platform', href: props.sessionsUrl, icon: 'settings', show: props.canManageSessions },
    { label: 'Update Aplikasi', href: props.updateUrl, icon: 'update', show: props.canManageSessions },
].filter((item) => item.show));

const pathOf = (href) => new URL(href, window.location.origin).pathname.replace(/\/$/, '') || '/';
const isActive = (href) => {
    const itemPath = pathOf(href);
    const currentPath = (props.currentPath || '/').replace(/\/$/, '') || '/';

    return itemPath === pathOf(props.dashboardUrl)
        ? currentPath === itemPath
        : currentPath === itemPath || currentPath.startsWith(`${itemPath}/`);
};

const logout = async () => {
    await fetch(props.logoutUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': props.csrfToken, 'Accept': 'application/json' },
        credentials: 'same-origin',
    });
    window.location.href = props.loginUrl;
};
</script>

<template>
    <header class="app-header">
        <div class="app-header__inner">
            <a :href="dashboardUrl" class="brand" :aria-label="`${brandName} Dashboard`">
                <img v-if="brandFavicon" :src="brandFavicon" alt="" class="h-10 w-10 rounded-lg object-contain"><span v-else class="brand__mark"><span></span><span></span><span></span></span>
                <span><strong :title="brandName" class="max-w-48 truncate">{{ brandName }}</strong><small>Release Automation</small></span>
            </a>

            <button class="mobile-toggle" type="button" aria-label="Buka navigasi" @click="open = !open">
                <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
            </button>

            <nav :class="['primary-nav', { 'primary-nav--open': open }]">
                <a v-for="item in items" :key="item.href" :href="item.href" :class="['nav-item', { 'nav-item--active': isActive(item.href) }]">
                    <svg v-if="item.icon === 'grid'" viewBox="0 0 24 24"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>
                    <svg v-else-if="item.icon === 'status'" viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5M17 15l1.5 1.5L21 14"/></svg>
                    <svg v-else-if="item.icon === 'users'" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                    <svg v-else-if="item.icon === 'update'" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 20h14"/></svg>
                    <svg v-else viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.6v.2h-4V21a1.7 1.7 0 00-1-1.6 1.7 1.7 0 00-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 00.3-1.9A1.7 1.7 0 003 14H2.8v-4H3a1.7 1.7 0 001.6-1 1.7 1.7 0 00-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 009 4.6 1.7 1.7 0 0010 3v-.2h4V3a1.7 1.7 0 001 1.6 1.7 1.7 0 001.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 00-.3 1.9 1.7 1.7 0 001.6 1h.2v4H21a1.7 1.7 0 00-1.6 1z"/></svg>
                    <span>{{ item.label }}</span>
                    <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
                </a>
            </nav>

            <div class="user-menu">
                <span class="user-avatar">{{ userName?.charAt(0).toUpperCase() }}</span>
                <span class="user-copy"><strong>{{ userName }}</strong><small>{{ userRole }}</small></span>
                <button type="button" class="logout-button" title="Keluar" @click="logout">
                    <svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M15 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4"/></svg>
                </button>
            </div>
        </div>
    </header>
</template>
