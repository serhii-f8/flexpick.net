import { PRODUCT_APP } from 'flexpick:config';

export interface PartnerContact {
  name: string;
  email: string;
  code: string | null;
}

export const DEFAULT_CONTACT_EMAIL = 'info@flexpick.net';

let pending: Promise<PartnerContact | null> | null = null;

/**
 * The partner who invited this browser, if any. The referral cookie lives on
 * the product app's host, so only the app can answer; one request per page.
 */
export function getPartnerContact(): Promise<PartnerContact | null> {
  pending ??= fetch(`${PRODUCT_APP.url}/api/partner/contact`, {
    credentials: 'include',
    signal: AbortSignal.timeout(4000),
  })
    .then((res) => (res.ok ? res.json() : null))
    .then((data) => (data?.partner?.email ? (data.partner as PartnerContact) : null))
    .catch(() => null);

  return pending;
}

/** Swap FlexPick's contact for the referrer's on every `[data-contact-email]` link. */
export async function applyPartnerContact(): Promise<void> {
  const partner = await getPartnerContact();
  if (!partner) return;

  document.querySelectorAll<HTMLAnchorElement>('a[data-contact-email]').forEach((el) => {
    el.href = `mailto:${partner.email}`;
    el.textContent = partner.email;
  });
  document.querySelectorAll<HTMLElement>('[data-contact-name]').forEach((el) => {
    el.textContent = partner.name;
    el.hidden = false;
  });
}
