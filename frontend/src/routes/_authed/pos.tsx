import { createFileRoute } from '@tanstack/react-router';

import { PosTerminal } from '@/features/pos/PosTerminal';

export const Route = createFileRoute('/_authed/pos')({
  component: PosPage,
});

function PosPage() {
  return <PosTerminal />;
}
