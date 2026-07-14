/**
 * ProductRelations: muestra las relaciones del producto (marca, categorias, tags, garantia).
 * Se muestra como una seccion dentro del tab General.
 */
import { Link2, Tag as TagIcon, Tags as TagsIcon, ShieldCheck } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import type { Product } from '../schemas';

export interface ProductRelationsProps {
  product: Product;
}

export function ProductRelations({ product }: ProductRelationsProps) {
  const hasAny = Boolean(
    product.brand ??
      (product.categories && product.categories.length > 0) ??
      (product.tags && product.tags.length > 0) ??
      product.warranty_policy,
  );

  if (!hasAny) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Relaciones</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-text-muted">
            Este producto aún no tiene marca, categorías, tags ni garantía asignados.
          </p>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Relaciones</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3 text-sm">
        {product.brand && (
          <Field icon={<Link2 className="size-4" />} label="Marca">
            <span className="font-medium">{product.brand.name}</span>
            <span className="ml-1 text-xs text-text-muted">({product.brand.slug})</span>
          </Field>
        )}

        {product.categories && product.categories.length > 0 && (
          <Field icon={<TagsIcon className="size-4" />} label="Categorías">
            <div className="flex flex-wrap gap-1">
              {product.categories.map((c) => (
                <Badge key={c.id} variant="info" className="font-normal">
                  {c.full_path ?? c.name}
                </Badge>
              ))}
            </div>
          </Field>
        )}

        {product.tags && product.tags.length > 0 && (
          <Field icon={<TagIcon className="size-4" />} label="Tags">
            <div className="flex flex-wrap gap-1">
              {product.tags.map((t) => (
                <Badge
                  key={t.id}
                  variant="default"
                  className="font-normal"
                  style={t.color ? { backgroundColor: `${t.color}20`, color: t.color } : undefined}
                >
                  {t.name}
                </Badge>
              ))}
            </div>
          </Field>
        )}

        {product.warranty_policy && (
          <Field icon={<ShieldCheck className="size-4" />} label="Garantía">
            <span className="font-medium">{product.warranty_policy.name}</span>
            <span className="ml-2 text-xs text-text-muted">
              {product.warranty_policy.duration_days
                ? `${product.warranty_policy.duration_days} días`
                : ''}
              {product.warranty_policy.coverage_type
                ? ` · ${product.warranty_policy.coverage_type}`
                : ''}
            </span>
          </Field>
        )}
      </CardContent>
    </Card>
  );
}

function Field({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-start gap-3">
      <span className="mt-0.5 text-text-muted">{icon}</span>
      <div className="flex-1">
        <p className="text-xs font-medium uppercase tracking-wide text-text-muted">{label}</p>
        <div className="mt-0.5">{children}</div>
      </div>
    </div>
  );
}