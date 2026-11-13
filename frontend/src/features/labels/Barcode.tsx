import { code128Bars } from '@/lib/code128';

interface BarcodeProps {
  value: string;
  height?: number;
  className?: string;
}

export function Barcode({ value, height = 32, className }: BarcodeProps) {
  let bars;
  try {
    bars = code128Bars(value);
  } catch {
    return <span className="font-mono text-[10px]">{value}</span>;
  }

  const total = bars.reduce((sum, bar) => sum + bar.width, 0);
  let x = 0;

  return (
    <svg
      role="img"
      aria-label={`Codigo de barras ${value}`}
      viewBox={`0 0 ${total} ${height}`}
      preserveAspectRatio="none"
      className={className}
      shapeRendering="crispEdges"
    >
      {bars.map((bar, index) => {
        const rect = bar.dark ? <rect key={index} x={x} y={0} width={bar.width} height={height} fill="#000" /> : null;
        x += bar.width;
        return rect;
      })}
    </svg>
  );
}
