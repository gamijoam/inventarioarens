import { createFileRoute } from '@tanstack/react-router';

import { PaymentMethodsSetup } from '@/features/pos/PaymentMethodsSetup';

export const Route = createFileRoute('/_authed/payment-methods')({
  component: PaymentMethodsPage,
});

function PaymentMethodsPage() {
  return <PaymentMethodsSetup />;
}
