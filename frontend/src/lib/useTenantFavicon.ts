import { useEffect } from 'react';

const DEFAULT_FAVICON = '/favicon.svg';

export function useTenantFavicon(logoUrl?: string | null) {
  useEffect(() => {
    if (typeof document === 'undefined') return;

    let link = document.querySelector<HTMLLinkElement>("link[rel~='icon']");
    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }

    const previousHref = link.getAttribute('href') || DEFAULT_FAVICON;
    link.href = logoUrl || DEFAULT_FAVICON;

    return () => {
      if (link) {
        link.href = previousHref;
      }
    };
  }, [logoUrl]);
}
