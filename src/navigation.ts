import { getPermalink } from './utils/permalinks';

export const headerData = {
  links: [
    {
      text: 'Services',
      href: getPermalink('/#services'),
    },
    {
      text: 'How It Works',
      href: getPermalink('/#how-it-works'),
    },
    {
      text: 'FAQ',
      href: getPermalink('/#faq'),
    },
  ],
  actions: [{ text: 'Contact Us', href: getPermalink('/#contact'), variant: 'primary' as const }],
};

export const footerData = {
  links: [],
  secondaryLinks: [{ text: 'Services', href: getPermalink('/#services') }],
  socialLinks: [],
  footNote: `&copy; ${new Date().getFullYear()} FlexPick. All rights reserved.`,
};
