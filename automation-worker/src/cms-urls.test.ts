import { afterEach, expect, test, vi } from 'vitest';
import { baseUrl, loginUrl } from './cms-urls.js';

afterEach(() => vi.unstubAllEnvs());

test('saved CMS URL overrides legacy worker domain and login defaults', () => {
    vi.stubEnv('SOUNDFRESH_BASE_URL', 'https://cms.soundfresh.id');
    vi.stubEnv('CMS_LOGIN_URL', 'https://default.example/login');
    const saved = 'https://cms.example:8443/sign-in?lang=id';
    expect(loginUrl('soundfresh', saved)).toBe(saved);
    expect(baseUrl('soundfresh', saved)).toBe('https://cms.example:8443');
});

test('CMS defaults to Dimensi Suara', () => {
    vi.stubEnv('CMS_LOGIN_URL', '');
    expect(loginUrl('soundfresh')).toBe('https://dimensisuara.id/login');
    expect(baseUrl('soundfresh')).toBe('https://dimensisuara.id');
});

test('CMS settings leave SoundOn URL configuration intact', () => {
    vi.stubEnv('SOUNDON_BASE_URL', 'https://www.soundon.global');
    vi.stubEnv('SOUNDON_LOGIN_URL', 'https://www.soundon.global/custom-login');
    expect(loginUrl('soundon', 'https://cms.example/login')).toBe('https://www.soundon.global/custom-login');
    expect(baseUrl('soundon', 'https://cms.example/login')).toBe('https://www.soundon.global');
});
