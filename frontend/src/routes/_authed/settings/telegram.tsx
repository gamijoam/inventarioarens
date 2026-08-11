/**
 * Ruta /settings/telegram - Panel de configuracion de Telegram del bot de
 * administracion por empresa (grupo o empresa sin hijas).
 *
 * Permite configurar:
 *  - Activar/desactivar el bot para esta empresa.
 *  - Hora del resumen diario y frecuencia de alertas de stock bajo.
 *  - Lista blanca: vincular un telegram_id a un usuario de la empresa.
 */
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { TelegramSettingsPanel } from '@/features/telegram-settings/TelegramSettingsPanel';

export const Route = createFileRoute('/_authed/settings/telegram')({
  component: TelegramSettingsPage,
});

function TelegramSettingsPage() {
  return (
    <PageLayout title="Telegram">
      <TelegramSettingsPanel />
    </PageLayout>
  );
}
