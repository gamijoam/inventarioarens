import { describe, expect, it } from 'vitest';

import { reportUrl, templateUrl } from './api';

describe('data-import api urls', () => {
  it('builds template and report urls relative to the api baseURL', () => {
    expect(templateUrl('products')).toBe('/import/templates/products');
    expect(reportUrl(42)).toBe('/import/sessions/42/report');
  });
});
