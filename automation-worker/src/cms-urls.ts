const cmsLoginUrl = (configured?: string) => configured?.trim()
    || process.env.CMS_LOGIN_URL?.trim() || "https://dimensisuara.id/login";
export const baseUrl = (platform: string, configuredCmsLoginUrl?: string) =>
    platform === "soundfresh"
        ? new URL(cmsLoginUrl(configuredCmsLoginUrl)).origin
        : (process.env.SOUNDON_BASE_URL?.trim() || "https://www.soundon.global");
export const loginUrl = (platform: string, configuredCmsLoginUrl?: string) =>
    platform === "soundfresh"
        ? cmsLoginUrl(configuredCmsLoginUrl)
        : (process.env.SOUNDON_LOGIN_URL?.trim() ||
          `${baseUrl(platform)}/login?lang=en&region=ID`);
