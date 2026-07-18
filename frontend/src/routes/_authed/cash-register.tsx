import { createFileRoute } from '@tanstack/react-router';

import { CashRegisterSetup } from '@/features/pos/CashRegisterSetup';

export const Route = createFileRoute('/_authed/cash-register')({
  component: CashRegisterPage,
});

function CashRegisterPage() {
  return <CashRegisterSetup />;
}
