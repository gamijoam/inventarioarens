import { createFileRoute } from '@tanstack/react-router';

import { LocalSupportPage } from '@/features/local-support/LocalSupportPage';

export const Route = createFileRoute('/support')({
  component: LocalSupportPage,
});
