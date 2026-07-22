import { useState } from 'react';

import { Card } from '@/components/ui/Card';

import { ImportWizard } from './ImportWizard';
import { ImportSessionList } from './ImportSessionList';

export function DataImportPage() {
  const [tab, setTab] = useState<'new' | 'history'>('new');

  return (
    <div className="space-y-4">
      <div className="flex gap-2 border-b">
        <TabButton active={tab === 'new'} onClick={() => setTab('new')}>
          Nuevo import
        </TabButton>
        <TabButton active={tab === 'history'} onClick={() => setTab('history')}>
          Historial
        </TabButton>
      </div>

      {tab === 'new' && (
        <Card>
          <ImportWizard />
        </Card>
      )}

      {tab === 'history' && (
        <Card>
          <ImportSessionList />
        </Card>
      )}
    </div>
  );
}

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
        active
          ? 'border-blue-600 text-blue-700'
          : 'border-transparent text-gray-600 hover:text-gray-900'
      }`}
    >
      {children}
    </button>
  );
}
