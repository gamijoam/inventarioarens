import { createFileRoute } from '@tanstack/react-router';

import { ArmOrderScreen } from '@/features/pos-armar/ArmOrderScreen';

export const Route = createFileRoute('/_authed/pos/armar')({
  component: ArmOrderScreen,
});
