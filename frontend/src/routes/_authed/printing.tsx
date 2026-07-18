import { createFileRoute } from '@tanstack/react-router';

import { PrintingManager } from '@/features/printing/PrintingManager';

export const Route = createFileRoute('/_authed/printing')({
  component: PrintingPage,
});

function PrintingPage() {
  return <PrintingManager />;
}
