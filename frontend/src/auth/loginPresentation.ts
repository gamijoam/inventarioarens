import type { AppMode } from '@/config/branding';

export interface LoginPresentation {
  theme: AppMode;
  eyebrow: string;
  title: string;
  description: string;
  formEyebrow: string;
  formTitle: string;
  submitLabel: string;
  footer: string;
}

const LOGIN_PRESENTATIONS: Record<AppMode, LoginPresentation> = {
  admin: {
    theme: 'admin',
    eyebrow: 'Control administrativo',
    title: 'Entra a tu espacio de trabajo',
    description:
      'Gestiona inventario, compras, ventas, caja y operaciones de tus empresas desde un solo lugar.',
    formEyebrow: 'Acceso de usuario',
    formTitle: 'Usa tus credenciales de empresa',
    submitLabel: 'Entrar al sistema',
    footer: 'Inventario · Compras · Ventas · Control operativo',
  },
  pos: {
    theme: 'pos',
    eyebrow: 'Terminal de caja',
    title: 'Listo para vender',
    description:
      'Inicia sesión para abrir tu operación local, cobrar y mantener tu caja sincronizada.',
    formEyebrow: 'Acceso de cajero',
    formTitle: 'Credenciales para iniciar la jornada',
    submitLabel: 'Entrar al POS',
    footer: 'Venta rápida · Pagos mixtos · Caja · Sincronización',
  },
};

export function getLoginPresentation(mode: AppMode): LoginPresentation {
  return LOGIN_PRESENTATIONS[mode];
}
