<script setup>
import { computed, ref, onMounted, onUnmounted, nextTick } from 'vue';
const props = defineProps({
 currentPath:String, systemName:String, systemFavicon:String, userName:String, userRole:String,
 canViewDashboard:Boolean, canManageSessions:Boolean, canManageUsers:Boolean, canManageMappings:Boolean, canViewReleases:Boolean,
 dashboardUrl:String, releaseStatusUrl:String, reviewsUrl:String, sessionsUrl:String, updateUrl:String, usersUrl:String,
 mappingsUrl:String, syncMetadataUrl:String, notificationsUrl:String, appVersion:String,
 loginUrl:String, logoutUrl:String, csrfToken:String,
});
const brandName=ref(props.systemName), brandFavicon=ref(props.systemFavicon), open=ref(false), search=ref(''), loggingOut=ref(false), logoutError=ref('');
const toggle=ref(null), sidebar=ref(null);
const mobileQuery=window.matchMedia('(max-width:760px)'), isMobile=ref(mobileQuery.matches);
const updateViewport=event=>{isMobile.value=event.matches;if(!event.matches)open.value=false;};
const updateBranding=({detail})=>{brandName.value=detail.name;brandFavicon.value=detail.favicon;};
const closeNavigation=async()=>{open.value=false;await nextTick();toggle.value?.focus();};
const handleKey=async(event)=>{
 if(event.key==='Escape'){search.value='';if(open.value)await closeNavigation();}
 if(event.key==='Tab'&&open.value&&sidebar.value){
  const links=[...sidebar.value.querySelectorAll('a,button')];const first=links[0],last=links.at(-1);
  if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
  else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
 }
};
const toggleNavigation=async()=>{open.value=!open.value;if(open.value){await nextTick();sidebar.value?.querySelector('a')?.focus();}};
onMounted(()=>{window.addEventListener('system-branding-updated',updateBranding);window.addEventListener('keydown',handleKey);mobileQuery.addEventListener('change',updateViewport);});
onUnmounted(()=>{window.removeEventListener('system-branding-updated',updateBranding);window.removeEventListener('keydown',handleKey);mobileQuery.removeEventListener('change',updateViewport);});
const groups=computed(()=>[
 {label:'Operasi',items:[
  {label:'Dashboard',href:props.dashboardUrl,icon:'grid',show:props.canViewDashboard},
  {label:'Cek Status Rilis',href:props.releaseStatusUrl,icon:'status',show:props.canViewReleases},
  {label:'Publish Rilis',href:props.reviewsUrl,icon:'send',show:props.canViewReleases},
 ]},
 {label:'Metadata',items:[
  {label:'Sync Metadata',href:props.syncMetadataUrl,icon:'sync',show:props.canManageMappings},
  {label:'Metadata Mapping',href:props.mappingsUrl,icon:'layers',show:props.canManageMappings},
 ]},
 {label:'Sistem',items:[
  {label:'Pengaturan Platform',href:props.sessionsUrl,icon:'settings',show:props.canManageSessions},
  {label:'Kelola User',href:props.usersUrl,icon:'users',show:props.canManageUsers},
  {label:'Notifikasi',href:props.notificationsUrl,icon:'bell',show:props.canViewDashboard},
  {label:'Update Aplikasi',href:props.updateUrl,icon:'update',show:props.canManageSessions},
 ]},
].map(g=>({...g,items:g.items.filter(i=>i.show&&i.href)})).filter(g=>g.items.length));
const pathOf=href=>new URL(href,window.location.origin).pathname.replace(/\/$/,'')||'/';
const isActive=href=>pathOf(href)===(props.currentPath?.replace(/\/$/,'')||'/');
const current=computed(()=>groups.value.flatMap(g=>g.items.map(i=>({...i,group:g.label}))).find(i=>isActive(i.href))||{label:'Detail Proses Rilisan',group:'Operasi'});
const results=computed(()=>search.value.trim()?groups.value.flatMap(g=>g.items).filter(i=>i.label.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase())):[]);
const paths={grid:'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',status:'M4 4h16v16H4zM8 9h8M8 13h5M8 17h3',send:'m21 3-7 18-4-7-7-4 18-7ZM10 14l5-5',sync:'M20 7a8 8 0 0 0-14-3L3 7m0-5v5h5M4 17a8 8 0 0 0 14 3l3-3m0 5v-5h-5',layers:'m12 3 10 5-10 5L2 8l10-5ZM2 12l10 5 10-5M2 16l10 5 10-5',users:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM17 4a4 4 0 0 1 0 7M22 21v-2a4 4 0 0 0-3-4',bell:'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4',update:'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4',settings:'m10 2 4 0 1 3 3 1 3 2-1 4 1 4-3 2-3 1-1 3h-4l-1-3-3-1-3-2 1-4-1-4 3-2 3-1 1-3ZM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z'};
const logout=async()=>{
 loggingOut.value=true;logoutError.value='';
 try{const response=await fetch(props.logoutUrl,{method:'POST',headers:{'X-CSRF-TOKEN':props.csrfToken,'Accept':'application/json'},credentials:'same-origin'});
  if(!response.ok)throw new Error('Keluar gagal. Silakan coba kembali.');window.location.href=props.loginUrl;
 }catch(error){logoutError.value=error.message;loggingOut.value=false;}
};
</script>
<template>
 <button v-if="open" class="sidebar-scrim" aria-label="Tutup navigasi" @click="closeNavigation"></button>
 <aside id="workspace-navigation" ref="sidebar" :class="['workspace-sidebar',{'is-open':open}]" :inert="isMobile&&!open" :aria-hidden="isMobile&&!open?true:undefined" :role="isMobile?'dialog':undefined" :aria-modal="isMobile&&open?true:undefined" aria-label="Navigasi utama">
  <a :href="canViewDashboard?dashboardUrl:releaseStatusUrl" class="sidebar-brand" :aria-label="brandName+' beranda'">
   <img v-if="brandFavicon" :src="brandFavicon" alt="" width="34" height="34"><span v-else class="sidebar-letter">D</span>
   <strong :title="brandName">{{brandName}}</strong>
  </a>
  <nav class="sidebar-groups">
   <section v-for="group in groups" :key="group.label" class="sidebar-group">
    <h2>{{group.label}}</h2>
    <a v-for="item in group.items" :key="item.href" :href="item.href" :aria-current="isActive(item.href)?'page':undefined" :class="['sidebar-item',{'is-active':isActive(item.href)}]">
     <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="paths[item.icon]"/></svg><span>{{item.label}}</span>
    </a>
   </section>
  </nav>
  <footer class="sidebar-footer"><span><i></i>Workspace operator</span><small>v{{appVersion}}</small></footer>
 </aside>
 <header class="workspace-topbar">
  <button ref="toggle" class="workspace-menu-toggle" type="button" :aria-label="open?'Tutup navigasi':'Buka navigasi'" :aria-expanded="open" aria-controls="workspace-navigation" @click="toggleNavigation"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
  <a class="mobile-brand" :href="canViewDashboard?dashboardUrl:releaseStatusUrl"><img :src="brandFavicon" alt="" width="28" height="28">{{brandName}}</a>
  <div class="workspace-breadcrumb"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10h-6v-6H9v6H3V10Z"/></svg><span>{{current.group}}</span><b>/</b><strong>{{current.label}}</strong></div>
  <div class="workspace-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/></svg><input v-model="search" aria-label="Cari halaman" placeholder="Cari halaman di DimensiMatic…" :aria-expanded="!!search.trim()" aria-controls="page-search-results" autocomplete="off">
   <div v-if="search.trim()" id="page-search-results" class="workspace-search-results"><a v-for="item in results" :key="item.href" :href="item.href">{{item.label}}<span>↗</span></a><p v-if="!results.length">Halaman tidak ditemukan.</p></div>
  </div>
  <a v-if="canViewDashboard" :href="notificationsUrl" class="workspace-icon-button" aria-label="Buka notifikasi"><svg viewBox="0 0 24 24"><path :d="paths.bell"/></svg></a>
  <div class="workspace-user"><span class="workspace-avatar">{{userName?.charAt(0).toUpperCase()}}</span><span><strong>{{userName}}</strong><small>{{userRole}}</small></span></div>
  <button class="workspace-icon-button" type="button" aria-label="Keluar" title="Keluar" :disabled="loggingOut" @click="logout"><svg viewBox="0 0 24 24"><path d="M9 4H4v16h5M14 8l4 4-4 4M8 12h13"/></svg></button>
  <p v-if="logoutError" class="workspace-logout-error" role="alert">{{logoutError}}</p>
 </header>
</template>
