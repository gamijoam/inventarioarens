import * as React from 'react';

/**
 * Centra un icono SVG de Lucide con un tamano consistente.
 */
export function Icon({ icon: Icon, className }: { icon: React.ComponentType<{ className?: string }>; className?: string }) {
  return <Icon className={className} />;
}