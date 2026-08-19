import { createFileRoute } from '@tanstack/react-router';

import { QuotationsManager } from '@/features/quotations/QuotationsManager';

export const Route = createFileRoute('/_authed/quotations')({
  component: QuotationsManager,
});
