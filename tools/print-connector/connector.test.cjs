'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');

const {
  PrintConnector,
  buildEscPos,
  buildPlainTicket,
  resolveCloudApiUrl,
  resolveConnectorVersion,
} = require('./connector.cjs');
const { ensureReleaseInputs, parseArgs } = require('./package-connector.cjs');

function response(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status,
    async text() {
      return JSON.stringify(body);
    },
  };
}

function job(uuid = 'job-1') {
  return {
    job_uuid: uuid,
    output: 'thermal',
    status: 'created',
    payload_snapshot: {
      tenant: { name: 'Tienda', slug: 'tienda' },
      profile: {
        paper_width_mm: 80,
        logo_text: 'Tienda',
        cut_paper: true,
      },
      pos_order: { id: 10, sale_id: 20, customer_name: 'Cliente' },
      items: [
        {
          product_name: 'Producto',
          quantity: 1,
          unit_price: 5,
          total: 5,
        },
      ],
      totals: { total_base_amount: 5, paid_base_amount: 5 },
    },
    station: { printer_type: 'windows_printer', printer_name: 'POS-80' },
  };
}

test('polls, claims, prints and acknowledges a job with the connector token', async () => {
  const calls = [];
  const printed = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url, options });
    if (url.endsWith('/printing/connector/jobs?limit=20')) return response(200, { data: [job()] });
    if (url.endsWith('/printing/connector/jobs/job-1/claim'))
      return response(200, {
        data: { claim_token: 'claim-1', job: job() },
      });
    if (url.endsWith('/printing/connector/jobs/job-1/ack'))
      return response(200, { data: { status: 'printed' } });
    if (url.endsWith('/printing/connector/heartbeat'))
      return response(200, { data: { connector: { status: 'active' } } });
    throw new Error(`Unexpected URL ${url}`);
  };
  const connector = new PrintConnector({
    config: {
      cloudApiUrl: 'https://app.example.test/api',
      token: 'secret',
      batchSize: 20,
      heartbeatIntervalMs: 999999,
    },
    fetchImpl,
    printImpl: async (value) => printed.push(value.job_uuid),
  });

  const result = await connector.pollOnce();

  assert.deepEqual(result, [{ jobUuid: 'job-1', status: 'printed' }]);
  assert.deepEqual(printed, ['job-1']);
  assert.equal(calls[0].options.headers.Authorization, 'Bearer secret');
  assert.equal(
    calls[2].options.body,
    JSON.stringify({ claim_token: 'claim-1', status: 'printed' }),
  );
});

test('registers once with a pairing code and persists the issued token', async () => {
  let saved;
  const connector = new PrintConnector({
    config: { cloudApiUrl: 'https://app.example.test/api' },
    fetchImpl: async (url, options) => {
      assert.equal(url, 'https://app.example.test/api/printing/connectors/register');
      assert.equal(options.headers.Authorization, undefined);
      assert.deepEqual(JSON.parse(options.body), {
        code: 'PAIR-1',
        name: 'Caja',
        installation_id: 'install-1',
        version: '0.1.0',
      });
      return response(201, {
        data: {
          connector: { uuid: 'connector-1', status: 'active' },
          token: 'issued-secret',
          token_expires_at: '2027-01-01T00:00:00Z',
        },
      });
    },
    saveConfig: async (value) => {
      saved = value;
    },
  });

  await connector.register({
    code: 'PAIR-1',
    name: 'Caja',
    installationId: 'install-1',
    version: '0.1.0',
  });

  assert.equal(saved.token, 'issued-secret');
  assert.equal(saved.connector.uuid, 'connector-1');
});

test('acknowledges a failed print so the cloud queue can retry it later', async () => {
  const acknowledgements = [];
  const fetchImpl = async (url, options) => {
    if (url.endsWith('/printing/connector/jobs?limit=20'))
      return response(200, { data: [job('job-2')] });
    if (url.endsWith('/printing/connector/jobs/job-2/claim'))
      return response(200, {
        data: { claim_token: 'claim-2', job: job('job-2') },
      });
    if (url.endsWith('/printing/connector/jobs/job-2/ack')) {
      acknowledgements.push(JSON.parse(options.body));
      return response(200, { data: { status: 'failed' } });
    }
    if (url.endsWith('/printing/connector/heartbeat'))
      return response(200, { data: { connector: { status: 'active' } } });
    throw new Error(`Unexpected URL ${url}`);
  };
  const connector = new PrintConnector({
    config: {
      cloudApiUrl: 'https://app.example.test/api',
      token: 'secret',
    },
    fetchImpl,
    printImpl: async () => {
      throw new Error('Impresora apagada');
    },
  });

  const result = await connector.pollOnce();

  assert.equal(result[0].status, 'failed');
  assert.deepEqual(acknowledgements, [
    {
      claim_token: 'claim-2',
      status: 'failed',
      message: 'Impresora apagada',
    },
  ]);
});

test('builds bounded ticket text and ESC/POS cut command', () => {
  const text = buildPlainTicket(job().payload_snapshot);
  assert.match(text, /Ticket POS #10/);
  assert.match(text, /Total USD: \$5\.00/);
  assert.ok(buildEscPos(text, { cutPaper: true }).includes(Buffer.from('\x1d\x56\x00', 'binary')));
});

test('keeps polling after a temporary cloud error', async () => {
  const errors = [];
  const signal = { aborted: false };
  const connector = new PrintConnector({
    config: {
      cloudApiUrl: 'https://app.example.test/api',
      token: 'secret',
    },
    fetchImpl: async () => {
      throw new Error('Cloud offline');
    },
    onError: (error) => errors.push(error.message),
    sleep: async () => {
      signal.aborted = true;
    },
  });

  await connector.run({ signal });

  assert.deepEqual(errors, ['Cloud offline']);
});

test('validates the standalone package inputs and arguments', async () => {
  await assert.doesNotReject(() => ensureReleaseInputs());
  assert.deepEqual(parseArgs(['--version', '0.1.0', '--check-only']), {
    outputDir: require('./package-connector.cjs').DEFAULT_OUTPUT_DIR,
    version: '0.1.0',
    checkOnly: true,
  });
  assert.throws(() => parseArgs(['--unknown']), /Argumento desconocido/);
});

test('resolves the cloud URL from the register command or environment', () => {
  assert.equal(
    resolveCloudApiUrl({}, 'https://cloud.example.test/api/'),
    'https://cloud.example.test/api',
  );
  assert.equal(
    resolveCloudApiUrl({}, undefined, {
      PRINT_CONNECTOR_CLOUD_API_URL: 'https://env.example.test/api',
    }),
    'https://env.example.test/api',
  );
  assert.throws(() => resolveCloudApiUrl({}, undefined, {}), /Indica la URL de la nube/);
});

test('resolves the installed connector version without exposing secrets', () => {
  assert.equal(resolveConnectorVersion({ version: '0.1.1' }, {}), '0.1.1');
  assert.equal(resolveConnectorVersion({}, { PRINT_CONNECTOR_VERSION: '0.1.2' }), '0.1.2');
});
